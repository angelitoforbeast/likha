<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\Chatbot\FlowGraph;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Ang draft ng flow: ang editor ay sumusulat sa draft_graph, ang live na takbo ay sa graph (published) lang hanggang
 * Publish. Discard, History at Restore, ang version check (dalawang nag-e-edit), at ang Test na draft o published.
 */
class ChatbotDraftPublishTest extends ChatblastTestCase
{
    private const SEND = 'https://botcake.io/api/public_api/v1/pages/1001/flows/send_content';
    private const DRAFT_MIGRATION = 'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
        ]]);
        // S-075.7 ang magpapatakbo nito, pagkatapos may flow na.
        if (!str_contains($this->name(), 'S_075_7')) Artisan::call('migrate', ['--path' => self::DRAFT_MIGRATION]);
        config(['services.chatbot.replies_enabled' => true]);
        $this->page('Page Uno', '1001');
        Http::fake([self::SEND => Http::response(['success' => true]), 'api.openai.com/*' => Http::response([], 500)]);
    }

    /** Start → Send "$text". */
    private function says(string $text): array
    {
        return FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start', 'x' => 40, 'y' => 40],
            ['id' => 'n2', 'type' => 'send', 'x' => 300, 'y' => 40, 'config' => ['items' => [['type' => 'text', 'value' => $text]]]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]]);
    }

    /** Isang flow na published na at nakatali sa Page Uno. */
    private function live(string $text = 'Old reply'): ChatbotFlow
    {
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => $this->says($text)]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);
        app(ChatbotPage::class)->link('Page Uno', $flow);

        return $flow->fresh();
    }

    /** Ang text na naipadala sa isang live na chat ngayon. */
    private function liveReply(string $psid = '7001'): ?string
    {
        $chat = BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => $psid, 'name' => 'Ana Cruz', 'message' => 'Hi', 'payload' => [], 'received_at' => now()]);
        $sends = fn () => Http::recorded(fn (HttpRequest $r) => $r->url() === self::SEND);
        $before = $sends()->count();
        app(ChatbotRunner::class)->handle($chat->id);
        if ($sends()->count() === $before) return null;

        return $sends()->last()[0]->data()['data']['content']['messages'][0]['text'] ?? null;
    }

    private function save(ChatbotFlow $flow, array $graph, ?int $version = null, array $extra = [])
    {
        return $this->putJson("/chatbot/flows/{$flow->id}/graph", ['graph' => $graph, 'version' => $version ?? ChatbotFlow::find($flow->id)->draft_version] + $extra);
    }

    private function act(ChatbotFlow $flow, string $what, array $body = [])
    {
        return $this->postJson("/chatbot/flows/{$flow->id}/{$what}", $body + ['version' => ChatbotFlow::find($flow->id)->draft_version]);
    }

    public function test_S_075_1_an_edit_does_not_reach_live_runs_until_publish(): void
    {
        $flow = $this->live('Old reply');
        $this->actingAs($ceo = $this->user());

        $this->save($flow, $this->says('New reply'))->assertOk()->assertJson(['status' => 'draft']);

        $this->assertSame('Old reply', $this->liveReply('7001'));
        $this->assertSame($this->says('New reply'), $this->getJson("/chatbot/flows/{$flow->id}/graph")->json('graph'), 'ang editor ay nasa draft');

        $this->act($flow, 'publish', ['note' => 'New greeting'])->assertOk()->assertJson(['status' => 'published']);

        $this->assertSame('New reply', $this->liveReply('7002'));
        $fresh = ChatbotFlow::find($flow->id);
        $this->assertNull($fresh->draft_graph);
        $this->assertSame($ceo->id, $fresh->published_by);
        $this->assertNotNull($fresh->published_at);
    }

    public function test_S_075_2_publish_refuses_a_graph_that_does_not_pass_clean(): void
    {
        $flow = $this->live('Old reply');
        $bad = ['nodes' => [['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'start']], 'edges' => []];
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['draft_graph' => json_encode($bad), 'draft_version' => 4]);
        $this->actingAs($this->user());

        $this->act($flow, 'publish')->assertStatus(422)->assertJson(['error' => 'This flow cannot be published: A flow has exactly one Start.']);

        $this->assertSame($this->says('Old reply'), ChatbotFlow::find($flow->id)->graph);
        $this->assertSame(0, DB::table('chatbot_flow_versions')->count());
        $this->assertSame('Old reply', $this->liveReply());

        DB::table('chatbot_flows')->where('id', $flow->id)->update(['draft_graph' => null]);
        $this->act($flow, 'publish')->assertStatus(422)->assertJson(['error' => 'There are no unpublished changes.']);
    }

    public function test_S_075_3_discard_brings_back_the_published_graph(): void
    {
        $flow = $this->live('Old reply');
        $this->actingAs($this->user());
        $this->save($flow, $this->says('Half done'))->assertOk();

        $this->act($flow, 'discard')->assertOk()->assertJson(['status' => 'published', 'graph' => $this->says('Old reply')]);

        $this->assertNull(ChatbotFlow::find($flow->id)->draft_graph);
        $this->assertSame($this->says('Old reply'), $this->getJson("/chatbot/flows/{$flow->id}/graph")->assertJson(['status' => 'published'])->json('graph'));
        $this->assertSame($this->says('Old reply'), ChatbotFlow::find($flow->id)->graph);

        // Ang flow na hindi pa naipa-publish ay walang published na mababalikan: hindi binubura ang draft.
        $fresh = ChatbotFlow::create(['name' => 'Fresh', 'graph' => FlowGraph::blank()]);
        $this->save($fresh, $this->says('Work in progress'))->assertOk();
        $this->act($fresh, 'discard')->assertStatus(422)->assertJson(['error' => 'This flow was never published, so there is no published version to go back to.']);
        $this->assertSame($this->says('Work in progress'), ChatbotFlow::find($fresh->id)->draft_graph);
    }

    public function test_S_075_4_history_lists_publishes_and_restore_puts_one_in_the_draft(): void
    {
        $flow = $this->live('Old reply');
        $this->actingAs($ceo = $this->user());
        foreach (['One', 'Two', 'Three'] as $text) {
            $this->save($flow, $this->says("Reply {$text}"))->assertOk();
            $this->act($flow, 'publish', ['note' => "Note {$text}"])->assertOk();
        }

        $list = $this->getJson("/chatbot/flows/{$flow->id}/versions")->assertOk()->json('versions');
        $this->assertSame(['Note Three', 'Note Two', 'Note One'], array_column($list, 'note'));
        $this->assertSame(['CEO User'], array_values(array_unique(array_column($list, 'by'))));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $list[0]['at']);

        $this->act($flow, 'versions/' . $list[2]['id'] . '/restore')->assertOk()->assertJson(['status' => 'draft', 'graph' => $this->says('Reply One')]);
        $fresh = ChatbotFlow::find($flow->id);
        $this->assertSame($this->says('Reply One'), $fresh->draft_graph);
        $this->assertSame($this->says('Reply Three'), $fresh->graph, 'ang Restore ay hindi nagpa-publish');
        $this->assertSame('Reply Three', $this->liveReply());

        $other = ChatbotFlow::create(['name' => 'Other', 'graph' => FlowGraph::blank()]);
        $this->act($other, 'versions/' . $list[0]['id'] . '/restore')->assertNotFound();

        // 20 sa listahan, 50 ang itinatago kada flow.
        $rows = [];
        for ($i = 0; $i < 52; $i++) $rows[] = ['flow_id' => $flow->id, 'graph' => json_encode($this->says("R{$i}")), 'note' => "N{$i}", 'published_by' => $ceo->id, 'created_at' => now()];
        DB::table('chatbot_flow_versions')->insert($rows);
        $this->assertCount(20, $this->getJson("/chatbot/flows/{$flow->id}/versions")->json('versions'));
        $this->act($flow, 'publish', ['note' => 'Latest'])->assertOk();
        $this->assertSame(50, DB::table('chatbot_flow_versions')->where('flow_id', $flow->id)->count());
        $this->assertSame('Latest', DB::table('chatbot_flow_versions')->where('flow_id', $flow->id)->orderByDesc('id')->value('note'));
        $this->assertSame('N3', DB::table('chatbot_flow_versions')->where('flow_id', $flow->id)->orderBy('id')->value('note'), 'ang 6 na pinakaluma ang nabura');
    }

    public function test_S_075_5_a_write_on_a_stale_draft_version_is_refused(): void
    {
        $flow = $this->live('Old reply');
        $this->actingAs($this->user());
        $opened = ChatbotFlow::find($flow->id)->draft_version;
        $this->save($flow, $this->says('First person'), $opened)->assertOk();
        $message = 'This draft changed since you opened it. Reload, or overwrite?';

        $this->save($flow, $this->says('Second person'), $opened)->assertStatus(409)->assertJson(['error' => $message, 'conflict' => true]);
        $this->putJson("/chatbot/flows/{$flow->id}/graph", ['graph' => $this->says('No version')])->assertStatus(409);
        $this->act($flow, 'publish')->assertOk();
        $restore = 'versions/' . DB::table('chatbot_flow_versions')->value('id') . '/restore';
        $this->save($flow, $this->says('First person again'))->assertOk();
        foreach (['publish', 'discard', $restore] as $what) {
            foreach ([['version' => $opened], []] as $body) $this->postJson("/chatbot/flows/{$flow->id}/{$what}", $body)->assertStatus(409)->assertJson(['error' => $message]);
        }
        $this->assertSame($this->says('First person again'), ChatbotFlow::find($flow->id)->draft_graph);
        $this->assertSame($this->says('First person'), ChatbotFlow::find($flow->id)->graph);
        $opened = ChatbotFlow::find($flow->id)->draft_version - 1;

        $this->save($flow, $this->says('Second person'), $opened, ['overwrite' => true])->assertOk();
        $this->assertSame($this->says('Second person'), ChatbotFlow::find($flow->id)->draft_graph);
    }

    public function test_S_075_6_test_runs_the_draft_or_the_published_version(): void
    {
        $flow = $this->live('Published reply');
        $this->actingAs($this->user());
        $this->save($flow, $this->says('Draft reply'))->assertOk();
        $text = fn (array $res) => $res['sends'][0]['messages'][0]['text'];

        $this->assertSame('Draft reply', $text($this->postJson('/chatbot/test/send', ['page_name' => 'Page Uno', 'flow_id' => $flow->id, 'message' => 'Hi'])->assertOk()->assertJson(['version' => 'draft'])->json()));
        $this->assertSame('Published reply', $text($this->postJson('/chatbot/test/send', ['page_name' => 'Page Uno', 'flow_id' => $flow->id, 'version' => 'published', 'message' => 'Hi'])->assertOk()->assertJson(['version' => 'published'])->json()));
        $this->assertSame('Draft reply', $text($this->postJson('/chatbot/test/send', ['page_name' => 'Page Uno', 'version' => 'draft', 'message' => 'Hi'])->assertOk()->json()), 'ang flow ng page, draft');
        $this->get("/chatbot/test?flow={$flow->id}&version=published")->assertOk()->assertSee('value="published" selected', false);
    }

    public function test_S_075_7_existing_flows_keep_running_after_the_migration(): void
    {
        $id = DB::table('chatbot_flows')->insertGetId(['name' => 'Main', 'graph' => json_encode($this->says('Before the migration')), 'created_at' => '2026-10-18 08:00:00', 'updated_at' => '2026-10-18 09:00:00']);
        app(ChatbotPage::class)->link('Page Uno', ChatbotFlow::find($id));

        Artisan::call('migrate', ['--path' => self::DRAFT_MIGRATION]);

        $flow = ChatbotFlow::find($id);
        $this->assertNull($flow->draft_graph);
        $this->assertSame(0, (int) $flow->draft_version);
        $this->assertSame('2026-10-18 09:00:00', $flow->published_at->format('Y-m-d H:i:s'));
        $this->assertSame('Before the migration', $this->liveReply());
        $this->actingAs($this->user());
        $this->getJson("/chatbot/flows/{$id}/graph")->assertOk()->assertJson(['status' => 'published', 'graph' => $this->says('Before the migration')]);
    }

    public function test_S_075_8_the_status_and_the_pages_using_the_flow(): void
    {
        $this->actingAs($this->user());
        $id = $this->postJson('/chatbot/flows', ['name' => 'Fresh'])->assertOk()->json('id');
        $fresh = ChatbotFlow::find($id);
        $this->getJson("/chatbot/flows/{$id}/graph")->assertJson(['status' => 'never', 'version' => 0, 'pages' => []]);
        $this->save($fresh, $this->says('Hi'))->assertOk()->assertJson(['status' => 'never', 'version' => 1]);
        $this->act($fresh, 'publish')->assertOk()->assertJson(['status' => 'published']);

        $flow = $this->live('Old reply');
        $this->page('Page Dos', '2002');
        app(ChatbotPage::class)->link('Page Dos', $flow);
        $this->getJson("/chatbot/flows/{$flow->id}/graph")->assertJson(['status' => 'published', 'pages' => ['Page Dos', 'Page Uno']]);
        $this->save($flow, $this->says('Changed'))->assertOk()->assertJson(['status' => 'draft']);
        $this->save($flow, $this->says('Old reply'))->assertOk()->assertJson(['status' => 'published']);
        $this->assertNull(ChatbotFlow::find($flow->id)->draft_graph, 'ang draft na kapareho ng published ay walang draft');
        // Ang "1" at "01" (o "500" at "500.00") ay magkaiba: hindi nawawala ang ganitong pagbabago.
        $this->save($flow, $this->says('1'))->assertOk();
        $this->act($flow, 'publish')->assertOk();
        $this->save($flow, $this->says('01'))->assertOk()->assertJson(['status' => 'draft']);
        $this->assertSame($this->says('01'), ChatbotFlow::find($flow->id)->draft_graph);

        $html = $this->get("/chatbot/flows/{$flow->id}")->assertOk()->getContent();
        foreach (['Undo', 'Redo', 'Save draft', 'Publish', 'Discard draft', 'History', 'Preview / Test'] as $button) $this->assertStringContainsString('>' . $button . '<', $html);
    }

    public function test_S_075_9_duplicate_copies_the_published_graph_and_the_draft(): void
    {
        $flow = $this->live('Old reply');
        $this->actingAs($this->user());
        $this->save($flow, $this->says('Draft reply'))->assertOk();

        $copy = ChatbotFlow::find($this->postJson("/chatbot/flows/{$flow->id}/duplicate")->assertOk()->json('id'));

        $this->assertSame([$this->says('Old reply'), $this->says('Draft reply'), null], [$copy->graph, $copy->draft_graph, $copy->published_at]);
        $this->getJson("/chatbot/flows/{$copy->id}/graph")->assertJson(['status' => 'never']);

        // Ang binurang flow ay walang naiiwang history.
        $this->act($copy, 'publish')->assertOk();
        $this->deleteJson("/chatbot/flows/{$copy->id}")->assertOk();
        $this->assertSame(0, DB::table('chatbot_flow_versions')->where('flow_id', $copy->id)->count());
    }
}
