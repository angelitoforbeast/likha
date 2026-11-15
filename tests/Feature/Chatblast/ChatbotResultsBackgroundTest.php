<?php

namespace Tests\Feature\Chatblast;

use App\Models\MacroOutput;
use App\Services\Chatbot\ChatbotResults;
use App\Services\Chatbot\Gateways;
use App\Services\Chatbot\ResultsPieces;
use App\Services\PageSettings;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Spec 278, /chatbot/results na binubuo sa background: ang page at results.json ay nagbabasa ng kopya lang (walang kopya =
 * "binubuo pa" at wanted), ang chatbot:results-warm ang bumubuo, at (spec 278-2) ang report ay pinagsamang mga bahagi ng
 * araw na may parehong numero ng dating code (OldChatbotResultsReference, ang kopya ng base). Ngayon ay 2026-10-21 09:00. Tatlong page
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
    private int $s1;
    private int $s2;
    private int $seq;
    /** @var array<int, array<int, int>> psid => mga id ng padala */
    private array $ids = [];

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
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
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

        $this->s1 = DB::table('page_settings')->insertGetId(['page_name' => 'Page Uno', 'provider' => 'chatbot', 'key' => 'main_flow', 'value' => 'Hello v1', 'is_secret' => false,
            'active' => false, 'created_at' => '2026-10-10 08:00:00', 'updated_at' => '2026-10-15 08:00:00']);
        $this->s2 = DB::table('page_settings')->insertGetId(['page_name' => 'Page Uno', 'provider' => 'chatbot', 'key' => 'main_flow', 'value' => 'Hello v2', 'is_secret' => false,
            'active' => true, 'created_at' => '2026-10-15 08:00:00', 'updated_at' => '2026-10-15 08:00:00']);
        $this->seq = DB::table('chatbot_sequences')->insertGetId(['name' => 'SEQ', 'active' => true, 'exit_on_reply' => true, 'created_at' => now(), 'updated_at' => now()]);

        // $tags: null = walang tags (luma), string = ang via, array = ang buong tags. Ang column na via ay kapareho ng tags.via
        // (ang backfill), para pantay ang luma at bagong daan. Ang padala ay may 1 o 2 tag row (message 0 at 1), may node,
        // botfield, sequence step at ad depende sa psid.
        $send = function (int $page, int $psid, string $at, ?int $seconds, array|string|null $tags, int $flow = 1, ?int $version = null, string $status = 'sent', string $kind = 'send') {
            $tags = is_string($tags) ? ['v' => 1, 'via' => $tags] : $tags;
            $setting = $psid % 3 === 0 ? null : ($psid % 2 ? $this->s1 : $this->s2);
            $seq = $psid % 4 === 0 ? [$this->seq, $psid % 8 ? 1 : 2] : [null, null];
            $id = DB::table('chatbot_replies')->insertGetId(['page_id' => $page, 'psid' => $psid, 'flow_id' => $flow, 'flow_version_id' => $version, 'node_id' => 'n' . ($psid % 2), 'kind' => $kind,
                'content' => '[]', 'status' => $status, 'sent_at' => $at, 'replied_at' => $seconds === null ? null : date('Y-m-d H:i:s', strtotime($at) + $seconds),
                'reply_seconds' => $seconds, 'tags' => $tags === null ? null : json_encode($tags), 'via' => is_array($tags) ? ($tags['via'] ?? null) : null,
                'sequence_id' => $seq[0], 'sequence_step' => $seq[1], 'ad_id' => $psid % 5 === 0 ? null : 'AD' . ($psid % 2)]);
            foreach ($psid % 2 ? [0] : [0, 1] as $m) {
                DB::table('chatbot_reply_tags')->insert(['reply_id' => $id, 'page_id' => $page, 'flow_id' => $flow, 'flow_version_id' => $version, 'node_id' => 'n' . ($psid % 2 + $m),
                    'message' => $m, 'botfield' => $setting ? 'main_flow' : null, 'setting_id' => $setting, 'sent_at' => $at]);
            }
            $this->ids[$psid][] = $id;
        };
        // Ngayon (2026-10-21) at kahapon: para sa range na kasama ang ngayon at sa pagbuo ulit ng luma.
        $send(1001, 24, '2026-10-21 00:30:00', 20, 'forbeast');
        $send(1002, 26, '2026-10-20 03:00:00', null, 'botcake');
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
        $order(1001, 11, '2026-10-14 12:00:00', ['via' => 'botcake', 'sends' => $this->ids[11], 'ad_id' => 'AD1']);
        $order(1001, 12, '2026-10-15 12:00:00', ['sends' => $this->ids[12]]);                            // luma: botcake
        $order(1001, 14, '2026-10-16 13:00:00', ['via' => 'incepxionbot', 'sequence_id' => $this->seq, 'sequence_step' => 1, 'sends' => $this->ids[14]]);
        $order(1001, 15, '2026-10-17 12:00:00', ['via' => 'forbeast', 'flow_version' => $this->v2, 'sends' => $this->ids[15], 'ad_id' => 'AD1'], 2);
        $order(1002, 21, '2026-10-15 12:00:00', ['via' => 'forbeast', 'sequence_id' => $this->seq, 'sequence_step' => 2, 'sends' => $this->ids[21], 'ad_id' => 'AD9']);
        $order(1002, 23, '2026-10-19 12:00:00', ['via' => 'forbeast', 'flow_version' => $this->v1, 'sends' => $this->ids[23]], 2);
        $order(1009, 91, '2026-10-19 13:00:00', ['via' => 'botcake']);                                   // page na walang padala
        DB::table('chatbot_sequence_subscriptions')->insert(['sequence_id' => $this->seq, 'page_id' => 1001, 'psid' => 16, 'status' => 'unsubscribed', 'current_step' => 2,
            'exit_reason' => 'reply', 'created_at' => '2026-10-16 11:00:00', 'updated_at' => '2026-10-18 15:00:00']);

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

        // Ang tingin sa schema (Schema::hasTable, hasColumn ng via; minsan kada app) ay hindi basa ng data.
        return array_values(array_filter($log, fn ($q) => !str_contains($q['query'], 'sqlite_master') && !str_contains($q['query'], 'pragma_table')));
    }

    /**
     * Ang mga query ng request na kapareho ng SQL ng mismong build ng mga hiling na ito (ang layout ng chatblast ay may
     * sariling basa ng chat at padala para sa nav, kaya ang SQL ng build ang hinahanap, hindi ang pangalan ng table).
     * Pagkatapos ng request ito kinukuwenta (naka-cache na ang page map), at ang kopya ay binubura ulit.
     * @return array<int, string>
     */
    private function reportQueriesIn(array $log, array ...$requests): array
    {
        // Walang naitabing bahagi o kopya: lahat ng query ng build ay lumalabas (ang page map ay naka-cache muna).
        Cache::flush();
        BotcakeChatMapWarm::warm();
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

    // ---- Equality: ang dating code at ang bago (spec 278-2: mula sa mga bahagi ng araw) ----

    private function old(): OldChatbotResultsReference
    {
        return new OldChatbotResultsReference(app(PageSettings::class));
    }

    private function oldBuild(array $in): array
    {
        return (new \ReflectionMethod(OldChatbotResultsReference::class, 'build'))->invoke($this->old(), ChatbotResults::filters($in));
    }

    public function test_S_278_1_the_merged_day_pieces_equal_the_old_code_for_1_3_and_7_day_ranges_filters_and_bots(): void
    {
        $ranges = [['from' => '2026-10-16', 'to' => '2026-10-16'], ['from' => '2026-10-15', 'to' => '2026-10-17'], self::RANGE, ['from' => '2026-10-15', 'to' => '2026-10-21']];
        $checked = 0;
        foreach ([false, true] as $incepxion) {
            config(['services.incepxionbot.url' => $incepxion ? 'https://incepxion.test' : '']);
            $this->assertSame($incepxion, Gateways::setUp(Gateways::INCEPXIONBOT));
            $cases = self::filterCases();
            if ($incepxion) $cases[] = ['via' => 'incepxionbot'];
            foreach ($ranges as $range) {
                foreach ($cases as $extra) {
                    if (($extra['version_id'] ?? null) === 'V2') $extra['version_id'] = (string) $this->v2;
                    $label = json_encode($range + $extra) . ($incepxion ? ' +incepxionbot' : '');
                    $this->assertSame($this->oldBuild($range + $extra), $this->build($range + $extra), $label);
                    $checked++;
                }
                // Compare: dalawang flow version (lahat ng bot at kada bot) at dalawang botfield version.
                foreach ([['flow', $this->v1, $this->v2, []], ['flow', $this->v2, $this->v1, ['via' => 'forbeast']], ['flow', $this->v1, $this->v2, ['page_id' => '1002']],
                    ['botfield', $this->s1, $this->s2, []], ['botfield', $this->s2, $this->s1, ['page_id' => '1001']]] as [$kind, $a, $b, $extra]) {
                    $f = ChatbotResults::filters($range + $extra);
                    $this->assertSame($this->old()->compare($f, $kind, $a, $b), app(ChatbotResults::class)->compare($f, $kind, $a, $b), "compare $kind $label");
                    $checked++;
                }
            }
        }
        $this->assertSame(2 * 4 * 5 + 4 * 9 + 4 * 10, $checked);

        // Ang data ay talagang may laman sa bawat bahagi at bot (hindi pantay na sero ang paghahambing).
        $r = $this->build(self::RANGE);
        foreach (['botcake', 'forbeast', 'incepxionbot'] as $via) {
            $this->assertGreaterThan(0, $r['by_via'][$via]['sent'], $via);
            $this->assertGreaterThan(0, $r['by_via'][$via]['chats'], $via);
        }
        // Ang psid 11 (1001) at 21 (1002) ay naabot ng dalawang bot: isang customer sa funnel, tig-isa sa bawat bot.
        $this->assertLessThan(array_sum(array_column($r['by_via'], 'customers_sent')), $r['funnel']['customers_sent']);
        $this->assertNotSame([], $r['by_message']);
        $this->assertNotNull($r['by_message'][0]['median_reply_seconds']);
        $this->assertNotSame([], $r['by_step']);
        $this->assertSame(1, array_sum(array_column($r['by_step'], 'unsubscribed')));
        $this->assertNotSame([], $r['by_ad']);
        $c = app(ChatbotResults::class)->compare(ChatbotResults::filters(self::RANGE), 'botfield', $this->s1, $this->s2);
        $this->assertGreaterThan(0, $c['a']['sent'] * $c['b']['sent']);
    }

    public function test_S_278_2_a_day_is_one_read_per_part_on_plain_columns_and_a_refresh_reads_only_the_stale_day(): void
    {
        config(['services.incepxionbot.url' => '']);
        $pieces = new ResultsPieces();
        $f = ChatbotResults::filters(self::RANGE);

        // Isang araw, saklaw na page lang: 1 basa ng padala, 1 ng tags (By message), 1 ng chat. May flow: 2 (walang chat).
        $log = array_column($this->logOf(fn () => $pieces->build('2026-10-16', ResultsPieces::base($f))), 'query');
        $this->assertCount(3, $log, json_encode($log));
        $this->assertCount(2, $this->logOf(fn () => $pieces->build('2026-10-16', ResultsPieces::main(['flow_id' => '1'] + $f))));
        foreach ($log as $q) {
            $this->assertStringNotContainsStringIgnoringCase('json', $q, 'no JSON in SQL: ' . $q);
            $this->assertStringNotContainsStringIgnoringCase('group by', $q);
        }
        $this->assertStringContainsString('"sent_at" >= ? and "sent_at" < ?', $log[0]);
        $this->assertStringContainsString('"via"', $log[0]);

        // Ang buong 7 araw na wala pang bahagi: 7 × 3 + ang order, delivered at iba pa. Ang muling pagbuo pagkatapos ng 16
        // minuto: ang ngayon lang ang binabasa ulit (wala ang ngayon sa RANGE, kaya walang basa ng padala).
        Cache::flush();
        BotcakeChatMapWarm::warm();
        $cold = $this->logOf(fn () => $this->build(self::RANGE));
        $sendReads = fn (array $log) => count(array_filter(array_column($log, 'query'), fn ($q) => str_starts_with($q, 'select "page_id", "psid", "replied_at", "via"')));
        $this->assertSame(7, $sendReads($cold));
        $this->travel(16)->minutes();
        $this->assertSame(0, $sendReads($this->logOf(fn () => $this->build(self::RANGE))), 'older days are not read again');
        $today = ['from' => '2026-10-15', 'to' => '2026-10-21'];
        $this->build($today);
        $this->travel(16)->minutes();
        $this->assertSame(1, $sendReads($this->logOf(fn () => $this->build($today))), 'only today is read again after 15 minutes');
        // Kahapon at noong isang araw: pagkatapos ng 60 minuto.
        $this->travel(45)->minutes();
        $this->assertSame(3, $sendReads($this->logOf(fn () => $this->build($today))), 'today, yesterday and the day before after an hour');
        // Ang kabuuang bilang ng query ng isang buong build (sinukat): bago 25 (bawat isa ay 7 araw), ngayon 33 kapag walang
        // bahagi pa (7 araw × 3 na isang araw lang ang bawat isa, at 12 na order, delivered, sequence at iba pa), at 16 sa
        // pagbuo ulit kada 15 minuto ng range na may ngayon (ang ngayon lang: 3, at ang 13 na iba).
        $this->assertSame(33, count($cold));
        $this->travel(16)->minutes();
        $this->assertSame(16, count($this->logOf(fn () => $this->build($today))));
    }

    public function test_S_278_2b_a_large_day_is_streamed_with_three_queries_and_bounded_memory(): void
    {
        // 30,000 padala sa isang araw (3 page, 3 bot, 12,000 customer), bawat isa may tag row.
        $day = '2026-10-12';
        [$start] = ResultsPieces::bounds($day);
        $rows = [];
        $tags = [];
        $id = 1000000;
        for ($i = 0; $i < 30000; $i++) {
            $id++;
            $at = $start->copy()->addSeconds($i * 2)->format('Y-m-d H:i:s');
            $via = ['botcake', 'forbeast', 'incepxionbot', null][$i % 4];
            $rows[] = ['id' => $id, 'page_id' => 1001 + $i % 3, 'psid' => 5000000 + $i % 12000, 'flow_id' => 1, 'node_id' => 'n' . ($i % 7), 'kind' => 'send', 'content' => '[]',
                'status' => 'sent', 'sent_at' => $at, 'replied_at' => $i % 3 ? null : $at, 'reply_seconds' => $i % 3 ? null : $i % 500, 'via' => $via,
                'tags' => json_encode(['v' => 1, 'via' => $via]), 'sequence_id' => $i % 5 ? null : $this->seq, 'sequence_step' => $i % 5 ? null : 1 + $i % 3, 'ad_id' => 'AD' . ($i % 40)];
            $tags[] = ['reply_id' => $id, 'page_id' => 1001 + $i % 3, 'flow_id' => 1, 'node_id' => 'n' . ($i % 7), 'message' => 0, 'botfield' => null, 'setting_id' => null, 'sent_at' => $at];
        }
        foreach (array_chunk($rows, 500) as $chunk) DB::table('chatbot_replies')->insert($chunk);
        foreach (array_chunk($tags, 500) as $chunk) DB::table('chatbot_reply_tags')->insert($chunk);
        unset($rows, $tags);

        $f = ChatbotResults::filters(['from' => $day, 'to' => $day]);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $t = microtime(true);
        $log = $this->logOf(fn () => (new ResultsPieces())->build($day, ResultsPieces::base($f)));
        $seconds = microtime(true) - $t;
        $peak = (memory_get_peak_usage() - $before) / 1048576;
        $size = strlen((string) Cache::get(ResultsPieces::PREFIX . $day . ':' . md5(json_encode(ResultsPieces::base($f)))));
        fwrite(STDERR, sprintf("\n[278-2] large day: %d sends, %d queries, peak +%.1f MB, %.2f s, stored %.1f KB\n", 30000, count($log), $peak, $seconds, $size / 1024));

        $this->assertCount(3, $log);
        $this->assertLessThan(64, $peak);
        $r = $this->build(['from' => $day, 'to' => $day]);
        $this->assertSame(30000, $r['funnel']['sent']);
        $this->assertSame(12000, $r['funnel']['customers_sent']);
        $this->assertSame(10000, $r['funnel']['replied']);
        $this->assertSame($this->oldBuild(['from' => $day, 'to' => $day]), $r);
    }

    public function test_S_278_2c_a_stale_or_missing_piece_is_rebuilt_and_the_others_are_kept(): void
    {
        $results = app(ChatbotResults::class);
        $pieces = new ResultsPieces();
        $default = ChatbotResults::request([]); // 2026-10-15 .. 2026-10-21
        $this->assertSame([], $results->warm()['failed']);
        $base = ResultsPieces::base(ChatbotResults::filters($default));
        $built = fn (string $day) => $pieces->get($day, $base)['built_at'] ?? null;
        $first = ['2026-10-21' => $built('2026-10-21'), '2026-10-16' => $built('2026-10-16'), '2026-10-17' => $built('2026-10-17'), '2026-10-20' => $built('2026-10-20')];
        $this->assertNotNull($first['2026-10-16']);
        $sent = $results->stored($default)['out']['funnel']['sent'];

        // Bagong padala ngayon, at nawala ang bahagi ng 10-16 (hal. LRU ng cache).
        DB::table('chatbot_replies')->insert(['page_id' => 1001, 'psid' => 77, 'flow_id' => 1, 'kind' => 'send', 'content' => '[]', 'status' => 'sent',
            'sent_at' => '2026-10-21 01:00:00', 'via' => 'botcake']);
        Cache::forget(ResultsPieces::PREFIX . '2026-10-16:' . md5(json_encode($base)));
        $this->travel(16)->minutes();

        $r = $results->warm();
        $this->assertSame(['default'], array_column($r['built'], 0));
        $this->assertSame(2, $r['pieces'], 'today (stale) and 2026-10-16 (missing) only');
        $this->assertSame(now()->toIso8601String(), $built('2026-10-21'));
        $this->assertSame(now()->toIso8601String(), $built('2026-10-16'));
        $this->assertNotSame($first['2026-10-21'], $built('2026-10-21'));
        // Ang luma (10-17) at kahapon (wala pang 60 minuto) ay hindi binasa ulit.
        $this->assertSame([$first['2026-10-17'], $first['2026-10-20']], [$built('2026-10-17'), $built('2026-10-20')]);
        $out = $results->stored($default)['out'];
        $this->assertSame($sent + 1, $out['funnel']['sent']);
        $this->assertSame($this->oldBuild($default), array_diff_key($out, ['compare' => true]));
    }

    public function test_S_278_2d_a_range_fills_in_progressively_within_the_time_cap_and_the_page_shows_building_until_then(): void
    {
        $this->actingAs($this->user('CEO', 'ceo278d@example.test'));
        $results = app(ChatbotResults::class);
        // Kapag 0 ang oras ng takbo: isang bahagi lang kada takbo (laging kahit isa).
        for ($run = 1; $run <= 6; $run++) {
            $r = $results->warm(0);
            $this->assertSame([1, ['default'], []], [$r['pieces'], $r['pending'], $r['built']], 'run ' . $run);
            $this->assertNull($results->stored([]));
        }
        $this->get('/chatbot/results')->assertOk()->assertSee('Binubuo pa ang report');
        $r = $results->warm(0);
        $this->assertSame([1, [], ['default']], [$r['pieces'], $r['pending'], array_column($r['built'], 0)]);
        $this->get('/chatbot/results')->assertOk()->assertDontSee('Binubuo pa ang report')->assertSee('id="h-funnel"', false);
        $this->assertSame([], $results->pending([]));

        // Ang wanted na range (3 araw, may flow): ang mga bahagi nito ay kasunod; nasa wanted pa habang kulang.
        $q = ['from' => '2026-10-15', 'to' => '2026-10-17', 'flow_id' => '1'];
        $results->want($q);
        $this->assertCount(3, $results->pending($q), 'the page-only pieces already exist; the flow pieces do not');
        $results->warm(0);
        $this->assertCount(2, $results->pending($q));
        $this->assertCount(1, $results->wanted());
        $results->warm(0);
        $results->warm(0);
        $this->assertNotNull($results->stored($q));
        $this->assertSame([], $results->wanted());
        $this->assertSame($this->oldBuild($q), array_diff_key($results->stored($q)['out'], ['compare' => true]));
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
        $this->assertMatchesRegularExpression('/^chatbot:results-warm built default \(\d+\.\d s\); 7 day pieces in \d+\.\d s$/', trim(Artisan::output()));
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
        $this->assertSame(json_decode(json_encode($this->oldBuild(self::RANGE)['funnel']), true), json_decode(Artisan::output(), true)['funnel']);
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
