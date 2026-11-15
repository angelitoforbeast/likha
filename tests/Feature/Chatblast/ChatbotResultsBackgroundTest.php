<?php

namespace Tests\Feature\Chatblast;

use App\Models\MacroOutput;
use App\Services\Chatbot\ChatbotResults;
use App\Services\Chatbot\Gateways;
use App\Services\PageSettings;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Spec 278, /chatbot/results na binubuo sa background: ang page at results.json ay nagbabasa ng kopya lang (walang kopya =
 * "binubuo pa" at wanted), ang chatbot:results-warm ang bumubuo, at ang funnel, By bot, By page at chats ay mula sa iisang
 * basa na may parehong numero ng dating code (OldResultsReference sa ibaba). Ngayon ay 2026-10-21 09:00. Tatlong page
 * (1003 ay wala sa page settings), padala na botcake, forbeast, incepxionbot, luma (walang tags o walang via) at ibang
 * via, dalawang flow at version, mga chat mula sa gateway at hindi, at mga order na may via.
 */
class ChatbotResultsBackgroundTest extends ChatblastTestCase
{
    private const RANGE = ['from' => '2026-10-14', 'to' => '2026-10-20'];
    /** Ang mga table ng report: wala sa mga ito ang dapat basahin ng web request. */
    private const REPORT_TABLES = ['chatbot_replies', 'chatbot_reply_tags', 'botcake_chats', 'chatbot_events', 'macro_output', 'from_jnts', 'jnt_latest_scans',
        'chatbot_sequence_subscriptions', 'chatbot_sequences', 'ads_manager_reports'];

