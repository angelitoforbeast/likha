<?php

namespace App\Jobs;

use App\Services\Jnt\JntCheckerMatcher;
use App\Services\Jnt\JntCheckerRuns;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ang pagtutugma ng isang J&T Checker upload (spec 283), sa queue para hindi tumama sa 60 s ng nginx.
 * Default connection, queue "default": doon nakikinig ang iisang worker ng bawat site.
 */
class RunJntCheckerUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const QUEUE = 'default';

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(
        public string $runId,
        public string $fileName,
        public string $ext,
        public string $originalName,
        public ?string $start,
        public ?string $end,
        public string $host,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(JntCheckerRuns $runs, JntCheckerMatcher $matcher): void
    {
        @set_time_limit(0);

        $state = $runs->get($this->runId);
        if ($state === null) {
            // Expired na ang run (o na-evict): wala nang titingin sa resulta.
            $runs->deleteWorkDir($this->runId);
            return;
        }
        if ($state['status'] !== JntCheckerRuns::STATUS_QUEUED) {
            return; // natapos o tumatakbo na: hindi uulitin
        }

        $runs->patch($this->runId, ['status' => JntCheckerRuns::STATUS_RUNNING, 'started_at' => now()->timestamp]);
        $started = microtime(true);

        try {
            $out = $matcher->run(
                $runs->workDir($this->runId) . DIRECTORY_SEPARATOR . $this->fileName,
                $this->ext,
                $this->originalName,
                $this->start,
                $this->end,
                $this->host,
                $runs->workDir($this->runId),
            );

            if ($out['error'] !== null) {
                $runs->patch($this->runId, ['status' => JntCheckerRuns::STATUS_ERROR, 'error' => $out['error']]);
                return;
            }

            $data = $out['data'];
            $runs->writeResult($this->runId, $data);
            $runs->patch($this->runId, [
                'status'  => JntCheckerRuns::STATUS_DONE,
                'success' => $out['success'],
                'counts'  => [
                    'matched'    => $data['matchedCount'],
                    'notMatched' => $data['notMatchedCount'],
                    'notInExcel' => $data['notInExcelCount'],
                    'updatable'  => $data['updatableCount'],
                ],
            ]);

            Log::info('JNT checker upload done', [
                'run' => $this->runId, 'seconds' => round(microtime(true) - $started, 1),
                'rows' => count($data['results']), 'updatable' => $data['updatableCount'],
            ]);
        } finally {
            $runs->deleteWorkDir($this->runId);
        }
    }

    public function failed(?Throwable $e): void
    {
        $runs = app(JntCheckerRuns::class);
        $runs->deleteWorkDir($this->runId);
        $runs->patch($this->runId, [
            'status' => JntCheckerRuns::STATUS_FAILED,
            'error'  => 'The check stopped: ' . self::shortReason($e),
        ]);
    }

    public static function shortReason(?Throwable $e): string
    {
        if ($e === null) return 'unknown error.';
        $class = class_basename($e);
        if (str_contains($class, 'Timeout') || str_contains($class, 'MaxAttempts')) {
            return 'it took longer than 10 minutes.';
        }

        return mb_strimwidth($e->getMessage() ?: $class, 0, 160, '...');
    }
}
