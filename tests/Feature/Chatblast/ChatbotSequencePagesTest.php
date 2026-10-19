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
        $seq = ChatbotSequence::create($attrs + ['name' => $name, 'active' => true, 'exit_on_reply' => true]);
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

    private function navLink(array $roles): void
    {
        if (!Schema::hasTable('nav_links')) {
            Artisan::call('migrate', ['--path' => 'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php', '--force' => true]);
        }
        $id = DB::table('nav_links')->insertGetId(['key' => 'chatblast', 'label' => 'Chatblast', 'route_url' => '/chatblast', 'icon' => 'x', 'active_pattern' => 'chatblast*',
            'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($roles as $role) DB::table('nav_link_role_visibility')->insert(['nav_link_id' => $id, 'role' => $role, 'is_visible' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function subscriber(ChatbotSequence $seq, int $psid, string $status, int $page = 1001, array $extra = []): void
    {
        DB::table('chatbot_sequence_subscriptions')->insert($extra + ['sequence_id' => $seq->id, 'page_id' => $page, 'psid' => $psid, 'status' => $status, 'current_step' => 1,
            'next_send_at' => $status === 'active' ? now()->addHour() : null, 'enrolled_via' => 'flow', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_S_070_1_the_list_shows_steps_enrolled_completed_and_status_behind_chatblast_access(): void
    {
        $seq = $this->sequence('Preorder');
        $seq->steps()->create(['position' => 2, 'delay_amount' => 1, 'delay_unit' => 'days', 'flow_id' => null, 'active' => true]);
        $this->sequence('Newsletter', ['active' => false]);
        foreach ([7001, 7002, 7003] as $p) $this->subscriber($seq, $p, 'active');
        $this->subscriber($seq, 7004, 'completed');
        $this->subscriber($seq, 7005, 'unsubscribed');

        $routes = [['get', '/chatbot/sequences'], ['post', '/chatbot/sequences'], ['get', '/chatbot/sequences/' . $seq->id], ['put', '/chatbot/sequences/' . $seq->id],
            ['get', '/chatbot/sequences/' . $seq->id . '/subscribers'], ['post', '/chatbot/inbox/sequence']];
        foreach ($routes as [$m, $url]) $this->{$m}($url)->assertRedirect('/login');
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        foreach ($routes as [$m, $url]) $this->{$m . 'Json'}($url)->assertForbidden();

        $this->actingAs($this->user());
        $html = $this->get('/chatbot/sequences')->assertOk()->getContent();
        preg_match_all('/data-cb-nav="([^"]+)"/', $html, $m);
        $this->assertSame(['Flow', 'Sequences', 'Page settings', 'Chats', 'Inbox', 'Replies', 'Test'], $m[1]); // the app switcher replaces the Chatblast tab
        preg_match_all('/<tr data-sequence="(\d+)">(.*?)<\/tr>/s', $html, $rows);
        $cells = array_map(fn ($r) => array_map(fn ($c) => trim(strip_tags($c)), preg_split('/<\/td>/', $r)), $rows[2]);
        $this->assertSame(['Newsletter', '1', '0', '0', 'Paused'], array_slice(array_map(fn ($c) => preg_replace('/\s+/', ' ', $c), $cells[0]), 0, 5));
        $this->assertSame(['Preorder', '2', '3', '1', 'Active'], array_slice(array_map(fn ($c) => preg_replace('/\s+/', ' ', $c), $cells[1]), 0, 5));
        $this->assertStringContainsString('+ New sequence', $html);

        $this->post('/chatbot/sequences', ['name' => 'Follow up'])->assertRedirect();
        $new = ChatbotSequence::where('name', 'Follow up')->first();
        $this->assertSame([true, true], [$new->active, $new->exit_on_reply]);
        $this->post('/chatbot/sequences', ['name' => 'Follow up'])->assertSessionHasErrors('name');
    }

    public function test_S_070_2_the_editor_saves_the_settings_and_the_steps_in_order(): void
    {
        $seq = $this->sequence('Preorder');
        $a = $this->sendFlow('A');
        $b = $this->sendFlow('B');
        $this->sequence('Taken');
        $this->actingAs($this->user());
        $this->get('/chatbot/sequences/' . $seq->id)->assertOk()->assertSee('Exit when the customer replies')->assertSee('Flow A')
            ->assertSee("Each step runs at its delay. A step is skipped when the customer's last message is over 24 hours old (Messenger's rule).", false)
            ->assertDontSee('Send from')->assertDontSee('Send until')->assertDontSee('Outside the 24-hour window');
        $this->get('/chatbot/sequences')->assertOk()->assertDontSee('send hours');

        $body = ['name' => ' Preorder 2 ', 'description' => 'For pre-orders', 'active' => false, 'exit_on_reply' => false, 'steps' => [
                ['delay_amount' => 10, 'delay_unit' => 'minutes', 'flow_id' => $b->id, 'active' => true],
                ['delay_amount' => 1, 'delay_unit' => 'days', 'flow_id' => $a->id, 'active' => false],
            ]];
        $this->putJson('/chatbot/sequences/' . $seq->id, $body)->assertOk();

        $seq->refresh();
        $this->assertSame(['Preorder 2', 'For pre-orders', false, false], [$seq->name, $seq->description, $seq->active, $seq->exit_on_reply]);
        $this->assertSame([[1, 10, 'minutes', $b->id, true], [2, 1, 'days', $a->id, false]],
            $seq->steps->map(fn ($s) => [$s->position, $s->delay_amount, $s->delay_unit, $s->flow_id, $s->active])->all());

        $bad = [
            ['name' => 'Taken'], ['name' => ''], ['name' => str_repeat('n', 121)], ['description' => str_repeat('d', 1001)],
            ['steps' => [['delay_amount' => 1000, 'delay_unit' => 'hours', 'flow_id' => $a->id, 'active' => true]]],
            ['steps' => [['delay_amount' => 1, 'delay_unit' => 'weeks', 'flow_id' => $a->id, 'active' => true]]],
            ['steps' => [['delay_amount' => -1, 'delay_unit' => 'hours', 'flow_id' => $a->id, 'active' => true]]],
            ['steps' => [['delay_amount' => 1, 'delay_unit' => 'hours', 'flow_id' => 999999, 'active' => true]]],
            ['steps' => array_fill(0, 51, ['delay_amount' => 1, 'delay_unit' => 'hours', 'flow_id' => $a->id, 'active' => true])],
        ];
        foreach ($bad as $change) $this->putJson('/chatbot/sequences/' . $seq->id, array_merge($body, $change))->assertStatus(422);
        $this->assertSame(['Preorder 2', 2], [$seq->fresh()->name, $seq->steps()->count()], 'nothing changed');
    }

    public function test_S_070_7_the_subscribers_page_filters_by_status_and_page_with_bounded_queries(): void
    {
        $this->page('Page Dos', '2002');
        $seq = $this->sequence('Preorder');
        $other = $this->sequence('Other');
        for ($p = 1; $p <= 105; $p++) $this->subscriber($seq, 8000 + $p, 'active');
        $this->subscriber($seq, 7001, 'unsubscribed', 1001, ['exit_reason' => 'reply', 'last_sent_at' => '2026-10-20 09:00:00', 'current_step' => 2]);
        $this->subscriber($seq, 7002, 'active', 2002);
        $this->subscriber($other, 7003, 'active');
        $this->actingAs($this->user());

        DB::enableQueryLog();
        $html = $this->get('/chatbot/sequences/' . $seq->id . '/subscribers')->assertOk()->getContent();
        $queries = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'from "chatbot_sequence_subscriptions"'));
        foreach ($queries as $q) $this->assertMatchesRegularExpression('/"sequence_id" = \?.*limit \d+/s', $q['query'], $q['query']);
        $this->assertSame(100, substr_count($html, '<tr data-row='), 'newest 100, then Older');
        $this->assertStringContainsString('Older', $html);
        $this->assertStringNotContainsString('PSID …7003', $html);

        $html = $this->get('/chatbot/sequences/' . $seq->id . '/subscribers?status=unsubscribed')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, '<tr data-row='));
        foreach (['PSID …7001', 'Page Uno', 'unsubscribed', 'reply', 'flow', '2026-10-20 09:00'] as $text) $this->assertStringContainsString($text, $html);
        $html = $this->get('/chatbot/sequences/' . $seq->id . '/subscribers?page_id=2002')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, '<tr data-row='));
        $this->assertStringContainsString('Page Dos', $html);
        $this->get('/chatbot/sequences/' . $seq->id . '/subscribers?status=nope&page_id=x')->assertOk();
    }

    public function test_S_070_3b_the_inbox_lists_the_customers_sequences_and_allowed_users_subscribe_and_unsubscribe(): void
    {
        $seq = $this->sequence('Preorder');
        $done = $this->sequence('Old promo');
        $this->subscriber($done, 7001, 'completed');
        BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => '7001', 'name' => 'Ana Cruz', 'message' => 'Hi', 'payload' => [], 'received_at' => now()]);
        $ceo = $this->user();
        $this->actingAs($ceo);

        $p = $this->getJson('/chatbot/inbox/panel?page_id=1001&psid=7001')->assertOk()->json();
        $this->assertSame([['id' => $done->id, 'name' => 'Old promo', 'status' => 'completed', 'step' => 1, 'next_send' => null]], $p['sequences']);
        $this->assertSame([['id' => $done->id, 'name' => 'Old promo'], ['id' => $seq->id, 'name' => 'Preorder']], $p['all_sequences']);

        $this->postJson('/chatbot/inbox/sequence', ['page_id' => '1001', 'psid' => '7001', 'sequence_id' => $seq->id, 'action' => 'subscribe'])->assertOk()
            ->assertJson(['result' => 'subscribed']);
        $row = $this->sub($seq);
        $this->assertSame(['active', 'inbox', $ceo->id, '2026-10-20 12:00'], [$row->status, $row->enrolled_via, (int) $row->enrolled_by, substr($row->next_send_at, 0, 16)]);
        $this->assertSame('Subscribed to sequence Preorder', $this->lastSequenceEvent());
        $this->assertSame('2026-10-20T12:00:00+08:00', collect($this->getJson('/chatbot/inbox/panel?page_id=1001&psid=7001')->json('sequences'))->firstWhere('id', $seq->id)['next_send']);

        $this->postJson('/chatbot/inbox/sequence', ['page_id' => '1001', 'psid' => '7001', 'sequence_id' => $seq->id, 'action' => 'unsubscribe'])->assertOk();
        $this->assertSame(['unsubscribed', 'inbox'], [$this->sub($seq)->status, $this->sub($seq)->exit_reason]);
        $this->postJson('/chatbot/inbox/sequence', ['page_id' => '1001', 'psid' => '7001', 'sequence_id' => 999, 'action' => 'subscribe'])->assertStatus(422);
        $this->postJson('/chatbot/inbox/sequence', ['page_id' => '1001', 'psid' => '7001', 'sequence_id' => $seq->id, 'action' => 'wipe'])->assertStatus(422);

        // Ang may Chatblast access na hindi kasama sa mga sumasagot: nakikita, hindi nakakapagbago.
        $this->navLink(['Marketing']);
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->assertCount(2, $this->getJson('/chatbot/inbox/panel?page_id=1001&psid=7001')->assertOk()->json('sequences'));
        $this->postJson('/chatbot/inbox/sequence', ['page_id' => '1001', 'psid' => '7001', 'sequence_id' => $seq->id, 'action' => 'subscribe'])->assertForbidden();
        $this->assertSame('unsubscribed', $this->sub($seq)->status);
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
