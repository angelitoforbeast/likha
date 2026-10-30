<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\ResponseSpeed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Spec 201, /chatbot/speed: ang page ay hindi na naghihintay sa Sequences (sariling JSON endpoint, sariling cache), at
 * ang default na view ng ngayon at kahapon ay pinapainit ng scheduler. Ngayon ay 2026-10-21 12:30. Tatlong araw ng data
 * (19, 20, 21): mga chat na may sagot (Forbeast na may oras ng Meta, Botcake), AI, mga padala, at mga sequence step na
 * may kasaysayan (subscribe, naunang takbo dalawang araw bago), skipped, failed at isang step na due bago maghatinggabi
 * pero tumakbo pagkatapos.
 */
class SpeedPrewarmTest extends ChatblastTestCase
{
    protected const SNAPSHOT = __DIR__ . '/fixtures/speed-201-before.json';

    /** Ang mga filter na inihahambing sa lumang code: lahat, page, bot, lumang araw, lumang araw + page, dalawang araw bago + bot. */
    protected const FILTER_SETS = [
        'all' => [],
        'page' => ['page_id' => '1001'],
        'bot' => ['bot' => 'Forbeast'],
        'past' => ['date' => '2026-10-20'],
        'past_page' => ['date' => '2026-10-20', 'page_id' => '1002'],
        'two_back_bot' => ['date' => '2026-10-19', 'bot' => 'Botcake'],
    ];

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
        $this->travelTo('2026-10-21 12:30:00');
        foreach (['Page Uno' => '1001', 'Page Dos' => '1002', 'Page Tres' => '1003'] as $name => $id) $this->page($name, $id);
        foreach (['Page Uno', 'Page Dos'] as $p) DB::table('page_settings')->insert(['page_name' => $p, 'provider' => 'chatbot', 'key' => 'flow_id', 'value' => '1', 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00']);
        // Page Uno ay nasa Forbeast (ang Bot filter ng mga sequence ay ayon sa bot ng page).
        DB::table('page_settings')->insert(['page_name' => 'Page Uno', 'provider' => 'chatbot', 'key' => 'provider', 'value' => 'gateway', 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00']);
        $this->seedDays();
    }

    protected function seedDays(): void
    {
        $seq = DB::table('chatbot_sequences')->insertGetId(['name' => 'Follow up', 'active' => true, 'exit_on_reply' => true]);
        DB::table('chatbot_sequence_steps')->insert(['sequence_id' => $seq, 'position' => 1, 'delay_amount' => 60, 'delay_unit' => 'minutes', 'active' => true]);
        DB::table('chatbot_sequence_steps')->insert(['sequence_id' => $seq, 'position' => 2, 'delay_amount' => 2, 'delay_unit' => 'hours', 'active' => true]);
        $at = fn (int $t) => date('Y-m-d H:i:s', $t);
        foreach ([19, 20, 21] as $d) {
            $day = strtotime("2026-10-$d 00:00:00");
            $lastHour = $d === 21 ? 12 : 23;
            for ($i = 0; $i < 30; $i++) {
                $page = [1001, 1002, 1003][$i % 3];
                $psid = 7000 + $d * 100 + $i;
                $t = $day + (($i * 5) % ($lastHour + 1)) * 3600 + ($i * 97) % 3000;
                $payload = ($page === 1001 ? ['source' => 'gateway'] : []) + ($i === 13 ? ['_ignored' => true] : []) + ($i === 16 ? ['_standby' => true] : []);
                $chat = DB::table('botcake_chats')->insertGetId(['page_id' => (string) $page, 'page_name' => 'x', 'psid' => (string) $psid, 'received_at' => $at($t), 'payload' => json_encode($payload)]);
                if ($i % 7 === 0) continue; // walang sagot
                $sent = $t + ($i * 17) % 200 + 1;
                $via = $page === 1001 ? 'forbeast' : 'botcake';
                $confirmed = $via === 'botcake' || $i % 4 !== 0;
                DB::table('chatbot_replies')->insert(['page_id' => $page, 'psid' => $psid, 'node_id' => 'n2', 'kind' => 'send', 'status' => $i % 11 === 0 ? 'partial' : 'sent',
                    'sent_at' => $at($sent), 'via' => $via, 'chat_id' => $i % 5 === 1 ? $chat : null,
                    'confirmed_at' => $confirmed ? $at($sent + ($via === 'forbeast' ? $i % 5 : 0)) : null, 'confirmed_status' => $confirmed ? 'sent' : null]);
                if ($i % 5 === 1) {
                    DB::table('chatbot_ai_requests')->insert(['request_id' => uniqid('r', true), 'page_id' => $page, 'psid' => $psid, 'chat_id' => $chat, 'flow_id' => 1,
                        'node_id' => 'n3', 'page_name' => 'x', 'model' => 'm', 'messages' => '[]', 'max_tokens' => 10, 'context' => '{}',
                        'status' => 'answered', 'attempts' => 1, 'deadline_at' => $at($t), 'answered_at' => $at($t + 3 + $i % 4), 'created_at' => $at($t + 1), 'updated_at' => $at($t + 1)]);
                }
            }
            for ($j = 0; $j < 12; $j++) {
                $page = $j % 2 ? 1002 : 1001;
                $psid = 8000 + $d * 100 + $j;
                $sub = $day + 8 * 3600 + $j * 1200 - ($d === 21 ? 7200 : 0);
                $event = fn (int $t, array $detail) => DB::table('chatbot_events')->insert(['page_id' => $page, 'psid' => $psid, 'type' => 'sequence', 'created_at' => $at($t),
                    'detail' => json_encode($detail + ['name' => 'Follow up'])]);
                if ($j % 3 === 0) $event($sub - 2 * 86400, ['op' => 'subscribed']);
                $event($sub, ['op' => 'subscribed']);
                $prev = $sub;
                foreach ([1 => 3600, 2 => 7200] as $step => $delay) {
                    if ($step === 2 && $j % 2) break;
                    $due = $prev + $delay;
                    $ran = $due + $j * 7 + $step * 11;
                    $op = $j % 6 === 4 ? 'skipped' : ($j % 6 === 5 && $step === 1 ? 'failed' : 'ran');
                    $event($ran, ['op' => $op, 'step' => $step, 'sequence_id' => $seq, 'due' => $at($due + ($j % 4) * 60)]);
                    if ($op === 'ran' && $j !== 8) {
                        $pushed = $ran - 2;
                        DB::table('chatbot_replies')->insert(['page_id' => $page, 'psid' => $psid, 'node_id' => 'n1', 'kind' => 'send', 'status' => 'sent', 'sent_at' => $at($pushed),
                            'sequence_id' => $seq, 'sequence_step' => $step, 'via' => $page === 1001 ? 'forbeast' : 'botcake',
                            'confirmed_at' => $page === 1001 && $j % 5 ? $at($pushed + $j * 3) : null, 'confirmed_status' => $page === 1001 && $j % 5 ? 'sent' : null]);
                    }
                    $prev = $ran;
                }
            }
        }
        // Due bago maghatinggabi ng 2026-10-20, tumakbo 00:10 ng 2026-10-21: sa araw pa rin ng due.
        DB::table('chatbot_events')->insert(['page_id' => 1001, 'psid' => 8999, 'type' => 'sequence', 'created_at' => '2026-10-21 00:10:00',
            'detail' => json_encode(['op' => 'ran', 'name' => 'Follow up', 'step' => 1, 'sequence_id' => $seq, 'due' => '2026-10-20 23:50:00'])]);
        DB::table('chatbot_replies')->insert(['page_id' => 1001, 'psid' => 8999, 'node_id' => 'n1', 'kind' => 'send', 'status' => 'sent', 'sent_at' => '2026-10-21 00:09:58',
            'sequence_id' => $seq, 'sequence_step' => 1, 'via' => 'forbeast', 'confirmed_at' => '2026-10-21 00:10:20', 'confirmed_status' => 'sent']);
    }

    /** Ang data na naka-JSON (para pareho ang paghahambing sa fixture: ang float ay float, ang key ay string). */
    protected static function plain(array $v): array
    {
        return json_decode(json_encode($v), true);
    }

    private function ceo(): void
    {
        $this->actingAs($this->user('CEO', 'ceo-201@example.test'));
    }

    /** Ang mga query sa malalaking table habang tumatakbo ang $run: [table => mga SQL]. */
    protected function bigReads(callable $run): array
    {
        $seen = [];
        DB::listen(function ($q) use (&$seen): void {
            foreach (['chatbot_events', 'botcake_chats', 'chatbot_replies', 'chatbot_ai_requests'] as $t) {
                if (preg_match('/\bfrom\s+["`]?' . $t . '["`]?/i', $q->sql)) $seen[$t][] = $q->bindings;
            }
        });
        $run();

        return $seen;
    }

    public function test_S_201_2_the_page_does_not_wait_for_sequences_and_loads_them_after(): void
    {
        $this->ceo();
        Cache::flush();
        $res = null;
        $reads = $this->bigReads(function () use (&$res): void {
            $res = $this->get('/chatbot/speed?page_id=1001&bot=Forbeast&date=2026-10-20')->assertOk();
        });
        $html = $res->getContent();

        // Walang basa ng mga sequence event; ang ibang section ay buo.
        $this->assertArrayNotHasKey('chatbot_events', $reads);
        $this->assertArrayHasKey('botcake_chats', $reads);
        foreach (['hours', 'ai', 'push'] as $t) $this->assertStringContainsString('data-table="' . $t . '"', $html);
        $this->assertStringNotContainsString('data-table="sequences"', $html);
        $this->assertArrayNotHasKey('sequences', $res->viewData('charts'));
        // Ang heading, ang loading line at ang endpoint na may parehong filter; ang script ay may error line at Retry.
        $this->assertMatchesRegularExpression('#<section aria-labelledby="h-seq" data-sp-seq="/chatbot/speed/sequences\?page_id=1001&amp;bot=Forbeast&amp;date=2026-10-20">\s*<h2 id="h-seq"[^>]*>Sequences by hour due</h2>\s*<div data-sp-seq-body>\s*<p[^>]*role="status" data-sp-seq-state="loading">Loading sequences…</p>#', $html);
        $this->assertStringContainsString('role="alert" data-sp-seq-state="error">Sequences did not load.', $html);
        $this->assertStringContainsString('data-sp-seq-retry>Retry</button>', $html);
        $this->assertStringContainsString("addEventListener('click', load)", $html);
        $this->assertStringContainsString("url.searchParams.set('part', 'chart')", $html);

        // Ang endpoint: ang HTML ng section (gaya ng dati) at ang mga numero; ang chart ay hiwalay na tawag.
        $s = $this->getJson('/chatbot/speed/sequences?page_id=1001&bot=Forbeast&date=2026-10-20')->assertOk()->json();
        $this->assertSame(['filters', 'sequences', 'html'], array_keys($s));
        $this->assertSame(['date' => '2026-10-20', 'page_id' => '1001', 'bot' => 'Forbeast'], $s['filters']);
        foreach (['data-sp-summary="sequences"', '<canvas id="sp-chart-seq"', 'data-table="sequences"', 'data-table="sequence-slowest"', 'dotted = 2026-10-19'] as $part) {
            $this->assertStringContainsString($part, $s['html']);
        }
        $this->assertSame(['date', 'prev_date', 'seq'], array_keys($this->getJson('/chatbot/speed/sequences?page_id=1001&bot=Forbeast&date=2026-10-20&part=chart')->assertOk()->json()));
    }

    public function test_S_201_2_the_endpoint_has_the_page_access_and_cleans_its_query(): void
    {
        $this->actingAs($this->user('Data Encoder', 'enc-201@example.test'));
        $this->getJson('/chatbot/speed/sequences')->assertForbidden();
        $this->getJson('/chatbot/speed/sequences?part=chart')->assertForbidden();
        $this->ceo();
        // Gaya ng page: ang maling petsa, page o bot ay ang default (ngayon, lahat), hindi error.
        foreach (['date=2026-13-40&page_id=abc&bot=Nobody', 'date[]=x&page_id[]=1&bot[]=y', 'date=2026-10-22', 'page_id=' . str_repeat('9', 31)] as $q) {
            $this->assertSame(['date' => '2026-10-21', 'page_id' => null, 'bot' => null], $this->getJson('/chatbot/speed/sequences?' . $q)->assertOk()->json('filters'), $q);
            $this->getJson('/chatbot/speed/sequences?part=chart&' . $q)->assertOk()->assertJsonPath('date', '2026-10-21');
        }
        $this->get('/chatbot/speed/sequences?part=other')->assertOk()->assertJsonStructure(['filters', 'sequences', 'html']);
        auth()->logout();
        $this->get('/chatbot/speed/sequences')->assertRedirect();
    }

    public function test_S_201_3_each_sequences_call_computes_at_most_one_day(): void
    {
        $this->ceo();
        Cache::flush();
        // Ang mga sequence event na binasa ng isang tawag: ang mga petsa ng mga oras na binasa (created_at >= ?).
        $days = function (string $url): array {
            $reads = $this->bigReads(fn () => $this->getJson($url)->assertOk());
            $out = [];
            foreach ($reads['chatbot_events'] ?? [] as $b) {
                foreach ($b as $v) if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2} /', $v)) $out[substr($v, 0, 10)] = true;
            }

            return array_keys($out);
        };
        // Ang section: ang araw lang (hanggang 06:00 ng susunod na araw, ang late na step). Ang chart: ang naunang araw lang
        // (ang araw ay naka-cache na). Ang ulit: walang basa.
        $this->assertSame(['2026-10-20', '2026-10-21'], $days('/chatbot/speed/sequences?date=2026-10-20'));
        $this->assertSame(['2026-10-19', '2026-10-20'], $days('/chatbot/speed/sequences?date=2026-10-20&part=chart'));
        $this->assertSame([], $days('/chatbot/speed/sequences?date=2026-10-20'));
        $this->assertSame([], $days('/chatbot/speed/sequences?date=2026-10-20&part=chart'));
    }

    /**
     * Ang default na view ng ngayon gaya ng binubuksan ng tao: ang page at ang dalawang tawag sa Sequences. Ang banner ng
     * mga problema ng layout (sariling cache, sa lahat ng chatbot page) ay inihahanda muna, para ang mga basa ay ang sa Speed lang.
     */
    private function openDefault(): array
    {
        app(\App\Services\Chatbot\ProblemsPanel::class)->panel();
        $out = [];
        $out['reads'] = $this->bigReads(function () use (&$out): void {
            $out['page'] = $this->get('/chatbot/speed')->assertOk();
            $out['seq'] = $this->getJson('/chatbot/speed/sequences?date=2026-10-21')->assertOk()->json();
            $out['chart'] = $this->getJson('/chatbot/speed/sequences?date=2026-10-21&part=chart')->assertOk()->json();
        });

        return $out;
    }

    /** Ang mga araw na binasa sa botcake_chats habang tumatakbo ang $run (ang petsa ng received_at >= ?). */
    private function chatDays(callable $run): array
    {
        $out = [];
        foreach ($this->bigReads($run)['botcake_chats'] ?? [] as $b) {
            foreach ($b as $v) if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2} /', $v)) $out[substr($v, 0, 10)] = true;
        }
        ksort($out);

        return array_keys($out);
    }

    public function test_S_201_1_the_warm_run_fills_the_default_view_so_opening_it_reads_no_big_table(): void
    {
        $this->ceo();
        Cache::flush();
        $this->assertSame(0, Artisan::call('chatbot:speed-warm'));

        $open = $this->openDefault();
        $this->assertSame([], $open['reads'], 'served from the warm entries');
        $entry = Cache::get(ResponseSpeed::PAGE_KEY . md5(json_encode(ResponseSpeed::filters([]))));
        foreach (['filters', 'hours', 'total', 'by_bot', 'push'] as $k) $this->assertSame($entry[$k], $open['page']->viewData($k), "the page reads the warm entry: $k");
        // Ang kahapon (ang reference ng mga chart) ay naka-cache rin, ang dalawang entry.
        $y = ResponseSpeed::filters(['date' => '2026-10-20']);
        $this->assertNotNull(Cache::get(ResponseSpeed::PAGE_KEY . md5(json_encode($y))));
        $this->assertNotNull(Cache::get(ResponseSpeed::SEQUENCES_KEY . md5(json_encode($y))));
        // Ang mga numero ay ang sa page na kinuwenta nang walang init.
        $warm = self::plain([$open['seq']['sequences'], $open['chart'], $open['page']->viewData('charts')]);
        Cache::flush();
        $cold = $this->openDefault();
        $this->assertSame($warm, self::plain([$cold['seq']['sequences'], $cold['chart'], $cold['page']->viewData('charts')]));

        // Lampas sa 2 minuto ng page pero bago ang susunod na takbo (na hindi dumating): galing pa rin sa cache.
        Cache::flush();
        Artisan::call('chatbot:speed-warm');
        $this->travel(4)->minutes();
        $this->assertSame([], $this->openDefault()['reads'], 'the entry outlives the 2-minute interval');
    }

    public function test_S_201_1_today_is_rebuilt_every_run_and_yesterday_only_when_due(): void
    {
        Cache::flush();
        $this->assertSame(['2026-10-20', '2026-10-21', '2026-10-22'], $this->chatDays(fn () => Artisan::call('chatbot:speed-warm')));
        // Isang bagong mensahe ngayon: lumalabas pagkatapos ng susunod na takbo, hindi kailangang hintayin ang TTL.
        $this->ceo();
        $before = $this->get('/chatbot/speed')->viewData('total')['messages'];
        DB::table('botcake_chats')->insert(['page_id' => '1002', 'page_name' => 'x', 'psid' => '9901', 'received_at' => '2026-10-21 12:20:00', 'payload' => '{}']);
        $this->travel(2)->minutes();
        // Ngayon lang ang binabasa (ang naunang araw ay ang kahapon, naka-cache).
        $this->assertSame(['2026-10-21', '2026-10-22'], $this->chatDays(fn () => Artisan::call('chatbot:speed-warm')));
        $this->assertSame($before + 1, $this->get('/chatbot/speed')->viewData('total')['messages']);
        // 20 minuto mula sa init ng kahapon: kinukuwenta ulit ang kahapon.
        $this->travel(ResponseSpeed::YESTERDAY_WARM_SECONDS)->seconds();
        $this->assertSame(['2026-10-20', '2026-10-21', '2026-10-22'], $this->chatDays(fn () => Artisan::call('chatbot:speed-warm')));
        // Pagkalipas ng hatinggabi: ang bagong kahapon (2026-10-21) ay kinukuwenta ulit bilang buong araw.
        $this->travelTo('2026-10-22 00:00:30');
        $this->assertSame(['2026-10-21', '2026-10-22', '2026-10-23'], $this->chatDays(fn () => Artisan::call('chatbot:speed-warm')));
        $y = Cache::get(ResponseSpeed::PAGE_KEY . md5(json_encode(ResponseSpeed::filters(['date' => '2026-10-21']))));
        $this->assertNotNull($y['hours'][23], 'the whole day, not the hours up to 12:00');
    }

    public function test_S_201_1_the_schedule_runs_the_warm_every_two_minutes_once_at_a_time(): void
    {
        Artisan::call('schedule:list'); // nilo-load ang routes/console.php
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'chatbot:speed-warm'))->values();

