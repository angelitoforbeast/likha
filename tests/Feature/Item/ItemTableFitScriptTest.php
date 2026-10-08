<?php

namespace Tests\Feature\Item;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Ang mga pure function ng public/js/item-table-fit.js, pinapatakbo sa node (tests/js/call.cjs).
 * Ang inaasahang value ng bawat case ay nakasulat dito mula sa kuwento nito, hindi kinukuwenta gaya ng script.
 * Kapag walang node sa makina, bawat test ay "skipped: node not found": hindi iyon pasado, hindi lang napatakbo.
 */
class ItemTableFitScriptTest extends TestCase
{
    private const SCRIPT = 'public/js/item-table-fit.js';
    private const RUNNER = 'tests/js/call.cjs';

    private static ?bool $node = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$node === null) {
            try {
                $probe = new Process(['node', '--version']);
                $probe->run();
                self::$node = $probe->isSuccessful();
            } catch (\Throwable $e) {
                self::$node = false;
            }
        }
        if (!self::$node) $this->markTestSkipped('skipped: node not found');
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Patakbuhin ang mga tawag sa iisang node process. Bawat tawag: [pangalan ng function, ...args].
     *
     * @return array<int, array{value: mixed, args: array}>
     */
    private function js(array $calls): array
    {
        $payload = array_map(fn (array $call) => ['fn' => $call[0], 'args' => array_slice($call, 1)], $calls);
        $process = new Process(['node', self::root() . '/' . self::RUNNER, self::root() . '/' . self::SCRIPT]);
        $process->setInput(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'node: ' . trim($process->getErrorOutput()));
        $out = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        foreach ($out as $i => $result) {
            $this->assertArrayNotHasKey('error', $result, $calls[$i][0] . ': ' . ($result['error'] ?? ''));
        }

        return $out;
    }

    private function call(string $fn, mixed ...$args): mixed
    {
        return $this->js([[$fn, ...$args]])[0]['value'];
    }

    /** Ang mga column ng isang listahan ng [id, pangalan]. */
    private function columns(array $suppliers): array
    {
        return $this->call('supplierColumns', array_map(fn (array $s) => ['id' => $s[0], 'name' => $s[1]], $suppliers));
    }

    private function quote(int|string $supplierId, ?float $price, ?int $moq = null, bool $cheapest = false, int $id = 1): array
    {
        return ['id' => $id, 'supplier_id' => $supplierId, 'supplier' => 'Zyxwv Kalakal', 'price' => $price, 'moq' => $moq, 'link' => null, 'cheapest' => $cheapest];
    }

    private function po(int|string $supplierId, float $cost, string $date = '2026-09-25', ?string $no = 'PO-0042'): array
    {
        return ['supplier' => 'Zyxwv Kalakal', 'supplier_id' => $supplierId, 'unit_cost' => $cost, 'order_date' => $date, 'order_no' => $no];
    }

    // ── S-34: isang column kada supplier ──────────────────────────────────────

    public function test_S_34_1_three_suppliers_give_three_numbered_columns_with_the_full_name(): void
    {
        $cols = $this->columns([[4, 'Kelly Trading'], [9, 'Albee Co'], [12, 'Helen Supply']]);

        $this->assertSame(['1 · Kelly', '2 · Albee', '3 · Helen'], array_column($cols, 'header'));
        $this->assertSame(['Kelly Trading', 'Albee Co', 'Helen Supply'], array_column($cols, 'full'));
        $this->assertSame([4, 9, 12], array_column($cols, 'id'));
        $this->assertSame(3, $this->call('groupSpan', count($cols)));
    }

    public function test_S_34_2_a_fourth_supplier_is_the_fourth_column(): void
    {
        $cols = $this->columns([[4, 'Kelly Trading'], [9, 'Albee Co'], [12, 'Helen Supply'], [13, 'Dante Importers']]);

        $this->assertCount(4, $cols);
        $this->assertSame(['pos' => 4, 'id' => 13, 'short' => 'Dante', 'full' => 'Dante Importers', 'header' => '4 · Dante'], $cols[3]);
        $this->assertSame(4, $this->call('groupSpan', count($cols)));
    }

    /** @return array<string, array{0: array}> */
    public static function idsAsNumbersAndStrings(): array
    {
        return [
            'numbers' => [[['id' => 7, 'name' => 'Zed'], ['id' => 3, 'name' => 'Amy'], ['id' => 12, 'name' => 'Kim']]],
            'strings' => [[['id' => '7', 'name' => 'Zed'], ['id' => '3', 'name' => 'Amy'], ['id' => '12', 'name' => 'Kim']]],
        ];
    }

    #[DataProvider('idsAsNumbersAndStrings')]
    public function test_S_34_3_columns_are_numbered_by_position_in_id_order(array $rows): void
    {
        $result = $this->js([['supplierColumns', $rows]])[0];

        $this->assertSame([3, 7, 12], array_column($result['value'], 'id'));
        $this->assertSame([1, 2, 3], array_column($result['value'], 'pos'));
        $this->assertSame(['1 · Amy', '2 · Zed', '3 · Kim'], array_column($result['value'], 'header'));
        // Kopya ang inaayos: ang listahang ibinigay (ang nasa form, alphabetical) ay hindi nagagalaw.
        $this->assertSame($rows, $result['args'][0]);
    }

    public function test_S_34_4_the_header_is_the_number_and_the_first_word_and_the_title_the_full_name(): void
    {
        $long = str_repeat('W', 120);
        $cols = $this->columns([
            [1, 'Kelly'], [2, '   Kelly Trading'], [3, ''], [4, 'Ñandú Trading 金龙'], [5, 'Kelly Export'],
            [6, '<img src=x onerror=alert(1)>'], [7, $long . ' Co'], [8, null],
        ]);

        $this->assertSame(
            ['1 · Kelly', '2 · Kelly', '3', '4 · Ñandú', '5 · Kelly', '6 · <img', '7 · ' . $long, '8'],
            array_column($cols, 'header')
        );
        $this->assertSame(
            ['Kelly', 'Kelly Trading', '', 'Ñandú Trading 金龙', 'Kelly Export', '<img src=x onerror=alert(1)>', $long . ' Co', ''],
            array_column($cols, 'full')
        );
    }

    public function test_S_34_5_no_supplier_gives_no_column_and_a_group_that_is_never_zero_wide(): void
    {
        $out = $this->js([
            ['supplierColumns', []], ['supplierColumns', null],
            ['groupSpan', 0], ['groupSpan', null], ['groupSpan', -3], ['groupSpan', 'x'], ['groupSpan', 1], ['groupSpan', 17],
        ]);

        $this->assertSame([[], [], 1, 1, 1, 1, 1, 17], array_column($out, 'value'));
    }

    public function test_S_34_6_a_quote_of_an_unknown_supplier_adds_no_column_and_no_warning(): void
    {
        $quotes = [$this->quote(99, 25.0, 100)];
        $out = $this->js([
            ['supplierColumns', [['id' => 4, 'name' => 'Kelly'], ['id' => 9, 'name' => 'Albee']]],
            ['supplierCell', $quotes, [], 4], ['supplierCell', $quotes, [], 9], ['noSupplier', $quotes, []],
        ]);

        $this->assertCount(2, $out[0]['value']);
        $this->assertSame(['empty', 'empty'], [$out[1]['value']['kind'], $out[2]['value']['kind']]);
        $this->assertFalse($out[3]['value']);
    }

    // ── S-35: ang laman ng isang cell ─────────────────────────────────────────

    public function test_S_35_1_a_cell_holds_only_that_suppliers_price_and_moq(): void
    {
        $quotes = [$this->quote(4, 18.5, 1000)];
        $out = array_column($this->js([['supplierCell', $quotes, [], 4], ['supplierCell', $quotes, [], 9], ['supplierCell', $quotes, [], 12]]), 'value');

        $this->assertSame(['quote', '₱18.50', 'MOQ 1000'], [$out[0]['kind'], $out[0]['priceText'], $out[0]['moqText']]);
        foreach ([$out[1], $out[2]] as $empty) {
            $this->assertSame(['empty', '', '', null], [$empty['kind'], $empty['priceText'], $empty['moqText'], $empty['quote']]);
        }
        // Ang ipinapakita ng cell ay presyo at MOQ lang: walang text nito ang may pangalan ng supplier.
        foreach ($out as $cell) {
            foreach (['priceText', 'moqText', 'poLine'] as $text) $this->assertStringNotContainsString('Zyxwv', $cell[$text]);
        }
    }

    public function test_S_35_2_the_plus_of_a_column_opens_the_form_on_that_supplier(): void
    {
        $this->assertSame(['id' => null, 'supplier_id' => '9', 'price' => '', 'moq' => '', 'link' => ''], $this->call('formPreset', 9, null));

        // Pagka-save ng 40 at MOQ 300 (string ang id sa sagot ng save): nasa column ng supplier 9 ang quote.
        $saved = [$this->quote('9', 40.0, 300)];
        $cells = array_column($this->js([['supplierCell', $saved, [], 9], ['supplierCell', $saved, [], 4]]), 'value');
        $this->assertSame(['quote', '₱40.00', 'MOQ 300'], [$cells[0]['kind'], $cells[0]['priceText'], $cells[0]['moqText']]);
        $this->assertSame('empty', $cells[1]['kind']);
    }

    public function test_S_35_4_the_forms_supplier_is_always_the_columns_supplier(): void
    {
        $own = ['id' => 7, 'supplier_id' => 4, 'price' => 18.5, 'moq' => 1000, 'link' => 'https://example.test/a'];
        $other = ['id' => 8, 'supplier_id' => 9, 'price' => 22, 'moq' => 50, 'link' => null];
        $out = array_column($this->js([['formPreset', 4, $own], ['formPreset', '4', $own], ['formPreset', 4, $other]]), 'value');

        $edit = ['id' => 7, 'supplier_id' => '4', 'price' => 18.5, 'moq' => 1000, 'link' => 'https://example.test/a'];
        $this->assertSame($edit, $out[0]);
        $this->assertSame($edit, $out[1]);
        // Quote ng ibang supplier: hindi ito kailanman dinadala sa form ng column na ito (kung hindi, mapapatungan ang quote ng column).
        $this->assertSame(['id' => null, 'supplier_id' => '4', 'price' => '', 'moq' => '', 'link' => ''], $out[2]);
    }

    public function test_S_35_5_a_missing_price_is_a_dash_a_zero_price_shows_and_a_missing_moq_is_left_out(): void
    {
        $quotes = [$this->quote(1, null, 500), $this->quote(2, 0.0, 10), $this->quote(3, 12.0, null)];
        $out = array_column($this->js([['supplierCell', $quotes, [], 1], ['supplierCell', $quotes, [], 2], ['supplierCell', $quotes, [], 3]]), 'value');

        $this->assertSame(['—', 'MOQ 500', null], [$out[0]['priceText'], $out[0]['moqText'], $out[0]['price']]);
        $this->assertSame(['₱0.00', 'MOQ 10'], [$out[1]['priceText'], $out[1]['moqText']]);
        $this->assertSame(['₱12.00', ''], [$out[2]['priceText'], $out[2]['moqText']]);
        $this->assertSame([false, false, false], array_column($out, 'cheapest'));
    }

    /** @return array<string, array{0: array, 1: array, 2: array<int, bool>}> quotes, PO rows, cheapest ng supplier 1, 2, 3 */
    public static function cheapestFlags(): array
    {
        $q = fn (int $sid, ?float $price, bool $flag) => ['id' => $sid, 'supplier_id' => $sid, 'price' => $price, 'moq' => null, 'cheapest' => $flag];
        $po = ['supplier' => 'B', 'supplier_id' => 2, 'unit_cost' => 15.0, 'order_date' => '2026-09-25', 'order_no' => null];

        return [
            '15, 15, 22: both 15s'            => [[$q(1, 15.0, true), $q(2, 15.0, true), $q(3, 22.0, false)], [], [true, true, false]],
            'one price only'                  => [[$q(1, 15.0, false)], [], [false, false, false]],
            '0 and no price'                  => [[$q(1, 0.0, false), $q(2, null, false)], [], [false, false, false]],
            'a PO cost never counts'          => [[$q(1, 20.0, false)], [$po], [false, false, false]],
            // Ang marka ng server ang sinusunod, hindi ang paghahambing ng presyo: sadyang baligtad dito.
            'the flag decides, not the price' => [[$q(1, 10.0, false), $q(2, 50.0, true)], [], [false, true, false]],
        ];
    }

    #[DataProvider('cheapestFlags')]
    public function test_S_35_6_cheapest_is_the_servers_flag(array $quotes, array $po, array $expected): void
    {
        $out = $this->js([['supplierCell', $quotes, $po, 1], ['supplierCell', $quotes, $po, 2], ['supplierCell', $quotes, $po, 3]]);

        $this->assertSame($expected, array_column(array_column($out, 'value'), 'cheapest'));
    }

    // ── S-36: ang huling PO ng supplier ───────────────────────────────────────

    public function test_S_36_1_a_quote_with_a_po_keeps_the_quote_price_and_gets_the_dot_and_the_po_line(): void
    {
        $cell = $this->call('supplierCell', [$this->quote(4, 18.5, 1000)], [$this->po(4, 17.8)], 4);

        $this->assertSame(['quote', '₱18.50', true, false], [$cell['kind'], $cell['priceText'], $cell['poDot'], $cell['poTag']]);
        $this->assertSame('Last PO ₱17.80, 2026-09-25, PO-0042', $cell['poLine']);
    }

    public function test_S_36_2_a_po_without_a_quote_shows_the_po_cost_with_a_tag_and_is_never_cheapest(): void
    {
        $out = array_column($this->js([
            ['supplierCell', [], [$this->po(4, 17.8)], 4],
            ['supplierCell', [], [$this->po(4, 1234.5, '2026-09-25', null)], 4],
        ]), 'value');

        $this->assertSame(['po', '₱17.80', true, false, false, null], [$out[0]['kind'], $out[0]['priceText'], $out[0]['poTag'], $out[0]['poDot'], $out[0]['cheapest'], $out[0]['quote']]);
        $this->assertSame('Last PO ₱17.80, 2026-09-25, PO-0042', $out[0]['poLine']);
        // Walang PO number: wala ring nakabiting kuwit.
        $this->assertSame(['₱1,234.50', 'Last PO ₱1,234.50, 2026-09-25'], [$out[1]['priceText'], $out[1]['poLine']]);
    }

    public function test_S_36_3_no_po_or_a_zero_cost_line_gives_no_dot_and_no_tag(): void
    {
        $quotes = [$this->quote(4, 18.5, 1000)];
        $out = array_column($this->js([
            ['supplierCell', $quotes, [], 4],
            ['supplierCell', $quotes, [$this->po(4, 0.0)], 4],
            ['supplierCell', [], [$this->po(4, 0.0), $this->po(4, -25.0)], 4],
        ]), 'value');

        $this->assertSame(['quote', 'quote', 'empty'], array_column($out, 'kind'));
        foreach ($out as $cell) $this->assertSame([false, false, ''], [$cell['poDot'], $cell['poTag'], $cell['poLine']]);
    }

    public function test_S_36_4_the_po_is_matched_to_its_column_by_supplier_id_not_by_name(): void
    {
        // Magkapareho ang pangalan ng supplier 4 at 9; ang PO ay sa 9 (number sa isang takbo, string sa kasunod).
        foreach ([9, '9'] as $id) {
            $out = array_column($this->js([['supplierCell', [], [$this->po($id, 17.8)], 4], ['supplierCell', [], [$this->po($id, 17.8)], 9]]), 'value');
            $this->assertSame(['empty', 'po'], array_column($out, 'kind'));
        }
    }

    // ── Ang fit (S-38 – S-41) ─────────────────────────────────────────────────

    /**
     * Ang mga column ng may-ari ngayon, ayon sa naka-save niyang ayos (wala na ang 12 nakatago sa settings at ang
     * apat na pilit na nakatago; iisa na ang RTS / DEL / INT at ang Prof.%): 23 lahat.
     */
    private const OWNER_COLS = [
        'promo', 'price', 'np_per_order_1m', 'adspent', 'orders_1d', 'proj_prof_1d', 'item_val', 'item_val_ceo', 'cpp',
        'rts_set', 'jnt_rdt', 'tcpr', 'breakeven_cpp', 'proj_profit', 'prof_pct', 'hold', 'action', 'stock', 'incoming',
        'units_per_day', 'doi', 'order_qty', 'lifecycle',
    ];

    /** Ang mga umaalis sa Sourcing set, ayon sa ayos ng pag-alis sa spec (mula RTS / DEL / INT hanggang PROF.%). */
    private const SOURCING_LEAVES = [
        'jnt_rdt', 'tcpr', 'hold', 'cpp', 'orders_1d', 'np_per_order_1m', 'adspent', 'proj_prof_1d', 'units_per_day',
        'incoming', 'stock', 'lifecycle', 'order_qty', 'doi', 'item_val_ceo', 'proj_profit', 'prof_pct',
    ];

    /** Ang estado ng page para sa layout(): [box, window, suppliers, set, mga id na nakatago sa settings]. */
    private function state(int $box, int $win, int $suppliers, string $set = 'Sourcing', array $hidden = [], array $extra = []): array
    {
        $cols = array_map(fn (string $id) => ['id' => $id], array_values(array_diff(self::OWNER_COLS, $hidden)));

        return $extra + ['box' => $box, 'win' => $win, 'suppliers' => $suppliers, 'set' => $set, 'cols' => $cols, 'ops' => []];
    }

    /**
     * Isang row ng fit table ng spec: [box, window, suppliers, set, nakatago sa settings, ilan ang nakikita,
     * ang mga inalis ng fit (sunod-sunod), sobrang lapad (null = n/a), "+N", nag-i-scroll ba].
     */
    private function assertFitRow(array $row): array
    {
        [$box, $win, $suppliers, $set, $hidden, $shown, $away, $spare, $plusN, $scrolls] = $row;
        $r = $this->call('layout', $this->state($box, $win, $suppliers, $set, $hidden));

        $this->assertCount($shown, $r['shown'], 'shown');
        $this->assertSame($away, $r['fitAway'], 'moved away by the fit, in order');
        $this->assertSame($spare, $r['spare'], 'spare');
        $this->assertSame($plusN, $r['plusN'], '+N');
        $this->assertSame($scrolls, $r['scrolls'], 'scrolls');
        if ($spare !== null) $this->assertGreaterThanOrEqual(0, $r['spare']);
        // Ang mga nakikita ay laging nasa naka-save na ayos.
        $this->assertSame(array_values(array_intersect(self::OWNER_COLS, $r['shown'])), $r['shown']);

        return $r;
    }

    /** @return array<string, array{0: array}> */
    public static function fitRowsF1toF4(): array
    {
        $l = self::SOURCING_LEAVES;

        return [
            'F1 1366' => [[1319, 1366, 3, 'Sourcing', [], 11, array_slice($l, 0, 6), 19, 12, false]],
            'F2 1440' => [[1393, 1440, 3, 'Sourcing', [], 12, array_slice($l, 0, 5), 29, 11, false]],
            'F3 1536' => [[1489, 1536, 3, 'Sourcing', [], 14, array_slice($l, 0, 3), 13, 9, false]],
            'F4 1920' => [[1873, 1920, 3, 'Sourcing', [], 17, [], 193, 6, false]],
        ];
    }

    #[DataProvider('fitRowsF1toF4')]
    public function test_S_38_1_three_suppliers_and_the_sourcing_set_fit_at_four_widths(array $row): void
    {
        $this->assertFitRow($row);
    }

    /** @return array<string, array{0: array, 1: ?array}> */
    public static function fitRowsF5toF7(): array
    {
        $l = self::SOURCING_LEAVES;

        return [
            'F5 4 suppliers' => [[1319, 1366, 4, 'Sourcing', [], 10, array_slice($l, 0, 7), 23, 13, false], null],
            'F6 6 suppliers' => [[1319, 1366, 6, 'Sourcing', [], 8, array_slice($l, 0, 9), 15, 15, false], null],
            'F7 8 suppliers' => [[1319, 1366, 8, 'Sourcing', [], 5, array_slice($l, 0, 12), 97, 18, false], ['item_val_ceo', 'proj_profit', 'prof_pct', 'doi', 'order_qty']],
        ];
    }

    #[DataProvider('fitRowsF5toF7')]
    public function test_S_38_2_columns_leave_one_by_one_and_the_core_is_last(array $row, ?array $shown): void
    {
        $r = $this->assertFitRow($row);
        if ($shown !== null) $this->assertSame($shown, $r['shown']);
    }

    public function test_S_38_3_an_exact_fit_fits_and_one_pixel_less_drops_one_more(): void
    {
        $l = self::SOURCING_LEAVES;
        // F8: 480 px ang kailangan ng anim, 480 ang natitira.
        $f8 = $this->assertFitRow([1320, 1367, 8, 'Sourcing', [], 6, array_slice($l, 0, 11), 0, 17, false]);
        $this->assertSame(['item_val_ceo', 'proj_profit', 'prof_pct', 'doi', 'order_qty', 'lifecycle'], $f8['shown']);
        $this->assertSame(1320, $f8['used']);
        // F7: isang pixel na mas makitid, umalis pa ang LIFECYCLE.
        $f7 = $this->assertFitRow([1319, 1366, 8, 'Sourcing', [], 5, array_slice($l, 0, 12), 97, 18, false]);
        $this->assertNotContains('lifecycle', $f7['shown']);
    }

    /** @return array<string, array{0: array, 1: ?array}> */
    public static function fitRowsF9toF11(): array
    {
        return [
            'F9 ADSPENT hidden by the server' => [[1319, 1366, 3, 'Sourcing', ['adspent'], 11, ['jnt_rdt', 'tcpr', 'hold', 'cpp', 'orders_1d'], 31, 11, false], null],
            'F10 Sales 1366' => [[1319, 1366, 3, 'Sales', [], 12, ['promo', 'rts_set', 'breakeven_cpp', 'action', 'item_val'], 7, 11, false],
                ['price', 'np_per_order_1m', 'adspent', 'orders_1d', 'proj_prof_1d', 'item_val_ceo', 'cpp', 'jnt_rdt', 'tcpr', 'proj_profit', 'prof_pct', 'hold']],
            'F11 Sales 1920' => [[1873, 1920, 3, 'Sales', [], 17, [], 187, 6, false], null],
        ];
    }

    #[DataProvider('fitRowsF9toF11')]
    public function test_S_38_4_a_server_hidden_column_is_in_no_set_and_sales_excludes_the_stock_columns(array $row, ?array $shown): void
    {
        $r = $this->assertFitRow($row);
        if ($shown !== null) $this->assertSame($shown, $r['shown']);
        // Ang nakatago sa settings ay wala sa nakikita at wala rin sa bilang ng "+N".
        foreach ($row[4] as $hidden) {
            $this->assertNotContains($hidden, array_merge($r['shown'], $r['away']));
        }
        if ($row[3] === 'Sales') {
            $this->assertSame([], array_intersect(['stock', 'incoming', 'units_per_day', 'doi', 'order_qty', 'lifecycle'], $r['shown']));
        }
    }

    public function test_S_38_5_below_1280_everything_shows_and_scrolls_and_from_1280_the_fit_runs(): void
    {
        // F12: 1279 — scroll mode; lahat ng 17 ng set, walang inalis, at mas malapad ang table kaysa sa kahon.
        $f12 = $this->assertFitRow([1232, 1279, 3, 'Sourcing', [], 17, [], null, 6, true]);
        $this->assertSame('scroll', $f12['mode']);
        // F13: 1280 — tumatakbo ang fit at walang nag-i-scroll.
        $f13 = $this->assertFitRow([1233, 1280, 3, 'Sourcing', [], 10, array_slice(self::SOURCING_LEAVES, 0, 7), 9, 13, false]);
        $this->assertSame('fit', $f13['mode']);
    }

    public function test_S_38_6_identity_and_supplier_columns_never_leave(): void
    {
        // F14: 17 supplier — lampas na sa kahon ang identity at ang mga supplier: walang ibang column, nag-i-scroll.
        $f14 = $this->assertFitRow([1319, 1366, 17, 'Sourcing', [], 0, self::SOURCING_LEAVES, null, 23, true]);
        $this->assertSame('fit', $f14['mode']);
        $this->assertSame(264 + 17 * 72, $f14['used']);
        // F15: walang supplier — gumagana pa rin ang fit.
        $this->assertFitRow([1319, 1366, 0, 'Sourcing', [], 15, ['jnt_rdt', 'tcpr'], 11, 8, false]);

        // Parehong input, parehong sagot, anuman ang ayos ng pagkakasulat ng mga column at ng listahan ng pag-alis
        // (bilang puwesto ang listahan: ang parehong mga puwesto na ibinigay nang baligtad ang pagkakasulat).
        $cols = array_map(fn (string $id) => ['id' => $id], array_values(array_diff(self::OWNER_COLS, ['promo', 'price', 'rts_set', 'breakeven_cpp', 'action', 'item_val'])));
        $input = ['box' => 1319, 'win' => 1366, 'suppliers' => 3, 'cols' => $cols];
        $reversed = ['cols' => array_reverse($cols)] + $input;
        [$a, $b] = array_column($this->js([['fit', $input], ['fit', $reversed]]), 'value');
        $this->assertSame(array_slice(self::SOURCING_LEAVES, 0, 6), $a['away']);
        $this->assertSame($a['away'], $b['away']);
        $this->assertSame(19, $b['spare']);
        $this->assertSame(array_reverse($a['shown']), $b['shown']);
    }

    public function test_S_38_10_this_tables_money_format(): void
    {
        $out = array_column($this->js([
            ['tableMoney', 99999.99, false], ['tableMoney', 100000, false], ['tableMoney', 2270000, true], ['tableMoney', -1200, false],
            ['tableMoney', 2270000, false], ['tableMoney', 99999.99, true], ['tableMoney', 1000000, true], ['tableMoney', -2270000, true],
            ['tableMoney', null, false], ['tableMoney', 18.5, false], ['tableMoney', -0.001, false],
        ]), 'value');

        $this->assertSame(['text' => '₱99,999.99', 'title' => '₱99,999.99'], $out[0]);
        $this->assertSame('₱100,000', $out[1]['text']);
        $this->assertSame(['text' => '₱2.27M', 'title' => '₱2,270,000.00'], $out[2]);
        $this->assertSame('−₱1,200.00', $out[3]['text']);
        // Sa labas ng TOTAL walang "M"; sa TOTAL, sa ilalim ng isang milyon walang "M".
        $this->assertSame(['₱2,270,000', '₱99,999.99', '₱1M', '−₱2.27M', '₱0.00', '₱18.50', '₱0.00'], array_column(array_slice($out, 4), 'text'));
    }

    // ── S-39: ang panel ng "+N columns" ───────────────────────────────────────

    public function test_S_39_1_the_count_is_the_columns_away_by_the_fit_plus_the_ones_the_set_excludes(): void
    {
        $r = $this->call('layout', $this->state(1319, 1366, 3));

        $this->assertSame(12, $r['plusN']);
        // Ang anim na inalis ng fit at ang anim na hindi inaalok ng set, ayon sa naka-save na ayos.
        $this->assertSame(['promo', 'price', 'np_per_order_1m', 'orders_1d', 'item_val', 'cpp', 'rts_set', 'jnt_rdt', 'tcpr', 'breakeven_cpp', 'hold', 'action'], $r['away']);
        $this->assertSame([1300, 19, 1319], [$r['used'], $r['spare'], $r['box']]);
        // Bawat isa sa listahan ay may lapad na kailangan nito.
        $this->assertSame([72, 104, 56], array_column($this->js([['minWidthOf', 'promo'], ['minWidthOf', 'jnt_rdt'], ['minWidthOf', 'orders_1d']]), 'value'));
        // Nakatago sa settings: hindi kasama sa bilang.
        $this->assertSame(11, $this->call('layout', $this->state(1319, 1366, 3, 'Sourcing', ['promo']))['plusN']);
    }

    public function test_S_39_2_a_column_that_fits_the_spare_width_is_turned_on_in_its_place(): void
    {
        // F4: 193 px ang sobra; ang PROMO (72 px, wala sa set) ay ibinabalik.
        $r = $this->call('layout', $this->state(1873, 1920, 3, 'Sourcing', [], ['ops' => [['id' => 'promo', 'on' => true]]]));

        $this->assertSame([], $r['refused']);
        $this->assertSame('promo', $r['shown'][0]);
        $this->assertCount(18, $r['shown']);
        $this->assertSame([5, 121], [$r['plusN'], $r['spare']]);
    }

    public function test_S_39_3_a_column_wider_than_the_spare_width_is_refused_with_both_numbers(): void
    {
        $state = $this->state(1319, 1366, 3, 'Sourcing', [], ['ops' => [['id' => 'orders_1d', 'on' => true]]]);
        $r = $this->call('layout', $state);
        $base = $this->call('layout', $this->state(1319, 1366, 3));

        $this->assertSame([['id' => 'orders_1d', 'reason' => 'needs 56 px, 19 px free']], $r['refused']);
        // Walang ibang gumalaw.
        foreach (['shown', 'away', 'plusN', 'spare', 'used'] as $key) $this->assertSame($base[$key], $r[$key], $key);
    }

    public function test_S_39_4_turning_a_shown_column_off_frees_its_width_for_the_refused_one(): void
    {
        // ADSPENT (76 px) off: 19 + 76 = 95 px ang sobra, kaya tanggap na ang ORDERS (1D) na 56 px.
        $r = $this->call('layout', $this->state(1319, 1366, 3, 'Sourcing', [], ['ops' => [['id' => 'adspent', 'on' => false], ['id' => 'orders_1d', 'on' => true]]]));

        $this->assertSame([], $r['refused']);
        $this->assertContains('orders_1d', $r['shown']);
        $this->assertNotContains('adspent', $r['shown']);
        $this->assertSame([39, 12], [$r['spare'], $r['plusN']]);

        // Ang parehong hakbang sa dalawang function: turnOff tapos turnOn.
        $cols = array_map(fn (string $id) => ['id' => $id], self::OWNER_COLS);
        $sourcing = array_values(array_filter($cols, fn (array $c) => !in_array($c['id'], ['promo', 'price', 'rts_set', 'breakeven_cpp', 'action', 'item_val'], true)));
        $input = ['box' => 1319, 'win' => 1366, 'suppliers' => 3, 'cols' => $sourcing, 'all' => $cols];
        $fit = $this->call('fit', $input);
        $refused = $this->call('turnOn', $input, $fit, 'orders_1d');
        $this->assertSame([false, 'needs 56 px, 19 px free'], [$refused['accepted'], $refused['reason']]);
        $off = $this->call('turnOff', $input, $fit, 'adspent');
        $this->assertSame(95, $off['spare']);
        $on = $this->call('turnOn', $input, $off, 'orders_1d');
        $this->assertSame([true, 39], [$on['accepted'], $on['result']['spare']]);
        // Sa sariling puwesto nito sa naka-save na ayos: bago ang PROF.PROFIT(1D).
        $this->assertSame('proj_prof_1d', $on['result']['shown'][array_search('orders_1d', $on['result']['shown'], true) + 1]);
    }

    // ── S-40: ang dalawang set ────────────────────────────────────────────────

    public function test_S_40_1_the_set_opens_on_sourcing_and_a_stored_sales_is_read_back(): void
    {
        $out = array_column($this->js([['readSet', null], ['readSet', 'Sales'], ['readSet', 'Sourcing'], ['setNames']]), 'value');

        $this->assertSame(['Sourcing', 'Sales', 'Sourcing', ['Sourcing', 'Sales']], $out);
    }

    public function test_S_40_2_a_junk_stored_set_opens_on_sourcing(): void
    {
        $junk = ['Mine', '', '<img src=x onerror=alert(1)>', 'sales', 'SALES', ' Sales', 0, 1, true, ['Sales'], ['a' => 'Sales'], 'constructor', '__proto__', 'toString'];
        $out = array_column($this->js(array_map(fn ($v) => ['readSet', $v], $junk)), 'value');

        $this->assertSame(array_fill(0, count($junk), 'Sourcing'), $out);
        // Kahit ang layout na binigyan ng basurang set ay Sourcing ang ginagamit.
        $this->assertSame(['Sourcing', 12], array_values(array_intersect_key($this->call('layout', $this->state(1319, 1366, 3, 'Mine')), ['set' => 1, 'plusN' => 1])));
    }

    public function test_S_40_4_a_set_only_chooses_the_columns_and_they_show_in_the_saved_order(): void
    {
        // Ibang naka-save na ayos: ang stock block muna, tapos ang iba nang pabaligtad.
        $order = array_merge(['lifecycle', 'order_qty', 'doi', 'units_per_day', 'incoming', 'stock'], array_reverse(array_slice(self::OWNER_COLS, 0, 17)));
        $state = ['cols' => array_map(fn (string $id) => ['id' => $id], $order)] + $this->state(1873, 1920, 3);
        $sourcing = $this->call('layout', $state);
        $sales = $this->call('layout', ['set' => 'Sales'] + $state);

        $this->assertSame(array_values(array_diff($order, ['promo', 'price', 'rts_set', 'breakeven_cpp', 'action', 'item_val'])), $sourcing['shown']);
        $this->assertSame(array_values(array_diff($order, ['stock', 'incoming', 'units_per_day', 'doi', 'order_qty', 'lifecycle'])), $sales['shown']);
    }

    public function test_S_40_5_the_order_saved_after_a_drag_keeps_every_catalog_id_in_place(): void
    {
        $catalog = [
            'adspent', 'orders', 'orders_1d', 'cpp', 'proceed', 'pcpp', 'tcpr', 'breakeven_cpp', 'proj_profit', 'per_order',
            'np_per_order', 'np_per_order_3d', 'np_per_order_7d', 'np_per_order_1m', 'proj_pct', 'proj_pct_1d', 'proj_pct_3d',
            'proj_pct_7d', 'proj_prof_1d', 'proj_prof_3d', 'proj_prof_7d', 'jnt_rts', 'jnt_del', 'jnt_transit', 'rts_set', 'promo',
            'price', 'item_val', 'item_val_ceo', 'ship', 'cod_fee', 'hold', 'action', 'claude_action', 'claude_reason',
            'ceo_action', 'ceo_reason', 'category', 'stock', 'incoming', 'units_per_day', 'doi', 'order_qty', 'lifecycle',
        ];
        // Nakikita: ADSPENT, ang Prof.% (apat na id), DOI, at ang RTS / DEL / INT (tatlong id). Hinila ang DOI sa unahan.
        // Ang pinagsamang column ay ibinibigay bilang listahan ng mga id nito (dito, ayon sa ayos ng switch: 1M, 7D, 3D, 1D).
        $dragged = ['doi', 'adspent', ['proj_pct', 'proj_pct_7d', 'proj_pct_3d', 'proj_pct_1d'], ['jnt_rts', 'jnt_del', 'jnt_transit']];
        // Sa loob ng pinagsamang column, nananatili ang dati nilang pagkakasunod sa naka-save na ayos: hindi ito
        // ginagalaw ng drag, dahil magkakahiwalay na column ang mga ito sa ibang page na bumabasa ng parehong ayos.
        $after = ['doi', 'adspent', 'proj_pct', 'proj_pct_1d', 'proj_pct_3d', 'proj_pct_7d', 'jnt_rts', 'jnt_del', 'jnt_transit'];
        $saved = $this->call('orderToSave', $catalog, $dragged, $catalog);

        // Walang nawala at walang nadoble.
        $this->assertCount(count($catalog), $saved);
        $this->assertEqualsCanonicalizing($catalog, $saved);
        // Ang mga wala sa table ay nasa dati nilang puwesto.
        $shown = array_flip($after);
        foreach ($catalog as $i => $id) {
            if (!isset($shown[$id])) $this->assertSame($id, $saved[$i], $id);
        }
        // Ang mga nakikita ay nasa bagong pagkakasunod, sa mga puwestong hawak nila dati.
        $this->assertSame($after, array_values(array_filter($saved, fn (string $id) => isset($shown[$id]))));
        $this->assertSame('doi', $saved[0]);
        $this->assertSame('orders', $saved[1]);

        // Kulang ang naka-save na ayos (lumang setting) at may id na hindi kilala sa hinila: kumpleto pa rin ang catalog, walang dagdag.
        $partial = $this->call('orderToSave', ['cpp', 'adspent', 'cpp'], ['adspent', 'cpp', 'not_a_column', 7, null], $catalog);
        $this->assertCount(count($catalog), $partial);
        $this->assertEqualsCanonicalizing($catalog, $partial);
        $this->assertSame(['adspent', 'cpp'], array_slice($partial, 0, 2));
        // Ang naka-save na ayos na galing sa browser ay puwedeng may basura: hindi ito isinasama sa ise-save.
        $dirty = $this->call('orderToSave', ['<img src=x onerror=alert(1)>', 'cpp', '__proto__', 'adspent', 'constructor', 'not_a_column'], ['adspent', 'cpp'], $catalog);
        $this->assertCount(count($catalog), $dirty);
        $this->assertEqualsCanonicalizing($catalog, $dirty);
        $this->assertSame(['adspent', 'cpp'], array_slice($dirty, 0, 2));
    }

    // ── S-41: ang Prof.% bilang isang column ──────────────────────────────────

    public function test_S_41_1_one_prof_pct_column_opens_on_1m_and_shows_that_periods_value(): void
    {
        $cols = array_map(fn (string $id) => ['id' => $id, 'align' => 'center'], ['adspent', 'proj_pct', 'proj_pct_1d', 'proj_pct_3d', 'proj_pct_7d', 'hold']);
        $agg = ['proj_pct' => 12.345, 'proj_pct_7d' => -3.21, 'proj_pct_3d' => null, 'proj_pct_1d' => 0];
        $out = array_column($this->js([
            ['mergeProfPct', $cols], ['pickPeriod', ['proj_pct', 'proj_pct_1d', 'proj_pct_3d', 'proj_pct_7d'], null],
            ['profPct', $agg, '1m'], ['profPct', $agg, '7d'], ['profPct', $agg, '3d'], ['profPct', $agg, '1d'],
        ]), 'value');

        $this->assertSame(['adspent', 'prof_pct', 'hold'], array_column($out[0], 'id'));
        $this->assertSame(['proj_pct', 'proj_pct_7d', 'proj_pct_3d', 'proj_pct_1d'], $out[0][1]['members']);
        $this->assertSame('1m', $out[1]);
        $this->assertSame(['12.3%', '-3.2%', '—', '0.0%'], array_column(array_slice($out, 2), 'text'));
        $this->assertNull($out[4]['value']);
    }

    public function test_S_41_2_another_period_changes_the_value_and_the_sort_field(): void
    {
        $agg = ['proj_pct' => 12.3, 'proj_pct_7d' => 8.0, 'proj_pct_3d' => 5.5, 'proj_pct_1d' => 1.0];
        $page = ['projected_profit' => 50.0, 'gross_sales' => 400.0, 'proj_pct_last_7d' => 9.0, 'proj_pct_last_3d' => null, 'proj_pct_last_day' => 2.5];
        $out = array_column($this->js([
            ['profPct', $agg, '1m'], ['profPct', $agg, '7d'], ['profPct', $agg, '3d'], ['profPct', $agg, '1d'],
            ['profPct', $page, '1m', 'page'], ['profPct', $page, '7d', 'page'], ['profPct', $page, '3d', 'page'], ['profPct', $page, '1d', 'page'],
            ['profPct', ['projected_profit' => null, 'gross_sales' => 400.0], '1m', 'page'], ['profPct', $agg, 'x'],
        ]), 'value');

        // Ang apat na dati nang field ng sort.
        $this->assertSame(['proj_pct_computed', 'proj_pct_last_7d', 'proj_pct_last_3d', 'proj_pct_last_day'], array_column(array_slice($out, 0, 4), 'sortKey'));
        $this->assertSame(['12.3%', '8.0%', '5.5%', '1.0%'], array_column(array_slice($out, 0, 4), 'text'));
        $this->assertSame(['12.5%', '9.0%', '—', '2.5%', '—', '—'], array_column(array_slice($out, 4), 'text'));
        // Ang catalog id ng bawat period: sa id na ito nakatali ang mga panuntunan ng kulay ng column settings.
        $this->assertSame(
            ['proj_pct', 'proj_pct_7d', 'proj_pct_3d', 'proj_pct_1d', null],
            array_column($this->js([['periodId', '1m'], ['periodId', '7d'], ['periodId', '3d'], ['periodId', '1d'], ['periodId', 'x']]), 'value')
        );
    }

    public function test_S_41_3_the_switch_offers_only_the_visible_periods_and_four_ids_stay_four(): void
    {
        $col = fn (string $id) => ['id' => $id];
        $out = array_column($this->js([
            ['mergeProfPct', [$col('proj_pct_7d'), $col('adspent'), $col('proj_pct')]],
            ['periodsOf', ['proj_pct', 'proj_pct_7d']], ['pickPeriod', ['proj_pct_7d', 'proj_pct_1d'], '1m'], ['pickPeriod', ['proj_pct_7d', 'proj_pct_1d'], '1d'],
            ['mergeProfPct', [$col('adspent'), $col('hold')]], ['pickPeriod', [], '1m'],
        ]), 'value');

        // Dalawa lang ang hindi nakatago: iyon lang ang inaalok, sa puwesto ng una sa kanila.
        $this->assertSame(['prof_pct', 'adspent'], array_column($out[0], 'id'));
        $this->assertSame(['proj_pct', 'proj_pct_7d'], $out[0][0]['members']);
        $this->assertSame(['1M', '7D'], array_column($out[1], 'label'));
        $this->assertSame(['7d', '1d'], [$out[2], $out[3]]);
        // Nakatago ang apat: walang column.
        $this->assertSame(['adspent', 'hold'], array_column($out[4], 'id'));
        $this->assertNull($out[5]);

        // Ang ayos na ise-save ay apat pa ring id ang Prof.% (hindi ang pinagsamang column).
        $saved = $this->call('orderToSave', ['adspent', 'proj_pct', 'proj_pct_1d', 'proj_pct_3d', 'proj_pct_7d', 'hold'], ['hold', ['proj_pct', 'proj_pct_7d', 'proj_pct_3d', 'proj_pct_1d'], 'adspent'], []);
        $this->assertSame(['hold', 'proj_pct', 'proj_pct_1d', 'proj_pct_3d', 'proj_pct_7d', 'adspent'], $saved);
        // Ang header: puwedeng maputol ang mahabang label pagkatapos ng tuldok o slash at bago ang panaklong.
        $this->assertSame(["Prof.​Profit​(1D)", "Benta/​araw", 'DOI'], array_column($this->js([['headLabel', 'Prof.Profit(1D)'], ['headLabel', 'Benta/araw'], ['headLabel', 'DOI']]), 'value'));
    }

    // ── Ang lapad ng bawat column ─────────────────────────────────────────────

    public function test_S_38_9_the_widths_add_up_to_the_box_when_it_fits_and_to_the_minimums_when_it_scrolls(): void
    {
        $rows = ['F1' => [1319, 1366, 3], 'F3' => [1489, 1536, 3], 'F4' => [1873, 1920, 3], 'F8' => [1320, 1367, 8], 'F15' => [1319, 1366, 0]];
        foreach ($rows as $name => [$box, $win, $n]) {
            $r = $this->call('layout', $this->state($box, $win, $n));
            $w = $this->call('widths', $r, ['suppliers' => $n]);
            // Eksaktong lapad ng kahon: walang sobra, walang kulang.
            $this->assertSame($box, $w['table'], $name);
            $this->assertSame($box, $w['page'] + $w['item'] + $n * $w['supplier'] + array_sum($w['cols']), $name);
            $this->assertSame($r['shown'], array_keys($w['cols']), $name);
            $this->assertGreaterThanOrEqual(168, $w['page'], $name);
            $this->assertLessThanOrEqual(196, $w['page'], $name);
        }
        // F1: 19 px ang sobra, lahat sa PAGE. F4: 193 = 28 (PAGE) + 12 (ITEM) + 3 × 4 (supplier) + 141 sa 17 column.
        $f1 = $this->call('widths', $this->call('layout', $this->state(1319, 1366, 3)), ['suppliers' => 3]);
        $this->assertSame([187, 96, 72], [$f1['page'], $f1['item'], $f1['supplier']]);
        $f4 = $this->call('widths', $this->call('layout', $this->state(1873, 1920, 3)), ['suppliers' => 3]);
        $this->assertSame([196, 108, 76, 64 + 9, 98 + 8], [$f4['page'], $f4['item'], $f4['supplier'], $f4['cols']['np_per_order_1m'], $f4['cols']['lifecycle']]);

        // Nag-i-scroll (F12, F14): ang pinakamaliit na lapad ng bawat isa.
        $f12 = $this->call('widths', $this->call('layout', $this->state(1232, 1279, 3)), ['suppliers' => 3]);
        $this->assertSame([168, 96, 72, 264 + 216 + 1200], [$f12['page'], $f12['item'], $f12['supplier'], $f12['table']]);
        $f14 = $this->call('widths', $this->call('layout', $this->state(1319, 1366, 17)), ['suppliers' => 17]);
        $this->assertSame([264 + 17 * 72, []], [$f14['table'], $f14['cols']]);
    }

    public function test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order(): void
    {
        // Ang catalog ng server (ang parehong listahan na binabasa ng column settings page).
        $source = file_get_contents(self::root() . '/app/Http/Controllers/OwnerColumnSettingsController.php');
        $this->assertSame(1, preg_match("/'owner_private'\s*=>\s*\[(.*?)\n        \],/s", $source, $block));
        preg_match_all("/'id'\s*=>\s*'([a-z0-9_]+)'/", $block[1], $m);
        $ids = $m[1];
        $this->assertCount(44, $ids);

        $calls = [];
        foreach ($ids as $id) {
            $calls[] = ['minWidthOf', $id];
            $calls[] = ['dropRankOf', $id];
        }
        $out = array_column($this->js($calls), 'value');
        foreach ($ids as $i => $id) {
            $this->assertIsInt($out[2 * $i], "walang lapad: {$id}");
            $this->assertGreaterThanOrEqual(48, $out[2 * $i], $id);
            $this->assertGreaterThanOrEqual(0, $out[2 * $i + 1], "wala sa ayos ng pag-alis: {$id}");
        }
        // Ang mga hindi pinangalanan sa listahan ng spec ay nauuna sa PROMO, ayon sa catalog.
        $unnamed = ['orders', 'proceed', 'pcpp', 'per_order', 'np_per_order', 'np_per_order_3d', 'np_per_order_7d', 'proj_prof_3d', 'proj_prof_7d', 'ship', 'cod_fee', 'claude_action', 'claude_reason', 'ceo_action', 'ceo_reason', 'category'];
        $this->assertSame($unnamed, array_values(array_intersect($ids, $unnamed)));
        $ranks = array_column($this->js(array_map(fn (string $id) => ['dropRankOf', $id], array_merge($unnamed, ['promo']))), 'value');
        $this->assertSame(range(0, 16), $ranks);
        // Ang id na hindi kilala ay walang lapad at walang puwesto.
        $this->assertSame([null, -1], array_column($this->js([['minWidthOf', 'not_a_column'], ['dropRankOf', 'not_a_column']]), 'value'));
    }

    // ── S-37: ang babala ng item na walang supplier ───────────────────────────

    /** @return array<string, array{0: ?array, 1: ?array, 2: bool}> */
    public static function warningRule(): array
    {
        $q = ['id' => 1, 'supplier_id' => 4, 'price' => 18.5, 'moq' => null, 'cheapest' => false];
        $po = ['supplier' => 'A', 'supplier_id' => 4, 'unit_cost' => 17.8, 'order_date' => '2026-09-25', 'order_no' => null];

        return [
            'S-37.1 no quote and no PO'    => [[], [], true],
            'S-37.2 a quote only'          => [[$q], [], false],
            'S-37.2 a PO only'             => [[], [$po], false],
            'S-37.2 a quote with no price' => [[['price' => null] + $q], [], false],
            'S-37.4 a quote and a PO'      => [[$q], [$po], false],
        ];
    }

    #[DataProvider('warningRule')]
    public function test_S_37_1_S_37_2_S_37_4_the_warning_is_for_an_item_with_no_quote_and_no_po(?array $quotes, ?array $po, bool $marked): void
    {
        $this->assertSame($marked, $this->call('noSupplier', $quotes, $po));
    }
}
