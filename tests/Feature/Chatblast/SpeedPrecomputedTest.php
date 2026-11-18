<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\ProblemsPanel;
use App\Services\Chatbot\ResponseSpeed;
use App\Services\PageSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Chatblast\Support\BaseResponseSpeed;

/**
 * Spec 306, /chatbot/speed: ang page ay nagbabasa lang ng nabuo ng chatbot:speed-warm (buildDay: bawat filter ng isang araw
 * sa isang basa), ang numero ay ang sa dating kuwenta kada filter (BaseResponseSpeed, ang code ng base 528326cc), ang araw
 * na wala pa ay "computing", at ang mabagal na basa ay "too slow" agad. Ngayon ay 2026-10-21 12:30. Tatlong bot (Botcake,
 * Forbeast, Incepxionbot), anim na page: 1001 Forbeast, 1002 Botcake, 1003 walang flow, 1004 wala sa listahan ng page pero
 * may chat, 1005 chatblast lang, 1006 Incepxionbot. Tatlong araw ng chat, sagot, pumalyang padala, AI, sequence step at
 * chatblast.
 */
class SpeedPrecomputedTest extends ChatblastTestCase
{
    use LoadsSpeedSequences;

    private const PAGES = ['1001' => 'Page Uno', '1002' => 'Page Dos', '1003' => 'Page Tres', '1005' => 'Page Cinco', '1006' => 'Page Seis'];
    private const BIG = ['chatbot_events', 'botcake_chats', 'chatbot_replies', 'chatbot_ai_requests', 'botcake_messages', 'chatbot_sequences'];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
            'database/migrations/2026_10_30_098000_add_raw_to_chatbot_replies.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
            'database/migrations/2026_11_06_100000_add_chat_index_to_chatbot_replies.php',
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
            'database/migrations/2026_11_11_100000_add_real_send_to_chatbot_replies.php',
        ]]);
        config(['services.gateway.url' => 'https://gateway.example.test', 'services.incepxionbot.url' => 'https://incepxionbot.example.test']);
        $this->travelTo('2026-10-21 12:30:00');
        foreach (self::PAGES as $id => $name) $this->page($name, (string) $id);
        foreach (['Page Uno', 'Page Dos', 'Page Seis'] as $p) DB::table('page_settings')->insert(['page_name' => $p, 'provider' => 'chatbot', 'key' => 'flow_id', 'value' => '1', 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00']);
        // Ang aktibong gateway ng Page Seis (isinusulat ng system, kaya diretso sa table).
        DB::table('page_settings')->insert(['page_name' => 'Page Seis', 'provider' => 'chatbot', 'key' => 'gateway', 'value' => 'incepxionbot', 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00']);
        $this->seed306();
    }

    private function seed306(): void
    {
        $at = fn (int $t) => date('Y-m-d H:i:s', $t);
        $seqs = [DB::table('chatbot_sequences')->insertGetId(['name' => 'Follow up', 'active' => true, 'exit_on_reply' => true]),
            DB::table('chatbot_sequences')->insertGetId(['name' => 'Promo', 'active' => true, 'exit_on_reply' => true])];
        $via = ['1001' => 'forbeast', '1002' => 'botcake', '1003' => 'botcake', '1004' => 'forbeast', '1006' => 'incepxionbot'];
        foreach ([19, 20, 21] as $d) {
            $day = strtotime("2026-10-$d 00:00:00");
            $hours = $d === 21 ? 13 : 24;
            for ($i = 0; $i < 90; $i++) {
                $page = ['1001', '1002', '1003', '1004', '1006'][$i % 5];
                $psid = 7000 + $d * 1000 + $i % 40; // ang parehong customer ay bumabalik sa araw
                $t = $day + ($i * 7 % $hours) * 3600 + ($i * 131) % 3500;
                if ($d === 21 && $t > strtotime('2026-10-21 12:29:00')) $t = strtotime('2026-10-21 12:29:00') - $i;
                $payload = ($via[$page] !== 'botcake' ? ['source' => 'gateway'] : []) + ($i === 13 ? ['_ignored' => true] : []) + ($i === 16 ? ['_standby' => true] : []) + ($i === 17 ? ['_ad_open' => true] : []);
                $chat = DB::table('botcake_chats')->insertGetId(['page_id' => $page, 'page_name' => 'x', 'psid' => (string) $psid, 'received_at' => $at($t), 'payload' => json_encode($payload)]);
                if ($i === 21) DB::table('botcake_chats')->insert(['page_id' => $page, 'page_name' => 'x', 'psid' => (string) $psid, 'received_at' => $at($t), 'payload' => json_encode($payload)]);
                if ($i % 9 === 0) continue; // walang sagot
                $sent = $t + ($i * 37) % 400 + 1;
                // Pumalyang padala bago ang sagot (Sent late), o walang sagot pagkatapos (Never sent).
                if ($i % 6 === 2) DB::table('chatbot_replies')->insert(['page_id' => (int) $page, 'psid' => $psid, 'node_id' => 'n2', 'kind' => 'send', 'status' => 'failed',
                    'sent_at' => $at($sent - 1), 'via' => $via[$page], 'error' => 'Could not send (#551) subcode 1545041']);
                if ($i % 12 === 8) continue;
                $confirmed = $via[$page] === 'botcake' || $i % 4 !== 0;
                DB::table('chatbot_replies')->insert(['page_id' => (int) $page, 'psid' => $psid, 'node_id' => 'n2', 'kind' => 'send', 'status' => $i % 11 === 0 ? 'partial' : 'sent',
                    'sent_at' => $at($sent), 'via' => $via[$page], 'chat_id' => $chat,
                    'confirmed_at' => $confirmed ? $at($sent + ($via[$page] === 'botcake' ? 0 : $i % 5)) : null, 'confirmed_status' => $confirmed ? 'sent' : null]);
                if ($i % 5 === 1) {
                    DB::table('chatbot_ai_requests')->insert(['request_id' => uniqid('r', true), 'page_id' => (int) $page, 'psid' => $psid, 'chat_id' => $chat, 'flow_id' => 1,
                        'node_id' => 'n3', 'page_name' => 'x', 'model' => 'm', 'messages' => '[]', 'max_tokens' => 10, 'context' => '{}',
                        'status' => 'answered', 'attempts' => 1, 'deadline_at' => $at($t), 'answered_at' => $at($t + 3 + $i % 4), 'created_at' => $at($t + 1), 'updated_at' => $at($t + 1)]);
                }
            }
            // Sequence steps (pati ang sa binurang sequence 999, na hindi binibilang) at manual na sagot.
            for ($j = 0; $j < 60; $j++) {
                $page = ['1001', '1002', '1004', '1006'][$j % 4];
                $sent = min($day + ($j * 5 % $hours) * 3600 + $j * 41, $d === 21 ? strtotime('2026-10-21 12:29:00') : PHP_INT_MAX);
                DB::table('chatbot_replies')->insert(['page_id' => (int) $page, 'psid' => 9000 + $j, 'node_id' => 's1', 'kind' => 'send', 'status' => $j % 7 ? 'sent' : 'failed',
                    'sent_at' => $at($sent), 'via' => $via[$page], 'sequence_id' => $j % 10 === 9 ? 999 : $seqs[$j % 2], 'sequence_step' => 1 + $j % 3]);
                if ($j % 8 === 0) DB::table('chatbot_replies')->insert(['page_id' => (int) $page, 'psid' => 9000 + $j, 'node_id' => 'm', 'kind' => 'manual', 'status' => 'sent', 'sent_at' => $at($sent)]);
            }
            // Chatblast: ng Page Uno, ng Page Cinco (wala nang ibang data), walang pangalan, ang test at ang pumalya ay hindi.
            foreach ([['Page Uno', 'promo', 'sent', false], ['Page Cinco', 'reminder', 'sent', false], ['', 'promo', 'sent', false], ['Page Uno', 'promo', 'sent', true], ['Page Dos', 'reminder', 'failed', false], ['Page Dos', 'follow_up', 'sent', false]] as $k => [$name, $kind, $status, $test]) {
                for ($n = 0; $n < 3; $n++) DB::table('botcake_messages')->insert(['page_name' => $name, 'waybill' => "WB$d$k$n", 'kind' => $kind, 'status' => $status, 'is_test' => $test,
                    'sent_at' => $at($day + (($k + $n * 4) % $hours) * 3600 + 60), 'created_at' => $at($day), 'updated_at' => $at($day)]);
            }
        }
    }

    private function ceo(): void
    {
        $this->actingAs($this->user('CEO', 'ceo-306@example.test'));
    }

    /** Ang mga query sa malalaking table habang tumatakbo ang $run: [table => bilang]. */
    private function bigReads(callable $run): array
    {
        $seen = [];
        DB::listen(function ($q) use (&$seen): void {
            foreach (self::BIG as $t) if (preg_match('/\bfrom\s+["`]?' . $t . '["`]?/i', $q->sql)) $seen[$t] = ($seen[$t] ?? 0) + 1;
        });
        $run();

        return $seen;
    }

    /** Bawat filter na inihahambing: lahat, bawat page (pati ang wala sa listahan at ang walang data), bawat bot, page + bot. */
    private static function filterSets(): array
    {
        $out = [];
        foreach (['2026-10-21', '2026-10-20', '2026-10-19'] as $date) {
            foreach ([null, '1001', '1002', '1003', '1004', '1005', '1006', '987654'] as $page) {
                foreach ([null, 'Botcake', 'Forbeast', 'Incepxionbot'] as $bot) $out[] = array_filter(['date' => $date, 'page_id' => $page, 'bot' => $bot], fn ($v) => $v !== null);
            }
        }

        return $out;
    }

    public function test_SP_2_every_filter_gives_exactly_the_numbers_charts_and_not_sent_list_of_the_old_code(): void
    {
        $this->assertSame(['Botcake', 'Forbeast', 'Incepxionbot'], \App\Services\Chatbot\ChatbotConnections::bots());
        $s = app(ResponseSpeed::class);
        foreach (['2026-10-21', '2026-10-20', '2026-10-19', '2026-10-18'] as $d) $s->buildDay($d);
        // Ang ikalawang pagbuo ng ngayon: ang saradong oras ng sequence steps ay mula sa naitabi.
        $s->buildDay('2026-10-21');
        $old = new BaseResponseSpeed();
        $checked = 0;
        foreach (self::filterSets() as $q) {
            $label = json_encode($q);
            $want = $old->output($q);
            $this->assertSame($want, $s->output($q), "$label: page numbers and charts");
            foreach ([null, '10', '23'] as $hour) {
                $hq = $q + ($hour === null ? [] : ['hour' => $hour]);
                $this->assertSame($old->notSentOutput($hq), $s->notSentOutput($hq), "$label hour $hour: Not sent list");
            }
            $checked++;
        }
        $this->assertSame(96, $checked);
        // Ang fixture ay may laman sa bawat bahagi (hindi puro sero ang paghahambing).
        $all = $s->output([]);
        $this->assertGreaterThan(0, $all['total']['not_sent']['never']);
        $this->assertGreaterThan(0, $all['total']['not_sent']['late']);
        $this->assertGreaterThan(0, $all['total']['with_ai']['n']);
        $this->assertGreaterThan(0, $all['by_bot']['Incepxionbot']['messages']);
        $this->assertGreaterThan(0, $all['volume']['total']['sequences']);
        $this->assertSame(['follow_up', 'promo', 'reminder'], $all['volume']['kinds']);
        $this->assertGreaterThan(0, $s->output(['page_id' => '1005'])['volume']['total']['chatblast']['reminder']);
        // Ang page na wala sa listahan ng page ay walang flow: wala sa bot, gaya ng dati.
        $this->assertGreaterThan(0, $s->output(['page_id' => '1004'])['total']['not_on_bot']);
        $this->assertGreaterThan(0, $s->notSentOutput(['page_id' => '1001'])['total']);
    }

    public function test_SP_2_the_page_and_its_lists_read_no_chat_or_reply_table_and_answer_at_once(): void
    {
        $this->ceo();
        $s = app(ResponseSpeed::class);
        foreach (['2026-10-21', '2026-10-20', '2026-10-19'] as $d) $s->buildDay($d);
        app(ProblemsPanel::class)->panel(); // ang banner ng layout (sariling cache, sa lahat ng chatbot page)
        foreach (['', 'page_id=1001', 'bot=Botcake', 'page_id=1006&bot=Incepxionbot', 'date=2026-10-20&page_id=1002', 'page_id=987654'] as $q) {
            $res = null;
            $t = microtime(true);
            $reads = $this->bigReads(function () use (&$res, $q): void {
                $res = $this->get('/chatbot/speed' . ($q !== '' ? '?' . $q : ''))->assertOk();
                $this->getJson('/chatbot/speed/not-sent' . ($q !== '' ? '?' . $q : ''))->assertOk()->assertJsonMissingPath('computing');
            });
            $this->assertSame([], $reads, "$q: no big table");
            $this->assertLessThan(2.0, microtime(true) - $t, "$q: under 2 s");
            $html = $res->getContent();
            $this->assertStringContainsString('data-sp-asof="page">Numbers as of 12:30</p>', $html, $q);
            $this->assertStringContainsString('id="sp-data"', $html, $q);
            $this->assertStringNotContainsString('data-sp-state=', $html, $q);
        }
    }

    public function test_SP_2_a_day_not_built_yet_shows_computing_asks_the_warm_and_appears_after_its_run(): void
    {
        $this->ceo();
        Cache::flush();
        app(ProblemsPanel::class)->panel(); // ang banner ng layout (sariling cache, sa lahat ng chatbot page)
        $reads = $this->bigReads(function () use (&$res): void {
            $res = $this->get('/chatbot/speed?date=2026-10-15&page_id=1001')->assertOk();
            $this->getJson('/chatbot/speed/not-sent?date=2026-10-15&page_id=1001')->assertOk()->assertJsonPath('computing', true)
                ->assertJsonPath('html', fn ($h) => str_contains($h, 'data-sp-notsent-state="computing"'));
        });
        $this->assertSame([], $reads, 'nothing computed in the request');
        $html = $res->getContent();
        $this->assertStringContainsString('data-sp-state="computing">Computing the numbers for 2026-10-15. This page refreshes in a minute.</p>', $html);
        $this->assertStringContainsString('setTimeout(() => location.reload(), 60000)', $html);
        $this->assertStringNotContainsString('id="sp-data"', $html);
        $this->assertStringNotContainsString('data-sp-seq=', $html);
        // Ang navigation at mga filter ay nandoon pa rin.
        $this->assertStringContainsString('aria-label="Previous day">← 2026-10-14</a>', $html);
        $this->assertSame(['2026-10-15', '2026-10-14'], array_keys(app(ResponseSpeed::class)->wanted()));

        // Ang takbo ng warm: ang mga araw ng default (ngayon, kahapon, bago kahapon) at ang dalawang hiniling.
        $this->assertSame(0, Artisan::call('chatbot:speed-warm'));
        $this->assertMatchesRegularExpression('#warmed 2026-10-20 \(\d+\.\d s\), 2026-10-21 \(\d+\.\d s\), 2026-10-19 \(\d+\.\d s\), 2026-10-15 \(\d+\.\d s\), 2026-10-14 \(\d+\.\d s\), #', Artisan::output());
        $this->assertSame([], app(ResponseSpeed::class)->wanted());
        $res = $this->get('/chatbot/speed?date=2026-10-15&page_id=1001')->assertOk();
        $res->assertViewMissing('computing');
        $this->assertSame(0, $res->viewData('total')['messages']);
        $this->assertStringContainsString('Numbers as of 12:30', $res->getContent());
    }

    public function test_SP_2_the_warm_builds_at_most_two_asked_days_a_run_oldest_ask_first_and_drops_stale_asks(): void
    {
        $this->ceo();
        Cache::flush();
        Artisan::call('chatbot:speed-warm');
        foreach (['2026-10-10', '2026-10-12'] as $d) {
            $this->get('/chatbot/speed?date=' . $d)->assertOk()->assertViewHas('computing', true);
            $this->travel(1)->seconds();
        }
        $this->assertSame(['2026-10-10', '2026-10-09', '2026-10-12', '2026-10-11'], array_keys(app(ResponseSpeed::class)->wanted()));
        Artisan::call('chatbot:speed-warm');
        $this->assertSame(['2026-10-12', '2026-10-11'], array_keys(app(ResponseSpeed::class)->wanted()));
        $this->get('/chatbot/speed?date=2026-10-10')->assertOk()->assertViewMissing('computing');
        // Ang hiling na hindi nabuo sa loob ng 30 minuto ay tinatanggal (idadagdag ulit ng page kapag may tumitingin pa).
        $this->travel(ResponseSpeed::WANTED_MAX_AGE + 1)->seconds();
        $reads = $this->bigReads(fn () => Artisan::call('chatbot:speed-warm'));
        $this->assertSame([], app(ResponseSpeed::class)->wanted());
        $this->assertNull(Cache::get(ResponseSpeed::DAY_KEY . '2026-10-12'));
        $this->assertArrayHasKey('botcake_chats', $reads, 'today still built');
    }

    public function test_SP_2_a_closed_sequence_hour_is_counted_once_and_kept(): void
    {
        $s = app(ResponseSpeed::class);
        $count = function (callable $run): int {
            $n = 0;
            DB::listen(function ($q) use (&$n): void {
                if (preg_match('/"sequence_id" in \(/', $q->sql)) $n++;
            });
            $run();

            return $n;
        };
        // 12:30: 00:00–11:59 ay sarado nang higit 10 minuto (12 basa), ang 12:00 hanggang hatinggabi ay isang basa.
        $this->assertSame(13, $count(fn () => $s->buildDay('2026-10-21')));
        $this->assertSame(1, $count(fn () => $s->buildDay('2026-10-21')));
        $this->assertSame(24, $count(fn () => $s->buildDay('2026-10-20')));
        $this->assertSame(0, $count(fn () => $s->buildDay('2026-10-20')));
        // 13:05: ang 12:00 ay hindi pa sarado nang 10 minuto (binabasa pa); 13:11: naitabi na.
        $this->travelTo('2026-10-21 13:05:00');
        $this->assertSame(2, $count(fn () => $s->buildDay('2026-10-21')));
        $this->travelTo('2026-10-21 13:11:00');
        $this->assertSame(2, $count(fn () => $s->buildDay('2026-10-21')));
        $this->assertSame(1, $count(fn () => $s->buildDay('2026-10-21')));
        // Ang bagong step sa kasalukuyang oras ay nabibilang sa susunod na takbo.
        $before = $s->stored(ResponseSpeed::filters(['page_id' => '1002']))['volume']['total']['sequences'];
        DB::table('chatbot_replies')->insert(['page_id' => 1002, 'psid' => 9999, 'node_id' => 's1', 'kind' => 'send', 'status' => 'sent', 'sent_at' => '2026-10-21 13:10:30',
            'via' => 'botcake', 'sequence_id' => DB::table('chatbot_sequences')->min('id'), 'sequence_step' => 1]);
        $s->buildDay('2026-10-21');
        $this->assertSame($before + 1, $s->stored(ResponseSpeed::filters(['page_id' => '1002']))['volume']['total']['sequences']);
    }

    public function test_SP_2_the_cache_entries_are_compressed_and_a_broken_one_reads_as_computing(): void
    {
        $s = app(ResponseSpeed::class);
        foreach (['2026-10-21', '2026-10-20'] as $d) $s->buildDay($d);
        $raw = Cache::get(ResponseSpeed::PAGE_KEY . '2026-10-21:1001');
        $this->assertIsString($raw);
        $this->assertSame(1, preg_match('#^[A-Za-z0-9+/=]+$#', $raw), 'base64 text, safe for a text column');
        $this->assertIsString(Cache::get(ResponseSpeed::NOT_SENT_KEY . '2026-10-21:1001'));
        $day = Cache::get(ResponseSpeed::DAY_KEY . '2026-10-21');
        $this->assertSame(['1001', '1002', '1003', '1005', '1006', '1004'], array_values(array_diff(array_map('strval', array_keys($day['pages'])), [''])));
        Cache::put(ResponseSpeed::PAGE_KEY . '2026-10-21:1001', 'not-a-copy', 60);
        $this->assertTrue($s->output(['page_id' => '1001'])['computing']);
        $this->assertArrayNotHasKey('computing', $s->output(['page_id' => '1002']));
    }

    /** Ang QueryException ng MySQL na pinutol ng max_execution_time (3024), o ibang code. */
    private static function mysqlError(int $code): QueryException
    {
        $pdo = new \PDOException('SQLSTATE[HY000]: General error: ' . $code . ' Query execution was interrupted, maximum statement execution time exceeded');
        $pdo->errorInfo = ['HY000', $code, 'Query execution was interrupted, maximum statement execution time exceeded'];

        return new QueryException('mysql', 'select 1', [], $pdo);
    }

    public function test_SP_3_a_timed_out_read_answers_at_once_with_the_too_slow_state(): void
    {
        $e = self::mysqlError(3024);
        $this->assertTrue(ResponseSpeed::isTimeout($e));
        $this->assertTrue(ResponseSpeed::isTimeout(self::mysqlError(1317)));
        $this->assertFalse(ResponseSpeed::isTimeout(self::mysqlError(1064)));
        $this->app->instance(ResponseSpeed::class, new class ($e) extends ResponseSpeed {
            public function __construct(private \Throwable $e)
            {
            }

            public function output(array $q): array
            {
                throw $this->e;
            }

            public function notSentOutput(array $q): array
            {
                throw $this->e;
            }

            public function sequencesOutput(array $q, bool $chart = false, bool $fromWarm = false): array
            {
                throw $this->e;
            }
        });
        $this->ceo();
        $logged = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($m) use (&$logged): void {
            if ($m->message === 'Chatbot speed too slow') $logged[] = [$m->level, $m->context['part']];
        });
        $t = microtime(true);
        $html = $this->get('/chatbot/speed?page_id=1001')->assertOk()->getContent();
        $this->assertStringContainsString('data-sp-state="slow">The numbers are taking too long to load. This page tries again in a minute.</p>', $html);
        $this->assertStringContainsString('setTimeout(() => location.reload(), 60000)', $html);
        $this->getJson('/chatbot/speed/not-sent?page_id=1001')->assertOk()->assertJsonPath('slow', true)
            ->assertJsonPath('html', fn ($h) => str_contains($h, 'The numbers are taking too long to load.'));
        $this->getJson('/chatbot/speed/sequences?page_id=1001')->assertOk()->assertJsonPath('slow', true)
            ->assertJsonPath('html', fn ($h) => str_contains($h, 'data-sp-seq-state="slow"'));
        $this->assertLessThan(2.0, microtime(true) - $t);
        $this->assertSame([['warning', 'page'], ['warning', 'not-sent'], ['warning', 'sequences']], $logged);
        // Ang page script ay hindi nagtatanong ng chart kapag mabagal ang section; subukan ulit pagkalipas ng isang minuto.
        $this->assertStringContainsString('if (s.slow) { setTimeout(load, 60000); return; }', (string) file_get_contents(resource_path('views/chatbot/speed.blade.php')));
    }

    public function test_SP_3_other_database_errors_are_not_hidden_as_too_slow(): void
    {
        $e = self::mysqlError(1064);
        $this->app->instance(ResponseSpeed::class, new class ($e) extends ResponseSpeed {
            public function __construct(private \Throwable $e)
            {
            }

            public function output(array $q): array
            {
                throw $this->e;
            }
        });
        $this->ceo();
        $this->withoutExceptionHandling();
        $this->expectException(QueryException::class);
        $this->get('/chatbot/speed');
    }

    public function test_SP_3_the_budget_wraps_the_reads_and_is_put_back_after_an_error(): void
    {
        // Sa SQLite ay walang session limit (MySQL lang); ang pagbalik ay sa finally, kahit pumalya ang laman.
        $this->assertSame(5, ResponseSpeed::PAGE_SECONDS);
        $this->assertSame(7, ResponseSpeed::withBudget(1, fn () => 7));
        $seen = [];
        DB::listen(function ($q) use (&$seen): void {
            if (str_contains($q->sql, 'max_execution_time')) $seen[] = $q->sql;
        });
        try {
            ResponseSpeed::withBudget(1, fn () => throw new \RuntimeException('boom'));
            $this->fail('the error is not swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertSame([], $seen, 'no MySQL statement on SQLite');
    }
}
