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
    use LoadsSpeedSequences;

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
        $this->assertSame(['filters', 'sequences', 'built_at', 'html'], array_keys($s));
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
        $this->get('/chatbot/speed/sequences?part=other')->assertOk()->assertJsonStructure(['filters', 'sequences', 'built_at', 'html']);
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
        // (ang araw ay naka-cache na). Ang ulit: walang basa. May page (ang default na view ay galing sa warm, spec 277).
        $this->assertSame(['2026-10-20', '2026-10-21'], $days('/chatbot/speed/sequences?page_id=1002&date=2026-10-20'));
        $this->assertSame(['2026-10-19', '2026-10-20'], $days('/chatbot/speed/sequences?page_id=1002&date=2026-10-20&part=chart'));
        $this->assertSame([], $days('/chatbot/speed/sequences?page_id=1002&date=2026-10-20'));
        $this->assertSame([], $days('/chatbot/speed/sequences?page_id=1002&date=2026-10-20&part=chart'));
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
        // Ang mga numero ay ang sa page na kinuwenta nang walang init (ang Sequences ng default na view ay kinukuwenta ng
        // service mismo: ang endpoint ay "building" lang kapag wala pa ang entry, spec 277).
        $warm = self::plain([$open['seq']['sequences'], $open['chart'], $open['page']->viewData('charts')]);
        Cache::flush();
        $cold = $this->openDefault();
        $this->assertTrue($cold['seq']['sequences']['building']);
        $speed = app(ResponseSpeed::class);
        $this->assertSame($warm, self::plain([$speed->sequencesOutput(['date' => '2026-10-21'])['sequences'], $speed->sequencesOutput(['date' => '2026-10-21'], true), $cold['page']->viewData('charts')]));

        // Lampas sa 2 minuto ng page pero bago ang susunod na takbo (na hindi dumating, o mabagal): galing pa rin sa cache,
        // hanggang 14 minuto (spec 277: ang entry ay mas matagal kaysa sa isang mabagal na takbo).
        Cache::flush();
        Artisan::call('chatbot:speed-warm');
        $this->travel(4)->minutes();
        $this->assertSame([], $this->openDefault()['reads'], 'the entry outlives the 2-minute interval');
        $this->travel(10)->minutes();
        $this->assertSame([], $this->openDefault()['reads'], 'the entry outlives a slow run');
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
        $this->assertMatchesRegularExpression('#^chatbot:speed-warm warmed 2026-10-20 \(\d+\.\d s\), 2026-10-21 \(\d+\.\d s\), 2026-10-20 sequences \(\d+\.\d s\), 2026-10-21 sequences \(\d+\.\d s\) in \d+\.\d s$#', $lines[0][1]);
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
        $this->assertMatchesRegularExpression('#^chatbot:speed-warm warmed 2026-10-20 \(\d+\.\d s\), 2026-10-20 sequences \(\d+\.\d s\), 2026-10-21 sequences \(\d+\.\d s\); failed 2026-10-21 \(\d+\.\d s\) in \d+\.\d s$#', $lines[0][1]);
        // Ang page ay gumagana: kinukuwenta ang ngayon on demand.
        $this->ceo();
        $this->assertGreaterThan(0, $this->get('/chatbot/speed')->assertOk()->viewData('total')['messages']);
        $this->assertNotNull(Cache::get(ResponseSpeed::PAGE_KEY . md5(json_encode($today))));
    }

    public function test_S_201_5_a_sequences_answer_over_the_budget_is_shown_as_too_large_and_does_not_replace_a_full_one(): void
    {
        // Ang Sequences ay lampas sa badyet sa takbo ng warm: kapag wala pang entry, ang tala na too_large ang isinusulat (ang
        // endpoint ay hindi kumukuwenta ng default na view, spec 277, kaya "too large" at hindi "building" nang walang hanggan);
        // ang page part ay naisulat, at ang kahapon ay may tala.
        Cache::flush();
        $this->app->instance(ResponseSpeed::class, new class extends ResponseSpeed {
            protected function sequenceBudget(): int
            {
                return 0;
            }
        });
        $lines = $this->warmLog(fn () => $this->assertSame(0, Artisan::call('chatbot:speed-warm')));

        foreach (['2026-10-20', '2026-10-21'] as $d) {
            $f = ResponseSpeed::filters(['date' => $d]);
            $this->assertTrue(Cache::get(ResponseSpeed::SEQUENCES_KEY . md5(json_encode($f)))['sequences']['too_large'], "$d sequences written as too large");
            $this->assertNotNull(Cache::get(ResponseSpeed::PAGE_KEY . md5(json_encode($f))), "$d page written");
        }
        $this->assertIsInt(Cache::get(ResponseSpeed::WARM_STAMP_KEY . '2026-10-20'));
        $this->assertCount(1, $lines);
        $this->assertSame('warning', $lines[0][0]);
        $this->assertMatchesRegularExpression('#^chatbot:speed-warm warmed 2026-10-20 \(\d+\.\d s\), 2026-10-21 \(\d+\.\d s\); too large 2026-10-20 sequences \(\d+\.\d s\), 2026-10-21 sequences \(\d+\.\d s\) in \d+\.\d s$#', $lines[0][1]);
        $this->ceo();
        $this->assertStringContainsString('Sequences section too large to compute now', $this->getJson('/chatbot/speed/sequences')->assertOk()->json('html'));

        // Ang buong sagot na naisulat na ay hindi pinapalitan ng susunod na too_large.
        $this->app->forgetInstance(ResponseSpeed::class);
        Cache::flush();
        Artisan::call('chatbot:speed-warm');
        $this->travel(ResponseSpeed::SEQUENCES_WARM_SECONDS)->seconds();
        $this->app->instance(ResponseSpeed::class, new class extends ResponseSpeed {
            protected function sequenceBudget(): int
            {
                return 0;
            }
        });
        Artisan::call('chatbot:speed-warm');
        $today = Cache::get(ResponseSpeed::SEQUENCES_KEY . md5(json_encode(ResponseSpeed::filters([]))));
        $this->assertArrayNotHasKey('too_large', $today['sequences']);
        $this->assertGreaterThan(0, $today['sequences']['total']['due']);
    }

    public function test_S_201_3_a_sequences_call_over_its_time_budget_answers_too_large_in_time(): void
    {
        // Ang web request ay may hangganang oras (bago ang 30 s ng php-fpm): lampas dito, ang tala na too_large (200), hindi
        // ang timeout ng server; ang ibang section ay buo. Ang takbo ng warm (console) ay walang hangganang oras.
        $this->assertLessThan(30, ResponseSpeed::SEQUENCE_SECONDS);
        $this->app->instance(ResponseSpeed::class, new class extends ResponseSpeed {
            protected function sequenceSeconds(): ?float
            {
                return 0.0;
            }
        });
        $this->ceo();
        Cache::flush();
        // May page: ang may filter ay kinukuwenta sa request (ang default na view ay galing sa warm, spec 277).
        $page = $this->get('/chatbot/speed?date=2026-10-20&page_id=1002')->assertOk();
        $this->assertGreaterThan(0, $page->viewData('total')['messages']);
        $s = $this->getJson('/chatbot/speed/sequences?date=2026-10-20&page_id=1002')->assertOk()->json();
        $this->assertTrue($s['sequences']['too_large']);
        $this->assertStringContainsString('Sequences section too large to compute now', $s['html']);
        $this->getJson('/chatbot/speed/sequences?date=2026-10-20&page_id=1002&part=chart')->assertOk()->assertJsonPath('seq.series.avg.10', null);
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
            // Lahat ng numero maliban sa bagong Not sent (ang fixture ay walang pumalyang padala ng Main Flow o AI).
            // The volume section is newer than the snapshot; ResponseSpeedTest covers it. Ang built_at at final ay bago rin (spec 277).
            $page = self::plain(self::withoutNotSent(array_diff_key(app(ResponseSpeed::class)->output($q), ['volume' => 1, 'built_at' => 1, 'final' => 1])));
            $wantPage = $old;
            unset($wantPage['sequences'], $wantPage['charts']['seq']);
            $this->assertSame($wantPage, $page, "$name: page");

            // Ang default na view ng ngayon at kahapon ay galing sa warm: ang speedSequences ay nagpapatakbo nito kapag "building".
            $section = $this->speedSequences(http_build_query($q));
            $this->assertSame($old['sequences'], $section['sequences'], "$name: sequences");
            $this->assertSame($old['filters'], $section['filters'], "$name: filters");
            $chart = $this->speedSequences(http_build_query($q), true);
            $this->assertSame(['date' => $old['charts']['date'], 'prev_date' => $old['charts']['prev_date'], 'seq' => $old['charts']['seq']], $chart, "$name: chart");
        }
    }

    // ── spec 277: ang warm na gumagana, ang tapos nang araw, ang page na hindi naghihintay ───────────────────────────

    /** Ang mga araw na binasa sa $table habang tumatakbo ang $run (ang petsa ng bawat binding na oras). */
    private function readDays(string $table, callable $run): array
    {
        $out = [];
        foreach ($this->bigReads($run)[$table] ?? [] as $b) {
            foreach ($b as $v) if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2} /', $v)) $out[substr($v, 0, 10)] = true;
        }
        ksort($out);

        return array_keys($out);
    }

    /** Ang mga araw na binasa ng isang takbo ng warm: [botcake_chats, chatbot_events]. */
    private function warmReads(): array
    {
        $events = [];
        $chats = $this->readDays('botcake_chats', function () use (&$events): void {
            $events = $this->readDays('chatbot_events', fn () => Artisan::call('chatbot:speed-warm'));
        });

        return [$chats, $events];
    }

    public function test_S_277_1_a_stamp_stored_as_a_numeric_string_is_respected(): void
    {
        // Ang Redis ay nagbabalik ng numero bilang string: ang tala ng init ay hindi dapat "wala" dahil dito.
        Cache::flush();
        Artisan::call('chatbot:speed-warm');
        $now = (string) now()->getTimestamp();
        Cache::put(ResponseSpeed::WARM_STAMP_KEY . '2026-10-20', $now, 3600);
        foreach (['2026-10-20', '2026-10-21'] as $d) Cache::put(ResponseSpeed::SEQUENCES_WARM_STAMP_KEY . $d, $now, 3600);
        $this->assertIsString(Cache::get(ResponseSpeed::WARM_STAMP_KEY . '2026-10-20'));
        $this->travel(2)->minutes();

        // Ang ngayon lang ang page na kinukuwenta; walang Sequences (wala pang 30 minuto ang tala).
        [$chats, $events] = $this->warmReads();
        $this->assertSame(['2026-10-21', '2026-10-22'], $chats);
        $this->assertSame([], $events, 'no sequences rebuilt within the period');

        // Ang lumang tala (string din) ay lumipas na: kinukuwenta ulit.
        $old = (string) (now()->getTimestamp() - ResponseSpeed::SEQUENCES_WARM_SECONDS);
        Cache::put(ResponseSpeed::WARM_STAMP_KEY . '2026-10-20', $old, 3600);
        Cache::put(ResponseSpeed::SEQUENCES_WARM_STAMP_KEY . '2026-10-21', $old, 3600);
        [$chats, $events] = $this->warmReads();
        $this->assertSame(['2026-10-20', '2026-10-21', '2026-10-22'], $chats);
        $this->assertSame(['2026-10-21', '2026-10-22'], $events, 'today\'s sequences only');
    }

    public function test_S_277_2_the_warm_runs_with_a_1024M_memory_limit_and_gives_the_old_one_back(): void
    {
        $seen = [];
        $this->app->instance(ResponseSpeed::class, new class ($seen) extends ResponseSpeed {
            public function __construct(private array &$seen)
            {
            }

            public function warmDefault(): array
            {
                $this->seen[] = [ini_get('memory_limit'), $this->sequenceBudget()];

                return ['warmed' => [], 'failed' => [], 'too_large' => [], 'secs' => []];
            }
        });
        $was = ini_get('memory_limit');
        // Ang "mas mababa" ay higit sa nagamit na ng proseso (sa buong suite ay malaki na ito), pero mas mababa sa 1024M.
        $low = max(512, intdiv(memory_get_usage(true), 1048576) + 64);
        if ($low >= 1024) $this->markTestSkipped("the process already uses about {$low}M");
        try {
            foreach (['-1', $low . 'M', '2048M'] as $limit) {
                ini_set('memory_limit', $limit);
                Artisan::call('chatbot:speed-warm');
                $this->assertSame($limit, ini_get('memory_limit'), "$limit given back");
            }
        } finally {
            ini_set('memory_limit', $was);
        }
        // Mas mababa o walang hangganan: 1024M (badyet 768M); mas mataas: hindi ibinababa.
        $this->assertSame([['1024M', 768 * 1048576], ['1024M', 768 * 1048576], ['2048M', 1536 * 1048576]], $seen);
    }

    public function test_S_277_4_final_sequences_of_yesterday_are_built_once_and_kept_for_a_week(): void
    {
        // 07:00: ang Sequences ng kahapon ay tapos na (lampas na ang 06:00 ng huling step); ang page ng kahapon ay hindi pa
        // (24 oras ng sagot), kaya ito ay kada YESTERDAY_WARM_SECONDS pa rin.
        $this->travelTo('2026-10-21 07:00:00');
        Cache::flush();
        $this->assertSame(['2026-10-20', '2026-10-21', '2026-10-22'], $this->readDays('chatbot_events', fn () => Artisan::call('chatbot:speed-warm')));
        $key = ResponseSpeed::SEQUENCES_KEY . md5(json_encode(ResponseSpeed::filters(['date' => '2026-10-20'])));
        $entry = Cache::get($key);
        $this->assertTrue($entry['final']);
        $this->assertFalse(Cache::get(ResponseSpeed::PAGE_KEY . md5(json_encode(ResponseSpeed::filters(['date' => '2026-10-20']))))['final']);

        // Lampas sa SEQUENCES_WARM_SECONDS at sa YESTERDAY_WARM_SECONDS: ang Sequences ng ngayon at ang page ng kahapon ay
        // kinukuwenta ulit, ang tapos nang Sequences ng kahapon ay hindi.
        $this->travel(ResponseSpeed::SEQUENCES_WARM_SECONDS)->seconds();
        [$chats, $events] = $this->warmReads();
        $this->assertSame(['2026-10-21', '2026-10-22'], $events, 'yesterday\'s final sequences are not rebuilt');
        $this->assertSame(['2026-10-20', '2026-10-21', '2026-10-22'], $chats);
        $this->assertSame($entry, Cache::get($key));

        // Itinatago nang 7 araw: anim na araw mula ngayon ay nandoon pa (ang endpoint ng araw na iyon ay galing dito).
        $this->travel(6)->days();
        $this->assertSame($entry, Cache::get($key));
        $this->ceo();
        $this->assertSame([], $this->readDays('chatbot_events', fn () => $this->getJson('/chatbot/speed/sequences?date=2026-10-20')->assertOk()));
        $this->travel(2)->days();
        $this->assertNull(Cache::get($key));
    }

    public function test_S_277_4_a_final_past_day_opened_on_the_page_is_kept_for_a_week(): void
    {
        $this->ceo();
        Cache::flush();
        // Ang 2026-10-19 ay tapos na (lampas na ang 24 oras ng sagot): ang default na view nito ay kinukuwenta nang isang beses.
        $this->get('/chatbot/speed?date=2026-10-19')->assertOk();
        $key = ResponseSpeed::PAGE_KEY . md5(json_encode(ResponseSpeed::filters(['date' => '2026-10-19'])));
        $entry = Cache::get($key);
        $this->assertTrue($entry['final']);
        $this->travel(5)->days();
        $res = $this->get('/chatbot/speed?date=2026-10-19')->assertOk();
        $this->assertSame($entry['built_at'], $res->viewData('built_at'), 'served from the kept copy');
        $this->assertSame($entry, Cache::get($key));
        // Ang may filter ay 30 minuto pa rin.
        $this->get('/chatbot/speed?date=2026-10-19&page_id=1001')->assertOk();
        $this->travel(ResponseSpeed::PAST_CACHE_SECONDS + 1)->seconds();
        $this->assertNotSame([], $this->readDays('botcake_chats', fn () => $this->get('/chatbot/speed?date=2026-10-19&page_id=1001')->assertOk()));
    }

    public function test_S_277_4_yesterday_not_final_yet_is_rebuilt_on_schedule(): void
    {
        // 03:00: ang mga step ng kahapon ay puwede pang tumakbo hanggang 06:00, kaya ang Sequences nito ay kada 30 minuto.
        $this->travelTo('2026-10-21 03:00:00');
        Cache::flush();
        Artisan::call('chatbot:speed-warm');
        $key = ResponseSpeed::SEQUENCES_KEY . md5(json_encode(ResponseSpeed::filters(['date' => '2026-10-20'])));
        $this->assertFalse(Cache::get($key)['final']);
        $this->travel(ResponseSpeed::SEQUENCES_WARM_SECONDS - 60)->seconds();
        $this->assertSame([], $this->readDays('chatbot_events', fn () => Artisan::call('chatbot:speed-warm')));
        $this->travel(60)->seconds();
        $this->assertSame(['2026-10-20', '2026-10-21', '2026-10-22'], $this->readDays('chatbot_events', fn () => Artisan::call('chatbot:speed-warm')));
        $this->assertFalse(Cache::get($key)['final'], 'still not final at 03:30');
    }

    public function test_S_277_5_the_default_page_comes_from_the_warm_entry_and_shows_when_it_was_built(): void
    {
        Cache::flush();
        Artisan::call('chatbot:speed-warm');
        $this->ceo();
        // Ilang minuto pagkatapos (ang susunod na takbo ay mabagal pa): walang basa, ang oras ng entry ang nakikita. Ang
        // banner ng mga problema ng layout (sariling cache) ay inihahanda muna.
        $this->travel(9)->minutes();
        app(\App\Services\Chatbot\ProblemsPanel::class)->panel();
        $res = null;
        $this->assertSame([], $this->bigReads(function () use (&$res): void {
            $res = $this->get('/chatbot/speed')->assertOk();
        }));
        $this->assertSame(strtotime('2026-10-21 12:30:00'), $res->viewData('built_at'));
        $this->assertStringContainsString('data-sp-asof="page">Numbers as of 12:30</p>', $res->getContent());
        $s = $this->getJson('/chatbot/speed/sequences')->assertOk()->json();
        $this->assertSame(strtotime('2026-10-21 12:30:00'), $s['built_at']);
        $this->assertStringContainsString('data-sp-asof="sequences">As of 12:30</p>', $s['html']);
    }

    public function test_S_277_5_a_missing_default_sequences_entry_answers_building_at_once(): void
    {
        $this->ceo();
        Cache::flush();
        foreach (['', '?date=2026-10-20'] as $q) {
            $s = null;
            $c = null;
            $reads = $this->bigReads(function () use (&$s, $q): void {
                $s = $this->getJson('/chatbot/speed/sequences' . $q)->assertOk()->json();
            });
            $this->assertArrayNotHasKey('chatbot_events', $reads, "$q: nothing computed");
            // Ang chart: ang araw ay walang punto; ang naunang araw ng kahapon (hindi pinapainit) ay kinukuwenta gaya ng dati.
            $c = $this->getJson('/chatbot/speed/sequences' . ($q === '' ? '?' : $q . '&') . 'part=chart')->assertOk()->json();
            $this->assertTrue($s['sequences']['building']);
            $this->assertNull($s['built_at']);
            $this->assertStringContainsString('Binubuo pa ang Sequences; lalabas sa loob ng ilang minuto', $s['html']);
            $this->assertStringNotContainsString('sp-chart-seq', $s['html']);
            $this->assertSame([], array_filter($c['seq']['series']['avg'], fn ($v) => $v !== null));
            $this->assertNull(Cache::get(ResponseSpeed::SEQUENCES_KEY . md5(json_encode(ResponseSpeed::filters(['date' => $s['filters']['date']])))), 'not written');
        }
        // Ang page ay may retry ng section kapag building.
        $this->assertStringContainsString('s.sequences.building', $this->get('/chatbot/speed')->getContent());
        // Ang susunod na takbo ng warm ang gumagawa nito.
        Artisan::call('chatbot:speed-warm');
        $s = $this->getJson('/chatbot/speed/sequences')->assertOk()->json();
        $this->assertArrayNotHasKey('building', $s['sequences']);
        $this->assertGreaterThan(0, $s['sequences']['total']['due']);
    }

    public function test_S_277_5_filtered_sequences_and_older_days_are_still_computed_on_request(): void
    {
        $this->ceo();
        Cache::flush();
        foreach (['?page_id=1001', '?bot=Forbeast', '?date=2026-10-19'] as $q) {
            $s = null;
            $reads = $this->bigReads(function () use (&$s, $q): void {
                $s = $this->getJson('/chatbot/speed/sequences' . $q)->assertOk()->json();
            });
            $this->assertArrayHasKey('chatbot_events', $reads, "$q: computed");
            $this->assertArrayNotHasKey('building', $s['sequences']);
            $this->assertIsInt($s['built_at']);
        }
    }
}
