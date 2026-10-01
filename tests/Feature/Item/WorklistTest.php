<?php

namespace Tests\Feature\Item;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GET /item/worklist — sourcing worklists (CEO lang).
 * Inaasahang values = kinompute ng kamay mula sa fixtures sa ibaba.
 */
class WorklistTest extends ItemTestCase
{
    private const RANGE = '/item/worklist?start_date=2026-09-01&end_date=2026-09-30';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function supplier(string $name): int
    {
        return DB::table('suppliers')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Isang PO na may isang line. */
    private function po(int $supplierId, string $status, string $date, string $itemName, string $itemKey, int $qty, float $cost, ?int $received = null): void
    {
        $orderId = DB::table('supply_orders')->insertGetId([
            'supplier_id' => $supplierId, 'order_date' => $date, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supply_order_items')->insert([
            'supply_order_id' => $orderId, 'item_key' => $itemKey, 'item_name' => $itemName,
            'ordered_qty' => $qty, 'unit_cost' => $cost, 'received_qty' => $received,
            'line_total' => $qty * $cost, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedWorld(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 12:00:00', 'Asia/Manila'));
        $acme = $this->supplier('Acme');
        $beta  = $this->supplier('Beta');
        $gamma = $this->supplier('Gamma');

        // HAND GRIP: 3×(1x) + 2×(2x) = 7 units; walang PO, walang quote → hanapan
        foreach (['H1', 'H2', 'H3'] as $w) $this->order('1 x HAND GRIP', 'Page A', $w);
        foreach (['H4', 'H5'] as $w)       $this->order('2 x HAND GRIP', 'Page B', $w);

        // ANKLE WEIGHT: 2 units; discount line lang (negative cost = hindi PO line) → hanapan
        $this->order('ANKLE WEIGHT', 'Page A', 'A1');
        $this->order('ANKLE WEIGHT', 'Page A', 'A2');
        $this->po($acme, 'counted', '2026-08-01', 'ANKLE WEIGHT', 'ankle weight', 1, -50, 1);

        // YOGA MAT: 2 units; may quote, walang PO → may_quote
        $this->order('1 x YOGA MAT', 'Page A', 'Y1');
        $this->order('1 x YOGA MAT', 'Page A', 'Y2');
        DB::table('item_supplier_quotes')->insert([
            'item_key' => 'yoga mat', 'item_name' => '1 x YOGA MAT', 'supplier_id' => $acme,
            'price' => 150, 'photo_path' => 'supplier-quote-images/q.jpg', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('item_images')->insert(['item_name' => '1 x YOGA MAT', 'image_path' => 'item-images/y.jpg', 'created_at' => now(), 'updated_at' => now()]);

        // JUMP ROPE: 5 units; lumang PO (counted) + dalawang open PO (2 + 1 libre) = 3 → i_order, kulang 2
        foreach (['J1', 'J2', 'J3', 'J4', 'J5'] as $w) $this->order('JUMP ROPE', 'Page A', $w);
        $this->po($acme, 'counted', '2026-08-01', 'Jump Rope', 'jump rope', 20, 30, 20);
        $this->po($beta, 'ordered', '2026-09-25', '1 x JUMP ROPE', '1 x jump rope', 2, 28);   // PO name na may "1 x" prefix
        $this->po($gamma, 'ordered', '2026-09-28', 'Jump Rope', 'jump rope', 1, 0);          // libre (cost 0): open qty oo, presyo hindi

        // RESISTANCE BAND: 4 units; delivered pero hindi pa na-count (open) 10 → naka_order
        foreach (['R1', 'R2', 'R3', 'R4'] as $w) $this->order('RESISTANCE BAND', 'Page A', $w);
        $this->po($acme, 'delivered', '2026-09-20', 'Resistance Band', 'resistance band', 10, 40);
        DB::table('supply_item_settings')->insert(['item_name' => 'Resistance Band', 'lead_time_days' => 5, 'safety_days' => 3, 'created_at' => now(), 'updated_at' => now()]);

        // Hindi kasama: labas sa range, shipped, cancelled
        $this->order('OLD ITEM', 'Page A', 'O1', '2026-08-15');
        $this->order('SHIPPED ITEM', 'Page A', 'S1');
        $this->shipped('S1');
        $this->order('CANCELLED ITEM', 'Page A', 'C1', '2026-09-10', 'CANNOT PROCEED');
    }

    public function test_ceo_gets_items_classified_into_the_four_lists(): void
    {
        $this->seedWorld();

        $json = $this->actingAs($this->user())->getJson(self::RANGE)->assertOk()->json();

        $this->assertSame(['hanapan' => 2, 'may_quote' => 1, 'i_order' => 1, 'naka_order' => 1], $json['counts']);

        // Sorted by HOLD units desc, tapos pangalan (tie: ANKLE WEIGHT bago YOGA MAT)
        $this->assertSame(
            [['HAND GRIP', 7, 'hanapan', 0], ['JUMP ROPE', 5, 'i_order', 2], ['RESISTANCE BAND', 4, 'naka_order', 0], ['ANKLE WEIGHT', 2, 'hanapan', 0], ['YOGA MAT', 2, 'may_quote', 0]],
            array_map(fn ($r) => [$r['name'], $r['hold_units'], $r['list'], $r['shortfall']], $json['items'])
        );

        $byName = array_column($json['items'], null, 'name');

        $hand = $byName['HAND GRIP'];
        $this->assertSame('hand grip', $hand['key']);
        $this->assertSame([['name' => '2 x HAND GRIP', 'units' => 4], ['name' => '1 x HAND GRIP', 'units' => 3]], $hand['variants']);
        $this->assertSame([], $hand['suppliers']);
        $this->assertNull($hand['open_po']);
        $this->assertNull($hand['image_url']);
        $this->assertSame('2 x HAND GRIP', $hand['photo_item_name']);

        $rope = $byName['JUMP ROPE'];
        // assertEquals: ang JSON ay ginagawang 28 ang 28.0
        $this->assertEquals([['po', 'Beta', 28.0, '2026-09-25'], ['po', 'Acme', 30.0, '2026-08-01']],
            array_map(fn ($s) => [$s['source'], $s['supplier'], $s['price'], $s['date']], $rope['suppliers']));
        // 2 open orders: sinuma ang qty; supplier/petsa = pinakamatagal nang naghihintay (Beta, 09-25)
        $this->assertSame(['orders' => 2, 'supplier' => 'Beta', 'order_date' => '2026-09-25', 'days_since' => 5,
            'ordered_qty' => 3, 'received_qty' => 0, 'open_qty' => 3, 'lead_time_days' => null], $rope['open_po']);

        $band = $byName['RESISTANCE BAND'];
        $this->assertSame(['orders' => 1, 'supplier' => 'Acme', 'order_date' => '2026-09-20', 'days_since' => 10,
            'ordered_qty' => 10, 'received_qty' => 0, 'open_qty' => 10, 'lead_time_days' => 5], $band['open_po']);

        $yoga = $byName['YOGA MAT'];
        $this->assertEquals([['quote', 'Acme', 150.0]], array_map(fn ($s) => [$s['source'], $s['supplier'], $s['price']], $yoga['suppliers']));
        $this->assertStringEndsWith('/storage/item-images/y.jpg', $yoga['image_url']);
        $this->assertSame('1 x YOGA MAT', $yoga['photo_item_name']);
        $this->assertStringEndsWith('/storage/supplier-quote-images/q.jpg', $yoga['suppliers'][0]['photo_url']);
    }

    public function test_impossible_dates_fall_back_to_the_default_range(): void
    {
        $this->seedWorld();   // ngayon = 2026-09-30 → default range 2026-08-01 .. 2026-09-30

        $json = $this->actingAs($this->user())
            ->getJson('/item/worklist?start_date=2026-09-99&end_date=2026-09-30')->assertOk()->json();

        $this->assertContains('OLD ITEM', array_column($json['items'], 'name'));   // 2026-08-15 = loob ng default
        $this->assertSame(6, count($json['items']));
    }

    public function test_non_ceo_roles_get_the_empty_shape_or_404(): void
    {
        $this->seedWorld();
        $cases = ['Marketing' => 200, 'Marketing - OIC' => 200, 'Encoder' => 404];
        $i = 0;
        foreach ($cases as $role => $status) {
            $res = $this->actingAs($this->user($role, 'u' . (++$i) . '@example.test'))->getJson(self::RANGE);
            $res->assertStatus($status);
            if ($status === 200) {
                $this->assertStringContainsString('"counts":{}', $res->getContent(), $role);
                $this->assertSame(['ok' => true, 'counts' => [], 'items' => []], $res->json(), $role);
            }
        }
    }
}
