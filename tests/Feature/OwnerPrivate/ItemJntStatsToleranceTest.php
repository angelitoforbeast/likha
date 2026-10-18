<?php

namespace Tests\Feature\OwnerPrivate;

use App\Services\ItemAliasResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * /owner/private/item-summary sa sqlite: ang RTS / DEL / INT cell ng item row ay kumukuha ng J&T stats
 * mula sa lahat ng bucket ng parehong page+item na nasa loob ng ₱1 ng presyo ng row (sinusuma).
 */
class ItemJntStatsToleranceTest extends OwnerPrivateTestCase
{
    private const END = '2026-10-17';
    private const PAGE = 'Pahina Delta';
    private const ITEM = '1 x Zorblat Curtain';

    protected function setUp(): void
    {
        parent::setUp();

        // Mga table na gawa sa kamay: ang mga column lang na binabasa ng itemSummary.
        Schema::create('macro_output', function (Blueprint $t) {
            $t->id();
            $t->string('PAGE')->nullable();
            $t->string('ITEM_NAME')->nullable();
            $t->string('STATUS')->nullable();
            $t->string('COD')->nullable();
            $t->date('ts_date')->nullable();
            $t->timestamps();
        });
        Schema::create('ads_manager_reports', function (Blueprint $t) {
            $t->id();
            $t->date('day')->nullable();
            $t->string('page_name')->nullable();
            $t->string('amount_spent_php')->nullable();
        });
        Schema::create('page_item_settings', function (Blueprint $t) {
            $t->id();
            $t->string('page_name');
            $t->string('item_name');
            $t->decimal('rts_pct', 6, 2)->nullable();
            $t->date('effective_date');
            $t->string('comment')->nullable();
            $t->string('item_value_comment')->nullable();
            $t->string('promo')->nullable();
            $t->integer('mode_cod_int')->nullable();
            $t->timestamps();
        });
        Schema::create('cogs_ceo', function (Blueprint $t) {
            $t->id();
            $t->date('date');
            $t->string('item_name');
            $t->decimal('unit_cost', 12, 2)->nullable();
            $t->timestamps();
        });
        Schema::create('supply_excluded_pages', function (Blueprint $t) {
            $t->id();
            $t->string('page_name');
            $t->timestamps();
        });
        Schema::create('from_jnts', function (Blueprint $t) {
            $t->id();
            $t->string('sender')->nullable();
            $t->string('item_name')->nullable();
            $t->string('cod')->nullable();
            $t->string('status')->nullable();
            $t->string('rts_reason')->nullable();
            $t->dateTime('submission_time')->nullable();
        });
        Schema::create('page_sender_mappings', function (Blueprint $t) {
            $t->id();
            $t->string('PAGE')->nullable();
            $t->string('SENDER_NAME')->nullable();
        });
        DB::table('page_sender_mappings')->insert(['PAGE' => self::PAGE, 'SENDER_NAME' => 'Sender Delta']);
        DB::table('fee_settings')->insert([
            'setting_key' => 'shipping_fee_per_order', 'setting_value' => 33, 'effective_date' => '2025-01-01',
            'host_scope' => 'likha', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function migrationPaths(): array
    {
        return array_merge(parent::migrationPaths(), [
            'database/migrations/2025_09_08_134839_create_cogs_table.php',
            'database/migrations/2026_04_24_000009_create_daily_page_primary_item_table.php',
            'database/migrations/2026_03_05_000001_create_fee_settings_table.php',
        ]);
    }

    /** Ang row ng page sa end date, may presyong $price. */
    private function row(int $price): void
    {
        DB::table('daily_page_primary_item')->insert([
            'ts_date' => self::END, 'page_label' => self::PAGE, 'page_key' => strtolower(self::PAGE),
            'primary_item' => self::ITEM, 'primary_item_key' => ItemAliasResolver::normalize(self::ITEM),
            'primary_orders' => 10, 'total_orders_all' => 10, 'primary_mode_cod' => $price,
        ]);
        DB::table('macro_output')->insert(array_map(fn () => [
            'PAGE' => self::PAGE, 'ITEM_NAME' => self::ITEM, 'STATUS' => 'Proceed', 'COD' => (string) $price, 'ts_date' => self::END,
        ], range(1, 10)));
    }

    /** Mga J&T shipment sa loob ng 60 araw bago ang end date, sa COD na $cod. */
    private function shipments(int $cod, int $rts, int $del, int $int): void
    {
        $rows = [];
        foreach (['Returned' => $rts, 'Delivered' => $del, 'In Transit' => $int] as $status => $n) {
            for ($i = 0; $i < $n; $i++) {
                $rows[] = [
                    'sender' => 'Sender Delta', 'item_name' => self::ITEM, 'cod' => '₱' . $cod, 'status' => $status,
                    'rts_reason' => '', 'submission_time' => '2026-10-01 10:00:00',
                ];
            }
        }
        DB::table('from_jnts')->insert($rows);
    }

    /** Ang J&T value ng row ng page. */
    private function cell(): array
    {
        $this->actingAs($this->user());
        $res = $this->getJson('/owner/private/item-summary?' . http_build_query(['start_date' => self::END, 'end_date' => self::END]))->assertOk();
        $row = array_column($res->json('rows'), null, 'page_key')[strtolower(self::PAGE)];

        return array_intersect_key($row, array_flip([
            'jnt_rts_pct', 'jnt_rts_cnt', 'jnt_del_pct', 'jnt_del_cnt', 'jnt_transit_pct', 'jnt_transit_cnt', 'jnt_total',
        ]));
    }

    private function expected(?float $rp, ?int $rc, ?float $dp, ?int $dc, ?float $tp, ?int $tc, ?int $total): array
    {
        return [
            'jnt_rts_pct' => $rp, 'jnt_rts_cnt' => $rc, 'jnt_del_pct' => $dp, 'jnt_del_cnt' => $dc,
            'jnt_transit_pct' => $tp, 'jnt_transit_cnt' => $tc, 'jnt_total' => $total,
        ];
    }

    public function test_S_058_1_an_end_date_price_one_peso_off_gets_the_bucket_stats(): void
    {
        $this->row(298);
        $this->shipments(299, 405, 237, 126);

        $this->assertEquals($this->expected(52.7, 405, 30.9, 237, 16.4, 126, 768), $this->cell());
    }

    public function test_S_058_2_buckets_on_both_sides_within_one_peso_are_summed(): void
    {
        $this->row(298);
        $this->shipments(297, 1, 1, 0);
        $this->shipments(298, 2, 3, 1);
        $this->shipments(299, 3, 4, 5);

        // 6 RTS, 8 DEL, 6 INT sa 20
        $this->assertEquals($this->expected(30.0, 6, 40.0, 8, 30.0, 6, 20), $this->cell());
    }

    public function test_S_058_3_a_bucket_two_pesos_away_is_not_included(): void
    {
        $this->row(298);
        $this->shipments(300, 5, 5, 0);

        $this->assertEquals($this->expected(null, null, null, null, null, null, null), $this->cell());
    }

    public function test_S_058_4_an_exact_bucket_alone_works_as_before(): void
    {
        $this->row(299);
        $this->shipments(299, 1, 2, 1);
        $this->shipments(301, 9, 9, 9);

        $this->assertEquals($this->expected(25.0, 1, 50.0, 2, 25.0, 1, 4), $this->cell());
    }
}
