<?php

namespace Tests\Feature\Chatblast;

use App\Models\ChatbotFlow;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Ang click sa button ng Test tab: ipinapadala ang title at ang payload, kaya sinusundan nito ang exit gaya ng totoong tap. */
class ChatbotButtonPortsTestTabTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_inbox_indexes.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_25_100000_create_chatbot_waits_table.php',
            'database/migrations/2026_10_30_098000_add_raw_to_chatbot_replies.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
            'database/migrations/2026_11_11_100000_add_real_send_to_chatbot_replies.php',
            'database/migrations/2026_11_13_100000_widen_via_on_chatbot_replies.php',
            'database/migrations/2026_11_17_100000_add_route_to_chatbot_replies.php',
        ]]);
        $this->page('Page Uno', '1001');
        config(['services.chatbot.replies_enabled' => true, 'services.gateway.url' => 'https://gw.example.test/api', 'services.gateway.key' => 'gw_key',
            'services.incepxionbot.url' => '', 'services.gateway.buttons' => 'forbeast']);
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'gateway', false, null, true);
        Http::fake();
        $this->actingAs($this->user());
    }

    /** Draft: n2 "Ano po?" (Order na → n4, naghihintay, ANSWER) → reply → n5. */
    private function draft(): ChatbotFlow
    {
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::blank()]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['draft_graph' => json_encode(FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Ano po?', 'buttons' => [
                ['title' => 'Order na', 'type' => 'postback', 'payload' => 'ORDER_NA', 'id' => 'ordr0001']]]], 'wait' => true, 'save_to' => 'ANSWER']],
            ['id' => 'n4', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Salamat sa order, {{ANSWER}}']]]],
            ['id' => 'n5', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Sige po']]]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'tap_ordr0001', 'to' => 'n4'], ['from' => 'n2', 'port' => 'reply', 'to' => 'n5']]]))]);
        app(ChatbotPage::class)->link('Page Uno', $flow);

        return $flow->fresh();
    }

    private function send(ChatbotFlow $flow, string $message, array $extra = []): array
    {
        return $this->postJson('/chatbot/test/send', ['page_name' => 'Page Uno', 'flow_id' => $flow->id, 'version' => 'draft', 'message' => $message] + $extra)->assertOk()->json();
    }

    public function test_S_237_3_2_a_test_tab_tap_follows_its_port_and_typed_text_leaves_by_reply(): void
    {
        $flow = $this->draft();
        $payload = $this->send($flow, 'Hi')['sends'][0]['messages'][0]['buttons'][0]['payload'];
        $res = $this->send($flow, 'Order na', ['tap' => $payload]);

        $this->assertContains(['node' => 'n2', 'type' => 'send', 'port' => 'tap_ordr0001', 'note' => 'Tapped "Order na"'], $res['path']);
        $this->assertSame('Salamat sa order, Order na', $res['sends'][0]['messages'][0]['text']);

        // Ang isinulat na "Order na" (walang tap) ay sagot lang, gaya ng totoong customer.
        $this->send($flow, 'Hi');
        $this->assertSame('Sige po', $this->send($flow, 'Order na')['sends'][0]['messages'][0]['text']);
    }

    public function test_S_237_3_2_page_the_shown_button_sends_its_title_and_payload(): void
    {
        $html = $this->get('/chatbot/test')->assertOk()->getContent();

        $this->assertStringContainsString("addEventListener('click', () => say(t.title, t.payload))", $html);
        $this->assertStringContainsString('{...base(), message: msg, ...(tap ? {tap} : {})}', $html);
    }
}
