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
