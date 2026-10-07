<?php

namespace App\Services;

use App\Models\MacroOutput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Iisang lugar para sa "patakbuhin ang ISANG row" ng AI Checker — ginagamit ng browser
 * (MacroCheckerController::runRow) at ng night job. Ito ang dating laman ng runRow: kunin ang row,
 * address maps, piliin ang engine, processRow, outcome, isang ai_checker_logs row, at ang payload.
 *
 * Walang request at walang Auth dito: ang tumatawag ang nagbibigay ng host at ng $ctx
 * (source, batch_id, batch_total, user_id, user_name).
 *
 * Ibinabalik: ['status' => HTTP status, 'payload' => JSON ng browser, 'result' => sagot ng engine o null,
 *              'log_id' => id ng ai_checker_logs row o null, 'last_error' => huling transport error ng Astra o null].
 */
class AiCheckerRowRunner
{
    public function run(int $id, string $engine, ?string $host, array $ctx): array
    {
        $row = MacroOutput::find($id);
        if (!$row) {
            return $this->out(404, ['ok' => false, 'error' => 'Row not found']);
        }

        $maps = MacroChecker::loadAddressMaps();
        if (empty($maps['provincesSet'])) {
            return $this->out(500, ['ok' => false, 'error' => 'jnt_address.txt missing or empty']);
        }

        // Logging context — 'batch' (AI Checker), 'single' (AI Fix per row) o 'night' (night run).
        $engine     = $engine === 'astra' ? 'astra' : 'classic';   // ✨ Astra Fix/Check o 🤖 AI Fix/Checker
        $batchTotal = (int) ($ctx['batch_total'] ?? 0);
        $logBase    = [
            'source'          => (string) ($ctx['source'] ?? 'single'),
            'batch_id'        => ($ctx['batch_id'] ?? null) ?: null,
            'batch_total'     => $batchTotal > 0 ? $batchTotal : null,
            'macro_output_id' => $id,
        ];
        $t0  = microtime(true);
        $svc = null;

        try {
            $svc = $this->engine($engine);
            // Engine options ng night run (Astra lang); hindi ito sine-set ng browser kaya walang nagbabago roon.
            if ($svc instanceof AstraEncoder) {
                if (isset($ctx['http_timeout']))            $svc->httpTimeout((int) $ctx['http_timeout']);
                if (!empty($ctx['only_when_status_blank'])) $svc->onlyWhenStatusBlank();
            }
            $result = $svc->processRow($id, $maps, (string) $host);
            $durationMs = (int) round((microtime(true) - $t0) * 1000);
            // Re-read so frontend gets the actual updated values
            $row = MacroOutput::find($id);

            $code      = (string) ($result['final_code'] ?? '');
            $allFilled = ($result['all_filled'] ?? true) ? true : false;
            $outcome   = ($code === '✅' && $allFilled) ? 'fixed' : 'partial';

            $logId = $this->writeLog($logBase + [
                'page'        => $row->PAGE ?? null,
                'item'        => $row->{'ITEM_NAME'} ?? null,
                'final_code'  => $code !== '' ? $code : null,
                'all_filled'  => $allFilled,
                'outcome'     => $outcome,
                'duration_ms' => $durationMs,
            ] + $this->logDetail($result), $ctx);

            return $this->out(200, [
                'ok'     => true,
                'engine' => $engine,
                'result' => $result,
                'row'    => [
                    'id'           => $row->id,
                    'FULL NAME'    => $row->{'FULL NAME'},
                    'PHONE NUMBER' => $row->{'PHONE NUMBER'},
                    'ADDRESS'      => $row->ADDRESS,
                    'PROVINCE'     => $row->PROVINCE,
                    'CITY'         => $row->CITY,
                    'BARANGAY'     => $row->BARANGAY,
                    'APP SCRIPT CHECKER' => $row->{'APP SCRIPT CHECKER'},
                    'STATUS'       => $row->STATUS,
                ],
            ], $result, $logId, $this->lastError($svc));
        } catch (\Throwable $e) {
            $durationMs = (int) round((microtime(true) - $t0) * 1000);
            $logId = $this->writeLog($logBase + [
                'page'        => $row->PAGE ?? null,
                'item'        => $row->{'ITEM_NAME'} ?? null,
                'final_code'  => '❌',
                'all_filled'  => false,
                'outcome'     => 'failed',
                'duration_ms' => $durationMs,
            ], $ctx);

            return $this->out(500, ['ok' => false, 'error' => $e->getMessage()], null, $logId, $this->lastError($svc));
        }
    }

