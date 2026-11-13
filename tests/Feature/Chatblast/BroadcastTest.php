<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\SendChatblastMessage;
use App\Models\BotcakeMessage;
use App\Models\BotcakePsid;
use App\Models\ChatblastBroadcast;
use App\Models\ChatblastCampaign;
use App\Services\Broadcast;
use App\Services\ChatblastSender;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Spec 263 slice 1: broadcast to a chosen audience of PSIDs (S1 audience, S2 flow and text, S3 test send, S4 send, pace,
 * pause, stop, progress). Fake Botcake (Http::fake) only.
 */
class BroadcastTest extends ChatblastTestCase
{
    /** page id => list of flows (GET /flows) */
    private array $flows = [];
    /** page id => step => [body, http] */
    private array $answers = [];
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_11_19_100000_add_broadcast_to_chatblast_campaigns.php']]);
        $this->actingAs($this->user());
        $this->page('Page A', '1001');
        $this->page('Page B', '1002');
        $this->flows = [
            '1001' => [['id' => 5501, 'name' => 'CHATBLAST Shipping update'], ['id' => 6601, 'name' => 'Shipping Status'], ['id' => 6699, 'name' => 'Old', 'is_removed' => true]],
            '1002' => [['id' => 5501, 'name' => 'CHATBLAST Shipping update'], ['id' => 6602, 'name' => 'shipping  status']],
        ];
        Http::fake(['botcake.io/*' => fn (Request $req) => $this->answer($req)]);
    }

    private function answer(Request $req)
    {
        $path = (string) parse_url($req->url(), PHP_URL_PATH);
        preg_match('#/pages/([^/]+)(/.*)$#', $path, $m);
        [$pageId, $rest] = [$m[1] ?? '', $m[2] ?? ''];
        if ($req->method() === 'GET' && $rest === '/flows') {
            return Http::response(['success' => true, 'data' => ['flows' => $this->flows[$pageId] ?? [], 'folders' => []]]);
        }
        $step = str_ends_with($rest, '/flows/send_flow') ? 'flow' : ($req->method() === 'POST' ? 'write' : 'read');
        if (isset($this->answers[$pageId][$step])) return Http::response(...$this->answers[$pageId][$step]);
        preg_match('#/customer/([^/]+)/#', $rest, $c);
        $who = $pageId . '|' . ($c[1] ?? '');
        if ($step === 'write') $this->written[$who] = $req->data()['data'][0]['value'];
        if ($step === 'read') return Http::response(['success' => true, 'data' => [['custom_field_id' => 7701, 'value' => $this->written[$who] ?? null]]]);

        return Http::response(['success' => true]);
    }

    private function psid(string $page, ?string $pageId, string $psid, array $extra = []): BotcakePsid
    {
        return BotcakePsid::create(array_merge([
            'page' => $page, 'page_id' => $pageId, 'fb_name' => 'Name ' . $psid, 'psid' => $psid, 'status' => 'available', 'source' => 'api',
            'subscribed_at' => '2026-07-01 10:00:00', 'last_interactive_at' => '2026-10-01 10:00:00',
        ], $extra));
    }

    private function input(array $over = []): array
    {
        return array_merge(['pages' => ['Page A'], 'orders' => 'any', 'phone' => 'any', 'text' => 'Sale today, 11.11!', 'flow' => '', 'cap' => 300], $over);
    }

    /**
     * What the page does: save the draft (no count), then ask the count of each page, one request per page. Returns the
     * last answer (the summary with every page counted), or the draft's answer when it was refused.
     */
    private function preview(array $over = [], ?int $id = null)
    {
        $r = $this->postJson('/chatblast/broadcast/preview', $this->input($over) + ($id ? ['id' => $id] : []));
        if ($r->status() !== 200) return $r;
        foreach ($r->json('pages') as $p) $r = $this->postJson('/chatblast/broadcast/' . $r->json('id') . '/count', ['page' => $p['page']])->assertOk();

        return $r;
    }

    /** Preview, then start with the total the preview showed. */
    private function startNew(array $over = []): int
    {
        $p = $this->preview($over)->assertOk()->json();
        $this->postJson('/chatblast/broadcast/' . $p['id'] . '/start', ['total' => $p['total']])->assertOk();

        return $p['id'];
    }

    private function rows(int $id)
    {
        return BotcakeMessage::where('campaign_id', $id)->where('kind', Broadcast::KIND)->where('is_test', false)->orderBy('id');
    }

    /** @return array<int, array{0: string, 1: string}> [page id, step] of every send request (not the flows reads) */
    private function sends(): array
    {
        return collect(Http::recorded())->map(fn ($r) => $r[0])->reject(fn ($req) => str_ends_with((string) parse_url($req->url(), PHP_URL_PATH), '/flows'))
            ->map(function ($req) {
                preg_match('#/pages/([^/]+)(/.*)$#', (string) parse_url($req->url(), PHP_URL_PATH), $m);
                return [$m[1], str_ends_with($m[2], '/flows/send_flow') ? 'flow' : ($req->method() === 'POST' ? 'write' : 'read')];
            })->values()->all();
    }

    // ---- S1 Build an audience ----

    public function test_s1_1_count_per_page_and_total_match_a_plain_sql_count(): void
    {
        $this->psid('Page A', '1001', 'a1');                                         // in
        $this->psid('Page A', '1001', 'a2');                                         // has an order on A: out
        $this->order('Page A', 'Name a2', null, 'a2');
        $this->psid('Page A', '1001', 'a3', ['subscribed_at' => '2026-08-01 00:00:00']); // not before Aug 1: out
        $this->psid('Page A', '1001', 'a4', ['status' => 'inactive']);               // not available: out
        $this->psid('Page A', '1001', 'a5', ['status' => 'verified', 'source' => 'chat_verify']); // in
        $this->psid('Page B', '1002', 'b1');                                         // in
        $this->psid(' page b ', null, 'b2', ['subscribed_at' => '2026-05-01 00:00:00']); // no page_id yet, same page by name: in
        $this->psid('Page B', '1002', 'b3', ['subscribed_at' => null]);              // no subscribed date: out

        $in = $this->input(['pages' => ['Page A', 'Page B'], 'orders' => 'without', 'subscribed_before' => '2026-08-01']);
        $draft = $this->postJson('/chatblast/broadcast/preview', $in)->assertOk()->json();
        $this->assertSame(['Page A' => null, 'Page B' => null], array_column($draft['pages'], 'count', 'page'), 'saving the draft counts nothing');
        $this->assertFalse($draft['counted']);

        // One request per page; each answer has that page's number, and the total when all are in.
        $a = $this->postJson('/chatblast/broadcast/' . $draft['id'] . '/count', ['page' => 'Page A'])->assertOk()->json();
        $this->assertSame(['Page A' => 2, 'Page B' => null], array_column($a['pages'], 'count', 'page'));
        $this->assertFalse($a['counted']);
        $r = $this->postJson('/chatblast/broadcast/' . $draft['id'] . '/count', ['page' => 'Page B'])->assertOk()->json();
        $this->assertTrue($r['counted']);

        // A reload (same draft, same filters) keeps the cached counts and does not count again.
        $queries = [];
        DB::listen(function ($q) use (&$queries) { $queries[] = $q->sql; });
        $again = $this->postJson('/chatblast/broadcast/preview', $in + ['id' => $draft['id']])->assertOk()->json();
        $this->postJson('/chatblast/broadcast/' . $draft['id'] . '/count', ['page' => 'Page A'])->assertOk();
        $this->assertSame([], array_values(array_filter($queries, fn ($s) => str_contains($s, 'botcake_psids'))));
        $this->assertSame(['Page A' => 2, 'Page B' => 2], array_column($again['pages'], 'count', 'page'));
        // Other filters clear them.
        $other = $this->postJson('/chatblast/broadcast/preview', ['orders' => 'any'] + $in + ['id' => $draft['id']])->assertOk()->json();
        $this->assertSame(['Page A' => null, 'Page B' => null], array_column($other['pages'], 'count', 'page'));
        $r = $this->preview(['pages' => ['Page A', 'Page B'], 'orders' => 'without', 'subscribed_before' => '2026-08-01'], $draft['id'])->assertOk()->json();

        $counts = array_column($r['pages'], 'count', 'page');
        $this->assertSame(['Page A' => 2, 'Page B' => 2], $counts);
        $this->assertSame(4, $r['total']);
        $this->assertNull($r['warning']);
        foreach (['Page A' => '1001', 'Page B' => '1002'] as $page => $id) {
            $key = mb_strtolower($page);
            $plain = DB::selectOne("SELECT COUNT(DISTINCT b.psid) AS n FROM botcake_psids b
                WHERE (b.page_id = ? OR (b.page_id IS NULL AND LOWER(TRIM(b.page)) = ?)) AND b.status IN ('available', 'verified')
                AND b.subscribed_at < '2026-08-01 00:00:00'
                AND NOT EXISTS (SELECT 1 FROM macro_output mo WHERE mo.botcake_psid = b.psid AND LOWER(TRIM(mo.PAGE)) = ?)", [$id, $key, $key])->n;
            $this->assertSame((int) $plain, $counts[$page], $page);
        }
    }

    public function test_s1_2_no_filter_shows_the_total_with_the_every_psid_warning(): void
    {
        $this->psid('Page A', '1001', 'a1');
        $this->psid('Page A', '1001', 'a2', ['subscribed_at' => null]);

        $r = $this->preview()->assertOk()->json();

        $this->assertSame(2, $r['total']);
        $this->assertSame(Broadcast::ALL_WARNING, $r['warning']);
    }

    public function test_s1_3_no_page_selected_cannot_preview_or_send(): void
    {
        $this->psid('Page A', '1001', 'a1');

        $this->preview(['pages' => []])->assertStatus(422)->assertJsonPath('error', 'Pick at least one page.');
        $this->assertSame(0, ChatblastBroadcast::count());

        $b = ChatblastBroadcast::create(['kind' => 'broadcast-x', 'name' => 'x', 'pages' => [], 'days' => [['Hi']], 'broadcast' => ['state' => 'draft', 'pages' => [], 'filters' => []]]);
        $this->postJson('/chatblast/broadcast/' . $b->id . '/start', ['total' => 0])->assertStatus(422);
        $this->assertSame(0, BotcakeMessage::count());
        $this->assertSame([], $this->sends());
    }

    public function test_s1_3_a_page_not_in_the_allowed_list_is_refused(): void
    {
        $this->preview(['pages' => ['Page Z']])->assertStatus(422);
    }

    public function test_s1_4_a_psid_on_the_page_twice_is_counted_and_sent_once(): void
    {
        $this->psid('Page A', '1001', 'a1');
        $this->psid('Page A', '1001', 'a1', ['source' => 'chat_verify', 'status' => 'verified']);
        $this->psid('Page A', null, 'a1', ['source' => 'sheet']);

        $this->assertSame(1, $this->preview()->assertOk()->json('total'));
        $id = $this->startNew();

        $this->assertSame(1, $this->rows($id)->count());
        $this->assertSame([['1001', 'write'], ['1001', 'flow']], $this->sends());
    }

    public function test_s1_5_an_order_on_page_a_only_keeps_the_psid_in_b_and_not_in_a(): void
    {
        $this->psid('Page A', '1001', 'x1');
        $this->psid('Page B', '1002', 'x1');
        $this->order('Page A', 'Name x1', null, 'x1');

        $r = $this->preview(['pages' => ['Page A', 'Page B'], 'orders' => 'without'])->assertOk()->json();
        $this->assertSame(['Page A' => 0, 'Page B' => 1], array_column($r['pages'], 'count', 'page'));

        $with = $this->preview(['pages' => ['Page A', 'Page B'], 'orders' => 'with'])->assertOk()->json();
        $this->assertSame(['Page A' => 1, 'Page B' => 0], array_column($with['pages'], 'count', 'page'));
    }

    public function test_s1_other_filters_last_interaction_and_phone(): void
    {
        $this->psid('Page A', '1001', 'p1', ['phone_numbers' => ['09171234567'], 'last_interactive_at' => '2026-11-05 08:00:00']);
        $this->psid('Page A', '1001', 'p2', ['phone_numbers' => [], 'last_interactive_at' => '2026-09-01 08:00:00']);
        $this->psid('Page A', '1001', 'p3', ['phone_numbers' => null, 'last_interactive_at' => '2026-11-10 08:00:00']);

        $this->assertSame(1, $this->preview(['phone' => 'has'])->json('total'));
        $this->assertSame(2, $this->preview(['phone' => 'none'])->json('total'));
        $this->assertSame(2, $this->preview(['interaction_from' => '2026-11-01'])->json('total'));
        $this->assertSame(1, $this->preview(['interaction_before' => '2026-11-01'])->json('total'));
        $this->assertSame(1, $this->preview(['interaction_from' => '2026-11-01', 'phone' => 'none'])->json('total'));
        $this->preview(['subscribed_from' => '2026-13-01'])->assertStatus(422);
    }

    // ---- S2 Flow and content ----

    public function test_s2_1_a_flow_by_name_uses_each_page_s_own_flow_id(): void
    {
        $this->psid('Page A', '1001', 'a1');
        $this->psid('Page B', '1002', 'b1');

        $r = $this->preview(['pages' => ['Page A', 'Page B'], 'flow' => 'Shipping Status'])->assertOk()->json();
        $this->assertSame(['Page A' => '6601', 'Page B' => '6602'], array_column($r['pages'], 'flow_id', 'page'));
        $this->postJson('/chatblast/broadcast/' . $r['id'] . '/start', ['total' => 2])->assertOk();

        $flows = collect(Http::recorded())->map(fn ($x) => $x[0])->filter(fn ($q) => str_ends_with($q->url(), '/flows/send_flow'))
            ->map(fn ($q) => [$q->data()['psid'], $q->data()['flow_id']])->values()->all();
        $this->assertSame([['a1', 6601], ['b1', 6602]], $flows);
        $this->assertSame(2, $this->rows($r['id'])->where('status', 'sent')->count());
    }

    public function test_s2_default_flow_is_the_page_flow_shipping(): void
    {
        $this->psid('Page A', '1001', 'a1');
        $id = $this->startNew();

        $flow = collect(Http::recorded())->map(fn ($x) => $x[0])->first(fn ($q) => str_ends_with($q->url(), '/flows/send_flow'));
        $this->assertSame(['psid' => 'a1', 'flow_id' => 5501], $flow->data());
        $this->assertSame('sent', $this->rows($id)->value('status'));
    }

    public function test_s2_2_a_page_without_the_flow_is_listed_and_left_out_of_the_send(): void
    {
        $this->psid('Page A', '1001', 'a1');
        $this->psid('Page B', '1002', 'b1');
        $this->flows['1002'] = [['id' => 5501, 'name' => 'CHATBLAST Shipping update']];

        $r = $this->preview(['pages' => ['Page A', 'Page B'], 'flow' => 'Shipping Status'])->assertOk()->json();
        $b = collect($r['pages'])->firstWhere('page', 'Page B');
        $this->assertSame('No flow named "Shipping Status"', $b['error']);
        $this->assertSame(1, $b['count']);
        $this->assertSame(1, $r['total'], 'B is not counted in the send');

        $this->postJson('/chatblast/broadcast/' . $r['id'] . '/start', ['total' => 1])->assertOk();
        $this->assertSame(['Page A'], $this->rows($r['id'])->pluck('page_name')->all());
        $this->assertNotContains('1002', array_column($this->sends(), 0), 'never sent with another flow');
    }

    public function test_s2_removed_flow_does_not_count_as_a_match(): void
    {
        $this->psid('Page A', '1001', 'a1');
        $r = $this->preview(['flow' => 'Old'])->assertOk()->json();
        $this->assertSame('No flow named "Old"', $r['pages'][0]['error']);
    }

    public function test_s4_the_cap_defaults_to_10000_per_page_per_hour_and_allows_up_to_20000(): void
    {
        $this->get("/chatblast/broadcast")->assertOk()->assertSee('value="10000"', false)->assertSee('max="20000"', false);

        $in = $this->input();
        unset($in["cap"]);
        $r = $this->postJson("/chatblast/broadcast/preview", $in)->assertOk();
        $this->assertSame(10000, $r->json("cap"), "no cap given: the default is 10000");

        $this->preview(["cap" => 20000])->assertOk();
        $this->postJson("/chatblast/broadcast/preview", $this->input(["cap" => 20001]))->assertStatus(422);
    }

    public function test_s2_3_empty_text_cannot_send(): void
    {
        $this->psid('Page A', '1001', 'a1');
        $r = $this->preview(['text' => ''])->assertOk()->json();

        $this->postJson('/chatblast/broadcast/' . $r['id'] . '/start', ['total' => 1])->assertStatus(422)->assertJsonPath('error', 'Text: the text is empty.');
        $this->assertSame(0, BotcakeMessage::count());
    }

    public function test_s2_text_rules_length_and_placeholders(): void
    {
        $this->preview(['text' => str_repeat('x', 1001)])->assertStatus(422);
        $this->preview(['text' => 'Hi {name}!'])->assertStatus(422);
    }

    public function test_s2_the_text_goes_into_the_field_exactly_as_written(): void
    {
        $this->psid('Page A', '1001', 'a1');
        $text = "Sale today!\n<b>50% off</b> & free shipping 🎉";
        $this->startNew(['text' => $text]);

        $write = collect(Http::recorded())->map(fn ($x) => $x[0])->first(fn ($q) => $q->method() === 'POST' && str_contains($q->url(), '/customer_fields'));
        $this->assertSame(['data' => [['id' => '7701', 'value' => $text]]], $write->data());
    }

    // ---- S3 Test send ----

    public function test_s3_1_a_test_on_one_page_is_one_row_with_the_steps_and_http_codes(): void
    {
        $this->psid('Page A', '1001', 'a1');
        app(PageSettings::class)->set('Page A', 'botcake', 'test_psid', 'me-1');
        $r = $this->preview(['flow' => 'Shipping Status'])->assertOk()->json();

        $t = $this->postJson('/chatblast/broadcast/' . $r['id'] . '/test', ['page' => 'Page A'])->assertOk()->json();

        $row = BotcakeMessage::findOrFail($t['id']);
        $this->assertTrue($row->is_test);
        $this->assertSame(['sent', 'me-1'], [$row->status, $row->psid]);
        $this->assertSame(['write', 'flow'], array_column($row->steps, 'step'));
        $this->assertSame([200, 200], array_column($row->steps, 'http'));
        $this->assertSame(['psid' => 'me-1', 'flow_id' => 6601], $row->steps[1]['request']);
        $this->assertSame(0, $this->rows($r['id'])->count(), 'nobody else');
        $this->assertSame('draft', ChatblastBroadcast::find($r['id'])->state());
        $progress = $this->getJson('/chatblast/broadcast/' . $r['id'] . '/progress')->assertOk()->json();
        $this->assertSame('sent', $progress['tests'][0]['status']);
    }

    public function test_s3_2_a_refused_token_is_shown_and_nothing_else_is_sent(): void
    {
        $this->psid('Page A', '1001', 'a1');
        app(PageSettings::class)->set('Page A', 'botcake', 'test_psid', 'me-1');
        $this->answers['1001']['write'] = [['message' => 'Unauthorized'], 401];
        $r = $this->preview()->assertOk()->json();

        $t = $this->postJson('/chatblast/broadcast/' . $r['id'] . '/test', ['page' => 'Page A'])->assertOk()->json();

        $row = BotcakeMessage::findOrFail($t['id']);
        $this->assertSame(['failed', 401], [$row->status, $row->last_http]);
        $this->assertStringContainsString('Token invalid (HTTP 401)', $row->reason);
        $this->assertSame([['1001', 'write']], $this->sends());
    }

    public function test_s3_a_page_without_a_test_psid_is_refused(): void
    {
        $this->psid('Page A', '1001', 'a1');
        $r = $this->preview()->assertOk()->json();
        $this->postJson('/chatblast/broadcast/' . $r['id'] . '/test', ['page' => 'Page A'])->assertStatus(422);
    }

    // ---- S4 Send, pace, pause, stop, progress ----

    public function test_s4_1_three_psids_are_three_sent_rows_that_write_then_send_without_a_read_back(): void
    {
        foreach (['a1', 'a2', 'a3'] as $p) $this->psid('Page A', '1001', $p);

        $id = $this->startNew();

        $rows = $this->rows($id)->get();
        $this->assertSame(['sent', 'sent', 'sent'], $rows->pluck('status')->all());
        foreach ($rows as $row) {
            $this->assertSame(['write', 'flow'], array_column($row->steps, 'step'));
            $this->assertSame('broadcast:' . $id . ':1001:' . $row->psid, $row->dedupe_key);
        }
        $this->assertSame('done', ChatblastBroadcast::find($id)->state());
        $p = $this->getJson('/chatblast/broadcast/' . $id . '/progress')->assertOk()->json('progress.0');
        $this->assertEquals(['audience' => 3, 'queued' => 0, 'sent' => 3, 'failed' => 0, 'unknown' => 0, 'paused' => null],
            array_intersect_key($p, array_flip(['audience', 'queued', 'sent', 'failed', 'unknown', 'paused'])));
    }

    public function test_s4_2_start_pressed_twice_is_still_three_rows(): void
    {
        foreach (['a1', 'a2', 'a3'] as $p) $this->psid('Page A', '1001', $p);
        $r = $this->preview()->assertOk()->json();

        $this->postJson('/chatblast/broadcast/' . $r['id'] . '/start', ['total' => 3])->assertOk();
        $this->postJson('/chatblast/broadcast/' . $r['id'] . '/start', ['total' => 3])->assertStatus(422)->assertJsonPath('error', 'This broadcast has already started.');
        $this->postJson('/chatblast/broadcast/' . $r['id'] . '/count', ['page' => 'Page A'])->assertStatus(422); // no recount once started
        (new \App\Jobs\QueueBroadcast($r['id']))->handle(app(Broadcast::class)); // a re-run of the queue job

        $this->assertSame(3, $this->rows($r['id'])->count());
        $this->assertCount(6, $this->sends());
    }

    public function test_s4_start_does_not_recount_and_the_queue_job_inserts_what_matches_at_queue_time(): void
    {
        $this->psid('Page A', '1001', 'a1');
        $r = $this->preview()->assertOk()->json();
        $this->psid('Page A', '1001', 'a2'); // subscribed after the preview

        $this->postJson('/chatblast/broadcast/' . $r['id'] . '/start', ['total' => 2])->assertStatus(422)
            ->assertJsonPath('error', 'The total is 1 now; check the preview and press Send again.');
        $this->assertSame(0, BotcakeMessage::count());
        $this->postJson('/chatblast/broadcast/' . $r['id'] . '/start', ['total' => 1])->assertOk();

        $this->assertSame(2, $this->rows($r['id'])->count());
        $p = $this->getJson('/chatblast/broadcast/' . $r['id'] . '/progress')->json('progress.0');
        $this->assertSame([1, 2], [$p['audience'], $p['matched']], 'previewed 1, matched 2 at queue time');
    }

    public function test_s4_start_needs_every_page_counted_for_the_current_filters(): void
    {
        $this->psid('Page A', '1001', 'a1');
        $this->psid('Page B', '1002', 'b1');
        $draft = $this->postJson('/chatblast/broadcast/preview', $this->input(['pages' => ['Page A', 'Page B']]))->assertOk()->json();
        $this->postJson('/chatblast/broadcast/' . $draft['id'] . '/count', ['page' => 'Page A'])->assertOk();

        $this->postJson('/chatblast/broadcast/' . $draft['id'] . '/start', ['total' => 1])->assertStatus(422)
            ->assertJsonPath('error', 'Count every page first (missing: Page B).');
        $this->postJson('/chatblast/broadcast/' . $draft['id'] . '/count', ['page' => 'Page Z'])->assertStatus(422);

        // The filters stored on the row no longer match the hash of the counts (e.g. changed in another tab).
        app(Broadcast::class)->mutate($draft['id'], fn ($d) => array_merge($d, ['filters' => array_merge($d['filters'], ['orders' => 'with'])]));
        $this->postJson('/chatblast/broadcast/' . $draft['id'] . '/count', ['page' => 'Page B'])->assertOk();
        $this->postJson('/chatblast/broadcast/' . $draft['id'] . '/start', ['total' => 2])->assertStatus(422)
            ->assertJsonPath('error', 'The filters changed since the count; preview again.');
        $this->assertSame(0, BotcakeMessage::count());
    }

    public function test_s4_a_start_request_with_three_pages_makes_no_count_query(): void
    {
        app(PageSettings::class)->set('Page C', 'botcake', 'page_id', '1003');
        $this->page('Page C', '1003');
        $this->flows['1003'] = $this->flows['1001'];
        foreach (['Page A' => '1001', 'Page B' => '1002', 'Page C' => '1003'] as $page => $id) $this->psid($page, $id, 'p-' . $id);
        $r = $this->preview(['pages' => ['Page A', 'Page B', 'Page C']])->assertOk()->json();
        $this->assertSame(3, $r['total']);
        Queue::fake([\App\Jobs\QueueBroadcast::class]);
        $calls = count(Http::recorded()); // nothing may reach Botcake either
        $queries = [];
        DB::listen(function ($q) use (&$queries) { $queries[] = $q->sql; });

        $this->postJson('/chatblast/broadcast/' . $r['id'] . '/start', ['total' => 3])->assertOk()->assertJsonPath('state', 'sending');

        $this->assertSame([], array_values(array_filter($queries, fn ($s) => str_contains($s, 'botcake_psids') || str_contains($s, 'macro_output'))));
        Queue::assertPushed(\App\Jobs\QueueBroadcast::class, 1);
        $this->assertSame(0, BotcakeMessage::count(), 'the rows are inserted by the job');
        $this->assertCount($calls, Http::recorded());
    }

    public function test_s4_3_twenty_failures_in_a_row_pause_page_a_and_page_b_goes_on(): void
    {
        for ($i = 1; $i <= Broadcast::inFlight() + 5; $i++) $this->psid('Page A', '1001', 'a' . str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        foreach (['b1', 'b2', 'b3'] as $p) $this->psid('Page B', '1002', $p);
        $this->answers['1001']['flow'] = [['success' => false, 'message' => 'flow off'], 200];

        $id = $this->startNew(['pages' => ['Page A', 'Page B']]);

        $a = $this->rows($id)->where('page_name', 'Page A');
        $this->assertSame(20, (clone $a)->where('status', 'failed')->count());
        $this->assertSame(5, (clone $a)->where('status', Broadcast::WAITING)->count());
        $this->assertSame(3, $this->rows($id)->where('page_name', 'Page B')->where('status', 'sent')->count());
        $paused = collect($this->getJson('/chatblast/broadcast/' . $id . '/progress')->json('progress'))->pluck('paused', 'page');
        $this->assertStringStartsWith(Broadcast::STREAK_REASON, $paused['Page A']);
        $this->assertNull($paused['Page B']);

        app(Broadcast::class)->feed();
        $this->assertSame(5, (clone $a)->where('status', Broadcast::WAITING)->count(), 'a paused page is not fed');
    }

    public function test_s4_the_feed_command_feeds_several_times_in_one_run(): void
    {
        foreach (['a1', 'a2'] as $p) $this->psid('Page A', '1001', $p);
        Queue::fake([SendChatblastMessage::class]);
        $this->startNew();

        $mock = \Mockery::mock(Broadcast::class);
        $mock->shouldReceive('feed')->times(3)->andReturn(1);
        $this->app->instance(Broadcast::class, $mock);

        $this->artisan('chatblast:broadcast-feed', ['--loops' => 3, '--every' => 0])->expectsOutput('3 handed out')->assertExitCode(0);
    }

    public function test_s4_3_customers_not_reachable_551_do_not_pause_the_page_and_are_counted_on_their_own(): void
    {
        for ($i = 1; $i <= 25; $i++) $this->psid('Page A', '1001', 'a' . str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        $this->answers['1001']['flow'] = [['success' => false, 'message' => "(#551) This person isn't available right now.", 'error_code' => null], 200];

        $id = $this->startNew(['cap' => 1000]);
        for ($i = 0; $i < 3; $i++) app(Broadcast::class)->feed();

        $this->assertSame(25, $this->rows($id)->where('status', 'failed')->where('reason', Broadcast::UNREACHABLE)->count(), 'all sent on, none held');
        $row = collect($this->getJson('/chatblast/broadcast/' . $id . '/progress')->assertOk()->json('progress'))->firstWhere('page', 'Page A');
        $this->assertNull($row['paused'], 'a #551 is the customer, not the page: no pause');
        $this->assertSame(25, $row['unreachable']);
        $this->assertSame(0, $row['failed']);
        $this->assertSame(0, $row['waiting']);
    }

    public function test_s4_3_a_restricted_page_pauses_at_once(): void
    {
        foreach (['a1', 'a2', 'a3'] as $p) $this->psid('Page A', '1001', $p);
        $this->answers['1001']['flow'] = [['success' => false, 'message' => '(#2022) This Page is temporarily restricted from messaging users'], 200];

        $id = $this->startNew();

        $this->assertSame(1, $this->rows($id)->where('status', 'failed')->count());
        $this->assertSame(2, $this->rows($id)->where('status', 'queued')->count(), 'the rest wait, not sent');
        $this->assertStringContainsString('restricted', (string) ChatblastBroadcast::find($id)->broadcast['pages']['Page A']['paused']);
        $this->assertCount(2, $this->sends());
    }

    public function test_s4_4_stop_cancels_the_queued_rows_and_a_sent_row_stays_sent(): void
    {
        foreach (['a1', 'a2', 'a3'] as $p) $this->psid('Page A', '1001', $p);
        Queue::fake([SendChatblastMessage::class]);
        $id = $this->startNew(['cap' => 2]);
        [$first, $second, $third] = $this->rows($id)->pluck('id')->all();
        app(ChatblastSender::class)->process($first);
        $this->assertSame('sent', BotcakeMessage::find($first)->status);

        $this->postJson('/chatblast/broadcast/' . $id . '/stop')->assertOk();

        $this->assertSame(['sent', 'cancelled', 'cancelled'], [BotcakeMessage::find($first)->status, BotcakeMessage::find($second)->status, BotcakeMessage::find($third)->status]);
        app(ChatblastSender::class)->process($second);
        $this->assertSame('cancelled', BotcakeMessage::find($second)->status);
        $this->assertCount(2, $this->sends());
        $this->assertSame('stopped', ChatblastBroadcast::find($id)->state());
    }

    public function test_s4_4_a_row_waiting_in_the_job_after_stop_is_cancelled_not_sent(): void
    {
        $this->psid('Page A', '1001', 'a1');
        Queue::fake([SendChatblastMessage::class]);
        $id = $this->startNew();
        $row = $this->rows($id)->sole();
        BotcakeMessage::whereKey($row->id)->update(['status' => 'queued']);
        app(Broadcast::class)->mutate($id, fn ($d) => array_merge($d, ['state' => 'stopped']));

        app(ChatblastSender::class)->process($row->id);

        $this->assertSame('cancelled', $row->fresh()->status);
        $this->assertSame([], $this->sends());
    }

    public function test_s4_5_a_cap_of_two_per_hour_sends_two_now_and_one_in_the_next_hour(): void
    {
        foreach (['a1', 'a2', 'a3'] as $p) $this->psid('Page A', '1001', $p);

        $id = $this->startNew(['cap' => 2]);

        $this->assertSame(2, $this->rows($id)->where('status', 'sent')->count());
        $this->assertSame(1, $this->rows($id)->where('status', Broadcast::WAITING)->count());
        Artisan::call('chatblast:broadcast-feed');
        $this->assertSame(1, $this->rows($id)->where('status', Broadcast::WAITING)->count(), 'still the same hour');

        $this->travel(61)->minutes();
        Artisan::call('chatblast:broadcast-feed');

        $this->assertSame(3, $this->rows($id)->where('status', 'sent')->count());
        $this->assertSame('done', ChatblastBroadcast::find($id)->state());
    }

    public function test_s4_pause_and_resume_the_whole_broadcast(): void
    {
        foreach (['a1', 'a2', 'a3'] as $p) $this->psid('Page A', '1001', $p);
        Queue::fake([SendChatblastMessage::class]);
        $id = $this->startNew();
        $ids = $this->rows($id)->pluck('id')->all();

        $this->postJson('/chatblast/broadcast/' . $id . '/pause')->assertOk()->assertJsonPath('state', 'paused');
        foreach ($ids as $rowId) app(ChatblastSender::class)->process($rowId);
        $this->assertSame([], $this->sends());
        $this->assertSame(['queued', 'queued', 'queued'], $this->rows($id)->pluck('status')->all());

        $this->postJson('/chatblast/broadcast/' . $id . '/resume')->assertOk()->assertJsonPath('state', 'sending');
        Queue::assertPushed(SendChatblastMessage::class, 6); // 3 at start, 3 again on resume
        foreach ($ids as $rowId) app(ChatblastSender::class)->process($rowId);
        $this->assertSame(['sent', 'sent', 'sent'], $this->rows($id)->pluck('status')->all());
    }

    public function test_s4_unknown_is_never_retried_by_resume(): void
    {
        $this->psid('Page A', '1001', 'a1');
        $this->answers['1001']['flow'] = [['message' => 'oops'], 502];
        $id = $this->startNew();
        $this->assertSame('unknown', $this->rows($id)->value('status'));
        unset($this->answers['1001']['flow']);

        $this->postJson('/chatblast/broadcast/' . $id . '/resume');
        Artisan::call('chatblast:broadcast-feed');

        $this->assertSame('unknown', $this->rows($id)->value('status'));
        $this->assertCount(2, $this->sends());
    }

    public function test_s4_broadcast_rows_wait_while_the_chatblast_is_paused(): void
    {
        $this->psid('Page A', '1001', 'a1');
        app(ChatblastSender::class)->pause();

        $id = $this->startNew();

        $this->assertSame(Broadcast::WAITING, $this->rows($id)->value('status'));
        $this->assertSame([], $this->sends());
    }

    public function test_s4_a_large_audience_is_queued_in_batches_by_the_job(): void
    {
        $now = now()->format('Y-m-d H:i:s');
        $rows = [];
        for ($i = 0; $i < Broadcast::BATCH + 5; $i++) {
            $rows[] = ['page' => 'Page A', 'page_id' => '1001', 'fb_name' => 'N', 'psid' => 'p' . str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'status' => 'available',
                'source' => 'api', 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 200) as $chunk) DB::table('botcake_psids')->insert($chunk);
        Queue::fake([SendChatblastMessage::class]);

        $id = $this->startNew();

        $this->assertSame(Broadcast::BATCH + 5, $this->rows($id)->count());
        $this->assertSame(Broadcast::inFlight(), $this->rows($id)->where('status', 'queued')->count(), 'only the in-flight limit is handed out');
        $this->assertTrue((bool) ChatblastBroadcast::find($id)->broadcast['queued_all']);
    }

    public function test_the_in_flight_limit_comes_from_the_setting(): void
    {
        foreach (range(1, 12) as $i) $this->psid('Page A', '1001', 'f' . $i);
        config(['services.botcake.broadcast_in_flight' => 7]);
        Queue::fake([SendChatblastMessage::class]);

        $id = $this->startNew();

        $this->assertSame(7, Broadcast::inFlight());
        $this->assertSame(7, $this->rows($id)->where('status', 'queued')->count(), 'the setting decides how many are handed out');
        config(['services.botcake.broadcast_in_flight' => null]);
        $this->assertSame(Broadcast::IN_FLIGHT, Broadcast::inFlight(), 'no setting: the default (60)');
    }

    // ---- Isolation from the sequences ----

    public function test_a_broadcast_is_not_a_sequence_and_does_not_count_toward_the_chatblast_daily_cap(): void
    {
        $this->psid('Page A', '1001', 'a1');
        $before = ChatblastCampaign::count();
        $this->startNew();

        $this->assertSame($before, ChatblastCampaign::count());
        $this->assertSame(1, ChatblastBroadcast::count());
        $this->get('/chatblast/sequence/data')->assertOk()->assertDontSee('Broadcast ');

        config(['services.botcake.chatblast_daily_cap' => 1]);
        $this->shipped('Page A', 'Ana Cruz', 'WB-001', 'psid-w');
        app(\App\Services\Chatblast::class)->build('delivering');
        $row = BotcakeMessage::where('waybill', 'WB-001')->sole();
        app(ChatblastSender::class)->queue([$row->id], 1);
        $this->assertSame('sent', $row->fresh()->status, 'the broadcast send did not use up the page daily cap');
    }

    public function test_the_page_needs_chatblast_access(): void
    {
        $this->actingAs($this->user('Encoder', 'enc@example.test'));
        $this->get('/chatblast/broadcast')->assertNotFound();
        $this->postJson('/chatblast/broadcast/preview', $this->input())->assertNotFound();
    }

    public function test_the_page_opens_and_lists_the_pages(): void
    {
        $this->get('/chatblast/broadcast')->assertOk()->assertSee('Broadcast')->assertSee('Page A')->assertSee('Page B');
    }
}
