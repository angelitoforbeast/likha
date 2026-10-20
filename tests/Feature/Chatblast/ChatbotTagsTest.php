<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\MarkChatbotReplied;
use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Models\ChatbotFlow;
use App\Models\ChatbotSequence;
use App\Models\PageSetting;
use App\Services\Chatbot\BotcakeSendPool;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\Chatbot\ChatbotSequences;
use App\Services\Chatbot\ChatbotTags;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec 100, ang talaan: ang tags ng bawat padala (botfield at ang version nito, ang flow version, sequence, ad_id) sa
 * live, pool, sequence at Inbox; ang replied_at; at ang order event ng node na "Counts as an order". Fake na Botcake.
 */
class ChatbotTagsTest extends ChatblastTestCase
{
    private const PSID = '7001';
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
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
            'database/migrations/2026_10_29_100000_create_botcake_send_requests_table.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true, 'services.botcake.async_sends' => false]);
        $this->page('Page Uno', '1001');
        $s = app(PageSettings::class);
        $s->set('Page Uno', 'chatbot', 'picture', 'https://img.example.test/p.jpg');
        $s->set('Page Uno', 'chatbot', 'main_flow', 'Hello! Only {{PRICE}} pesos');
        $s->set('Page Uno', 'chatbot', 'main_flow_2', 'Free shipping');
        $s->set('Page Uno', 'chatbot', 'fill_up_form', 'Name, address, phone');
        $s->set('Page Uno', 'chatbot', 'price', '499');
        $this->travelTo('2026-10-21 10:00:00');
    }

    private function sid(string $key): int
    {
        return (int) PageSetting::where('provider', 'chatbot')->where('key', $key)->where('active', true)->value('id');
    }

    /** Ang n10 ng CBB: apat na mensahe sa isang Send. $extra: dagdag na node pagkatapos ng n10 (n11). */
    private function flow(array $send = [], ?array $extra = null, bool $version = true): ChatbotFlow
    {
        $nodes = [['id' => 'n1', 'type' => 'start'], ['id' => 'n10', 'type' => 'send', 'config' => $send + ['items' => [
            ['type' => 'image', 'value' => '{{PICTURE}}'], ['type' => 'text', 'value' => '{{Main Flow}}'],
            ['type' => 'text', 'value' => '{{Main Flow 2}}'], ['type' => 'text', 'value' => '{{fill up form}} {{first_name}} {{CHAT STATUS}}'],
        ]]]];
        $edges = [['from' => 'n1', 'port' => 'next', 'to' => 'n10']];
        if ($extra) {
            $nodes[] = ['id' => 'n11'] + $extra;
            $edges[] = ['from' => 'n10', 'port' => 'continue', 'to' => 'n11'];
        }
        $graph = FlowGraph::clean(['nodes' => $nodes, 'edges' => $edges]);
        $flow = ChatbotFlow::create(['name' => 'Main Flow (from CBB) ' . uniqid(), 'graph' => $graph]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);
        if ($version) DB::table('chatbot_flow_versions')->insert(['flow_id' => $flow->id, 'graph' => json_encode($graph), 'created_at' => now()]);
        app(ChatbotPage::class)->link('Page Uno', $flow);

        return $flow->fresh();
    }

    private function chat(string $message = 'Hi', string $psid = self::PSID, string $pageId = '1001', string $page = 'Page Uno', array $payload = []): BotcakeChat
    {
        return BotcakeChat::create(['page_id' => $pageId, 'page_name' => $page, 'psid' => $psid, 'name' => 'Ana Cruz',
            'message' => $message, 'payload' => $payload, 'received_at' => now()]);
    }

    /** Dumating ang chat (gaya ng /api/botcake/chat): ang job ng Results. */
    private function arrive(string $message, string $psid, string $pageId = '1001', string $page = 'Page Uno'): BotcakeChat
    {
        $chat = $this->chat($message, $psid, $pageId, $page);
        (new MarkChatbotReplied($chat->id))->handle();

        return $chat;
    }

    private function runChat(BotcakeChat $chat): void
    {
        app(ChatbotRunner::class)->handle($chat->id);
    }

    private function lastReply(): object
    {
        return DB::table('chatbot_replies')->orderByDesc('id')->first();
    }

    /** Ang mga botfield (key => id) ng bawat mensahe sa tags ng reply. */
    private function messageTags(object $reply): array
    {
        return array_map(fn ($m) => $m['botfields'], (array) json_decode((string) $reply->tags, true)['messages']);
    }

    // ---- S-100.1 ----

    public static function paths(): array
    {
        return ['the run waits for Botcake' => [false], 'through the send pool' => [true]];
    }

    #[DataProvider('paths')]
    public function test_S_100_1_a_four_message_send_gets_four_tags_with_botfield_versions_flow_version_and_ad_id(bool $pool): void
    {
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Ana Cruz', 'psid' => self::PSID, 'status' => 'available', 'source' => 'api', 'ad_id' => '120200000000000001']);
        $flow = $this->flow();
        $version = (int) DB::table('chatbot_flow_versions')->where('flow_id', $flow->id)->value('id');
        if ($pool) {
            config(['services.botcake.async_sends' => true]);
            $calls = [];
            $sendPool = new BotcakeSendPool(function ($req, array $options) use (&$calls) { $p = new Promise(); $calls[] = $p; return $p; }, $this->clock);
            $this->assertTrue($sendPool->start());
            $this->runChat($this->chat());
            $this->assertSame(0, DB::table('chatbot_replies')->count(), 'the pool has not sent yet');
            $sendPool->tick();
            foreach ($calls as $p) $p->resolve(new Response(200, ['Content-Type' => 'application/json'], json_encode(['success' => true])));
            $sendPool->tick(false);
            $this->assertSame('done', DB::table('botcake_send_requests')->value('status') === 'resumed' ? 'done' : DB::table('botcake_send_requests')->value('status'));
        } else {
            Http::fake([self::SEND => Http::response(['success' => true])]);
            $this->runChat($this->chat());
        }

        $r = $this->lastReply();
        $this->assertSame('sent', $r->status);
        $this->assertSame([
            ['picture' => $this->sid('picture')],
            ['main_flow' => $this->sid('main_flow'), 'price' => $this->sid('price')],
            ['main_flow_2' => $this->sid('main_flow_2')],
            ['fill_up_form' => $this->sid('fill_up_form')],
        ], $this->messageTags($r));
        $tags = json_decode((string) $r->tags, true);
        $this->assertSame(['chat status'], $tags['messages'][3]['fields'], 'customer fields are listed; run variables like first_name are not');
        $this->assertSame([[], [], []], array_map(fn ($m) => $m['fields'], array_slice($tags['messages'], 0, 3)));
        $this->assertSame([(int) $flow->id, 'n10', $version, '120200000000000001'], [(int) $r->flow_id, $r->node_id, (int) $r->flow_version_id, $r->ad_id]);
        $this->assertNull($r->sequence_id);

        // Isang row kada mensahe at botfield, para sa "By message".
        $rows = DB::table('chatbot_reply_tags')->where('reply_id', $r->id)->orderBy('message')->orderBy('id')->get();
        $this->assertSame([[0, 'picture'], [1, 'main_flow'], [1, 'price'], [2, 'main_flow_2'], [3, 'fill_up_form']], $rows->map(fn ($t) => [(int) $t->message, $t->botfield])->all());
        $this->assertSame([$version], $rows->pluck('flow_version_id')->map(fn ($v) => (int) $v)->unique()->values()->all());
        $this->assertSame(['n10'], $rows->pluck('node_id')->unique()->values()->all());
    }

    // ---- S-100.2 ----

    public function test_S_100_2_a_sequence_step_records_its_sequence_and_step_and_a_manual_send_has_no_flow_but_the_ad_id(): void
    {
        Http::fake([self::SEND => Http::response(['success' => true])]);
        $this->chat('Hi', self::PSID, '1001', 'Page Uno', ['ad_id' => '120200000000000999']);
        $stepFlow = ChatbotFlow::create(['name' => 'SEQ step 2', 'graph' => FlowGraph::clean(['nodes' => [['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => '{{Main Flow 2}}']]]]], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]])]);
        DB::table('chatbot_flows')->where('id', $stepFlow->id)->update(['published_at' => now()]);
        $seq = ChatbotSequence::create(['name' => 'SEQ', 'active' => true, 'exit_on_reply' => true]);
        $seq->steps()->create(['position' => 1, 'delay_amount' => 0, 'delay_unit' => 'minutes', 'flow_id' => null, 'active' => false]);
        $seq->steps()->create(['position' => 2, 'delay_amount' => 0, 'delay_unit' => 'minutes', 'flow_id' => $stepFlow->id, 'active' => true]);
        app(ChatbotSequences::class)->subscribe($seq->id, 1001, (int) self::PSID, 'other');
        Artisan::call('chatbot:sequence-tick');

        $r = $this->lastReply();
        $this->assertSame([(int) $seq->id, 2, (int) $stepFlow->id, 'n2'], [(int) $r->sequence_id, (int) $r->sequence_step, (int) $r->flow_id, $r->node_id]);
        $this->assertSame([['main_flow_2' => $this->sid('main_flow_2')]], $this->messageTags($r));
        $this->assertSame('120200000000000999', $r->ad_id, 'the ad of the first chat when botcake_psids has none');
        $this->assertNull($r->flow_version_id, 'no version row: null id');
        $this->assertNotEmpty(json_decode((string) $r->tags, true)['flow_hash']);

        // Inbox manual send.
        $this->actingAs($this->user());
        $this->postJson('/chatbot/inbox/send', ['page_id' => '1001', 'psid' => self::PSID, 'text' => 'Hello po'])->assertOk();
        $m = $this->lastReply();
        $this->assertSame(['manual', null, null, '120200000000000999'], [$m->kind, $m->flow_id, $m->flow_version_id, $m->ad_id]);
        $this->assertSame([[]], $this->messageTags($m));
        $this->assertSame(1, DB::table('chatbot_reply_tags')->where('reply_id', $m->id)->whereNull('botfield')->count());
    }

    // ---- S-100.3 ----

    public function test_S_100_3_editing_a_botfield_gives_the_next_send_a_new_version_id(): void
    {
        Http::fake([self::SEND => Http::response(['success' => true])]);
        $this->flow();
        $this->runChat($this->chat('Hi', '7001'));
        $old = $this->sid('main_flow_2');
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'main_flow_2', 'Free shipping today only');
        $new = $this->sid('main_flow_2');
        $this->runChat($this->chat('Hi', '7002'));

        $this->assertNotSame($old, $new);
        $first = DB::table('chatbot_replies')->where('psid', 7001)->first();
        $second = DB::table('chatbot_replies')->where('psid', 7002)->first();
        $this->assertSame(['main_flow_2' => $old], $this->messageTags($first)[2]);
        $this->assertSame(['main_flow_2' => $new], $this->messageTags($second)[2]);
    }

    // ---- S-100.4 ----

    public function test_S_100_4_each_publish_gives_its_sends_that_publishs_version_id_and_no_version_row_gives_a_hash(): void
    {
        Http::fake([self::SEND => Http::response(['success' => true])]);
        $flow = $this->flow();
        $v1 = (int) DB::table('chatbot_flow_versions')->where('flow_id', $flow->id)->value('id');
        $this->runChat($this->chat('Hi', '7001'));

        // Publish ulit (gaya ng ChatbotFlowController::publish): bagong graph at bagong version row.
        $graph = $flow->graph;
        $graph['nodes'][1]['config']['items'][2]['value'] = '{{Main Flow 2}}!';
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['graph' => json_encode($graph), 'published_at' => now()]);
        $v2 = DB::table('chatbot_flow_versions')->insertGetId(['flow_id' => $flow->id, 'graph' => json_encode($graph), 'created_at' => now()]);
        $this->runChat($this->chat('Hi', '7002'));

        $this->assertSame($v1, (int) DB::table('chatbot_replies')->where('psid', 7001)->value('flow_version_id'));
        $this->assertSame($v2, (int) DB::table('chatbot_replies')->where('psid', 7002)->value('flow_version_id'));
        $h1 = json_decode((string) DB::table('chatbot_replies')->where('psid', 7001)->value('tags'), true)['flow_hash'];
        $h2 = json_decode((string) DB::table('chatbot_replies')->where('psid', 7002)->value('tags'), true)['flow_hash'];
        $this->assertNotSame($h1, $h2);
        $this->assertSame(ChatbotTags::graphHash($graph), $h2, 'the hash is stable for the same graph');
    }

    public function test_S_100_4_b_publishing_an_earlier_graph_again_gives_the_new_publish_its_own_version_id(): void
    {
        Http::fake([self::SEND => Http::response(['success' => true])]);
        $this->actingAs($this->user());
        $flow = $this->flow([], null, false);
        $g1 = $flow->graph;
        $g2 = $g1;
        $g2['nodes'][1]['config']['items'][2]['value'] = '{{Main Flow 2}}!';
        $publish = function (array $graph) use ($flow) {
            $this->putJson("/chatbot/flows/{$flow->id}/graph", ['graph' => $graph, 'version' => ChatbotFlow::find($flow->id)->draft_version])->assertOk();
            $this->postJson("/chatbot/flows/{$flow->id}/publish", ['version' => ChatbotFlow::find($flow->id)->draft_version])->assertOk();
            return (int) DB::table('chatbot_flow_versions')->where('flow_id', $flow->id)->max('id');
        };
        $v1 = $publish($g2);
        $this->runChat($this->chat('Hi', '7001'));
        $v2 = $publish($g1);
        $this->runChat($this->chat('Hi', '7002'));
        $v3 = $publish($g2); // published again after going back: a Publish of its own
        $this->runChat($this->chat('Hi', '7003'));

        $this->assertSame([$v1, $v2, $v3], DB::table('chatbot_replies')->orderBy('id')->pluck('flow_version_id')->map(fn ($v) => (int) $v)->all());
    }

    // ---- S-100.5 ----

    public function test_S_100_5_the_next_chat_within_24h_marks_the_send_replied_and_nothing_else_does(): void
    {
        Http::fake([self::SEND => Http::response(['success' => true])]);
        $this->flow([], ['type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Second node']]]]);
        $this->runChat($this->chat('Hi', '7001'));
        $this->assertSame(2, DB::table('chatbot_replies')->where('psid', 7001)->count(), 'two nodes = two send groups');

        // Ang sariling padala ng bot (ibang node, mamaya) ay hindi sagot.
        $this->travel(5)->minutes();
        DB::table('chatbot_replies')->insert(['page_id' => 1001, 'psid' => 7001, 'kind' => 'send', 'status' => 'sent', 'content' => '[]', 'sent_at' => now()]);
        $this->assertSame(0, DB::table('chatbot_replies')->whereNotNull('replied_at')->count());

        // Chat sa ibang page ng parehong PSID: hindi sagot sa page na ito.
        $this->page('Page Dos', '1002');
        $this->arrive('Hello', '7001', '1002', 'Page Dos');
        $this->assertSame(0, DB::table('chatbot_replies')->whereNotNull('replied_at')->count());

        $this->travel(10)->minutes();
        $this->arrive('How much?', '7001');
        $replied = DB::table('chatbot_replies')->where('psid', 7001)->orderBy('id')->get();
        $this->assertSame([900, 900, 600], $replied->map(fn ($r) => (int) $r->reply_seconds)->all());
        $this->assertSame([now()->format('Y-m-d H:i:s')], $replied->pluck('replied_at')->map(fn ($v) => substr((string) $v, 0, 19))->unique()->values()->all());

        // Lampas 24 oras: hindi na sagot. Ang naunang sagot ay hindi binabago ng kasunod na chat.
        Http::fake([self::SEND => Http::response(['success' => true])]);
        DB::table('chatbot_replies')->insert(['page_id' => 1001, 'psid' => 7002, 'kind' => 'send', 'status' => 'sent', 'content' => '[]', 'sent_at' => now()]);
        $this->travel(25)->hours();
        $this->arrive('Late', '7002');
        $this->arrive('Again', '7001');
        $this->assertNull(DB::table('chatbot_replies')->where('psid', 7002)->value('replied_at'));
        $this->assertSame([900, 900, 600], DB::table('chatbot_replies')->where('psid', 7001)->orderBy('id')->limit(3)->pluck('reply_seconds')->map(fn ($v) => (int) $v)->all());

        // Ang backfill: ang padalang hindi naabot ng job (hal. patay ang queue) ay napupunan.
        DB::table('chatbot_replies')->insert(['page_id' => 1001, 'psid' => 7003, 'kind' => 'send', 'status' => 'sent', 'content' => '[]', 'sent_at' => now()]);
        $this->travel(2)->minutes();
        DB::table('botcake_chats')->insert(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => '7003', 'name' => 'Cy', 'message' => 'ok', 'payload' => '[]', 'received_at' => now()]);
        Artisan::call('chatbot:replied-backfill');
        $this->assertSame(120, (int) DB::table('chatbot_replies')->where('psid', 7003)->value('reply_seconds'));

        // Ang chat na dumaan sa /api/botcake/chat ay nagpapasa ng job sa queue na chatbot.
        Queue::fake();
        app(PageSettings::class)->set('Page Uno', 'botcake', 'chat.key', 'page-key-uno-test-a1s2d3f4g5h6j7k8l9', true, null, true);
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Ana Cruz', 'psid' => '7004', 'status' => 'available', 'source' => 'api']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.171.22.6'])->postJson('/api/botcake/chat',
            ['page_id' => '1001', 'psid' => '7004', 'name' => 'Ana Cruz', 'message' => 'hi'], ['X-Chat-Key' => 'page-key-uno-test-a1s2d3f4g5h6j7k8l9'])->assertOk();
        Queue::assertPushedOn('chatbot', MarkChatbotReplied::class, fn ($job) => $job->chatId === BotcakeChat::where('psid', '7004')->value('id'));
    }

    // ---- S-100.6 ----

    public function test_S_100_6_a_ticked_node_writes_one_order_event_per_customer_per_24h(): void
    {
        Http::fake([self::SEND => Http::response(['success' => true])]);
        $seq = ChatbotSequence::create(['name' => 'SEQ', 'active' => true, 'exit_on_reply' => true]);
        DB::table('chatbot_replies')->insert(['page_id' => 1001, 'psid' => 7001, 'kind' => 'send', 'status' => 'sent', 'content' => '[]',
            'sequence_id' => $seq->id, 'sequence_step' => 3, 'sent_at' => now()->subHours(3)]);
        $flow = $this->flow([], ['type' => 'set', 'config' => ['ops' => [['op' => 'set', 'field' => 'order', 'value' => 'yes']], 'counts_order' => true]]);
        $version = (int) DB::table('chatbot_flow_versions')->where('flow_id', $flow->id)->value('id');
        $this->runChat($this->chat('Order po', '7001'));

        $orders = DB::table('chatbot_events')->where('type', 'order')->get();
        $this->assertCount(1, $orders);
        $d = json_decode($orders[0]->detail, true);
        $sends = DB::table('chatbot_replies')->where('psid', 7001)->orderByDesc('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([1001, 7001, (int) $flow->id, 'n11'], [(int) $orders[0]->page_id, (int) $orders[0]->psid, (int) $orders[0]->flow_id, $orders[0]->node_id]);
        $this->assertSame($version, $d['flow_version']);
        $this->assertSame($sends, $d['sends'], 'the last sends before it, newest first');
        $this->assertSame([(int) $seq->id, 3], [$d['sequence_id'], $d['sequence_step']]);
        $this->assertNotEmpty($d['run']);

        // Ulit sa loob ng 24 oras: walang bago. Pagkalipas ng 24 oras: bago.
        $this->travel(23)->hours();
        $this->runChat($this->chat('Order ulit', '7001'));
        $this->assertSame(1, DB::table('chatbot_events')->where('type', 'order')->count());
        $this->travel(2)->hours();
        $this->runChat($this->chat('Order ulit', '7001'));
        $this->assertSame(2, DB::table('chatbot_events')->where('type', 'order')->count());

        // Ang sequence step na lampas 92 araw na ay hindi na ang step ng customer.
        DB::table('chatbot_replies')->insert(['page_id' => 1001, 'psid' => 7004, 'kind' => 'send', 'status' => 'sent', 'content' => '[]',
            'sequence_id' => $seq->id, 'sequence_step' => 5, 'sent_at' => now()->subDays(100)]);
        $this->runChat($this->chat('Order', '7004'));
        $this->assertNull(json_decode(DB::table('chatbot_events')->where('type', 'order')->where('psid', 7004)->value('detail'), true)['sequence_id']);

        // Hindi naka-tick, at ang pumalyang node: walang order.
        $this->flow([], ['type' => 'set', 'config' => ['ops' => [['op' => 'set', 'field' => 'order', 'value' => 'yes']]]]);
        $this->runChat($this->chat('Order', '7002'));
        $this->flow([], ['type' => 'sheet', 'config' => ['sheet' => 'not a sheet', 'tab' => 'Orders', 'columns' => ['x'], 'counts_order' => true]]);
        $this->runChat($this->chat('Order', '7003'));
        $this->assertSame(0, DB::table('chatbot_events')->where('type', 'order')->whereIn('psid', [7002, 7003])->count());
    }

    public function test_S_100_6_c_a_ticked_send_through_the_pool_counts_once_after_it_is_sent_and_not_when_refused(): void
    {
        config(['services.botcake.async_sends' => true]);
        $this->flow(['counts_order' => true]);
        $calls = [];
        $pool = new BotcakeSendPool(function ($req, array $options) use (&$calls) { $p = new Promise(); $calls[] = $p; return $p; }, $this->clock);
        $this->assertTrue($pool->start());
        $answer = function (int $status, array $body) use (&$calls, $pool) {
            $pool->tick();
            foreach ($calls as $p) if ($p->getState() === 'pending') $p->resolve(new Response($status, ['Content-Type' => 'application/json'], json_encode($body)));
            $pool->tick(false);
        };

        $this->runChat($this->chat('Order', '7001'));
        $this->assertSame(0, DB::table('chatbot_events')->where('type', 'order')->count(), 'not before the send is made');
        $answer(200, ['success' => true]);
        $orders = DB::table('chatbot_events')->where('type', 'order')->get();
        $this->assertCount(1, $orders);
        $this->assertSame(['n10', [(int) DB::table('chatbot_replies')->where('psid', 7001)->value('id')]], [$orders[0]->node_id, json_decode($orders[0]->detail, true)['sends']]);

        $this->runChat($this->chat('Order', '7002'));
        $answer(200, ['success' => false, 'message' => 'Outside the 24h window']);
        $this->assertSame(0, DB::table('chatbot_events')->where('type', 'order')->where('psid', 7002)->count(), 'a refused send is no order');
    }

    public function test_S_100_6_d_the_daily_prune_keeps_order_events(): void
    {
        foreach (['order', 'run_started'] as $type) {
            DB::table('chatbot_events')->insert(['page_id' => 1001, 'psid' => 7001, 'type' => $type, 'detail' => '{}', 'created_at' => now()->subDays(40)]);
        }
        \App\Services\Chatbot\ChatbotEvents::prune();
        $this->assertSame(['order'], DB::table('chatbot_events')->pluck('type')->all());
    }

    public function test_S_100_6_b_the_test_tab_writes_no_order(): void
    {
        $flow = $this->flow([], ['type' => 'set', 'config' => ['ops' => [['op' => 'set', 'field' => 'order', 'value' => 'yes']], 'counts_order' => true]]);
        $this->actingAs($this->user());
        $this->postJson('/chatbot/test/send', ['flow_id' => $flow->id, 'page_name' => 'Page Uno', 'version' => 'published', 'message' => 'Order'])->assertOk();
        $this->assertSame(0, DB::table('chatbot_events')->where('type', 'order')->count());
    }

    // ---- S-100.7 ----

    public function test_S_100_7_counts_order_is_kept_only_when_ticked_on_send_set_and_sheet(): void
    {
        foreach (['send' => ['items' => []], 'set' => ['ops' => []], 'sheet' => ['sheet' => '', 'tab' => '', 'columns' => []]] as $type => $base) {
            $this->assertTrue(FlowGraph::cleanConfig($type, $base + ['counts_order' => '1'])['counts_order'], $type);
            $this->assertArrayNotHasKey('counts_order', FlowGraph::cleanConfig($type, $base + ['counts_order' => false]), $type);
            $this->assertArrayNotHasKey('counts_order', FlowGraph::cleanConfig($type, $base), $type . ': an old graph is unchanged');
        }
        $this->assertArrayNotHasKey('counts_order', FlowGraph::cleanConfig('ai', ['counts_order' => true]));
    }
}
