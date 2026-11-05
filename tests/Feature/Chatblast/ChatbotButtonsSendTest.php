<?php

namespace Tests\Feature\Chatblast;

use App\Models\ChatbotReply;
use App\Services\Chatbot\BotcakeOutbox;
use App\Services\Chatbot\BotcakeSendQueue;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowEngine;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\FlowRun;
use App\Services\Chatbot\GatewayProvider;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ang padala ng Send message na may button o quick reply: ang mga title na may variable (pinuputol sa 20), ang mensaheng
 * laging tatanggapin ng gateway, ang gate (services.gateway.buttons) sa wire, at ang talaan sa chatbot_replies.
 */
class ChatbotButtonsSendTest extends ChatblastTestCase
{
    private const GW = 'https://gw.example.test/api';
    private const APP2 = 'https://incepxion.example.test/api';
    private const BOTCAKE = 'https://botcake.io/api/public_api/v1/pages/1001/flows/send_content';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_25_100000_create_chatbot_waits_table.php',
            'database/migrations/2026_10_29_100000_create_botcake_send_requests_table.php',
            'database/migrations/2026_10_30_098000_add_raw_to_chatbot_replies.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
            'database/migrations/2026_11_11_100000_add_real_send_to_chatbot_replies.php',
            'database/migrations/2026_11_13_100000_widen_via_on_chatbot_replies.php',
            'database/migrations/2026_11_17_100000_add_route_to_chatbot_replies.php',
        ]]);
        config([
            'services.chatbot.replies_enabled' => true, 'services.botcake.async_sends' => false,
            'services.gateway.url' => self::GW, 'services.gateway.key' => 'gw_global_key',
            'services.gateway.urls' => 'incepxion=' . self::APP2, 'services.incepxionbot.url' => '',
            'services.gateway.buttons' => '',
        ]);
        $this->page('Page Uno', '1001');
        Http::fake([self::GW . '/*' => fn () => $this->gatewayAnswer ?? Http::response(['status' => 'queued'], 202), self::APP2 . '/*' => Http::response(['status' => 'queued'], 202),
            'https://botcake.io/*' => Http::response(['success' => true])]);
    }

    /** Ang sagot ng gateway sa susunod na tawag (null = 202 queued). */
    private $gatewayAnswer = null;

    private function gateway(?string $route = null): void
    {
        $s = app(PageSettings::class);
        $s->set('Page Uno', 'chatbot', 'provider', 'gateway', false, null, true);
        if ($route !== null) $s->set('Page Uno', 'chatbot', 'gateway_app', $route, false, null, true);
    }

    private function botcake(): void
    {
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'botcake', false, null, true);
    }

    /** Start → Send n2 (ang mga item) → (reply/continue) → Send n3 "Next". */
    private function graph(array $items, array $config = []): array
    {
        return FlowGraph::clean(['nodes' => [['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'send', 'config' => ['items' => $items] + $config],
            ['id' => 'n3', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Next']]]]],
            'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => !empty($config['wait']) ? 'reply' : 'continue', 'to' => 'n3']]]);
    }

    private function flow(array $graph, string $input = 'hi', array $botfields = [], bool $test = false): FlowRun
    {
        $run = new FlowRun(1, $graph, 'Page Uno', '1001', $botfields, new CustomerFields(1001, 7001), null, $input, 'Ana Cruz', '2026-11-05 10:00', $test);

        return app(FlowEngine::class)->run($run, app(BotcakeOutbox::class));
    }

    /** Ang mga mensahe ng bawat tawag sa gateway o sa Botcake, sa ayos. @return array<int, array> */
    private function wire(): array
    {
        return Http::recorded()->map(fn ($p) => $p[0])->map(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://botcake.io')
            ? $r['data']['content']['messages'] : $r['messages'])->values()->all();
    }

    private function bodies(): array
    {
        return Http::recorded()->map(fn ($p) => $p[0]->data())->values()->all();
    }

    private const YES_NO = [['type' => 'text', 'value' => 'Order na po?', 'buttons' => [
        ['title' => 'Yes', 'type' => 'postback', 'payload' => 'YES'], ['title' => 'No', 'type' => 'postback', 'payload' => 'NO'],
        ['title' => 'Shop', 'type' => 'web_url', 'url' => 'https://shop.example.test/p'],
    ]]];

    public function test_S_229_5_1_a_gateway_route_on_the_list_gets_the_049_shape_and_nothing_else_new(): void
    {
        config(['services.gateway.buttons' => 'forbeast,incepxion']);
        $this->gateway();
        $this->flow($this->graph(array_merge(self::YES_NO, [['type' => 'image', 'value' => 'https://img.example.test/a.jpg', 'ai' => true,
            'quick_replies' => [['title' => '{{product}} please', 'payload' => 'LONG']]]])), 'hi', ['product' => 'Seat cover set']);

        $this->assertSame(['psid', 'messages', 'messaging_type', 'client_ref'], array_keys($this->bodies()[0]));
        $this->assertSame([
            ['type' => 'buttons', 'text' => 'Order na po?', 'buttons' => [['title' => 'Yes', 'type' => 'postback', 'payload' => 'YES'],
                ['title' => 'No', 'type' => 'postback', 'payload' => 'NO'], ['title' => 'Shop', 'type' => 'web_url', 'url' => 'https://shop.example.test/p']]],
            ['type' => 'image', 'url' => 'https://img.example.test/a.jpg', 'quick_replies' => [['title' => 'Seat cover set pleas', 'payload' => 'LONG']]],
        ], $this->wire()[0], 'no ai, no cut mark: the 049 shape only');
    }

    public function test_S_229_5_2_a_botcake_page_gets_the_text_alone_and_the_reply_records_the_buttons_were_left_out(): void
    {
        config(['services.gateway.buttons' => 'forbeast']);
        $this->botcake();
        $this->flow($this->graph(self::YES_NO));

        $this->assertSame([['type' => 'text', 'text' => 'Order na po?']], $this->wire()[0]);
        $row = ChatbotReply::where('node_id', 'n2')->firstOrFail();
        $this->assertSame('botcake', $row->via);
        $this->assertTrue($row->content[0]['left_out']);
        $this->assertSame(['Yes', 'No', 'Shop'], array_column($row->content[0]['buttons'], 'title'));
    }

    public function test_S_229_5_3_a_gateway_route_not_on_the_list_is_treated_like_botcake(): void
    {
        config(['services.gateway.buttons' => 'forbeast']);
        $this->gateway('incepxion');
        $this->flow($this->graph(self::YES_NO));

        $this->assertSame([['type' => 'text', 'text' => 'Order na po?']], $this->wire()[0]);
        $this->assertStringStartsWith(self::APP2, Http::recorded()->first()[0]->url());
        $row = ChatbotReply::where('node_id', 'n2')->firstOrFail();
        $this->assertSame(['forbeast', 'incepxion'], [$row->via, $row->route]);
        $this->assertTrue($row->content[0]['left_out']);
    }

    public function test_S_229_5_4_a_botcake_page_and_a_waiting_postback_step_leave_by_reply_when_typed_and_the_skip_timer_is_set(): void
    {
        $this->botcake();
        $graph = $this->graph(self::YES_NO, ['wait' => true, 'save_to' => 'answer', 'skip_after' => ['amount' => 30, 'unit' => 'minutes']]);
        $this->flow($graph);
        $this->assertSame(1, DB::table('chatbot_waits')->where('node_id', 'n2')->count(), 'the skip timer is parked');

        $run = $this->flow($graph, 'sige po, order ako');
        $this->assertContains(['node' => 'n2', 'type' => 'send', 'port' => 'reply', 'note' => 'Reply received'], $run->path);
        $this->assertSame('sige po, order ako', (new CustomerFields(1001, 7001))->get('answer'));
    }

    public function test_S_229_5_5_the_provider_is_read_at_send_time(): void
    {
        config(['services.gateway.buttons' => 'forbeast']);
        $graph = $this->graph(self::YES_NO);
        $this->botcake();
        $this->flow($graph);
        $this->gateway();
        $this->flow($graph);

        [$first, , $second] = $this->wire(); // ang "Next" ng n3 ay nasa pagitan
        $this->assertSame([['type' => 'text', 'text' => 'Order na po?']], $first);
        $this->assertSame('buttons', $second[0]['type']);
        $this->assertSame(['botcake', 'forbeast'], ChatbotReply::where('node_id', 'n2')->orderBy('id')->pluck('via')->all());
    }

    public function test_S_229_5_6_a_gateway_4xx_is_recorded_failed_with_the_error_and_not_retried(): void
    {
        config(['services.gateway.buttons' => 'forbeast']);
        $this->gatewayAnswer = Http::response(['error' => ['code' => 'invalid_message', 'message' => 'buttons[0].title is too long']], 422);
        $this->gateway();
        $this->flow($this->graph(self::YES_NO));

        $row = ChatbotReply::where('node_id', 'n2')->firstOrFail();
        $this->assertSame(['failed', 422], [$row->status, $row->http]);
        $this->assertStringContainsString('buttons[0].title is too long', $row->error);
        Http::assertSentCount(2); // n2 once (no retry) and n3
    }

    public function test_S_229_5_7_the_buttons_survive_the_send_pool_and_the_sequence_pool(): void
    {
        config(['services.gateway.buttons' => 'forbeast', 'services.botcake.async_sends' => true]);
        \Illuminate\Support\Facades\Cache::put(\App\Services\Chatbot\BotcakeSendPool::beatKey(), ['at' => now()->getTimestamp()], 60); // a live pool
        $this->botcake();
        $this->flow($this->graph(self::YES_NO));
        $row = DB::table(BotcakeSendQueue::TABLE)->where('node_id', 'n2')->first();
        $queued = json_decode($row->payload, true)['messages'];
        $this->assertSame(['Yes', 'No', 'Shop'], array_column($queued[0]['buttons'], 'title'), 'queued as built');

        // Ang Botcake pool: text lang sa wire, at ang talaan ay may tala na naiwan ang mga button.
        $this->assertSame([['type' => 'text', 'text' => 'Order na po?']], BotcakeOutbox::wire($queued));
        BotcakeSendQueue::finishChatbot($row, null, ['status' => 'sent', 'error' => null, 'http' => 200]);
        $this->assertTrue(ChatbotReply::where('node_id', 'n2')->firstOrFail()->content[0]['left_out']);

        // Ang sequence pool (gateway): ang parehong naka-queue na mensahe sa GatewayProvider::request.
        $this->gateway();
        $q = (new GatewayProvider())->request('Page Uno', '1001', '7001', $queued, 'likha-ref', GatewayProvider::UPDATE);
        $this->assertSame('buttons', $q['body']['messages'][0]['type']);
        $this->assertSame(['Yes', 'No', 'Shop'], array_column($q['body']['messages'][0]['buttons'], 'title'));
    }

    public function test_S_229_7_3_a_real_send_records_the_buttons_with_filled_titles_via_and_route(): void
    {
        config(['services.gateway.buttons' => 'incepxion']);
        $this->gateway('incepxion');
        $this->flow($this->graph([['type' => 'text', 'value' => 'Hi {{first_name}}', 'quick_replies' => [['title' => 'Si {{first_name}} po', 'payload' => 'ME']]]]));

        $row = ChatbotReply::where('node_id', 'n2')->firstOrFail();
        $this->assertSame(['forbeast', 'incepxion', 'sent'], [$row->via, $row->route, $row->status]);
        $this->assertSame([['type' => 'text', 'text' => 'Hi Ana', 'quick_replies' => [['title' => 'Si Ana po', 'payload' => 'ME']], 'ai' => false]], $row->content);
    }

    public function test_S_229_1_7_filipino_and_emoji_titles_go_out_unchanged(): void
    {
        config(['services.gateway.buttons' => 'forbeast']);
        $this->gateway();
        $this->flow($this->graph([['type' => 'text', 'value' => 'Kukunin niyo po?', 'quick_replies' => [['title' => 'Oo po 🙏', 'payload' => 'OO_PO'], ['title' => 'Señor 👍🏽', 'payload' => 'SENOR']]]]));

        $this->assertSame(['Oo po 🙏', 'Señor 👍🏽'], array_column($this->wire()[0][0]['quick_replies'], 'title'));
    }

    public function test_S_229_1_10_a_link_that_is_not_https_is_never_sent(): void
    {
        config(['services.gateway.buttons' => 'forbeast']);
        $this->gateway();
        // Ang draft na naka-save sa save API (hindi pa dumaan sa Publish).
        $this->flow($this->graph([['type' => 'text', 'value' => 'Hi', 'buttons' => [['title' => 'Bad', 'type' => 'web_url', 'url' => 'javascript:alert(1)'],
            ['title' => 'Plain', 'type' => 'web_url', 'url' => 'http://shop.example.test'], ['title' => 'Good', 'type' => 'web_url', 'url' => 'https://shop.example.test']]]]));

        $this->assertSame([['title' => 'Good', 'type' => 'web_url', 'url' => 'https://shop.example.test']], $this->wire()[0][0]['buttons']);
        $this->assertSame(['Good'], array_column(ChatbotReply::where('node_id', 'n2')->firstOrFail()->content[0]['buttons'], 'title'));
    }

    public function test_S_229_2_5_a_title_that_gets_long_after_filling_is_cut_to_20_and_the_cut_is_noted(): void
    {
        config(['services.gateway.buttons' => 'forbeast']);
        $this->gateway();
        $this->flow($this->graph([['type' => 'text', 'value' => 'Hi', 'buttons' => [['title' => 'Buy {{product}}', 'type' => 'postback', 'payload' => 'BUY']]]]), 'hi', ['product' => 'Reflective sticker roll']);

        $this->assertSame([['title' => 'Buy Reflective stick', 'type' => 'postback', 'payload' => 'BUY']], $this->wire()[0][0]['buttons']);
        $this->assertSame(['title' => 'Buy Reflective stick', 'type' => 'postback', 'payload' => 'BUY', 'cut' => true], ChatbotReply::where('node_id', 'n2')->firstOrFail()->content[0]['buttons'][0]);
    }

    public function test_S_229_2_7_text_and_titles_that_break_the_limits_only_after_filling_still_make_a_valid_message(): void
    {
        config(['services.gateway.buttons' => 'forbeast']);
        $this->gateway();
        $this->flow($this->graph([
            ['type' => 'text', 'value' => '{{details}}', 'buttons' => [['title' => '{{a}}', 'type' => 'postback', 'payload' => 'A'],
                ['title' => '{{b}}', 'type' => 'postback', 'payload' => 'B'], ['title' => '{{empty}}', 'type' => 'postback', 'payload' => 'E']]],
            ['type' => 'text', 'value' => 'Size?', 'quick_replies' => [['title' => '{{a}}', 'payload' => 'X'], ['title' => 'Other', 'payload' => 'X']]],
        ]), 'hi', ['details' => str_repeat('x', 700), 'a' => 'Same', 'b' => 'same', 'empty' => '']);

        [$m, $q] = $this->wire()[0];
        $this->assertSame(640, mb_strlen($m['text']));
        $this->assertSame([['title' => 'Same', 'type' => 'postback', 'payload' => 'A']], $m['buttons'], 'a title that repeats and an empty title dropped');
        $this->assertSame([['title' => 'Same', 'payload' => 'X']], $q['quick_replies'], 'a payload that repeats dropped');
        $this->assertTrue(ChatbotReply::where('node_id', 'n2')->firstOrFail()->content[0]['text_cut']);
    }

    public function test_with_gateway_buttons_empty_nothing_changes_on_the_wire_for_any_page(): void
    {
        $old = [['type' => 'text', 'value' => 'Hello {{first_name}}', 'ai' => true], ['type' => 'image', 'value' => 'https://img.example.test/a.jpg']];
        $expected = [['type' => 'text', 'text' => 'Hello Ana'], ['type' => 'image', 'url' => 'https://img.example.test/a.jpg']];
        foreach (['botcake' => fn () => $this->botcake(), 'forbeast' => fn () => $this->gateway(), 'incepxion' => fn () => $this->gateway('incepxion')] as $page => $switch) {
            $switch();
            $this->flow($this->graph($old));
            $this->flow($this->graph(array_merge(self::YES_NO, [['type' => 'text', 'value' => 'Size?', 'quick_replies' => [['title' => 'M', 'payload' => 'M']]]])));
        }
        $wire = $this->wire();
        // Kada page: ang lumang graph, ang "Next", ang graph na may button, ang "Next".
        foreach ([0, 4, 8] as $i) {
            $this->assertSame($expected, $wire[$i], 'an old graph goes out as before');
            $this->assertSame([['type' => 'text', 'text' => 'Order na po?'], ['type' => 'text', 'text' => 'Size?']], $wire[$i + 2], 'buttons and quick replies left out');
        }
        $this->assertSame([['type' => 'text', 'text' => 'Hello Ana', 'ai' => true], ['type' => 'image', 'url' => 'https://img.example.test/a.jpg', 'ai' => false]],
            ChatbotReply::where('node_id', 'n2')->orderBy('id')->first()->content, 'the old graph\'s record has no new key');
    }
}
