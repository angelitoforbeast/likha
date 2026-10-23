<?php

namespace Tests\Feature\Chatblast;

use App\Models\AppSetting;
use App\Services\Chatbot\BotHealth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Spec 134, /chatbot/health: isang nakatakdang data (ngayon 2026-10-21, kahapon 10-20) na kinuwenta nang mano-mano.
 * Ngayon: 4 inbound sa mga page na sinagot (A, B, C, D), 3 ang sinagot (4 s, 20 s, 10 s), 1 hindi; E ay nasa page na hindi
 * sinagot kaya hindi binibilang. 10 send: 4 sent, 6 failed (1 unreachable). 3 AI call. Kahapon: 2 inbound (2 s, 60 s).
 */
class ChatbotHealthTest extends ChatblastTestCase
{
    private const KEY = 'events-key-health-test';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
            'database/migrations/2026_10_30_098000_add_raw_to_chatbot_replies.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
            'database/migrations/2026_11_06_100000_add_chat_index_to_chatbot_replies.php',
        ]]);
        config(['services.chatbot.events_key' => self::KEY]);
        $this->travelTo('2026-10-21 12:00:00');
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '1002');
        $this->seedDay();
    }

    private function chat(string $page, string $psid, string $at, string $name = 'Page Uno'): int
    {
        return DB::table('botcake_chats')->insertGetId(['page_id' => $page, 'page_name' => $name, 'psid' => $psid, 'received_at' => $at]);
    }

    private function reply(?int $chat, string $at, string $status = 'sent', ?string $error = null, ?int $http = null, string $kind = 'send', string $page = '1001', string $node = 'n2'): int
    {
        return DB::table('chatbot_replies')->insertGetId(['page_id' => (int) $page, 'psid' => 7001, 'chat_id' => $chat, 'node_id' => $node, 'kind' => $kind,
            'status' => $status, 'error' => $error, 'http' => $http, 'sent_at' => $at]);
    }

    private function ai(string $at, ?string $answered, string $status = 'answered', int $attempts = 1, ?string $error = null, string $page = '1001'): void
    {
        DB::table('chatbot_ai_requests')->insert(['request_id' => uniqid('r', true), 'page_id' => (int) $page, 'psid' => 7001, 'flow_id' => 1, 'node_id' => 'n3',
            'page_name' => 'Page Uno', 'model' => 'm', 'messages' => '[]', 'max_tokens' => 10, 'context' => '{}', 'status' => $status, 'attempts' => $attempts,
            'deadline_at' => $at, 'error' => $error, 'answered_at' => $answered, 'created_at' => $at, 'updated_at' => $at]);
    }

    private function seedDay(): void
    {
        $a = $this->chat('1001', '7001', '2026-10-21 09:00:00');
        $this->reply($a, '2026-10-21 09:00:04');
        $this->reply($a, '2026-10-21 09:00:30'); // hindi ang una
        $b = $this->chat('1001', '7002', '2026-10-21 09:10:00');
        $this->reply($b, '2026-10-21 09:10:01', 'failed', 'Botcake said "(#551) This person isn\'t available right now." (HTTP 200)', 200);
        $this->reply($b, '2026-10-21 09:10:20', node: 'n7');
        $this->chat('1001', '7002', '2026-10-21 09:10:08'); // C: parehong customer ni B, walang sagot
        $d = $this->chat('1002', '7003', '2026-10-21 10:00:00', 'Page Dos');
        $this->reply($d, '2026-10-21 10:00:10', page: '1002');
        $this->chat('1003', '7004', '2026-10-21 10:30:00', 'Page Tres'); // E: walang sagot ang bot sa page na ito
        // Failures: botcake 5xx, outside window, no answer, other (failed) at skipped (other).
        $this->reply($a, '2026-10-21 09:30:00', 'failed', 'Botcake HTTP 502', 502);
        $this->reply($a, '2026-10-21 09:31:00', 'failed', 'Botcake said "(#10) Outside the allowed window." (HTTP 400)', 400);
        $this->reply($a, '2026-10-21 09:32:00', 'failed', 'No answer from Botcake', 0);
        $this->reply($a, '2026-10-21 09:33:00', 'failed', 'Page token expired', 400);
        $this->reply($a, '2026-10-21 09:34:00', 'skipped', 'Bot paused', null);
        $this->reply(null, '2026-10-21 09:35:00', 'sent', null, null, 'sheet');
        $this->reply(null, '2026-10-21 09:36:00', 'failed', 'Sheet down', 500, 'sheet');
        DB::table('chatbot_events')->insert([
            ['page_id' => 1001, 'psid' => 7001, 'type' => 'order', 'created_at' => '2026-10-21 09:40:00'],
            ['page_id' => 1002, 'psid' => 7003, 'type' => 'order', 'created_at' => '2026-10-21 10:40:00'],
            ['page_id' => 1001, 'psid' => 7001, 'type' => 'field', 'created_at' => '2026-10-21 09:41:00'],
            ['page_id' => 1001, 'psid' => 7005, 'type' => 'order', 'created_at' => '2026-10-20 09:40:00'],
        ]);
        $this->ai('2026-10-21 09:00:00', '2026-10-21 09:00:02');
        $this->ai('2026-10-21 09:05:00', '2026-10-21 09:05:06', 'answered', 2);
        $this->ai('2026-10-21 09:10:00', null, 'failed', 3, 'timeout');

        // Kahapon: 2 s at 60 s.
        $f = $this->chat('1001', '7010', '2026-10-20 08:00:00');
        $this->reply($f, '2026-10-20 08:00:02');
        $g = $this->chat('1001', '7011', '2026-10-20 08:05:00');
        $this->reply($g, '2026-10-20 08:06:00');
    }

    private function out(array $q = []): array
    {
        Cache::flush();

        return app(BotHealth::class)->output($q);
    }

    private function today(array $q = []): array
    {
        return $this->out($q)['tiles']['today'];
    }

    public function test_S_134_1_first_reply_time_counts_the_first_sent_reply_and_leaves_unanswered_out(): void
    {
        $t = $this->today();
        $this->assertSame(4, $t['inbound']); // E (page na hindi sinagot) ay wala
        $this->assertSame(3, $t['answered']);
        $this->assertSame(1, $t['not_answered']);
        $this->assertEquals(75.0, $t['answered_pct']);
        $this->assertEquals(11.3, $t['reply_avg']); // (4 + 20 + 10) / 3
        $this->assertEquals(10, $t['reply_median']);
        $this->assertEquals(20, $t['reply_p90']); // nearest rank: ceil(0.9 * 3) = 3
        $this->assertEquals(20, $t['reply_max']);
    }

    public function test_S_134_2_percentiles_use_nearest_rank(): void
    {
        $this->assertNull(BotHealth::percentile([], 90));
        $this->assertNull(BotHealth::median([]));
        $this->assertEquals(60, BotHealth::percentile([2, 60], 90));
        $this->assertEquals(9, BotHealth::percentile(range(1, 10), 90));
        $this->assertEquals(5.5, BotHealth::median(range(1, 10)));
    }

    public function test_S_134_3_failures_are_grouped_by_reason_with_both_rates(): void
    {
        $t = $this->today();
        $this->assertSame(4, $t['sent']);
        $this->assertSame(6, $t['failed']);
        $this->assertSame(['unreachable' => 1, 'botcake' => 1, 'window' => 1, 'timeout' => 1, 'other' => 2], $t['reasons']);
        $this->assertEquals(60.0, $t['failed_pct']);
        $this->assertEquals(50.0, $t['failed_pct_excl']); // 5 / 10: ang unreachable ay hindi binibilang na pumalya
    }

    public function test_S_134_4_ai_calls_failures_times_and_retries(): void
    {
        $t = $this->today();
        $this->assertSame(3, $t['ai_calls']);
        $this->assertSame(1, $t['ai_failed']);
        $this->assertEquals(33.3, $t['ai_failed_pct']);
        $this->assertSame(2, $t['ai_retried']); // attempts 2 at 3
        $this->assertEquals(4, $t['ai_avg']);
        $this->assertEquals(6, $t['ai_p90']);
        $this->assertEquals(6, $t['ai_max']);
    }

    public function test_S_134_5_orders_and_sheet_failures_and_today_vs_yesterday(): void
    {
        $o = $this->out();
        $this->assertSame(2, $o['tiles']['today']['orders']);
        $this->assertSame(1, $o['tiles']['yesterday']['orders']);
        $this->assertSame(1, $o['tiles']['today']['sheet_failed']);
        $this->assertSame(2, $o['tiles']['yesterday']['inbound']);
        $this->assertEquals(31, $o['tiles']['yesterday']['reply_avg']);
        $this->assertSame(2, $o['tiles']['delta']['inbound']);
        $this->assertSame(1, $o['tiles']['delta']['orders']);
        $this->assertEquals(-19.7, $o['tiles']['delta']['reply_avg']); // 11.3 - 31
        $this->assertNull($o['tiles']['delta']['ai_avg']); // kahapon: walang AI call
    }

    public function test_S_134_6_lights_come_from_the_thresholds_with_the_rule_shown(): void
    {
        $lights = $this->out()['lights'];
        $this->assertSame('amber', $lights['reply_p90']['light']); // 20 > 15
        $this->assertSame('> 15 s amber, > 60 s red', $lights['reply_p90']['rule']);
        $this->assertSame('green', $lights['reply_max']['light']);
        $this->assertSame('red', $lights['failed_pct']['light']); // 50 > 10
        $this->assertSame('red', $lights['ai_failed_pct']['light']);
        $this->assertSame('red', $lights['answered_pct']['light']); // 75 < 90
        $this->assertSame('< 98 % amber, < 90 % red', $lights['answered_pct']['rule']);
        $this->assertSame('amber', $lights['sheet_failed']['light']);
        $this->assertSame('red', $lights['overall']['light']);

        AppSetting::set(BotHealth::SETTING, json_encode(['reply_p90' => ['amber' => 30, 'red' => 100], 'failed_pct' => ['amber' => 60, 'red' => 90],
            'ai_failed_pct' => ['amber' => 50, 'red' => 90], 'answered_pct' => ['amber' => 50, 'red' => 20], 'sheet_failed' => ['amber' => 5, 'red' => null]]));
        $lights = $this->out()['lights'];
        $this->assertSame('green', $lights['reply_p90']['light']);
        $this->assertSame('green', $lights['failed_pct']['light']);
        $this->assertSame('green', $lights['sheet_failed']['light']);
        $this->assertSame('green', $lights['overall']['light']);
        $this->assertSame('> 120 s amber', $lights['reply_max']['rule']); // ang key na wala sa JSON ay nasa default
    }

    public function test_S_134_7_a_metric_without_data_has_no_light(): void
    {
        DB::table('chatbot_ai_requests')->delete();
        $lights = $this->out()['lights'];
        $this->assertSame('none', $lights['ai_failed_pct']['light']);
        $this->assertSame('none', $lights['ai_p90']['light']);
    }

    public function test_S_134_8_page_filter_keeps_only_that_page(): void
    {
        $t = $this->today(['page_id' => '1002']);
        $this->assertSame(1, $t['inbound']);
        $this->assertSame(1, $t['sent']);
        $this->assertSame(0, $t['failed']);
        $this->assertSame(1, $t['orders']);
        $this->assertSame(0, $t['ai_calls']);
        $this->assertEquals(10, $t['reply_max']);
    }

    public function test_S_134_9_range_is_capped_at_31_days_and_defaults_to_7(): void
    {
        $this->assertCount(7, $this->out()['days']);
        $o = $this->out(['from' => '2026-08-01', 'to' => '2026-10-21']);
        $this->assertCount(31, $o['days']);
        $this->assertSame('2026-09-21', $o['filters']['from']);
        $this->assertSame('2026-10-21', $o['filters']['to']);
        $o = $this->out(['from' => 'nonsense', 'to' => '2026-10-21', 'page_id' => 'x; drop']);
        $this->assertSame('2026-10-15', $o['filters']['from']);
        $this->assertNull($o['filters']['page_id']);
    }

    public function test_S_134_10_the_payload_is_cached_for_five_minutes(): void
    {
        $this->out();
        $this->chat('1001', '7020', '2026-10-21 11:00:00');
        $this->assertSame(4, app(BotHealth::class)->output([])['tiles']['today']['inbound']);
        $this->travel(6)->minutes();
        $this->assertSame(5, app(BotHealth::class)->output([])['tiles']['today']['inbound']);
    }

    public function test_S_134_11_slow_list_has_the_slowest_first_with_node_and_burst_flag(): void
    {
        $slow = $this->out()['slow'];
        $this->assertSame([60, 20, 10, 4, 2], array_column($slow, 'seconds'));
        $this->assertFalse($slow[0]['burst']); // G: iisang mensahe
        $this->assertTrue($slow[1]['burst']); // B: may isa pang mensahe ng customer 8 s pagkatapos
        $this->assertSame('n7', $slow[1]['node']);
        $this->assertSame('Page Uno', $slow[1]['page']);
        $this->assertSame('/chatbot/replies/' . $slow[1]['reply_id'], $slow[1]['url']);
    }

    public function test_S_134_12_hourly_chart_has_today_reply_time_and_failures_per_hour(): void
    {
        $h = $this->out()['hourly'];
        $this->assertCount(24, $h);
        $this->assertEquals(12, $h[9]['avg']); // 4 s at 20 s, 9:00
        $this->assertSame(2, $h[9]['answered']);
        $this->assertSame(6, $h[9]['failed']);
        $this->assertSame(0, $h[10]['failed']);
        $this->assertEquals(10, $h[10]['avg']);
    }

    public function test_S_134_13_failure_list_has_the_latest_failed_sends_with_the_reason(): void
    {
        $f = $this->out()['failures'];
        $this->assertCount(6, $f);
        $this->assertSame('Other', $f[0]['reason']); // ang skipped 09:34 ang pinakabago
        $this->assertStringStartsWith('Customer not reachable', $f[5]['reason']);
    }

    public function test_S_134_14_the_queue_depth_is_a_number_or_null_when_unreadable(): void
    {
        $this->assertSame(0, $this->out()['queue']['total']); // sync driver: walang naghihintay
        $svc = new class extends BotHealth {
            protected function queueSize(string $connection, string $queue): int { throw new \RuntimeException('redis down'); }
        };
        $this->assertNull($svc->output([])['queue']['total']);
        $this->assertSame('none', $svc->output([])['lights']['queue']['light']);
    }

    public function test_S_134_15_saving_thresholds_is_ceo_only_and_validated(): void
    {
        $good = ['t' => ['reply_p90' => ['amber' => 20, 'red' => 90], 'answered_pct' => ['amber' => 97, 'red' => 85], 'queue' => ['amber' => 300, 'red' => '']]];
        $this->actingAs($this->user('Data Encoder', 'enc@example.test'))->post('/chatbot/health/thresholds', $good)->assertForbidden();
        $this->assertNull(AppSetting::get(BotHealth::SETTING));

        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $this->post('/chatbot/health/thresholds', ['t' => ['reply_p90' => ['amber' => 'abc', 'red' => 90]]])->assertSessionHasErrors();
        $this->post('/chatbot/health/thresholds', ['t' => ['reply_p90' => ['amber' => 90, 'red' => 20]]])->assertSessionHasErrors(); // amber > red
        $this->post('/chatbot/health/thresholds', ['t' => ['answered_pct' => ['amber' => 80, 'red' => 90]]])->assertSessionHasErrors(); // baligtad para sa "mas mababa ay masama"
        $this->post('/chatbot/health/thresholds', ['t' => ['hack' => ['amber' => 1, 'red' => 2]]])->assertSessionHasErrors();
        $this->post('/chatbot/health/thresholds', ['t' => ['reply_p90' => ['amber' => -1, 'red' => 5]]])->assertSessionHasErrors();
        $this->assertNull(AppSetting::get(BotHealth::SETTING));

        $this->post('/chatbot/health/thresholds', $good)->assertRedirect();
        $saved = json_decode((string) AppSetting::get(BotHealth::SETTING), true);
        $this->assertEquals(['amber' => 20, 'red' => 90], $saved['reply_p90']);
        $this->assertEquals(['amber' => 300, 'red' => null], $saved['queue']);
        $this->assertEquals(['amber' => 120, 'red' => null], $saved['reply_max']); // buong hugis, kasama ang default
    }

    public function test_S_134_16_json_endpoint_needs_the_events_key_and_has_numbers_only(): void
    {
        $this->getJson('/api/chatbot/health')->assertStatus(401);
        $this->getJson('/api/chatbot/health', ['X-CHATBOT-EVENTS-KEY' => 'wrong'])->assertStatus(401);
        $res = $this->getJson('/api/chatbot/health?page=1002', ['X-CHATBOT-EVENTS-KEY' => self::KEY])->assertOk();
        $this->assertSame(1, $res->json('tiles.today.inbound'));
        $this->assertNull($res->json('slow'));
        $this->assertNull($res->json('failures'));
        $this->assertStringNotContainsString('7003', $res->getContent()); // walang PSID
        config(['services.chatbot.events_key' => '']);
        $this->getJson('/api/chatbot/health', ['X-CHATBOT-EVENTS-KEY' => ''])->assertStatus(401);
    }

    public function test_S_134_17_page_is_for_chatbot_roles_embeds_chart_data_and_escapes_page_names(): void
    {
        $this->actingAs($this->user('Data Encoder', 'enc@example.test'))->get('/chatbot/health')->assertForbidden();

        DB::table('botcake_chats')->where('page_id', '1002')->update(['page_name' => '<script>alert(1)</script>']);
        $this->page('<script>alert(1)</script>', '1002');
        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $res = $this->get('/chatbot/health?page_id=1001')->assertOk();
        $html = $res->getContent();
        $this->assertStringContainsString('id="bh-data"', $html);
        $this->assertStringContainsString('chart.js', $html);
        preg_match('#<script type="application/json" id="bh-data">(.*?)</script>#s', $html, $m);
        $data = json_decode($m[1], true);
        $this->assertCount(7, $data['days']);
        $this->assertCount(24, $data['hourly']);
        $this->assertStringContainsString('Page Uno', $html);
        $all = $this->get('/chatbot/health')->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $all);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $all); // lumalabas, naka-escape
        $this->assertStringContainsString('Health', $html); // nasa nav
    }
}
