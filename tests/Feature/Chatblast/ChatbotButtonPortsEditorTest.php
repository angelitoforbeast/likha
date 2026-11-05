<?php

namespace Tests\Feature\Chatblast;

use App\Models\ChatbotFlow;
use App\Services\Chatbot\FlowGraph;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

/**
 * Ang exit ng bawat button sa editor: ang mga patakaran ng public/js/chatbot-buttons.js (sa node, tests/js/call.cjs) na
 * kapareho ng FlowGraph (ang id, ang ayos ng mga port), at ang render ng editor at ng Test tab. Kapag walang node sa
 * makina, ang mga test ng script ay "skipped: node not found": hindi iyon pasado.
 */
class ChatbotButtonPortsEditorTest extends ChatblastTestCase
{
    private const SCRIPT = 'public/js/chatbot-buttons.js';
    private const RUNNER = 'tests/js/call.cjs';

    private static ?bool $node = null;

    private ?\App\Models\User $me = null;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
        ]]);
    }

    private function script(string $fn, mixed ...$args): array
    {
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
        $root = dirname(__DIR__, 3);
        $process = new Process(['node', $root . '/' . self::RUNNER, $root . '/' . self::SCRIPT, 'ChatbotButtons']);
        $process->setInput(json_encode([['fn' => $fn, 'args' => $args]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'node: ' . trim($process->getErrorOutput()));
        $out = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR)[0];
        $this->assertArrayNotHasKey('error', $out, $fn . ': ' . ($out['error'] ?? ''));

        return $out;
    }

    private function value(string $fn, mixed ...$args): mixed
    {
        return $this->script($fn, ...$args)['value'];
    }

    private function editor(): string
    {
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::blank()]);
        $this->actingAs($this->me ??= $this->user());

        return $this->get("/chatbot/flows/{$flow->id}")->assertOk()->getContent();
    }

    private const ORDER = ['title' => 'Order na', 'type' => 'postback', 'payload' => 'ORDER_NA', 'id' => 'ordr0001'];
    private const ASK = ['title' => 'Magtanong', 'type' => 'postback', 'payload' => 'MAGTANONG', 'id' => 'magt0001'];
    private const SITE = ['title' => 'Site', 'type' => 'web_url', 'url' => 'https://shop.example.test/', 'id' => 'site0001'];

    /** Ang mga port ng tap ayon sa script, at ayon sa server para sa parehong mga item (iisang ayos). */
    private function ports(array $items): array
    {
        $js = array_column($this->value('tapPorts', $items), 'port');
        $node = ['type' => 'send', 'config' => ['items' => $items, 'wait' => true]];
        $this->assertSame(array_merge(['reply'], $js), FlowGraph::ports($node), 'the same ports as the server');

        return $js;
    }

    public function test_S_237_1_1_editor_the_node_draws_a_port_per_postback_labelled_with_its_title_and_none_for_the_link(): void
    {
        $items = [['type' => 'text', 'value' => 'Ano po?', 'buttons' => [self::ORDER, self::ASK, self::SITE]]];
        $this->assertSame([['port' => 'tap_ordr0001', 'title' => 'Order na'], ['port' => 'tap_magt0001', 'title' => 'Magtanong']], $this->value('tapPorts', $items));
        $this->ports($items);

        $html = $this->editor();
        $this->assertStringContainsString("case 'send': return c.wait ? ['reply', ...ChatbotButtons.tapPorts(c.items).map(t => t.port), ...(skipMinutes(c) ? ['skipped'] : [])] : ['continue'];", $html);
        $this->assertStringContainsString("if (p.startsWith('tap_')) return (ChatbotButtons.tapPorts(n.config.items).find(t => t.port === p) || {}).title || '(no title)';", $html);
    }

    public function test_S_237_1_2_editor_three_quick_replies_draw_three_ports_in_order(): void
    {
        $quick = [['title' => 'S', 'payload' => 'S', 'id' => 'size000s'], ['title' => 'M', 'payload' => 'M', 'id' => 'size000m'], ['title' => 'L', 'payload' => 'L', 'id' => 'size000l']];

        $this->assertSame(['tap_size000s', 'tap_size000m', 'tap_size000l'], $this->ports([['type' => 'text', 'value' => 'Hi'], ['type' => 'text', 'value' => 'Size?', 'quick_replies' => $quick]]));
    }

    public function test_S_237_1_3_editor_renaming_or_removing_the_first_of_three_keeps_the_other_ports(): void
    {
        $third = ['title' => 'Mamaya', 'type' => 'postback', 'payload' => 'MAMAYA', 'id' => 'mmya0001'];
        $this->assertSame(['tap_ordr0001', 'tap_magt0001', 'tap_mmya0001'], $this->ports([['type' => 'text', 'value' => 'Ano po?', 'buttons' => [self::ORDER, self::ASK, $third]]]));
        // Ang editor ay walang paglipat ng ayos: ang pagtanggal ng una ang nagpapalipat sa iba, at ang port nila ay hindi nagbabago.
        $this->assertSame(['tap_magt0001', 'tap_mmya0001'], $this->ports([['type' => 'text', 'value' => 'Ano po?', 'buttons' => [['title' => 'Tanong po'] + self::ASK, $third]]]));
        $this->assertSame('Tanong po', $this->value('tapPorts', [['type' => 'text', 'value' => 'Ano po?', 'buttons' => [['title' => 'Tanong po'] + self::ASK]]])[0]['title']);
    }

    public function test_S_237_1_4_editor_a_removed_button_has_no_port_and_done_drops_its_wire(): void
    {
        $this->assertSame(['tap_magt0001'], $this->ports([['type' => 'text', 'value' => 'Ano po?', 'buttons' => [self::ASK]]]));

        $html = $this->editor();
        // Ang Done: ang wire ng port na nawala ay inaalis (gaya ng ibang port).
        $this->assertStringContainsString('graph.edges = graph.edges.filter(e => e.from !== editing.n.id || keep.includes(e.port));', $html);
    }

    public function test_S_237_1_6_editor_ids_follow_the_server_rule_are_made_on_add_load_restore_and_discard_and_survive_a_type_switch(): void
    {
        // Ang /D ng PHP: ang $ ay hindi tumatanggap ng newline sa dulo, gaya ng $ ng JS.
        $this->assertSame(FlowGraph::TAP_ID, '/' . $this->value('idRule') . '/D');
        $this->assertSame(0, preg_match(FlowGraph::TAP_ID, "abcdef
"));

        // 20 button na walang id, may sira, at may umuulit: bawat isa ay may bagong tamang id na hindi umuulit sa step.
        $buttons = array_map(fn ($i) => ['title' => 'B' . $i, 'type' => 'postback', 'payload' => 'B' . $i], range(1, 20));
        $buttons[3]['id'] = 'BAD ID';
        $buttons[4]['id'] = 'keep0001';
        $buttons[5]['id'] = 'keep0001';
        $graph = ['nodes' => [['id' => 'n2', 'type' => 'send', 'config' => ['items' => array_map(fn ($c) => ['type' => 'text', 'value' => 'Hi', 'buttons' => $c], array_chunk($buttons, 3))]]], 'edges' => []];
        $ids = array_column(array_merge(...array_column($this->script('withIds', $graph)['args'][0]['nodes'][0]['config']['items'], 'buttons')), 'id');
        $this->assertCount(20, array_unique($ids));
        foreach ($ids as $id) $this->assertMatchesRegularExpression(FlowGraph::TAP_ID, $id);
        $this->assertSame('keep0001', $ids[4], 'a good id is kept');
        $this->assertSame(8, strlen($ids[0]));

        // Ang link ay may id din: kapag ginawang postback, ang port nito ay bumabalik.
        $this->assertSame(['tap_site0001'], $this->ports([['type' => 'text', 'value' => 'Hi', 'buttons' => [['type' => 'postback', 'payload' => 'SITE'] + self::SITE]]]));

        $html = $this->editor();
        $this->assertStringContainsString("(it.buttons = it.buttons || []).push({title: '', type: 'postback', payload: '', id: B.newId()})", $html);
        $this->assertStringContainsString("(it.quick_replies = it.quick_replies || []).push({title: '', payload: '', id: B.newId()})", $html);
        // Bago ang unang snapshot (hindi maa-Undo) at sa bawat graph mula sa server (Restore, Discard).
        $this->assertStringContainsString('ChatbotButtons.withIds(graph);' . "\n" . '        current = snapshot();', $html);
        $this->assertStringContainsString('graph = g; ChatbotButtons.withIds(graph);', $html);
    }

    public function test_S_237_3_4_the_port_rows_and_the_button_rows_fit_a_phone(): void
    {
        $html = $this->editor();
        // Ang mahabang title ng port ay pinuputol sa loob ng 220 px na node; ang mga row ng button ay nagwa-wrap (390 px).
        $this->assertStringContainsString("el('div', {class: 'nd-p'}, [el('span', {class: 'truncate', text: portLabel(n, p)})", $html);
        $this->assertStringContainsString("class: 'flex flex-wrap items-end gap-2 min-w-0'", $html);
    }
}
