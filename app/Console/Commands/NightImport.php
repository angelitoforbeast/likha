<?php

namespace App\Console\Commands;

use App\Models\NightRunStep;
use App\Services\Imports\LikhaImportStarter;
use App\Services\Imports\MacroImportStarter;
use App\Support\NightRunSettings;
use Illuminate\Console\Command;

/**
 * Night import (handoff 007): tinatawag ng scheduler sa oras 1 at oras 2 —
 * `php artisan night:import macro 1`, `night:import likha 2`, atbp.
 * Isang step lang kada (gabi, kind): ang pangalawang tawag sa parehong gabi ay walang ginagawa.
 * Ang resulta ng import mismo ay binabasa ng page mula sa run (ref_id); dito, kung nasimulan lang o hindi.
 */
class NightImport extends Command
{
    private const STALE_MESSAGE = 'Stale: closed by the night run';

    protected $signature   = 'night:import {kind : macro|likha} {slot : 1|2}';
    protected $description = 'Start the night macro or Likha import (one step per night and kind)';

    public function handle(MacroImportStarter $macro, LikhaImportStarter $likha): int
    {
        $kind = (string) $this->argument('kind');
        $slot = (string) $this->argument('slot');

        if (!in_array($kind, ['macro', 'likha'], true) || !in_array($slot, ['1', '2'], true)) {
            $this->error('Usage: night:import {macro|likha} {1|2}');

            return self::FAILURE;
        }

        // Binabasa ulit ang switch: naka-off → walang sisimulan, walang itatala.
        if (!NightRunSettings::read()["night_{$kind}_import_enabled"]) {
            return self::SUCCESS;
        }

        $stepId = $this->claimStep(now('Asia/Manila')->toDateString(), "{$kind}_import_{$slot}");
        if ($stepId === null) {
            return self::SUCCESS; // may step na ngayong gabi para sa kind na ito
        }

        try {
            if ($kind === 'macro') {
                $macro->closeStaleRuns(self::STALE_MESSAGE);
                $result = $macro->start(null, 'Night run');
            } else {
                // Likha: ang 2-oras-mula-started_at na stale rule ay nasa starter mismo (bawat path).
                $result = $likha->start(null, self::STALE_MESSAGE);
            }
        } catch (\Throwable $e) {
            // Class name lang — ang exception message ay pwedeng may laman na di dapat makita sa page.
            $reason = 'Failed to start: ' . class_basename($e);
            NightRunStep::where('id', $stepId)->update(['state' => 'failed', 'reason' => $reason]);
            $this->error("night:import {$kind} {$slot}: {$reason}");

            return self::FAILURE;
        }

        if ($result['started']) {
            NightRunStep::where('id', $stepId)->update([
                'state'      => 'started',
                'reason'     => null,
                'ref_id'     => $result['run']->id,
                'started_at' => now(),
            ]);
            $this->info("night:import {$kind} {$slot}: started run #{$result['run']->id}");

            return self::SUCCESS;
        }

        $reason = $result['run']
            ? "Skipped: run #{$result['run']->id} was still running"
            : 'Skipped: another start was in progress';
        NightRunStep::where('id', $stepId)->update(['state' => 'skipped', 'reason' => $reason]);
        $this->info("night:import {$kind} {$slot}: {$reason}");

        return self::SUCCESS;
    }

    /**
     * Kunin ang step ng gabing ito. Ang unique (night_date, kind) ang guard: sa dalawang sabay na tawag,
     * isa lang ang nakaka-insert. Null = may step na. Nakatala muna bilang failed/"interrupted" para kung
     * mamatay ang process bago matapos ang start, hindi mukhang nasimulan ang import.
     */
    private function claimStep(string $nightDate, string $stepKind): ?int
    {
        $inserted = NightRunStep::insertOrIgnore([
            'night_date' => $nightDate,
            'kind'       => $stepKind,
            'state'      => 'failed',
            'reason'     => 'Failed to start: interrupted',
            'trigger'    => 'schedule',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (!$inserted) {
            return null;
        }

        return (int) NightRunStep::where('night_date', $nightDate)->where('kind', $stepKind)->value('id');
    }
}
