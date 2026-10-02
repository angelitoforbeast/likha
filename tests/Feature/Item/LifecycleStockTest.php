<?php

namespace Tests\Feature\Item;

use Illuminate\Support\Facades\DB;

/**
 * GET /item/stock — lifecycle at ang normal / lugi na result sets (handoff 004).
 * Inaasahang values = section 5 ng handoff 004, nakasulat nang kamay (hindi kinompute ulit).
 * Lead 7, stock 0, incoming 0, HOLD 50, 14-day velocity 10/araw maliban kung sinabi.
 */
class LifecycleStockTest extends ItemTestCase
{
    private const URL = '/item/stock?start_date=2026-09-01&end_date=2026-10-02';

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('app_settings')->updateOrInsert(
            ['key' => 'item_stock_start'],
            ['value' => '2026-09-25', 'created_at' => now(), 'updated_at' => now()]
        );
    }

    private function stock(): array
    {
        $ceo = $this->ceo ??= $this->user();

        return $this->actingAs($ceo)->getJson(self::URL)->assertOk()->json('items');
    }

    private ?\App\Models\User $ceo = null;

    /** PO na may isang line (item_key = $itemKey). */
    private function po(string $status, string $itemKey, int $ordered, ?int $received = null, ?string $countedAt = null): void
    {
        $supplier = DB::table('suppliers')->insertGetId(['name' => 'Acme ' . $itemKey, 'created_at' => now(), 'updated_at' => now()]);
        $orderId  = DB::table('supply_orders')->insertGetId([
            'supplier_id' => $supplier, 'order_date' => '2026-09-20', 'status' => $status,
            'counted_at' => $countedAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supply_order_items')->insert([
            'supply_order_id' => $orderId, 'item_key' => $itemKey, 'item_name' => $itemKey,
            'ordered_qty' => $ordered, 'unit_cost' => 10, 'received_qty' => $received,
            'line_total' => $ordered * 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** $n orders ng $item sa $date; $holdN sa unahan ay may waybill (HOLD), ang iba ay wala. */
    private function rows(string $item, int $n, string $date, int $holdN = 0, string $status = 'PROCEED'): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $this->order($item, 'Page A', $i <= $holdN ? 'W' . substr(md5($item . $date . $status), 0, 6) . $i : null, $date, $status);
        }
    }

    private function setting(string $item, array $cols): void
    {
        DB::table('supply_item_settings')->insert(['item_name' => $item, 'created_at' => now(), 'updated_at' => now()] + $cols);
    }

    /**
     * 140 units sa window (14-day v = 10, unang order 09-19), 50 dito HOLD.
     * $u7 = ilan sa 140 ang nasa huling 7 araw (end−6 = 09-26).
     */
    private function formulaItem(string $name, string $lifecycle, int $u7 = 0, ?int $palugitOverride = null): void
    {
        $held = min(50, 140 - $u7);
        $this->rows("1 x $name", 140 - $u7, '2026-09-19', $held);
        $this->rows("1 x $name", $u7, '2026-10-02', 50 - $held);
        $this->setting("1 x $name", ['lifecycle_override' => $lifecycle, 'palugit_override' => $palugitOverride]);
    }

    public function test_every_section_5_row_gives_its_order_qty_in_both_sets(): void
    {
        // [lifecycle, u7, palugit_override, normal qty, lugi qty, gated]
        $cases = [
            'NEWX'   => ['new',         0,   null, 150, 150, false],
            'ACTX'   => ['active',      0,   null, 190, 190, false],
            'CONSX'  => ['consistent',  0,   null, 220, 150, true],
            'SCALX'  => ['scaling',     105, null, 365, 150, true],   // 7-day v = 15
            'SCALZ'  => ['scaling',     0,   null, 260, 150, true],   // 7-day v = 0, 14-day v = 10 wins (amendment 004-1)
            'DECLX'  => ['declining',   0,   null, 120, 120, false],
            'PHASEX' => ['phasing_out', 0,   null, 50,  50,  false],
            'DORMX'  => ['dormant',     0,   null, 50,  50,  false],
            'OVERX'  => ['consistent',  0,   5,    170, 170, true],
        ];
        foreach ($cases as $name => [$lc, $u7, $po]) $this->formulaItem($name, $lc, $u7, $po);

        $items = $this->stock();

        foreach ($cases as $name => [$lc, , $po, $normal, $lugi, $gated]) {
            $it = $items[strtolower($name)];
            $this->assertSame($lc, $it['lifecycle'], $name);
            $this->assertFalse($it['lifecycle_auto'], $name);
            $this->assertSame($gated, $it['gated'], $name);
            $this->assertSame($po, $it['palugit_override'], $name);
            $this->assertSame($normal, $it['normal']['order_qty'], "$name normal");
            $this->assertSame($lugi, $it['lugi']['order_qty'], "$name lugi");
            $this->assertEquals(10, $it['lugi']['units_per_day'], $name);
        }
        foreach (['newx' => 3, 'scalx' => 14, 'consx' => 10, 'actx' => 7, 'declx' => 0, 'overx' => 5] as $k => $p) {
            $this->assertSame($p, $items[$k]['normal']['palugit'], $k);
        }
        $this->assertSame(5, $items['overx']['lugi']['palugit']);
        $this->assertEquals(15, $items['scalx']['normal']['units_per_day']);
        $this->assertSame(3, $items['scalx']['lugi']['palugit']);
        $this->assertEquals(10, $items['scalz']['normal']['units_per_day']);
        $this->assertSame(14, $items['scalz']['normal']['palugit']);
        $this->assertEquals(-5.0, $items['scalz']['normal']['doi']);   // (0 − 50) × 14 ÷ 140, 14-day units/days

        foreach (['phasex', 'dormx'] as $k) {
            foreach (['normal', 'lugi'] as $set) {
                $s = $items[$k][$set];
                $this->assertSame('walang_benta', $s['doi_note'], "$k $set");
                $this->assertSame('grey', $s['colour'], "$k $set");
                $this->assertNull($s['doi']);
                $this->assertNull($s['order_by']);
                $this->assertNull($s['palugit']);
                $this->assertEquals(10, $s['units_per_day']);
            }
        }
        $this->assertSame('Scaling', trim(preg_replace('/[^A-Za-z ]/u', '', $items['scalx']['lifecycle_label'])));
    }

    public function test_scaling_doi_example_is_16_point_7_amber(): void
    {
        $this->formulaItem('DOIX', 'scaling', 105);
        $this->po('counted', 'Doix', 300, 300, '2026-09-26 09:00:00');

        $s = $this->stock()['doix']['normal'];

        $this->assertEquals(16.7, $s['doi']);
        $this->assertSame('amber', $s['colour']);
        $this->assertNull($s['doi_note']);
    }

    public function test_velocity_under_half_a_unit_a_day_is_halos_walang_benta_but_keeps_the_formula(): void
    {
        // 3 units mula 09-23 (10 araw) = 0.3/araw (7-day = 0, kaya 14-day ang nananalo sa Scaling); HOLD 12 (3 dito + 9 mas luma)
        // [lifecycle, normal qty]: active 12 + 0.3 × 14 = 16.2 → 17; scaling 12 + 0.3 × 21 = 18.3 → 19
        $cases = ['SLOW' => ['active', 17], 'SLOWS' => ['scaling', 19]];
        foreach ($cases as $name => [$lc]) {
            $this->rows("1 x $name", 3, '2026-09-23', 3);
            $this->rows("1 x $name", 9, '2026-09-10', 9);
            $this->setting("1 x $name", ['lifecycle_override' => $lc]);
        }

        $items = $this->stock();

        foreach ($cases as $name => [, $qty]) {
            $s = $items[strtolower($name)]['normal'];

            $this->assertEquals(0.3, $s['units_per_day'], $name);
            $this->assertSame('halos_walang_benta', $s['doi_note'], $name);
            $this->assertSame('grey', $s['colour'], $name);
            $this->assertSame($qty, $s['order_qty'], $name);
            $this->assertEquals(-40.0, $s['doi'], $name);   // (0 − 12) × 10 ÷ 3
            $this->assertSame('now', $s['order_by'], $name);
        }
    }

    public function test_natural_lifecycle_matches_the_jnt_supply_definitions(): void
    {
        $old = '2026-01-01';
        // bawat item: [first order, prev units (09-10, HOLD), recent units (09-25, wala sa HOLD)]
        $this->rows('1 x NEWN', 5, '2026-09-25');
        $this->rows('1 x SCALN', 1, $old);
        $this->rows('1 x SCALN', 5, '2026-09-10', 5);
        $this->rows('1 x SCALN', 20, '2026-09-25');
        $this->rows('1 x CONSN', 1, $old);
        $this->rows('1 x CONSN', 14, '2026-09-10', 14);
        $this->rows('1 x CONSN', 14, '2026-09-25');
        $this->rows('1 x ACTN', 1, '2026-08-20');
        $this->rows('1 x ACTN', 10, '2026-09-10', 10);
        $this->rows('1 x ACTN', 10, '2026-09-25');
        $this->rows('1 x DECLN', 1, $old);
        $this->rows('1 x DECLN', 20, '2026-09-10', 20);
        $this->rows('1 x DECLN', 5, '2026-09-25');
        $this->rows('1 x PHASEN', 1, $old);
        $this->rows('1 x PHASEN', 10, '2026-09-10', 10);
        $this->rows('1 x DORMN', 10, '2026-09-02', 10);       // HOLD lang, labas ng 28 araw
        $this->rows('1 x OVRN', 5, '2026-09-25');             // natural: new
        $this->setting('1 x OVRN', ['lifecycle_override' => 'declining']);

        $items = $this->stock();

        foreach (['newn' => 'new', 'scaln' => 'scaling', 'consn' => 'consistent', 'actn' => 'active',
                  'decln' => 'declining', 'phasen' => 'phasing_out', 'dormn' => 'dormant'] as $k => $lc) {
            $this->assertSame($lc, $items[$k]['lifecycle'], $k);
            $this->assertTrue($items[$k]['lifecycle_auto'], $k);
        }
        $this->assertSame('declining', $items['ovrn']['lifecycle']);
        $this->assertFalse($items['ovrn']['lifecycle_auto']);
    }

    public function test_lifecycle_counts_cancelled_orders_but_velocity_does_not(): void
    {
        $this->rows('1 x VOID', 6, '2026-09-25', 0, 'CANNOT PROCEED');
        $this->po('ordered', 'Void', 10);   // para lumabas ang item

        $it = $this->stock()['void'];

        $this->assertSame('new', $it['lifecycle']);
        $this->assertEquals(0, $it['normal']['units_per_day']);
    }

    public function test_item_missing_from_the_cached_first_dates_is_classified_from_its_window_first_date(): void
    {
        $this->rows('1 x OLDC', 3, '2026-09-25');
        $this->stock();                                  // init ng cache (12 oras)

        $this->rows('1 x FRESH', 4, '2026-09-30');       // bagong item pagkatapos ma-cache
        $it = $this->stock()['fresh'];

        $this->assertSame('new', $it['lifecycle']);      // walang window_first fallback = 'scaling'
    }

    public function test_palugit_default_is_read_from_supply_settings(): void
    {
        $this->formulaItem('ACTS', 'active');
        DB::table('supply_settings')->where('key', 'palugit_active')->update(['value' => '10']);

        $this->assertSame(220, $this->stock()['acts']['normal']['order_qty']);   // 50 + 10 × 17
    }
}
