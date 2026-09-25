<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill ng from_jnts.address mula macro_output.ADDRESS,
 * naka-join sa waybill (macro_output.waybill = from_jnts.waybill_number).
 *
 *  - IDEMPOTENT: pinupunan LANG ang rows na NULL/blangko ang address.
 *  - Chunked by id range para hindi mag-lock nang matagal sa malaking table.
 *  - HINDI ginagalaw ang updated_at (para hindi ma-trigger ang ?since= ng TeleSMS).
 *  - Driver-aware: MySQL (UPDATE ... JOIN) at PostgreSQL (UPDATE ... FROM).
 *
 *   php artisan jnt:backfill-address --dry-run
 *   php artisan jnt:backfill-address
 *   php artisan jnt:backfill-address --tables=from_jnts --chunk=10000
 */
class BackfillJntAddressFromMacro extends Command
{
    protected $signature   = 'jnt:backfill-address
                              {--tables=from_jnts : table na ba-backfill (default: from_jnts)}
                              {--chunk=20000 : id-range size kada batch}
                              {--dry-run : bilangin lang, huwag isulat}';
    protected $description = 'Backfill from_jnts.address mula macro_output.ADDRESS (join sa waybill); NULL lang ang pupunan';

    public function handle(): int
    {
        $tables = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('tables')))));
        $chunk  = max(1000, (int) $this->option('chunk'));
        $dry    = (bool) $this->option('dry-run');
        $driver = DB::getDriverName();

        if (!Schema::hasTable('macro_output') || !Schema::hasColumn('macro_output', 'waybill')) {
            $this->error('Walang macro_output.waybill — hindi makaka-join.');
            return self::FAILURE;
        }

        // Quoted column name para sa uppercase na "ADDRESS" (case-sensitive sa pgsql).
        $addr = $driver === 'pgsql' ? 'mo."ADDRESS"' : 'mo.`ADDRESS`';

        foreach ($tables as $t) {
            if (!preg_match('/^[a-z0-9_]+$/', $t)) { $this->warn("Skip (invalid name): {$t}"); continue; }
            if (!Schema::hasTable($t))              { $this->warn("Skip (walang table): {$t}"); continue; }
            if (!Schema::hasColumn($t, 'address'))  { $this->warn("Skip ({$t}.address wala pa — patakbuhin muna ang migrate): {$t}"); continue; }

            $minId = (int) DB::table($t)->min('id');
            $maxId = (int) DB::table($t)->max('id');
            if ($maxId < $minId || $maxId === 0) { $this->info("{$t}: walang rows."); continue; }

            $this->info(($dry ? '[DRY-RUN] ' : '') . "{$t}: id {$minId}..{$maxId}, chunk {$chunk}");
            $total = 0;
            $bar   = $this->output->createProgressBar((int) ceil(($maxId - $minId + 1) / $chunk));

            for ($from = $minId; $from <= $maxId; $from += $chunk) {
                $to = $from + $chunk - 1;

                if ($dry) {
                    $n = (int) DB::selectOne(
                        "SELECT COUNT(*) AS c FROM {$t} t
                         JOIN macro_output mo ON mo.waybill = t.waybill_number
                         WHERE (t.address IS NULL OR t.address = '')
                           AND NULLIF(TRIM({$addr}), '') IS NOT NULL
                           AND t.id BETWEEN ? AND ?",
                        [$from, $to]
                    )->c;
                } else {
                    $sql = $driver === 'pgsql'
                        ? "UPDATE {$t} AS t SET address = TRIM({$addr})
                           FROM macro_output mo
                           WHERE mo.waybill = t.waybill_number
                             AND (t.address IS NULL OR t.address = '')
                             AND NULLIF(TRIM({$addr}), '') IS NOT NULL
                             AND t.id BETWEEN ? AND ?"
                        : "UPDATE {$t} t
                           JOIN macro_output mo ON mo.waybill = t.waybill_number
                           SET t.address = TRIM({$addr})
                           WHERE (t.address IS NULL OR t.address = '')
                             AND NULLIF(TRIM({$addr}), '') IS NOT NULL
                             AND t.id BETWEEN ? AND ?";
                    $n = DB::affectingStatement($sql, [$from, $to]);
                }

                $total += $n;
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
            $this->info(($dry ? '[DRY-RUN] mapupunan: ' : 'na-backfill: ') . number_format($total) . " rows sa {$t}");
        }

        return self::SUCCESS;
    }
}
