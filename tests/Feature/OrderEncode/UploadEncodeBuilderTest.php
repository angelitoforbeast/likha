<?php

namespace Tests\Feature\OrderEncode;

use App\Models\ChatbotFlow;
use App\Services\Chatbot\FlowGraph;
use Illuminate\Support\Facades\Artisan;

/** Ang dalawang bagong step sa builder (palette, config, Publish notice) at ang label ng legacy na setting. */
class UploadEncodeBuilderTest extends UploadEncodeTestCase
{
    public function test_S_142_1_the_add_bar_has_one_encode_order_button_and_no_legacy_one(): void
    {
        $this->actingAs($this->user());
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::blank()]);

        $html = $this->get("/chatbot/flows/{$flow->id}")->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-add="encode_macro"'));
        $this->assertSame(0, substr_count($html, 'data-add="encode_order"'));
        $this->assertSame(1, preg_match_all('/<button[^>]*data-add="[^"]*"[^>]*>Encode order<\/button>/', $html));
    }

    public function test_S_137_1_the_palette_has_both_blocks_and_their_configs_save_and_reload(): void
    {
        $this->actingAs($this->user());
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::blank()]);

        $page = $this->get("/chatbot/flows/{$flow->id}")->assertOk();
        $page->assertSee('data-add="upload_macro"', false)->assertSee('data-add="encode_macro"', false)
            ->assertSee('Saves the order to the orders (macro output) first: a new row (Insert) or the open row of the customer (Update).')
            ->assertSee('Encodes the row that Upload to macro saved in this run, waits for the result and puts it in encode_* variables.')
            ->assertSee('Encode order (legacy)');

        $graph = $this->graph([
            'n2' => ['upload_macro', $this->uploadConfig(['mode' => 'update', 'if_not_found' => 'skip', 'counts_order' => true, 'evil' => 'x'])],
            'n3' => ['encode_macro', ['wait' => 120, 'astra' => false, 'evil' => 'x']],
        ], [['n1', 'next', 'n2'], ['n2', 'next', 'n3']]);
        $this->putJson("/chatbot/flows/{$flow->id}/graph", ['graph' => $graph, 'version' => 0])->assertOk();
        $saved = $this->getJson("/chatbot/flows/{$flow->id}/graph")->assertOk()->json('graph');

        [$up, $enc] = [$saved['nodes'][1], $saved['nodes'][2]];
        $this->assertSame([['next', 'failed'], ['next', 'failed']], [FlowGraph::ports($up), FlowGraph::ports($enc)]);
        $this->assertSame(['update', 'skip', true, '{{PHONE}}', '998'], [$up['config']['mode'], $up['config']['if_not_found'], $up['config']['counts_order'], $up['config']['phone'], $up['config']['price']]);
        $this->assertSame(['astra' => false], $enc['config']);
        $this->assertArrayNotHasKey('evil', $up['config']);
    }

    /** @return array<string, array{0: array, 1: array}> ang config na ipinasa → ang naka-save */
    public static function configs(): array
    {
        return [
            'encode: defaults' => [['encode_macro', []], ['astra' => true]],
            'encode: an old wait is dropped' => [['encode_macro', ['wait' => 30, 'astra' => '1']], ['astra' => true]],
            'upload: bad enums' => [['upload_macro', ['mode' => 'drop', 'if_not_found' => 'explode']], ['mode' => 'insert', 'if_not_found' => 'insert']],
        ];
    }

    /** @dataProvider configs */
    public function test_S_137_1_block_configs_are_validated(array $node, array $expected): void
    {
        $c = FlowGraph::cleanConfig($node[0], $node[1]);

        $this->assertSame($expected, array_intersect_key($c, $expected + ['wait' => 0]));
    }

    /** @return array<string, array{0: bool, 1: bool}> may Upload bago ang Encode, may notice */
    public static function publishNotices(): array
    {
        return ['no Upload before the Encode' => [false, true], 'Upload before the Encode' => [true, false]];
    }

    /** @dataProvider publishNotices */
    public function test_S_137_38_publish_shows_a_notice_when_the_encode_has_no_upload_before_it(bool $upload, bool $notice): void
    {
        $this->actingAs($this->user());
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::blank()]);
        $graph = $upload
            ? $this->graph(['n2' => ['upload_macro', $this->uploadConfig()], 'n3' => ['encode_macro']], [['n1', 'next', 'n2'], ['n2', 'next', 'n3']])
            : $this->graph(['n2' => ['upload_macro', $this->uploadConfig()], 'n3' => ['encode_macro']], [['n1', 'next', 'n3'], ['n3', 'next', 'n2']]);
        $this->putJson("/chatbot/flows/{$flow->id}/graph", ['graph' => $graph, 'version' => 0])->assertOk();

        $res = $this->postJson("/chatbot/flows/{$flow->id}/publish", ['version' => 1])->assertOk();

        $this->assertNotNull($flow->fresh()->published_at);
        $this->assertSame($notice, str_contains((string) $res->json('notice'), 'Encode order step n3 has no Upload to macro before it'));
    }

    public function test_S_137_20_the_settings_page_says_the_address_sequence_is_for_the_legacy_block_only(): void
    {
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_11_05_100000_add_final_compare_to_order_encodes_table.php']]);
        $this->actingAs($this->user('CEO', 'ceo@example.test'));

        $this->get('/order-encode/final')->assertOk()->assertSee('Address question sequence (legacy block only)');
    }
}
