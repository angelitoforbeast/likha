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
     * Key: <layout>.<viewer>. Ang old.ceo ay ikinukumpara sa orihinal na table ng CEO, pagkatapos tanggalin ang link nito sa toolbar at ang salitang pinapanatili nito sa address.
     */
    private const BASE = [
        'default.ceo'              => '5bc06183505014c1c76d6a4ad3b2a64d85bae452',
        'default.ceo_as_marketing' => 'a7f063ad4ee56665ad763514b4dfa513284ec91e',
        'default.marketing'        => 'c87616640b0ef585d2217aec00eb419124757f9b',
        'default.marketing_oic'    => '57c713bd753a02648b4d8cd765188dfacf37e323',
        'old.ceo'                  => 'b8395ba9d734e304e8bc7afc39bade923db1674a', // ikinukumpara pagkatapos tanggalin ang link sa toolbar at ang salita sa address
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

    // ── Ang mga salita ng layout, ang gate at ang dalawang link sa toolbar ────
    // ?layout=old at ?layout=suppliers = table na may suppliers (CEO view lang); ?layout=original = orihinal na table.

    /** Ang link sa table na may suppliers, papunta sa orihinal na table, kasama ang indentation at line break nito. */
    private const ORIGINAL_LINK = "    <a href=\"?layout=original\" @click.prevent=\"const q = new URLSearchParams(window.location.search); q.set('layout', 'original'); window.location.href = window.location.pathname + '?' + q.toString()\"\n"
        . "       title=\"Open the table as it was before the suppliers columns\"\n"
        . "       style=\"background:#1e293b;color:#c4b5fd;border:1px solid #475569;text-decoration:none;\n"
        . "              border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700;\n"
        . "              cursor:pointer;margin-left:4px;\">🗂 Original table</a>\n";

    /** Ang link sa orihinal na table ng CEO, pabalik sa table na may suppliers. */
    private const SUPPLIERS_TABLE_LINK = "    <a href=\"?layout=old\" @click.prevent=\"const q = new URLSearchParams(window.location.search); q.set('layout', 'old'); window.location.href = window.location.pathname + '?' + q.toString()\"\n"
        . "       title=\"Back to the table with suppliers and prices side by side\"\n"
        . "       style=\"background:#1e293b;color:#c4b5fd;border:1px solid #475569;text-decoration:none;\n"
        . "              border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700;\n"
        . "              cursor:pointer;margin-left:4px;\">🏷 Table with suppliers</a>\n";

    /** Ang linya ng script na nagpapanatili ng ?layout=old sa address; pareho para sa lahat ng viewer. */
    private const OLD_ADDRESS = "        if (this.layoutOld) qsObj.layout = 'old';\n";

    /** Ang dalawang linyang kasunod ng OLD_ADDRESS sa orihinal na table ng CEO. */
    private const ORIGINAL_ADDRESS = "        // Orihinal na table — dapat manatili sa URL; kung hindi, gagawin itong ?layout=old (table na may suppliers) ng unang load.\n"
        . "        qsObj.layout = 'original';\n";

    /**
     * Ang tatlong viewer na hindi CEO view: [key ng BASE, role, email, mga dagdag sa address na hindi dapat
     * magbago ng anuman, ang view data na ginagamit ng render() para sa viewer na iyon:
     * isCEO, isMarketingOIC, viewAs, effectiveIsCEO].
     */
    private const NON_CEO_VIEWS = [
        ['marketing', 'Marketing', 'mkt@example.test', ['', '&view_as=ceo', '&view_as=CEO'], [false, false, 'ceo', false]],
        ['marketing_oic', 'Marketing - OIC', 'oic@example.test', ['', '&view_as=ceo', '&view_as=CEO'], [false, true, 'ceo', false]],
        ['ceo_as_marketing', 'CEO', 'ceo@example.test', ['&view_as=marketing', '&view_as=MARKETING', '&view_as=marketing%20'], [true, false, 'marketing', false]],
    ];

    private const LAYOUT_WORDS = ['old', 'suppliers', 'original'];

    /** Mga marker ng table na may suppliers at ng dalawang link; nasa CEO view ang bawat isa, wala ni isa sa hindi CEO. */
    private const SUPPLIERS_MARKERS = [
        'SUPPLIERS', 'spl-', 'splReady', 'splCols', 'splCell', 'splSpan', 'splNone', 'splLoaded', 'splFailed',
        'hindi na-load', 'spl-card', 'splCard', 'spl-warn', 'spl-dot', 'spl-edit', 'wala pang supplier',
        'Add a supplier in Finance', 'ItemTableFit', 'item-table-fit.js', 'supplierColumns', 'supplierCell',
        'noSupplier', 'formPreset',
        'layout=original', "'original'", 'Original table', 'before the suppliers columns',
        'Table with suppliers', 'suppliers and prices side by side',
    ];

    /** Normalised na body ng isang GET; dito agad kinukuha dahil iba ang CSRF token ng bawat request. */
    private function body(string $url): string
    {
        return $this->normalise((string) $this->get($url)->assertOk()->getContent());
    }

    private function actAs(string $role = 'CEO', string $email = 'ceo@example.test'): void
    {
        $this->actingAs(User::where('email', $email)->first() ?? $this->user($role, $email));
    }

    /** Ang view data na nagpapasya kung aling table at aling mga block ang lalabas: ang apat ng NON_CEO_VIEWS, tapos layoutOld at layoutSuppliers. */
    private function gate(string $url): array
    {
        $res = $this->get($url)->assertOk();

        return array_map(fn (string $key) => $res->viewData($key), ['isCEO', 'isMarketingOIC', 'viewAs', 'effectiveIsCEO', 'layoutOld', 'layoutSuppliers']);
    }

    public function test_S_13_5_only_the_exact_layout_words_select_a_view(): void
    {
        // [layoutOld, layoutSuppliers] ng CEO.
        $cases = [
            '/item'                                    => [false, false],
            '/item?layout=old'                         => [true, true],
            '/item?layout=suppliers'                   => [true, true],
            '/item?layout=original'                    => [true, false],
            '/item?layout=SUPPLIERS'                   => [false, false],
            '/item?layout=supplier'                    => [false, false],
            '/item?layout=x'                           => [false, false],
            '/item?layout[]=suppliers'                 => [false, false],
            '/item?layout=old&view_as=marketing'       => [true, false],
            '/item?layout=suppliers&view_as=marketing' => [true, false],
            '/item?layout=original&view_as=marketing'  => [true, false],
        ];
        $this->actAs();
        foreach ($cases as $url => $expected) {
            $this->assertSame($expected, array_slice($this->gate($url), 4), $url);
        }
    }

    public function test_S_34_1_layout_old_draws_one_header_per_supplier_of_the_list(): void
    {
        $this->actAs();
        $this->assertSame([true, false, 'ceo', true, true, true], $this->gate('/item?layout=old'));

        $body = $this->body('/item?layout=old');
        $this->assertSame(1, substr_count($body, self::ORIGINAL_LINK));
        $this->assertSame(1, substr_count($body, self::OLD_ADDRESS));
        $this->assertStringContainsString(self::GROUP_HEADER, $body);
        $this->assertStringContainsString(self::SPL_READY, $body);
        $this->assertSame(2, substr_count($body, '<style'));

        // Ang header at ang mga cell ay umiikot sa listahan ng supplier: walang nakasulat na bilang ng column.
        $table = $this->tableSource();
        $this->assertSame(1, substr_count($table, self::HEADER_FOR));
        $this->assertSame(1, substr_count($table, self::CELL_FOR));
        $this->assertStringContainsString("splCols(){ return ItemTableFit.supplierColumns(this.supplierList); },", $body);
        $this->assertStringContainsString('<th class="spl-sh" :title="c.full" :aria-label="c.pos + \' · \' + c.full"><span x-text="c.header"></span></th>', $table);
        foreach (['Supplier 1', 'Supplier 2', 'Supplier 3', 'Others', '>PO</th>', '[0, 1, 2]', 'colspan="4"', 'colspan="5"', 'colspan="3"', 'splTop3', 'splRest'] as $gone) {
            $this->assertStringNotContainsString($gone, $body, $gone);
        }

        // Ang script ng mga pure function: isang tag, sa loob ng suppliers view lang, may bersyon mula sa laman ng file.
        $version = substr(md5_file(public_path('js/item-table-fit.js')), 0, 10);
        $this->assertSame(1, substr_count($body, '<script src="APPROOT/js/item-table-fit.js?v=' . $version . '"></script>'));
        $this->assertSame(1, substr_count($body, 'item-table-fit.js?v='));
        $this->assertLessThan(strpos($body, '<div id="scroll" class="spl-scroll"'), strpos($body, 'item-table-fit.js?v='));
    }

    public function test_S_22_2_layout_suppliers_is_the_same_view_and_the_address_stays_layout_old(): void
    {
        $this->actAs();
        $suppliers = $this->body('/item?layout=suppliers');

        $this->assertSame([true, false, 'ceo', true, true, true], $this->gate('/item?layout=suppliers'));
        $this->assertTrue($suppliers === $this->body('/item?layout=old'), 'iba ang layout=suppliers sa layout=old');
        $this->assertSame(1, substr_count($suppliers, self::OLD_ADDRESS));
        $this->assertStringNotContainsString("qsObj.layout = 'suppliers'", $suppliers);
        $this->assertStringNotContainsString("qsObj.layout = 'original'", $suppliers);
    }

    public function test_S_22_3_any_other_layout_value_is_the_default_layout_of_the_base_for_the_ceo(): void
    {
        // Ang render sa mga flag na ito ay nakapin na sa test_S_19_2; dito, ang route ang sinusubok.
        $this->actAs();
        $default = $this->body('/item');
        $urls = [
            '/item', '/item?layout=', '/item?layout=OLD', '/item?layout=Old', '/item?layout=x', '/item?layout=olds',
            '/item?layout[]=old', '/item?layout[]=original', '/item?layout[old]=1', '/item?layout=old&layout=x',
        ];
        foreach ($urls as $url) {
            $this->assertSame([true, false, 'ceo', true, false, false], $this->gate($url), $url);
            $this->assertTrue($default === $this->body($url), "{$url}: iba sa default layout");
        }
    }

    public function test_S_22_4_the_old_view_of_the_ceo_has_the_original_table_link_only(): void
    {
        $this->actAs();
        $body = $this->body('/item?layout=old');

        $this->assertSame(1, substr_count($body, self::ORIGINAL_LINK));
        $this->assertSame(1, substr_count($body, 'Original table'));
        $this->assertStringContainsString('✨ New view', $body);
        // Ang salitang "Suppliers view" ay nasa dalawang comment pa ng script ng table na ito; ang link ang wala na.
        foreach (['Suppliers view</a>', 'Open the table with suppliers', 'layout=suppliers', 'Table with suppliers', '🗂 Old view'] as $gone) {
            $this->assertStringNotContainsString($gone, $body, $gone);
        }
    }

    public function test_S_23_1_layout_original_is_the_original_table_for_the_ceo_view(): void
    {
        $this->actAs();
        $this->assertSame([true, false, 'ceo', true, true, false], $this->gate('/item?layout=original'));

        $body = $this->body('/item?layout=original');
        // Ang orihinal na table, kasama ang nakapatong na mga linya ng supplier sa Item cell.
        foreach (['<td>TOTAL</td>', 'Drag headers to reorder', '>walang supplier<', '+ supplier quote', '🏭'] as $present) {
            $this->assertStringContainsString($present, $body, $present);
        }
        foreach (['SUPPLIERS', 'spl-', 'splReady', 'splLoaded', 'Original table', 'layout=original', 'Suppliers view'] as $gone) {
            $this->assertStringNotContainsString($gone, $body, $gone);
        }
        $this->assertSame(1, substr_count($body, '<style'));
        $this->assertSame(1, substr_count($body, self::OLD_ADDRESS . self::ORIGINAL_ADDRESS));
        $this->assertSame(1, substr_count($body, "qsObj.layout = 'original'"));
        $this->assertSame(1, substr_count($body, self::SUPPLIERS_TABLE_LINK));
        $this->assertSame(1, substr_count($body, 'Table with suppliers'));
    }

    public function test_S_23_2_the_original_table_of_the_ceo_differs_from_the_base_old_view_by_two_strings(): void
    {
        $original = $this->normalise($this->render('ceo', true));

        $this->assertSame(1, substr_count($original, self::SUPPLIERS_TABLE_LINK));
        $this->assertSame(1, substr_count($original, self::ORIGINAL_ADDRESS));
        $this->assertSame(self::BASE['old.ceo'], sha1(str_replace([self::SUPPLIERS_TABLE_LINK, self::ORIGINAL_ADDRESS], '', $original)));
    }

    public function test_S_24_1_non_ceo_views_get_the_base_old_view_for_every_layout_word(): void
    {
        $sid = $this->supplier('Zyxwv Kalakal');
        $this->quote('HAND GRIP', $sid, 100, 10);
        $this->po($sid, 'HAND GRIP', 'hand grip', 5, 90);

        // Hindi kailanman nasa render ng page (pangalan ng file; pangalan ng supplier na sa fetch lang dumarating;
        // ang lumang pangalan ng view), kaya walang positive control ang mga ito: tripwire lang kung sakaling may mag-print ng mga ito balang araw.
        $tripwires = ['_table_suppliers', 'Zyxwv Kalakal', 'Suppliers view', 'layout=suppliers', "'suppliers'", '1 · Zyxwv', 'Supplier 1', 'groupSpan'];

        // Patunayan na totoo ang mga marker: nasa dalawang view ng CEO ang mga ito, kaya may ibig sabihin ang pagkawala nila sa iba.
        $this->actAs();
        $ceo = $this->body('/item?layout=old') . $this->body('/item?layout=original');
        foreach (self::SUPPLIERS_MARKERS as $marker) {
            $this->assertStringContainsString($marker, $ceo, "CEO: {$marker}");
        }

        foreach (self::NON_CEO_VIEWS as [$viewer, $role, $email, $extras, $viewData]) {
            $this->actAs($role, $email);
            $old = $this->body('/item?layout=old' . $extras[0]);

            foreach (self::LAYOUT_WORDS as $word) {
                foreach ($extras as $extra) {
                    $url = "/item?layout={$word}{$extra}";
                    // Parehong view data sa ginagamit ng nakapin na render ng viewer na ito (test_S_18_4), at orihinal na table ang napili.
                    $this->assertSame(array_merge($viewData, [true, false]), $this->gate($url), "{$viewer}: {$url}");
                    $body = $this->body($url);
                    $this->assertTrue($body === $old, "{$viewer}: iba ang {$url} sa layout=old");
                    foreach (array_merge(self::SUPPLIERS_MARKERS, $tripwires) as $marker) {
                        $this->assertStringNotContainsString($marker, $body, "{$viewer}: {$url}: {$marker}");
                    }
                }
            }
        }
    }

    public function test_S_24_2_the_script_does_not_call_the_loaders_for_non_ceo_views(): void
    {
        $calls = ['this.loadItemSuppliers(),', 'this.loadItemQuotes(),'];

        // Patunayan na totoo ang mga marker: sa dalawang view ng CEO, tig-isang beses ang bawat tawag.
        $this->actAs();
        foreach (['old', 'original'] as $word) {
            $ceo = $this->body('/item?layout=' . $word);
            foreach ($calls as $call) $this->assertSame(1, substr_count($ceo, $call), "CEO {$word}: {$call}");
        }

        foreach (self::NON_CEO_VIEWS as [$viewer, $role, $email, $extras]) {
            $this->actAs($role, $email);
            foreach (self::LAYOUT_WORDS as $word) {
                $body = $this->body("/item?layout={$word}{$extras[0]}");
                foreach ($calls as $call) $this->assertStringNotContainsString($call, $body, "{$viewer} {$word}: {$call}");
            }
        }
    }

    public function test_S_24_3_the_default_layout_of_non_ceo_views_is_the_base(): void
    {
        foreach (self::NON_CEO_VIEWS as [$viewer, $role, $email, $extras, $viewData]) {
            $this->actAs($role, $email);
            $query = ltrim($extras[0], '&');
            $default = '/item' . ($query === '' ? '' : '?' . $query);
            // Parehong view data sa ginagamit ng nakapin na render ng viewer na ito (test_S_18_4), at default layout ang napili.
            $this->assertSame(array_merge($viewData, [false, false]), $this->gate($default), $viewer);
            foreach (['x', 'ORIGINAL', 'Old'] as $word) {
                $url = "/item?layout={$word}{$extras[0]}";
                $this->assertSame(array_merge($viewData, [false, false]), $this->gate($url), "{$viewer}: {$url}");
                $this->assertTrue($this->body($default) === $this->body($url), "{$viewer}: iba ang {$url} sa default layout");
            }
        }
    }

    public function test_S_24_4_only_the_exact_word_original_after_trimming_selects_the_original_table(): void
    {
        // [layoutOld, layoutSuppliers] para sa CEO; ang hindi CEO ay hindi kailanman nakakakuha ng layoutSuppliers.
        $cases = [
            '/item?layout=original%20'             => [true, false], // tinatanggal ng framework ang puwang, gaya ng sa old
            '/item?layout=%20original'             => [true, false],
            '/item?layout=old%20'                  => [true, true],
            '/item?layout=old&layout=original'     => [true, false], // ang huli ang binabasa
            '/item?layout=original&layout=old'     => [true, true],
            '/item?layout=Original'                => [false, false],
            '/item?layout=ORIGINAL'                => [false, false],
            '/item?layout=originals'               => [false, false],
            '/item?layout[]=original'              => [false, false],
            '/item?layout[original]=original'      => [false, false],
            '/item?layout[]=old&layout[]=original' => [false, false],
        ];

        $this->actAs();
        $original = $this->body('/item?layout=original');
        foreach ($cases as $url => $expected) {
            $this->assertSame($expected, array_slice($this->gate($url), 4), "CEO: {$url}");
        }
        $this->assertTrue($original === $this->body('/item?layout=original%20'), 'iba ang may puwang sa dulo');

        $this->actAs('Marketing', 'mkt@example.test');
        foreach ($cases as $url => $expected) {
            $this->assertSame([$expected[0], false], array_slice($this->gate($url), 4), "Marketing: {$url}");
        }
    }

    // ── S-13.1 – S-13.4 / S-14.8 / S-15.10 / S-19.4 / S-19.6 / S-21.1: ang table ng suppliers view ──
    // Binabasa ng mga test na ito ang markup at ang text ng script; hindi nila pinapatakbo ang script.

    private const TABLE_FILE = 'views/item/_table_suppliers.blade.php';
    private const JS_FILE = 'views/item/_suppliers_js.blade.php';

    private const SPL_READY = 'splReady(){ return this.splLoaded.quotes && this.splLoaded.po; },';
    private const LOADED_PO = 'if (res.ok && j && j.ok === true && j.suppliers) this.splLoaded.po = true; else this.splFailed = true;';
    private const LOADED_QUOTES = 'if (res.ok && j && j.ok === true && j.quotes) this.splLoaded.quotes = true; else this.splFailed = true;';

    private const GROUP_HEADER = '<th class="spl-grp" :colspan="splSpan()">SUPPLIERS</th>';
    private const HEADER_FOR = "<template x-for=\"c in splCols()\" :key=\"'spl-h-'+c.id\">";
    private const WAIT_IF = '<template x-if="!splReady()">';
    private const ZERO_IF = '<template x-if="splReady() && !splCols().length">';
    private const CELL_FOR = '<template x-for="c in (splReady() ? splCols() : [])"';
    private const WARN_IF = '<template x-if="splReady() && splNone(row.item_name)">';
    private const FULL_WIDTH = ':colspan="cols.length + 2 + splSpan()"';

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

    public function test_S_13_1_header_has_two_rows_with_suppliers_over_one_subheader_per_supplier(): void
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

        // Ang grupo ay kasinlapad ng listahan ng supplier, at ang pangalawang row ay isang sub-header kada supplier.
        $this->assertStringContainsString(self::GROUP_HEADER, $head);
        $this->assertOrder(['<span>Item</span>', ':colspan="splSpan()">SUPPLIERS<', 'x-for="col in cols"', '<tr class="spl-h2">'], $head);

        $second = $this->between($head, '<tr class="spl-h2">', '</tr>');
        $this->assertOrder([self::HEADER_FOR, '<th class="spl-sh" :title="c.full"', '<span x-text="c.header"></span>', '<template x-if="!splCols().length">', '<th class="spl-sh spl-sh-none">'], $second);
        $this->assertSame(2, substr_count($second, '<th'));
        foreach (['@click', 'draggable', 'Supplier 1', '>PO<', 'sb('] as $never) {
            $this->assertStringNotContainsString($never, $second, $never);
        }
    }

    public function test_S_13_2_item_row_has_one_cell_per_supplier_after_the_item_cell(): void
    {
        $row = $this->itemRow();

        $this->assertOrder([
            'spl-c2',
            '<td :colspan="splSpan()" class="spl-sc spl-wait" @click.stop>',
            '<td class="spl-sc spl-zero" @click.stop>',
            self::CELL_FOR,
            '<td class="spl-sc" @click.stop>',
            "<template x-for=\"C in [splCell(row.item_name, c.id)]\" :key=\"'spl-c-'+row.item_name+'-'+c.id\">",
            "<template x-for=\"col in cols\" :key=\"'ic-'+row.item_name+'-'+col.id\">",
        ], $row);
        foreach (['class="spl-sc spl-wait"', 'class="spl-sc spl-zero"', self::CELL_FOR, '<td class="spl-sc" @click.stop>', 'splCell('] as $once) {
            $this->assertSame(1, substr_count($row, $once), $once);
        }
        // Walang cell ng PO at walang band: ang PO ay nasa cell ng sarili nitong supplier.
        foreach (['spl-po"', 'spl-nosup', 'spl-band', 'suppliersFor(', 'quotesFor(', '[0, 1, 2]'] as $gone) {
            $this->assertStringNotContainsString($gone, $row, $gone);
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

    /** Ang colspan ng bawat <td> / <th> ng isang piraso ng markup bilang text: '1' kapag wala, ang expression kapag naka-bind. */
    private function spanExpressions(string $markup): array
    {
        preg_match_all('/<t[dh](?=[\s>])(?:[^>"]|"[^"]*")*>/', $markup, $m);

        return array_map(fn (string $tag) => preg_match('/\s:?colspan="([^"]*)"/', $tag, $c) ? $c[1] : '1', $m[0]);
    }

    public function test_S_13_4_S_15_8_every_row_spans_page_item_the_supplier_columns_and_the_other_columns(): void
    {
        $old = $this->render('ceo', true);
        $suppliers = $this->render('ceo', true, true);
        $plusTwo = '/:colspan="\(?cols\.length \+ 2\)?"/';
        $this->assertSame(5, preg_match_all($plusTwo, $old));
        $this->assertSame(0, substr_count($old, 'splSpan'));
        $this->assertSame(0, preg_match_all($plusTwo, $suppliers));
        // Loading, walang data, walang nasa listahan, walang nasa category, at ang naka-expand na block ng page.
        $this->assertSame(5, substr_count($suppliers, self::FULL_WIDTH));

        $table = $this->suppliersTable();
        $row = $this->itemRow();
        $pageRow = $this->between($table, '<!-- Fixed: Page -->', '<!-- Dynamic columns -->');
        $pageHeader = $this->between($table, 'class="page-col-header"', "'ph-'");
        $this->assertSame(['1', '1'], $this->spanExpressions($this->between($row, '>', self::WAIT_IF)));

        // Bawat klase ng row, bago ang mga configurable column: Page + Item + ang grupo. Ang grupo ay splSpan() ang
        // lapad, o isang cell kada supplier ng parehong listahan (ang loop), o ang nag-iisang cell kapag walang supplier
        // (1 ang splSpan() noon).
        $kinds = [
            'header row 1'   => [$this->spanExpressions($this->between($table, '<tr class="spl-h1">', 'x-for="col in cols"')), ['1', '1', 'splSpan()']],
            'item row, wait' => [$this->spanExpressions($this->between($row, self::WAIT_IF, self::ZERO_IF)), ['splSpan()']],
            'item row, zero' => [$this->spanExpressions($this->between($row, self::ZERO_IF, self::CELL_FOR)), ['1']],
            'item row, cell' => [$this->spanExpressions($this->between($row, self::CELL_FOR, "'ic-'")), ['1']],
            'page header'    => [$this->spanExpressions($pageHeader), ['1', '1', 'splSpan()']],
            'page row'       => [$this->spanExpressions($pageRow), ['1', '1', 'splSpan()']],
            'TOTAL'          => [$this->spanExpressions($this->between($table, 'class="total-row"', 'x-for="col in cols"')), ['1', '1 + splSpan()']],
        ];
        foreach ($kinds as $kind => [$actual, $expected]) {
            $this->assertSame($expected, $actual, $kind);
        }
        // Ang pangalawang row ng header: ang loop ng mga supplier, o ang nag-iisang header kapag walang supplier.
        $this->assertSame(['1', '1'], $this->spanExpressions($this->between($table, '<tr class="spl-h2">', '</tr>')));
    }

    public function test_S_34_5_no_colspan_is_ever_zero_and_an_empty_list_links_to_the_supply_page(): void
    {
        // Bawat colspan ng table ay literal na hindi 0, o dumadaan sa splSpan() (na hindi bumababa sa 1: nasa node test).
        $source = $this->tableSource();
        preg_match_all('/\s(:?)colspan="([^"]*)"/', $source, $m, PREG_SET_ORDER);
        $this->assertGreaterThanOrEqual(10, count($m));
        foreach ($m as [, $bound, $value]) {
            if ($bound === '') {
                $this->assertGreaterThan(0, (int) $value, $value);
                continue;
            }
            $this->assertContains($value, ['splSpan()', '1 + splSpan()', 'cols.length + 2 + splSpan()'], $value);
        }
        $this->assertStringContainsString('splSpan(){ return ItemTableFit.groupSpan(this.splCols().length); },', $this->render('ceo', true, true));

        // Walang supplier sa sumagot nang listahan: isang header na may link papunta sa Finance → Supply; bago ang
        // sagot, ang neutral na placeholder. Ang babala ay hindi lumalabas bago sumagot ang dalawang listahan.
        $none = $this->between($this->normalise($this->render('ceo', true, true)), '<template x-if="!splCols().length">', '</tr>');
        $this->assertOrder([
            '<template x-if="!splLoaded.quotes">',
            "x-text=\"splFailed ? 'hindi na-load' : '…'\"",
            '<template x-if="splLoaded.quotes">',
            '<a href="APPROOT/finance/supply" target="_blank" rel="noopener">Add a supplier in Finance → Supply</a>',
        ], $none);
        $this->assertSame(1, substr_count($source, self::WARN_IF));
    }

    public function test_S_35_6_the_templates_read_the_servers_cheapest_flag_and_compare_no_price(): void
    {
        // Ang server ang nag-aayos at nagmamarka ng pinakamura; ang script ng view ay hindi nag-so-sort o nagkukumpara ng presyo.
        $js = file_get_contents(resource_path(self::JS_FILE));
        foreach (['.sort(', 'price', 'Math.min', '.slice('] as $never) {
            $this->assertStringNotContainsString($never, $js, $never);
        }
        $this->assertStringNotContainsString('cheapest', (string) preg_replace('~//.*$~m', '', $js));

        // Sa template: iisang anyo lang ang pagbasa ng marka, mula sa laman ng cell.
        $table = $this->tableSource();
        $marks = substr_count($table, ":class=\"C.cheapest ? 'spl-price spl-low' : (C.price === null ? 'spl-price spl-nil' : 'spl-price')\"");
        $this->assertSame(2, $marks);
        $this->assertSame($marks, preg_match_all('/\bcheapest\b/', $table));
        $this->assertSame($marks, substr_count($table, 'spl-low'));

        // Sa pure function: ang marka ay ang sa quote mismo, at walang paghahambing ng halaga saanman sa file.
        $fit = (string) preg_replace('~//.*$~m', '', file_get_contents(public_path('js/item-table-fit.js')));
        $this->assertSame(1, substr_count($fit, 'cell.cheapest = quote.cheapest === true;'));
        $this->assertSame(2, preg_match_all('/\bcheapest\b\s*[:=](?!=)/', $fit));
        foreach (['Math.min', 'Math.max'] as $never) $this->assertStringNotContainsString($never, $fit, $never);
    }

    public function test_S_15_10_the_cells_and_the_warning_are_bound_to_the_loaded_state(): void
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

        // Ang placeholder, ang blangkong cell, ang mga cell ng supplier at ang babala ay nakatali lahat sa splReady().
        $row = $this->itemRow();
        $this->assertOrder([self::WARN_IF, self::WAIT_IF, self::ZERO_IF, self::CELL_FOR], $row);
        $wait = $this->between($row, self::WAIT_IF, self::ZERO_IF);
        $this->assertStringContainsString("x-text=\"splFailed ? 'hindi na-load' : '…'\"", $wait);
        $this->assertStringContainsString(":title=\"splFailed ? 'Hindi na-load ang listahan ng supplier. I-refresh ang page.' : 'Loading suppliers…'\"", $wait);
        // Ang "+" ay nasa labas ng placeholder, sa loob ng loop na may splReady().
        $this->assertStringNotContainsString('splForm(', $this->between($row, '>', self::CELL_FOR));
        $this->assertSame(4, substr_count($row, 'splReady()'));

        foreach ([$this->render('ceo', true), $this->render('ceo', false)] as $other) {
            $this->assertStringNotContainsString('splLoaded', $other);
            $this->assertStringNotContainsString('splFailed', $other);
        }
    }

    public function test_S_37_1_S_37_3_one_small_warning_mark_replaces_the_red_band_and_waits_for_both_lists(): void
    {
        $row = $this->itemRow();
        $itemCell = $this->between($row, '<td class="spl-c1">', '</td>');

        // Isang babala, sa loob ng item cell, pagkatapos ng HOLD; lumalabas lang kapag sumagot na ang dalawang listahan.
        $mark = self::WARN_IF . "\n" . '                    <span class="spl-warn" role="img" title="wala pang supplier" aria-label="wala pang supplier">!</span>';
        $this->assertSame(1, substr_count(str_replace("\r\n", "\n", $itemCell), $mark));
        $this->assertSame(1, substr_count($row, 'spl-warn'));
        $this->assertOrder(['class="item-hold"', 'class="spl-warn"'], $itemCell);
        $this->assertStringContainsString('splNone(name){ return ItemTableFit.noSupplier(this.quotesFor(name), this.suppliersFor(name)); },', $this->render('ceo', true, true));

        // Wala na ang pulang band, saanman: markup, style at salita.
        foreach ([self::TABLE_FILE, self::STYLE_FILE, self::JS_FILE] as $file) {
            $source = file_get_contents(resource_path($file));
            foreach (['spl-band', 'spl-nosup', '⚠ wala pang supplier'] as $gone) {
                $this->assertStringNotContainsString($gone, $source, "{$file}: {$gone}");
            }
        }
        // "walang running page" ay pula pa rin sa Item column.
        $this->assertStringContainsString('<div style="font-size:11px;color:#b91c1c;font-weight:700;">⚠ walang running page</div>', $row);
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
            // Dalawang link lang: ang kinopyang link ng photo page, at ang page kung saan idinadagdag ang supplier.
            $this->assertSame([], array_values(array_diff($this->routesIn($source), ["route('item.photo')", "route('finance.supply.index')"])), $file);
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
    private const SUPPLIER_VALUE = '/(?<![\w.$])(?:row\.item_name|[qs]\.(?:supplier|name|price|moq|link|photo_url|updated_at|prev_price|prev_date|unit_cost|order_date|order_no)|c\.(?:full|short|header)|C\.(?:quote|priceText|moqText|poLine))\b/';

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
        // Change, Copy (2); ang placeholder at ang blangkong cell (2); ang cell ng supplier (20: cell, halaga, ✎,
        // halaga ng PO, "+" ng PO, "+", card, photo, link, form card, form, 4 na field, Save, Cancel, Tanggalin).
        $this->assertSame(22, count($controls));
    }

    public function test_S_16_6_the_form_opens_for_one_row_only(): void
    {
        $html = $this->render('ceo', true, true);
        $table = $this->tableSource();

        // Ang form ay bukas lang sa row na may parehong item name, hindi sa bawat row na may parehong quote key.
        $this->assertStringContainsString(self::FORM_OPEN, $html);
        $this->assertStringNotContainsString('quoteForm.key === supKey(row.item_name)"', $table);
        // Iisa ang form: sa cell ng supplier. Kasama sa kondisyon ang sariling column: kung wala ito, lalabas ang form sa lahat ng cell ng row.
        $this->assertSame(1, substr_count($table, 'class="spl-form"'));
        $this->assertSame(1, substr_count($table, "<template x-if=\"splIs(row.item_name, c.id, 'form') && " . self::FORM_OPEN . '">'));
        $this->assertSame(1, substr_count($table, self::FORM_OPEN));

        // Ang save at delete ay ang dati nang functions at routes ng page; walang sariling request ang mga bagong file.
        foreach (['saveQuote()', 'splRemove(row.item_name, C.quote)', 'splForm(row.item_name, '] as $call) {
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

    /** Ang pangalawang row ng header sa source ng partial: ang mga sub-header ng supplier. */
    private function headerSource(): string
    {
        return $this->between($this->tableSource(), '<tr class="spl-h2">', '</tr>');
    }

    public function test_S_20_1_supplier_values_are_bound_as_text_and_the_note_is_not_shown(): void
    {
        $allowed = [
            'x-text', ':title', ':aria-label', ':alt', ':src', ':href', ':class', ':key', 'x-if', 'x-show', 'x-for',
            '@click', '@click.stop', ':aria-expanded', '@mouseenter', '@mouseleave',
        ];
        // Ang mga cell ng grupo at ang mga sub-header: doon lumalabas ang pangalan at mga halaga ng supplier.
        $seen = [];
        foreach ($this->tags($this->groupSource() . $this->headerSource()) as $tag) {
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
        // Ang pangalan ng supplier sa header: text, title at aria-label lang.
        $header = $this->headerSource();
        $this->assertStringContainsString('<th class="spl-sh" :title="c.full" :aria-label="c.pos + \' · \' + c.full"><span x-text="c.header"></span></th>', $header);
        $this->assertSame(3, preg_match_all('/\bc\.(?:full|short|header)\b/', $header));

        foreach ([self::TABLE_FILE, self::JS_FILE] as $file) {
            $source = file_get_contents(resource_path($file));
            foreach (['x-html', 'innerHTML', 'insertAdjacentHTML', 'outerHTML', 'document.write', '{!!', '.note'] as $never) {
                $this->assertStringNotContainsString($never, $source, "{$file}: {$never}");
            }
        }
        $fit = file_get_contents(public_path('js/item-table-fit.js'));
        foreach (['innerHTML', 'insertAdjacentHTML', 'outerHTML', 'document', 'window', '.note'] as $never) {
            $this->assertStringNotContainsString($never, $fit, "item-table-fit.js: {$never}");
        }

        // Ang presyo at MOQ ng cell ay text na buo na mula sa pure function (dash kapag walang presyo; ang MOQ na 0 ay value pa rin).
        $group = $this->groupSource();
        $this->assertSame(3, substr_count($group, 'x-text="C.priceText"'));
        $this->assertStringContainsString("x-text=\"C.moqText ? C.moqText : 'walang MOQ'\"", $group);
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

    public function test_S_34_4_the_header_binds_the_name_as_text_and_cuts_a_long_word(): void
    {
        $header = $this->headerSource();
        // Ang bawat value ng supplier sa header ay nasa x-text, :title, :aria-label o :key lang.
        foreach ($this->tags($header) as $tag) {
            foreach ($this->attributes($tag) as [$name, $value]) {
                if (!preg_match('/\bc\.\w+/', $value)) continue;
                $this->assertContains($name, ['x-text', ':title', ':aria-label', ':key'], $tag);
            }
        }
        $this->assertStringNotContainsString('{{ $', $header);

        // Ang mahabang unang salita ay pinuputol sa loob ng header: fixed ang lapad, nakatago ang sobra.
        $css = file_get_contents(resource_path(self::STYLE_FILE));
        $this->assertStringContainsString('.spl-table > thead > tr.spl-h2 > th.spl-sh { width:76px; min-width:76px; max-width:76px; padding:5px 6px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }', $css);
    }

    public function test_S_35_1_a_cell_shows_no_supplier_name(): void
    {
        // Bawat text na ipinapakita ng grupo, sunod-sunod. Ang pangalan ng supplier ay nasa pamagat lang ng card
        // at ng form (na bukas lang kapag hiningi), hindi kailanman sa cell.
        $names = [];
        $texts = [];
        foreach ($this->tags($this->groupSource()) as $tag) {
            foreach ($this->attributes($tag) as [$name, $value]) {
                if ($name !== 'x-text') continue;
                $texts[] = $value;
                if (preg_match('/\b(?:c\.(?:full|short|header)|[qs]\.supplier)\b/', $value)) $names[] = $tag;
            }
        }
        $this->assertSame([
            "splFailed ? 'hindi na-load' : '…'",
            'C.priceText', 'C.moqText', 'C.priceText',
            "c.pos + ' · ' + c.full", 'C.priceText', "C.moqText ? C.moqText : 'walang MOQ'",
            "'dati ' + money(q.prev_price) + (q.prev_date ? ' (' + q.prev_date + ')' : '')", "'updated ' + q.updated_at", 'C.poLine',
            "c.pos + ' · ' + c.full", "quoteForm.saving ? '…' : 'Save'",
        ], $texts);
        $this->assertSame([
            '<div class="spl-card-name" x-text="c.pos + \' · \' + c.full">',
            '<div class="spl-form-sup" x-text="c.pos + \' · \' + c.full">',
        ], $names);
        $this->assertStringNotContainsString('q.supplier', $this->groupSource());
    }

    public function test_S_35_3_a_filled_cell_has_an_edit_control_and_the_edit_card_can_remove(): void
    {
        $group = $this->groupSource();
        // Ang ✎ ay nasa cell na may quote, at binubuksan nito ang form sa quote ng sarili nitong column.
        $quoteCell = $this->between($group, '<template x-if="C.kind === \'quote\'">', '<template x-if="C.kind === \'po\'">');
        $this->assertSame(1, substr_count($quoteCell, '<button type="button" class="spl-edit" title="I-edit ang quote"'));
        $this->assertSame(1, substr_count($group, '@click.stop="splForm(row.item_name, C.quote, c.id, $el)">✎</button>'));

        // Ang bura ay nasa loob ng form (ang edit card), sa edit lang, at dumadaan sa dati nang tanong ng deleteQuote.
        $form = substr($group, (int) strpos($group, '<div class="spl-form"'));
        $this->assertSame(1, substr_count($group, 'splRemove('));
        $this->assertStringContainsString('<template x-if="quoteForm.id !== null && C.quote">', $form);
        $this->assertStringContainsString(':disabled="quoteForm.saving" @click.stop="splRemove(row.item_name, C.quote)">✕ Tanggalin</button>', $form);
        $this->assertStringContainsString("if (!confirm('Delete the quote from ' + q.supplier + ' for ' + name + '?')) return;", $this->render('ceo', true, true));
        foreach (['x-model="quoteForm.price"', 'x-model="quoteForm.moq"', 'x-model="quoteForm.link"', 'type="file"'] as $field) {
            $this->assertSame(1, substr_count($form, $field), $field);
        }

        // Lumalabas ang ✎ sa hover, sa keyboard focus, at laging nakikita sa touch.
        $css = str_replace("\r\n", "\n", file_get_contents(resource_path(self::STYLE_FILE)));
        foreach (['.spl-table .spl-cell:hover .spl-edit { opacity:1; }', '.spl-table .spl-cell:focus-within .spl-edit { opacity:1; }'] as $rule) {
            $this->assertStringContainsString($rule, $css, $rule);
        }
        $touch = $this->between($css, '@media (hover:none), (max-width:767px) {', "\n  }");
        $this->assertStringContainsString('.spl-table .spl-edit { opacity:1; }', $touch);
        $this->assertStringContainsString('.spl-table .spl-add-po { opacity:1; }', $touch);
    }

    public function test_S_35_4_the_cell_form_has_no_supplier_control(): void
    {
        $table = $this->tableSource();
        // Walang dropdown at walang field ng supplier sa table na ito: text lang ang supplier ng form.
        foreach (['<select', '<option', 'quoteForm.supplier_id', 'supplierList'] as $never) {
            $this->assertStringNotContainsString($never, $table, $never);
        }
        $this->assertSame(1, substr_count($table, '<div class="spl-form-sup" x-text="c.pos + \' · \' + c.full"></div>'));

        // Ang supplier ng form ay itinatakda ng column na pinindutan, sa iisang lugar, pagkabukas ng form.
        $js = str_replace("\r\n", "\n", file_get_contents(resource_path(self::JS_FILE)));
        $this->assertSame(1, substr_count($js, "this.openQuote(name, q);\n        Object.assign(this.quoteForm, ItemTableFit.formPreset(cell, q));\n"));
        $this->assertSame(0, substr_count($js, 'supplier_id'));
        $this->assertSame(3, substr_count($table, 'splForm(row.item_name, '));
        $this->assertSame(3, preg_match_all('/splForm\(row\.item_name, (?:C\.quote|null), c\.id, \$el\)/', $table));
    }

    public function test_S_36_1_a_po_shows_as_a_dot_with_a_label_and_as_a_line_in_the_card(): void
    {
        $group = $this->groupSource();
        // Ang tuldok ay may title at aria-label (ang linya ng PO), kaya hindi kulay lang ang nagsasabi.
        $this->assertSame(1, substr_count($group, "<template x-if=\"C.poDot\">\n                      <span class=\"spl-dot\" role=\"img\" :title=\"C.poLine\" :aria-label=\"C.poLine\"></span>"));
        // Ang linya ng PO ay nasa card ng cell, may quote man o wala.
        $card = $this->between($group, '<template x-if="C.kind !== \'empty\' && splIs(row.item_name, c.id, \'quote\')">', "splIs(row.item_name, c.id, 'form')");
        $this->assertSame(1, substr_count($card, "<template x-if=\"C.poLine\">\n                          <div class=\"spl-card-sub spl-card-po\" x-text=\"C.poLine\"></div>"));
        // PO lang: ang cost, ang tag na "PO", at isang "+" para sa quote ng supplier na iyon.
        $poCell = $this->between($group, '<template x-if="C.kind === \'po\'">', '<template x-if="C.kind === \'empty\'">');
        $this->assertOrder(['<span class="spl-pocost" x-text="C.priceText"></span>', '<span class="spl-potag">PO</span>', 'class="spl-add spl-add-po"', '@click.stop="splForm(row.item_name, null, c.id, $el)">+</button>'], $poCell);
        $this->assertStringNotContainsString('spl-low', $poCell);
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
        // Hindi napipindot ang Save habang may save na hindi pa sumasagot: ang pangalawang sagot ay bubura sa form na bukas noon.
        $this->assertSame(1, substr_count($table, ':disabled="quoteForm.saving" @click.stop="saveQuote()"'));

        // Ang bawat "+" at ✎ ay dumadaan sa iisang helper na umaatras habang may buhay na form o may save na hindi pa
        // sumasagot: kung hindi, mawawala ang tina-type. (Text ng source ang binabasa rito; hindi ito pinapatakbo.)
        $form = $body('splForm(name, q, cell, el){', 'splHover(');
        $this->assertOrder([
            'if (this.quoteForm.saving) return;',
            "if (this.splCard.mode === 'form' && this.splLive()) return;",
            'this.openQuote(name, q);',
            'Object.assign(this.quoteForm, ItemTableFit.formPreset(cell, q));',
            "this.splOpen(name, cell, 'form', el, true);",
        ], $form);
        $this->assertStringNotContainsString('openQuote(', $table);
        $this->assertSame(3, substr_count($table, 'splForm('));
        $this->assertSame(2, substr_count($table, '@click.stop="splForm(row.item_name, null, c.id, $el)"'));
        $this->assertSame(1, substr_count($table, '@click.stop="splForm(row.item_name, C.quote, c.id, $el)"'));

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
        $this->assertSame(1, substr_count($table, '@click.stop="splRemove(row.item_name, C.quote)">✕ Tanggalin</button>'));
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

        // May sentimo at magkakaibang haba ng numero: bilang ang pinagkukumpara, hindi text ("1000.5" < "9.5" kung text).
        foreach ([['D100', 100], ['D9', 9.5], ['D1000', 1000.5], ['D99', 99.99]] as [$name, $price]) {
            $this->quote('GLOW TAPE', $this->supplier($name), $price);
        }
        $this->assertSame(['D9', 'D99', 'D100', 'D1000'], array_column($this->listed('GLOW TAPE'), 'supplier'));
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
        // Set lang ng supplier ang tinitingnan dito — ang pagkakasunod ay may sarili nang mga test.
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

    // ── S-17.1 / S-17.2: ang styles at ang isang-linyang RTS / DEL / INT ay para sa suppliers view lang ──

    private const STYLE_FILE = 'views/item/_suppliers_style.blade.php';

    /** Bawat selector ng isang CSS text (walang comments); ang laman ng @media ay kasama, ang @media mismo ay hindi. */
    private function selectors(string $css): array
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        preg_match_all('/([^{}]+)\{/', $css, $m);
        $selectors = [];
        foreach ($m[1] as $prelude) {
            $prelude = trim($prelude);
            if (str_starts_with($prelude, '@')) continue;
            foreach (explode(',', $prelude) as $selector) $selectors[] = trim($selector);
        }

        return $selectors;
    }

    public function test_S_17_1_the_suppliers_styles_are_rendered_only_for_the_suppliers_view(): void
    {
        // Sa source: ang include ay nasa loob ng suppliers-only na block, kasunod agad ng unang </style>.
        $index = str_replace("\r\n", "\n", file_get_contents(resource_path('views/item/index.blade.php')));
        $block = "@if(!empty(\$layoutSuppliers))\n@include('item._suppliers_style')\n@endif\n";
        $this->assertSame(1, substr_count($index, '_suppliers_style'));
        $this->assertSame(strpos($index, '</style>') + strlen("</style>\n"), strpos($index, $block));

        // Sa render: ang suppliers view lang ang may pangalawang <style>, at doon ang mga rule nito.
        $suppliers = $this->render('ceo', true, true);
        $this->assertSame(2, substr_count($suppliers, '<style'));
        $second = substr($suppliers, strrpos($suppliers, '<style'));
        $this->assertStringContainsString('.spl-table', substr($second, 0, strpos($second, '</style>')));
        // Dumadaan sa Blade ang partial: dapat buo pa rin ang mga @media nito sa render.
        $this->assertStringContainsString('@media (min-width:768px) {', substr($second, 0, strpos($second, '</style>')));
        $this->assertStringContainsString('@media (hover:none), (max-width:767px) {', substr($second, 0, strpos($second, '</style>')));
        $this->assertStringNotContainsString('.spl-', substr($suppliers, 0, strrpos($suppliers, '<style')));

        $others = [
            'old.ceo' => ['ceo', true], 'default.ceo' => ['ceo', false],
            'old.marketing' => ['marketing', true], 'default.marketing' => ['marketing', false],
            'old.marketing_oic' => ['marketing_oic', true], 'default.marketing_oic' => ['marketing_oic', false],
            'old.ceo_as_marketing' => ['ceo_as_marketing', true], 'default.ceo_as_marketing' => ['ceo_as_marketing', false],
        ];
        foreach ($others as $label => [$viewer, $old]) {
            $html = $this->render($viewer, $old);
            $this->assertStringNotContainsString('.spl-', $html, $label);
            $this->assertSame(1, substr_count($html, '<style'), $label);
        }

        // Sa partial: iisang <style>, at bawat selector ay naka-scope sa suppliers view.
        $source = file_get_contents(resource_path(self::STYLE_FILE));
        $this->assertSame(1, substr_count($source, '<style'));
        $this->assertStringNotContainsString('x-html', $source);
        $selectors = $this->selectors(substr($this->between($source, '<style>', '</style>'), strlen('<style>')));
        $this->assertGreaterThan(40, count($selectors));
        foreach ($selectors as $selector) {
            $this->assertStringStartsWith('.spl-', $selector, $selector);
        }
    }

    public function test_S_17_2_rts_del_int_is_one_line_on_item_rows_only(): void
    {
        $table = $this->suppliersTable();
        $item = $this->itemRow();
        $pages = substr($table, strpos($table, 'page-col-header'));

        // Ang mga linyang nagpapakilala sa tatlong-linyang cell: ang label ng nested table (item row ng Old view)
        // at ang sariling value cell ng page row.
        $label = '<td style="padding:1px 6px;text-align:left;color:#94a3b8;font-size:9px;font-weight:700;letter-spacing:0.04em;">RTS</td>';
        $pageRowLine = "<td style=\"padding:1px 6px;text-align:right;border-left:1px solid #cbd5e1;\" :style=\"row.jnt_rts_pct===null?'color:#cbd5e1':'color:#111;font-weight:700'\"";
        $this->assertStringContainsString($label, file_get_contents(resource_path('views/item/_agg_cells.blade.php')));
        $this->assertStringContainsString($pageRowLine, file_get_contents(resource_path('views/item/_table_old.blade.php')));

        // Item row ng suppliers view: isang linya, tatlong value ayon sa RTS, DEL, INT, bawat isa ayon sa napiling member.
        $this->assertSame(1, substr_count($item, 'class="spl-rdt"'));
        $rdt = $this->between($item, '<div class="spl-rdt">', '</div>');
        $this->assertSame(3, substr_count($rdt, '<span'));
        $this->assertSame(3, substr_count($rdt, '<template x-if='));
        $this->assertOrder([
            "<template x-if=\"col.members && col.members.includes('jnt_rts')\">",
            ":title=\"A.jnt_rts_pct!=null ? 'RTS ' + A.jnt_rts_pct.toFixed(1) + '% (' + A.jnt_rts_cnt + ')' : 'RTS —'\"",
            "<template x-if=\"col.members && col.members.includes('jnt_del')\">",
            ":title=\"A.jnt_del_pct!=null ? 'DEL ' + A.jnt_del_pct.toFixed(1) + '% (' + A.jnt_del_cnt + ')' : 'DEL —'\"",
            "<template x-if=\"col.members && col.members.includes('jnt_transit')\">",
            ":title=\"A.jnt_transit_pct!=null ? 'INT ' + A.jnt_transit_pct.toFixed(1) + '% (' + A.jnt_transit_cnt + ')' : 'INT —'\"",
        ], $rdt);
        $this->assertStringContainsString("x-text=\"A.jnt_rts_pct!=null ? A.jnt_rts_pct.toFixed(1)+'%' : '—'\"", $rdt);
        $this->assertStringNotContainsString($label, $item);

        // Ang page rows ng suppliers view ay may sarili pa ring tatlong-linyang cell.
        $this->assertStringContainsString($pageRowLine, $pages);
        $this->assertStringContainsString($label, $pages);

        // Old view: ang item row ay may nested table pa rin, at walang isang-linyang cell.
        $old = $this->render('ceo', true);
        $this->assertStringNotContainsString('spl-rdt', $old);
        $this->assertStringContainsString($label, $this->between($old, 'class="item-row"', 'page-col-header'));
    }

    /**
     * Text pin (binabasa ang CSS at markup; walang browser dito): sa 768px pataas, walang side padding sa kaliwa ang
     * scroll area ng suppliers view, kaya ang sticky cell (left:0) ay nakadikit mismo sa gilid nito — walang puwang
     * sa kaliwa ng column na madadaanan ng mga column na nag-i-scroll. Ang takip ay ang cell mismo (opaque na kulay
     * sa bawat klase ng row), hindi anino sa labas ng cell.
     */
    public function test_S_17_6_the_sticky_item_column_sits_at_the_scroll_edge_and_is_opaque_on_every_row_kind(): void
    {
        $css = str_replace("\r\n", "\n", file_get_contents(resource_path(self::STYLE_FILE)));
        $wide = substr($css, (int) strrpos($css, '@media (min-width:768px) {'));
        $this->assertStringStartsWith('@media (min-width:768px) {', $wide);
        $narrow = substr($css, 0, (int) strrpos($css, '@media (min-width:768px) {'));

        // Ang scroll area ng view na ito lang ang may class; ang rule ay nasa loob ng 768px block lang.
        $this->assertSame(1, substr_count($this->tableSource(), '<div id="scroll" class="spl-scroll"'));
        $this->assertStringContainsString('.spl-scroll { padding-left:0 !important; }', $wide);
        $this->assertStringNotContainsString('spl-scroll', $narrow);
        $this->assertStringNotContainsString('position:sticky', $narrow);
        foreach (['item.index default' => $this->render('ceo', false), 'item.index old' => $this->render('ceo', true)] as $name => $html) {
            $this->assertStringNotContainsString('spl-scroll', $html, $name);
        }
        // Walang takip na anino sa labas ng cell: hindi iyon ang inaasahan.
        $this->assertStringNotContainsString('-16px', $css);

        // Bawat klase ng row: sticky sa left:0 at may sariling opaque na kulay.
        $rules = [
            'header corner (dalawang header row: rowspan 2)' => '.spl-table > thead > tr > th.spl-c1 { position:sticky; left:0; z-index:40; }',
            'page row'                => "position:sticky; left:0; z-index:6; background:#fff; box-shadow:1px 0 0 #c7d2fe;",
            'row hover'               => '.spl-table > tbody > tr:hover > td.spl-c1 { background:#f8fafc; }',
            'item row'                => '.spl-table > tbody > tr.item-row > td.spl-c1 { background:#eef2ff; }',
            'item row hover'          => '.spl-table > tbody > tr.item-row:hover > td.spl-c1 { background:#e0e7ff; }',
            'no running page'         => '.spl-table > tbody > tr.item-row.item-row-nopage > td.spl-c1 { background:#fff7ed; }',
            'no running page hover'   => '.spl-table > tbody > tr.item-row.item-row-nopage:hover > td.spl-c1 { background:#ffedd5; }',
            'expanded page row'       => 'background:#fff; box-shadow:inset 3px 0 0 #2563eb, 1px 0 0 #c7d2fe;',
            'editing row'             => '.spl-table > tbody > tr.editing-row > td.spl-c1 { background:#eff6ff; }',
            'repeated per-page header' => 'position:sticky; left:0; z-index:6; background:#334155;',
            'TOTAL'                   => '.spl-table > tbody > tr.total-row > td:first-child { left:0; z-index:25; background:#f1f5f9; box-shadow:1px 0 0 #cbd5e1; }',
        ];
        foreach ($rules as $kind => $rule) {
            $this->assertStringContainsString($rule, $wide, $kind);
        }
        $this->assertStringContainsString('.spl-table > tbody > tr > td.spl-c1 {', $wide);
        $this->assertStringContainsString('.spl-table > tbody.page-section-expanded > tr.page-row-expanded > td.spl-c1 {', $wide);
        $this->assertStringContainsString('.spl-table > tbody > tr.page-col-header > th:first-child {', $wide);

        // Ang kulay ng header corner at ang pagiging sticky ng TOTAL ay galing sa sariling CSS ng page.
        $page = $this->render('ceo', true, true);
        $this->assertStringContainsString("background:#1e293b; color:#94a3b8;", $this->between($page, 'thead th {', '}'));
        $this->assertStringContainsString('position:sticky; bottom:0; z-index:20;', $this->between($page, 'tr.total-row td {', '}'));

        // Bawat row na may cell sa unang column ay may class na iyon (ang buong-lapad na rows — mensahe at ang naka-expand
        // na block ng page — ay iisang cell na sakop ang lahat ng column at sumasabay sa scroll).
        $table = $this->tableSource();
        $this->assertSame(1, preg_match('/<th\s[^>]*spl-c1/', $this->between($table, '<thead>', '</thead>')));
        $this->assertSame(2, substr_count($table, '<td class="spl-c1">'));
    }
}
