<?php

namespace Tests\Feature\RtsProjection;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\OwnerPrivate\MacroOutputTestCase;

/**
 * Base ng RTS projection tests (sqlite): macro_output + from_jnts na gawa sa kamay, at ang orasan ay naka-freeze.
 * Ngayon = 2026-10-23 12:00 Manila.
 */
abstract class RtsProjectionTestCase extends MacroOutputTestCase
{
    protected int $wb = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-23 12:00:00', 'Asia/Manila'));

        Schema::create('from_jnts', function (Blueprint $t) {
            $t->id();
            $t->string('waybill_number')->nullable();
            $t->dateTime('submission_time')->nullable();
            $t->string('status')->nullable();
            $t->text('status_logs')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function migrationPaths(): array
    {
        return array_merge(parent::migrationPaths(), [
            'database/migrations/2026_05_04_000001_create_page_item_settings_log_table.php',
            'database/migrations/2026_05_15_140000_add_cogs_ceo_columns_to_page_item_settings_log.php',
            'database/migrations/2026_11_01_100000_add_source_to_page_item_settings_log.php',
        ]);
    }

    /**
     * Isang order na may J&T row. $logs: [[to, batch_at], ...]. Ang ts_date ay ang araw ng $sub.
     */
    protected function order(string $page, string $item, string $sub, string $status, array $logs = []): void
    {
        $wb = 'WB'.(++$this->wb);
        DB::table('macro_output')->insert([
            'PAGE' => $page, 'ITEM_NAME' => $item, 'STATUS' => 'Proceed', 'COD' => '599',
            'waybill' => $wb, 'ts_date' => substr($sub, 0, 10),
        ]);
        DB::table('from_jnts')->insert([
            'waybill_number' => $wb, 'submission_time' => $sub, 'status' => $status,
            'status_logs' => json_encode(array_map(fn ($l) => ['from' => null, 'to' => $l[0], 'batch_at' => $l[1]], $logs)),
        ]);
    }

    protected function orders(int $n, string $page, string $item, string $sub, string $status, array $logs = []): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->order($page, $item, $sub, $status, $logs);
        }
    }

    /** Naka-save na rate table: pooled[age] = p, items['page||item'][age] = p. */
    protected function rates(array $pooled, array $items = []): void
    {
        $wrap = fn ($m) => array_map(fn ($p) => ['p' => $p, 'n' => 100], $m);
        DB::table('app_settings')->updateOrInsert(['key' => 'rts_projection_rates'], ['value' => json_encode([
            'learned_at' => '2026-10-23 03:30:00',
            'pooled'     => $wrap($pooled),
            'items'      => array_map($wrap, $items),
        ])]);
    }
}
