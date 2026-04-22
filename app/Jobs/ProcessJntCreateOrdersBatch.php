<?php

namespace App\Jobs;

use App\Models\AppSetting;
use App\Models\JntBatchRun;
use App\Models\JntShipment;
use App\Services\Jnt\JntClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProcessJntCreateOrdersBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public function __construct(
        public int $runId,
        public array $macroOutputIds,
        public ?int $userId = null,
    ) {}

    public function handle(): void
    {
        $run = JntBatchRun::findOrFail($this->runId);

        $run->update([
            'status' => 'running',
            'started_at' => now(),
            'total' => count($this->macroOutputIds),
        ]);

        // Resolve per-page JNT credentials from DB mapping, fallback to .env
        $filters = is_array($run->filters) ? $run->filters : (json_decode((string) $run->filters, true) ?? []);
        $page    = (string) ($filters['page'] ?? '');

        $client = JntClient::fromPageOrConfig($page);

        foreach ($this->macroOutputIds as $i => $macroId) {
            // Pull row from macro_output (adjust table/model if needed)
            $row = DB::table('macro_output')->where('id', $macroId)->first();

            if (!$row) {
                $this->storeFail($run, $macroId, null, 'ROW_NOT_FOUND');
                $this->tick($run, false);
                continue;
            }

            // Required fields (adjust column names if your macro_output uses different ones)
            $fullName = trim((string)($row->FULL_NAME ?? ''));
            $phone    = trim((string)($row->PHONE_NUMBER ?? ''));
            $addr1    = trim((string)($row->ADDRESS ?? ''));
            $prov     = trim((string)($row->PROVINCE ?? ''));
            $city     = trim((string)($row->CITY ?? ''));
            $brgy     = trim((string)($row->BARANGAY ?? ''));
            $itemName = trim((string)($row->ITEM_NAME ?? ''));
            $cod      = (string)($row->COD ?? '');

            // Parse "N x ITEM" prefix for quantity
            if (preg_match('/^(\d+)\s*[xX]\s+(.+)$/u', $itemName, $m)) {
                $qty      = max(1, (int) $m[1]);
                $itemName = trim($m[2]);
            } else {
                $qty = 1;
            }

            // Apply force uppercase if enabled on the account
            if ($client->isForceUppercase()) {
                $fullName = mb_strtoupper($fullName);
                $addr1    = mb_strtoupper($addr1);
                $prov     = mb_strtoupper($prov);
                $city     = mb_strtoupper($city);
                $brgy     = mb_strtoupper($brgy);
                $itemName = mb_strtoupper($itemName);
            }

            // Basic validation
            if ($fullName === '' || $phone === '' || $addr1 === '' || $prov === '' || $city === '' || $brgy === '' || $itemName === '' || $cod === '') {
                $this->storeFail($run, $macroId, [
                    'missing' => [
                        'FULL_NAME' => $fullName === '',
                        'PHONE_NUMBER' => $phone === '',
                        'ADDRESS' => $addr1 === '',
                        'PROVINCE' => $prov === '',
                        'CITY' => $city === '',
                        'BARANGAY' => $brgy === '',
                        'ITEM_NAME' => $itemName === '',
                        'COD' => $cod === '',
                    ]
                ], 'MISSING_REQUIRED_FIELDS');
                $this->tick($run, false);
                continue;
            }

            // Build payload (PH doc format you already validated)
            $tx = 'MO-' . $macroId . '-' . now()->format('YmdHis'); // unique txlogisticid

            $payload = [
                'actiontype' => 'add',
                'environment' => 'yes', // staging=yes in sandbox
                'eccompanyid' => $client->getEccompanyid(),
                'customerid'  => $client->getCustomerid(),
                'txlogisticid'=> $tx,

                'ordertype' => '1',
                'servicetype' => '6',
                'deliverytype' => '1',

                'sender' => $this->resolveSenderOpts($page, $itemName),

                'receiver' => [
                    'name' => $fullName,
                    'phone'=> $phone,
                    'mobile'=> $phone,
                    'prov' => $prov,
                    'city' => $city,
                    'area' => $brgy,
                    'address' => $addr1,
                ],

                'createordertime' => now()->format('Y-m-d H:i:s'),
                'sendstarttime' => now()->copy()->addDays(max(0, (int) AppSetting::get('jnt_pickup_days_offset', 0)))->format('Y-m-d') . ' 09:00:00',
                'sendendtime'   => now()->copy()->addDays(max(0, (int) AppSetting::get('jnt_pickup_days_offset', 0)))->format('Y-m-d') . ' 18:00:00',

                'paytype' => '1',
                'weight' => (string)config('jnt.defaults.weight', '0.5'),
                'itemsvalue' => (string)$cod,
                'totalquantity' => (string) $qty,
                'remark' => 'AUTO_FROM_MACRO_OUTPUT',
                'isInsured' => '0',

                'items' => [[
                    'itemname' => $itemName,
                    'number' => (string) $qty,
                    'itemvalue' => (string)$cod,
                    'desc' => $itemName,
                ]],
            ];

            try {
                $resp = $client->createOrder($payload);

                $item = $resp['responseitems'][0] ?? [];
                $success = (($item['success'] ?? 'false') === 'true');

                JntShipment::create([
                    'jnt_batch_run_id' => $run->id,
                    'macro_output_id' => $macroId,
                    'txlogisticid' => $item['txlogisticid'] ?? $tx,
                    'mailno' => $item['mailno'] ?? null,
                    'sortingcode' => $item['sortingcode'] ?? null,
                    'sortingNo' => $item['sortingNo'] ?? null,
                    'success' => $success,
                    'reason' => $item['reason'] ?? null,
                    'request_payload' => $payload,
                    'response_payload' => $resp,
                    'created_by' => $this->userId,
                ]);

                $this->tick($run, $success);
            } catch (\Throwable $e) {
                $this->storeFail($run, $macroId, ['exception' => $e->getMessage()], 'EXCEPTION');
                $this->tick($run, false);
            }
        }

        $run->update([
            'status' => 'done',
            'finished_at' => now(),
        ]);
    }

    protected function tick(JntBatchRun $run, bool $success): void
    {
        // lightweight atomic increment
        $run->increment('processed', 1);
        if ($success) $run->increment('success_count', 1);
        else $run->increment('fail_count', 1);
    }

    protected function storeFail(JntBatchRun $run, int $macroId, ?array $payload, string $reason): void
    {
        JntShipment::create([
            'jnt_batch_run_id' => $run->id,
            'macro_output_id' => $macroId,
            'success' => false,
            'reason' => $reason,
            'request_payload' => $payload,
            'response_payload' => null,
            'created_by' => $this->userId,
        ]);
    }

    /**
     * Look up sender name from page_sender_mappings (page+item first, then page-only),
     * then fall back to config/empty. Also pulls sender address from sender_addresses.
     */
    protected function resolveSenderOpts(string $page, string $itemName): array
    {
        $senderName = null;

        if ($page !== '' && Schema::hasTable('page_sender_mappings')) {
            // 1) Try page + item name
            if ($itemName !== '') {
                $senderName = DB::table('page_sender_mappings')
                    ->where('PAGE', $page)
                    ->where('item_name', $itemName)
                    ->whereNotNull('item_name')
                    ->orderByDesc('id')
                    ->value('SENDER_NAME');
            }

            // 2) Fallback: page only (no item_name)
            if (!$senderName) {
                $senderName = DB::table('page_sender_mappings')
                    ->where('PAGE', $page)
                    ->whereNull('item_name')
                    ->orderByDesc('id')
                    ->value('SENDER_NAME');
            }
        }

        // No fallback — if not found, fail the order with a clear error
        if (!$senderName) {
            throw new \RuntimeException("SENDER_NAME_NOT_FOUND: no page_sender_mappings entry for page='{$page}' item='{$itemName}'");
        }

        // Sender address from sender_addresses table — no config fallback
        $addr = null;
        if (Schema::hasTable('sender_addresses')) {
            $addr = DB::table('sender_addresses')->orderByDesc('id')->first();
        }

        return [
            'name'    => $senderName,
            'phone'   => (string) ($addr->jnt_sender_phone   ?? ''),
            'mobile'  => (string) ($addr->jnt_sender_phone   ?? ''),
            'prov'    => (string) ($addr->jnt_sender_prov    ?? ''),
            'city'    => (string) ($addr->jnt_sender_city    ?? ''),
            'area'    => (string) ($addr->jnt_sender_area    ?? ''),
            'address' => (string) ($addr->jnt_sender_address ?? ''),
        ];
    }
}
