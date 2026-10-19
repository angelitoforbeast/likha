<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\RunChatbotSequenceStep;
use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Models\ChatbotFlow;
use App\Models\ChatbotSequence;
use App\Services\Chatbot\ChatbotEvents;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotSequences;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowEngine;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use Carbon\Carbon;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ang mga sequence (drip): ang tick (chatbot:sequence-tick) na kumukuha ng mga due nang isang beses, ang job na
 * nagpapatakbo ng published na flow ng step, ang send window (Manila), ang 24 oras, ang page na Off, ang bot na
 * naka-pause, ang flow na wala o hindi published, ang exit on reply at ang mga event. Fake na HTTP lang.
 */
class ChatbotSequenceEngineTest extends ChatblastTestCase
{
    private const SEND = 'https://botcake.io/api/public_api/v1/pages/1001/flows/send_content';
    private const KEY = 'chat-key-for-tests-only-0123456789';

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

    /** Isang published na flow na nagpapadala ng $text (hindi naka-link sa page). */
    private function flow(string $text, bool $published = true, bool $wait = false): ChatbotFlow
    {
        $send = ['items' => [['type' => 'text', 'value' => $text]]] + ($wait ? ['wait' => true, 'save_to' => 'seq answer', 'skip_after' => ['amount' => 2, 'unit' => 'hours']] : []);
        $flow = ChatbotFlow::create(['name' => 'Flow ' . $text, 'graph' => FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'send', 'config' => $send],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]])]);
        if ($published) DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);

        return $flow->fresh();
    }

    /** @param array<int, array{0: int, 1: string, 2: ?int, 3?: bool}> $steps [delay, unit, flow id, active] */
    private function sequence(string $name, array $steps, array $attrs = []): ChatbotSequence
    {
        $seq = ChatbotSequence::create($attrs + ['name' => $name, 'active' => true, 'exit_on_reply' => true]);
        foreach ($steps as $i => $s) {
            $seq->steps()->create(['position' => $i + 1, 'delay_amount' => $s[0], 'delay_unit' => $s[1], 'flow_id' => $s[2], 'active' => $s[3] ?? true]);
        }

        return $seq;
    }

    private function chat(string $psid = '7001', ?string $at = null, string $page = '1001'): BotcakeChat
    {
        return BotcakeChat::create(['page_id' => $page, 'page_name' => $page === '1001' ? 'Page Uno' : 'Page Dos', 'psid' => $psid, 'name' => 'Ana Cruz',
            'message' => 'Hi', 'payload' => [], 'received_at' => $at ?? now()]);
    }

    private function svc(): ChatbotSequences
    {
        return app(ChatbotSequences::class);
    }

    private function sub(ChatbotSequence $seq, string $psid = '7001', int $page = 1001): object
    {
        return DB::table('chatbot_sequence_subscriptions')->where('sequence_id', $seq->id)->where('page_id', $page)->where('psid', (int) $psid)->first();
    }

    private function tick(): void
    {
        Artisan::call('chatbot:sequence-tick');
    }

    /** @return array<int, string> */
    private function sent(string $psid = '7001'): array
    {
        return Http::recorded(fn (HttpRequest $r) => $r->url() === self::SEND && ($r->data()['psid'] ?? null) === $psid)
            ->map(fn ($p) => $p[0]->data()['data']['content']['messages'][0]['text'] ?? '')->values()->all();
    }

    /** @return array<int, string> ang mga event na sequence ng customer, ayon sa ayos */
    private function events(string $psid = '7001'): array
    {
        return DB::table('chatbot_events')->where('psid', (int) $psid)->where('type', 'sequence')->orderBy('id')->get()
            ->map(fn ($e) => ChatbotEvents::text($e->type, $e->node_id, (array) json_decode($e->detail, true)))->all();
    }

    public function test_S_078_1_a_step_due_at_2am_is_sent_at_2am(): void
    {
        $this->travelTo('2026-10-21 01:00:00');
        $this->chat('7001', '2026-10-21 00:50:00');
        $seq = $this->sequence('Night', [[1, 'hours', $this->flow('Seq 1')->id], [30, 'minutes', $this->flow('Seq 2')->id]]);
        // Ang lumang send hours sa table (08:00–21:00) ay hindi na ginagamit.
        DB::table('chatbot_sequences')->where('id', $seq->id)->update(['send_from' => '08:00', 'send_to' => '21:00']);

        $this->svc()->subscribe($seq->id, 1001, 7001, 'other');
        $this->assertSame('2026-10-21 02:00', substr($this->sub($seq)->next_send_at, 0, 16));

        $this->travelTo('2026-10-21 02:00:00');
        $this->tick();
        $this->assertSame(['Seq 1'], $this->sent());
        $this->assertSame([2, '2026-10-21 02:30'], [(int) $this->sub($seq)->current_step, substr($this->sub($seq)->next_send_at, 0, 16)]);
    }

    public function test_S_070_4a_each_due_row_is_queued_once_on_the_chatbot_queue_and_a_stale_claim_again(): void
    {
        Queue::fake();
        $seq = $this->sequence('Preorder', [[0, 'minutes', $this->flow('Seq 1')->id]]);
        $paused = $this->sequence('Paused one', [[0, 'minutes', $this->flow('Other')->id]]);
        foreach (['7001', '7002'] as $psid) $this->svc()->subscribe($seq->id, 1001, (int) $psid, 'other');
        $this->svc()->subscribe($paused->id, 1001, 7003, 'other');
        $paused->update(['active' => false]);

        $this->tick();
        $this->tick();
        Queue::assertPushed(RunChatbotSequenceStep::class, 2);
        Queue::assertPushedOn('chatbot', RunChatbotSequenceStep::class);

        // Dalawang tick na sabay na nakakita ng parehong mga row: isa lang ang nakakakuha.
        $ids = DB::table('chatbot_sequence_subscriptions')->where('sequence_id', $seq->id)->pluck('id')->all();
        DB::table('chatbot_sequence_subscriptions')->whereIn('id', $ids)->update(['dispatched_at' => null]);
        $this->assertSame($ids, $this->svc()->claim($ids));
        $this->assertSame([], $this->svc()->claim($ids));

        // Ang job na namatay: lampas 10 minuto na hindi pa umuusad → kinukuha ulit.
        $this->travel(9)->minutes();
        $this->tick();
        Queue::assertPushed(RunChatbotSequenceStep::class, 2);
        $this->travel(2)->minutes();
        $this->tick();
        Queue::assertPushed(RunChatbotSequenceStep::class, 4);

        // Ang lumang job (naunahan ng bagong claim) at ang job ng row na hindi pa due ay walang ginagawa.
        $this->chat();
        $jobs = Queue::pushed(RunChatbotSequenceStep::class)->filter(fn ($j) => $j->subscriptionId === $ids[0])->values();
        $this->assertCount(2, $jobs);
        app()->call([$jobs[0], 'handle']);
        $this->assertSame([], $this->sent(), 'the stale job does nothing');
        app()->call([$jobs[1], 'handle']);
        $this->assertSame(['Seq 1'], $this->sent());
        app()->call([$jobs[1], 'handle']);
        app()->call([$jobs[0], 'handle']);
        $this->assertSame(['Seq 1'], $this->sent(), 'a second run of a done step sends nothing');
        DB::table('chatbot_sequence_subscriptions')->where('id', $ids[1])->update(['next_send_at' => now()->addHour(), 'dispatched_at' => now()]);
        app()->call([new RunChatbotSequenceStep($ids[1], now()->format('Y-m-d H:i:s')), 'handle']);
        $this->assertSame([], $this->sent('7002'), 'not due yet');
    }

    public function test_S_070_4b_three_steps_run_their_published_flows_in_order_and_the_subscription_completes(): void
    {
        $first = $this->flow('Seq 1', true, true); // may "wait for reply": hindi nito pinapalitan ang wait ng main flow
        DB::table('chatbot_flows')->where('id', $first->id)->update(['draft_graph' => json_encode(['nodes' => [], 'edges' => []])]);
        $seq = $this->sequence('Preorder', [[0, 'minutes', $first->id], [2, 'hours', $this->flow('Seq 2')->id], [5, 'minutes', $this->flow('Skipped')->id, false], [1, 'days', $this->flow('Seq 3')->id]],
            ['exit_on_reply' => false]);
        $this->chat();
        $fields = new CustomerFields(1001, 7001);
        $fields->set(CustomerFields::WAIT, '999:n2'); // naghihintay sa main flow ng page
        \App\Services\Chatbot\ChatbotWaits::park(1001, 7001, 999, 'n2', now()->addHours(3));

        $this->assertSame('subscribed', $this->svc()->subscribe($seq->id, 1001, 7001, 'other')['result']);
        $this->assertSame(['active', 1, '2026-10-20 10:00'], [$this->sub($seq)->status, (int) $this->sub($seq)->current_step, substr($this->sub($seq)->next_send_at, 0, 16)]);

        $this->tick();
        $row = $this->sub($seq);
        $this->assertSame(['Seq 1'], $this->sent(), 'ang published na graph, hindi ang draft');
        $this->assertSame([2, '2026-10-20 12:00', '2026-10-20 10:00', null], [(int) $row->current_step, substr($row->next_send_at, 0, 16), substr($row->last_sent_at, 0, 16), $row->dispatched_at]);
        $this->assertSame('999:n2', $fields->get(CustomerFields::WAIT), 'ang step ay hindi gumagalaw sa wait ng main flow');
        $this->assertSame([[999, 'n2']], DB::table('chatbot_waits')->where('psid', 7001)->get()->map(fn ($w) => [(int) $w->flow_id, $w->node_id])->all(), 'the main timer stays');

        $this->travelTo('2026-10-20 12:00:00');
        $this->tick();
        $this->assertSame(['Seq 1', 'Seq 2'], $this->sent());
        $this->assertSame([4, '2026-10-21 12:00'], [(int) $this->sub($seq)->current_step, substr($this->sub($seq)->next_send_at, 0, 16)], 'ang hindi active na step ay nilalaktawan');

        $this->chat('7001', '2026-10-21 09:00:00');
        $this->travelTo('2026-10-21 12:00:00');
        $this->tick();
        $this->assertSame(['Seq 1', 'Seq 2', 'Seq 3'], $this->sent());
        $this->assertSame(['completed', null], [$this->sub($seq)->status, $this->sub($seq)->next_send_at]);
        $this->assertSame(['Subscribed to sequence Preorder', 'Sequence Preorder step 1 ran', 'Sequence Preorder step 2 ran', 'Sequence Preorder step 4 ran', 'Sequence Preorder completed'], $this->events());
        $this->assertSame(3, DB::table('chatbot_replies')->where('kind', 'send')->where('status', 'sent')->count());

        $this->travel(1)->days();
        $this->tick();
        $this->assertCount(3, $this->sent(), 'tapos na: wala nang padala');
    }

    public function test_S_078_2_outside_24_hours_the_step_is_always_skipped(): void
    {
        $skip = $this->sequence('Skipper', [[0, 'minutes', $this->flow('Seq 1')->id], [1, 'hours', $this->flow('Seq 2')->id]]);
        $stop = $this->sequence('Stopper', [[0, 'minutes', $this->flow('Stop 1')->id], [2, 'hours', $this->flow('Stop 2')->id]]);
        // Ang lumang "stop" sa table ay hindi na ginagamit: laging skip.
        DB::table('chatbot_sequences')->where('id', $stop->id)->update(['outside_window' => 'stop']);
        $this->chat('7001', '2026-10-19 09:59:00');
        $this->chat('7002', '2026-10-19 09:59:00');
        $this->svc()->subscribe($skip->id, 1001, 7001, 'other');
        $this->svc()->subscribe($stop->id, 1001, 7002, 'other');

        $this->tick();

        $this->assertSame([[], []], [$this->sent('7001'), $this->sent('7002')]);
        $this->assertSame(['active', 2, '2026-10-20 11:00'], [$this->sub($skip)->status, (int) $this->sub($skip)->current_step, substr($this->sub($skip)->next_send_at, 0, 16)]);
        $this->assertSame('Sequence Skipper step 1 skipped (outside 24h)', last($this->events('7001')));
        $this->assertSame(['active', 2, '2026-10-20 12:00'], [$this->sub($stop, '7002')->status, (int) $this->sub($stop, '7002')->current_step, substr($this->sub($stop, '7002')->next_send_at, 0, 16)]);
        $this->assertSame('Sequence Stopper step 1 skipped (outside 24h)', last($this->events('7002')));
    }

    public function test_S_070_5b_a_page_that_is_off_pauses_the_subscription_until_it_is_on_again(): void
    {
        $seq = $this->sequence('Preorder', [[0, 'minutes', $this->flow('Seq 1')->id]]);
        $this->chat();
        $this->svc()->subscribe($seq->id, 1001, 7001, 'other');
        $settings = app(PageSettings::class);
        $settings->set('Page Uno', 'chatbot', 'active', '0', false, null, true);

        $this->tick();
        $this->tick();
        $this->assertSame(['paused', 'page off'], [$this->sub($seq)->status, $this->sub($seq)->exit_reason]);
        $this->assertSame([], $this->sent());
        $this->assertSame('Sequence Preorder paused (page off)', last($this->events()));

        $settings->set('Page Uno', 'chatbot', 'active', '1', false, null, true);
        $this->tick();
        $this->assertSame(['Seq 1'], $this->sent());
        $this->assertSame(['completed', null], [$this->sub($seq)->status, $this->sub($seq)->exit_reason]);
    }

    public function test_S_070_5c_with_the_bot_paused_for_the_customer_the_step_is_held_for_an_hour(): void
    {
        $seq = $this->sequence('Preorder', [[0, 'minutes', $this->flow('Seq 1')->id]]);
        $this->chat();
        $this->svc()->subscribe($seq->id, 1001, 7001, 'other');
        (new CustomerFields(1001, 7001))->set(CustomerFields::BOT_PAUSED, '1');

        $this->tick();

        $row = $this->sub($seq);
        $this->assertSame([], $this->sent());
        $this->assertSame(['active', 1, '2026-10-20 11:00', null], [$row->status, (int) $row->current_step, substr($row->next_send_at, 0, 16), $row->dispatched_at]);
        $this->assertSame('Sequence Preorder step 1 held (bot paused)', last($this->events()));
    }

    public function test_S_070_5d_a_missing_or_unpublished_flow_or_a_failed_run_is_an_error_and_the_pointer_moves_on(): void
    {
        $gone = $this->flow('Gone');
        $seq = $this->sequence('Preorder', [[0, 'minutes', $this->flow('Draft only', false)->id], [0, 'minutes', $gone->id], [0, 'minutes', $this->flow('Seq 3')->id]]);
        $gone->delete();
        $this->chat();
        $this->svc()->subscribe($seq->id, 1001, 7001, 'other');

        $this->tick();
        $this->tick();
        $this->tick();

        $this->assertSame(['Seq 3'], $this->sent());
        $this->assertSame(['Subscribed to sequence Preorder', 'Sequence Preorder step 1 failed: the flow is not published', 'Sequence Preorder step 2 failed: the flow was deleted',
            'Sequence Preorder step 3 ran', 'Sequence Preorder completed'], $this->events());
        $this->actingAs($this->user());
        $errors = array_column($this->getJson('/chatbot/inbox/panel?page_id=1001&psid=7001')->assertOk()->json('errors'), 'text');
        $this->assertContains('Sequence Preorder step 1 failed: the flow is not published', $errors);

        // Ang takbong pumalya (exception): error event, at umuusad pa rin (walang paulit-ulit).
        $other = $this->sequence('Second', [[0, 'minutes', $this->flow('Boom')->id], [1, 'hours', $this->flow('After')->id]]);
        $this->svc()->subscribe($other->id, 1001, 7001, 'other');
        $this->app->instance(FlowEngine::class, new class extends FlowEngine {
            public function __construct() {}
            public function run(\App\Services\Chatbot\FlowRun $run, \App\Services\Chatbot\Outbox $outbox): \App\Services\Chatbot\FlowRun { throw new \RuntimeException('engine broke'); }
        });
        $this->tick();
        $this->assertSame([2, 'active'], [(int) $this->sub($other)->current_step, $this->sub($other)->status]);
        $this->assertSame('Sequence Second step 1 failed: engine broke', last($this->events()));
    }

    public function test_S_070_5e_a_busy_customer_is_retried_in_a_minute_an_unknown_page_is_an_error_and_a_paused_sequence_refuses(): void
    {
        $seq = $this->sequence('Preorder', [[0, 'minutes', $this->flow('Seq 1')->id]]);
        $this->chat();
        $this->svc()->subscribe($seq->id, 1001, 7001, 'other');
        $lock = \Illuminate\Support\Facades\Cache::lock('chatbot-run:1001:7001', 300);
        $lock->get();
        $this->tick();
        $lock->release();
        $this->assertSame([], $this->sent());
        $this->assertSame([1, '2026-10-20 10:01', null], [(int) $this->sub($seq)->current_step, substr($this->sub($seq)->next_send_at, 0, 16), $this->sub($seq)->dispatched_at]);

        $this->svc()->subscribe($seq->id, 3003, 7001, 'other');
        $this->tick();
        $this->assertSame(['error', 'page not set up'], [$this->sub($seq, '7001', 3003)->status, $this->sub($seq, '7001', 3003)->exit_reason]);

        $paused = $this->sequence('Paused', [[0, 'minutes', $this->flow('P')->id]], ['active' => false]);
        $empty = $this->sequence('Empty', [[0, 'minutes', $this->flow('E')->id, false]]);
        $this->assertSame(['refused', 'refused'], [$this->svc()->subscribe($paused->id, 1001, 7002, 'other')['result'], $this->svc()->subscribe($empty->id, 1001, 7002, 'other')['result']]);
        $this->assertSame(['Not subscribed to Paused: the sequence is paused', 'Not subscribed to Empty: the sequence has no active steps'], $this->events('7002'));
        $this->assertSame(0, DB::table('chatbot_sequence_subscriptions')->where('psid', 7002)->count());
    }

    public function test_S_070_6_a_reply_exits_the_sequences_of_that_page_that_exit_on_reply_and_the_bot_still_answers(): void
    {
        config(['services.botcake.chat_ips' => ['203.171.22.6'], 'services.botcake.chat_ip_mode' => 'log']);
        app(PageSettings::class)->set('Page Uno', 'botcake', 'chat.key', self::KEY, true, null, true);
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Ana Cruz', 'psid' => '7001', 'status' => 'available', 'source' => 'api']);
        $this->page('Page Dos', '2002');
        $main = $this->flow('Hello back');
        app(ChatbotPage::class)->link('Page Uno', $main);
        $exits = $this->sequence('Preorder', [[1, 'days', $this->flow('Seq 1')->id]]);
        $stays = $this->sequence('Newsletter', [[1, 'days', $this->flow('News')->id]], ['exit_on_reply' => false]);
        $this->svc()->subscribe($exits->id, 1001, 7001, 'flow');
        $this->svc()->subscribe($stays->id, 1001, 7001, 'flow');
        $this->svc()->subscribe($exits->id, 2002, 7001, 'flow');

        $this->withServerVariables(['REMOTE_ADDR' => '203.171.22.6'])->postJson('/api/botcake/chat',
            ['page_id' => '1001', 'psid' => '7001', 'name' => 'Ana Cruz', 'message' => 'How much?'], ['X-Chat-Key' => self::KEY])->assertOk();

        $this->assertSame(['unsubscribed', 'reply'], [$this->sub($exits)->status, $this->sub($exits)->exit_reason]);
        $this->assertSame('active', $this->sub($stays)->status, 'exit on reply is off');
        $this->assertSame('active', $this->sub($exits, '7001', 2002)->status, 'another page');
        $this->assertContains('Unsubscribed from Preorder (reply)', $this->events());
        $this->assertSame(['Hello back'], $this->sent(), 'the bot still answers the chat');
    }
}
