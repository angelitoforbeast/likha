<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time data migration:
 *   Split the existing `jnt_v2` queue into two dedicated queues:
 *     - `jnt_v2_parse`       → per-file parser jobs (ProcessJntUploadV2)
 *                              Multiple workers OK (5x parallel) kasi pure INSERT lang
 *                              sa staging table — no contention.
 *
 *     - `jnt_v2_consolidate` → batch-wide consolidator (ConsolidateAndMergeJntV2Run)
 *                              Single worker only, atomic compare-and-set guards lang
 *                              ang nagti-trigger nito once per run.
 *
 *   Reason: para hindi mag-orphan ang existing pending jobs after queue rename.
 *   Kung walang migration, yung mga jobs na naka-queue sa `jnt_v2` ay hindi mahahawakan
 *   ng kahit anong worker (walang nakikinig sa lumang pangalan).
 *
 * Idempotent: kung nai-apply na once (or kung walang affected rows), 0 ang updated count — safe.
 */
return new class extends Migration {
    public function up(): void
    {
        // 1) Per-file parser jobs → jnt_v2_parse
        $parseMigrated = DB::table('jobs')
            ->where('queue', 'jnt_v2')
            ->where('payload', 'like', '%ProcessJntUploadV2%')
            ->update(['queue' => 'jnt_v2_parse']);

        // 2) Consolidator jobs → jnt_v2_consolidate
        $consolidateMigrated = DB::table('jobs')
            ->where('queue', 'jnt_v2')
            ->where('payload', 'like', '%ConsolidateAndMergeJntV2Run%')
            ->update(['queue' => 'jnt_v2_consolidate']);

        echo "  Migrated {$parseMigrated} parse jobs to jnt_v2_parse queue\n";
        echo "  Migrated {$consolidateMigrated} consolidate jobs to jnt_v2_consolidate queue\n";
    }

    public function down(): void
    {
        // No rollback — one-way data fix.
        // Manually merge back kung kailangan i-revert.
    }
};
