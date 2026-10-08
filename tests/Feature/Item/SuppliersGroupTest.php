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

        $suppliers = $this->body('/item?layout=suppliers');
        $this->assertStringContainsString('🗂 Old view', $suppliers);
        // Kung wala ito, gagawing ?layout=old ng unang load ang address ng suppliers view.
        $this->assertStringContainsString("qsObj.layout = 'suppliers';", $suppliers);
        $this->assertStringContainsString('🏷 Suppliers view', $this->body('/item?layout=old'));

        // Hindi CEO: Old view ang napipili ng parehong address.
        foreach ([['Marketing', 'mkt@example.test'], ['Marketing - OIC', 'oic@example.test']] as [$role, $email]) {
            $this->actingAs($this->user($role, $email));
            $res = $this->get('/item?layout=suppliers')->assertOk();
            $this->assertSame([true, false], [$res->viewData('layoutOld'), $res->viewData('layoutSuppliers')], $role);
        }
    }

    public function test_S_18_1_non_ceo_views_get_the_old_view_with_no_suppliers_markers(): void
    {
        $sid = $this->supplier('Zyxwv Kalakal');
        $this->quote('HAND GRIP', $sid, 100, 10);
        $this->po($sid, 'HAND GRIP', 'hand grip', 5, 90);

        // Iisang listahan para sa dalawang tanong: nasa CEO view ang bawat isa, at wala ni isa sa hindi CEO.
        $markers = [
            'SUPPLIERS', 'Supplier 1', 'Supplier 2', 'Supplier 3', 'spl-', 'splReady', 'splTop3', 'splRest',
            'splNone', 'splLoaded', 'splFailed', 'hindi na-load', 'spl-card', 'splCard', "'suppliers'",
            'layout=suppliers', 'Suppliers view',
        ];
        // Hindi kailanman nasa render ng page (pangalan ng file; pangalan ng supplier na sa fetch lang dumarating),
        // kaya walang positive control ang mga ito: tripwire lang kung sakaling may mag-print ng mga ito balang araw.
        $tripwires = ['_table_suppliers', 'Zyxwv Kalakal'];

        // Patunayan na totoo ang mga marker: nasa suppliers view ng CEO ang mga ito (ang link papunta roon ay
        // nasa Old view niya), kaya may ibig sabihin ang pagkawala nila sa iba.
        $this->actingAs($this->user());
        $ceo = $this->body('/item?layout=suppliers') . $this->body('/item?layout=old');
        foreach ($markers as $marker) {
            $this->assertStringContainsString($marker, $ceo, "CEO: {$marker}");
        }

        foreach (self::NON_CEO_VIEWS as [$label, $role, $email, $extra]) {
            $this->actingAs(User::where('email', $email)->first() ?? $this->user($role, $email));
            $suppliers = $this->body('/item?layout=suppliers' . $extra);
            $old = $this->body('/item?layout=old' . $extra);

            $this->assertTrue($suppliers === $old, "{$label}: iba ang layout=suppliers sa layout=old");
            foreach (array_merge($markers, $tripwires) as $marker) {
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

    // ── S-13.1 – S-13.4 / S-14.8 / S-15.10 / S-19.4 / S-19.6 / S-21.1: ang table ng suppliers view ──
    // Binabasa ng mga test na ito ang markup at ang text ng script; hindi nila pinapatakbo ang script.

    private const TABLE_FILE = 'views/item/_table_suppliers.blade.php';
    private const JS_FILE = 'views/item/_suppliers_js.blade.php';

    private const SPL_TOP3 = 'splTop3(name){ return this.quotesFor(name).slice(0, 3); },';
    private const SPL_REST = 'splRest(name){ return Math.max(0, this.quotesFor(name).length - 3); },';
    private const SPL_READY = 'splReady(){ return this.splLoaded.quotes && this.splLoaded.po; },';
    private const LOADED_PO = 'if (res.ok && j && j.ok === true && j.suppliers) this.splLoaded.po = true; else this.splFailed = true;';
    private const LOADED_QUOTES = 'if (res.ok && j && j.ok === true && j.quotes) this.splLoaded.quotes = true; else this.splFailed = true;';

    private const WAIT_IF = '<template x-if="!splReady()">';
    private const BAND_IF = '<template x-if="splReady() && splNone(row.item_name)">';
    private const SLOT_FOR = '<template x-for="si in (splReady() && !splNone(row.item_name) ? [0, 1, 2] : [])"';
    private const PO_IF = '<template x-if="splReady() && !splNone(row.item_name)">';

    /** Ang bahagi ng $html mula sa $from hanggang bago ang susunod na $to; parehong dapat naroon. */
    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "wala ang: {$from}");
        $end = strpos($html, $to, $start + strlen($from));
        $this->assertNotFalse($end, "wala ang: {$to} (pagkatapos ng {$from})");

        return substr($html, $start, $end - $start);
    }

    /** Ang table ng suppliers view sa render ng CEO (hanggang sa footnote nito: may mga table sa loob ng mga cell). */
    private function suppliersTable(): string
    {
        return $this->between($this->render('ceo', true, true), '<table class="spl-table">', 'Drag headers to reorder');
    }

    /** Ang item-row template: mula sa row hanggang sa paulit-ulit na header ng bawat page. */
    private function itemRow(): string
    {
        return $this->between($this->suppliersTable(), 'class="item-row"', 'page-col-header');
    }

    /** Ang lapad (colspan, o 1) ng bawat <td> / <th> ng isang piraso ng markup, sunod-sunod. */
    private function spans(string $markup): array
    {
        preg_match_all('/<t[dh](?=[\s>])[^>]*>/', $markup, $m);

        return array_map(fn (string $tag) => preg_match('/\scolspan="(\d+)"/', $tag, $c) ? (int) $c[1] : 1, $m[0]);
    }

    /** Dapat sunod-sunod ang mga marker sa $html. */
    private function assertOrder(array $markers, string $html): void
    {
        $at = -1;
        foreach ($markers as $marker) {
            $pos = strpos($html, $marker, $at + 1);
            $this->assertNotFalse($pos, "wala o wala sa pagkakasunod: {$marker}");
            $at = $pos;
        }
    }

    public function test_S_13_1_header_has_two_rows_with_suppliers_over_four_plain_subheaders(): void
    {
        $head = $this->between($this->suppliersTable(), '<thead>', '</thead>');
        $this->assertSame(2, substr_count($head, '<tr'));

        // Page, Item at ang template ng ibang column ay sumasakop sa dalawang row; buo pa rin ang sort at drag.
        $cells = preg_split('/<th(?=[\s>])/', $head);
        foreach (["@click=\"sb('page_name')\"" => 'spl-c1', "@click=\"sb('item_name')\"" => 'spl-c2', 'draggable="true"' => 'colDragStart($event, col.id)'] as $own => $also) {
            $found = array_values(array_filter($cells, fn (string $cell) => str_contains($cell, $own)));
            $this->assertCount(1, $found, $own);
            $this->assertStringContainsString('rowspan="2"', strstr($found[0], '>', true), $own);
            $this->assertStringContainsString($also, $found[0], $own);
        }

        $this->assertStringContainsString('<th class="spl-grp" colspan="4">SUPPLIERS</th>', $head);
        $this->assertOrder(['<span>Item</span>', 'colspan="4">SUPPLIERS<', 'x-for="col in cols"', '<tr class="spl-h2">'], $head);

        $second = $this->between($head, '<tr class="spl-h2">', '</tr>');
        preg_match_all('/<th[^>]*>([^<]*)<\/th>/', $second, $m);
        $this->assertSame(['Supplier 1', 'Supplier 2', 'Supplier 3', 'PO'], $m[1]);
        $this->assertSame(4, substr_count($second, '<th'));
        $this->assertStringNotContainsString('@click', $second);
        $this->assertStringNotContainsString('draggable', $second);
    }

    public function test_S_13_2_item_row_has_four_supplier_cells_after_the_item_cell(): void
    {
        $row = $this->itemRow();

        $this->assertOrder([
            'spl-c2',
            '<td colspan="4" class="spl-sc spl-wait" @click.stop>',
            '<td colspan="4" class="spl-sc spl-nosup" @click.stop>',
            self::SLOT_FOR,
            '<td class="spl-sc" @click.stop>',
            '<td class="spl-sc spl-po" @click.stop>',
            "<template x-for=\"col in cols\" :key=\"'ic-'+row.item_name+'-'+col.id\">",
        ], $row);
        foreach (['class="spl-sc spl-wait"', 'class="spl-sc spl-nosup"', 'class="spl-sc spl-po"', '[0, 1, 2]', '<td class="spl-sc" @click.stop>'] as $once) {
            $this->assertSame(1, substr_count($row, $once), $once);
        }
    }

    public function test_S_13_3_item_cell_no_longer_holds_the_supplier_stack(): void
    {
        $row = $this->itemRow();
        $cell = $this->between($row, 'spl-c2', '</td>');

        $this->assertStringContainsString("' running page':' running pages'", $cell);
        $this->assertStringContainsString('⚠ walang running page', $cell);
        foreach (['🏭', '🏷', 'walang supplier', 'dati ', 'quoteForm.supplier_id', '+ supplier quote', 'suppliersFor(', 'quotesFor('] as $gone) {
            $this->assertStringNotContainsString($gone, $cell, $gone);
        }
        // Wala na rin ang inline form at ang "dati" line saanman bago ang grupo (nasa card na ng supplier cell ang mga ito),
        // at wala na ang dalawang icon ng lumang stack saanman sa row.
        $beforeGroup = $this->between($row, '>', self::WAIT_IF);
        foreach (['quoteForm.supplier_id', "'dati '", '🏭', '🏷'] as $gone) {
            $this->assertStringNotContainsString($gone, $beforeGroup, $gone);
        }
        foreach (['🏭', '🏷'] as $gone) {
            $this->assertStringNotContainsString($gone, $row, $gone);
        }
        $this->assertStringNotContainsString('>walang supplier<', $this->render('ceo', true, true));
    }

    public function test_S_13_4_full_width_rows_and_total_span_four_more_columns(): void
    {
        $old = $this->render('ceo', true);
        $suppliers = $this->render('ceo', true, true);
        $plusTwo = '/:colspan="\(?cols\.length \+ 2\)?"/';
        $plusSix = '/:colspan="\(?cols\.length \+ 6\)?"/';
        $this->assertSame(5, preg_match_all($plusTwo, $old));
        $this->assertSame(0, preg_match_all($plusSix, $old));
        $this->assertSame(0, preg_match_all($plusTwo, $suppliers));
        $this->assertSame(5, preg_match_all($plusSix, $suppliers));

        $table = $this->suppliersTable();
        $this->assertMatchesRegularExpression('/<td>TOTAL<\/td>\s*<td colspan="5"><\/td>/', $table);
        $pageRow = $this->between($table, '<!-- Fixed: Page -->', '<!-- Dynamic columns -->');
        $this->assertStringContainsString('<td colspan="4" class="spl-under"></td>', $pageRow);
        $pageHeader = $this->between($table, 'class="page-col-header"', "'ph-'");
        $this->assertStringContainsString('<th colspan="4"></th>', $pageHeader);

        // Bawat klase ng row: ang lapad ng bawat cell bago ang mga configurable column. Laging 6 ang kabuuan
        // (Page + Item + ang apat ng grupo).
        $row = $this->itemRow();
        $fixed = $this->spans($this->between($row, '>', self::WAIT_IF));
        $slot = $this->spans($this->between($row, self::SLOT_FOR, self::PO_IF));
        $this->assertSame([1], $slot);
        $this->assertSame(1, preg_match('/\? \[([\d, ]+)\] : \[\]\)"/', self::SLOT_FOR, $slots));
        $filled = array_merge($fixed, ...array_fill(0, count(explode(',', $slots[1])), $slot));
        $filled = array_merge($filled, $this->spans($this->between($row, self::PO_IF, "'ic-'")));

        $kinds = [
            'header row 1'    => [$this->spans($this->between($table, '<tr class="spl-h1">', 'x-for="col in cols"')), [1, 1, 4]],
            'item row, wait'  => [array_merge($fixed, $this->spans($this->between($row, self::WAIT_IF, self::BAND_IF))), [1, 1, 4]],
            'item row, band'  => [array_merge($fixed, $this->spans($this->between($row, self::BAND_IF, self::SLOT_FOR))), [1, 1, 4]],
            'item row, cells' => [$filled, [1, 1, 1, 1, 1, 1]],
            'page header'     => [$this->spans($pageHeader), [1, 1, 4]],
            'page row'        => [$this->spans($pageRow), [1, 1, 4]],
            'TOTAL'           => [$this->spans($this->between($table, 'class="total-row"', 'x-for="col in cols"')), [1, 5]],
        ];
        foreach ($kinds as $kind => [$actual, $expected]) {
            $this->assertSame($expected, $actual, $kind);
            $this->assertSame(6, array_sum($actual), $kind);
        }
        // Ang pangalawang row ng header ang apat na nasa ilalim ng SUPPLIERS.
        $this->assertSame([1, 1, 1, 1], $this->spans($this->between($table, '<tr class="spl-h2">', '</tr>')));
    }

    public function test_S_14_8_script_helpers_take_the_first_three_and_count_the_rest(): void
    {
        $html = $this->render('ceo', true, true);
        $this->assertSame(1, substr_count($html, self::SPL_TOP3));
        $this->assertSame(1, substr_count($html, self::SPL_REST));

        // Ang server ang nag-aayos at nagmamarka ng pinakamura; ang script ay hindi nag-so-sort o nagkukumpara ng presyo.
        $js = file_get_contents(resource_path(self::JS_FILE));
        foreach (['.sort(', 'price', 'Math.min'] as $never) {
            $this->assertStringNotContainsString($never, $js, $never);
        }
        // Sa code ng script (hindi kasama ang comments), walang bumabasa ng marka: iisang anyo lang ito, sa template.
        $this->assertStringNotContainsString('cheapest', (string) preg_replace('~//.*$~m', '', $js));
        $table = file_get_contents(resource_path(self::TABLE_FILE));
        $marks = substr_count($table, ":class=\"q.cheapest === true ? 'spl-price spl-low' : 'spl-price'\"");
        $this->assertGreaterThanOrEqual(3, $marks);
        $this->assertSame($marks, preg_match_all('/\bcheapest\b/', $table));
        $this->assertSame($marks, substr_count($table, 'spl-low'));
    }

    public function test_S_15_10_band_and_plus_cells_are_bound_to_the_loaded_state(): void
    {
        $html = $this->render('ceo', true, true);
        $this->assertSame(1, substr_count($html, self::SPL_READY));
        $this->assertSame(1, substr_count($html, 'splLoaded: { quotes:false, po:false },'));

        // Ang flag ay nagiging true lang sa linyang ito ng bawat loader, pagkatapos sumagot nang ok.
        $suppliersLoader = $this->between($html, 'async loadItemSuppliers(){', 'supKey(n){');
        $quotesLoader = $this->between($html, 'async loadItemQuotes(){', 'quotesFor(name){');
        $this->assertStringContainsString(self::LOADED_PO, $suppliersLoader);
        $this->assertStringContainsString('if (!this.splLoaded.po) this.splFailed = true;', $suppliersLoader);
        $this->assertStringContainsString(self::LOADED_QUOTES, $quotesLoader);
        $this->assertStringContainsString('if (!this.splLoaded.quotes) this.splFailed = true;', $quotesLoader);
        foreach (['splLoaded.po =', 'splLoaded.quotes =', 'splLoaded.po = true', 'splLoaded.quotes = true'] as $set) {
            $this->assertSame(1, substr_count($html, $set), $set);
        }
        $this->assertSame(0, substr_count($html, 'splLoaded ='));
        $this->assertSame(0, preg_match_all('/splLoaded\s*\[/', $html));

        // Ang placeholder, ang pulang band, ang tatlong cell at ang PO cell ay nakatali lahat sa splReady().
        $row = $this->itemRow();
        $this->assertOrder([self::WAIT_IF, self::BAND_IF, self::SLOT_FOR, self::PO_IF], $row);
        $wait = $this->between($row, self::WAIT_IF, self::BAND_IF);
        $this->assertStringContainsString("x-text=\"splFailed ? 'hindi na-load' : '…'\"", $wait);
        $this->assertStringContainsString(":title=\"splFailed ? 'Hindi na-load ang listahan ng supplier. I-refresh ang page.' : 'Loading suppliers…'\"", $wait);
        // Ang band at ang "+" ay nasa labas ng placeholder, sa loob ng mga template na may splReady().
        $this->assertStringNotContainsString('splForm(', $this->between($row, '>', self::BAND_IF));
        $this->assertSame(4, substr_count($row, 'splReady()'));

        foreach ([$this->render('ceo', true), $this->render('ceo', false)] as $other) {
            $this->assertStringNotContainsString('splLoaded', $other);
            $this->assertStringNotContainsString('splFailed', $other);
        }
    }

    public function test_S_19_4_everything_the_old_table_has_is_present(): void
    {
        $html = $this->render('ceo', true, true);
        $present = [
            'x-for="col in cols" :key="col.id"', '@dragstart="colDragStart($event, col.id)"', "@click=\"sb('page_name')\"",
            "x-text=\"'HOLD '+Number(row.hold||0).toLocaleString()\"", 'class="expand-chev"', '@click.stop="toggleItemExpand(row.item_name)"',
            '@click="row.hasPages && toggleItemExpand(row.item_name)"', 'class="page-col-header"', 'class="page-expand-row"',
            '<td>TOTAL</td>', 'Drag headers to reorder', '@click="setWorklist(c.key)"', "'kabuuan: ' + num(W.hold_units)",
            "'Kulang ' + num(W.shortfall)", '⚙ Columns', '🔄 Refresh',
            "x-text=\"itemImages[row.item_name] ? 'Change' : 'Add photo'\"", '@click.stop="copyItem(row.item_name, row.hold)"',
            "col.id==='doi'",
        ];
        foreach ($present as $text) $this->assertStringContainsString($text, $html, $text);

        // Ang page row ay may sarili pa ring tatlong-linyang RTS / DEL / INT cell, gaya ng sa Old view.
        $pageRowLine = ":style=\"row.jnt_transit_pct===null?'color:#cbd5e1':'color:#111;font-weight:600'\"";
        foreach (['views/item/_table_old.blade.php', self::TABLE_FILE] as $file) {
            $this->assertSame(1, substr_count(file_get_contents(resource_path($file)), $pageRowLine), $file);
        }
    }

    public function test_S_19_6_the_suppliers_files_use_no_x_html_and_no_inner_html(): void
    {
        $this->assertFileExists(resource_path(self::JS_FILE));
        $files = [self::TABLE_FILE, self::JS_FILE];
        if (is_file(resource_path('views/item/_suppliers_style.blade.php'))) $files[] = 'views/item/_suppliers_style.blade.php';

        foreach ($files as $file) {
            $source = file_get_contents(resource_path($file));
            foreach (['x-html', 'innerHTML', '{!!', 'q.note'] as $never) {
                $this->assertStringNotContainsString($never, $source, "{$file}: {$never}");
            }
        }
    }

    public function test_S_21_1_each_loader_is_called_once_and_the_new_templates_fetch_nothing(): void
    {
        $html = $this->render('ceo', true, true);
        $this->assertSame(1, substr_count($html, 'this.loadItemSuppliers(),'));
        $this->assertSame(1, substr_count($html, 'this.loadItemQuotes(),'));
        $this->assertSame(1, substr_count($html, 'this.loadItemSuppliers('), 'tinatawag lang sa init');
        $this->assertSame(1, substr_count($html, 'this.loadItemQuotes('), 'tinatawag lang sa init');

        $this->assertFileExists(resource_path(self::JS_FILE));
        foreach ([self::TABLE_FILE, self::JS_FILE] as $file) {
            $source = file_get_contents(resource_path($file));
            $this->assertStringNotContainsString('fetch(', $source, $file);
            $this->assertStringNotContainsString('XMLHttpRequest', $source, $file);
            // Ang nag-iisang route ay ang kinopyang link ng photo page.
            $this->assertSame([], array_values(array_diff($this->routesIn($source), ["route('item.photo')"])), $file);
        }
    }

    /** Bawat route(...) na tawag sa isang source. */
    private function routesIn(string $source): array
    {
        preg_match_all("/route\\('[^']*'\\)/", $source, $m);

        return $m[0];
    }

    // ── S-16.1 / S-16.6 / S-20.1 / S-20.2: ang card, ang form at ang text-only na bindings ──
    // Binabasa ng mga test na ito ang source ng partial at ang render; hindi nila pinapatakbo ang script.

    private const FORM_OPEN = 'quoteForm.key === supKey(row.item_name) && quoteForm.item_name === row.item_name';
    private const LINK_IF = '<template x-if="safeLink(q.link)">';
    private const SUPPLIER_VALUE = '/(?<![\w.$])(?:row\.item_name|[qs]\.(?:supplier|name|price|moq|link|photo_url|updated_at|prev_price|prev_date|unit_cost|order_date|order_no))\b/';

    /** Ang source ng table partial, walang Blade comments. */
    private function tableSource(): string
    {
        $source = str_replace("\r\n", "\n", file_get_contents(resource_path(self::TABLE_FILE)));

        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    }

    /** Ang apat na column ng grupo sa item row ng partial: mula sa placeholder hanggang bago ang ibang column. */
    private function groupSource(): string
    {
        return $this->between($this->tableSource(), self::WAIT_IF, "'ic-'");
    }

    /** Bawat opening tag; ang > sa loob ng naka-quote na value (arrow function, paghahambing) ay hindi dulo ng tag. */
    private function tags(string $markup): array
    {
        preg_match_all('/<[a-zA-Z][\w-]*(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/', $markup, $m);

        return $m[0];
    }

    /** Ang mga attribute ng isang tag bilang [pangalan, value]. */
    private function attributes(string $tag): array
    {
        preg_match_all('/\s([^\s="<>]+)="([^"]*)"/', $tag, $m, PREG_SET_ORDER);

        return array_map(fn (array $a) => [$a[1], $a[2]], $m);
    }

    public function test_S_16_1_every_new_control_stops_the_click_from_reaching_the_row(): void
    {
        $row = $this->between($this->tableSource(), 'class="item-row"', 'page-col-header');
        $this->assertStringContainsString('@click="row.hasPages && toggleItemExpand(row.item_name)"', $row);

        $range = $this->between($row, '<td class="spl-c2"', '</td>') . $this->groupSource();
        $controls = array_values(array_filter($this->tags($range), fn (string $tag) => preg_match('/^<(?:button|a|input|select)[\s>]/', $tag)
            || preg_match('/^<td\s(?:[^>"]|"[^"]*")*class="spl-sc[\s"]/', $tag)
            || preg_match('/\sclass="(?:[^"]*\s)?spl-(?:card|form)(?:\s[^"]*)?"/', $tag)));

        foreach ($controls as $tag) {
            // Sa labas ng mga value hinahanap, para hindi mabilang ang salitang nasa loob ng isang expression.
            $this->assertStringContainsString(' @click.stop', (string) preg_replace('/"[^"]*"/', '""', $tag), $tag);
        }
        // Change, Copy (2); ang placeholder (1); ang band (11: cell, button, card, form, 5 field, Save, Cancel);
        // ang quote cell (22: cell, pangalan, card, photo, link, edit, remove, "+N", list card at ang 3 control nito,
        // "+", form card, form, 5 field, Save, Cancel); ang PO cell (4: cell, pangalan, "+N", card).
        $this->assertGreaterThanOrEqual(40, count($controls));
    }

    public function test_S_16_6_the_form_opens_for_one_row_only(): void
    {
        $html = $this->render('ceo', true, true);
        $table = $this->tableSource();

        // Ang form ay bukas lang sa row na may parehong item name, hindi sa bawat row na may parehong quote key.
        $this->assertStringContainsString(self::FORM_OPEN, $html);
        $this->assertStringNotContainsString('quoteForm.key === supKey(row.item_name)"', $table);
        $forms = substr_count($table, 'class="spl-form"');
        $this->assertGreaterThan(0, $forms);
        $this->assertSame($forms, substr_count($table, self::FORM_OPEN . '">'));
        // Kasama rin sa kondisyon ang sariling cell: kung wala ito, lalabas ang form sa lahat ng cell ng row.
        $places = ["splIs(row.item_name, 'band', 'form')", "splIs(row.item_name, si, 'form')"];
        $this->assertSame(count($places), $forms);
        foreach ($places as $place) {
            $this->assertSame(1, substr_count($table, '<template x-if="' . $place . ' && ' . self::FORM_OPEN . '">'), $place);
        }

        // Ang save at delete ay ang dati nang functions at routes ng page; walang sariling request ang mga bagong file.
        foreach (['saveQuote()', 'splRemove(row.item_name, q)', 'splForm(row.item_name, '] as $call) {
            $this->assertStringContainsString($call, $table, $call);
        }
        $this->assertStringContainsString('this.openQuote(name, q);', file_get_contents(resource_path(self::JS_FILE)));
        $this->assertStringContainsString('await this.deleteQuote(name, q);', file_get_contents(resource_path(self::JS_FILE)));
        foreach ([self::TABLE_FILE, self::JS_FILE] as $file) {
            $this->assertStringNotContainsString('fetch(', file_get_contents(resource_path($file)), $file);
        }
        foreach (['item.quotes.save', 'item.quotes.delete'] as $name) {
            $this->assertSame(1, substr_count($html, "fetch('" . route($name) . "', { method:'POST'"), $name);
        }
    }

    public function test_S_20_1_supplier_values_are_bound_as_text_and_the_note_is_not_shown(): void
    {
        $allowed = [
            'x-text', ':title', ':aria-label', ':alt', ':src', ':href', ':class', ':key', 'x-if', 'x-show', 'x-for',
            '@click', '@click.stop', ':aria-expanded', '@mouseenter', '@mouseleave',
        ];
        $group = $this->groupSource();
        $seen = [];
        foreach ($this->tags($group) as $tag) {
            foreach ($this->attributes($tag) as [$name, $value]) {
                if (!preg_match(self::SUPPLIER_VALUE, $value)) continue;
                $this->assertContains($name, $allowed, $tag);
                if ($name === ':href') $this->assertSame('safeLink(q.link)', $value, $tag);
                $seen[$name] = true;
            }
        }
        // Ang card ang nagpapakita ng pangalan, link at photo: kung wala ang mga ito, walang nabasa ang loop sa itaas.
        foreach (['x-text', ':title', ':aria-label', ':alt', ':src', ':href'] as $name) {
            $this->assertArrayHasKey($name, $seen, $name);
        }

        foreach ([self::TABLE_FILE, self::JS_FILE] as $file) {
            $source = file_get_contents(resource_path($file));
            foreach (['x-html', 'innerHTML', 'insertAdjacentHTML', 'outerHTML', 'document.write', '{!!', '.note'] as $never) {
                $this->assertStringNotContainsString($never, $source, "{$file}: {$never}");
            }
        }

        // Dash lang kapag walang presyo (0.00 ang ipi-print ng formatter sa null); ang MOQ na 0 ay value pa rin.
        $this->assertStringContainsString("q.price !== null ? money(q.price) : '—'", $group);
        $this->assertStringContainsString('q.moq !== null && q.moq !== undefined', $group);
        $this->assertStringContainsString("'walang MOQ'", $group);
        $this->assertStringNotContainsString('x-show="q.moq"', $group);

        // Ang style ng card ay binubuo ng script mula sa mga numero lang (sukat ng cell at ng window), walang value ng supplier.
        $js = str_replace("\r\n", "\n", file_get_contents(resource_path(self::JS_FILE)));
        $place = $this->between($js, 'splPlace(){', 'splScrolled(){');
        foreach ([
            "this.splCard.style = 'position:fixed;z-index:60;width:262px;left:' + Math.round(left) + 'px;' + vertical;",
            "? 'bottom:' + Math.round(window.innerHeight - r.top - 6) + 'px;'",
            ": 'top:' + Math.round(r.bottom - 6) + 'px;';",
        ] as $line) {
            $this->assertStringContainsString($line, $place, $line);
        }
        $this->assertSame(1, substr_count($js, 'splCard.style ='));
        $this->assertSame(1, preg_match_all('/\bvertical\s*=/', $js));
    }

    public function test_S_17_4_an_open_form_is_not_closed_by_a_click_or_another_card(): void
    {
        $js = str_replace("\r\n", "\n", file_get_contents(resource_path(self::JS_FILE)));
        $table = $this->tableSource();
        $body = fn (string $from, string $to) => $this->between($js, $from, $to);

        // Habang may buhay na form, hindi ito napapalitan ng ibang card at hindi isinasara ng click sa labas.
        $open = $body('splOpen(name, cell, mode, el, pinned){', 'splToggle(');
        $this->assertStringContainsString("if (mode !== 'form' && this.splCard.mode === 'form' && this.splLive()) return;", $open);
        $outside = $body('splOutside(e){', 'splSync(){');
        $this->assertStringContainsString("if (this.splCard.mode === null || this.splCard.mode === 'form' || this.photoModal.open) return;", $outside);

        // Ang bawat "+" at ✎ ay dumadaan sa iisang helper na umaatras habang may buhay na form o may save na hindi pa
        // sumasagot: kung hindi, mawawala ang tina-type. (Text ng source ang binabasa rito; hindi ito pinapatakbo.)
        $form = $body('splForm(name, q, cell, el){', 'splHover(');
        $this->assertOrder([
            'if (this.quoteForm.saving) return;',
            "if (this.splCard.mode === 'form' && this.splLive()) return;",
            'this.openQuote(name, q);',
            "this.splOpen(name, cell, 'form', el, true);",
        ], $form);
        $this->assertStringNotContainsString('openQuote(', $table);
        $this->assertSame(5, substr_count($table, 'splForm('));
        $this->assertSame(1, substr_count($table, "@click.stop=\"splForm(row.item_name, null, 'band', \$el)\""));
        $this->assertSame(2, substr_count($table, '@click.stop="splForm(row.item_name, null, si, $el)"'));
        $this->assertSame(2, substr_count($table, '@click.stop="splForm(row.item_name, q, si, $el)"'));

        // Esc: ang photo popup muna; ang form ay dumadaan sa Cancel; focus lang kapag naka-pin ang card.
        $esc = $body('splEsc(){', 'splOutside(e){');
        $this->assertStringContainsString('if (this.splCard.mode === null || this.photoModal.open) return;', $esc);
        $this->assertStringContainsString("if (this.splCard.mode === 'form') { this.splCancel(); return; }", $esc);
        $this->assertStringContainsString('this.splClose(this.splCard.pinned);', $esc);
        $this->assertStringNotContainsString('splClose(true)', $esc);

        // Habang may save na hindi pa sumasagot, walang nagbubura ng key ng form.
        $cancel = $body('splCancel(){', 'splRemove(');
        $this->assertStringContainsString('if (this.quoteForm.saving) return;', $cancel);
        $this->assertStringContainsString("if (mode !== 'form' && !this.quoteForm.saving) this.quoteForm.key = null;", $open);
        $this->assertSame(2, substr_count($js, 'quoteForm.key = null'));
        $this->assertSame(1, substr_count($cancel, 'quoteForm.key = null'));
        $this->assertStringNotContainsString('quoteForm.key = null', $table);
        $this->assertSame(substr_count($table, 'class="spl-form"'), substr_count($table, '@click.stop="splCancel()">Cancel</button>'));

        // Ang remove ay nagsasara lang pagkatapos ng totoong bura (bilang ng listahan), at ibinabalik ang focus.
        $remove = $body('async splRemove(name, q){', 'splPlace(){');
        $this->assertStringContainsString('const n = this.quotesFor(name).length;', $remove);
        $this->assertStringContainsString('if (this.quotesFor(name).length === n) return;', $remove);
        // Ang card na pinindutan lang ang isinasara: kinukuha ang pagkakakilanlan nito bago maghintay ng sagot.
        $this->assertOrder([
            'const item = this.splCard.item, cell = this.splCard.cell, mode = this.splCard.mode;',
            'await this.deleteQuote(name, q);',
            'if (this.splIs(item, cell, mode)) this.splClose(true);',
        ], $remove);
        $this->assertSame(1, substr_count($remove, 'splClose('));
        $this->assertSame(2, substr_count($table, '@click.stop="splRemove(row.item_name, q)">✕</button>'));
        $this->assertStringNotContainsString('deleteQuote(', $table);
        $this->assertStringNotContainsString('splClose(', $table);

        // Pagkatapos ng matagumpay na save: sarado ang card at balik ang focus.
        $this->assertStringContainsString("if (this.splCard.mode === 'form' && this.quoteForm.key === null) this.splClose(true);", $body('splSync(){', '},'));

        // Ang naka-pin na card ay sumusunod sa cell nito sa scroll at sa pagbabago ng laki ng window.
        $this->assertSame(1, substr_count($table, '@scroll.passive="splScrolled()" @resize.window="splScrolled()"'));
    }

    public function test_S_20_2_a_link_is_rendered_only_through_the_http_guard(): void
    {
        $guard = "safeLink(u){ const s = String(u || '').trim(); return /^https?:\\/\\//i.test(s) ? s : ''; }";
        $this->assertSame(1, substr_count($this->render('ceo', true, true), $guard));

        $links = array_values(array_filter($this->tags($this->groupSource()), fn (string $tag) => str_starts_with($tag, '<a ')));
        $this->assertNotEmpty($links);
        foreach ($links as $tag) {
            $this->assertStringContainsString(' :href="safeLink(q.link)"', $tag);
            $this->assertStringContainsString(' target="_blank"', $tag);
            $this->assertStringContainsString(' rel="noopener"', $tag);
        }
        // Bawat link ay nasa loob mismo ng guard, at walang ibang :href na galing sa link ng quote.
        $table = $this->tableSource();
        $this->assertSame(count($links), preg_match_all('/' . preg_quote(self::LINK_IF, '/') . '\s*<a /', $table));
        $this->assertSame(count($links), preg_match_all('/\s:href="[^"]*link[^"]*"/', $table));
        $this->assertSame(0, preg_match_all('/\shref="[^"]*q\.[^"]*"/', $table));
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