        $this->assertCount(1, $events);
        $e = $events[0];
        $this->assertSame('*/2 * * * *', $e->expression);
        $this->assertTrue($e->withoutOverlapping);
        $this->assertSame(10, $e->expiresAt);
        $this->assertTrue($e->onOneServer);
        $this->assertTrue($e->runInBackground);
    }

    /** Ang mga linya ng log ng warm ([level, mensahe]) habang tumatakbo ang $run. */
    private function warmLog(callable $run): array
    {
        $lines = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($e) use (&$lines): void {
            if (str_starts_with($e->message, 'chatbot:speed-warm')) $lines[] = [$e->level, $e->message];
        });
        $run();

        return $lines;
    }
    public function test_S_201_5_a_run_while_the_last_one_is_going_skips_and_logs_one_line(): void
    {
        Cache::flush();
        $lock = Cache::lock(\App\Console\Commands\ChatbotSpeedWarm::LOCK, 600);
        $this->assertTrue($lock->get());
        $log = $this->warmLog(function () use (&$reads): void {
            $reads = $this->bigReads(fn () => $this->assertSame(0, Artisan::call('chatbot:speed-warm')));
        });

        $this->assertSame([], $reads, 'no heavy work twice at once');
        $this->assertNull(Cache::get(ResponseSpeed::PAGE_KEY . md5(json_encode(ResponseSpeed::filters([])))));
        $this->assertSame([['info', 'chatbot:speed-warm skipped: the previous run is still going']], $log);
        $lock->release();
    }

    public function test_S_201_5_each_run_logs_one_line_with_its_time(): void
    {
        Cache::flush();
        $lines = $this->warmLog(fn () => $this->assertSame(0, Artisan::call('chatbot:speed-warm')));
        $this->assertCount(1, $lines);
        $this->assertSame('info', $lines[0][0]);
        $this->assertMatchesRegularExpression('#^chatbot:speed-warm warmed 2026-10-20, 2026-10-20 sequences, 2026-10-21, 2026-10-21 sequences in \d+\.\d s$#', $lines[0][1]);
        // Ang lock ay pinakawalan: ang susunod na takbo ay tumatakbo.
        $this->assertTrue(Cache::lock(\App\Console\Commands\ChatbotSpeedWarm::LOCK, 1)->get());
    }

    public function test_S_201_5_a_failing_part_is_reported_not_written_and_the_page_builds_it_on_demand(): void
    {
        Cache::flush();
        // Ang basa ng mga chat ng ngayon ay pumapalya (gaya ng nawalang koneksyon) habang tumatakbo ang warm lang.
        $fail = true;
        DB::listen(function ($q) use (&$fail): void {
            if ($fail && str_contains($q->sql, 'botcake_chats') && ($q->bindings[0] ?? null) === '2026-10-21 00:00:00') throw new \RuntimeException('server has gone away');
        });
        $lines = $this->warmLog(fn () => $this->assertSame(1, Artisan::call('chatbot:speed-warm')));
        $fail = false;

        $today = ResponseSpeed::filters([]);
        $this->assertNull(Cache::get(ResponseSpeed::PAGE_KEY . md5(json_encode($today))), 'nothing written for the failed part');
        $this->assertNotNull(Cache::get(ResponseSpeed::SEQUENCES_KEY . md5(json_encode($today))), 'the other part is still warm');
        $this->assertCount(1, $lines);
        $this->assertSame('warning', $lines[0][0]);
        $this->assertMatchesRegularExpression('#^chatbot:speed-warm warmed 2026-10-20, 2026-10-20 sequences, 2026-10-21 sequences; failed 2026-10-21 in \d+\.\d s$#', $lines[0][1]);
        // Ang page ay gumagana: kinukuwenta ang ngayon on demand.
        $this->ceo();
        $this->assertGreaterThan(0, $this->get('/chatbot/speed')->assertOk()->viewData('total')['messages']);
        $this->assertNotNull(Cache::get(ResponseSpeed::PAGE_KEY . md5(json_encode($today))));
    }

    public function test_S_201_4_the_page_and_the_sequences_endpoint_give_the_numbers_of_the_old_screen(): void
    {
        $before = json_decode((string) file_get_contents(self::SNAPSHOT), true);
        $this->assertSame(array_keys(self::FILTER_SETS), array_keys($before));
        $this->ceo();
        foreach (self::FILTER_SETS as $name => $q) {
            $old = $before[$name];
            $this->assertGreaterThan(0, $old['sequences']['total']['due'] + $old['total']['messages'], "$name has data");
            Cache::flush();
            $page = self::plain(app(ResponseSpeed::class)->output($q));
            $wantPage = $old;
            unset($wantPage['sequences'], $wantPage['charts']['seq']);
            $this->assertSame($wantPage, $page, "$name: page");

            $qs = http_build_query($q);
            $section = $this->getJson('/chatbot/speed/sequences' . ($qs !== '' ? '?' . $qs : ''))->assertOk()->json();
            $this->assertSame($old['sequences'], $section['sequences'], "$name: sequences");
            $this->assertSame($old['filters'], $section['filters'], "$name: filters");
            $chart = $this->getJson('/chatbot/speed/sequences?' . http_build_query($q + ['part' => 'chart']))->assertOk()->json();
            $this->assertSame(['date' => $old['charts']['date'], 'prev_date' => $old['charts']['prev_date'], 'seq' => $old['charts']['seq']], $chart, "$name: chart");
        }
    }
}
