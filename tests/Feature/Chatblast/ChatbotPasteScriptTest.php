<?php

namespace Tests\Feature\Chatblast;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Ang mga pure function ng public/js/chatbot-paste.js (Paste values sa /chatbot/pages), pinapatakbo sa node
 * (tests/js/call.cjs). Ang inaasahan ay mula sa kuwento (S-092), hindi kinukuwenta gaya ng script.
 * Kapag walang node sa makina, bawat test ay "skipped: node not found": hindi iyon pasado, hindi lang napatakbo.
 */
class ChatbotPasteScriptTest extends TestCase
{
    private const SCRIPT = 'public/js/chatbot-paste.js';
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

    private function call(string $fn, mixed ...$args): mixed
    {
        $root = dirname(__DIR__, 3);
        $process = new Process(['node', $root . '/' . self::RUNNER, $root . '/' . self::SCRIPT, 'ChatbotPaste']);
        $process->setInput(json_encode([['fn' => $fn, 'args' => $args]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'node: ' . trim($process->getErrorOutput()));
        $out = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR)[0];
        $this->assertArrayNotHasKey('error', $out, $fn . ': ' . ($out['error'] ?? ''));

        return $out['value'];
    }

    private const COLUMNS = [
        ['key' => 'price', 'label' => 'PRICE', 'kind' => 'text'],
        ['key' => 'main_flow', 'label' => 'Main Flow', 'kind' => 'text'],
        ['key' => 'picture', 'label' => 'PICTURE', 'kind' => 'image'],
        ['key' => 'seq_1', 'label' => 'Seq 1', 'kind' => 'long'],
    ];

    private function plan(string $text, array $targets, array $extra = []): array
    {
        return $this->call('plan', ['text' => $text, 'columns' => self::COLUMNS, 'targets' => $targets] + $extra);
    }

    /** @return array<int, string> "page|key|value|status" kada linya ng preview */
    private function lines(array $plan): array
    {
        return array_map(fn ($c) => $c['page'] . '|' . $c['key'] . '|' . $c['value'] . '|' . $c['status'], $plan['changes']);
    }

    public function test_S_092_1_the_tsv_parser_reads_sheets_quoting_and_writes_it_back(): void
    {
        foreach ([
            'tabs and rows' => ["a\tb\nc\td", [['a', 'b'], ['c', 'd']]],
            'trailing newline' => ["a\tb\n", [['a', 'b']]],
            'crlf' => ["a\tb\r\nc\t\r\n", [['a', 'b'], ['c', '']]],
            'quoted line break and tab' => ["\"Hi\nthere\"\t\"x\ty\"\nz\t", [["Hi\nthere", "x\ty"], ['z', '']]],
            'doubled quotes' => ["\"say \"\"hi\"\"\"\tok", [['say "hi"', 'ok']]],
            'quotes inside a plain cell' => ["\"Hello\" world\t5 \"inch\"", [['"Hello" world', '5 "inch"']]],
            'empty' => ['', []],
        ] as $case => [$text, $rows]) {
            $this->assertSame($rows, $this->call('parseTsv', $text), $case);
        }

        $rows = [["Line one\nLine two", "tab\there", 'say "hi"', 'plain']];
        $tsv = $this->call('toTsv', $rows);
        $this->assertSame("\"Line one\nLine two\"\t\"tab\there\"\t\"say \"\"hi\"\"\"\tplain", $tsv);
        $this->assertSame($rows, $this->call('parseTsv', $tsv));
    }

    public function test_S_092_2_a_header_line_maps_by_name_otherwise_by_order_from_the_chosen_field(): void
    {
        $uno = ['page' => 'Page Uno', 'cells' => []];

        $byName = $this->plan("Page\tACTIVE\tFLOW\tmain flow\tprice\tColour\nUno\tOn\tMain\tHi\t499\tred", [$uno]);
        $this->assertTrue($byName['byName']);
        $this->assertSame(['Page', 'Colour'], $byName['ignored']);
        $this->assertTrue($byName['reserved']);
        $this->assertSame(['Page Uno|main_flow|Hi|changed', 'Page Uno|price|499|changed'], $this->lines($byName));

        $byOrder = $this->plan("Hi\thttps://img.example.test/a.jpg\tStep one\textra\tmore", [$uno], ['startKey' => 'main_flow']);
        $this->assertFalse($byOrder['byName']);
        $this->assertSame(2, $byOrder['dropped']);
        $this->assertSame(['Page Uno|main_flow|Hi|changed', 'Page Uno|picture|https://img.example.test/a.jpg|changed', 'Page Uno|seq_1|Step one|changed'], $this->lines($byOrder));

        $this->assertSame(['Page Uno|price|499|changed'], $this->lines($this->plan('499', [$uno])), 'default: ang unang botfield');
    }

    public function test_S_092_3_one_row_goes_to_every_page_n_rows_one_per_page_any_other_count_is_refused(): void
    {
        $targets = [['page' => 'Page Uno', 'cells' => []], ['page' => 'Page Dos', 'cells' => []]];

        $this->assertSame(['Page Uno|price|10|changed', 'Page Dos|price|10|changed'], $this->lines($this->plan('10', $targets)));
        $this->assertSame(['Page Uno|price|10|changed', 'Page Dos|price|20|changed'], $this->lines($this->plan("10\n20\n", $targets)));
        $three = $this->plan("10\n20\n30", $targets);
        $this->assertSame([], $three['changes']);
        $this->assertStringContainsString('3 rows', $three['error']);
        $this->assertStringContainsString('2 pages', $three['error']);
        $this->assertNotNull($this->plan('10', [])['error'], 'walang page na napili');
    }

    public function test_S_092_4_non_links_are_flagged_blanks_keep_unless_ticked_and_equal_values_are_unchanged(): void
    {
        $uno = ['page' => 'Page Uno', 'cells' => ['price' => '499', 'main_flow' => 'Old flow']];

        $plan = $this->plan("499\t\twww.example.test/a.jpg", [$uno]);
        $this->assertSame(['Page Uno|price|499|unchanged', 'Page Uno|main_flow||blank', 'Page Uno|picture|www.example.test/a.jpg|flagged'], $this->lines($plan));
        $this->assertSame('Old flow', $plan['changes'][1]['old']);

        $this->assertSame(['Page Uno|price|499|unchanged', 'Page Uno|main_flow||changed'], $this->lines($this->plan("499\t", [$uno], ['blanksEmpty' => true])));
    }

    public function test_S_096_4_one_page_uses_only_the_first_data_row_by_name_or_by_order(): void
    {
        $uno = ['page' => 'Page Uno', 'cells' => ['price' => '499']];
        $one = ['firstRowOnly' => true];

        $byName = $this->plan("PRICE\tMain Flow\n599\t\"Hi\nThere\"\n700\tSecond row", [$uno], $one);
        $this->assertNull($byName['error']);
        $this->assertTrue($byName['byName']);
        $this->assertSame(1, $byName['extraRows']);
        $this->assertSame(['Page Uno|price|599|changed', "Page Uno|main_flow|Hi\nThere|changed"], $this->lines($byName));

        $byOrder = $this->plan("Hi\thttps://img.example.test/a.jpg\nignored\tx", [$uno], $one + ['startKey' => 'main_flow']);
        $this->assertNull($byOrder['error']);
        $this->assertSame(['Page Uno|main_flow|Hi|changed', 'Page Uno|picture|https://img.example.test/a.jpg|changed'], $this->lines($byOrder));

        $this->assertNotNull($this->plan("10\n20", [$uno])['error'], 'walang firstRowOnly: tinatanggihan pa rin gaya ng dati');
    }

    public function test_S_120_5_a_text_column_holding_only_a_link_gets_the_warning_naming_its_image_column(): void
    {
        $cols = [['key' => 'seq_11', 'label' => 'Seq 11', 'kind' => 'long'], ['key' => 'seq_img_11', 'label' => 'Seq Img 11', 'kind' => 'image'], ['key' => 'main_flow', 'label' => 'Main Flow', 'kind' => 'text']];
        $this->assertTrue($this->call('isLinkOnly', " https://botcake.pro/i/a.jpg \n"));
        $this->assertFalse($this->call('isLinkOnly', 'See https://botcake.pro/i/a.jpg here'));
        $this->assertSame('Seq 11 is an image link: put it in Seq Img 11. You can still save it.', $this->call('linkWarning', $cols[0], $cols));
        $this->assertSame('Main Flow is a link: this column needs text. You can still save it.', $this->call('linkWarning', $cols[2], $cols));
    }
}
