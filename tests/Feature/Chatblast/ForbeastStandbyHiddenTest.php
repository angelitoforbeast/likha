<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotReply;
use App\Services\Chatbot\ChatbotResults;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowEngine;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\FlowRun;
use App\Services\Chatbot\TestOutbox;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * Ang standby na kopya ng Botcake (payload._standby) ay hindi nakikita saanman maliban sa bantay at sa lipat: hindi sa AI
 * history, Inbox, Results at iba pang basa ng botcake_chats; at binubura ng bantay ang luma nang standby.
 */
class ForbeastStandbyHiddenTest extends ChatblastTestCase
{
    private const GW = 'https://gw.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_inbox_indexes.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_29_100000_create_botcake_send_requests_table.php',
        ]]);
        config(['services.gateway.url' => self::GW, 'services.gateway.key' => 'gw_key_test', 'services.openai.key' => 'sk-test-not-real']);
        $this->page('Page Uno', '1001');
    }

    /** Isang chat ng customer; $standby = ang kopya ng Botcake habang nasa Forbeast ang page. */
    private function chat(string $message, bool $standby, string $psid = '7001', $at = null): BotcakeChat
    {
        return BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => $psid, 'name' => 'Ana Cruz', 'message' => $message,
            'payload' => $standby ? ['_standby' => true] : ['source' => 'gateway'], 'received_at' => $at ?? now()]);
    }

    public function test_S_117_13_a_standby_twin_is_not_in_the_ai_history(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);
        $this->chat('Available pa po?', false, '7001', now()->subMinute());
        $this->chat('Available pa po?', true, '7001', now()->subMinute());
        $current = $this->chat('Magkano?', false);
        $graph = FlowGraph::clean(['nodes' => [['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'ai', 'config' => ['system' => 'Be nice']]],
            'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]]);
        $run = new FlowRun(1, $graph, 'Page Uno', '1001', [], new CustomerFields(1001, 7001), $current->id, 'Magkano?', 'Ana Cruz', '2026-10-19 10:00');

        app(FlowEngine::class)->run($run, new TestOutbox());

        Http::assertSent(fn (HttpRequest $r) => $r['messages'] === [['role' => 'system', 'content' => 'Be nice'],
            ['role' => 'user', 'content' => 'Available pa po?'], ['role' => 'user', 'content' => 'Magkano?']]);
    }

    public function test_S_117_14_a_standby_twin_is_not_in_the_inbox_list_nor_the_thread(): void
    {
        $this->actingAs($this->user());
        $this->chat('Real one', false, '7001', now()->subMinutes(3));
        $this->chat('Real one', true, '7001', now()->subMinutes(2));
        $this->chat('Only standby', true, '7002', now()->subMinute());

        $this->assertSame(['7001'], array_column($this->getJson('/chatbot/inbox/conversations?page_id=1001')->assertOk()->json('rows'), 'psid'));
        $items = $this->getJson('/chatbot/inbox/thread?page_id=1001&psid=7001')->assertOk()->json('items');
        $this->assertSame(['Real one'], array_column($items, 'text'));
        $this->assertSame([], $this->getJson('/chatbot/inbox/thread?page_id=1001&psid=7002')->assertOk()->json('items'));
    }

    public function test_S_117_15_a_standby_twin_is_not_counted_in_results_nor_the_chat_stats(): void
    {
        $this->actingAs($this->user());
        $at = now()->subHour();
        $this->chat('Real', false, '7001', $at);
        $this->chat('Real', true, '7001', $at);
        $this->chat('Only standby', true, '7002', $at);
        $start = $at->copy()->subDay();
        $f = ['page_id' => null, 'via' => null];
        $chats = new \ReflectionMethod(ChatbotResults::class, 'chats');
        $chats->setAccessible(true);

        $this->assertSame(1, $chats->invoke(app(ChatbotResults::class), $f, $start, now()->addDay()));
        $row = collect($this->getJson('/chatbot/data')->assertOk()->json('rows'))->firstWhere('page', 'Page Uno');
        $this->assertSame(1, $row['today']);
    }

    public function test_S_117_16_the_watcher_prunes_standby_older_than_twenty_minutes_and_keeps_the_rest(): void
    {
        Http::fake();
        $this->chat('Old standby', true, '7001', now()->subMinutes(21));
        $this->chat('Fresh standby', true, '7001', now()->subMinutes(19));
        $this->chat('Old real', false, '7001', now()->subMinutes(30));

        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();

        $this->assertSame(['Fresh standby', 'Old real'], BotcakeChat::orderBy('id')->get()->pluck('message')->sort()->values()->all());
    }
}
