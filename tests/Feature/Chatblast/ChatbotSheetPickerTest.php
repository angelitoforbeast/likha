<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Models\ChatbotReply;
use App\Services\Chatbot\BotcakeOutbox;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotSheet;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\FlowRun;
use App\Services\PageSettings;
use Google\Service\Exception as GoogleException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Ang Google Sheet step sa editor: ang pangalan at mga tab ng spreadsheet, ang header ng tab (row 1), ang share na
 * mensahe, ang cache, ang preview ng row, at ang "Open row" na link. Fake na Google: walang tawag sa labas.
 */
class ChatbotSheetPickerTest extends ChatblastTestCase
{
    private const ID = '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789';

    /** Ang fake na Google: ilang beses tinawag, at ang isasagot (o ang code na itatapon). */
    public array $calls = ['info' => 0, 'headers' => 0, 'append' => 0];
    public ?int $refuse = null;
    public array $info = [];
    public array $headers = [];
    public ?string $updatedRange = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Cache::flush();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100000_add_inbox_indexes.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
        ]]);
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '2002');
        $this->info = ['title' => 'Likha Orders', 'tabs' => [['title' => 'Sheet1', 'gid' => 0, 'index' => 1], ['title' => 'All Orders', 'gid' => 777, 'index' => 0]]];
        $this->headers = ['All Orders' => ['Name', 'Phone', '', 'Address']];

        $test = $this;
        $this->app->instance(ChatbotSheet::class, new class($test) extends ChatbotSheet {
            public function __construct(private $test) {}
            protected function fetchInfo(string $spreadsheetId): array
            {
                $this->test->calls['info']++;
                if ($this->test->refuse) throw new \Google\Service\Exception('{"error": {"message": "The caller does not have permission sa-key-secret"}}', $this->test->refuse);
                return $this->test->info;
            }
            protected function fetchHeaders(string $spreadsheetId, string $tab): array
            {
                $this->test->calls['headers']++;
                return $this->test->headers[$tab] ?? [];
            }
            public function append(string $spreadsheetId, string $tab, array $row): ?string
            {
                $this->test->calls['append']++;
                return $this->test->updatedRange;
            }
        });
    }

    private function info(array $q)
    {
        return $this->getJson('/chatbot/sheet?' . http_build_query($q));
    }

    public function test_S_090_1_a_link_gives_the_title_and_the_tabs_in_sheet_order(): void
    {
        $this->info['title'] = str_repeat('T', 250);
        $this->actingAs($this->user());

        $data = $this->info(['sheet' => 'https://docs.google.com/spreadsheets/d/' . self::ID . '/edit#gid=0'])->assertOk()->json();
        $this->assertSame(self::ID, $this->info(['sheet' => 'docs.google.com/spreadsheets/d/' . self::ID . '/edit'])->assertOk()->json('id'), 'link na walang https://');

        $this->assertSame(self::ID, $data['id']);
        $this->assertSame(str_repeat('T', 200), $data['title']);
        $this->assertSame([['title' => 'All Orders', 'gid' => 777], ['title' => 'Sheet1', 'gid' => 0]], $data['tabs']);
        $this->assertNull($data['tab']);
        $this->assertSame(0, $this->calls['headers']);
    }

    public function test_S_090_2_picking_a_tab_gives_its_headers_and_a_gone_tab_is_missing(): void
    {
        $this->headers['All Orders'] = array_merge(['Name', str_repeat('h', 300)], array_fill(0, 120, 'x'));
        $this->actingAs($this->user());

        $tab = $this->info(['sheet' => self::ID, 'tab' => 'All Orders'])->assertOk()->json('tab');
        $this->assertSame(['title' => 'All Orders', 'gid' => 777, 'missing' => false], array_diff_key($tab, ['headers' => 1]));
        $this->assertCount(100, $tab['headers']);
        $this->assertSame(['Name', str_repeat('h', 200), 'x'], array_slice($tab['headers'], 0, 3));

        $gone = $this->info(['sheet' => self::ID, 'tab' => 'Old Orders'])->assertOk()->json('tab');
        $this->assertSame(['title' => 'Old Orders', 'gid' => null, 'missing' => true, 'headers' => []], $gone);
        $this->assertSame(1, $this->calls['headers'], 'ang nawalang tab ay hindi binabasa');
    }

    public static function refusals(): array
    {
        $share = 'Share this sheet with ' . ChatbotSheet::SHARE_EMAIL . ' as Editor, then press Check again.';

        return ['not shared' => [403, 422, $share], 'not found' => [404, 422, $share], 'other Google error' => [500, 502, 'Google did not answer. Try again in a minute.']];
    }

    /** @dataProvider refusals */
    public function test_S_090_3_google_refusing_gives_the_share_message_with_the_email_and_no_raw_error(int $code, int $status, string $error): void
    {
        $this->refuse = $code;
        $this->actingAs($this->user());

        $res = $this->info(['sheet' => self::ID, 'tab' => 'All Orders'])->assertStatus($status);

        $this->assertSame($status === 422, (bool) $res->json('share'));
        $this->assertSame($status === 422 ? ChatbotSheet::SHARE_EMAIL : null, $res->json('email'));
        $this->assertSame($error, $res->json('error'));
        $this->assertStringNotContainsString('permission', $res->getContent());
        $this->assertStringNotContainsString('sa-key-secret', $res->getContent());
        // Ang pagtanggi ay hindi naka-cache: ang "Check again" pagkatapos i-share ay gumagana agad.
        $this->refuse = null;
        $this->info(['sheet' => self::ID])->assertOk()->assertJsonPath('title', 'Likha Orders');
    }

    public function test_S_090_4_title_tabs_and_headers_are_cached_5_minutes_and_refresh_bypasses_it(): void
    {
        $this->actingAs($this->user());
        $q = ['sheet' => self::ID, 'tab' => 'All Orders'];

        $this->info($q)->assertOk();
        $this->info['title'] = 'Renamed';
        $this->headers['All Orders'] = ['Full name'];
        $this->assertSame('Likha Orders', $this->info($q)->json('title'));
        $this->assertSame([1, 1], [$this->calls['info'], $this->calls['headers']]);

        $fresh = $this->info($q + ['refresh' => 1])->json();
        $this->assertSame(['Renamed', ['Full name']], [$fresh['title'], $fresh['tab']['headers']]);
        $this->assertSame([2, 2], [$this->calls['info'], $this->calls['headers']]);

        $this->info['title'] = 'Later';
        $this->travel(301)->seconds();
        $this->assertSame('Later', $this->info($q)->json('title'));
    }

    public function test_S_090_5_an_old_sheet_step_opens_and_saves_unchanged(): void
    {
        $graph = FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start', 'x' => 40, 'y' => 40],
            ['id' => 'n43', 'type' => 'sheet', 'x' => 300, 'y' => 40, 'config' => ['sheet' => self::ID, 'tab' => 'Orders', 'columns' => ['{{full_name}}', '', '=TODAY()', '']]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n43']]]);
        $flow = ChatbotFlow::create(['name' => 'Main Flow (from CBB)', 'graph' => $graph, 'published_at' => now()]);
        $this->actingAs($this->user());

        $opened = $this->getJson("/chatbot/flows/{$flow->id}/graph")->assertOk()->json('graph');
        $this->putJson("/chatbot/flows/{$flow->id}/graph", ['graph' => $opened, 'version' => 0])->assertOk();

        $this->assertSame($graph, $opened);
        $this->assertSame(['{{full_name}}', '', '=TODAY()', ''], $opened['nodes'][1]['config']['columns']);
        $this->assertSame($graph, ChatbotFlow::find($flow->id)->draft(), 'ang na-save ay kapareho ng dati');
    }

    public function test_S_090_6_the_preview_fills_the_cells_of_a_recent_customer_with_the_formula_guard_and_writes_nothing(): void
    {
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::blank()]);
        app(ChatbotPage::class)->link('Page Uno', $flow);
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'price', '499');
        BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => '7001', 'name' => 'Ana Cruz', 'message' => 'old', 'received_at' => now()->subHour()]);
        $latest = BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => '7001', 'name' => 'Ana Cruz', 'message' => '=HYPERLINK("x")', 'received_at' => now()]);
        $older = BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => '7002', 'name' => 'Ben Reyes', 'message' => 'hi', 'received_at' => now()->subMinutes(5)]);
        $other = BotcakeChat::create(['page_id' => '2002', 'page_name' => 'Page Dos', 'psid' => '7003', 'name' => 'Cy', 'message' => 'x', 'received_at' => now()->addMinute()]);
        (new CustomerFields(1001, 7001))->set('size', '09171234567');
        $this->actingAs($this->user());
        $before = [ChatbotReply::count(), DB::table('chatbot_customer_values')->count(), DB::table('chatbot_events')->count()];

        $customers = $this->getJson("/chatbot/flows/{$flow->id}/sheet-customers")->assertOk()->json('customers');
        $this->assertSame([$latest->id, $older->id], array_column($customers, 'chat_id'), 'pinakabago muna, isa kada customer, mga page ng flow lang');
        $this->assertStringContainsString('Ana Cruz', $customers[0]['label']);

        $cells = $this->postJson("/chatbot/flows/{$flow->id}/sheet-preview", ['chat_id' => $latest->id,
            'columns' => ['{{full_name}}', '', '{{last_input}}', '=TODAY()', '{{size}}', 'P{{price}}']])->assertOk()->json('cells');

        $this->assertSame(["'Ana Cruz", '', "'=HYPERLINK(\"x\")", '=TODAY()', "'09171234567", "'P499"], $cells);
        $this->assertSame($before, [ChatbotReply::count(), DB::table('chatbot_customer_values')->count(), DB::table('chatbot_events')->count()]);
        $this->assertSame(0, $this->calls['append']);
        $this->postJson("/chatbot/flows/{$flow->id}/sheet-preview", ['chat_id' => $other->id, 'columns' => ['{{full_name}}']])->assertNotFound();
    }

    public function test_S_090_7_the_updated_range_is_kept_and_the_open_row_link_is_built(): void
    {
        config(['services.chatbot.replies_enabled' => true]);
        $this->updatedRange = "'All Orders'!A123:J123";
        $run = new FlowRun(1, [], 'Page Uno', '1001', [], new CustomerFields(1001, 7001), null, 'hi', 'Ana Cruz', '2026-10-20 10:00');

        app(BotcakeOutbox::class)->appendRow($run, 'n43', self::ID, 'All Orders', ["'Ana"]);
        $this->updatedRange = null;
        app(BotcakeOutbox::class)->appendRow($run, 'n43', self::ID, 'All Orders', ["'Ben"]);
        // Ang tab na bago pa lang (wala pa sa naka-cache na listahan): binabasa muli ang listahan nang isang beses.
        $this->info['tabs'][] = ['title' => 'Orders 2', 'gid' => 888, 'index' => 2];
        $this->updatedRange = "'Orders 2'!A9:B9";
        app(BotcakeOutbox::class)->appendRow($run, 'n43', self::ID, 'Orders 2', ["'Cy"]);
        // Hindi mabasa ang listahan pagkatapos maisulat: naisulat pa rin, may range, walang gid (walang link).
        $this->refuse = 500;
        $this->updatedRange = "'Returns'!A5:B5";
        app(BotcakeOutbox::class)->appendRow($run, 'n43', self::ID, 'Returns', ["'Di"]);
        ChatbotReply::create(['page_id' => 1001, 'psid' => 7001, 'node_id' => 'n43', 'kind' => 'sheet', 'status' => 'sent', 'sent_at' => now(),
            'content' => ['sheet' => self::ID, 'tab' => 'All Orders', 'row' => ['old']]]);
        [$new, $noRange, $newTab, $noGid, $old] = ChatbotReply::orderBy('id')->get()->all();

        $this->assertSame(['sheet' => self::ID, 'tab' => 'All Orders', 'row' => ["'Ana"], 'range' => "'All Orders'!A123:J123", 'gid' => 777], $new->content);
        $this->assertSame(['sheet' => self::ID, 'tab' => 'All Orders', 'row' => ["'Ben"]], $noRange->content);
        $this->assertSame([888, 'sent'], [$newTab->content['gid'], $newTab->status]);
        $this->assertSame(['sheet' => self::ID, 'tab' => 'Returns', 'row' => ["'Di"], 'range' => "'Returns'!A5:B5"], $noGid->content);
        $this->assertSame('sent', $noGid->status);
        $link = 'https://docs.google.com/spreadsheets/d/' . self::ID . '/edit#gid=777&range=A123';
        $this->assertSame($link, ChatbotSheet::rowLink($new->content));
        $this->assertSame([null, null, null, null, null], [ChatbotSheet::rowLink($noRange->content), ChatbotSheet::rowLink($old->content), ChatbotSheet::rowLink($noGid->content),
            ChatbotSheet::rowLink(['sheet' => 'x/../evil', 'range' => 'A1', 'gid' => 1]), ChatbotSheet::rowLink(['sheet' => self::ID, 'range' => '"><script>', 'gid' => 1])]);

        $this->actingAs($this->user());
        $this->assertSame($link, $this->getJson('/chatbot/replies/' . $new->id)->assertOk()->json('link'));
        $this->assertNull($this->getJson('/chatbot/replies/' . $old->id)->assertOk()->json('link'));
        $items = collect($this->getJson('/chatbot/inbox/thread?page_id=1001&psid=7001')->assertOk()->json('items'))->where('side', 'system')->values();
        $this->assertSame([$link, null, 'https://docs.google.com/spreadsheets/d/' . self::ID . '/edit#gid=888&range=A9', null, null], $items->pluck('link')->all());
    }

    public function test_S_090_8_the_endpoints_refuse_a_non_google_link_and_users_without_access(): void
    {
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::blank()]);
        $this->info(['sheet' => self::ID])->assertUnauthorized();
        $this->postJson("/chatbot/flows/{$flow->id}/sheet-preview", ['chat_id' => 1, 'columns' => []])->assertUnauthorized();
        $this->actingAs($this->user());
        foreach (['https://evil.example/spreadsheets/d/' . self::ID . '/edit', 'http://169.254.169.254/latest', 'not a sheet', '', self::ID . '/../x'] as $bad) {
            $this->info(['sheet' => $bad])->assertStatus(422)->assertJsonMissingPath('share');
        }
        $this->info(['sheet' => self::ID, 'tab' => str_repeat('t', 101)])->assertStatus(422);
        $this->assertSame(0, $this->calls['info']);

        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->info(['sheet' => self::ID])->assertForbidden();
        $this->getJson("/chatbot/flows/{$flow->id}/sheet-customers")->assertForbidden();
        $this->postJson("/chatbot/flows/{$flow->id}/sheet-preview", ['chat_id' => 1, 'columns' => []])->assertForbidden();
        $this->assertSame(0, $this->calls['info']);
    }
}