    private int $v1;
    private int $v2;

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
        if (!\Illuminate\Support\Facades\Schema::hasColumn('from_jnts', 'rts_reason')) \Illuminate\Support\Facades\Schema::table('from_jnts', fn ($t) => $t->string('rts_reason')->nullable());
        $this->travelTo('2026-10-21 09:00:00');
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '1002');
        Cache::flush();
        $this->seedMixed();
    }

    private function seedMixed(): void
    {
        $f1 = DB::table('chatbot_flows')->insertGetId(['name' => 'Main', 'graph' => '{"nodes":[],"edges":[]}']);
        $f2 = DB::table('chatbot_flows')->insertGetId(['name' => 'Other', 'graph' => '{"nodes":[],"edges":[]}']);
        $this->v1 = DB::table('chatbot_flow_versions')->insertGetId(['flow_id' => $f2, 'graph' => '{}', 'note' => 'a', 'created_at' => '2026-10-10 08:00:00']);
        $this->v2 = DB::table('chatbot_flow_versions')->insertGetId(['flow_id' => $f2, 'graph' => '{}', 'note' => 'b', 'created_at' => '2026-10-16 08:00:00']);

        // $tags: null = walang tags (luma), string = ang via, array = ang buong tags.
        $send = function (int $page, int $psid, string $at, ?int $seconds, array|string|null $tags, int $flow = 1, ?int $version = null, string $status = 'sent', string $kind = 'send') {
            $tags = is_string($tags) ? ['v' => 1, 'via' => $tags] : $tags;
            DB::table('chatbot_replies')->insert(['page_id' => $page, 'psid' => $psid, 'flow_id' => $flow, 'flow_version_id' => $version, 'node_id' => 'n1', 'kind' => $kind,
                'content' => '[]', 'status' => $status, 'sent_at' => $at, 'replied_at' => $seconds === null ? null : date('Y-m-d H:i:s', strtotime($at) + $seconds),
                'reply_seconds' => $seconds, 'tags' => $tags === null ? null : json_encode($tags)]);
        };
        $send(1001, 11, '2026-10-14 10:00:00', 60, 'botcake');
        $send(1001, 11, '2026-10-15 10:00:00', null, 'forbeast');          // iisang customer sa dalawang bot
        $send(1001, 12, '2026-10-15 11:00:00', 120, null);                 // luma: walang tags = botcake
        $send(1001, 13, '2026-10-16 10:00:00', null, ['v' => 1]);          // may tags, walang via = botcake
        $send(1001, 14, '2026-10-16 12:00:00', 30, 'incepxionbot');
        $send(1001, 15, '2026-10-17 10:00:00', 45, 'forbeast', 2, $this->v2);
        $send(1001, 15, '2026-10-17 11:00:00', null, 'forbeast', 2, $this->v2);
        $send(1001, 16, '2026-10-18 10:00:00', null, 'something-else');    // ibang via = botcake
        $send(1001, 17, '2026-10-18 11:00:00', null, 'botcake', 1, null, 'failed'); // hindi naipadala
        $send(1001, 18, '2026-10-18 12:00:00', 10, 'botcake', 1, null, 'sent', 'manual'); // hindi padala ng bot
        $send(1001, 19, '2026-10-01 10:00:00', 10, 'botcake');             // labas ng range
        $send(1002, 21, '2026-10-15 10:00:00', 90, 'forbeast');
        $send(1002, 21, '2026-10-16 10:00:00', null, 'botcake');
        $send(1002, 22, '2026-10-16 11:00:00', null, null, 2, $this->v1);
        $send(1002, 23, '2026-10-19 10:00:00', 15, 'forbeast', 2, $this->v1);
        $send(1003, 31, '2026-10-19 12:00:00', null, 'botcake');
        $send(1003, 32, '2026-10-20 15:30:00', 5, 'incepxionbot');         // 23:30 Manila kung UTC ang app

        $chat = fn (string $page, string $psid, string $at, array $payload) => DB::table('botcake_chats')->insert(['page_id' => $page, 'page_name' => 'X', 'psid' => $psid,
            'name' => 'Customer', 'message' => 'hi', 'payload' => json_encode($payload), 'received_at' => $at]);
        $chat('1001', '11', '2026-10-14 09:00:00', ['source' => 'gateway']);                            // forbeast
        $chat('1001', '11', '2026-10-15 09:00:00', ['source' => 'default_reply']);                      // botcake: iisang customer, dalawang bot
        $chat('1001', '12', '2026-10-15 09:30:00', []);                                                  // botcake (walang source)
        $chat('1001', '14', '2026-10-16 09:00:00', ['source' => 'gateway', 'gateway' => 'incepxionbot']);
        $chat('1001', '15', '2026-10-17 09:00:00', ['source' => 'gateway', 'gateway' => 'forbeast']);
        $chat('1001', '15', '2026-10-17 09:05:00', ['source' => 'gateway', '_standby' => true]);         // standby: wala sa bilang
        $chat('1002', '21', '2026-10-15 09:00:00', ['source' => 'gateway']);
        $chat('1002', '22', '2026-10-16 09:00:00', ['source' => 'flow']);
        $chat('1003', '31', '2026-10-19 09:00:00', []);
        $chat('1001', '19', '2026-10-01 09:00:00', []);                                                  // labas ng range

        $order = fn (int $page, int $psid, string $at, array $detail, int $flow = 1) => DB::table('chatbot_events')->insert(['page_id' => $page, 'psid' => $psid,
            'flow_id' => $flow, 'node_id' => 'n9', 'type' => 'order', 'created_at' => $at, 'detail' => json_encode($detail)]);
        $order(1001, 11, '2026-10-14 12:00:00', ['via' => 'botcake']);
        $order(1001, 12, '2026-10-15 12:00:00', []);                                                     // luma: botcake
        $order(1001, 14, '2026-10-16 13:00:00', ['via' => 'incepxionbot', 'sequence_id' => 7, 'sequence_step' => 1]);
        $order(1001, 15, '2026-10-17 12:00:00', ['via' => 'forbeast', 'flow_version' => $this->v2], 2);
        $order(1002, 21, '2026-10-15 12:00:00', ['via' => 'forbeast', 'sequence_id' => 7, 'sequence_step' => 2]);
        $order(1002, 23, '2026-10-19 12:00:00', ['via' => 'forbeast', 'flow_version' => $this->v1], 2);
        $order(1009, 91, '2026-10-19 13:00:00', ['via' => 'botcake']);                                   // page na walang padala

        MacroOutput::create(['PAGE' => 'Page Uno', 'fb_name' => 'X', 'waybill' => 'W11', 'botcake_psid' => '11', 'ts_date' => '2026-10-14']);
        DB::table('from_jnts')->insert(['waybill_number' => 'W11', 'status' => 'Delivered', 'rts_reason' => '']);
        MacroOutput::create(['PAGE' => 'Page Dos', 'fb_name' => 'X', 'waybill' => 'W21', 'botcake_psid' => '21', 'ts_date' => '2026-10-15']);
        DB::table('from_jnts')->insert(['waybill_number' => 'W21', 'status' => 'Returned', 'rts_reason' => '']);
    }

    private function build(array $in): array
    {
        return (new \ReflectionMethod(ChatbotResults::class, 'build'))->invoke(app(ChatbotResults::class), ChatbotResults::filters($in));
    }

    /** @return array<int, array{query: string}> ang query log ng $run */
    private function logOf(callable $run): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return $log;
    }

    /**
     * Ang mga query ng request na kapareho ng SQL ng mismong build ng mga hiling na ito (ang layout ng chatblast ay may
     * sariling basa ng chat at padala para sa nav, kaya ang SQL ng build ang hinahanap, hindi ang pangalan ng table).
     * Pagkatapos ng request ito kinukuwenta (naka-cache na ang page map), at ang kopya ay binubura ulit.
     * @return array<int, string>
     */
    private function reportQueriesIn(array $log, array ...$requests): array
    {
        $build = [];
        foreach ($requests as $in) $build = array_merge($build, array_column($this->logOf(fn () => app(ChatbotResults::class)->buildCopy($in)), 'query'));
        // Ang Schema::hasTable ay hindi basa ng data (ginagamit din ng layout).
        $build = array_filter($build, fn ($q) => !str_contains($q, 'sqlite_master'));
        foreach ($requests as $in) Cache::forget('chatbot-results:copy:v3:' . md5(json_encode([ChatbotResults::filters($in), ChatbotResults::compareFrom($in)])));
        $this->assertGreaterThan(10, count(array_unique($build)), 'the build itself runs the report queries');

        return array_values(array_intersect(array_column($log, 'query'), $build));
    }

    /** @return array<int, string> ang mga query sa mga table ng report (para sa command, na walang layout) */
    private function reportQueries(array $log): array
    {
        return array_values(array_filter(array_column($log, 'query'), fn ($q) => (bool) preg_match('/\b(from|join)\s+["`]?(' . implode('|', self::REPORT_TABLES) . ')["`]?/i', $q)));
    }

    private static function filterCases(): array
    {
        return [[], ['via' => 'botcake'], ['via' => 'forbeast'], ['page_id' => '1001'], ['page_id' => '1002', 'via' => 'forbeast'], ['flow_id' => '1'],
            ['flow_id' => '2', 'version_id' => 'V2'], ['flow_id' => '2', 'via' => 'botcake'], ['page_id' => '1003']];
    }

    // ---- Equality: ang dating code at ang bago ----

    public function test_S_278_1_funnel_by_via_by_page_and_chats_equal_the_old_code_paths(): void
    {
        foreach ([false, true] as $incepxion) {
            config(['services.incepxionbot.url' => $incepxion ? 'https://incepxion.test' : '']);
            $this->assertSame($incepxion, Gateways::setUp(Gateways::INCEPXIONBOT));
            $cases = self::filterCases();
            if ($incepxion) $cases[] = ['via' => 'incepxionbot'];
            foreach ($cases as $extra) {
                if (($extra['version_id'] ?? null) === 'V2') $extra['version_id'] = (string) $this->v2;
                $f = ChatbotResults::filters(self::RANGE + $extra);
                $new = $this->build(self::RANGE + $extra);
                $old = (new OldResultsReference(app(ChatbotResults::class)))->sections($f);
                $label = json_encode($extra) . ($incepxion ? ' +incepxionbot' : '');
                $this->assertSame($old['funnel'], $new['funnel'], 'funnel ' . $label);
                $this->assertSame($old['by_via'], $new['by_via'], 'by_via ' . $label);
                $this->assertSame($old['by_page'], $new['by_page'], 'by_page ' . $label);
                $this->assertSame($old['chats'], $new['funnel']['chats'], 'chats ' . $label);
            }
        }

        // Ang data ay talagang may laman sa bawat bot (hindi pantay na sero ang paghahambing).
        $r = $this->build(self::RANGE);
        $this->assertSame([7, 5, 2], [$r['by_via']['botcake']['sent'], $r['by_via']['forbeast']['sent'], $r['by_via']['incepxionbot']['sent']]);
        $this->assertSame([14, 11, 7], [$r['funnel']['sent'], $r['funnel']['customers_sent'], $r['funnel']['chats']]);
        $this->assertSame([4, 3, 1], [$r['by_via']['botcake']['chats'], $r['by_via']['forbeast']['chats'], $r['by_via']['incepxionbot']['chats']]);
        // Ang psid 11 (1001) at 21 (1002) ay naabot ng dalawang bot: isang customer sa funnel (11), tig-isa sa bawat bot (7 + 4 + 2).
        $this->assertSame([7, 4, 2], [$r['by_via']['botcake']['customers_sent'], $r['by_via']['forbeast']['customers_sent'], $r['by_via']['incepxionbot']['customers_sent']]);
        $this->assertSame(['1001', '1002', '1009', '1003'], array_column($r['by_page']['rows'], 'page_id'));
    }

    public function test_S_278_2_a_build_runs_fewer_queries(): void
    {
        config(['services.incepxionbot.url' => '']);
        $f = ChatbotResults::filters(self::RANGE);
        $count = function (callable $run): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $run();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        Cache::flush();
        BotcakeChatMapWarm::warm();
        $new = $count(fn () => $this->build(self::RANGE));
        BotcakeChatMapWarm::warm();
        $oldSections = $count(fn () => (new OldResultsReference(app(ChatbotResults::class)))->sections($f, false));
        BotcakeChatMapWarm::warm();
        $newSections = $count(function () use ($f) {
            [$start, $end] = (new \ReflectionMethod(ChatbotResults::class, 'bounds'))->invoke(app(ChatbotResults::class), $f);
            (new \ReflectionMethod(ChatbotResults::class, 'sendCounts'))->invoke(app(ChatbotResults::class), $f, $start, $end);
            (new \ReflectionMethod(ChatbotResults::class, 'chatCounts'))->invoke(app(ChatbotResults::class), $f, $start, $end);
        });

        // Dati: funnel 3 + 1 (chats), By bot 2 bot × (3 + 1), By page 1 = 13. Ngayon: 1 basa ng padala + 1 ng chat.
        $this->assertSame(13, $oldSections);
        $this->assertSame(2, $newSections);
        // Ang buong build sa seeded na data: 25 query bago (sinukat sa base; 29 kapag naka-set up ang incepxionbot), 14 ngayon
        // (14 din kahit 3 bot: hindi na dumarami kada bot).
        $this->assertSame(14, $new);
        config(['services.incepxionbot.url' => 'https://incepxion.test']);
        BotcakeChatMapWarm::warm();
        $this->assertSame(14, $count(fn () => $this->build(self::RANGE)));
    }

    // ---- Web: kopya lang ----

    public function test_S_278_3_with_no_copy_the_page_and_json_answer_building_add_to_wanted_and_run_no_report_query(): void
    {
        $this->actingAs($this->user('CEO', 'ceo278@example.test'));
        $q = self::RANGE + ['page_id' => '1001', 'compare' => 'flow', 'a' => (string) $this->v1, 'b' => (string) $this->v2];

        $log = $this->logOf(function () use ($q, &$page) {
            $page = $this->get('/chatbot/results?' . http_build_query($q))->assertOk();
        });
        $page->assertSee('Binubuo pa ang report (mga 1–3 minuto). Kusa itong magre-reload.');
        $page->assertSee('location.reload(); }, 20000', false);
        $page->assertDontSee('id="h-funnel"', false);
        $page->assertSee('name="from" value="2026-10-14"', false);

        $wanted = app(ChatbotResults::class)->wanted();
        $this->assertCount(1, $wanted);
        $w = array_values($wanted)[0];
        $this->assertSame(['page_id' => '1001', 'from' => '2026-10-14', 'to' => '2026-10-20', 'compare' => 'flow', 'a' => (string) $this->v1, 'b' => (string) $this->v2], $w['in']);
        $this->assertSame(now()->getTimestamp(), $w['at']);

        // Ang ulit na hiling ay hindi nagdadagdag at hindi binabago ang oras (pinakamatanda muna ang warm).
        $this->travel(20)->seconds();
        $log = array_merge($log, $this->logOf(function () use ($q) {
            $this->getJson('/chatbot/results.json?' . http_build_query($q))->assertStatus(202)->assertExactJson(['status' => 'building']);
            $this->getJson('/chatbot/results.json?' . http_build_query(self::RANGE))->assertStatus(202)->assertExactJson(['status' => 'building']);
        }));
        $wanted = app(ChatbotResults::class)->wanted();
        $this->assertCount(2, $wanted);
        $this->assertSame($w, array_values($wanted)[0]);
        $this->assertSame(self::RANGE, array_values($wanted)[1]['in']);

        $this->assertSame([], $this->reportQueriesIn($log, $q, self::RANGE));
    }

    public function test_S_278_4_with_a_fresh_copy_the_page_and_json_serve_it_with_as_of_build_nothing_and_do_not_re_want(): void
    {
        $this->actingAs($this->user('CEO', 'ceo278b@example.test'));
        $copy = app(ChatbotResults::class)->buildCopy(self::RANGE);
        $asOf = Carbon::parse($copy['built_at'])->timezone('Asia/Manila')->format('H:i');
        $this->travel(14)->minutes(); // sariwa pa (wala pang 15 minuto)

        $log = $this->logOf(function () use (&$page, &$json) {
            $page = $this->get('/chatbot/results?' . http_build_query(self::RANGE))->assertOk();
            $json = $this->getJson('/chatbot/results.json?' . http_build_query(self::RANGE))->assertOk()->json();
        });

        $page->assertSee('as of ' . $asOf . ' (Manila)');
        $page->assertSeeInOrder(['name="to"', 'as of ' . $asOf, 'Funnel', 'By page', 'Page Uno'], false);
        $page->assertDontSee('Binubuo pa ang report');
        $this->assertSame(json_decode(json_encode($copy['out'] + ['built_at' => $copy['built_at']]), true), $json);
        $this->assertSame([], app(ChatbotResults::class)->wanted());
        $this->assertSame([], $this->reportQueriesIn($log, self::RANGE));
    }

    public function test_S_278_4b_a_stale_filtered_copy_is_served_at_once_and_wanted_and_the_warm_rebuilds_it(): void
    {
        $this->actingAs($this->user('CEO', 'ceo278c@example.test'));
        $results = app(ChatbotResults::class);
        $q = self::RANGE + ['page_id' => '1001'];
        $old = $results->buildCopy($q);
        $oldAsOf = Carbon::parse($old['built_at'])->timezone('Asia/Manila')->format('H:i');
        $this->travel(16)->minutes(); // lampas 15 minuto: luma na
        $results->buildCopy([], true); // sariwa ang default, para ang hiling lang ang binubuo ng warm

        $log = $this->logOf(function () use ($q, &$page, &$json) {
            $page = $this->get('/chatbot/results?' . http_build_query($q))->assertOk();
            $json = $this->getJson('/chatbot/results.json?' . http_build_query($q))->assertOk()->json();
        });
        $page->assertSee('as of ' . $oldAsOf . ' (Manila)');
        $page->assertSee('id="h-funnel"', false);
        $page->assertDontSee('Binubuo pa ang report');
        $this->assertSame($old['built_at'], $json['built_at']);
        $this->assertSame([ChatbotResults::request($q)], array_column(array_values($results->wanted()), 'in'), 'the stale copy is wanted once (page and json)');

        // Ang warm ay hindi nag-aalis nito (luma ang kopya): binubuo ulit.
        $this->travel(1)->minutes();
        $r = $results->warm();
        $this->assertSame([ChatbotResults::label($q)], array_column($r['built'], 0));
        $this->assertSame(0, $r['dropped']);
        $this->assertSame([], $results->wanted());
        $fresh = $results->stored($q);
        $this->assertSame(now()->toIso8601String(), $fresh['built_at']);
        $this->assertSame(['1001', 6], [$fresh['out']['by_page']['rows'][0]['page_id'], $fresh['out']['by_page']['rows'][0]['customers_sent']]);

        // Ang bagong kopya ay ipinapakita at hindi na idinadagdag sa wanted.
        $this->get('/chatbot/results?' . http_build_query($q))->assertOk()
            ->assertSee('as of ' . Carbon::parse($fresh['built_at'])->timezone('Asia/Manila')->format('H:i') . ' (Manila)');
        $this->assertSame([], $results->wanted());
        $this->assertSame([], $this->reportQueriesIn($log, $q));
    }

    // ---- Warm ----

    public function test_S_278_5_the_warm_builds_the_default_when_missing_or_stale_and_skips_it_when_fresh(): void
    {
        $results = app(ChatbotResults::class);
        $this->assertNull($results->stored([]));

        $r = $results->warm();
        $this->assertSame(['default'], array_column($r['built'], 0));
        $copy = $results->stored([]);
        $this->assertSame(now()->toIso8601String(), $copy['built_at']);
        $this->assertSame(['2026-10-15', '2026-10-21'], [$copy['out']['filters']['from'], $copy['out']['filters']['to']]);

        $this->travel(14)->minutes();
        $this->assertSame([], $results->warm()['built'], 'fresh: nothing to build');
        $this->travel(2)->minutes();
        $this->assertSame(['default'], array_column($results->warm()['built'], 0), 'older than 15 minutes');
        $this->assertSame(now()->toIso8601String(), $results->stored([])['built_at']);

        // Ang default ay 6 na oras itinatago, ang iba 1 oras.
        $results->buildCopy(self::RANGE);
        $this->travel(61)->minutes();
        $this->assertNull($results->stored(self::RANGE));
        $this->assertNotNull($results->stored([]));
    }

    public function test_S_278_6_the_warm_builds_wanted_oldest_first_three_per_run_and_drops_entries_older_than_30_minutes(): void
    {
        $results = app(ChatbotResults::class);
        $results->buildCopy([], true); // sariwa ang default
        $results->want(self::RANGE + ['page_id' => '1003']);       // ang pinakamatanda: lalampas sa 30 minuto
        $this->travel(25)->minutes();
        $order = [['page_id' => '1002'], ['via' => 'forbeast'], ['page_id' => '1001'], ['flow_id' => '2']];
        foreach ($order as $i => $extra) {
            $results->want(self::RANGE + $extra);
            $this->travel(1)->minutes();
        }
        $results->want([]); // may kopya na (ang default): tinatanggal, hindi binubuo ulit
        $this->travel(2)->minutes(); // ang una ay 32 minuto na

        // 31 minuto na ang default (lampas 15): ito muna, saka ang 3 pinakamatandang wanted.
        $r = $results->warm();
        $this->assertSame(['default', ChatbotResults::label(self::RANGE + ['page_id' => '1002']), ChatbotResults::label(self::RANGE + ['via' => 'forbeast']),
            ChatbotResults::label(self::RANGE + ['page_id' => '1001'])], array_column($r['built'], 0));
        $this->assertSame(2, $r['dropped']);
        $this->assertNull($results->stored(self::RANGE + ['page_id' => '1003']));
        foreach (array_slice($order, 0, 3) as $extra) $this->assertNotNull($results->stored(self::RANGE + $extra));
        $this->assertSame([self::RANGE + ['flow_id' => '2']], array_column(array_values($results->wanted()), 'in'));

        // Ang kopya ng wanted ay kapareho ng diretsong build (report) ng parehong filter.
        $this->assertSame($results->output(self::RANGE + ['page_id' => '1002']), $results->stored(self::RANGE + ['page_id' => '1002'])['out']);

        $this->assertSame([ChatbotResults::label(self::RANGE + ['flow_id' => '2'])], array_column($results->warm()['built'], 0));
        $this->assertSame([], $results->wanted());
        $this->assertSame([], $results->warm()['built']);
    }

    public function test_S_278_7_a_failing_build_is_reported_and_the_others_still_build(): void
    {
        $failing = new class(app(PageSettings::class)) extends ChatbotResults {
            public function buildCopy(array $in, bool $default = false): array
            {
                if (($in['page_id'] ?? null) === '1002') throw new \RuntimeException('boom');

                return parent::buildCopy($in, $default);
            }
        };
        $this->app->instance(ChatbotResults::class, $failing);
        $failing->want(self::RANGE + ['page_id' => '1002']);
        $this->travel(1)->seconds();
        $failing->want(self::RANGE + ['page_id' => '1001']);
        Log::spy();

        $this->assertSame(1, Artisan::call('chatbot:results-warm'));
        $line = trim(Artisan::output());
        $this->assertStringStartsWith('chatbot:results-warm built default (', $line);
        $this->assertStringContainsString(ChatbotResults::label(self::RANGE + ['page_id' => '1001']) . ' (', $line);
        $this->assertStringContainsString('; failed ' . ChatbotResults::label(self::RANGE + ['page_id' => '1002']), $line);
        $this->assertNotNull($failing->stored([]));
        $this->assertNotNull($failing->stored(self::RANGE + ['page_id' => '1001']));
        $this->assertNull($failing->stored(self::RANGE + ['page_id' => '1002']));
        $this->assertSame([], $failing->wanted(), 'the failed one is not retried every minute; the page adds it again');
        Log::shouldHaveReceived('warning')->once()->with($line);
    }

    public function test_S_278_8_the_warm_logs_one_line_and_skips_while_another_run_holds_the_lock(): void
    {
        Log::spy();
        $this->assertSame(0, Artisan::call('chatbot:results-warm'));
        $this->assertMatchesRegularExpression('/^chatbot:results-warm built default \(\d+\.\d s\) in \d+\.\d s$/', trim(Artisan::output()));
        Log::shouldHaveReceived('info')->once();

        $lock = Cache::lock(\App\Console\Commands\ChatbotResultsWarm::LOCK, 60);
        $this->assertTrue($lock->get());
        $this->assertSame(0, Artisan::call('chatbot:results-warm'));
        $this->assertSame('', trim(Artisan::output()));
        $lock->release();
    }

    public function test_S_278_9_the_schedule_runs_the_warm_every_minute_once_at_a_time_on_one_server(): void
    {
        Artisan::call('schedule:list'); // nilo-load ang routes/console.php
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'chatbot:results-warm'))->values();

        $this->assertCount(1, $events);
        $e = $events[0];
        $this->assertSame('* * * * *', $e->expression);
        $this->assertTrue($e->withoutOverlapping);
        $this->assertSame(30, $e->expiresAt);
        $this->assertTrue($e->onOneServer);
    }

    public function test_S_278_10_the_console_command_still_builds_directly(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Artisan::call('chatbot:results', ['--from' => '2026-10-14', '--to' => '2026-10-20', '--json' => true]);
        $log = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertNotSame([], $this->reportQueries($log));
        $this->assertSame(14, json_decode(Artisan::output(), true)['funnel']['sent']);
        $this->assertSame([], app(ChatbotResults::class)->wanted());
    }
}

