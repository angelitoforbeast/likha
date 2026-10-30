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
