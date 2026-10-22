<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Models\ChatbotSequence;
use App\Services\Chatbot\ChatbotSequences;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Ang After Order sequence: hindi humihinto kapag sumagot ang customer, at hindi nagpapadala lampas 24 oras mula sa huling
 * mensahe ng customer.
 */
class ChatbotAfterOrderSequenceTest extends ChatblastTestCase
{
    private const SEND = 'https://botcake.io/api/public_api/v1/pages/1001/flows/send_content';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_25_100000_create_chatbot_waits_table.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true]);
        $this->page('Page Uno', '1001');
        Http::fake([self::SEND => Http::response(['success' => true])]);
        $this->travelTo('2026-10-20 10:00:00');
    }

    private function flow(string $text): ChatbotFlow
    {
        $flow = ChatbotFlow::create(['name' => 'Flow ' . $text, 'graph' => FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => $text]]]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]])]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);

        return $flow->fresh();
    }

    private function afterOrder(string $text = '{{After Order 1}}'): ChatbotSequence
    {
        $seq = ChatbotSequence::create(['name' => 'After Order', 'active' => true, 'exit_on_reply' => false]);
        $seq->steps()->create(['position' => 1, 'delay_amount' => 0, 'delay_unit' => 'minutes', 'flow_id' => $this->flow($text)->id, 'active' => true]);

        return $seq;
    }


    private function chat(string $psid = '7001', ?string $at = null): void
    {
        BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => $psid, 'name' => 'Ana Cruz', 'message' => 'Hi', 'payload' => [], 'received_at' => $at ?? now()]);
    }

    private function subs(): array
    {
        return DB::table('chatbot_sequence_subscriptions')->orderBy('id')->get(['page_id', 'psid', 'status', 'exit_reason'])->map(fn ($s) => array_values((array) $s))->all();
    }

    private function sent(): array
    {
        return Http::recorded(fn (HttpRequest $r) => $r->url() === self::SEND)->map(fn ($p) => $p[0]->data()['data']['content']['messages'][0]['text'] ?? '')->values()->all();
    }

    public function test_S_115_3_a_reply_does_not_exit_a_sequence_that_keeps_going_but_exits_a_default_one(): void
    {
        $keeps = $this->afterOrder();
        $exits = ChatbotSequence::create(['name' => 'Default', 'active' => true, 'exit_on_reply' => true]);
        $exits->steps()->create(['position' => 1, 'delay_amount' => 1, 'delay_unit' => 'days', 'flow_id' => $this->flow('x')->id, 'active' => true]);
        $svc = app(ChatbotSequences::class);
        $svc->subscribe($keeps->id, 1001, 7001, 'order');
        $svc->subscribe($exits->id, 1001, 7001, 'flow');

        $svc->exitOnReply(1001, 7001);

        $this->assertSame(['active', 'unsubscribed'], DB::table('chatbot_sequence_subscriptions')->orderBy('sequence_id')->pluck('status')->all());
    }

    public function test_S_115_4_a_step_past_24_hours_sends_nothing_and_ends_the_subscription_outside_24h(): void
    {
        $seq = $this->afterOrder('Hello');
        $this->chat('7001', '2026-10-19 09:59:00');
        app(ChatbotSequences::class)->subscribe($seq->id, 1001, 7001, 'order');

        Artisan::call('chatbot:sequence-tick');

        $this->assertSame([], $this->sent());
        $this->assertSame([[1001, 7001, 'unsubscribed', 'outside 24h']], $this->subs());
    }

    public function test_S_115_5_an_empty_page_value_sends_no_blank_message(): void
    {
        $seq = $this->afterOrder();
        $this->chat();
        app(ChatbotSequences::class)->subscribe($seq->id, 1001, 7001, 'order');

        Artisan::call('chatbot:sequence-tick');

        $this->assertSame([], $this->sent());
    }
}
