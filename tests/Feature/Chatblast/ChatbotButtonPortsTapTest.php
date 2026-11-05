<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\HandleGatewayEvent;
use App\Jobs\RunChatbotFlow;
use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Models\ChatbotReply;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ang tap na sumusunod sa exit ng button: ang payload ng chat (galing sa gateway lang) laban sa payload ng button sa
 * graph. Ang walang wire, ang hindi kilalang payload, ang isinulat at ang chat ng Botcake ay sa "reply" pa rin.
 */
class ChatbotButtonPortsTapTest extends ChatblastTestCase
{
    private const PSID = '7101';
    private const GW = 'https://gw.example.test/api';

    private ChatbotFlow $flow;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([RunChatbotFlow::class]);
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_inbox_indexes.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_25_100000_create_chatbot_waits_table.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
            'database/migrations/2026_10_29_100000_create_botcake_send_requests_table.php',
            'database/migrations/2026_10_30_098000_add_raw_to_chatbot_replies.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
            'database/migrations/2026_11_11_100000_add_real_send_to_chatbot_replies.php',
            'database/migrations/2026_11_13_100000_widen_via_on_chatbot_replies.php',
            'database/migrations/2026_11_17_100000_add_route_to_chatbot_replies.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true, 'services.botcake.async_sends' => false,
            'services.gateway.url' => self::GW, 'services.gateway.key' => 'gw_key', 'services.gateway.webhook_secret' => 'gw-secret',
            'services.gateway.typing' => false, 'services.incepxionbot.url' => '', 'services.gateway.buttons' => 'forbeast']);
        Http::fake([self::GW . '/*' => Http::response(['status' => 'queued'], 202), 'https://botcake.io/*' => Http::response(['success' => true])]);
        $this->page('Page Uno', '1001');
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'gateway', false, null, true);
        $this->travelTo('2026-11-05 10:00:00');
        $this->flow = ChatbotFlow::create(['name' => 'Main', 'graph' => $this->graph(), 'published_at' => now()]);
        app(ChatbotPage::class)->link('Page Uno', $this->flow);
    }

    /**
     * Start → Send n2 (naghihintay, save_to ANSWER): "Ano po?" may "Order na" (→ n4), "Magtanong" (walang wire) at "Site";
     * saka "O kaya" may quick reply "Mamaya" (→ n7). reply → n5 "Sige po"; skipped (30 min) → n6.
     */
    private function graph(string $orderPayload = 'ORDER_NA'): array
    {
        return FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [
                ['type' => 'text', 'value' => 'Ano po?', 'buttons' => [
                    ['title' => 'Order na', 'type' => 'postback', 'payload' => $orderPayload, 'id' => 'ordr0001'],
                    ['title' => 'Magtanong', 'type' => 'postback', 'payload' => 'MAGTANONG', 'id' => 'magt0001'],
                    ['title' => 'Site', 'type' => 'web_url', 'url' => 'https://shop.example.test/', 'id' => 'site0001']]],
                ['type' => 'text', 'value' => 'O kaya', 'quick_replies' => [['title' => 'Mamaya', 'payload' => 'MAMAYA', 'id' => 'mmya0001']]],
            ], 'wait' => true, 'save_to' => 'ANSWER', 'skip_after' => ['amount' => 30, 'unit' => 'minutes']]],
            ['id' => 'n4', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Salamat sa order']]]],
            ['id' => 'n5', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Sige po']]]],
            ['id' => 'n6', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Nandito lang po kami']]]],
            ['id' => 'n7', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Sige, mamaya po']]]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'tap_ordr0001', 'to' => 'n4'],
            ['from' => 'n2', 'port' => 'tap_mmya0001', 'to' => 'n7'], ['from' => 'n2', 'port' => 'reply', 'to' => 'n5'], ['from' => 'n2', 'port' => 'skipped', 'to' => 'n6']]]);
    }

    /** Ang graph gaya ng bago ang pagbabagong ito: walang id, walang wire ng tap. */
    private function withoutIds(array $graph): array
    {
        foreach ($graph['nodes'] as &$n) foreach ($n['config']['items'] ?? [] as $k => $i) foreach (['buttons', 'quick_replies'] as $key) {
            if (isset($i[$key])) $n['config']['items'][$k][$key] = array_map(fn ($t) => array_diff_key($t, ['id' => 1]), $i[$key]);
        }
        $graph['edges'] = array_values(array_filter($graph['edges'], fn ($e) => !str_starts_with($e['port'], 'tap_')));

        return FlowGraph::clean($graph);
    }

    private function event(array $e, string $psid = self::PSID): ?BotcakeChat
    {
        $before = (int) BotcakeChat::max('id');
        (new HandleGatewayEvent(HandleGatewayEvent::fields($e + ['kind' => 'event', 'event_id' => random_int(1, 999999), 'page_id' => '1001', 'psid' => $psid,
            'type' => 'message', 'timestamp' => now()->getTimestampMs()])))->handle(app(PageSettings::class), app(\App\Services\BotcakeChatGuard::class));

        return BotcakeChat::where('id', '>', $before)->orderByDesc('id')->first();
    }

    private function runChat(?BotcakeChat $chat): string
    {
        return app(ChatbotRunner::class)->handle($chat->id);
    }

    private function say(string $text, string $psid = self::PSID): BotcakeChat
    {
        return $this->event(['mid' => 'm_' . uniqid(), 'text' => $text], $psid);
    }

    private function postback(string $title, string $payload, ?string $mid = null): ?BotcakeChat
    {
        return $this->event(['type' => 'postback', 'mid' => $mid ?? 'm_' . uniqid(), 'postback' => ['title' => $title, 'payload' => $payload]]);
    }

    private function ask(string $psid = self::PSID): void
    {
        $this->assertSame('done', $this->runChat($this->say('Hi', $psid)));
        $this->assertSame($this->flow->id . ':n2', (new CustomerFields(1001, (int) $psid))->get(CustomerFields::WAIT));
    }

    private function answer(): ?string
    {
        return (new CustomerFields(1001, (int) self::PSID))->get('answer');
    }

    /** @return array<int, string> */
    private function sent(): array
    {
        return ChatbotReply::where('kind', 'send')->orderBy('id')->pluck('node_id')->all();
    }

    public function test_S_237_2_1_tapping_a_wired_button_continues_at_its_step_and_saves_the_title(): void
    {
        $this->ask();
        $this->assertSame('done', $this->runChat($this->postback('Order na', 'ORDER_NA')));

        $this->assertSame(['n2', 'n4'], $this->sent());
        $this->assertSame('Order na', $this->answer());
    }

    public function test_S_237_2_2_tapping_a_wired_quick_reply_continues_at_its_step_and_saves_the_title(): void
    {
        $this->ask();
        $this->runChat($this->event(['mid' => 'm_qr', 'text' => 'Mamaya', 'quick_reply_payload' => 'MAMAYA']));

        $this->assertSame(['n2', 'n7'], $this->sent());
        $this->assertSame('Mamaya', $this->answer());
    }

    public function test_S_237_2_3_typing_instead_of_tapping_leaves_by_reply(): void
    {
        $this->ask();
        $this->runChat($this->say('Order na po'));

        $this->assertSame(['n2', 'n5'], $this->sent());
        $this->assertSame('Order na po', $this->answer());
    }

    public function test_S_237_2_4_tapping_a_button_whose_port_has_no_wire_leaves_by_reply(): void
    {
        $this->ask();
        $this->runChat($this->postback('Magtanong', 'MAGTANONG'));

        $this->assertSame(['n2', 'n5'], $this->sent());
        $this->assertSame('Magtanong', $this->answer());
    }

    public function test_S_237_2_5_a_tap_with_an_unknown_payload_leaves_by_reply(): void
    {
        $this->ask();
        $this->runChat($this->postback('Order na', 'OLD_PROMO_2025'));

        $this->assertSame(['n2', 'n5'], $this->sent());
        $this->assertSame('Order na', $this->answer());
    }

    public function test_S_237_2_6_a_tap_after_the_wait_ended_is_a_new_message(): void
    {
        $this->ask();
        $this->travel(31)->minutes();
        $this->assertSame('done', app(ChatbotRunner::class)->skip((int) DB::table('chatbot_waits')->value('id')));
        $this->runChat($this->postback('Order na', 'ORDER_NA'));

        // Mula sa Start ulit (n2 at naghihintay), hindi ang exit ng lumang tanong.
        $this->assertSame(['n2', 'n6', 'n2'], $this->sent());
        $this->assertNull($this->answer());
    }

    public function test_S_237_2_7_the_same_tap_delivered_twice_moves_the_run_once(): void
    {
        $this->ask();
        $first = $this->postback('Order na', 'ORDER_NA', 'm_tap_237');
        $this->assertNull($this->postback('Order na', 'ORDER_NA', 'm_tap_237'), 'the repeat is not stored');
        $this->runChat($first);

        $this->assertSame(['n2', 'n4'], $this->sent());
    }

    public function test_S_237_2_8_a_botcake_chat_whose_params_look_like_a_tap_leaves_by_reply(): void
    {
        $this->ask();
        // Ang params ng webhook ng Botcake ay napupunta sa payload: hindi ito tap ng gateway.
        $chat = BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => self::PSID, 'name' => 'Ana Cruz', 'message' => 'Order na',
            'message_id' => 'bc_237', 'payload' => ['postback' => 'ORDER_NA', 'quick_reply' => 'MAMAYA'], 'received_at' => now()]);
        $this->runChat($chat);

        $this->assertSame(['n2', 'n5'], $this->sent());
    }

    public function test_S_237_2_9_a_graph_payload_with_spaces_still_matches_the_trimmed_tap(): void
    {
        $this->flow->update(['graph' => $this->graph(' ORDER_NA ')]);
        $this->ask();
        $this->runChat($this->postback('Order na', ' ORDER_NA '));

        $this->assertSame(['n2', 'n4'], $this->sent());
    }

    public function test_S_237_2_10_ids_never_reach_the_gateway_or_the_reply_record(): void
    {
        $this->ask('7102');
        $this->flow->update(['graph' => $this->withoutIds($this->graph())]);
        $this->ask('7103');

        $bodies = Http::recorded()->map(fn ($p) => $p[0])->filter(fn (HttpRequest $r) => str_starts_with($r->url(), self::GW))->map(fn (HttpRequest $r) => $r['messages'])->values();
        $content = ChatbotReply::where('kind', 'send')->orderBy('id')->pluck('content')->map(fn ($c) => json_encode($c))->values();
        $this->assertCount(2, $bodies);
        $this->assertSame($bodies[1], $bodies[0]);
        $this->assertSame($content[1], $content[0]);
        $this->assertStringNotContainsString('ordr0001', json_encode($bodies[0]) . $content[0]);
    }

    public function test_S_237_3_5_a_payload_shared_by_two_messages_takes_the_first_exit_or_reply_when_only_the_second_is_wired(): void
    {
        $shared = fn (array $wired) => FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [
                ['type' => 'text', 'value' => 'Una?', 'buttons' => [['title' => 'Oo', 'type' => 'postback', 'payload' => 'OO', 'id' => 'oo000001']]],
                ['type' => 'text', 'value' => 'Ikalawa?', 'buttons' => [['title' => 'Oo po', 'type' => 'postback', 'payload' => 'OO', 'id' => 'oo000002']]]], 'wait' => true]],
            ['id' => 'n4', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Una']]]],
            ['id' => 'n5', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Sige po']]]],
            ['id' => 'n7', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Ikalawa']]]],
        ], 'edges' => array_merge([['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'reply', 'to' => 'n5']], $wired)]);

        $this->flow->update(['graph' => $shared([['from' => 'n2', 'port' => 'tap_oo000001', 'to' => 'n4'], ['from' => 'n2', 'port' => 'tap_oo000002', 'to' => 'n7']])]);
        $this->ask();
        $this->runChat($this->postback('Oo po', 'OO'));
        $this->assertSame(['n2', 'n4'], $this->sent());

        // Ang una ang laging sinusundan (gaya ng paalala ng Publish): walang wire ito, kaya sa reply, hindi sa ikalawa.
        $this->flow->update(['graph' => $shared([['from' => 'n2', 'port' => 'tap_oo000002', 'to' => 'n7']])]);
        $this->ask();
        $this->runChat($this->postback('Oo po', 'OO'));
        $this->assertSame(['n2', 'n4', 'n2', 'n5'], $this->sent());
    }

    public function test_S_237_3_3_a_flow_saved_before_this_change_sends_taps_to_reply(): void
    {
        $this->flow->update(['graph' => $this->withoutIds($this->graph())]);
        $this->ask();
        $this->runChat($this->postback('Order na', 'ORDER_NA'));

        $this->assertSame(['n2', 'n5'], $this->sent());
        $this->assertSame('Order na', $this->answer());
    }
}