/** Para sa bilang ng query: ang page map ay naka-cache, kaya pare-pareho ang bilang ng bawat paraan. */
final class BotcakeChatMapWarm
{
    public static function warm(): void
    {
        \App\Models\BotcakeChat::pageMap();
    }
}

/**
 * Ang kopya ng dating code (bago ang spec 278) ng funnel, By bot, By page at chats: hiwalay na query kada bahagi at kada
 * bot. Ang orders at deliveries ay hindi nagbago, kaya kinukuha sa service.
 */
final class OldResultsReference
{
    public function __construct(private ChatbotResults $service)
    {
    }

    private function call(string $method, ...$args)
    {
        return (new \ReflectionMethod(ChatbotResults::class, $method))->invoke($this->service, ...$args);
    }

    /** $orders: false = ang sends lang (para sa bilang ng query ng mga bahaging ito). */
    public function sections(array $f, bool $withOrders = true): array
    {
        [$start, $end] = $this->call('bounds', $f);
        $allOrders = $withOrders ? $this->call('orders', $f, $start, $end) : collect();
        $orders = $allOrders->filter(fn ($o) => $f['via'] === null || self::orderVia($o) === $f['via']);
        $delivery = $withOrders ? $this->service->deliveries($allOrders) : [];
        $flowOrders = $orders->filter(fn ($o) => ($f['flow_id'] === null || (int) $o->flow_id === (int) $f['flow_id'])
            && ($f['version_id'] === null || (int) ($o->detail['flow_version'] ?? 0) === (int) $f['version_id']));
        $names = \App\Models\BotcakeChat::pageMap();
        $chats = $this->chats($f, $start, $end);

        return [
            'chats' => $chats,
            'funnel' => ['chats' => $chats] + $this->metrics($this->sends($f, $start, $end, true), $flowOrders, $delivery),
            'by_via' => $this->byVia($f, $start, $end, $allOrders, $delivery),
            'by_page' => $this->byPage($f, $start, $end, $flowOrders, $delivery, $names),
        ];
    }

