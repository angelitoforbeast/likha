<?php

namespace Tests\Feature\Item;

use App\Models\ItemSupplierQuote;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suppliers group sa item table — characterisation at absence tests: pinned ang kasalukuyang
 * render ng default layout at ng Old view, at ang quote / supplier endpoints para sa bawat role.
 * Ang mga render test ay nagbabasa ng markup; hindi nila pinapatakbo ang script.
 */
class SuppliersGroupTest extends ItemTestCase
{
    /**
     * sha1 ng normalised render ng item.index, galing sa base commit (bago ang anumang pagbabago).
     * Key: <layout>.<viewer>. Ang old.ceo ay ikinukumpara pagkatapos tanggalin ang nag-iisang bagong link ng Old view.
     */
    private const BASE = [
        'default.ceo'              => '5bc06183505014c1c76d6a4ad3b2a64d85bae452',
        'default.ceo_as_marketing' => 'a7f063ad4ee56665ad763514b4dfa513284ec91e',
        'default.marketing'        => 'c87616640b0ef585d2217aec00eb419124757f9b',
        'default.marketing_oic'    => '57c713bd753a02648b4d8cd765188dfacf37e323',
        'old.ceo'                  => 'b8395ba9d734e304e8bc7afc39bade923db1674a', // ikinukumpara pagkatapos tanggalin ang nag-iisang bagong link sa toolbar
        'old.ceo_as_marketing'     => '25d69d35fb68970d598e8ab622cda967ee5b4ec5',
        'old.marketing'            => '85aaefd987989115e948a47f1d62bd6949cdba27',
        'old.marketing_oic'        => '97a1c74f5b91bc47b9738d5cb82f3da82dc7c0b4',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Kailangan ng layout (task badge) at ng index() (page list + fee rates) — gaya ng ItemLayoutTest.
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

    // ── helpers ───────────────────────────────────────────────────────────────

    private function supplier(string $name): int
    {
        return DB::table('suppliers')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Isang quote row; ang item_key ay galing sa model para pareho sa endpoint. */
    private function quote(string $item, int $supplierId, ?float $price, ?int $moq = null): int
    {
        return DB::table('item_supplier_quotes')->insertGetId([
            'item_key' => ItemSupplierQuote::keyFor($item), 'item_name' => $item, 'supplier_id' => $supplierId,
            'price' => $price, 'moq' => $moq, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Isang PO na may isang line (gaya ng WorklistTest). */
    private function po(int $supplierId, string $itemName, string $itemKey, int $qty, float $cost): void
    {
        $orderId = DB::table('supply_orders')->insertGetId([
            'supplier_id' => $supplierId, 'order_date' => '2026-09-25', 'status' => 'ordered',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supply_order_items')->insert([
            'supply_order_id' => $orderId, 'item_key' => $itemKey, 'item_name' => $itemName,
            'ordered_qty' => $qty, 'unit_cost' => $cost, 'received_qty' => null,
            'line_total' => $qty * $cost, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function render(string $viewer, bool $layoutOld, bool $layoutSuppliers = false): string
    {
        $v = [
            'ceo'              => [true, false, 'ceo', true],
            'ceo_as_marketing' => [true, false, 'marketing', false],
            'marketing'        => [false, false, 'ceo', false],
            'marketing_oic'    => [false, true, 'ceo', false],
        ][$viewer];

        $this->actingAs(User::where('email', 'ceo@example.test')->first() ?? $this->user());

        return view('item.index', [
            'layoutOld' => $layoutOld, 'layoutSuppliers' => $layoutSuppliers,
            'pages' => [], 'isCEO' => $v[0], 'isMarketingOIC' => $v[1],
            'viewAs' => $v[2], 'effectiveIsCEO' => $v[3],
            'ownerPrivateColsConfig' => null, 'campaignsColsConfig' => null, 'breakevenTargetPct' => 5,
            'colFormatRules' => [], 'campaignsColFormatRules' => [],
            'feeShipping' => null, 'feeCodRate' => null, 'feeVatRate' => null,
        ])->render();
    }

    /** CRLF → LF, ang root ng app (plain at JSON-escaped) at ang CSRF token → fixed na salita. */
    private function normalise(string $html): string
    {
        $html = str_replace("\r\n", "\n", $html);
        $root = rtrim(url('/'), '/');
        $html = str_replace([$root, str_replace('/', '\/', $root)], 'APPROOT', $html);
        $token = csrf_token();
        if (is_string($token) && $token !== '') $html = str_replace($token, 'CSRF', $html);

        return (string) preg_replace('/<meta name="csrf-token" content="[^"]*"/', '<meta name="csrf-token" content="CSRF"', $html);
    }

    private function hash(string $viewer, bool $old): string
    {
        return sha1($this->normalise($this->render($viewer, $old)));
    }

    // ── S-19.2 / S-18.4: ang base render ──────────────────────────────────────

    public function test_S_19_2_default_layout_for_the_ceo_is_identical_to_the_base(): void
    {
        $this->assertSame(self::BASE['default.ceo'], $this->hash('ceo', false));
    }

    public function test_S_18_4_marketing_renders_of_the_old_and_default_layout_are_identical_to_the_base(): void
    {
        foreach (self::BASE as $key => $expected) {
            if ($key === 'default.ceo' || $key === 'old.ceo') continue;
            [$layout, $viewer] = explode('.', $key, 2);
            $this->assertSame($expected, $this->hash($viewer, $layout === 'old'), "render {$key} nagbago");
        }
    }

    // ── S-13.5 / S-18.1 / S-18.2 / S-19.3: ang switch at ang gate ─────────────

    /**
     * Ang nag-iisang dagdag sa Old view ng CEO: ang link papunta sa suppliers view, kasama ang
     * indentation at line break nito. Kapag tinanggal ito, dapat bumalik ang base render.
     */
    private const SUPPLIERS_LINK = "    <a href=\"?layout=suppliers\" @click.prevent=\"const q = new URLSearchParams(window.location.search); q.set('layout', 'suppliers'); window.location.href = window.location.pathname + '?' + q.toString()\"\n"
        . "       title=\"Open the table with suppliers and prices side by side\"\n"
        . "       style=\"background:#1e293b;color:#c4b5fd;border:1px solid #475569;text-decoration:none;\n"
        . "              border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700;\n"
        . "              cursor:pointer;margin-left:4px;\">🏷 Suppliers view</a>\n";

    /** Ang tatlong request na hindi CEO view: [label, role, email, dagdag sa address]. */
    private const NON_CEO_VIEWS = [
        ['Marketing', 'Marketing', 'mkt@example.test', ''],
        ['Marketing - OIC', 'Marketing - OIC', 'oic@example.test', ''],
        ['CEO as marketing', 'CEO', 'ceo@example.test', '&view_as=marketing'],
    ];

    /** Normalised na body ng isang GET; dito agad kinukuha dahil iba ang CSRF token ng bawat request. */
    private function body(string $url): string
    {
        return $this->normalise((string) $this->get($url)->assertOk()->getContent());
    }

    public function test_S_13_5_only_the_exact_string_suppliers_selects_the_suppliers_view(): void
    {
        $this->actingAs($this->user());

        $cases = [
            '/item'                                    => [false, false],
            '/item?layout=old'                         => [true, false],
            '/item?layout=suppliers'                   => [true, true],
            '/item?layout=SUPPLIERS'                   => [false, false],
            '/item?layout=supplier'                    => [false, false],
            '/item?layout=x'                           => [false, false],
            '/item?layout[]=suppliers'                 => [false, false],
            '/item?layout=suppliers&view_as=marketing' => [true, false],
        ];
        foreach ($cases as $url => $expected) {
            $res = $this->get($url)->assertOk();
            $this->assertSame($expected, [$res->viewData('layoutOld'), $res->viewData('layoutSuppliers')], $url);
        }

        $this->assertStringContainsString('🗂 Old view', $this->body('/item?layout=suppliers'));
        $this->assertStringContainsString('🏷 Suppliers view', $this->body('/item?layout=old'));
    }

    public function test_S_18_1_non_ceo_views_get_the_old_view_with_no_suppliers_markers(): void
    {
        $sid = $this->supplier('Zyxwv Kalakal');
        $this->quote('HAND GRIP', $sid, 100, 10);
        $this->po($sid, 'HAND GRIP', 'hand grip', 5, 90);

        $markers = [
            'SUPPLIERS', 'Supplier 1', 'Supplier 2', 'Supplier 3', 'spl-', 'splReady', 'splTop3', 'splRest',
            'splNone', 'splLoaded', 'layout=suppliers', "'suppliers'", 'Suppliers view', '_table_suppliers', 'Zyxwv Kalakal',
        ];
        foreach (self::NON_CEO_VIEWS as [$label, $role, $email, $extra]) {
            $this->actingAs(User::where('email', $email)->first() ?? $this->user($role, $email));
            $suppliers = $this->body('/item?layout=suppliers' . $extra);
            $old = $this->body('/item?layout=old' . $extra);

            $this->assertTrue($suppliers === $old, "{$label}: iba ang layout=suppliers sa layout=old");
            foreach ($markers as $marker) {
                $this->assertStringNotContainsString($marker, $suppliers, "{$label}: {$marker}");
            }
        }
    }

    public function test_S_18_2_the_script_does_not_call_the_loaders_for_those_requests(): void
    {
        $calls = ['this.loadItemSuppliers(),', 'this.loadItemQuotes(),'];

        // Patunayan na totoo ang mga marker: sa CEO view, tig-isang beses ang bawat tawag.
        $this->actingAs($this->user());
        $ceo = $this->body('/item?layout=suppliers');
        foreach ($calls as $call) $this->assertSame(1, substr_count($ceo, $call), "CEO: {$call}");

        foreach (self::NON_CEO_VIEWS as [$label, $role, $email, $extra]) {
            $this->actingAs(User::where('email', $email)->first() ?? $this->user($role, $email));
            $body = $this->body('/item?layout=suppliers' . $extra);
            foreach ($calls as $call) $this->assertStringNotContainsString($call, $body, "{$label}: {$call}");
        }
    }

    public function test_S_19_3_old_view_for_the_ceo_differs_only_by_the_toolbar_link(): void
    {
        $old = $this->normalise($this->render('ceo', true));
        $this->assertSame(1, substr_count($old, self::SUPPLIERS_LINK));
        $this->assertSame(self::BASE['old.ceo'], sha1(str_replace(self::SUPPLIERS_LINK, '', $old)));

        $suppliers = $this->render('ceo', true, true);
        $this->assertStringNotContainsString('🏷 Suppliers view', $suppliers);
        $this->assertStringContainsString('🗂 Old view', $suppliers);
        $this->assertStringContainsString('<td>TOTAL</td>', $suppliers);
    }

    // ── S-14.1 – S-14.7: pagkakasunod ng quotes at ang cheapest flag ──────────

    /** Ang listahan ng isang item mula sa GET /item/quotes. */
    private function listed(string $item = 'HAND GRIP'): array
    {
        return $this->getJson('/item/quotes')->assertOk()->json('quotes')[ItemSupplierQuote::keyFor($item)] ?? [];
    }

    public function test_S_14_1_quotes_come_back_cheapest_first(): void
    {
        $this->actingAs($this->user());
        foreach ([160, 142, 148, 155] as $price) $this->quote('HAND GRIP', $this->supplier("S{$price}"), $price);

        $this->assertSame(['S142', 'S148', 'S155', 'S160'], array_column($this->listed(), 'supplier'));
    }

    public function test_S_14_2_a_quote_without_a_price_is_last(): void
    {
        $this->actingAs($this->user());
        foreach ([['Nil', null], ['Acme', 160], ['Beta', 142], ['Gamma', 148]] as [$name, $price]) {
            $this->quote('HAND GRIP', $this->supplier($name), $price);
        }

        $this->assertSame(['Beta', 'Gamma', 'Acme', 'Nil'], array_column($this->listed(), 'supplier'));
    }

    public function test_S_14_3_equal_prices_keep_the_lower_id_first_every_time(): void
    {
        $this->actingAs($this->user());
        // Baliktad ang supplier ids sa quote ids, at mas bago ang updated_at ng mas mababang id,
        // para hindi sumakto ang natural na order ng database.
        $beta = $this->supplier('Beta');
        $acme = $this->supplier('Acme');
        $first = $this->quote('HAND GRIP', $acme, 150);
        $second = $this->quote('HAND GRIP', $beta, 150);
        DB::table('item_supplier_quotes')->where('id', $first)->update(['updated_at' => now()->addDay()]);
        $this->assertLessThan($second, $first);
        $pair = fn (array $rows) => array_values(array_intersect(array_column($rows, 'id'), [$first, $second]));

        $this->assertSame([$first, $second], $pair($this->listed()));
        $this->assertSame([$first, $second], $pair($this->listed()));

        $this->postJson('/item/quotes', ['item_name' => 'YOGA MAT', 'supplier_id' => $beta, 'price' => 90])->assertOk();
        $this->assertSame([$first, $second], $pair($this->listed()), 'pagkatapos mag-save ng quote ng ibang item');

        $saved = $this->postJson('/item/quotes', ['item_name' => 'HAND GRIP', 'supplier_id' => $this->supplier('Gamma'), 'price' => 200])->assertOk();
        $this->assertCount(3, $saved->json('quotes'));
        $this->assertSame([$first, $second], $pair($saved->json('quotes')), 'sagot ng save');

        $third = (int) $saved->json('quotes.2.id');
        $deleted = $this->postJson('/item/quotes/delete', ['id' => $third, 'item_name' => 'HAND GRIP'])->assertOk();
        $this->assertSame([$first, $second], array_column($deleted->json('quotes'), 'id'), 'sagot ng delete');
    }

    public function test_S_14_4_a_zero_price_is_ordered_last_and_never_cheapest(): void
    {
        $this->actingAs($this->user());
        foreach ([['Zero', 0], ['Acme', 120], ['Beta', 110], ['Nil', null]] as [$name, $price]) {
            $this->quote('HAND GRIP', $this->supplier($name), $price);
        }

        $rows = $this->listed();
        $this->assertSame(['Beta', 'Acme', 'Zero', 'Nil'], array_column($rows, 'supplier'));
        $this->assertFalse($rows[2]['cheapest']);
        $this->assertNotNull($rows[2]['price']);
        $this->assertEquals(0, $rows[2]['price']);
        $this->assertNull($rows[3]['price']);
    }

    public function test_S_14_5_every_quote_at_the_lowest_price_is_cheapest(): void
    {
        $this->actingAs($this->user());
        [$acme, $beta, $gamma] = [$this->supplier('Acme'), $this->supplier('Beta'), $this->supplier('Gamma')];
        $this->quote('HAND GRIP', $acme, 142);
        $this->quote('HAND GRIP', $beta, 142);
        $this->quote('HAND GRIP', $gamma, 155);
        $this->quote('YOGA MAT', $acme, 148);
        $this->quote('YOGA MAT', $beta, 142);

        $tied = $this->listed();
        $this->assertSame(['Acme', 'Beta', 'Gamma'], array_column($tied, 'supplier'));
        $this->assertSame([true, true, false], array_column($tied, 'cheapest'));

        $single = $this->listed('YOGA MAT');
        $this->assertSame(['Beta', 'Acme'], array_column($single, 'supplier'));
        $this->assertSame([true, false], array_column($single, 'cheapest'));
    }

    public function test_S_14_6_no_cheapest_with_one_priced_quote_or_none(): void
    {
        $this->actingAs($this->user());
        $ids = [$this->supplier('Acme'), $this->supplier('Beta'), $this->supplier('Gamma')];
        $cases = [
            'ONE PRICED'       => [100],
            'PRICED NULL ZERO' => [100, null, 0],
            'ONLY NULLS'       => [null, null],
        ];
        foreach ($cases as $item => $prices) {
            foreach ($prices as $i => $price) $this->quote($item, $ids[$i], $price);
        }

        foreach ($cases as $item => $prices) {
            $rows = $this->listed($item);
            $this->assertCount(count($prices), $rows, $item);
            foreach ($rows as $row) {
                $this->assertArrayHasKey('cheapest', $row, $item);
                $this->assertFalse($row['cheapest'], $item);
            }
        }
    }

    public function test_S_14_7_the_save_answer_is_already_in_the_new_order(): void
    {
        $this->actingAs($this->user());
        $acme = $this->supplier('Acme');
        $this->quote('HAND GRIP', $acme, 100);
        $this->quote('HAND GRIP', $this->supplier('Beta'), 110);
        $this->quote('HAND GRIP', $this->supplier('Gamma'), 120);

        $rows = $this->postJson('/item/quotes', ['item_name' => 'HAND GRIP', 'supplier_id' => $acme, 'price' => 130])
            ->assertOk()->json('quotes');

        $this->assertSame(['Beta', 'Gamma', 'Acme'], array_column($rows, 'supplier'));
        $this->assertSame([true, false, false], array_column($rows, 'cheapest'));
    }

    // ── S-14.9: ang default layout at photo page ay nagbabasa pa rin ng buong listahan ──

    public function test_S_14_9_the_default_layout_and_the_photo_page_still_list_every_quote(): void
    {
        $this->assertStringContainsString('in quotesFor(G.item_name)', $this->render('ceo', false));
        $this->assertStringContainsString('x-for="q in (it.quotes||[])"', file_get_contents(resource_path('views/item/photo.blade.php')));

        // render() na ang nag-act as CEO.
        foreach ([['Acme', 160], ['Beta', 142], ['Gamma', 148], ['Delta', 155], ['Epsilon', null]] as [$name, $price]) {
            $this->quote('1 x HAND GRIP', $this->supplier($name), $price);
        }
        $rows = $this->getJson('/item/quotes')->assertOk()->json('quotes.hand grip');
        $this->assertCount(5, $rows);
        // Set lang ng supplier — ang pagkakasunod ay magbabago sa susunod na task.
        $names = array_column($rows, 'supplier');
        sort($names);
        $this->assertSame(['Acme', 'Beta', 'Delta', 'Epsilon', 'Gamma'], $names);
    }

    // ── S-16: save / delete ───────────────────────────────────────────────────

    public function test_S_16_2_a_marketing_user_cannot_save_or_delete_a_quote(): void
    {
        $sid = $this->supplier('Acme');
        $id = $this->quote('HAND GRIP', $sid, 100, 10);
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));

        $this->postJson('/item/quotes', ['item_name' => 'HAND GRIP', 'supplier_id' => $sid, 'price' => 1, 'moq' => 1])->assertStatus(403);
        $this->postJson('/item/quotes/delete', ['id' => $id, 'item_name' => 'HAND GRIP'])->assertStatus(403);

        $this->assertSame(1, DB::table('item_supplier_quotes')->count());
        $row = DB::table('item_supplier_quotes')->first();
        $this->assertSame($id, (int) $row->id);
        $this->assertEquals(100, $row->price);
        $this->assertSame(10, (int) $row->moq);
        $this->assertSame(0, DB::table('item_supplier_quote_history')->count());
    }

    public function test_S_16_4_deleting_a_removed_quote_again_answers_ok_with_the_current_list(): void
    {
        $this->actingAs($this->user());
        $a = $this->quote('HAND GRIP', $this->supplier('Acme'), 100);
        $this->quote('HAND GRIP', $this->supplier('Beta'), 120);

        $this->postJson('/item/quotes/delete', ['id' => $a, 'item_name' => 'HAND GRIP'])->assertOk();
        $again = $this->postJson('/item/quotes/delete', ['id' => $a, 'item_name' => 'HAND GRIP'])->assertOk();

        $this->assertTrue($again->json('ok'));
        $this->assertCount(1, $again->json('quotes'));
        $this->assertSame('Beta', $again->json('quotes.0.supplier'));
    }

    public function test_S_16_5_validation_is_as_today_and_a_rejected_save_writes_nothing(): void
    {
        $this->actingAs($this->user());

        // Tinatanggap: walang price at walang MOQ → null ang naka-store.
        $fresh = $this->supplier('Beta');
        $this->postJson('/item/quotes', ['item_name' => 'HAND GRIP', 'supplier_id' => $fresh])->assertOk();
        $stored = DB::table('item_supplier_quotes')->where('supplier_id', $fresh)->first();
        $this->assertNull($stored->price);
        $this->assertNull($stored->moq);

        // Tinatanggihan: 422 at walang nagbago sa dating quote.
        $sid = $this->supplier('Acme');
        $this->quote('HAND GRIP', $sid, 100, 10);
        $before = DB::table('item_supplier_quotes')->orderBy('id')->get()->toArray();
        $rejected = [
            'price 100000000 (lampas sa 99999999)' => ['price' => 100000000],
            'negative price'                       => ['price' => -1],
            'link na 501 characters'               => ['link' => str_repeat('a', 501)],
            'moq 100000001 (lampas sa 100000000)'  => ['moq' => 100000001],
        ];
        foreach ($rejected as $label => $fields) {
            $this->postJson('/item/quotes', array_merge(['item_name' => 'HAND GRIP', 'supplier_id' => $sid], $fields))
                ->assertStatus(422);
            $this->assertEquals($before, DB::table('item_supplier_quotes')->orderBy('id')->get()->toArray(), $label);
            $this->assertSame(0, DB::table('item_supplier_quote_history')->count(), $label);
        }
    }

    // ── S-18.3: ang Marketing roles ay walang makukuha sa quote / supplier endpoints ──

    public function test_S_18_3_marketing_roles_get_empty_quote_and_supplier_lists(): void
    {
        $acme = $this->supplier('Acme');
        $this->quote('HAND GRIP', $acme, 100, 10);
        $this->quote('HAND GRIP', $this->supplier('Beta'), 120);
        $this->po($acme, 'HAND GRIP', 'hand grip', 5, 90);

        // Patunayan na totoo ang fixture: ang CEO ay nakakakuha ng data.
        $this->actingAs($this->user());
        $this->assertCount(2, $this->getJson('/item/quotes')->assertOk()->json('quotes.hand grip'));
        $this->assertSame('Acme', $this->getJson('/item/suppliers')->assertOk()->json('suppliers.hand grip.0.supplier'));

        foreach (['Marketing' => 'mkt@example.test', 'Marketing - OIC' => 'oic@example.test'] as $role => $email) {
            $this->actingAs($this->user($role, $email));
            $quotes = $this->getJson('/item/quotes')->assertOk();
            $suppliers = $this->getJson('/item/suppliers')->assertOk();

            $quotes->assertExactJson(['ok' => true, 'suppliers' => [], 'quotes' => []]);
            $suppliers->assertExactJson(['ok' => true, 'suppliers' => []]);
            foreach ([$quotes->getContent(), $suppliers->getContent()] as $body) {
                foreach (['cheapest', 'Acme', 'Beta', 'price'] as $needle) {
                    $this->assertStringNotContainsString($needle, $body, "{$role}: {$needle}");
                }
            }
        }
    }
}
