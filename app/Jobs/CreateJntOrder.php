<?php

namespace App\Jobs;

use App\Models\JntShipment;
use App\Models\JntBatchRun;
use App\Services\Jnt\JntClient;
use App\Services\Jnt\JntPayloadBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class CreateJntOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public int $shipmentId) {}

    public function handle(): void
    {
        // 1) Load shipment row (this is what you are dispatching)
        /** @var \App\Models\JntShipment|null $shipment */
        $shipment = JntShipment::query()->find($this->shipmentId);

        if (!$shipment) {
            // Nothing to do
            return;
        }

        // Idempotency: if already has mailno, do nothing
        if (!empty($shipment->mailno)) {
            return;
        }

        // If run is stopped, skip (optional safety)
        if (!empty($shipment->jnt_batch_run_id)) {
            $run = JntBatchRun::query()->find($shipment->jnt_batch_run_id);
            if ($run && $run->status === 'stopped') {
                return;
            }
        }

        // 2) Load macro_output row using the shipment's macro_output_id
        $row = DB::table('macro_output')->where('id', $shipment->macro_output_id)->first();
        if (!$row) {
            $this->markFail($shipment, 'macro_output not found');
            return;
        }

        // IMPORTANT: your macro_output has columns with spaces
        $rowArr = (array) $row;

        // Normalize (so builder can consume predictable keys even if it expects snake_case)
        $norm = $this->normalizeMacroRow($rowArr);

        try {
            $client = JntClient::fromConfig();

            $payload = JntPayloadBuilder::buildCreateFromMacroOutput($norm, [
                'txlogisticid' => 'MO-' . $shipment->macro_output_id . '-' . now('Asia/Manila')->format('YmdHis'),
                'remark' => 'LIKHA',
            ]);

            $res = $client->createOrder($payload);

            // Handle different possible shapes:
            // - responseitems[0]
            // - orderList[0]
            $item = [];
            if (is_array($res)) {
                if (!empty($res['responseitems'][0]) && is_array($res['responseitems'][0])) {
                    $item = $res['responseitems'][0];
                } elseif (!empty($res['orderList'][0]) && is_array($res['orderList'][0])) {
                    $item = $res['orderList'][0];
                }
            }

            $successRaw = $item['success'] ?? $res['success'] ?? null;
            $success = $this->toBool($successRaw);

            $tx = $item['txlogisticid'] ?? $payload['txlogisticid'] ?? null;
            $mailno = $item['mailno'] ?? $item['billcode'] ?? null;
            $reason = $item['reason'] ?? $item['desc'] ?? $res['reason'] ?? $res['msg'] ?? null;

            // 3) Persist shipment updates
            DB::transaction(function () use ($shipment, $success, $tx, $mailno, $reason, $payload, $res) {
                // lock row to avoid double updates
                $locked = JntShipment::query()->lockForUpdate()->find($shipment->id);

                if (!$locked) return;
                if (!empty($locked->mailno)) return; // idempotent inside tx

                $locked->update([
                    'txlogisticid' => $tx,
                    'mailno' => $mailno,
                    'success' => $success ? 1 : 0,
                    'reason' => $reason,
                    'request_payload' => $payload,
                    'response_payload' => $res,
                ]);

                // Update batch run counters (if part of a run)
                if (!empty($locked->jnt_batch_run_id)) {
                    $run = JntBatchRun::query()->lockForUpdate()->find($locked->jnt_batch_run_id);
                    if ($run) {
                        $run->processed = (int)$run->processed + 1;
                        if ($success) $run->success_count = (int)$run->success_count + 1;
                        else $run->fail_count = (int)$run->fail_count + 1;

                        // finish if complete
                        if ((int)$run->processed >= (int)$run->total) {
                            $run->status = 'finished';
                            $run->finished_at = now();
                        }

                        $run->save();
                    }
                }
            });

        } catch (\Throwable $e) {
            $this->markFail($shipment, $e->getMessage(), $rowArr);
            throw $e; // retry if needed
        }
    }

    private function markFail(JntShipment $shipment, string $reason, array $extraResponse = []): void
    {
        DB::transaction(function () use ($shipment, $reason, $extraResponse) {
            $locked = JntShipment::query()->lockForUpdate()->find($shipment->id);
            if (!$locked) return;

            $locked->update([
                'success' => 0,
                'reason' => $reason,
                'response_payload' => $extraResponse ?: ($locked->response_payload ?? null),
            ]);

            if (!empty($locked->jnt_batch_run_id)) {
                $run = JntBatchRun::query()->lockForUpdate()->find($locked->jnt_batch_run_id);
                if ($run) {
                    $run->processed = (int)$run->processed + 1;
                    $run->fail_count = (int)$run->fail_count + 1;

                    if ((int)$run->processed >= (int)$run->total) {
                        $run->status = 'finished';
                        $run->finished_at = now();
                    }

                    $run->save();
                }
            }
        });
    }

    private function toBool($v): bool
    {
        if (is_bool($v)) return $v;
        if (is_numeric($v)) return ((int)$v) === 1;
        $s = strtolower(trim((string)$v));
        return in_array($s, ['true', '1', 'yes', 'ok', 'success'], true);
    }

    /**
     * Normalize macro_output row.
     * Your actual columns include: "FULL NAME", "PHONE NUMBER" (with spaces).
     */
    private function normalizeMacroRow(array $rowArr): array
    {
        // Keep original too (builder might read either)
        $fullName = $rowArr['FULL NAME'] ?? $rowArr['FULL_NAME'] ?? $rowArr['full_name'] ?? null;
        $phone = $rowArr['PHONE NUMBER'] ?? $rowArr['PHONE_NUMBER'] ?? $rowArr['phone_number'] ?? null;

        return array_merge($rowArr, [
            'full_name' => $fullName,
            'phone_number' => $phone,
            'address' => $rowArr['ADDRESS'] ?? $rowArr['address'] ?? null,
            'province' => $rowArr['PROVINCE'] ?? $rowArr['province'] ?? null,
            'city' => $rowArr['CITY'] ?? $rowArr['city'] ?? null,
            'barangay' => $rowArr['BARANGAY'] ?? $rowArr['barangay'] ?? null,
            'item_name' => $rowArr['ITEM_NAME'] ?? $rowArr['item_name'] ?? null,
            'cod' => $rowArr['COD'] ?? $rowArr['cod'] ?? null,
            'page' => $rowArr['PAGE'] ?? $rowArr['page'] ?? null,
            'ts_date' => $rowArr['ts_date'] ?? $rowArr['TS_DATE'] ?? null,
        ]);
    }
}