    private function sends(array $f, Carbon $start, Carbon $end, bool $flow)
    {
        return DB::table('chatbot_replies')->where('sent_at', '>=', $start)->where('sent_at', '<', $end)->where('kind', 'send')->where('status', 'sent')
            ->when($f['page_id'], fn ($q, $v) => $q->where('page_id', (int) $v))
            ->when($flow && $f['flow_id'], fn ($q) => $q->where('flow_id', (int) $f['flow_id']))
            ->when($flow && $f['version_id'], fn ($q) => $q->where('flow_version_id', (int) $f['version_id']))
            ->when($f['via'], fn ($q, $v) => self::whereVia($q, 'tags->via', $v));
    }

    private static function whereVia($q, string $column, string $via)
    {
        $gateway = Gateways::valid($via);
        if ($column === 'payload->source') {
            return $q->where(fn ($w) => !$gateway ? $w->whereNull($column)->orWhere($column, '<>', 'gateway')
                : $w->where($column, 'gateway')->where(fn ($g) => $via === Gateways::FORBEAST ? $g->whereNull('payload->gateway')->orWhere('payload->gateway', $via) : $g->where('payload->gateway', $via)));
        }

        return $q->where(fn ($w) => $gateway ? $w->where($column, $via) : $w->whereNull($column)->orWhereNotIn($column, Gateways::ALL));
    }

