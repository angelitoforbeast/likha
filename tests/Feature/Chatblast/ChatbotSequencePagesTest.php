<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Models\ChatbotSequence;
use App\Services\Chatbot\ChatbotEvents;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\Chatbot\FlowGraph;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Ang mga sequence mula sa labas ng tick: ang step na "Sequence" sa flow, ang /chatbot/sequences (listahan, editor,
 * mga subscriber) at ang "Subscribed sequences" sa inbox. Fake na HTTP lang.
 */
class ChatbotSequencePagesTest extends ChatblastTestCase
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

    private function published(array $nodes, array $edges, string $name): ChatbotFlow
    {
        $flow = ChatbotFlow::create(['name' => $name, 'graph' => FlowGraph::clean(['nodes' => $nodes, 'edges' => $edges])]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);

        return $flow->fresh();
    }

    private function sendFlow(string $text): ChatbotFlow
    {
        return $this->published([['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => $text]]]]],
            [['from' => 'n1', 'port' => 'next', 'to' => 'n2']], 'Flow ' . $text);
    }

    /** Isang flow na Start → Sequence step, naka-link sa Page Uno. */
    private function sequenceFlow(string $action, int $sequenceId): ChatbotFlow
    {
        $flow = $this->published([['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'sequence', 'config' => ['action' => $action, 'sequence_id' => $sequenceId]]],
            [['from' => 'n1', 'port' => 'next', 'to' => 'n2']], ucfirst($action) . ' ' . $sequenceId);
        app(ChatbotPage::class)->link('Page Uno', $flow);

        return $flow;
    }

    private function sequence(string $name, array $attrs = []): ChatbotSequence
    {
        $seq = ChatbotSequence::create($attrs + ['name' => $name, 'active' => true, 'exit_on_reply' => true, 'send_from' => '08:00', 'send_to' => '21:00', 'outside_window' => 'skip']);
        $seq->steps()->create(['position' => 1, 'delay_amount' => 2, 'delay_unit' => 'hours', 'flow_id' => $this->sendFlow('Seq 1 of ' . $name)->id, 'active' => true]);

        return $seq;
    }

    private function says(string $message = 'Hi', string $psid = '7001'): void
    {
        $chat = BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => $psid, 'name' => 'Ana Cruz', 'message' => $message, 'payload' => [], 'received_at' => now()]);
        app(ChatbotRunner::class)->handle($chat->id);
    }

    private function sub(ChatbotSequence $seq, string $psid = '7001'): ?object
    {
        return DB::table('chatbot_sequence_subscriptions')->where('sequence_id', $seq->id)->where('page_id', 1001)->where('psid', (int) $psid)->first();
    }

    private function lastSequenceEvent(string $psid = '7001'): string
    {
        $e = DB::table('chatbot_events')->where('psid', (int) $psid)->where('type', 'sequence')->orderByDesc('id')->first();

        return $e ? ChatbotEvents::text($e->type, $e->node_id, (array) json_decode($e->detail, true)) : '';
    }

    public function test_S_070_3a_a_sequence_step_in_a_flow_subscribes_and_unsubscribes(): void
    {
        $seq = $this->sequence('Preorder');
        $other = $this->sequence('Newsletter');
        $this->assertSame(['action' => 'unsubscribe', 'sequence_id' => 0], FlowGraph::cleanConfig('sequence', ['action' => 'unsubscribe', 'sequence_id' => 'all', 'x' => 1]));
        $this->assertSame(['action' => 'subscribe', 'sequence_id' => 5], FlowGraph::cleanConfig('sequence', ['action' => 'nope', 'sequence_id' => '5']));
        $this->assertSame(['next'], FlowGraph::ports(['type' => 'sequence']));

        $subscribe = $this->sequenceFlow('subscribe', $seq->id);
        $this->says();
        $row = $this->sub($seq);
        $this->assertSame(['active', 1, '2026-10-20 12:00', 'flow', $subscribe->id], [$row->status, (int) $row->current_step, substr($row->next_send_at, 0, 16), $row->enrolled_via, (int) $row->enrolled_by]);
        $this->assertSame('Subscribed to sequence Preorder', $this->lastSequenceEvent());
        $this->assertSame('n2', DB::table('chatbot_events')->where('type', 'sequence')->value('node_id'), 'kasama ng takbo, sa step');

        $this->travel(30)->minutes();
        $this->says('Again');
        $this->assertSame([1, '2026-10-20 12:00'], [(int) $this->sub($seq)->current_step, substr($this->sub($seq)->next_send_at, 0, 16)], 'habang active: walang nagbabago');
        $this->assertSame('Already in sequence Preorder', $this->lastSequenceEvent());

        DB::table('chatbot_sequence_subscriptions')->insert(['sequence_id' => $other->id, 'page_id' => 1001, 'psid' => 7001, 'status' => 'active', 'current_step' => 1,
            'next_send_at' => now()->addDay(), 'enrolled_via' => 'other', 'created_at' => now(), 'updated_at' => now()]);
        $this->sequenceFlow('unsubscribe', 0);
        $this->says('Stop');
        $this->assertSame([['unsubscribed', 'flow'], ['unsubscribed', 'flow']], [[$this->sub($seq)->status, $this->sub($seq)->exit_reason], [$this->sub($other)->status, $this->sub($other)->exit_reason]]);

        app(ChatbotPage::class)->link('Page Uno', $subscribe);
        $this->travel(1)->hours();
        $this->says('Back');
        $this->assertSame(['active', 1, null, '2026-10-20 13:30'], [$this->sub($seq)->status, (int) $this->sub($seq)->current_step, $this->sub($seq)->exit_reason, substr($this->sub($seq)->next_send_at, 0, 16)], 'mula ulit sa step 1');

        // Ang editor: ang step na Sequence at ang listahan ng mga sequence para sa form.
        $this->actingAs($this->user());
        $this->get('/chatbot/flows/' . $subscribe->id)->assertOk()->assertSee('data-add="sequence"', false);
        $this->assertSame([['id' => $other->id, 'name' => 'Newsletter'], ['id' => $seq->id, 'name' => 'Preorder']],
            $this->getJson('/chatbot/flows/' . $subscribe->id . '/graph')->assertOk()->json('sequences'));

        // Ang Test tab ay walang binabago.
        $this->postJson('/chatbot/test/send', ['page_name' => 'Page Uno', 'flow_id' => $this->sequenceFlow('subscribe', $other->id)->id, 'message' => 'Hi'])->assertOk();
        $this->assertSame('unsubscribed', $this->sub($other)->status);
    }
}