    /** Ang pagpili ng engine — hiwalay na method para mapalitan ng test ng engine na pumapalya. */
    protected function engine(string $engine)
    {
        return $engine === 'astra' ? new AstraEncoder() : new MacroChecker();
    }

    private function out(int $status, array $payload, ?array $result = null, ?int $logId = null, ?array $lastError = null): array
    {
        return ['status' => $status, 'payload' => $payload, 'result' => $result, 'log_id' => $logId, 'last_error' => $lastError];
    }

    /** Huling transport error ng Astra (kind/status/code); null para sa classic engine o kapag wala. */
    private function lastError($svc): ?array
    {
        return $svc instanceof AstraEncoder ? $svc->lastError() : null;
    }

    /**
     * Detalye ng takbo (mula sa MacroChecker trace) → dagdag na columns ng ai_checker_logs.
     * Kung wala pa ang columns (hindi pa na-migrate), walang idadagdag — hindi masisira ang insert.
     */
    private function logDetail(array $result): array
    {
        static $has = null;
        if ($has === null) {
            try { $has = Schema::hasColumn('ai_checker_logs', 'detail'); } catch (\Throwable $e) { $has = false; }
        }
        if (!$has) return [];
        $log = (array) ($result['log'] ?? []);
        $sum = (array) ($log['summary'] ?? []);
        unset($result['log']);
        return [
            'model'      => mb_substr(implode(',', (array) ($sum['models'] ?? [])), 0, 96),
            'escalated'  => !empty($sum['escalated']),
            'searches'   => (int) ($sum['searches'] ?? 0),
            'tokens_in'  => (int) ($sum['tokens_in'] ?? 0),
            'tokens_out' => (int) ($sum['tokens_out'] ?? 0),
            'cost_usd'   => round((float) ($sum['cost_usd'] ?? 0), 4),
            'evidence'   => mb_substr(implode("\n", (array) ($log['evidence'] ?? [])), 0, 60000),
            'detail'     => json_encode(['result' => $result] + $log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /** Insert ng isang per-row AI log — best-effort (di sisirain ang takbo ng row). Ibinabalik ang id, o null kapag pumalya. */
    private function writeLog(array $data, array $ctx): ?int
    {
        try {
            // Defensive caps — para hindi mag-fail ang insert dahil sa haba ng value.
            foreach (['final_code' => 64, 'page' => 255, 'item' => 255] as $k => $max) {
                if (isset($data[$k]) && is_string($data[$k])) {
                    $data[$k] = mb_substr($data[$k], 0, $max);
                }
            }
            return (int) DB::table('ai_checker_logs')->insertGetId(array_merge($data, [
                'user_id'    => $ctx['user_id'] ?? null,
                'user_name'  => mb_substr((string) ($ctx['user_name'] ?? 'unknown'), 0, 255),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        } catch (\Throwable $e) {
            // Huwag ipa-fail ang row, pero i-log na (hindi na tahimik) para ma-debug.
            // HINDI nilo-log ang message: kasama sa QueryException ang SQL at bindings (text ng customer). Class at SQLSTATE lang.
            $fail = ['exception' => get_class($e)];
            if ($e instanceof \Illuminate\Database\QueryException) $fail['sqlstate'] = (string) $e->getCode();
            Log::warning('AI_CHECKER_LOG_FAIL', $fail);
            return null;
        }
    }
}
