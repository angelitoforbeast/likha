<?php

namespace Tests\Feature\Chatblast;

use App\Models\ChatbotFlow;
use App\Services\Chatbot\FlowGraph;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;

/**
 * Ang exit ng bawat button at quick reply ng naghihintay na Send message: ang id na naka-save sa button ang pangalan ng
 * port (tap_<id>), kaya ang pagpalit ng title o ayos ay hindi nag-aalis ng wire. Ang lumang graph (walang id) ay walang
 * bagong port.
 */
class ChatbotButtonPortsTest extends ChatblastTestCase
{
    private ?\App\Models\User $me = null;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
        ]]);
    }

    private const ORDER = ['title' => 'Order na', 'type' => 'postback', 'payload' => 'ORDER_NA', 'id' => 'ordr0001'];
    private const ASK = ['title' => 'Magtanong', 'type' => 'postback', 'payload' => 'MAGTANONG', 'id' => 'magt0001'];
    private const SITE = ['title' => 'Site', 'type' => 'web_url', 'url' => 'https://shop.example.test/', 'id' => 'site0001'];

    /** Start → Send n2 (ang mga item, naghihintay) → ang ibinigay na mga wire mula sa n2 papunta sa n3 / n4. */
    private function graph(array $items, array $config = ['wait' => true], array $edges = []): array
    {
        return ['nodes' => [['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'send', 'config' => ['items' => $items] + $config],
            ['id' => 'n3', 'type' => 'end'], ['id' => 'n4', 'type' => 'end']],
            'edges' => array_merge([['from' => 'n1', 'port' => 'next', 'to' => 'n2']], $edges)];
    }

    private function buttons(array ...$buttons): array
    {
        return [['type' => 'text', 'value' => 'Ano po ang gusto ninyo?', 'buttons' => $buttons]];
    }

    private function send(array $graph): array
    {
        return collect($graph['nodes'])->firstWhere('id', 'n2');
    }

    private function flow(): ChatbotFlow
    {
        $this->actingAs($this->me ??= $this->user());

        return ChatbotFlow::create(['name' => 'Main ' . random_int(1, 99999), 'graph' => FlowGraph::blank()]);
    }

    private function save(ChatbotFlow $flow, array $graph): \Illuminate\Testing\TestResponse
    {
        return $this->putJson("/chatbot/flows/{$flow->id}/graph", ['graph' => $graph, 'version' => ChatbotFlow::find($flow->id)->draft_version]);
    }

    private function reload(ChatbotFlow $flow): array
    {
        return $this->getJson("/chatbot/flows/{$flow->id}/graph")->assertOk()->json('graph');
    }

    public function test_S_237_1_1_postbacks_get_a_port_each_between_reply_and_skipped_and_the_link_gets_none(): void
    {
        $items = $this->buttons(self::ORDER, self::ASK, self::SITE);
        $this->assertSame(['reply', 'tap_ordr0001', 'tap_magt0001'], FlowGraph::ports($this->send(FlowGraph::clean($this->graph($items)))));
        $this->assertSame(['reply', 'tap_ordr0001', 'tap_magt0001', 'skipped'],
            FlowGraph::ports($this->send(FlowGraph::clean($this->graph($items, ['wait' => true, 'skip_after' => ['amount' => 30, 'unit' => 'minutes']])))));
        // Hindi naghihintay: walang sagot, kaya continue lang.
        $this->assertSame(['continue'], FlowGraph::ports($this->send(FlowGraph::clean($this->graph($items, ['wait' => false])))));
    }

    public function test_S_237_1_2_three_quick_replies_get_three_ports_in_order(): void
    {
        $quick = [['title' => 'S', 'payload' => 'S', 'id' => 'size000s'], ['title' => 'M', 'payload' => 'M', 'id' => 'size000m'], ['title' => 'L', 'payload' => 'L', 'id' => 'size000l']];
        $graph = FlowGraph::clean($this->graph([['type' => 'text', 'value' => 'Hi'], ['type' => 'text', 'value' => 'Size?', 'quick_replies' => $quick]]));

        $this->assertSame(['reply', 'tap_size000s', 'tap_size000m', 'tap_size000l'], FlowGraph::ports($this->send($graph)));
    }

    public function test_S_237_1_3_renaming_or_reordering_buttons_keeps_their_wires_after_save_and_reload(): void
    {
        $flow = $this->flow();
        $wires = [['from' => 'n2', 'port' => 'tap_ordr0001', 'to' => 'n3'], ['from' => 'n2', 'port' => 'tap_magt0001', 'to' => 'n4']];
        $this->save($flow, $this->graph($this->buttons(self::ORDER, self::ASK), edges: $wires))->assertOk();

        $renamed = ['title' => 'Bili na', 'payload' => 'BILI_NA'] + self::ORDER;
        $this->save($flow, $this->graph($this->buttons(self::ASK, $renamed), edges: $wires))->assertOk();
        $back = $this->reload($flow);

        $this->assertSame(['reply', 'tap_magt0001', 'tap_ordr0001'], FlowGraph::ports($this->send($back)));
        $this->assertSame($wires, array_slice($back['edges'], 1));
        $this->assertSame('Bili na', $this->send($back)['config']['items'][0]['buttons'][1]['title']);
    }

    public function test_S_237_1_4_a_deleted_button_has_no_port_and_a_wire_left_to_it_is_refused(): void
    {
        $flow = $this->flow();
        $this->save($flow, $this->graph($this->buttons(self::ORDER, self::ASK), edges: [['from' => 'n2', 'port' => 'tap_ordr0001', 'to' => 'n3']]))->assertOk();

        $error = $this->save($flow, $this->graph($this->buttons(self::ASK), edges: [['from' => 'n2', 'port' => 'tap_ordr0001', 'to' => 'n3']]))->assertStatus(422)->json('error');
        $this->assertStringContainsString('has no port "tap_ordr0001"', $error);

        $this->save($flow, $this->graph($this->buttons(self::ASK)))->assertOk();
        $this->assertSame(['reply', 'tap_magt0001'], FlowGraph::ports($this->send($this->reload($flow))));
    }

    public function test_S_237_1_5_a_wire_to_a_port_that_does_not_exist_is_refused(): void
    {
        $bad = [
            'an id no button has' => [$this->buttons(self::ORDER), 'tap_zzzz9999'],
            'the link button' => [$this->buttons(self::ORDER, self::SITE), 'tap_site0001'],
            'a malformed id' => [$this->buttons(['id' => 'BAD ID!'] + self::ORDER), 'tap_BAD ID!'],
            'an id that is not a string' => [$this->buttons(['id' => 12345678] + self::ORDER), 'tap_12345678'],
            'an id with a trailing newline' => [$this->buttons(['id' => "abcdef
"] + self::ORDER), "tap_abcdef
"],
            'an id of 5 characters' => [$this->buttons(['id' => 'abcde'] + self::ORDER), 'tap_abcde'],
            'an id of 17 characters' => [$this->buttons(['id' => str_repeat('a', 17)] + self::ORDER), 'tap_' . str_repeat('a', 17)],
            'a step that does not wait' => [$this->buttons(self::ORDER), 'tap_ordr0001', ['wait' => false]],
        ];
        foreach ($bad as $why => $case) {
            [$items, $port] = $case;
            try {
                FlowGraph::clean($this->graph($items, $case[2] ?? ['wait' => true], [['from' => 'n2', 'port' => $port, 'to' => 'n3']]));
                $this->fail('expected a refusal: ' . $why);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Node n2 has no port', $e->getMessage(), $why);
            }
        }
    }

    public function test_S_237_1_6_a_link_keeps_its_id_so_switching_to_postback_brings_the_port_back_and_ids_repeat_nowhere_in_a_step(): void
    {
        $link = FlowGraph::clean($this->graph($this->buttons(self::SITE)));
        $this->assertSame('site0001', $this->send($link)['config']['items'][0]['buttons'][0]['id']);
        $this->assertSame(['reply'], FlowGraph::ports($this->send($link)));

        $postback = FlowGraph::clean($this->graph($this->buttons(['type' => 'postback', 'payload' => 'SITE'] + self::SITE), edges: [['from' => 'n2', 'port' => 'tap_site0001', 'to' => 'n3']]));
        $this->assertSame(['reply', 'tap_site0001'], FlowGraph::ports($this->send($postback)));

        // Ang hangganan ng haba: 6 at 16 ay tinatanggap.
        $edge = FlowGraph::clean($this->graph($this->buttons(['id' => 'abcdef'] + self::ORDER, ['id' => str_repeat('z', 16)] + self::ASK)));
        $this->assertSame(['reply', 'tap_abcdef', 'tap_' . str_repeat('z', 16)], FlowGraph::ports($this->send($edge)));

        $repeats = [
            'a button and a quick reply' => [['type' => 'text', 'value' => 'Una', 'buttons' => [self::ORDER]], ['type' => 'text', 'value' => 'Ikalawa', 'quick_replies' => [['title' => 'Oo', 'payload' => 'OO', 'id' => 'ordr0001']]]],
            'a link and a postback' => $this->buttons(self::ORDER, ['id' => 'ordr0001'] + self::SITE),
        ];
        foreach ($repeats as $why => $items) {
            try {
                FlowGraph::clean($this->graph($items));
                $this->fail('expected a refusal of the repeated id: ' . $why);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Send message step n2', $e->getMessage(), $why);
                $this->assertStringContainsString('same id', $e->getMessage(), $why);
            }
        }
    }

    public function test_S_237_3_1_an_unwired_button_exit_publishes_with_a_notice(): void
    {
        $flow = $this->flow();
        $this->save($flow, $this->graph($this->buttons(self::ORDER, self::ASK), edges: [['from' => 'n2', 'port' => 'tap_ordr0001', 'to' => 'n3']]))->assertOk();
        $notice = $this->postJson("/chatbot/flows/{$flow->id}/publish", ['version' => ChatbotFlow::find($flow->id)->draft_version])->assertOk()->json('notice');

        $this->assertStringContainsString('Send message step n2 has button or quick reply exits with no wire (Magtanong)', $notice);
        $this->assertStringContainsString('leaves by "reply"', $notice);
        $this->assertNotNull(ChatbotFlow::find($flow->id)->published_at);
    }

    public function test_S_237_3_3_a_flow_saved_before_this_change_cleans_unchanged_with_no_new_ports(): void
    {
        $old = $this->graph([['type' => 'text', 'value' => 'Order na po?', 'buttons' => [['title' => 'Yes', 'type' => 'postback', 'payload' => 'YES'], ['title' => 'Shop', 'type' => 'web_url', 'url' => 'https://shop.example.test/']]],
            ['type' => 'text', 'value' => 'Size?', 'quick_replies' => [['title' => 'M', 'payload' => 'M']]]], ['wait' => true, 'save_to' => 'ANSWER'], [['from' => 'n2', 'port' => 'reply', 'to' => 'n3']]);
        $once = FlowGraph::clean($old);

        $this->assertSame($once, FlowGraph::clean($once));
        $this->assertStringNotContainsString('"id":"', json_encode($this->send($once)['config']));
        $this->assertSame(['reply'], FlowGraph::ports($this->send($once)));
        $this->assertNull(FlowGraph::publishNotice($once));
    }

    public function test_S_237_3_5_two_messages_of_one_step_with_the_same_payload_get_a_publish_notice(): void
    {
        $graph = FlowGraph::clean($this->graph([['type' => 'text', 'value' => 'Una?', 'buttons' => [['title' => 'Oo', 'type' => 'postback', 'payload' => 'OO', 'id' => 'oo000001']]],
            ['type' => 'text', 'value' => 'Ikalawa?', 'buttons' => [['title' => 'Oo po', 'type' => 'postback', 'payload' => 'OO', 'id' => 'oo000002']]]],
            edges: [['from' => 'n2', 'port' => 'tap_oo000001', 'to' => 'n3'], ['from' => 'n2', 'port' => 'tap_oo000002', 'to' => 'n4']]));

        $this->assertStringContainsString('Send message step n2 has two buttons or quick replies with the same payload (OO): a tap always takes the first one\'s exit.', (string) FlowGraph::publishNotice($graph));
    }
}