    private static function orderVia(object $o): string
    {
        return Gateways::valid($o->detail['via'] ?? null) ? $o->detail['via'] : 'botcake';
    }

    private static function pct(int|float $a, int|float $b): ?float
    {
        return $b > 0 ? round(100 * $a / $b, 1) : null;
    }

    private function chats(array $f, Carbon $start, Carbon $end): int
    {
        return (int) DB::table('botcake_chats')->whereNull('payload->_standby')->where('received_at', '>=', $start)->where('received_at', '<', $end)
            ->when($f['page_id'], fn ($q, $v) => $q->where('page_id', (string) $v))
            ->when($f['via'], fn ($q, $v) => self::whereVia($q, 'payload->source', $v))
            ->groupBy('page_id')->selectRaw('COUNT(DISTINCT psid) AS n')->pluck('n')->sum();
    }

    private function byVia(array $f, Carbon $start, Carbon $end, Collection $allOrders, array $delivery): array
    {
        $out = [];
        foreach (ChatbotResults::vias() as $via) {
            $g = ['via' => $via] + $f;
            $orders = $allOrders->filter(fn ($o) => self::orderVia($o) === $via
                && ($f['flow_id'] === null || (int) $o->flow_id === (int) $f['flow_id'])
                && ($f['version_id'] === null || (int) ($o->detail['flow_version'] ?? 0) === (int) $f['version_id']));
            $out[$via] = ['chats' => $this->chats($g, $start, $end)] + $this->metrics($this->sends($g, $start, $end, true), $orders, $delivery);
        }

        return $out;
    }

