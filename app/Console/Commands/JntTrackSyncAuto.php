<?php

namespace App\Console\Commands;

use App\Services\Jnt\JntAutoTrackSync;
use Illuminate\Console\Command;

/**
 * Ang kusang J&T track-sync (JntAutoTrackSync): sa Likha mula sa scheduler (:20 ng 02 at 06–23 Manila), sa server na
 * walang scheduler ay sinisimulan ng chatblast:auto sa background. Ang claim ng oras ang nagpapasya kung tatakbo.
 */
class JntTrackSyncAuto extends Command
{
    protected $signature   = 'jnt:track-sync-auto';
    protected $description = 'Hourly J&T track-sync: latest scan, status and rider of the open waybills of the last 60 days';

    public function handle(JntAutoTrackSync $sync): int
    {
        $r = $sync->runIfDue();
        if ($r === null) {
            $this->line('Not due, already ran this hour, or switched off.');
        } elseif (!empty($r['busy'])) {
            $this->line('The previous run is still going; skipped this hour.');
        } else {
            $this->line(sprintf('Run #%d: %d waybills, %d checked, %d status changed, %d not reached%s',
                $r['run_id'], $r['total'], $r['checked'] ?? 0, $r['updated'] ?? 0, $r['pending'], $r['error'] ? ' (error: ' . $r['error'] . ')' : ''));
        }

        return self::SUCCESS;
    }
}
