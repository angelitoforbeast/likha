<?php

namespace App\Jobs;

use App\Models\JntBatchRun;
use App\Models\JntShipment;
use App\Services\Jnt\JntClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

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

        $client = JntClient::fromConfig();

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
                'eccompanyid' => config('jnt.credentials.eccompanyid'),
                'customerid'  => config('jnt.credentials.customerid'),
                'txlogisticid'=> $tx,

                'ordertype' => '1',
                'servicetype' => '6',
                'deliverytype' => '1',

                // Sender should come from your config (company pickup address)
                // Put these in config/jnt.php or a dedicated config to keep it clean
                'sender' => [
                    'name' => (string)config('jnt.sender.name', 'INCEPXION'),
                    'phone'=> (string)config('jnt.sender.phone', ''),
                    'mobile'=>(string)config('jnt.sender.mobile', ''),
                    'prov' => (string)config('jnt.sender.prov', ''),
                    'city' => (string)config('jnt.sender.city', ''),
                    'area' => (string)config('jnt.sender.area', ''),
                    'address' => (string)config('jnt.sender.address', ''),
                ],

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
                'sendstarttime' => now()->format('Y-m-d') . ' 09:00:00',
                'sendendtime'   => now()->format('Y-m-d') . ' 18:00:00',

                'paytype' => '1',
                'weight' => (string)config('jnt.defaults.weight', '0.5'),
                'itemsvalue' => (string)$cod,
                'totalquantity' => '1',
                'remark' => 'AUTO_FROM_MACRO_OUTPUT',
                'isInsured' => '0',

                'items' => [[
                    'itemname' => $itemName,
                    'number' => '1',
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
}
