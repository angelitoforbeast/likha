<?php

namespace Tests\Feature\Chatblast;

use App\Models\ChatbotFlow;
use App\Models\ChatbotReply;
use App\Services\Chatbot\BotcakeOutbox;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Ang Test tab na may button at quick reply (draft, walang padala), at ang Inbox na may bagong key sa talaan. */
class ChatbotButtonsTestTabTest extends ChatblastTestCase
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
        // Ang page ay nasa gateway na tumatanggap ng button: kahit ganoon, walang ipinapadala ang Test.
        config(['services.chatbot.replies_enabled' => true, 'services.gateway.url' => 'https://gw.example.test/api', 'services.gateway.key' => 'gw_key',
            'services.incepxionbot.url' => '', 'services.gateway.buttons' => 'forbeast']);
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'gateway', false, null, true);
        Http::fake();
        $this->actingAs($this->user());
    }

    /** n2 nagtatanong na may button (naghihintay, ANSWER) → reply → n3 "Salamat, {{ANSWER}}". Draft lang (hindi published). */
    private function draft(array $buttons): ChatbotFlow
    {
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::blank()]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['draft_graph' => json_encode(FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Hi {{first_name}}, order na po?', 'buttons' => $buttons]], 'wait' => true, 'save_to' => 'ANSWER']],
            ['id' => 'n3', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Salamat, {{ANSWER}}']]]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'reply', 'to' => 'n3']]]))]);
        app(ChatbotPage::class)->link('Page Uno', $flow);

        return $flow->fresh();
    }

    private function send(ChatbotFlow $flow, string $message): array
    {
        return $this->postJson('/chatbot/test/send', ['page_name' => 'Page Uno', 'flow_id' => $flow->id, 'version' => 'draft', 'message' => $message])->assertOk()->json();
    }

    public function test_S_229_7_1_the_test_tab_shows_the_text_and_the_buttons_with_variables_filled(): void
    {
        $flow = $this->draft([['title' => 'Oo, {{first_name}}', 'type' => 'postback', 'payload' => 'YES'], ['title' => 'Shop', 'type' => 'web_url', 'url' => 'https://shop.example.test/']]);
        $m = $this->send($flow, 'Hi')['sends'][0]['messages'][0];

        $this->assertSame('Hi Test, order na po?', $m['text']);
        $this->assertSame([['title' => 'Oo, Test', 'type' => 'postback', 'payload' => 'YES'], ['title' => 'Shop', 'type' => 'web_url', 'url' => 'https://shop.example.test/']], $m['buttons']);
        $html = $this->get('/chatbot/test')->assertOk()->getContent();
        $this->assertStringContainsString('[...(m.buttons || []), ...(m.quick_replies || [])]', $html, 'the bubble draws its buttons and quick replies');
    }

    public function test_S_229_7_2_clicking_a_shown_button_continues_the_flow_as_a_real_tap(): void
    {
        $flow = $this->draft([['title' => 'Yes', 'type' => 'postback', 'payload' => 'ORDER_NOW']]);
        $this->send($flow, 'Hi');
        // Ang click ay ipinapadala ang title bilang mensahe ng test customer (gaya ng chat ng tap).
        $res = $this->send($flow, 'Yes');

        $this->assertContains(['node' => 'n2', 'type' => 'send', 'port' => 'reply', 'note' => 'Reply received'], $res['path']);
        $this->assertSame('Salamat, Yes', $res['sends'][0]['messages'][0]['text']);
        $this->assertStringContainsString("addEventListener('click', () => say(t.title, t.payload))", $this->get('/chatbot/test')->getContent());
    }

    public function test_S_229_7_4_a_test_run_sends_nothing_to_messenger(): void
    {
        $flow = $this->draft([['title' => 'Yes', 'type' => 'postback', 'payload' => 'YES']]);
        $this->send($flow, 'Hi');
        $this->send($flow, 'Yes');

        Http::assertNothingSent();
        $this->assertSame(0, ChatbotReply::count());
    }

    public function test_S_229_1_10_the_test_tab_never_links_to_a_url_that_is_not_https(): void
    {
        $flow = $this->draft([['title' => 'Bad', 'type' => 'web_url', 'url' => 'javascript:alert(document.cookie)'], ['title' => 'Yes', 'type' => 'postback', 'payload' => 'YES']]);
        $m = $this->send($flow, 'Hi')['sends'][0]['messages'][0];

        $this->assertSame(['Yes'], array_column($m['buttons'], 'title'), 'the sender dropped the link');
        $html = $this->get('/chatbot/test')->getContent();
        $this->assertStringContainsString("/^https:\\/\\//i.test(t.url || '')", $html, 'the page links only https');
        // Ang tap na walang title (draft) ay hindi iginuguhit: walang walang-laman na mensahe kapag pinindot.
        $this->assertStringContainsString(".filter(t => String(t.title || '').trim() !== '')", $html);
    }

    public function test_S_229_7_5_the_inbox_shows_a_sent_button_message_without_breaking_on_the_new_keys(): void
    {
        BotcakeOutbox::record(1001, 7001, null, 1, 'n2', 'send', [['type' => 'text', 'text' => 'Order na po?', 'buttons' => [['title' => 'Yes', 'type' => 'postback', 'payload' => 'YES', 'cut' => true]], 'ai' => false, 'text_cut' => true],
            ['type' => 'image', 'url' => 'https://img.example.test/a.jpg', 'quick_replies' => [['title' => 'M', 'payload' => 'M']]]], 'sent', null, 202, null, null, 'forbeast', 'likha-r1', 'forbeast');
        BotcakeOutbox::record(1001, 7001, null, 1, 'n2', 'send', [['type' => 'text', 'text' => 'Botcake po', 'buttons' => [['title' => 'Yes', 'type' => 'postback', 'payload' => 'YES']]]], 'sent', null, 200, null, null, 'botcake');

        $out = collect($this->getJson('/chatbot/inbox/thread?page_id=1001&psid=7001')->assertOk()->json('items'))->where('side', 'out')->pluck('messages')->values()->all();
        $this->assertSame([[['type' => 'text', 'text' => 'Order na po?'], ['type' => 'image', 'url' => 'https://img.example.test/a.jpg']], [['type' => 'text', 'text' => 'Botcake po']]], $out);
    }
}
