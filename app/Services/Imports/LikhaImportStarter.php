<?php

namespace App\Services\Imports;

use App\Jobs\ImportLikhaFromGoogleSheet;
use App\Models\LikhaImportRun;
use App\Models\LikhaImportRunSheet;
use App\Models\LikhaOrderSetting;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Iisang simula ng Likha import — gamit ng "Run Import Now", ng per-sheet Import at ng scheduler.
 * Atomic ang guard (cache lock sa check + create): isang running run lang sa bawat pagkakataon.
 */
class LikhaImportStarter
{
    /** Default na message ng isinarang stale run; "Stale: closed by the night run" ang ipinapasa ng scheduler. */
    public const STALE_MESSAGE = 'Stale: closed at the next start';

    /** Running pa rin pagkalipas nito mula started_at = patay na (wala pang isang oras ang buong import). */
    public const STALE_MINUTES = 120;

    /** Ilang segundo maghihintay sa lock (0 sa tests para hindi matulog). */
    public int $lockWait = 5;

    /**
     * @param  LikhaOrderSetting|null  $only  iisang sheet lang (manual per-gsheet); null = lahat ng hindi archived
     * @return array{started: bool, run: LikhaImportRun|null}
     *   started=false → may running pa (run = iyon), o hindi nakuha ang lock (run = active kung meron, else null).
     */
    public function start(?LikhaOrderSetting $only = null, string $staleMessage = self::STALE_MESSAGE): array
    {
        try {
            return Cache::lock('import-start:likha', 30)->block(
                $this->lockWait,
                fn () => $this->startLocked($only, $staleMessage)
            );
        } catch (LockTimeoutException $e) {
            return ['started' => false, 'run' => $this->activeRun()];
        }
    }

    private function activeRun(): ?LikhaImportRun
    {
        return LikhaImportRun::where('status', 'running')->latest('id')->first();
    }

    private function startLocked(?LikhaOrderSetting $only, string $staleMessage): array
    {
        $this->closeStaleRuns($staleMessage);

        $active = $this->activeRun();
        if ($active) {
            return ['started' => false, 'run' => $active];
        }

        // Skip archived settings — naka-configure pa rin pero hindi ini-import.
        $settings = $only
            ? collect([$only])
            : LikhaOrderSetting::where('is_archived', false)->orderBy('id')->get();

        // Run + run sheets sa iisang transaction — walang maiiwang running run na kulang ang sheets.
        $run = DB::transaction(function () use ($settings) {
            $run = LikhaImportRun::create([
                'status' => 'running',
                'total_settings' => $settings->count(),
                'started_at' => now(),
            ]);

            foreach ($settings as $s) {
                LikhaImportRunSheet::create([
                    'run_id' => $run->id,
                    'setting_id' => $s->id,
                    'status' => 'queued',
                ]);
            }

            return $run;
        });

        ImportLikhaFromGoogleSheet::dispatch($run->id);

        return ['started' => true, 'run' => $run];
    }

    /**
     * Walang failed() hook at walang Force-stop ang Likha job: ang napatay na job ay nag-iiwan ng run na
     * `running` habambuhay, at haharangin nito ang lahat ng susunod na import. Kaya isinasara dito, sa bawat start.
     */
    private function closeStaleRuns(string $message): void
    {
        $cutoff = now()->subMinutes(self::STALE_MINUTES);

        // Walang started_at (lumang run) → created_at ang batayan, para hindi ito manatiling running habambuhay.
        $staleIds = LikhaImportRun::where('status', 'running')
            ->where(function ($q) use ($cutoff) {
                $q->where('started_at', '<', $cutoff)
                    ->orWhere(function ($q) use ($cutoff) {
                        $q->whereNull('started_at')->where('created_at', '<', $cutoff);
                    });
            })
            ->pluck('id');

        if ($staleIds->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($staleIds, $message) {
            LikhaImportRun::whereIn('id', $staleIds)
                ->where('status', 'running')
                ->update(['status' => 'failed', 'finished_at' => now(), 'message' => $message]);

            LikhaImportRunSheet::whereIn('run_id', $staleIds)
                ->whereNotIn('status', ['done', 'failed'])
                ->update(['status' => 'failed']);
        });
    }
}
