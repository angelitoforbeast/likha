<?php

namespace App\Jobs;

use App\Services\MacroChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Async batch worker — processes a list of macro_output IDs through the
 * MacroChecker service (CHECKER_11_1 port).
 *
 * Progress is tracked sa Laravel Cache (no DB table) keyed by run_id.
 * Frontend polls `/encoder/checker_1/ai-checker/status?run_id=X` to read
 * the current state.
 *
 * Cache shape:
 *   {
 *     status: 'running' | 'done' | 'failed' | 'stopped',
 *     total: 100, processed: 47, fixed: 40, partial: 5, failed: 2,
 *     started_at: '...', finished_at: null|...,
 *     message: null|'...'
 *   }
 *
 * Load behavior (Option B per user pref): address maps loaded ONCE at the
 * start of the job, then passed to each row processor. No per-row file IO.
 */
class RunMacroCheckerBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Cache key prefix — used by both this job + the status endpoint. */
    public const CACHE_PREFIX = 'macro_checker_run:';

    /** Cache key prefix for the "stop" signal flag. */
    public const STOP_PREFIX  = 'macro_checker_stop:';

    /** Cache TTL in seconds (24h is plenty for a progress modal). */
    public const TTL_SECONDS  = 86400;

    /** Soft time-cap so the queue worker doesn't run indefinitely (4 min, matches Apps Script). */
    public const MAX_RUNTIME_SEC = 240;

    public string $runId;

    /** @var int[] */
    public array $rowIds;

    public ?int $userId;
    public ?string $userEmail;

    /**
     * @param  string $runId      caller-generated UUID
     * @param  int[]  $rowIds     macro_output IDs to process
     * @param  ?int   $userId     who triggered (for logging)
     * @param  ?string $userEmail who triggered
     */
    public function __construct(string $runId, array $rowIds, ?int $userId = null, ?string $userEmail = null)
    {
        $this->runId     = $runId;
        $this->rowIds    = array_values(array_unique(array_filter(array_map('intval', $rowIds), fn ($v) => $v > 0)));
        $this->userId    = $userId;
        $this->userEmail = $userEmail;
    }

    public function handle(): void
    {
        $start = microtime(true);

        $progress = [
            'status'       => 'running',
            'total'        => count($this->rowIds),
            'processed'    => 0,
            'fixed'        => 0,
            'partial'      => 0,
            'failed'       => 0,
            'started_at'   => now()->toDateTimeString(),
            'finished_at'  => null,
            'message'      => null,
            'triggered_by' => $this->userEmail,
        ];
        $this->writeProgress($progress);

        if (empty($this->rowIds)) {
            $progress['status']      = 'done';
            $progress['finished_at'] = now()->toDateTimeString();
            $progress['message']     = 'No rows to process.';
            $this->writeProgress($progress);
            return;
        }

        // ── Load address maps ONCE per batch (Option B, no inter-request cache) ─
        $maps = MacroChecker::loadAddressMaps();

        if (empty($maps['provincesSet'])) {
            $progress['status']      = 'failed';
            $progress['finished_at'] = now()->toDateTimeString();
            $progress['message']     = 'jnt_address.txt missing or empty.';
            $this->writeProgress($progress);
            return;
        }

        $checker = new MacroChecker();

        foreach ($this->rowIds as $id) {
            // Cooperative stop check (set by /encoder/checker_1/ai-checker/stop)
            if (Cache::get(self::STOP_PREFIX . $this->runId) === true) {
                $progress['status']      = 'stopped';
                $progress['finished_at'] = now()->toDateTimeString();
                $progress['message']     = 'Stopped by user at ' . $progress['processed'] . '/' . $progress['total'];
                $this->writeProgress($progress);
                Cache::forget(self::STOP_PREFIX . $this->runId);
                return;
            }

            // Soft runtime cap — leaves remaining rows for a later run
            if ((microtime(true) - $start) > self::MAX_RUNTIME_SEC) {
                $progress['status']      = 'stopped';
                $progress['finished_at'] = now()->toDateTimeString();
                $progress['message']     = 'Runtime cap reached (' . self::MAX_RUNTIME_SEC . 's). '
                                         . 'Processed ' . $progress['processed'] . '/' . $progress['total']
                                         . '. Re-run para sa natitirang rows.';
                $this->writeProgress($progress);
                return;
            }

            try {
                $result = $checker->processRow($id, $maps);

                $progress['processed']++;
                if (($result['status'] ?? null) === 'fixed') {
                    $progress['fixed']++;
                } elseif (($result['status'] ?? null) === 'partial') {
                    $progress['partial']++;
                } else {
                    $progress['failed']++;
                }
            } catch (\Throwable $e) {
                $progress['processed']++;
                $progress['failed']++;
                Log::error('MACRO_CHECKER_ROW_FAIL', [
                    'run_id' => $this->runId,
                    'row_id' => $id,
                    'error'  => $e->getMessage(),
                ]);
            }

            // Cheap update — write every row (no DB hit, just cache)
            $this->writeProgress($progress);
        }

        $progress['status']      = 'done';
        $progress['finished_at'] = now()->toDateTimeString();
        $this->writeProgress($progress);

        Log::info('MACRO_CHECKER_BATCH_DONE', [
            'run_id'       => $this->runId,
            'total'        => $progress['total'],
            'fixed'        => $progress['fixed'],
            'partial'      => $progress['partial'],
            'failed'       => $progress['failed'],
            'duration_sec' => round(microtime(true) - $start, 2),
        ]);
    }

    private function writeProgress(array $progress): void
    {
        Cache::put(self::CACHE_PREFIX . $this->runId, $progress, self::TTL_SECONDS);
    }
}
