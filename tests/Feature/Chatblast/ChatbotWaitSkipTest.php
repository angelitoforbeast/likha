<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Services\Chatbot\ChatbotEvents;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use App\Jobs\ResumeChatbotWait;
use InvalidArgumentException;

/**
 * Ang "wait for reply" na may skip_after: ang deadline sa chatbot_waits, ang sagot bago ang deadline (reply), ang tick
 * (chatbot:resume-skipped) na nagpapatuloy sa skipped nang isang beses lang, ang mga hinihintay na binabagsak, at ang
 * 24 oras ng Messenger. Fake na HTTP (walang Botcake o OpenAI).
 */
class ChatbotWaitSkipTest extends ChatblastTestCase
{
    private const SEND = 'https://botcake.io/api/public_api/v1/pages/1001/flows/send_content';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_25_100000_create_chatbot_waits_table.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true]);
        $this->page('Page Uno', '1001');
        Http::fake([self::SEND => Http::response(['success' => true])]);
    }

    /** n2 nagtatanong at naghihintay (skips after 2 h); reply → n3, skipped → n4. */
    private function graph(?array $skip = ['amount' => 2, 'unit' => 'hours'], bool $withStep = true): array
    {
        $ask = ['items' => [['type' => 'text', 'value' => 'Want to order?']], 'wait' => true, 'save_to' => 'answer'] + ($skip ? ['skip_after' => $skip] : []);
        $nodes = [['id' => 'n1', 'type' => 'start'], ['id' => 'n3', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Thanks: {{answer}}']]]],
            ['id' => 'n4', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Still there?']]]]];
        if ($withStep) $nodes[] = ['id' => 'n2', 'type' => 'send', 'config' => $ask];
        $edges = $withStep ? array_merge([['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'reply', 'to' => 'n3']],
            $skip ? [['from' => 'n2', 'port' => 'skipped', 'to' => 'n4']] : []) : [['from' => 'n1', 'port' => 'next', 'to' => 'n3']];

        return FlowGraph::clean(['nodes' => $nodes, 'edges' => $edges]);
    }

    private function live(?array $graph = null, string $name = 'Main'): ChatbotFlow
    {
        $flow = ChatbotFlow::create(['name' => $name, 'graph' => $graph ?? $this->graph()]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);
        app(ChatbotPage::class)->link('Page Uno', $flow);

        return $flow->fresh();
    }

    /** Isang chat ng customer, pinatakbo agad (maliban kung $run = false). */
    private function says(string $message, string $psid = '7001', bool $run = true): BotcakeChat
    {
        $chat = BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => $psid, 'name' => 'Ana Cruz', 'message' => $message, 'payload' => [], 'received_at' => now()]);
        if ($run) app(ChatbotRunner::class)->handle($chat->id);

        return $chat;
    }

    /** @return array<int, string> ang mga text na naipadala sa Botcake, ayon sa ayos */
    private function sent(string $psid = '7001'): array
    {
        return Http::recorded(fn (HttpRequest $r) => $r->url() === self::SEND && ($r->data()['psid'] ?? null) === $psid)
            ->map(fn ($p) => $p[0]->data()['data']['content']['messages'][0]['text'] ?? '')->values()->all();
    }

    private function tick(): void
    {
        Artisan::call('chatbot:resume-skipped');
    }

    private function lastEvent(string $psid = '7001', ?string $type = null): string
    {
        $e = DB::table('chatbot_events')->where('psid', (int) $psid)->when($type, fn ($q) => $q->where('type', $type))->orderByDesc('id')->first();

        return $e ? ChatbotEvents::text($e->type, $e->node_id, (array) json_decode($e->detail, true)) : '';
    }

    private function waits(string $psid = '7001')
    {
        return DB::table('chatbot_waits')->where('page_id', 1001)->where('psid', (int) $psid);
    }

    public function test_S_076_1_a_reply_before_the_deadline_goes_through_reply_and_removes_the_deadline(): void
    {
        $flow = $this->live();
        $this->says('Hi');

        $wait = $this->waits()->first();
        $this->assertSame([$flow->id, 'n2'], [(int) $wait->flow_id, $wait->node_id]);
        $this->assertSame(now()->addHours(2)->format('Y-m-d H:i'), substr((string) $wait->due_at, 0, 16));
        $this->assertSame($flow->id . ':n2', (new CustomerFields(1001, 7001))->get(CustomerFields::WAIT));
        $this->assertSame('Waiting for reply at step n2 (skips after 2 h)', $this->lastEvent('7001', 'waiting'));

        $this->travel(90)->minutes();
        $this->says('Yes please');
        $this->assertSame(['Want to order?', 'Thanks: Yes please'], $this->sent());
        $this->assertSame(0, $this->waits()->count());

        $this->travel(3)->hours();
        $this->tick();
        $this->assertSame(['Want to order?', 'Thanks: Yes please'], $this->sent());
    }

    public function test_S_076_2_with_no_reply_the_tick_goes_through_skipped_once(): void
    {
        $this->live();
        $this->says('Hi');
        $this->says('Hi', '7002');

        $this->travel(119)->minutes();
        $this->tick();
        $this->assertSame(['Want to order?'], $this->sent(), 'wala pa sa deadline');

        $this->travel(2)->minutes();
        $held = Cache::lock('chatbot-run:1001:7002', 300);
        $held->get();
        $this->tick();
        $this->tick(); // dalawang tick: isang beses lang
        $this->assertSame(['Want to order?', 'Still there?'], $this->sent());
        $this->assertSame('No reply in 2 h → skipped at step n2', $this->lastEvent('7001', 'wait_skipped'));
        $fields = new CustomerFields(1001, 7001);
        $this->assertNull($fields->get(CustomerFields::WAIT));
        $this->assertNull($fields->get('answer'), 'walang sagot na sine-save sa skipped');
        $this->assertSame(0, $this->waits()->count());

        $this->assertSame(['Want to order?'], $this->sent('7002'), 'hawak ng iba ang customer: sa susunod na tick');
        $this->assertSame(1, $this->waits('7002')->count());
        $held->release();
        $this->tick();
        $this->assertSame(['Want to order?'], $this->sent('7002'), 'naipadala na sa queue: sinusubukan ulit pagkalipas ng 5 minuto');
        $this->travel(6)->minutes();
        $this->tick();
        $this->assertSame(['Want to order?', 'Still there?'], $this->sent('7002'));
    }

    public function test_S_076_2_the_row_is_claimed_once_and_each_wait_is_one_job_on_the_chatbot_queue(): void
    {
        $this->live();
        $this->says('Hi');
        $this->travel(121)->minutes();
        $id = (int) $this->waits()->value('id');

        // Iisang job kada wait; ang pangalawang tick ay hindi nagpapadala ulit habang nasa queue pa.
        Queue::fake();
        $this->tick();
        $this->tick();
        Queue::assertPushedOn('chatbot', ResumeChatbotWait::class, fn ($job) => $job->waitId === $id);
        Queue::assertPushed(ResumeChatbotWait::class, 1);

        // Ang claim: ang row na nabasa pero naitulak na ang deadline (ibang takbo ang nauna) ay hindi pinapatakbo.
        $this->waits()->update(['due_at' => now()->addHour()]);
        $this->assertSame('gone', app(ChatbotRunner::class)->skip($id));
        $this->assertSame(['Want to order?'], $this->sent());
        $this->assertSame(1, $this->waits()->count());
    }

    public static function dropped(): array
    {
        return [
            'another flow on the page' => ['other flow', 'flow changed'],
            'the step removed' => ['step removed', 'flow changed'],
            'skip_after removed' => ['skip removed', 'flow changed'],
            'page Off' => ['page off', 'page is off or has no flow'],
            'no flow' => ['no flow', 'page is off or has no flow'],
            'bot paused' => ['paused', 'bot paused'],
        ];
    }

    /** @dataProvider dropped */
    public function test_S_076_3_a_changed_flow_or_page_drops_the_wait(string $change, string $reason): void
    {
        $flow = $this->live();
        $this->says('Hi');
        match ($change) {
            'other flow' => $this->live($this->graph(), 'Other'),
            'step removed' => DB::table('chatbot_flows')->where('id', $flow->id)->update(['graph' => json_encode($this->graph(null, false))]),
            'skip removed' => DB::table('chatbot_flows')->where('id', $flow->id)->update(['graph' => json_encode($this->graph(null))]),
            'page off' => app(PageSettings::class)->set('Page Uno', 'chatbot', 'active', '0', false, null, true),
            'no flow' => app(ChatbotPage::class)->link('Page Uno', null),
            'paused' => (new CustomerFields(1001, 7001))->set(CustomerFields::BOT_PAUSED, '1'),
        };

        $this->travel(121)->minutes();
        $this->tick();

        $this->assertSame(['Want to order?'], $this->sent());
        $this->assertSame(0, $this->waits()->count());
        $this->assertSame('Skipped: no reply in 2 h, but the ' . $reason . ' (n2)', $this->lastEvent('7001', 'skipped'));
        // Ang sagot ay nagpapatuloy pa rin kapag ang step ay naghihintay pa (walang skip lang), o tao ang sumasagot.
        $this->assertSame(in_array($change, ['skip removed', 'paused', 'page off', 'no flow'], true), (new CustomerFields(1001, 7001))->get(CustomerFields::WAIT) !== null);
    }

    public function test_S_076_3_a_draft_change_does_not_touch_the_live_wait(): void
    {
        $flow = $this->live();
        $this->says('Hi');
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['draft_graph' => json_encode($this->graph(null, false))]);

        $this->travel(121)->minutes();
        $this->tick();

        $this->assertSame(['Want to order?', 'Still there?'], $this->sent());

        // Ang haba sa event ay ang hinintay talaga (2 h), kahit 5 h na sa bagong publish.
        $this->says('Hi', '7002');
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['graph' => json_encode($this->graph(['amount' => 5, 'unit' => 'hours']))]);
        $this->travel(121)->minutes();
        $this->tick();
        $this->assertSame('No reply in 2 h → skipped at step n2', $this->lastEvent('7002', 'wait_skipped'));
    }

    public function test_S_076_4_outside_24_hours_nothing_is_sent_and_the_event_is_logged(): void
    {
        $this->live();
        $this->says('Hi');

        $this->travel(24)->hours();
        $this->tick();

        $this->assertSame(['Want to order?'], $this->sent());
        $this->assertSame('Skipped: no reply in 2 h, but the 24-hour window has closed (n2)', $this->lastEvent('7001', 'skipped'));
        $this->assertNull((new CustomerFields(1001, 7001))->get(CustomerFields::WAIT));
        $this->assertSame(0, $this->waits()->count());
    }

    public function test_S_076_5_skip_after_and_the_skipped_port_are_validated(): void
    {
        $send = fn (array $c) => FlowGraph::cleanConfig('send', ['items' => [], 'wait' => true] + $c);
        $this->assertSame(['amount' => 23, 'unit' => 'hours'], $send(['skip_after' => ['amount' => 23, 'unit' => 'hours']])['skip_after']);
        $this->assertSame(['amount' => 1380, 'unit' => 'minutes'], $send(['skip_after' => ['amount' => '1380', 'unit' => 'minutes']])['skip_after']);
        foreach ([['amount' => 0, 'unit' => 'hours'], ['amount' => '', 'unit' => 'minutes'], 'soon', null] as $empty) {
            $this->assertArrayNotHasKey('skip_after', $send(['skip_after' => $empty]));
        }
        $this->assertArrayNotHasKey('skip_after', FlowGraph::cleanConfig('send', ['wait' => false, 'skip_after' => ['amount' => 2, 'unit' => 'hours']]));
        foreach ([['amount' => 24, 'unit' => 'hours'], ['amount' => 1381, 'unit' => 'minutes'], ['amount' => '99999999999999999999', 'unit' => 'hours']] as $tooLong) {
            try {
                $send(['skip_after' => $tooLong]);
                $this->fail('over 23 hours was accepted');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Skip after can be at most 23 hours.', $e->getMessage());
            }
        }

        $this->assertSame(['reply', 'skipped'], FlowGraph::ports(['type' => 'send', 'config' => ['wait' => true, 'skip_after' => ['amount' => 5, 'unit' => 'minutes']]]));
        $this->assertSame(['reply'], FlowGraph::ports(['type' => 'send', 'config' => ['wait' => true]]));
        $this->expectExceptionMessage('Node n2 has no port "skipped".');
        FlowGraph::clean(['nodes' => [['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'send', 'config' => ['wait' => true]], ['id' => 'n3', 'type' => 'end']],
            'edges' => [['from' => 'n2', 'port' => 'skipped', 'to' => 'n3']]]);
    }

    public function test_S_117_12_a_standby_botcake_chat_after_the_wait_does_not_hold_the_skip(): void
    {
        $this->live();
        $this->says('Hi');
        $this->travel(119)->minutes();
        // Ang kopya ng Botcake habang nasa Forbeast ang page: hindi sagot ng customer na tatakbo.
        BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => '7001', 'name' => 'Ana Cruz', 'message' => 'Hi',
            'payload' => ['_standby' => true], 'received_at' => now()]);

        $this->travel(2)->minutes();
        $this->tick();

        $this->assertSame(['Want to order?', 'Still there?'], $this->sent());
    }

    public function test_S_076_6_a_reply_waiting_in_the_queue_wins_over_the_tick(): void
    {
        $this->live();
        $this->says('Hi');
        $this->travel(119)->minutes();
        $reply = $this->says('Yes', '7001', false); // nasa queue pa

        $this->travel(2)->minutes();
        $this->tick();
        $this->assertSame(['Want to order?'], $this->sent());

        app(ChatbotRunner::class)->handle($reply->id);
        $this->assertSame(['Want to order?', 'Thanks: Yes'], $this->sent());
        $this->assertSame(0, $this->waits()->count());
    }

    public function test_S_076_6_a_reply_that_can_no_longer_run_ends_the_timer_without_a_send(): void
    {
        $this->live();
        $this->says('Hi');
        $this->says('Hi', '7002');
        $this->travel(10)->minutes();
        $this->says('Yes', '7001', false); // hindi natakbo (hal. patay ang worker nang lampas 30 minuto)

        $this->travel(111)->minutes();
        $this->tick();

        $this->assertSame(['Want to order?'], $this->sent());
        $this->assertSame(0, $this->waits()->count());
        $this->assertSame('Skipped: no reply in 2 h, but the customer replied and the reply was not run (n2)', $this->lastEvent('7001', 'skipped'));
        $this->assertNotNull((new CustomerFields(1001, 7001))->get(CustomerFields::WAIT), 'ang susunod na sagot ay nagpapatuloy pa rin');
        $this->assertSame(['Want to order?', 'Still there?'], $this->sent('7002'), 'hindi nito hinaharangan ang iba');
    }

    public function test_S_076_7_the_timer_follows_wait(): void
    {
        // Ang susunod na wait na walang skip_after ay walang timer.
        $graph = $this->graph();
        $graph['nodes'][1]['config']['wait'] = true;
        $flow = $this->live(FlowGraph::clean($graph));
        $this->says('Hi');
        $this->says('Yes');
        $this->assertSame($flow->id . ':n3', (new CustomerFields(1001, 7001))->get(CustomerFields::WAIT));
        $this->assertSame(0, $this->waits()->count());

        // "Clear all fields" ay nagbubura rin ng timer.
        $this->says('Hi', '7003');
        $this->assertSame(1, $this->waits('7003')->count());
        (new CustomerFields(1001, 7003))->clearAll();
        $this->assertSame(0, $this->waits('7003')->count());

        // Ang Test tab ay walang timer.
        $this->actingAs($this->user());
        $this->postJson('/chatbot/test/send', ['page_name' => 'Page Uno', 'flow_id' => $flow->id, 'version' => 'published', 'message' => 'Hi'])->assertOk()->assertJson(['waiting' => true]);
        $this->assertSame(0, DB::table('chatbot_waits')->where('psid', CustomerFields::TEST_PSID)->count());
    }

    public function test_S_076_8_the_editor_saves_and_loads_the_setting(): void
    {
        $flow = $this->live($this->graph(null));
        $this->actingAs($this->user());
        $graph = $this->graph(['amount' => 30, 'unit' => 'minutes']);

        $this->putJson("/chatbot/flows/{$flow->id}/graph", ['graph' => $graph, 'version' => 0])->assertOk()->assertJson(['status' => 'draft']);
        $loaded = $this->getJson("/chatbot/flows/{$flow->id}/graph")->assertOk()->json('graph');

        $this->assertSame(['amount' => 30, 'unit' => 'minutes'], collect($loaded['nodes'])->firstWhere('id', 'n2')['config']['skip_after']);
        $this->assertContains(['from' => 'n2', 'port' => 'skipped', 'to' => 'n4'], $loaded['edges']);
        $this->get("/chatbot/flows/{$flow->id}")->assertOk()->assertSee('Skip if no reply after');
    }
}
