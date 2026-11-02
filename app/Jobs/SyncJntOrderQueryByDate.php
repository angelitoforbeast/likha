<?php

namespace App\Jobs;

use App\Services\Jnt\JntOrderQueryClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncJntOrderQueryByDate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 minutes
    public int $tries = 1;

    public function __construct(
        public int $runId,
        public string $uploadDate,
        public ?string $page = null
    ) {}

    public function handle(JntOrderQueryClient $client): void
    {
        $now = now('Asia/Manila');

        DB::table('jnt_orderquery_runs')->where('id', $this->runId)->update([
            'status' => 'running',
            'started_at' => $now,
            'last_error' => null,
            'updated_at' => $now,
        ]);

        $serials = $this->baseQuery()
            ->pluck('s.txlogisticid')
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->values()
            ->all();

        DB::table('jnt_orderquery_runs')->where('id', $this->runId)->update([
            'total' => count($serials),
            'updated_at' => $now,
        ]);

        $normDt = function (?string $s): ?string {
            $s = trim((string) $s);
            if ($s === '') return null;
            $s = preg_replace('/\.\d+$/', '', $s); // "2026-01-27 20:50:46.0" -> "2026-01-27 20:50:46"
            return $s ?: null;
        };

        $chunkSize = 10; // keep sane
        $processed = $ok = $missing = $failed = 0;

        try {
            for ($i = 0; $i < count($serials); $i += $chunkSize) {
                $batch = array_slice($serials, $i, $chunkSize);

                // Client returns [serial => order] only when it gets valid order
                $items = $client->queryOrders($batch);

                DB::transaction(function () use ($batch, $items, $now, $normDt, &$processed, &$ok, &$missing) {
                    foreach ($batch as $serial) {
                        $order = $items[$serial] ?? null;

                        if (is_array($order)) {
                            $receiver = $order['receiver'] ?? [];
                            $receiverAddress = trim((string) ($receiver['address'] ?? ''));

                            DB::table('jnt_shipments')
                                ->where('txlogisticid', $serial)
                                ->update([
                                    'mailno'           => $order['mailno'] ?? DB::raw('mailno'),
                                    'sortingcode'      => $order['sortingcode'] ?? DB::raw('sortingcode'),
                                    'sortingNo'        => $order['sortingNo'] ?? DB::raw('sortingNo'),

                                    'receiver_name'    => $receiver['name'] ?? null,
                                    'receiver_phone'   => $receiver['phone'] ?? null,
                                    'receiver_prov'    => $receiver['prov'] ?? null,
                                    'receiver_city'    => $receiver['city'] ?? null,
                                    'receiver_area'    => $receiver['area'] ?? null,
                                    'receiver_address' => $receiverAddress !== '' ? $receiverAddress : null,

                                    'order_status'       => $order['orderStatus'] ?? null,
                                    'create_order_time'  => $normDt($order['createordertime'] ?? null),
                                    'send_start_time'    => $normDt($order['sendstarttime'] ?? null),
                                    'send_end_time'      => $normDt($order['sendendtime'] ?? null),

                                    'weight'            => isset($order['weight']) ? (float) $order['weight'] : null,
                                    'charge_weight'     => isset($order['chargeWeight']) ? (float) $order['chargeWeight'] : null,
                                    'sumfreight'        => isset($order['sumfreight']) ? (float) $order['sumfreight'] : null,
                                    'offer_fee'         => isset($order['offerFee']) ? (float) $order['offerFee'] : null,
                                    'goods_value'       => isset($order['goodsValue']) ? (float) $order['goodsValue'] : null,
                                    'goods_names'       => $order['goodsNames'] ?? null,

                                    'orderquery_raw'        => json_encode($order, JSON_UNESCAPED_UNICODE),
                                    'orderquery_queried_at' => $now,

                                    'status' => 'ORDERQUERY_OK',
                                    'last_error' => null,
                                    'updated_at' => $now,
                                ]);

                            $ok++;
                        } else {
                            DB::table('jnt_shipments')
                                ->where('txlogisticid', $serial)
                                ->update([
                                    'order_status' => null,
                                    'create_order_time' => null,
                                    'send_start_time' => null,
                                    'send_end_time' => null,
                                    'weight' => null,
                                    'charge_weight' => null,
                                    'sumfreight' => null,
                                    'offer_fee' => null,
                                    'goods_value' => null,
                                    'goods_names' => null,
                                    'orderquery_raw' => null,
                                    'orderquery_queried_at' => $now,

                                    'status' => 'ORDERQUERY_MISSING',
                                    'last_error' => 'ORDERQUERY returned no order for txlogisticid',
                                    'updated_at' => $now,
                                ]);

                            $missing++;
                        }

                        $processed++;
                    }
                });

                DB::table('jnt_orderquery_runs')->where('id', $this->runId)->update([
                    'processed' => $processed,
                    'ok' => $ok,
                    'missing' => $missing,
                    'failed' => $failed,
                    'updated_at' => now('Asia/Manila'),
                ]);
            }

            DB::table('jnt_orderquery_runs')->where('id', $this->runId)->update([
                'status' => 'done',
                'finished_at' => now('Asia/Manila'),
                'updated_at' => now('Asia/Manila'),
            ]);

        } catch (Throwable $e) {
            $failed++;

            DB::table('jnt_orderquery_runs')->where('id', $this->runId)->update([
                'status' => 'failed',
                'failed' => $failed,
                'last_error' => $e->getMessage(),
                'finished_at' => now('Asia/Manila'),
                'updated_at' => now('Asia/Manila'),
            ]);

            throw $e;
        }
    }

    private function baseQuery(): Builder
    {
        $q = DB::table('jnt_shipments as s')
            ->join('macro_output as m', 'm.id', '=', 's.macro_output_id')
            ->whereDate('s.created_at', $this->uploadDate)
            ->whereNotNull('s.txlogisticid')
            ->where('s.txlogisticid', '!=', '');

        if ($this->page !== null && trim($this->page) !== '') {
            $q->where('m.PAGE', trim($this->page));
        }

        return $q;
    }
}
