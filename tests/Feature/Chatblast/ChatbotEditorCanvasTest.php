<?php

namespace Tests\Feature\Chatblast;

use App\Models\ChatbotFlow;
use App\Services\Chatbot\FlowGraph;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * Ang canvas ng flow editor: ang pag-save ay posisyon lang ng mga step ang binabago (drag, Snap, Arrange), at ang page
 * ay may Snap at minimap. Ang paggalaw mismo ay nasa browser check ng spec.
 */
class ChatbotEditorCanvasTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
        ]]);
    }

    private function graph(array $at): array
    {
        return ['nodes' => [
            ['id' => 'n1', 'type' => 'start', 'x' => $at[0][0], 'y' => $at[0][1], 'config' => []],
            ['id' => 'n2', 'type' => 'send', 'x' => $at[1][0], 'y' => $at[1][1], 'config' => ['items' => [['type' => 'text', 'value' => 'Hi']], 'wait' => false, 'save_to' => '']],
            ['id' => 'n3', 'type' => 'end', 'x' => $at[2][0], 'y' => $at[2][1], 'config' => []],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'continue', 'to' => 'n3']]];
    }

    public function test_S_101_1_saving_keeps_the_node_positions(): void
    {
        $this->actingAs($this->user());
        $before = FlowGraph::clean($this->graph([[40, 40], [340, 40], [640, 40]]));
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => $before]);

        // Isang drag na naka-Snap (16 px), isang drag sa kaliwa ng 0, at ang Arrange: posisyon lang ang nagbago.
        $moved = $this->graph([[32, 48], [-208, 1296], [3200, 640]]);
        $this->putJson("/chatbot/flows/{$flow->id}/graph", ['graph' => $moved, 'version' => 0])->assertOk();
        $saved = $this->getJson("/chatbot/flows/{$flow->id}/graph")->assertOk()->json('graph');

        $this->assertSame([[32, 48], [-208, 1296], [3200, 640]], array_map(fn ($n) => [$n['x'], $n['y']], $saved['nodes']));
        $strip = fn ($g) => array_map(fn ($n) => array_diff_key($n, ['x' => 1, 'y' => 1]), $g['nodes']);
        $this->assertSame($strip($before), $strip($saved));
        $this->assertSame($before['edges'], $saved['edges']);
    }

    public function test_S_101_2_the_editor_has_snap_and_a_minimap(): void
    {
        $this->actingAs($this->user());
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::blank()]);

        $html = $this->get("/chatbot/flows/{$flow->id}")->assertOk()->getContent();

        $this->assertStringContainsString('id="snap" checked', $html);
        $this->assertStringContainsString('id="minimap-toggle"', $html);
        $this->assertStringContainsString('id="minimap"', $html);
        $this->assertMatchesRegularExpression('/#cv\.moving[^{]*\{[^}]*user-select: none/', $html);
    }
}
