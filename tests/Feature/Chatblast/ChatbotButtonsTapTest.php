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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ang tap ng customer sa button o quick reply, mula sa event ng gateway hanggang sa takbo: dumarating ito bilang chat
 * (ang title ang text), kaya ang naghihintay na step ay lumalabas sa "reply" gaya ng sagot na isinulat. Ang late na tap
 * ay bagong mensahe. Ang karamihan ay ugali na ng code bago ang mga button (characterisation).
 */
class ChatbotButtonsTapTest extends ChatblastTestCase
{
    private const PSID = '7001';
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
        $this->flow = $this->link($this->graph(['amount' => 30, 'unit' => 'minutes']));
    }

    /**
     * Start → Send n2 "Order na po?" (Yes, No, Shop; naghihintay, save_to ANSWER) → reply → Condition n3 (ANSWER is Yes)
     * → row 1: Send n4 "Salamat po" / no: Send n5 "Sige po"; skipped → Send n6 "Nandito lang po kami".
     */
    private function graph(?array $skip): array
    {
        return FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Order na po?', 'buttons' => [
                ['title' => 'Yes', 'type' => 'postback', 'payload' => 'ORDER_NOW_PROMO'], ['title' => 'No', 'type' => 'postback', 'payload' => 'NO'],
                ['title' => 'Shop', 'type' => 'web_url', 'url' => 'https://shop.example.test/']]]], 'wait' => true, 'save_to' => 'ANSWER'] + ($skip ? ['skip_after' => $skip] : [])],
            ['id' => 'n3', 'type' => 'condition', 'config' => ['rows' => [['variable' => 'ANSWER', 'rule' => 'is', 'value' => 'Yes']]]],
            ['id' => 'n4', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Salamat po']]]],
            ['id' => 'n5', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Sige po']]]],
            ['id' => 'n6', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Nandito lang po kami']]]],
        ], 'edges' => array_merge([['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'reply', 'to' => 'n3'],
            ['from' => 'n3', 'port' => 'row_1', 'to' => 'n4'], ['from' => 'n3', 'port' => 'no', 'to' => 'n5']], $skip ? [['from' => 'n2', 'port' => 'skipped', 'to' => 'n6']] : [])]);
    }

    private function link(array $graph): ChatbotFlow
    {
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => $graph, 'published_at' => now()]);
        app(ChatbotPage::class)->link('Page Uno', $flow);

        return $flow;
    }

    /** Isang event ng gateway (gaya ng sa Forbeast), iniimbak; ang chat na naimbak (null = hindi, hal. ulit). */
    private function event(array $e): ?BotcakeChat
    {
        $before = (int) BotcakeChat::max('id');
        (new HandleGatewayEvent(HandleGatewayEvent::fields($e + ['kind' => 'event', 'event_id' => random_int(1, 999999), 'page_id' => '1001', 'psid' => self::PSID,
            'type' => 'message', 'timestamp' => now()->getTimestampMs()])))->handle(app(PageSettings::class), app(\App\Services\BotcakeChatGuard::class));

        return BotcakeChat::where('id', '>', $before)->orderByDesc('id')->first();
    }

    private function runChat(?BotcakeChat $chat): string
    {
        return app(ChatbotRunner::class)->handle($chat->id);
    }

    private function say(string $text): BotcakeChat
    {
        return $this->event(['mid' => 'm_' . uniqid(), 'text' => $text]);
    }

    private function postback(string $title, string $payload, ?string $mid = null): ?BotcakeChat
    {
        return $this->event(['type' => 'postback', 'mid' => $mid ?? 'm_' . uniqid(), 'postback' => ['title' => $title, 'payload' => $payload]]);
    }

    /** Ang tanong: "Hi" → n2 nagpadala at naghihintay. */
    private function ask(): void
    {
        $this->assertSame('done', $this->runChat($this->say('Hi')));
        $this->assertSame($this->flow->id . ':n2', (new CustomerFields(1001, (int) self::PSID))->get(CustomerFields::WAIT));
    }

    private function answer(): ?string
    {
        return (new CustomerFields(1001, (int) self::PSID))->get('answer');
    }

    /** @return array<int, string> ang node ng bawat padala, sa ayos */
    private function sent(): array
    {
        return ChatbotReply::where('kind', 'send')->orderBy('id')->pluck('node_id')->all();
    }

    public function test_S_229_4_1_a_postback_tap_leaves_by_reply_and_saves_the_title(): void
    {
        $this->ask();
        $this->assertSame('done', $this->runChat($this->postback('Yes', 'ORDER_NOW_PROMO')));

        $this->assertSame('Yes', $this->answer());
        $this->assertSame(['n2', 'n4'], $this->sent());
    }

    public function test_S_229_4_2_a_quick_reply_tap_leaves_by_reply_and_saves_the_title(): void
    {
        $this->ask();
        $chat = $this->event(['mid' => 'm_qr1', 'text' => 'Yes', 'quick_reply_payload' => 'YES']);
        $this->runChat($chat);

        $this->assertSame('YES', $chat->payload['quick_reply']);
        $this->assertSame('Yes', $this->answer());
        $this->assertSame(['n2', 'n4'], $this->sent());
    }

    public function test_S_229_4_3_a_condition_on_the_saved_field_takes_the_right_branch(): void
    {
        $this->ask();
        $this->runChat($this->postback('No', 'NO'));

        $this->assertSame('No', $this->answer());
        $this->assertSame(['n2', 'n5'], $this->sent());
    }

    public function test_S_229_4_4_a_link_button_sends_no_event_and_the_step_keeps_waiting(): void
    {
        $this->ask();
        // Ang link ay binubuksan sa browser ng customer: walang event ng Messenger. Lumipas ang oras, wala pang skip.
        $this->travel(10)->minutes();

        $this->assertSame($this->flow->id . ':n2', (new CustomerFields(1001, (int) self::PSID))->get(CustomerFields::WAIT));
        $this->assertSame(['n2'], $this->sent());
        $this->assertSame(1, DB::table('chatbot_waits')->count());
    }

    public function test_S_229_4_6_typing_instead_of_tapping_leaves_by_reply_with_the_typed_text(): void
    {
        $this->ask();
        $this->runChat($this->say('pa-reserve po muna'));

        $this->assertSame('pa-reserve po muna', $this->answer());
        $this->assertSame(['n2', 'n5'], $this->sent());
    }

    public function test_S_229_4_7_the_same_tap_delivered_twice_moves_the_flow_once(): void
    {
        $this->ask();
        $first = $this->postback('Yes', 'ORDER_NOW_PROMO', 'm_tap_1');
        $this->assertNull($this->postback('Yes', 'ORDER_NOW_PROMO', 'm_tap_1'), 'the repeat is not stored');
        $this->runChat($first);

        $this->assertSame(['n2', 'n4'], $this->sent());
        $this->assertSame(1, BotcakeChat::where('message_id', 'm_tap_1')->count());
    }

    public function test_S_229_4_8_a_postback_whose_title_and_payload_differ_saves_the_title_and_the_inbox_shows_the_title(): void
    {
        $this->ask();
        $chat = $this->postback('Yes', 'ORDER_NOW_PROMO');
        $this->runChat($chat);

        $this->assertSame(['Yes', 'ORDER_NOW_PROMO'], [$chat->message, $chat->payload['postback']]);
        $this->assertSame('Yes', $this->answer());
        $this->actingAs($this->user());
        $in = collect($this->getJson('/chatbot/inbox/thread?page_id=1001&psid=' . self::PSID)->assertOk()->json('items'))->where('side', 'in')->pluck('text')->values()->all();
        $this->assertSame(['Hi', 'Yes'], $in);
    }

    public function test_S_229_8_1_a_tap_while_the_wait_is_open_under_the_23_hour_cap_leaves_by_reply(): void
    {
        $this->flow->update(['graph' => $this->graph(['amount' => 23, 'unit' => 'hours'])]);
        $this->ask();
        $this->travel(22)->hours();
        $this->runChat($this->postback('Yes', 'ORDER_NOW_PROMO'));

        $this->assertSame(['n2', 'n4'], $this->sent());
    }

    public function test_S_229_8_2_a_tap_after_the_wait_skipped_is_a_new_message_with_no_second_exit(): void
    {
        $this->ask();
        $this->travel(31)->minutes();
        $this->assertSame('done', app(ChatbotRunner::class)->skip((int) DB::table('chatbot_waits')->value('id')));
        $this->assertSame(['n2', 'n6'], $this->sent());

        $this->runChat($this->postback('Yes', 'ORDER_NOW_PROMO'));
        // Bagong mensahe: mula sa Start (n2 ulit at naghihintay), hindi ang reply ng lumang step.
        $this->assertSame(['n2', 'n6', 'n2'], $this->sent());
        $this->assertNull($this->answer());
    }

    public function test_S_229_8_3_a_tap_for_a_step_that_was_deleted_starts_over_with_no_error_and_no_stuck_wait(): void
    {
        $this->ask();
        // Na-republish: wala na ang n2 (ang tanong ay n7 na, walang hinihintay).
        $this->flow->update(['graph' => FlowGraph::clean(['nodes' => [['id' => 'n1', 'type' => 'start'], ['id' => 'n7', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Bago po']]]]],
            'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n7']]])]);
        $this->assertSame('done', $this->runChat($this->postback('Yes', 'ORDER_NOW_PROMO')));

        $this->assertSame(['n2', 'n7'], $this->sent());
        $this->assertNull((new CustomerFields(1001, (int) self::PSID))->get(CustomerFields::WAIT));
    }

    public function test_S_229_8_4_a_tap_while_another_run_holds_the_lock_is_kept_and_runs_after(): void
    {
        $this->ask();
        $chat = $this->postback('Yes', 'ORDER_NOW_PROMO');
        $lock = Cache::lock('chatbot-run:1001:' . self::PSID, 60);
        $this->assertTrue($lock->get());
        $this->assertSame('locked', $this->runChat($chat), 'the job is released back to the queue');
        $lock->release();

        $this->assertSame('done', $this->runChat($chat));
        $this->assertSame(['n2', 'n4'], $this->sent());
    }

    public function test_S_229_8_5_a_tap_at_the_moment_the_wait_skips_exactly_one_wins(): void
    {
        $this->ask();
        $this->travel(31)->minutes();
        $chat = $this->postback('Yes', 'ORDER_NOW_PROMO');
        // Ang tick ng skip ay nauna sa job ng tap: ang tap na hindi pa natatakbo ang nananaig.
        $this->assertSame('waiting', app(ChatbotRunner::class)->skip((int) DB::table('chatbot_waits')->value('id')));
        $this->runChat($chat);
        $this->assertSame('gone', app(ChatbotRunner::class)->skip((int) DB::table('chatbot_waits')->value('id') ?? 0));

        $this->assertSame(['n2', 'n4'], $this->sent());
    }
}
