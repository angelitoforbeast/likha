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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;



class CreateJntOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public int $shipmentId) {}

    public function handle(): void
{
    /** @var \App\Models\JntShipment|null $shipment */
    $shipment = JntShipment::query()->find($this->shipmentId);
    if (!$shipment) return;

    // Idempotency: if already has mailno, skip
    if (!empty($shipment->mailno)) return;

    // If run is stopped, skip
    if (!empty($shipment->jnt_batch_run_id)) {
        $run = JntBatchRun::query()->find($shipment->jnt_batch_run_id);
        if ($run && $run->status === 'stopped') return;
    }

    // Load macro_output row
    $row = DB::table('macro_output')->where('id', $shipment->macro_output_id)->first();
    if (!$row) {
        $this->markFail($shipment, 'macro_output not found');
        return;
    }

    $rowArr = (array) $row;
    $norm = $this->normalizeMacroRow($rowArr);

    try {
        $page   = (string) ($norm['page'] ?? '');
        $client = JntClient::fromPageOrConfig($page);

        $itemName = trim((string)($norm['item_name'] ?? 'Item'));
        if ($itemName === '') $itemName = 'Item';

        // Apply force_uppercase if enabled on the account
        if ($client->isForceUppercase()) {
            $norm['full_name']    = mb_strtoupper((string)($norm['full_name'] ?? ''));
            $norm['FULL NAME']    = mb_strtoupper((string)($norm['FULL NAME'] ?? ''));
            $norm['address']      = mb_strtoupper((string)($norm['address'] ?? ''));
            $norm['ADDRESS']      = mb_strtoupper((string)($norm['ADDRESS'] ?? ''));
            $norm['province']     = mb_strtoupper((string)($norm['province'] ?? ''));
            $norm['PROVINCE']     = mb_strtoupper((string)($norm['PROVINCE'] ?? ''));
            $norm['city']         = mb_strtoupper((string)($norm['city'] ?? ''));
            $norm['CITY']         = mb_strtoupper((string)($norm['CITY'] ?? ''));
            $norm['barangay']     = mb_strtoupper((string)($norm['barangay'] ?? ''));
            $norm['BARANGAY']     = mb_strtoupper((string)($norm['BARANGAY'] ?? ''));
            $norm['item_name']    = mb_strtoupper((string)($norm['item_name'] ?? ''));
            $norm['ITEM_NAME']    = mb_strtoupper((string)($norm['ITEM_NAME'] ?? ''));
            $itemName             = mb_strtoupper($itemName);
        }

        // Generate stable pure-numeric txlogisticid: 4905026 + MMDD + shipmentId (8 digits)
        $tx = $shipment->txlogisticid;
        if (empty($tx)) {
            $mmdd = now('Asia/Manila')->format('md');
            $tx   = '4905026' . $mmdd . str_pad((string)$shipment->id, 8, '0', STR_PAD_LEFT);
            $shipment->txlogisticid = $tx;
            $shipment->save();
        }

        // ✅ sender from DB (global or per page)
        $senderOpts = $this->resolveSenderOpts($page);

        $payload = JntPayloadBuilder::buildCreateFromMacroOutput($norm, array_merge([
            'txlogisticid' => $tx,
            'remark'       => $itemName,
            'eccompanyid'  => $client->getEccompanyid(),
            'customerid'   => $client->getCustomerid(),
        ], $senderOpts));



        $res = $client->createOrder($payload);

        // Try to get response item
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

        $tx     = $item['txlogisticid'] ?? $payload['txlogisticid'] ?? null;
        $mailno = $item['mailno'] ?? $item['billcode'] ?? null;
        $reason = $item['reason'] ?? $item['desc'] ?? $res['reason'] ?? $res['msg'] ?? null;

        // ✅ capture exact request/response from client
        $protocol = [
            'endpoint'            => $client->lastRequest['endpoint'] ?? null,
            'msg_type'            => $client->lastRequest['msg_type'] ?? null,
            'logistics_interface' => $client->lastRequest['logistics_interface'] ?? null, // exact JSON string
            'data_digest'         => $client->lastRequest['data_digest'] ?? null,
            'form'                => $client->lastRequest['form'] ?? null,
        ];

        $responsePayload = [
            'http_status' => $client->lastResponse['http_status'] ?? null,
            'raw'         => $client->lastResponse['raw'] ?? null,  // exact raw response
            'json'        => $client->lastResponse['json'] ?? $res, // parsed json
        ];

        DB::transaction(function () use (
            $shipment, $success, $tx, $mailno, $reason,
            $payload, $protocol, $responsePayload
        ) {
            $locked = JntShipment::query()->lockForUpdate()->find($shipment->id);
            if (!$locked) return;
            if (!empty($locked->mailno)) return;

            // ✅ snapshot receiver fields for debugging B063
            $receiver = $payload['receiver'] ?? [];

            $locked->update([
                'txlogisticid'      => $tx,
                'mailno'            => $mailno,
                'success'           => $success ? 1 : 0,
                'reason'            => $reason,

                'request_payload'   => $payload,
                'request_protocol'  => $protocol,
                'response_payload'  => $responsePayload,

                'receiver_name'     => $receiver['name'] ?? null,
                'receiver_phone'    => $receiver['phone'] ?? null,
                'receiver_prov'     => $receiver['prov'] ?? null,
                'receiver_city'     => $receiver['city'] ?? null,
                'receiver_area'     => $receiver['area'] ?? null,
                'receiver_address'  => $receiver['address'] ?? null,
            ]);

            // Update batch run counters
            if (!empty($locked->jnt_batch_run_id)) {
                $run = JntBatchRun::query()->lockForUpdate()->find($locked->jnt_batch_run_id);
                if ($run) {
                    $run->processed = (int)$run->processed + 1;
                    if ($success) $run->success_count = (int)$run->success_count + 1;
                    else $run->fail_count = (int)$run->fail_count + 1;

                    if ((int)$run->processed >= (int)$run->total) {
                        $run->status = 'finished';
                        $run->finished_at = now();
                    }

                    $run->save();
                }
            }
        });

    } catch (\Throwable $e) {
        $this->markFail($shipment, $e->getMessage(), [
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
        ]);
        throw $e;
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

    private function resolveSenderOpts(string $page): array
{
    $page = trim($page);

    return Cache::remember("jnt_sender_opts:" . ($page !== '' ? $page : 'default'), 60, function () use ($page) {

        // 1) sender name (per page) - pick LATEST
        $senderName = null;

        if ($page !== '' && Schema::hasTable('page_sender_mappings')) {
            $senderName = DB::table('page_sender_mappings')
                ->where('PAGE', $page)          // ✅ uppercase column
                ->orderByDesc('id')             // ✅ latest row
                // or ->orderByDesc('created_at')
                ->value('SENDER_NAME');         // ✅ uppercase column
        }

        // 2) sender address fields - GLOBAL latest (from sender_addresses)
        $addr = null;
        if (Schema::hasTable('sender_addresses')) {
            $addr = DB::table('sender_addresses')->orderByDesc('id')->first();
        }

        // build opts (null/empty filtered out)
        $opts = [
            'sender_name'    => $senderName,

            'sender_phone'   => $addr->jnt_sender_phone   ?? null,
            'sender_mobile'  => $addr->jnt_sender_phone   ?? null, // optional: same as phone
            'sender_prov'    => $addr->jnt_sender_prov    ?? null,
            'sender_city'    => $addr->jnt_sender_city    ?? null,
            'sender_area'    => $addr->jnt_sender_area    ?? null,
            'sender_address' => $addr->jnt_sender_address ?? null,
        ];

        // remove null/blank
        return array_filter($opts, fn($v) => !is_null($v) && trim((string)$v) !== '');
    });
}



}
