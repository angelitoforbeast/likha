<?php

namespace App\Services\Imports;

use App\Jobs\ImportMacroFromGoogleSheet;
use App\Models\MacroGsheetSetting;
use App\Models\MacroImportRun;
use App\Models\MacroImportRunItem;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Iisang simula ng macro import — gamit ng button, ng API at ng scheduler.
 * Atomic ang guard: nasa loob ng cache lock ang check + create, kaya dalawang
 * sabay na start ay iisang run lang ang nagagawa.
 */
class MacroImportStarter
{
    /** Ilang segundo maghihintay sa lock (0 sa tests para hindi matulog). */
    public int $lockWait = 5;

    /**
     * @return array{started: bool, run: MacroImportRun|null}
     *   started=false → may queued/running pa (run = iyon), o hindi nakuha ang lock (run = active kung meron, else null).
     */
    public function start(?int $userId, ?string $message): array
    {
        try {
            return Cache::lock('import-start:macro', 30)->block(
                $this->lockWait,
                fn () => $this->startLocked($userId, $message)
            );
        } catch (LockTimeoutException $e) {
            return ['started' => false, 'run' => $this->activeRun()];
        }
    }

    private function activeRun(): ?MacroImportRun
    {
        return MacroImportRun::whereIn('status', ['queued', 'running'])->latest('id')->first();
    }

    private function startLocked(?int $userId, ?string $message): array
    {
        $active = $this->activeRun();
        if ($active) {
            return ['started' => false, 'run' => $active];
        }

        // Skip archived settings — naka-configure pa rin pero hindi ini-import (lahat ng path).
        $settings = MacroGsheetSetting::where('is_archived', false)->get();

        // Run + items sa iisang transaction — walang maiiwang queued run na kulang ang items.
        $run = DB::transaction(function () use ($settings, $userId, $message) {
            $run = MacroImportRun::create([
                'started_by'         => $userId,
                'status'             => 'queued',
                'started_at'         => now(),
                'total_settings'     => $settings->count(),
                'processed_settings' => 0,
                'total_processed'    => 0,
                'total_inserted'     => 0,
                'total_updated'      => 0,
                'total_skipped'      => 0,
                'message'            => $message,
            ]);

            // Per-setting items snapshot
            foreach ($settings as $s) {
                MacroImportRunItem::create([
                    'run_id'      => $run->id,
                    'setting_id'  => $s->id,
                    'gsheet_name' => $s->gsheet_name,
                    'sheet_url'   => $s->sheet_url,
                    'sheet_range' => $s->sheet_range,
                    'status'      => 'queued',
                    'processed'   => 0,
                    'inserted'    => 0,
                    'updated'     => 0,
                    'skipped'     => 0,
                    'message'     => null,
                    'started_at'  => null,
                    'finished_at' => null,
                ]);
            }

            return $run;
        });

        ImportMacroFromGoogleSheet::dispatch($run->id, $userId);

        return ['started' => true, 'run' => $run];
    }
}
