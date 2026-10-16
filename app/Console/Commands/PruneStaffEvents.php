<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Binubura ang staff_events na mas luma sa 90 araw (araw-araw, 04:20 Manila, via scheduler).
 */
class PruneStaffEvents extends Command
{
    protected $signature   = 'staff-events:prune';
    protected $description = 'Delete staff_events older than 90 days';

    public function handle(): int
    {
        if (!Schema::hasTable('staff_events')) {
            return self::SUCCESS;
        }
        $deleted = DB::table('staff_events')->where('created_at', '<', now()->subDays(90))->delete();
        $this->info("staff_events: {$deleted} row(s) na mas luma sa 90 araw ang binura.");

        return self::SUCCESS;
    }
}
