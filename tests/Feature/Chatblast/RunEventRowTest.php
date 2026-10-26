<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Models\ChatbotSequence;
use App\Services\Chatbot\BotHealth;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotResults;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\Chatbot\FlowGraph;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Spec 163: isang `run` row kada takbo ng flow (simula at dulo sa iisang row), pero pareho ang mga linya sa Inbox: "Flow …
 * started (…)" at "Run ended (…)". Ang lumang run_started / run_ended ay lumalabas pa rin. Fake na Botcake.
 */
class RunEventRowTest extends ChatblastTestCase
{
    private const SEND = 'https://botcake.io/api/public_api/v1/pages/1001/flows/send_content';

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
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
            'database/migrations/2026_10_30_098000_add_raw_to_chatbot_replies.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
            'database/migrations/2026_11_06_100000_add_chat_index_to_chatbot_replies.php',
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
            'database/migrations/2026_11_11_100000_add_real_send_to_chatbot_replies.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true]);
        $this->page('Page Uno', '1001');
        Http::fake([self::SEND => Http::response(['success' => true])]);
        $this->travelTo('2026-10-26 10:00:00');
    }

    private function flow(string $name, array $nodes, array $edges, bool $link = true): ChatbotFlow
    {
        $flow = ChatbotFlow::create(['name' => $name, 'graph' => FlowGraph::clean(['nodes' => $nodes, 'edges' => $edges])]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);
        if ($link) app(ChatbotPage::class)->link('Page Uno', $flow);

        return $flow->fresh();
    }

    /** Start → Condition (walang row: no match) → Send na naghihintay ng sagot (counts as an order). */
    private function main(): ChatbotFlow
    {
        return $this->flow('Main', [
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'condition', 'config' => ['rows' => [['variable' => 'last_input', 'rule' => 'contains', 'value' => 'zzz']]]],
            ['id' => 'n3', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Order na po?']], 'wait' => true, 'save_to' => 'answer', 'counts_order' => true]],
        ], [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'no', 'to' => 'n3']]);
    }

    private function chat(string $message, string $psid = '7001'): BotcakeChat
    {
        $chat = BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => $psid, 'name' => 'Ana Cruz', 'message' => $message, 'payload' => [], 'received_at' => now()]);
        app(ChatbotRunner::class)->handle($chat->id);

        return $chat;
    }

    /** @return array<int, string> ang mga linya ng executed actions sa Inbox, ayon sa ayos */
    private function lines(string $psid = '7001'): array
    {
        $this->actingAs($this->user('CEO', 'ceo@example.test'));

        return collect($this->getJson('/chatbot/inbox/thread?page_id=1001&psid=' . $psid . '&events=1')->assertOk()->json('items'))
            ->where('side', 'event')->map(fn ($x) => $x['type'] . ': ' . $x['text'])->values()->all();
    }

    public function test_S_163_3_a_run_writes_one_run_row_and_the_inbox_shows_the_same_two_lines(): void
    {
        $this->main();
        $this->chat('Hi');
        $this->travel(2)->minutes();
        $this->chat('Yes po');

        $this->assertSame(['order', 'condition', 'waiting', 'run', 'field', 'run'], DB::table('chatbot_events')->orderBy('id')->pluck('type')->all());
        $run = json_decode((string) DB::table('chatbot_events')->where('type', 'run')->orderBy('id')->value('detail'), true);
        $this->assertSame(['2026-10-26 10:00:00', '2026-10-26 10:00:00', 'new', 'waiting for a reply', ['n1', 'n2', 'n3']],
            [$run['started_at'], $run['ended_at'], $run['start'], $run['reason'], $run['path']]);
        // Gaya ng dati (nakuha sa lumang run_started / run_ended): ang order ay isinusulat bago ang mga event ng takbo.
        $this->assertSame([
            'order: Counted as an order at step n3', 'run_started: Flow Main started (new)', 'condition: Condition n2 → no match', 'waiting: Waiting for reply at step n3',
            'run_ended: Run ended (waiting for a reply)',
            'run_started: Flow Main started (resumed after wait)', 'field: Custom field value changed — ANSWER was set to Yes po', 'run_ended: Run ended (no next step)',
        ], $this->lines());
        // Ang panel (huling takbo): pareho ang flow, dinaanan at resulta.
        $p = $this->getJson('/chatbot/inbox/panel?page_id=1001&psid=7001')->assertOk()->json('runs');
        $this->assertSame([['Main', ['n3'], 'no next step'], ['Main', ['n1', 'n2', 'n3'], 'waiting for a reply']],
            array_map(fn ($r) => [$r['flow'], $r['path'], $r['result']], $p));
    }

    public function test_S_163_3b_old_run_started_and_run_ended_rows_still_show(): void
    {
        $flow = $this->main();
        foreach ([['run_started', null, ['reason' => 'resumed after AI', 'flow' => $flow->id]], ['condition', 'n2', ['result' => 'no']],
            ['run_ended', null, ['reason' => 'end', 'path' => ['n4', 'n2']]]] as [$type, $node, $d]) {
            DB::table('chatbot_events')->insert(['page_id' => 1001, 'psid' => 7001, 'flow_id' => $flow->id, 'node_id' => $node, 'type' => $type,
                'detail' => json_encode($d + ['run' => 'ab12cd34']), 'created_at' => '2026-10-26 09:00:00']);
        }

        $this->assertSame(['run_started: Flow Main started (resumed after AI)', 'condition: Condition n2 → no match', 'run_ended: Run ended (end)'], $this->lines());
        $this->assertSame([['Main', ['n4', 'n2'], 'end']], array_map(fn ($r) => [$r['flow'], $r['path'], $r['result']],
            $this->getJson('/chatbot/inbox/panel?page_id=1001&psid=7001')->assertOk()->json('runs')));
    }

    public function test_S_163_3c_bot_health_and_results_are_the_same_with_old_and_new_run_rows(): void
    {
        $flow = $this->main();
        $this->chat('Hi');
        $this->chat('Hello', '7002');
        $outputs = function () {
            Cache::flush();
            $health = app(BotHealth::class)->output([]);
            unset($health['queue']);

            return [$health, app(ChatbotResults::class)->report(ChatbotResults::filters(['from' => '2026-10-26', 'to' => '2026-10-26']))];
        };
        $before = $outputs();
        $this->assertSame(2, DB::table('chatbot_events')->where('type', 'run')->count());

        foreach (['run_started' => ['reason' => 'new', 'flow' => $flow->id], 'run_ended' => ['reason' => 'end', 'path' => ['n1']]] as $type => $d) {
            DB::table('chatbot_events')->insert(['page_id' => 1001, 'psid' => 7003, 'flow_id' => $flow->id, 'type' => $type, 'detail' => json_encode($d), 'created_at' => now()]);
        }

        $this->assertEquals($before, $outputs());
    }

    public function test_S_163_3d_a_run_that_throws_still_writes_its_run_row_as_failed(): void
    {
        // Isang loop: lampas 100 hakbang, at ang log ng limit ay pumapalya (hal. puno ang disk).
        $this->flow('Loop', [['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'condition', 'config' => ['rows' => []]], ['id' => 'n3', 'type' => 'condition', 'config' => ['rows' => []]]],
            [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'no', 'to' => 'n3'], ['from' => 'n3', 'port' => 'no', 'to' => 'n2']]);
        Log::shouldReceive('warning')->andReturnUsing(function (string $message) {
            if ($message === 'chatbot: step limit') throw new \RuntimeException('log down');
        });

        try {
            $this->chat('Hi');
            $this->fail('the run should throw');
        } catch (\RuntimeException $e) {
            $this->assertSame('log down', $e->getMessage());
        }

        $run = DB::table('chatbot_events')->where('type', 'run')->sole();
        $this->assertSame('failed: RuntimeException', json_decode((string) $run->detail, true)['reason']);
        $this->assertSame(['run_started: Flow Loop started (new)', 'run_ended: Run ended (failed: RuntimeException)'],
            array_values(array_filter($this->lines(), fn ($l) => str_starts_with($l, 'run_'))));
    }

    public function test_S_163_4_a_sequence_step_still_shows_its_lines(): void
    {
        $step = $this->flow('Step', [['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Follow up']]]]],
            [['from' => 'n1', 'port' => 'next', 'to' => 'n2']], false);
        $seq = ChatbotSequence::create(['name' => 'Follow', 'active' => true, 'exit_on_reply' => false]);
        $seq->steps()->create(['position' => 1, 'delay_amount' => 1, 'delay_unit' => 'hours', 'flow_id' => $step->id, 'active' => true]);
        $this->flow('Main', [['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'sequence', 'config' => ['action' => 'subscribe', 'sequence_id' => $seq->id]]],
            [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]);
        $this->chat('Hi');
        $this->travel(61)->minutes();
        Artisan::call('chatbot:sequence-tick');

        $this->assertSame([
            'run_started: Flow Main started (new)', 'sequence: Subscribed to sequence Follow', 'run_ended: Run ended (no next step)',
            'run_started: Flow Step started (sequence Follow step 1)', 'run_ended: Run ended (no next step)', 'sequence: Sequence Follow step 1 ran', 'sequence: Sequence Follow completed',
        ], $this->lines());
    }
}
