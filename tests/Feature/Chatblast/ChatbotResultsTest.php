<?php

namespace Tests\Feature\Chatblast;

use App\Models\MacroOutput;
use App\Services\Chatbot\ChatbotResults;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec 100, ang report: /chatbot/results, /chatbot/results.json at chatbot:results. Isang nakatakdang data (Page Uno,
 * 2026-10-14 hanggang 10-20) na kinuwenta nang mano-mano: 5 customer, 7 padala, 4 order (delivered, unmatched, RTS, RTS).
 */
class ChatbotResultsTest extends ChatblastTestCase
{
    private const RANGE = ['page_id' => '1001', 'from' => '2026-10-14', 'to' => '2026-10-20'];

    private int $flowF;
    private int $flowG;
    private int $v1;
    private int $v2;
    private int $s1;
    private int $s2;
    private int $seq;
    /** @var array<string, int> */
    private array $r = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
        ]]);
        if (!Schema::hasTable('ads_manager_reports')) Schema::create('ads_manager_reports', function ($t) { $t->id(); $t->date('day')->nullable(); $t->string('page_name'); $t->string('campaign_name')->nullable(); $t->string('ad_set_name')->nullable(); $t->string('headline')->nullable(); $t->string('ad_id')->default(''); });
        if (!Schema::hasColumn('from_jnts', 'rts_reason')) Schema::table('from_jnts', fn ($t) => $t->string('rts_reason')->nullable());
        $this->travelTo('2026-10-21 09:00:00');
        $this->page('Page Uno', '1001');
        $this->seedResults();
    }

    private function seedResults(): void
    {
        $ceo = $this->user();
        $this->flowF = DB::table('chatbot_flows')->insertGetId(['name' => 'Main Flow (from CBB)', 'graph' => '{"nodes":[],"edges":[]}', 'published_at' => '2026-10-15 08:00:00']);
        $this->flowG = DB::table('chatbot_flows')->insertGetId(['name' => 'SEQ step flow', 'graph' => '{"nodes":[],"edges":[]}', 'published_at' => '2026-10-01 08:00:00']);
        $this->v1 = DB::table('chatbot_flow_versions')->insertGetId(['flow_id' => $this->flowF, 'graph' => '{}', 'note' => 'first', 'published_by' => $ceo->id, 'created_at' => '2026-10-10 08:00:00']);
        $this->v2 = DB::table('chatbot_flow_versions')->insertGetId(['flow_id' => $this->flowF, 'graph' => '{}', 'note' => 'shorter greeting', 'published_by' => $ceo->id, 'created_at' => '2026-10-15 08:00:00']);
        $this->s1 = DB::table('page_settings')->insertGetId(['page_name' => 'Page Uno', 'provider' => 'chatbot', 'key' => 'main_flow', 'value' => 'Hello v1', 'is_secret' => false,
            'active' => false, 'replaced_at' => '2026-10-15 08:00:00', 'created_at' => '2026-10-10 08:00:00', 'updated_at' => '2026-10-15 08:00:00']);
        $this->s2 = DB::table('page_settings')->insertGetId(['page_name' => 'Page Uno', 'provider' => 'chatbot', 'key' => 'main_flow', 'value' => 'Hello v2', 'is_secret' => false,
            'active' => true, 'created_at' => '2026-10-15 08:00:00', 'updated_at' => '2026-10-15 08:00:00']);
        $this->seq = DB::table('chatbot_sequences')->insertGetId(['name' => 'SEQ', 'active' => true, 'exit_on_reply' => true, 'created_at' => now(), 'updated_at' => now()]);

        // Mga chat: 5 customer sa loob ng range (may text na hindi dapat lumabas), at isa sa labas.
        foreach ([1 => '2026-10-14 09:55:00', 2 => '2026-10-16 09:50:00', 3 => '2026-10-16 10:50:00', 4 => '2026-10-17 09:00:00', 5 => '2026-10-19 09:00:00', 9 => '2026-10-01 09:00:00'] as $psid => $at) {
            DB::table('botcake_chats')->insert(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => (string) $psid, 'name' => 'Customer Name ' . $psid,
                'message' => 'secret customer text ' . $psid, 'payload' => '[]', 'received_at' => $at]);
        }

        $send = function (string $key, int $psid, int $flow, ?int $version, string $node, string $at, ?int $seconds, ?string $ad, ?int $setting, ?array $seq = null, string $status = 'sent') {
            $id = DB::table('chatbot_replies')->insertGetId(['page_id' => 1001, 'psid' => $psid, 'flow_id' => $flow, 'flow_version_id' => $version, 'node_id' => $node,
                'kind' => 'send', 'content' => '[{"type":"text","text":"bot text"}]', 'status' => $status, 'sent_at' => $at, 'ad_id' => $ad,
                'sequence_id' => $seq[0] ?? null, 'sequence_step' => $seq[1] ?? null,
                'replied_at' => $seconds === null ? null : date('Y-m-d H:i:s', strtotime($at) + $seconds), 'reply_seconds' => $seconds]);
            if ($status === 'sent') DB::table('chatbot_reply_tags')->insert(['reply_id' => $id, 'page_id' => 1001, 'flow_id' => $flow, 'flow_version_id' => $version, 'node_id' => $node,
                'message' => 0, 'botfield' => $setting ? 'main_flow' : null, 'setting_id' => $setting, 'sent_at' => $at]);
            $this->r[$key] = $id;
        };
        $send('r1', 1, $this->flowF, $this->v1, 'n10', '2026-10-14 10:00:00', 300, 'A1', $this->s1);
        $send('r2', 2, $this->flowF, $this->v2, 'n10', '2026-10-16 10:00:00', 60, 'A1', $this->s2);
        $send('r3', 3, $this->flowF, $this->v2, 'n10', '2026-10-16 11:00:00', null, 'A2', $this->s2);
        $send('r4', 4, $this->flowF, $this->v2, 'n10', '2026-10-17 10:00:00', 120, null, $this->s2);
        $send('r5', 2, $this->flowG, null, 'n2', '2026-10-17 12:00:00', 600, 'A1', null, [$this->seq, 1]);
        $send('r6', 3, $this->flowG, null, 'n2', '2026-10-18 12:00:00', null, 'A2', null, [$this->seq, 2]);
        $send('r7', 5, $this->flowF, $this->v2, 'n10', '2026-10-19 10:00:00', null, null, $this->s2);
        $send('rf', 4, $this->flowF, $this->v2, 'n10', '2026-10-17 10:30:00', null, null, $this->s2, null, 'failed');
        $send('rx', 9, $this->flowF, $this->v1, 'n10', '2026-10-01 10:00:00', 30, 'A1', $this->s1);

        $order = fn (int $psid, string $at, int $version, array $sends, ?array $seq, ?string $ad) => DB::table('chatbot_events')->insert(['page_id' => 1001, 'psid' => $psid,
            'flow_id' => $this->flowF, 'node_id' => 'n45', 'type' => 'order', 'created_at' => $at, 'detail' => json_encode(['run' => 'abcd1234', 'flow_version' => $version,
                'sends' => $sends, 'sequence_id' => $seq[0] ?? null, 'sequence_step' => $seq[1] ?? null, 'ad_id' => $ad])]);
        $order(2, '2026-10-16 12:00:00', $this->v2, [$this->r['r2']], null, 'A1');                         // o1: delivered
        $order(4, '2026-10-17 11:00:00', $this->v2, [$this->r['r4']], [$this->seq, 1], null);              // o2: unmatched (order 5 days later)
        $order(1, '2026-10-14 11:00:00', $this->v1, [$this->r['r1']], null, 'A1');                         // o3: closest is Returned
        $order(3, '2026-10-18 13:00:00', $this->v2, [$this->r['r6'], $this->r['r3']], [$this->seq, 2], 'A2'); // o4: In Transit with an RTS reason
        DB::table('chatbot_events')->insert(['page_id' => 1001, 'psid' => 9, 'flow_id' => $this->flowF, 'type' => 'order', 'created_at' => '2026-10-02 10:00:00', 'detail' => '{}']);

        $mo = function (string $page, string $psid, string $date, string $waybill, ?string $status, string $rts = '') {
            MacroOutput::create(['PAGE' => $page, 'fb_name' => 'X', 'waybill' => $waybill, 'botcake_psid' => $psid, 'ts_date' => $date]);
            if ($status !== null) DB::table('from_jnts')->insert(['waybill_number' => $waybill, 'status' => $status, 'rts_reason' => $rts]);
        };
        $mo('page uno ', '2', '2026-10-17', 'W1', 'Delivered');
        $mo('Page Uno', '4', '2026-10-22', 'W2', 'Delivered');           // 5 days after: outside the window
        $mo('Page Uno', '1', '2026-10-13', 'W3a', 'Returned');           // 1 day before: closest
        $mo('Page Uno', '1', '2026-10-16', 'W3b', 'Delivered');          // 2 days after
        $mo('Page Dos', '3', '2026-10-18', 'W4x', 'Delivered');          // another page: never
        $mo('Page Uno', '3', '2026-10-18', 'W4', 'In Transit', 'Refused');

        DB::table('chatbot_sequence_subscriptions')->insert(['sequence_id' => $this->seq, 'page_id' => 1001, 'psid' => 3, 'status' => 'unsubscribed', 'current_step' => 3,
            'exit_reason' => 'reply', 'created_at' => '2026-10-16 11:00:00', 'updated_at' => '2026-10-18 15:00:00']);
        DB::table('ads_manager_reports')->insert(['page_name' => 'Page Uno', 'ad_id' => 'A1', 'headline' => 'Promo Ad One', 'campaign_name' => 'Camp', 'day' => '2026-10-15']);
    }

    private function report(array $extra = []): array
    {
        return app(ChatbotResults::class)->report(ChatbotResults::filters(self::RANGE + $extra));
    }

    // ---- S-100.8 ----

    public static function jnt(): array
    {
        return [
            'Delivered' => [[['Delivered', '']], false, 'delivered'],
            'the furthest row wins' => [[['In Transit', ''], ['Delivered', '']], false, 'delivered'],
            'Returned' => [[['Returned', '']], false, 'rts'],
            'For Return' => [[['For Return', '']], false, 'rts'],
            'In Transit with an RTS reason' => [[['In Transit', 'Refused']], false, 'rts'],
            'Delivering' => [[['Delivering', '']], false, 'in_transit'],
            'no J&T row but a scan' => [[], true, 'in_transit'],
            'no J&T row, no scan' => [[], false, 'other'],
            'unknown status' => [[['Problematic', '']], false, 'other'],
        ];
    }

    #[DataProvider('jnt')]
    public function test_S_100_8_the_waybill_status_maps_like_chatblast(array $rows, bool $scan, string $expected): void
    {
        $this->assertSame($expected, ChatbotResults::deliveryOf(array_map(fn ($r) => ['status' => $r[0], 'rts_reason' => $r[1]], $rows), $scan));
    }

    public function test_S_100_8_b_an_order_links_to_the_closest_macro_output_row_of_its_page_and_psid(): void
    {
        $orders = DB::table('chatbot_events')->where('type', 'order')->where('psid', '<>', 9)->orderBy('created_at')->get();
        $linked = app(ChatbotResults::class)->deliveries($orders);
        // psid 1 (Returned, 1 day before, beats Delivered 2 days after), 2 (page name in another case), 4 (5 days after: none), 3 (other page ignored).
        $this->assertSame([['rts', 'W3a'], ['delivered', 'W1'], ['unmatched', null], ['rts', 'W4']],
            $orders->map(fn ($o) => [$linked[$o->id]['delivery'], $linked[$o->id]['waybill']])->all());
    }

    // ---- S-100.9 ----

    public function test_S_100_9_the_funnel_and_by_message_table_from_seeded_data(): void
    {
        $r = $this->report();
        $this->assertSame(['chats' => 5, 'sent' => 7, 'customers_sent' => 5, 'replied' => 4, 'replied_pct' => 57.1, 'orders' => 4, 'orders_pct' => 80.0,
            'delivered' => 1, 'delivered_pct' => 25.0, 'rts' => 2, 'rts_pct' => 50.0, 'in_transit' => 0, 'other' => 0, 'unmatched' => 1], $r['funnel']);

        $rows = collect($r['by_message'])->map(fn ($m) => [$m['flow'], $m['node'], $m['botfield'], $m['version'], $m['sent'], $m['replied_pct'], $m['median_reply_seconds'], $m['orders'], $m['delivered']])->all();
        $this->assertSame([
            ['Main Flow (from CBB)', 'n10', 'main_flow', $this->s2, 4, 50.0, 90, 3, 1],
            ['SEQ step flow', 'n2', null, null, 2, 50.0, 600, 1, 0],
            ['Main Flow (from CBB)', 'n10', 'main_flow', $this->s1, 1, 100.0, 300, 1, 0],
        ], $rows);
        $v2 = $r['by_message'][0];
        $this->assertSame(['Main Flow', '2026-10-15', 'Hello v2', true], [$v2['label'], $v2['changed_on'], $v2['value'], $v2['active']]);
        $this->assertSame([false, 'Hello v1'], [$r['by_message'][2]['active'], $r['by_message'][2]['value']]);
    }

    // ---- S-100.10 ----

    public function test_S_100_10_by_sequence_step_and_by_ad(): void
    {
        $r = $this->report();
        $this->assertSame([
            ['SEQ', 1, 1, 100.0, 0, 1, 0],
            ['SEQ', 2, 1, 0.0, 1, 1, 0],
        ], collect($r['by_step'])->map(fn ($s) => [$s['sequence'], $s['step'], $s['sent'], $s['replied_pct'], $s['unsubscribed'], $s['orders'], $s['delivered']])->all());
        $this->assertSame([
            ['A1', 'Promo Ad One', 2, 2, 1],
            ['A2', null, 1, 1, 0],
        ], collect($r['by_ad'])->map(fn ($a) => [$a['ad_id'], $a['name'], $a['chats'], $a['orders'], $a['delivered']])->all());
    }

    // ---- S-100.11 ----

    public function test_S_100_11_compare_two_flow_versions_and_two_botfield_versions(): void
    {
        $pick = fn (array $m) => [$m['sent'], $m['replied'], $m['replied_pct'], $m['median_reply_seconds'], $m['orders'], $m['delivered'], $m['rts']];
        $c = app(ChatbotResults::class)->compare(ChatbotResults::filters(self::RANGE + ['flow_id' => (string) $this->flowF]), 'flow', $this->v1, $this->v2);
        $this->assertSame([1, 1, 100.0, 300, 1, 0, 1], $pick($c['a']));
        $this->assertSame([4, 2, 50.0, 90, 3, 1, 1], $pick($c['b']));
        $this->assertSame(['2026-10-10 08:00', 'CEO User'], [$c['a']['label']['at'], $c['a']['label']['by']]);

        $c = app(ChatbotResults::class)->compare(ChatbotResults::filters(self::RANGE), 'botfield', $this->s1, $this->s2);
        $this->assertSame([1, 1, 100.0, 300, 1, 0, 1], $pick($c['a']));
        $this->assertSame([4, 2, 50.0, 90, 3, 1, 1], $pick($c['b']));
        $this->assertSame('Hello v2', $c['b']['label']['value']);

        // The version filter narrows the funnel to that Publish.
        $this->assertSame([4, 2, 3], [$this->report(['flow_id' => (string) $this->flowF, 'version_id' => (string) $this->v2])['funnel']['sent'],
            $this->report(['flow_id' => (string) $this->flowF, 'version_id' => (string) $this->v2])['funnel']['replied'],
            $this->report(['flow_id' => (string) $this->flowF, 'version_id' => (string) $this->v2])['funnel']['orders']]);
    }

    // ---- S-100.12 ----

    public function test_S_100_12_the_json_and_the_command_give_the_same_shape_without_customer_data(): void
    {
        $this->actingAs($this->user('CEO', 'ceo2@example.test'));
        $json = $this->getJson('/chatbot/results.json?' . http_build_query(self::RANGE + ['compare' => 'flow', 'flow_id' => $this->flowF, 'a' => $this->v1, 'b' => $this->v2]))->assertOk()->json();
        $this->assertSame(['filters', 'funnel', 'by_message', 'by_step', 'by_ad', 'versions', 'compare'], array_keys($json));
        $this->assertSame(['a', 'b', 'kind'], collect(array_keys($json['compare']))->sort()->values()->all());
        $raw = json_encode($json);
        foreach (['secret customer text', 'Customer Name', '"psid"'] as $never) $this->assertStringNotContainsString($never, $raw);

        Artisan::call('chatbot:results', ['--page' => 'Page Uno', '--from' => '2026-10-14', '--to' => '2026-10-20', '--flow' => (string) $this->flowF, '--json' => true]);
        $cli = json_decode(Artisan::output(), true);
        $web = $this->getJson('/chatbot/results.json?' . http_build_query(self::RANGE + ['flow_id' => $this->flowF]))->json();
        $this->assertSame($web, $cli);

        // Bad filters fall back to the defaults (last 7 days, all pages), never an error.
        $bad = $this->getJson('/chatbot/results.json?page_id=abc&from=2026-99-99&to=%27%3B--&flow_id=x&version_id=-1&compare=nope')->assertOk()->json();
        $this->assertSame([null, '2026-10-15', '2026-10-21', null, null], [$bad['filters']['page_id'], $bad['filters']['from'], $bad['filters']['to'], $bad['filters']['flow_id'], $bad['filters']['version_id']]);
        $this->assertNull($bad['compare']);
    }

    // ---- S-100.13 ----

    public function test_S_100_13_access_is_the_same_as_chatbot_and_the_nav_has_results(): void
    {
        $this->get('/chatbot/results')->assertRedirect();
        $this->getJson('/chatbot/results.json')->assertUnauthorized();
        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        $this->get('/chatbot/results')->assertForbidden();
        $this->getJson('/chatbot/results.json')->assertForbidden();

        $this->actingAs($this->user('CEO', 'ceo3@example.test'));
        $page = $this->get('/chatbot/results?' . http_build_query(self::RANGE))->assertOk();
        $this->assertMatchesRegularExpression('/data-cb-nav="Results"\s+aria-current="page"/', $page->getContent());
        foreach (['Funnel', 'By message', 'By sequence step', 'By ad', 'Compare', 'Promo Ad One', 'Hello v2'] as $text) $page->assertSee($text);
        $page->assertDontSee('secret customer text');
    }
}