    private function byPage(array $f, Carbon $start, Carbon $end, Collection $orders, array $delivery, array $names): array
    {
        $blank = ['sent' => 0, 'customers_sent' => 0, 'replied' => 0, 'orders' => 0, 'main_flow' => 0, 'after_sequence' => 0, 'delivered' => 0];
        $rows = [];
        foreach ($this->sends($f, $start, $end, true)->groupBy('page_id')
            ->selectRaw('page_id, COUNT(*) AS sent, COUNT(DISTINCT psid) AS customers, SUM(CASE WHEN replied_at IS NOT NULL THEN 1 ELSE 0 END) AS replied')->get() as $r) {
            $rows[(string) $r->page_id] = ['sent' => (int) $r->sent, 'customers_sent' => (int) $r->customers, 'replied' => (int) $r->replied] + $blank;
        }
        foreach ($orders as $o) {
            $p = (string) $o->page_id;
            $rows[$p] ??= $blank;
            $rows[$p]['orders']++;
            $rows[$p][empty($o->detail['sequence_id']) || empty($o->detail['sequence_step']) ? 'main_flow' : 'after_sequence']++;
            if (($delivery[$o->id]['delivery'] ?? '') === 'delivered') $rows[$p]['delivered']++;
        }

        $total = $blank;
        foreach ($rows as $r) foreach ($blank as $k => $_) $total[$k] += $r[$k];
        $rates = fn (array $r) => $r + ['replied_pct' => self::pct($r['replied'], $r['sent']), 'orders_pct' => self::pct($r['orders'], $r['customers_sent']),
            'main_flow_pct' => self::pct($r['main_flow'], $r['orders']), 'after_sequence_pct' => self::pct($r['after_sequence'], $r['orders']),
            'delivered_pct' => self::pct($r['delivered'], $r['orders'])];
        $out = [];
        foreach ($rows as $id => $r) $out[] = ['page_id' => (string) $id, 'page' => $names[(string) $id] ?? (string) $id] + $rates($r);
        usort($out, fn ($a, $b) => [$b['orders'], $b['sent'], $a['page']] <=> [$a['orders'], $a['sent'], $b['page']]);

        return ['rows' => array_slice($out, 0, ChatbotResults::MAX_ROWS), 'total' => $rates($total)];
    }

    /** Ang dating metrics() sa anyo ng funnel (walang median). */
    private function metrics($sends, Collection $orders, array $delivery): array
    {
        $sent = (int) (clone $sends)->count();
        $customers = (int) (clone $sends)->groupBy('page_id')->selectRaw('COUNT(DISTINCT psid) AS n')->pluck('n')->sum();
        $replied = (int) (clone $sends)->whereNotNull('replied_at')->count();
        $tally = ['delivered' => 0, 'rts' => 0, 'in_transit' => 0, 'other' => 0, 'unmatched' => 0];
        foreach ($orders as $o) $tally[$delivery[$o->id]['delivery'] ?? 'unmatched']++;
        $n = $orders->count();
        $out = ['sent' => $sent, 'customers_sent' => $customers, 'replied' => $replied, 'replied_pct' => self::pct($replied, $sent)];

        return $out + ['orders' => $n, 'orders_pct' => self::pct($n, $customers), 'delivered' => $tally['delivered'], 'delivered_pct' => self::pct($tally['delivered'], $n),
            'rts' => $tally['rts'], 'rts_pct' => self::pct($tally['rts'], $n), 'in_transit' => $tally['in_transit'], 'other' => $tally['other'], 'unmatched' => $tally['unmatched']];
    }
}
