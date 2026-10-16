<?php

namespace Tests\Feature\Item;

use App\Models\ItemSupplierQuote;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier work sa /item (suppliers, worklist, quotes, category, lead / palugit, supplier table):
 * CEO sa CEO view at Marketing - OIC. Ang CEO-only na profit view ay CEO pa rin.
 */
class MoicSupplierAccessTest extends ItemTestCase
{
    private const RANGE = '/item/worklist?start_date=2026-09-01&end_date=2026-09-30';

    protected function setUp(): void
    {
        parent::setUp();
        // Kailangan ng layout at ng index() — gaya ng ItemLayoutTest.
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('ads_manager_reports', function (Blueprint $t) {
            $t->id();
            $t->string('page_name')->nullable();
        });
        Schema::create('fee_settings', function (Blueprint $t) {
            $t->id();
            $t->string('setting_key');
            $t->decimal('setting_value', 14, 6)->nullable();
            $t->date('effective_date')->nullable();
            $t->string('description')->nullable();
            $t->string('host_scope')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedWorld(): int
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 12:00:00', 'Asia/Manila'));
        $acme = DB::table('suppliers')->insertGetId(['name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('item_supplier_quotes')->insert([
            'item_key' => ItemSupplierQuote::keyFor('HAND GRIP'), 'item_name' => 'HAND GRIP', 'supplier_id' => $acme,
            'price' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $orderId = DB::table('supply_orders')->insertGetId([
            'supplier_id' => $acme, 'order_date' => '2026-09-25', 'status' => 'ordered', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supply_order_items')->insert([
            'supply_order_id' => $orderId, 'item_key' => 'hand grip', 'item_name' => 'HAND GRIP',
            'ordered_qty' => 5, 'unit_cost' => 90, 'received_qty' => null, 'line_total' => 450,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->order('1 x HAND GRIP', 'Page A', 'WB1', '2026-09-10');

        return $acme;
    }

    /** Ang mga endpoint ng supplier work: [role, email] → may data ba. */
    private const ROLES = [
        'CEO'             => ['ceo@example.test', true],
        'Marketing - OIC' => ['oic@example.test', true],
        'Marketing'       => ['mkt@example.test', false],
    ];

    public function test_S_37A_1_marketing_oic_reads_suppliers_quotes_and_worklist_plain_marketing_does_not(): void
    {
        $this->seedWorld();
        foreach (self::ROLES as $role => [$email, $sees]) {
            $this->actingAs($this->user($role, $email));
            $suppliers = $this->getJson('/item/suppliers')->assertOk()->json('suppliers');
            $quotes    = $this->getJson('/item/quotes')->assertOk()->json();
            $worklist  = $this->getJson(self::RANGE)->assertOk()->json('items');

            $this->assertSame($sees ? 'Acme' : null, $suppliers['hand grip'][0]['supplier'] ?? null, "{$role}: suppliers");
            $this->assertSame($sees ? 1 : 0, count($quotes['quotes']['hand grip'] ?? []), "{$role}: quotes");
            $this->assertSame($sees ? 1 : 0, count($quotes['suppliers']), "{$role}: supplier list");
            $this->assertSame($sees ? ['HAND GRIP'] : [], array_column($worklist, 'name'), "{$role}: worklist");
        }
        $this->actingAs($this->user('Encoder', 'enc@example.test'))->getJson('/item/suppliers')->assertNotFound();
    }

    public function test_S_37A_2_marketing_oic_edits_quotes_category_and_settings_plain_marketing_gets_403(): void
    {
        $acme = $this->seedWorld();
        foreach (['Marketing - OIC' => ['oic@example.test', 200], 'Marketing' => ['mkt@example.test', 403]] as $role => [$email, $status]) {
            $this->actingAs($this->user($role, $email));
            $this->postJson('/item/quotes', ['item_name' => 'BELT', 'supplier_id' => $acme, 'price' => 50])->assertStatus($status);
            $this->postJson('/item/category', ['item_name' => 'BELT', 'new_category' => 'Gym'])->assertStatus($status);
            $this->postJson('/item/supply-settings', ['item_name' => 'BELT', 'lead_time_days' => 7, 'safety_days' => 3])->assertStatus($status);
            $id = (int) DB::table('item_supplier_quotes')->where('item_key', 'hand grip')->value('id');
            $this->postJson('/item/quotes/delete', ['id' => $id, 'item_name' => 'HAND GRIP'])->assertStatus($status);
            if ($status === 403) continue;

            $this->assertEquals(50, DB::table('item_supplier_quotes')->where('item_key', 'belt')->value('price'));
            $this->assertSame(1, DB::table('item_category_assignments')->where('item_key', 'belt')->count());
            $this->assertSame(7, (int) DB::table('supply_item_settings')->where('item_name', 'BELT')->value('lead_time_days'));
            $this->assertSame(0, DB::table('item_supplier_quotes')->where('item_key', 'hand grip')->count());
        }
    }

    public function test_S_37A_3_marketing_oic_sees_the_supplier_table_but_not_the_ceo_profit_view(): void
    {
        $this->actingAs($this->user('Marketing - OIC', 'oic@example.test'));

        $res = $this->get('/item?layout=suppliers')->assertOk();
        $this->assertTrue($res->viewData('canSupplierWork'));
        $this->assertTrue($res->viewData('layoutSuppliers'));
        $this->assertFalse($res->viewData('effectiveIsCEO'));
        $body = (string) $res->getContent();
        foreach (['Original table', 'splCell', 'Sourcing:', 'loadItemQuotes(),'] as $needle) {
            $this->assertStringContainsString($needle, $body, $needle);
        }
        foreach (['👁 View:', '📅 Daily', 'Save Snapshot', '⚙ Columns'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, $needle);
        }
        // Mula sa table na walang suppliers: ang link papunta sa table na may suppliers.
        $this->assertStringContainsString('Table with suppliers', (string) $this->get('/item?layout=original')->assertOk()->getContent());

        // Plain Marketing: walang nagbago.
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $res = $this->get('/item?layout=suppliers')->assertOk();
        $this->assertFalse($res->viewData('canSupplierWork'));
        $this->assertFalse($res->viewData('layoutSuppliers'));
        foreach (['Table with suppliers', 'Sourcing:', 'splCell'] as $needle) {
            $this->assertStringNotContainsString($needle, (string) $res->getContent(), $needle);
        }
    }
}
