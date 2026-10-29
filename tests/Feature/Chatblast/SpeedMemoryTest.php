<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\ResponseSpeed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Spec 198, /chatbot/speed sa dami ng isang buong araw: 150,000 sequence event (50,000 customer × 3 step, may subscribe
 * kahapon), ang mga padala nila, at 40,000 chat na may sagot. Ang araw ay 2026-10-21; ngayon ay 2026-10-22 08:00, kaya
 * tapos na ang buong araw. Ang inaasahang numero ay galing sa lumang code (5b99fef7) sa parehong fixture.
 */
class SpeedMemoryTest extends ChatblastTestCase
{
    private const DAY = '2026-10-21';

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
        $this->travelTo('2026-10-22 08:00:00');
        foreach (['Page Uno' => '1001', 'Page Dos' => '1002'] as $name => $id) {
            $this->page($name, $id);
            DB::table('page_settings')->insert(['page_name' => $name, 'provider' => 'chatbot', 'key' => 'flow_id', 'value' => '1', 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00']);
        }
    }

    /** Isinusulat ang mga row nang paisa-isang bungkos, para hindi ang fixture ang kumain ng memory ng test. */
    private function flush(array &$buf, bool $all = false): void
    {
        foreach ($buf as $table => $rows) {
            if ($rows && ($all || count($rows) >= 400)) {
                DB::table($table)->insert($rows);
                $buf[$table] = [];
            }
        }
    }

    /**
     * Ang dami ng isang gabi: bawat customer ay may subscribe at tatlong step (ran, skipped o failed); ang ran ay may padala
     * (minsan may naunang pumalyang padala, minsan wala: "no message"), ang Page Uno ay Forbeast (may oras ng Meta ang
     * karamihan), ang Page Dos ay Botcake. Ang mga chat ay may payload na kasinlaki ng totoo, 90% ay may sagot.
     */
    private function seedVolume(int $customers = 50000, int $chats = 40000): void
    {
        $day = strtotime(self::DAY . ' 00:00:00');
        $at = fn (int $t) => date('Y-m-d H:i:s', $t);
        $names = ['Follow up', 'Promo', 'Reminder'];
        $seqs = [];
        foreach ($names as $n => $name) {
            $seqs[$n] = DB::table('chatbot_sequences')->insertGetId(['name' => $name, 'active' => true, 'exit_on_reply' => true]);
            foreach ([1 => 20, 2 => 40, 3 => 60] as $pos => $min) {
                DB::table('chatbot_sequence_steps')->insert(['sequence_id' => $seqs[$n], 'position' => $pos, 'delay_amount' => $min, 'delay_unit' => 'minutes', 'active' => true]);
            }
        }
        $buf = ['chatbot_events' => [], 'chatbot_replies' => [], 'botcake_chats' => []];
        for ($i = 0; $i < $customers; $i++) {
            $page = $i % 2 ? 1002 : 1001;
            $psid = 8000000 + $i;
            $n = $i % 3;
            $event = fn (int $t, array $d) => ['page_id' => $page, 'psid' => $psid, 'type' => 'sequence', 'created_at' => $at($t), 'detail' => json_encode($d + ['name' => $names[$n]])];
            $prev = $day - 600 + ($i * 1543) % 64800;
            // Bawat ika-10 ay may naunang takbo kahapon (subscribe at step 1): ang huli lang bago ang takbo ngayon ang binibilang.
            if ($i % 10 === 0) {
                $buf['chatbot_events'][] = $event($prev - 86400, ['op' => 'subscribed']);
                $buf['chatbot_events'][] = $event($prev - 86400 + 1200, ['op' => 'ran', 'step' => 1, 'sequence_id' => $seqs[$n]]);
            }
            $buf['chatbot_events'][] = $event($prev, ['op' => 'subscribed']);
            foreach ([1 => 20, 2 => 40, 3 => 60] as $k => $min) {
                $due = $prev + $min * 60;
                $ran = $due + ($i * 7 + $k * 13) % 300;
                $r = ($i * 3 + $k) % 20;
                $op = $r === 0 ? 'skipped' : ($r === 1 ? 'failed' : 'ran');
                // Bawat ika-50 ay lumang event na walang sequence_id (ang sequence ay ayon sa pangalan).
                $buf['chatbot_events'][] = $event($ran, ['op' => $op, 'step' => $k, 'due' => $at($due + ($i % 5) * 60)] + ($i % 50 ? ['sequence_id' => $seqs[$n]] : []));
                if ($op === 'ran' && ($i + $k) % 29 !== 0) {
                    $pushed = $ran - ($i % 9) - 1;
                    $send = ['page_id' => $page, 'psid' => $psid, 'node_id' => 'n1', 'kind' => 'send', 'sequence_id' => $seqs[$n], 'sequence_step' => $k,
                        'via' => $page === 1001 ? 'forbeast' : 'botcake', 'confirmed_at' => null, 'confirmed_status' => null];
                    if ($i % 17 === 0) $buf['chatbot_replies'][] = ['status' => 'failed', 'sent_at' => $at($pushed - 30)] + $send;
                    $meta = $page === 1001 ? (($i + $k) % 6 ? $pushed + ($i * $k) % 45 : null) : $pushed;
                    $status = ($i + $k) % 31 === 0 ? 'failed' : 'sent';
                    $buf['chatbot_replies'][] = ['status' => $status, 'sent_at' => $at($pushed), 'confirmed_at' => $meta === null || $status !== 'sent' ? null : $at($meta),
                        'confirmed_status' => $meta === null || $status !== 'sent' ? null : 'sent'] + $send;
                }
                $prev = $ran;
            }
            $this->flush($buf);
        }
        for ($j = 0; $j < $chats; $j++) {
            $page = $j % 2 ? 1002 : 1001;
            $psid = 9000000 + $j % 30000;
            $t = $day + ($j * 2161) % 86400;
            $buf['botcake_chats'][] = ['page_id' => (string) $page, 'page_name' => $page === 1001 ? 'Page Uno' : 'Page Dos', 'psid' => (string) $psid, 'received_at' => $at($t),
                'payload' => json_encode(['message' => ['mid' => 'm_' . md5((string) $j), 'text' => 'Magkano po ito? Order po ako ng isa, COD po sa Quezon City. Salamat po!'],
                    'sender' => ['id' => (string) $psid], 'recipient' => ['id' => (string) $page], 'timestamp' => $t * 1000] + ($page === 1001 ? ['source' => 'gateway'] : []))];
            if ($j % 10 !== 0) {
                $sent = $t + 2 + ($j * 13) % 240;
                $buf['chatbot_replies'][] = ['page_id' => $page, 'psid' => $psid, 'node_id' => 'n2', 'kind' => 'send', 'status' => 'sent', 'sent_at' => $at($sent), 'sequence_id' => null, 'sequence_step' => null,
                    'via' => $page === 1001 ? 'forbeast' : 'botcake', 'confirmed_at' => $j % 7 ? $at($sent + $j % 9) : null, 'confirmed_status' => $j % 7 ? 'sent' : null];
            }
            $this->flush($buf);
        }
        $this->flush($buf, true);
    }

    /** Ang mga numero ng screen (lahat ng table at chart) bilang isang fingerprint, para makita ang kahit isang ibang numero. */
    private static function numbers($res): array
    {
        return ['hours' => $res->viewData('hours'), 'total' => $res->viewData('total'), 'by_bot' => $res->viewData('by_bot'), 'push' => $res->viewData('push'),
            'sequences' => $res->viewData('sequences'), 'charts' => $res->viewData('charts')];
    }

    public function test_S_198_1_a_late_day_volume_renders_under_192_mb_with_the_same_numbers(): void
    {
        $this->seedVolume();
        $this->assertSame(150000, DB::table('chatbot_events')->where('created_at', '>=', self::DAY)->where('created_at', '<', '2026-10-22')->where('detail', 'not like', '%subscribed%')->count());
        $this->actingAs($this->user('CEO', 'ceo-198@example.test'));
        Cache::flush();
        gc_collect_cycles();
        memory_reset_peak_usage();
        $base = memory_get_usage();
        $t = microtime(true);
        $res = $this->get('/chatbot/speed?date=' . self::DAY)->assertOk();
        $secs = round(microtime(true) - $t, 1);
        // Ang memory ng request mismo: sa buong suite ay may dala nang memory ang proseso mula sa ibang test.
        $peak = memory_get_peak_usage() - $base;
        $n = self::numbers($res);

        $s = $n['sequences']['total'];
        $this->assertSame([150000, 126142, 11703, 7500, 4655, 63070, 126142, 56063], [$s['due'], $s['sent'], $s['failed'], $s['skipped'], $s['no_message'], $s['botcake'], $s['scheduled_to_pushed']['n'], $s['pushed_to_meta']['n']]);
        $this->assertSame([40000, 36000, 4000, 126], [$n['total']['messages'], $n['total']['answered'], $n['total']['unanswered'], $n['total']['median']]);
        $this->assertSame([382, '2026-10-21 18:46:45'], [$n['sequences']['slowest'][0]['delay'], $n['sequences']['slowest'][0]['pushed']]);
        // Lahat ng iba pang numero (bawat oras, bawat bot, ang push, ang 20 pinakamabagal at ang mga chart): ang fingerprint ng lumang code.
        $this->assertSame('2aed0348c883fd4283b20bf8e706a451', md5(json_encode($n)));
        // 192 MB sa kabuuan kapag mag-isa ang test (mga 58 MB ang PHPUnit at Laravel bago ang request): 128 MB ang sa request.
        $this->assertLessThan(128 * 1048576, $peak, 'request peak ' . round($peak / 1048576, 1) . " MB in $secs s");
    }

    /**
     * Isang step na naipadala, sariling pangalan ng sequence (para makilala sa listahan), walang kasaysayan kaya ang
     * naitalang due (10:00) ang scheduled. Ang takbo ay $ranAfterPushed segundo pagkatapos ng padala.
     */
    private function tieStep(int $n, int $ranAfterPushed, int $sentAfterDue, int $pushedAfterDue): void
    {
        $due = strtotime('2026-10-21 10:00:00');
        $psid = 7300 + $n;
        DB::table('chatbot_events')->insert(['page_id' => 1001, 'psid' => $psid, 'type' => 'sequence', 'created_at' => date('Y-m-d H:i:s', $due + $pushedAfterDue + $ranAfterPushed),
            'detail' => json_encode(['op' => 'ran', 'name' => sprintf('Seq %02d', $n), 'step' => 1, 'sequence_id' => 900 + $n, 'due' => date('Y-m-d H:i:s', $due)])]);
        DB::table('chatbot_replies')->insert(['page_id' => 1001, 'psid' => $psid, 'node_id' => 'n1', 'kind' => 'send', 'status' => 'sent', 'sent_at' => date('Y-m-d H:i:s', $due + $pushedAfterDue),
            'sequence_id' => 900 + $n, 'sequence_step' => 1, 'via' => 'forbeast', 'confirmed_status' => 'sent', 'confirmed_at' => date('Y-m-d H:i:s', $due + $sentAfterDue)]);
    }

    public function test_S_198_2_the_slowest_20_are_the_same_rows_in_the_same_order_as_before(): void
    {
        // 34 step, maraming tabla: 4 na 1200 s na parehong pushed; 10 na 900 s na mas huling pushed; 12 na 900 s na
        // parehong pushed, ang takbo ay hindi ayon sa pagkakasulat (ang pagkakasunod ng tabla ay ayon sa oras ng takbo);
        // at 8 na 700 s na hindi na aabot sa 20.
        foreach (range(1, 12) as $n) $this->tieStep($n, (13 - $n) % 5, 900, 300);
        foreach (range(13, 22) as $n) $this->tieStep($n, 0, 900, 400);
        foreach (range(23, 30) as $n) $this->tieStep($n, 100 - $n, 700, 300);
        foreach (range(31, 34) as $n) $this->tieStep($n, $n % 2, 1200, 590);
        $this->actingAs($this->user('CEO', 'ceo-198b@example.test'));
        Cache::flush();
        $slow = $this->get('/chatbot/speed?date=' . self::DAY)->assertOk()->viewData('sequences')['slowest'];

        $this->assertSame('["Seq 32","Seq 34","Seq 31","Seq 33","Seq 13","Seq 14","Seq 15","Seq 16","Seq 17","Seq 18","Seq 19","Seq 20","Seq 21","Seq 22","Seq 03","Seq 08","Seq 02","Seq 07","Seq 12","Seq 01"]', json_encode(array_column($slow, 'sequence')));
        $this->assertSame(['page' => 'Page Uno', 'sequence' => 'Seq 32', 'step' => 1, 'scheduled' => '2026-10-21 10:00:00', 'pushed' => '2026-10-21 10:09:50', 'sent' => '2026-10-21 10:20:00',
            'scheduled_to_pushed' => 590, 'pushed_to_meta' => 610, 'delay' => 1200, 'sent_is_pushed' => false], $slow[0]);
    }

    public function test_S_198_1_a_step_written_while_the_page_reads_is_not_counted_half(): void
    {
        // Gaya ng dati, ang mga event ng araw ay ang nakita bago basahin ang mga padala: ang step na naisulat (padala at
        // event) habang binabasa ang mga padala ay sa susunod na load na, hindi "no message" o walang kasaysayan ngayon.
        foreach (range(1, 3) as $n) $this->tieStep($n, 0, 900, 300);
        $done = false;
        DB::listen(function ($q) use (&$done): void {
            if ($done || !str_contains($q->sql, 'from "chatbot_replies"') || !str_contains($q->sql, '"sequence_id" is not null')) return;
            $done = true;
            $this->tieStep(4, 5, 900, 3600);
        });
        $this->actingAs($this->user('CEO', 'ceo-198d@example.test'));
        Cache::flush();
        $s = $this->get('/chatbot/speed?date=' . self::DAY)->assertOk()->viewData('sequences')['total'];

        $this->assertTrue($done);
        $this->assertSame([3, 3, 0], [$s['due'], $s['sent'], $s['no_message']]);
    }

    public function test_S_198_4_a_sequences_section_over_its_budget_or_failing_leaves_the_rest_of_the_page(): void
    {
        foreach (range(1, 3) as $n) $this->tieStep($n, 0, 900, 300);
        DB::table('botcake_chats')->insert(['page_id' => '1002', 'page_name' => 'Page Dos', 'psid' => '7001', 'received_at' => '2026-10-21 10:05:00', 'payload' => '{}']);
        DB::table('chatbot_replies')->insert(['page_id' => 1002, 'psid' => 7001, 'node_id' => 'n2', 'kind' => 'send', 'status' => 'sent', 'sent_at' => '2026-10-21 10:05:20', 'via' => 'botcake']);
        $this->actingAs($this->user('CEO', 'ceo-198c@example.test'));
        // Lampas sa badyet (0 byte), at isang ibang error sa loob ng section. Iisang instance (ang controller ay naitatago
        // ng route), ang $mode ang nagpapalit.
        $mode = 'over';
        $this->app->instance(ResponseSpeed::class, new class ($mode) extends ResponseSpeed {
            public function __construct(private string &$mode)
            {
            }

            protected function sequenceBudget(): int
            {
                if ($this->mode === 'error') throw new \RuntimeException('database went away');

                return $this->mode === 'over' ? 0 : parent::sequenceBudget();
            }
        });
        foreach (['over', 'error'] as $mode) {
            Cache::flush();
            $res = $this->get('/chatbot/speed?date=' . self::DAY)->assertOk();
            $html = $res->getContent();

            $this->assertStringContainsString('Sequences section too large to compute now', $html);
            $this->assertStringNotContainsString('data-table="sequences"', $html);
            $this->assertStringNotContainsString('data-table="sequence-slowest"', $html);
            $this->assertSame(1, $res->viewData('total')['answered']); // ang ibang section ay buo
            foreach (['hours', 'ai', 'push'] as $table) $this->assertStringContainsString('data-table="' . $table . '"', $html);
            $this->assertStringContainsString('id="sp-data"', $html);
        }

        // Hindi naka-cache nang 30 minuto ang kulang na sagot ng lumang araw: pagkalipas ng 2 minuto ay kinukuwenta ulit.
        $mode = 'normal';
        $this->assertStringContainsString('Sequences section too large', $this->get('/chatbot/speed?date=' . self::DAY)->getContent());
        $this->travel(121)->seconds();
        $res = $this->get('/chatbot/speed?date=' . self::DAY)->assertOk();
        $this->assertStringNotContainsString('Sequences section too large', $res->getContent());
        $this->assertSame(3, $res->viewData('sequences')['total']['sent']);
    }
}
