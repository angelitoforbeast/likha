<?php

namespace App\Console\Commands;

use App\Services\Jnt\JntLabelStore;
use Illuminate\Console\Command;

/**
 * Spec 269: deletes the kept J&T waybill labels (storage jnt_labels/{day}) older than jnt.labels.keep_days (14).
 * Daily via the scheduler (routes/console.php).
 */
class PruneJntLabels extends Command
{
    protected $signature = 'jnt:prune-labels {--days= : keep this many days instead of jnt.labels.keep_days}';

    protected $description = 'Delete kept J&T waybill labels older than 14 days';

    public function handle(JntLabelStore $store): int
    {
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : null;
        $removed = $store->prune($days);
        $this->info("jnt_labels: {$removed} label(s) older than ".($days ?? $store->keepDays()).' days deleted.');

        return self::SUCCESS;
    }
}
