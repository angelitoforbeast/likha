<?php

namespace Tests\Feature\Chatblast;

use App\Models\ChatbotFlow;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Ang Flow tab ng /chatbot: ang listahan ng flow (gawa, palit-pangalan, kopya, bura), ang pag-save at pagbasa ng graph
 * na may pagsusuri kada port, at ang FLOW na dropdown sa page list. Walang tawag sa labas.
 */
class ChatbotEditorTest extends ChatblastTestCase
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
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '2002');
    }

    private function navLink(string $url, array $roles): void
    {
        if (!Schema::hasTable('nav_links')) {
            Artisan::call('migrate', ['--path' => 'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php', '--force' => true]);
        }
        $id = DB::table('nav_links')->insertGetId([
            'key' => 'chatblast', 'label' => 'Chatblast', 'route_url' => $url, 'icon' => 'fa-solid fa-link', 'active_pattern' => 'chatblast*',
            'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($roles as $role) {
            DB::table('nav_link_role_visibility')->insert(['nav_link_id' => $id, 'role' => $role, 'is_visible' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function graph(): array
    {
        return ['nodes' => [
            ['id' => 'n1', 'type' => 'start', 'x' => 40, 'y' => 40],
            ['id' => 'n2', 'type' => 'condition', 'x' => 300, 'y' => 40, 'config' => ['rows' => [
                ['variable' => 'last_input', 'rule' => 'contains_any', 'value' => "price\nhow much"], ['variable' => 'CHAT STATUS', 'rule' => 'is_empty'],
            ]]],
            ['id' => 'n3', 'type' => 'send', 'x' => 600, 'y' => 40, 'config' => ['items' => [['type' => 'text', 'value' => 'It is {{PRICE}}.']], 'wait' => true, 'save_to' => 'Reply One']],
            ['id' => 'n4', 'type' => 'ai', 'x' => 600, 'y' => 240, 'config' => ['system' => '{{Prompt 1}}', 'history' => 5, 'save_to' => 'GPT INPUT', 'send' => true]],
            ['id' => 'n5', 'type' => 'end', 'x' => 900, 'y' => 40],
        ], 'edges' => [
            ['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'row_1', 'to' => 'n3'],
            ['from' => 'n2', 'port' => 'no', 'to' => 'n4'], ['from' => 'n3', 'port' => 'reply', 'to' => 'n5'], ['from' => 'n4', 'port' => 'failed', 'to' => 'n5'],
        ]];
    }

    public function test_S_069_D1_the_graph_saves_and_loads_cleaned(): void
    {
        $this->actingAs($ceo = $this->user());
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::blank()]);

        $this->putJson("/chatbot/flows/{$flow->id}/graph", ['graph' => $this->graph(), 'version' => 0])->assertOk()->assertJsonStructure(['saved_at']);
        $saved = $this->getJson("/chatbot/flows/{$flow->id}/graph")->assertOk()->json();

        $this->assertSame(FlowGraph::clean($this->graph()), $saved['graph']);
        $this->assertSame('Main', $saved['name']);
        $this->assertSame(['row_1', 'row_2', 'no'], FlowGraph::ports($saved['graph']['nodes'][1]));
        $this->assertSame('reply one', $saved['graph']['nodes'][2]['config']['save_to']);
        $this->assertContains('last_input', $saved['variables']);
        $this->assertSame($ceo->id, ChatbotFlow::find($flow->id)->updated_by);
    }

    public static function badGraphs(): array
    {
        $g = fn (array $nodes, array $edges) => ['nodes' => $nodes, 'edges' => $edges];
        $start = ['id' => 'n1', 'type' => 'start'];
        $cond = ['id' => 'n2', 'type' => 'condition', 'config' => ['rows' => [['variable' => 'x', 'rule' => 'is', 'value' => 'y']]]];
        $send = ['id' => 'n3', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'hi']]]];
        $many = array_map(fn ($i) => ['id' => 'n' . $i, 'type' => 'end'], range(2, 202));

        return [
            'port the condition does not have' => [$g([$start, $cond, $send], [['from' => 'n2', 'port' => 'row_2', 'to' => 'n3']]), 'no port "row_2"'],
            'reply without wait'               => [$g([$start, $send, $cond], [['from' => 'n3', 'port' => 'reply', 'to' => 'n2']]), 'no port "reply"'],
            'port of another type'             => [$g([$start, $send], [['from' => 'n1', 'port' => 'success', 'to' => 'n3']]), 'no port "success"'],
            'node of another flow'             => [$g([$start, $send], [['from' => 'n1', 'port' => 'next', 'to' => 'n77']]), 'not in this flow'],
            'self-loop'                        => [$g([$start, $send], [['from' => 'n3', 'port' => 'continue', 'to' => 'n3']]), 'itself'],
            'two wires on one port'            => [$g([$start, $send, $cond], [['from' => 'n1', 'port' => 'next', 'to' => 'n3'], ['from' => 'n1', 'port' => 'next', 'to' => 'n2']]), 'two wires'],
            'two starts'                       => [$g([$start, ['id' => 'n2', 'type' => 'start']], []), 'exactly one Start'],
            'no start'                         => [$g([$send], []), 'exactly one Start'],
            'unknown type'                     => [$g([$start, ['id' => 'n2', 'type' => 'php']], []), 'unknown type'],
            'bad id'                           => [$g([$start, ['id' => '../x', 'type' => 'end']], []), 'id like'],
            'too many nodes'                   => [$g(array_merge([$start], $many), []), 'At most 200 nodes'],
            'too many wires'                   => [$g([$start, $send], array_fill(0, 1001, ['from' => 'n1', 'port' => 'next', 'to' => 'n3'])), 'At most 1000 wires'],
        ];
    }

    /** @dataProvider badGraphs */
    public function test_S_069_D2_a_bad_graph_is_refused_and_the_saved_one_stays(array $graph, string $message): void
    {
        $this->actingAs($this->user());
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::blank()]);

        $res = $this->putJson("/chatbot/flows/{$flow->id}/graph", ['graph' => $graph])->assertStatus(422);

        $this->assertStringContainsString($message, $res->json('error'));
        $this->assertSame(FlowGraph::blank(), ChatbotFlow::find($flow->id)->graph);
    }

    public function test_S_069_D3_flows_are_created_renamed_duplicated_and_deleted(): void
    {
        $this->actingAs($this->user());

        $id = $this->postJson('/chatbot/flows', ['name' => ' Main  Flow '])->assertOk()->json('id');
        $this->assertSame(FlowGraph::blank(), ChatbotFlow::find($id)->graph);
        $this->postJson('/chatbot/flows', ['name' => 'main flow'])->assertStatus(422); // ulit ang pangalan
        $this->postJson('/chatbot/flows', ['name' => ''])->assertStatus(422);

        $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno', 'key' => 'flow', 'value' => 'Main Flow'])->assertOk();
        $this->postJson("/chatbot/flows/{$id}/rename", ['name' => 'Main COD'])->assertOk();
        $this->assertSame('Main COD', ChatbotFlow::find($id)->name);
        $this->assertSame('Main COD', app(PageSettings::class)->get('Page Uno', 'chatbot', 'flow'), 'ang mga page na gumagamit ay sumusunod sa bagong pangalan');

        $copy = $this->postJson("/chatbot/flows/{$id}/duplicate")->assertOk()->json('id');
        $this->assertSame(['Main COD (copy)', ChatbotFlow::find($id)->graph], [ChatbotFlow::find($copy)->name, ChatbotFlow::find($copy)->graph]);

        $this->deleteJson("/chatbot/flows/{$id}")->assertStatus(422)->assertJson(['error' => 'Page Uno uses this flow. Pick another flow for it first.']);
        $this->deleteJson("/chatbot/flows/{$copy}")->assertOk();
        $this->assertNull(ChatbotFlow::find($copy));
    }

    public function test_S_069_D4_the_flow_pages_need_chatblast_access(): void
    {
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::blank()]);
        foreach (['/chatbot', "/chatbot/flows/{$flow->id}"] as $url) $this->get($url)->assertRedirect();

        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        $this->get('/chatbot')->assertForbidden();
        $this->get("/chatbot/flows/{$flow->id}")->assertForbidden();
        $this->getJson("/chatbot/flows/{$flow->id}/graph")->assertForbidden();
        $this->putJson("/chatbot/flows/{$flow->id}/graph", ['graph' => $this->graph()])->assertForbidden();
        $this->postJson('/chatbot/flows', ['name' => 'X'])->assertForbidden();
        $this->postJson("/chatbot/flows/{$flow->id}/rename", ['name' => 'X'])->assertForbidden();
        $this->postJson("/chatbot/flows/{$flow->id}/duplicate")->assertForbidden();
        $this->deleteJson("/chatbot/flows/{$flow->id}")->assertForbidden();
        foreach (['publish', 'discard', 'versions/1/restore'] as $action) $this->postJson("/chatbot/flows/{$flow->id}/{$action}", ['version' => 0])->assertForbidden();
        $this->getJson("/chatbot/flows/{$flow->id}/versions")->assertForbidden();
        $this->assertSame(1, ChatbotFlow::count());

        $this->navLink('/chatblast', ['Marketing']);
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->get('/chatbot')->assertOk()->assertSee('Main')->assertSee('On + a flow = the bot answers.');
        // Ang JS ng editor ay hindi nasisira ng sariling echo ng Blade (nangyari ito: naging walang laman ang OPEN at CLOSE).
        $html = $this->get("/chatbot/flows/{$flow->id}")->assertOk()->getContent();
        $this->assertStringContainsString("const OPEN = String.fromCharCode(123, 123), CLOSE = String.fromCharCode(125, 125);", $html);
        $this->assertDoesNotMatchRegularExpression("/const OPEN = .,/", $html);
        $this->putJson("/chatbot/flows/{$flow->id}/graph", ['graph' => $this->graph(), 'version' => 0])->assertOk();
    }

    public function test_S_069_D5_the_page_list_flow_is_a_dropdown_of_flows(): void
    {
        ChatbotFlow::create(['name' => 'Main COD', 'graph' => FlowGraph::blank()]);
        ChatbotFlow::create(['name' => 'Promo', 'graph' => FlowGraph::blank()]);
        $this->actingAs($this->user());

        $data = $this->getJson('/chatbot/data')->assertOk()->json();
        $this->assertSame(['Main COD', 'Promo'], $data['flows']);
        $this->assertSame('flow', collect($data['columns'])->firstWhere('key', 'flow')['kind']);

        $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno', 'key' => 'flow', 'value' => 'Promo'])->assertOk();
        $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno', 'key' => 'flow', 'value' => 'Not A Flow'])->assertStatus(422);
        $this->assertSame('Promo', app(PageSettings::class)->get('Page Uno', 'chatbot', 'flow'));
        $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno', 'key' => 'flow', 'value' => ''])->assertOk()->assertJson(['status' => 'cleared']);
    }
}
