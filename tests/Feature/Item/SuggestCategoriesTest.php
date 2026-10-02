<?php

namespace Tests\Feature\Item;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * items:suggest-categories — keyword rules (config/item_categories.php), dry run bilang default.
 * "Ngayon" = 2026-10-02 Manila → 90-araw window ay nagsisimula sa 2026-07-04.
 */
class SuggestCategoriesTest extends ItemTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-02 10:00:00', 'Asia/Manila'));

        $this->order('1 x GLOW TAPE', 'Page A', null, '2026-09-10');
        $this->order('2 x GLOW TAPE', 'Page A', null, '2026-09-11');
        $this->order('SLEEP PATCH', 'Page A', null, '2026-09-10');   // "sleep patch" bago ang "patch"
        $this->order('TOY CAR', 'Page A', null, '2026-09-10');       // "car " na may espasyo
        $this->order('CARD HOLDER', 'Page A', null, '2026-09-10');   // hindi dapat tumugma sa "car "
        $this->order('ZZZ GADGET', 'Page A', null, '2026-09-10');
        $this->order('EDGE BULB', 'Page A', null, '2026-07-04');     // hangganan ng 90 araw: kasama
        $this->order('OLD LED STRIP', 'Page A', null, '2026-07-03'); // lampas 90 araw: wala

        $supplier = DB::table('suppliers')->insertGetId(['name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        $orderId  = DB::table('supply_orders')->insertGetId([
            'supplier_id' => $supplier, 'order_date' => '2026-01-05', 'status' => 'ordered',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supply_order_items')->insert([
            'supply_order_id' => $orderId, 'item_key' => 'led bulb', 'item_name' => 'LED BULB',
            'ordered_qty' => 5, 'unit_cost' => 10, 'line_total' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('item_supplier_quotes')->insert([
            'item_key' => 'mop head', 'item_name' => 'MOP HEAD', 'supplier_id' => $supplier,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function categoryId(string $name): int
    {
        return (int) DB::table('item_categories')->where('name', $name)->value('id');
    }

    private function run_(array $args = []): string
    {
        Artisan::call('items:suggest-categories', $args);

        return Artisan::output();
    }

    public function test_dry_run_prints_matches_and_unmatched_and_writes_nothing(): void
    {
        $out = $this->run_();

        foreach ([
            'EDGE BULB → Ilaw at Kuryente',
            'GLOW TAPE → Repair at DIY',
            'LED BULB → Ilaw at Kuryente',
            'MOP HEAD → Bahay at Paglilinis',
            'SLEEP PATCH → Health at Beauty',
            'TOY CAR → Sasakyan at Motor',
            'Walang tugma (2):',
        ] as $line) {
            $this->assertStringContainsString($line, $out);
        }
        $this->assertSame(1, substr_count($out, 'GLOW TAPE →'));
        $this->assertStringNotContainsString('OLD LED STRIP', $out);
        $this->assertStringNotContainsString('CARD HOLDER →', $out);
        $this->assertMatchesRegularExpression('/Walang tugma \(2\):\s+CARD HOLDER\s+ZZZ GADGET/', $out);
        $this->assertStringContainsString('--apply', $out);
        $this->assertSame(0, DB::table('item_category_assignments')->count());

        DB::table('item_category_assignments')->insert([
            'item_key' => 'glow tape', 'category_id' => $this->categoryId('Iba pa'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertStringContainsString('GLOW TAPE → Repair at DIY [may category na: Iba pa]', $this->run_());
    }

    public function test_apply_inserts_only_missing_assignments_and_never_overwrites(): void
    {
        $iba = $this->categoryId('Iba pa');
        DB::table('item_category_assignments')->insert([
            'item_key' => 'glow tape', 'category_id' => $iba, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $out = $this->run_(['--apply' => true]);

        $this->assertStringContainsString('Na-save: 5', $out);
        $map = DB::table('item_category_assignments')->join('item_categories as c', 'c.id', '=', 'category_id')
            ->pluck('c.name', 'item_key')->all();
        $this->assertEquals([
            'glow tape'   => 'Iba pa',
            'sleep patch' => 'Health at Beauty',
            'toy car'     => 'Sasakyan at Motor',
            'edge bulb'   => 'Ilaw at Kuryente',
            'led bulb'    => 'Ilaw at Kuryente',
            'mop head'    => 'Bahay at Paglilinis',
        ], $map);
        $this->assertNull(DB::table('item_category_assignments')->where('item_key', 'toy car')->value('updated_by'));
    }

    public function test_apply_skips_items_whose_rule_category_is_missing_and_warns(): void
    {
        DB::table('item_categories')->where('name', 'Bahay at Paglilinis')->delete();

        $out = $this->run_(['--apply' => true]);

        $this->assertStringContainsString('Bahay at Paglilinis', $out);
        $this->assertStringContainsString('Na-save: 5', $out);
        $this->assertSame(0, DB::table('item_category_assignments')->where('item_key', 'mop head')->count());
    }
}
