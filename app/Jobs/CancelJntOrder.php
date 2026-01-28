<?php

namespace App\Jobs;

use App\Models\JntShipment;
use App\Services\Jnt\JntClient;
use App\Services\Jnt\JntPayloadBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CancelJntOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    // ✅ set true if you want to clear mailno/tx after cancel success
    private bool $clearIdentifiersOnSuccess = false;

    public function __construct(public int $shipmentId) {}

    public function handle(): void
    {
        $shipment = JntShipment::query()->find($this->shipmentId);
        if (!$shipment) return;

        $mailno = trim((string)($shipment->mailno ?? ''));
        $tx     = trim((string)($shipment->txlogisticid ?? ''));

        // ✅ J&T Cancel requires txlogisticid (as you confirmed)
        if ($tx === '') {
            $shipment->update([
                'status'     => 'CANCEL_FAIL',
                'last_error' => 'No txlogisticid to cancel (required by J&T).',
                'reason'     => 'NO_TX',
            ]);
            return;
        }

        $logId = null;
        $payload = null;
        $res = null;

        try {
            $client = JntClient::fromConfig();

            $payload = JntPayloadBuilder::buildCancelOrder([
                'txlogisticid' => $tx,
                'country'      => 'PH',
                'reason'       => 'Customer cancel',
            ]);

            if (empty($payload)) {
                $shipment->update([
                    'status'     => 'CANCEL_FAIL',
                    'last_error' => 'Cancel payload empty (txlogisticid missing).',
                    'reason'     => 'EMPTY_CANCEL_PAYLOAD',
                ]);
                return;
            }

            $shipment->update([
                'status'     => 'CANCELING',
                'last_error' => null,
                'last_reason'=> null,
            ]);

            $logId = $this->logApi('ORDERCANCEL', $shipment, $payload, null, null, null);

            $res = $client->cancelOrder($payload);

            [$ok, $code] = $this->inferJntSuccessAndReason($res);

            $msg = $ok ? 'Cancel OK' : ($code ?: 'Cancel failed');

            DB::transaction(function () use ($shipment, $ok, $msg, $code, $mailno, $tx, $res) {
                $locked = JntShipment::query()->lockForUpdate()->find($shipment->id);
                if (!$locked) return;

                $respToStore = is_array($res) ? json_encode($res, JSON_UNESCAPED_UNICODE) : (string)$res;

                // ✅ IMPORTANT: do NOT overwrite create "success" meaning here
                // We'll only update status + error fields + reason for cancel result.
                // If you want to keep create reason separate, add cancel_reason columns later.
                $update = [
                    'status'           => $ok ? 'CANCEL_OK' : 'CANCEL_FAIL',
                    'last_reason'      => $ok ? "CANCEL OK: prev_mailno={$mailno} prev_tx={$tx}" : null,
                    'last_error'       => $ok ? null : $msg,
                    'reason'           => $ok ? null : ($code ?: 'CANCEL_FAIL'), // stores cancel fail code only when fail
                    'response_payload' => $respToStore,
                    'updated_at'       => now(),
                ];

                // ✅ optional: clear identifiers after cancel OK
                if ($ok && $this->clearIdentifiersOnSuccess) {
                    $update['mailno'] = null;
                    // keep tx if you want audit trail; clear only if you want recreate as "new"
                    // $update['txlogisticid'] = null;
                }

                $locked->update($update);
            });

            $this->logApiUpdate($logId, $shipment, $ok ? 1 : 0, $ok ? null : $msg, $res, $mailno, $tx);

        } catch (\Throwable $e) {
            $shipment->update([
                'status'     => 'CANCEL_FAIL',
                'last_error' => 'CANCEL exception: ' . $e->getMessage(),
                'reason'     => 'EXCEPTION',
            ]);

            $this->logApiUpdate($logId, $shipment, 0, $e->getMessage(), $res, $mailno, $tx);

            throw $e;
        }
    }

    private function inferJntSuccessAndReason($res): array
    {
        if (!is_array($res)) return [false, 'INVALID_RESPONSE'];

        $item = $res['responseitems'][0] ?? null;
        if (!is_array($item)) return [false, 'NO_RESPONSEITEM'];

        $ok = $this->toBool($item['success'] ?? null);
        $reason = $item['reason'] ?? null;

        $code = is_string($reason) ? trim($reason) : null;
        return [$ok, $code];
    }

    private function toBool($v): bool
    {
        if (is_bool($v)) return $v;
        if (is_numeric($v)) return ((int)$v) === 1;
        $s = strtolower(trim((string)$v));
        return in_array($s, ['true','1','yes','ok','success'], true);
    }

    private function logApi(string $action, JntShipment $shipment, $requestPayload, $responsePayload, ?int $success, ?string $error): ?int
    {
        if (!Schema::hasTable('jnt_api_logs')) return null;

        return (int) DB::table('jnt_api_logs')->insertGetId([
            'jnt_shipment_id'  => $shipment->id,
            'macro_output_id'  => $shipment->macro_output_id,
            'jnt_batch_run_id' => $shipment->jnt_batch_run_id,
            'action'           => $action,
            'request_payload'  => $requestPayload ? json_encode($requestPayload, JSON_UNESCAPED_UNICODE) : null,
            'response_payload' => $responsePayload ? json_encode($responsePayload, JSON_UNESCAPED_UNICODE) : null,
            'success'          => $success,
            'http_status'      => null,
            'error'            => $error,
            'mailno'           => $shipment->mailno,
            'txlogisticid'     => $shipment->txlogisticid,
            'created_at'       => now(),
        ]);
    }

    private function logApiUpdate(?int $logId, JntShipment $shipment, ?int $success, ?string $error, $responsePayload, ?string $mailno, ?string $tx): void
    {
        if (!$logId) return;
        if (!Schema::hasTable('jnt_api_logs')) return;

        DB::table('jnt_api_logs')
            ->where('id', $logId)
            ->update([
                'success'          => $success,
                'error'            => $error,
                'response_payload' => $responsePayload ? json_encode($responsePayload, JSON_UNESCAPED_UNICODE) : null,
                'mailno'           => $mailno,
                'txlogisticid'     => $tx,
            ]);
    }
}
