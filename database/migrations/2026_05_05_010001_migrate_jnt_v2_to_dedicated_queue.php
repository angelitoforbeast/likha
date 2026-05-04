<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time data migration:
 *   1. Move existing pending ProcessJntUploadV2 jobs sa `default` queue → `jnt_v2` queue.
 *      Necessary kasi V2 job constructor binago to use onQueue('jnt_v2'),
 *      pero ang in-flight at queued jobs ay naka-pin pa sa `default`.
 *
 *   2. Reset orphaned `upload_logs_v2.status = 'processing'` rows back to 'queued'.
 *      Yung mga rows na yan ay hindi natapos kasi nag-fatal ang worker mid-flight.
 *      Failed-handler hook hindi nakaabot, naka-stuck sila sa 'processing' forever.
 *
 * Idempotent: kung wala nang dapat baguhin (e.g., re-run after na-apply na once),
 * 0 rows lang ang ma-update — safe.
 */
return new class extends Migration {
    public function up(): void
    {
        // 1) Migrate pending V2 queue jobs to jnt_v2 queue
        $jobsMigrated = DB::table('jobs')
            ->where('queue', 'default')
            ->where('payload', 'like', '%ProcessJntUploadV2%')
            ->update(['queue' => 'jnt_v2']);

        // 2) Reset orphaned 'processing' files (stuck from crashes) back to 'queued'
        $logsReset = DB::table('upload_logs_v2')
            ->where('status', 'processing')
            ->update(['status' => 'queued']);

        // Log results para makita sa migration output
        if (function_exists('echo')) {
            echo "  Migrated {$jobsMigrated} jobs to jnt_v2 queue\n";
            echo "  Reset {$logsReset} orphaned processing files\n";
        }
    }

    public function down(): void
    {
        // No rollback — this is a one-way data fix.
        // If you need to revert, manually move jobs back to 'default' queue.
    }
};
