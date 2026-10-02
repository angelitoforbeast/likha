<?php

namespace Tests\Feature\Item;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GET /item/stock — stock gauge, DOI, order qty, item values.
 * Inaasahang values = worked examples A–D ng handoff 003 (section 5), hindi kinompute ulit.
 */
class StockEndpointTest extends ItemTestCase
{
    private const URL = '/item/stock?start_date=2026-09-01&end_date=2026-10-02';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['cogs', 'cogs_ceo'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->date('date');
                $t->string('item_name');
                $t->decimal('unit_cost', 12, 2)->nullable();
                $t->timestamps();
            });
        }

        DB::table('app_settings')->updateOrInsert(
            ['key' => 'item_stock_start'],
            ['value' => '2026-09-25', 'created_at' => now(), 'updated_at' => now()]
        );
    }

    /** $n orders ng $item; waybill = $wbPrefix.$i (o null). Returns ang mga waybill. */
    private function rows(string $item, int $n, string $date, ?string $wbPrefix, string $status = 'PROCEED'): array
    {
        $wbs = [];
        for ($i = 1; $i <= $n; $i++) {
            $wb = $wbPrefix === null ? null : $wbPrefix . $i;
            $this->order($item, 'Page A', $wb, $date, $status);
            if ($wb !== null) $wbs[] = $wb;
        }
        return $wbs;
    }

    private function shipAll(array $waybills, string $at = '2026-09-26 10:00:00'): void
    {
        foreach ($waybills as $w) $this->shipped($w, $at);
    }

    /** Demand + HOLD ng worked example A/B: 140 units sa 14 araw (10/araw), HOLD 50. */
    private function glowTapeOrders(bool $someLeft): void
    {
        $this->rows('1 x GLOW TAPE', 30, '2026-09-19', 'GH');          // held
        $leftOnes = $this->rows('1 x GLOW TAPE', 40, '2026-09-19', $someLeft ? 'GL' : null);
        $this->rows('1 x GLOW TAPE', 30, '2026-09-19', null);
        $this->rows('2 x GLOW TAPE', 10, '2026-09-19', 'GM');          // held
        $leftTwos = $this->rows('2 x GLOW TAPE', 10, '2026-09-19', $someLeft ? 'GN' : null);
        $this->rows('1 x GLOW TAPE', 5, '2026-09-19', null, 'CANNOT PROCEED');
        $this->shipAll(array_merge($leftOnes, $leftTwos));             // 40 + 10×2 = 60 units left
    }

    private function po(string $status, string $itemKey, int $ordered, ?int $received = null, ?string $countedAt = null, float $unitCost = 10): void
    {
        $supplier = DB::table('suppliers')->insertGetId(['name' => 'Acme ' . $itemKey . $status, 'created_at' => now(), 'updated_at' => now()]);
        $orderId  = DB::table('supply_orders')->insertGetId([
            'supplier_id' => $supplier, 'order_date' => '2026-09-20', 'status' => $status,
            'counted_at' => $countedAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supply_order_items')->insert([
            'supply_order_id' => $orderId, 'item_key' => $itemKey, 'item_name' => $itemKey,
            'ordered_qty' => $ordered, 'unit_cost' => $unitCost, 'received_qty' => $received,
            'line_total' => $ordered * $unitCost, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function stock(string $role = 'CEO', string $query = ''): \Illuminate\Testing\TestResponse
    {
        $email = strtolower(str_replace(' ', '', $role)) . uniqid() . '@example.test';

        return $this->actingAs($this->user($role, $email))->getJson(self::URL . $query);
    }

    public function test_example_a_stock_incoming_doi_and_order_by(): void
    {
        $this->glowTapeOrders(true);
        $this->po('ordered', 'Glow Tape', 200);
        $this->po('counted', 'Glow Tape', 100, 100, '2026-09-26 09:00:00');

        $item = $this->stock()->assertOk()->json('items.glow tape');

        $this->assertSame('GLOW TAPE', $item['name']);
        $this->assertSame(50, $item['hold_units']);
        $this->assertEquals(10, $item['units_per_day']);
        $this->assertSame(200, $item['incoming']);
        $this->assertSame(40, $item['stock']);
        $this->assertFalse($item['stock_needs_count']);
        $this->assertSame(0, $item['order_qty']);
        $this->assertEquals(19.0, $item['doi']);
        $this->assertSame('2026-10-14', $item['order_by']);
        $this->assertSame('green', $item['colour']);
        $this->assertSame([7, 3], [$item['lead'], $item['safety']]);
        $this->assertSame(['1 x GLOW TAPE', '2 x GLOW TAPE'], $item['variants']);
    }

    public function test_example_b_no_stock_means_order_now_in_red(): void
    {
        $this->glowTapeOrders(false);

        $item = $this->stock()->assertOk()->json('items.glow tape');

        $this->assertSame(0, $item['stock']);
        $this->assertSame(0, $item['incoming']);
        $this->assertSame(150, $item['order_qty']);
        $this->assertEquals(-5.0, $item['doi']);
        $this->assertSame('now', $item['order_by']);
        $this->assertSame('red', $item['colour']);
    }

    public function test_example_c_hold_without_recent_demand_has_no_doi(): void
    {
        $this->rows('1 x LAMP', 12, '2026-09-10', 'LH');   // labas ng 14-araw window, nasa range

        $item = $this->stock()->assertOk()->json('items.lamp');

        $this->assertEquals(0, $item['units_per_day']);
        $this->assertNull($item['doi']);
        $this->assertNull($item['order_by']);
        $this->assertNull($item['colour']);
        $this->assertSame(12, $item['order_qty']);
    }

    public function test_example_d_negative_raw_stock_shows_zero_and_needs_count(): void
    {
        $this->shipAll($this->rows('1 x ROPE', 25, '2026-09-26', 'RP'));

        $item = $this->stock()->assertOk()->json('items.rope');

        $this->assertSame(-25, $item['stock_raw']);
        $this->assertSame(0, $item['stock']);
        $this->assertTrue($item['stock_needs_count']);
    }

    public function test_waybill_first_seen_by_jnt_before_start_is_not_left(): void
    {
        $this->rows('1 x PEN', 2, '2026-09-26', 'PN');
        $this->shipped('PN1', '2026-09-20 08:00:00');   // unang J&T record bago START
        $this->shipped('PN1', '2026-09-26 08:00:00');
        $this->shipped('PN2', '2026-09-26 08:00:00');   // ito lang ang umalis mula START

        $this->assertSame(-1, $this->stock()->assertOk()->json('items.pen.stock_raw'));
    }

    public function test_stock_fields_are_null_without_a_start_date(): void
    {
        DB::table('app_settings')->where('key', 'item_stock_start')->delete();
        $this->glowTapeOrders(true);
        $this->po('ordered', 'Glow Tape', 200);

        $json = $this->stock()->assertOk()->json();
        $item = $json['items']['glow tape'];

        $this->assertFalse($json['stock_ready']);
        foreach (['stock', 'stock_raw', 'stock_needs_count', 'doi', 'order_qty', 'order_by', 'colour'] as $f) {
            $this->assertNull($item[$f], $f);
        }
        $this->assertSame(50, $item['hold_units']);
        $this->assertSame(200, $item['incoming']);
    }

    public function test_values_map_gives_item_value_for_a_hold_only_item(): void
    {
        $this->rows('1 x LAMP', 2, '2026-09-10', 'LH');
        DB::table('cogs')->insert([
            ['date' => '2026-08-01', 'item_name' => '1 x LAMP', 'unit_cost' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['date' => '2026-09-20', 'item_name' => '1 x LAMP', 'unit_cost' => 12.5, 'created_at' => now(), 'updated_at' => now()],
            ['date' => '2026-10-05', 'item_name' => '1 x LAMP', 'unit_cost' => 99, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $json = $this->stock()->assertOk()->json();

        $this->assertEquals(12.5, $json['values']['1 x lamp']['item_value']);
    }

    public function test_item_value_ceo_only_for_ceo_viewing_as_ceo(): void
    {
        $this->rows('1 x LAMP', 1, '2026-09-10', 'LH');
        DB::table('cogs')->insert(['date' => '2026-09-01', 'item_name' => '1 x LAMP', 'unit_cost' => 12, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cogs_ceo')->insert(['date' => '2026-09-01', 'item_name' => '1 x LAMP', 'unit_cost' => 8, 'created_at' => now(), 'updated_at' => now()]);

        $ceo = $this->stock('CEO')->assertOk()->json('values.1 x lamp');
        $this->assertEquals(8, $ceo['item_value_ceo']);

        $asMarketing = $this->stock('CEO', '&view_as=marketing')->assertOk()->json('values.1 x lamp');
        $this->assertArrayNotHasKey('item_value_ceo', $asMarketing);
        $this->assertEquals(12, $asMarketing['item_value']);

        $marketing = $this->stock('Marketing', '&view_as=ceo')->assertOk()->json('values.1 x lamp');
        $this->assertArrayNotHasKey('item_value_ceo', $marketing);
    }

    public function test_marketing_gets_the_same_numbers(): void
    {
        $this->glowTapeOrders(true);
        $this->po('counted', 'Glow Tape', 100, 100, '2026-09-26 09:00:00');

        $item = $this->stock('Marketing')->assertOk()->json('items.glow tape');

        $this->assertSame(40, $item['stock']);
        $this->assertSame(50, $item['hold_units']);
    }

    public function test_doi_is_exact_then_rounded_and_the_rounded_value_decides_colour_and_date(): void
    {
        // 10 units sa 3 araw (upd 3.333…); (0 + 100 − 0) × 3 / 10 = 30 eksakto
        $this->rows('1 x NOISE', 10, '2026-09-30', null);
        $this->po('ordered', 'Noise', 100);

        $noise = $this->stock()->assertOk()->json('items.noise');
        $this->assertEquals(30.0, $noise['doi']);
        $this->assertSame('2026-10-25', $noise['order_by']);   // 10-02 + 23 araw
        $this->assertSame('green', $noise['colour']);

        // [item, incoming, doi, order_by, colour] — upd 10/araw (10 orders sa end date), lead 7, safety 3
        foreach ([
            ['EDGEA', 70,  7.0,  '2026-10-02', 'amber'],   // DOI = lead → hindi "now"
            ['EDGEB', 100, 10.0, '2026-10-05', 'green'],   // DOI = lead + safety
            ['EDGEC', 69,  6.9,  'now',        'red'],
        ] as [$name, $incoming, $doi, $orderBy, $colour]) {
            $this->rows("1 x $name", 10, '2026-10-02', null);
            $this->po('ordered', $name, $incoming);
            $item = $this->stock()->assertOk()->json('items.' . strtolower($name));
            $this->assertEquals($doi, $item['doi'], $name);
            $this->assertSame($orderBy, $item['order_by'], $name);
            $this->assertSame($colour, $item['colour'], $name);
        }

        // 6.99 ay nagro-round sa 7.0 → ang 7.0 ang nagpapasya (amber), hindi ang 6.99
        $this->rows('1 x ROUNDUP', 100, '2026-10-02', null);
        $this->po('ordered', 'Roundup', 699);
        $up = $this->stock()->assertOk()->json('items.roundup');
        $this->assertEquals(7.0, $up['doi']);
        $this->assertSame('amber', $up['colour']);
        $this->assertSame('2026-10-02', $up['order_by']);
    }

    public function test_order_qty_uses_stock_after_the_zero_floor(): void
    {
        $this->shipAll($this->rows('1 x ROPE', 25, '2026-09-10', 'RP'));
        $this->rows('1 x ROPE', 5, '2026-09-10', 'RH');   // hold, walang recent demand

        $item = $this->stock()->assertOk()->json('items.rope');

        $this->assertSame(-25, $item['stock_raw']);
        $this->assertSame(5, $item['order_qty']);          // hindi 30
    }

    public function test_discount_lines_are_not_received_or_incoming(): void
    {
        $this->rows('1 x TAPE', 1, '2026-09-10', 'TH');
        $this->po('counted', 'Tape', 100, 100, '2026-09-26 09:00:00');
        $this->po('counted', 'Tape', 1, 20, '2026-09-26 09:00:00', -5);
        $this->po('ordered', 'Tape', 10);
        $this->po('ordered', 'Tape', 30, null, null, -5);

        $item = $this->stock()->assertOk()->json('items.tape');

        $this->assertSame(100, $item['stock']);
        $this->assertSame(10, $item['incoming']);
    }

    public function test_po_counted_before_start_does_not_add_to_received(): void
    {
        $this->rows('1 x TAPE', 1, '2026-09-10', 'TH');
        $this->po('counted', 'Tape', 100, 100, '2026-09-20 09:00:00');
        $this->po('counted', 'Tape', 7, 7, '2026-09-25 00:00:00');

        $this->assertSame(7, $this->stock()->assertOk()->json('items.tape.stock_raw'));
    }

    public function test_delivered_po_counts_as_incoming_but_counted_po_does_not(): void
    {
        $this->rows('1 x POT', 1, '2026-09-10', 'PH');
        $this->po('delivered', 'Pot', 30, 10);
        $this->po('counted', 'Pot', 50, 10, '2026-09-26 09:00:00');

        $item = $this->stock()->assertOk()->json('items.pot');

        $this->assertSame(20, $item['incoming']);
        $this->assertSame(10, $item['stock']);
    }

    public function test_cancelled_orders_and_null_submission_time_are_not_left(): void
    {
        $this->shipAll($this->rows('1 x PEN', 1, '2026-09-10', 'PN'));
        $this->shipAll($this->rows('1 x PEN', 1, '2026-09-10', 'PC', 'CANNOT PROCEED'));
        $this->shipAll($this->rows('1 x PEN', 1, '2026-09-10', 'PO', 'ODZ'));
        $this->rows('1 x PEN', 1, '2026-09-10', 'PX');
        $this->shipped('PX', null);

        $this->assertSame(-1, $this->stock()->assertOk()->json('items.pen.stock_raw'));
    }

    public function test_lead_and_safety_come_from_settings_lowest_id_wins(): void
    {
        $this->rows('1 x GLOW TAPE', 1, '2026-09-10', 'GH');
        $now = ['created_at' => now(), 'updated_at' => now()];
        DB::table('supply_item_settings')->insert([
            ['item_name' => 'Glow Tape', 'lead_time_days' => 5, 'safety_days' => 2] + $now,
            ['item_name' => 'GLOW TAPE', 'lead_time_days' => 9, 'safety_days' => 8] + $now,
        ]);

        $item = $this->stock()->assertOk()->json('items.glow tape');

        $this->assertSame([5, 2], [$item['lead'], $item['safety']]);
    }

    public function test_category_assignment_and_category_list(): void
    {
        $this->rows('1 x GLOW TAPE', 1, '2026-09-10', 'GH');
        DB::table('item_category_assignments')->insert([
            'item_key' => 'glow tape', 'category_id' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $json = $this->stock()->assertOk()->json();

        $this->assertSame(2, $json['items']['glow tape']['category_id']);
        $this->assertSame('Ilaw at Kuryente', $json['items']['glow tape']['category']);
        $this->assertSame(
            ['Bahay at Paglilinis', 'Ilaw at Kuryente', 'Sasakyan at Motor', 'Repair at DIY',
             'Health at Beauty', 'Fashion at Accessories', 'Office at School', 'Iba pa'],
            array_column($json['categories'], 'name')
        );
    }

    public function test_item_value_is_null_without_a_cogs_row(): void
    {
        $this->rows('1 x LAMP', 1, '2026-09-10', 'LH');

        $this->assertNull($this->stock()->assertOk()->json('values.1 x lamp.item_value'));
    }

    public function test_unknown_role_gets_404(): void
    {
        $this->stock('Warehouse')->assertNotFound();
    }
}
