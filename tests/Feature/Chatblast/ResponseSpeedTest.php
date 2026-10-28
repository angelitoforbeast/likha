<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\ResponseSpeed;
use App\Services\Chatbot\SendStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Spec 182, /chatbot/speed: ang bilis ng unang sagot ng bot kada oras (mensahe ng customer → unang padala na talagang
 * naipadala). Ngayon ay 2026-10-21 12:30. Page Uno (1001) ay Forbeast, Page Dos (1002) ay Botcake, Page Tres (1003) ay
 * walang flow (wala sa bot). Tatlong customer nang 10:05: 20 s (Botcake), 72 s (Forbeast, kumpirmado ng send_status) at
 * wala.
 */
class ResponseSpeedTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_30_098000_add_raw_to_chatbot_replies.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
            'database/migrations/2026_11_06_100000_add_chat_index_to_chatbot_replies.php',
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
            'database/migrations/2026_11_11_100000_add_real_send_to_chatbot_replies.php',
        ]]);
        $this->travelTo('2026-10-21 12:30:00');
        foreach (['Page Uno' => '1001', 'Page Dos' => '1002', 'Page Tres' => '1003'] as $name => $id) $this->page($name, $id);
        foreach (['Page Uno', 'Page Dos'] as $p) DB::table('page_settings')->insert(['page_name' => $p, 'provider' => 'chatbot', 'key' => 'flow_id', 'value' => '1', 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00']);
        $this->seedDay();
    }

    private function chat(string $page, string $psid, string $at, array $payload = []): int
    {
        return DB::table('botcake_chats')->insertGetId(['page_id' => $page, 'page_name' => 'x', 'psid' => $psid, 'received_at' => $at,
            'payload' => json_encode($payload + ($page === '1001' ? ['source' => 'gateway'] : []))]);
    }

    private function reply(string $page, string $psid, string $at, array $extra = []): int
    {
        $via = $page === '1001' ? 'forbeast' : 'botcake';

        return DB::table('chatbot_replies')->insertGetId($extra + ['page_id' => (int) $page, 'psid' => (int) $psid, 'node_id' => 'n2', 'kind' => 'send',
            'status' => 'sent', 'sent_at' => $at, 'via' => $via] + ($via === 'botcake' ? ['confirmed_at' => $at, 'confirmed_status' => 'sent'] : []));
    }

    private function seedDay(): void
    {
        // Botcake: 20 s.
        $this->chat('1002', '7001', '2026-10-21 10:05:00');
        $this->reply('1002', '7001', '2026-10-21 10:05:20');
        $this->reply('1002', '7001', '2026-10-21 10:05:40'); // hindi ang una
        // Forbeast: tinanggap 10:06:10, naipadala ayon sa send_status 10:06:12 → 72 s.
        $this->chat('1001', '7002', '2026-10-21 10:05:00');
        $this->reply('1001', '7002', '2026-10-21 10:06:10', ['client_ref' => 'ref-7002']);
        $this->assertSame('send_status', app(SendStatus::class)->receive(['page_id' => '1001', 'psid' => '7002', 'send' => [
            'client_ref' => 'ref-7002', 'status' => 'sent', 'accepted_at' => '2026-10-21T02:06:10.000Z', 'sent_at' => '2026-10-21T02:06:12.000Z',
            'message_count' => 1, 'sent_count' => 1, 'error' => null, 'error_code' => null, 'error_subcode' => null]]));
        // Walang sagot: ang sequence step, ang test at ang pumalyang padala ay hindi sagot.
        $this->chat('1001', '7003', '2026-10-21 10:05:00');
        $this->reply('1001', '7003', '2026-10-21 10:30:00', ['sequence_id' => 5, 'sequence_step' => 1]);
        $this->reply('1001', '7003', '2026-10-21 10:31:00', ['status' => 'test']);
        $this->reply('1001', '7003', '2026-10-21 10:32:00', ['status' => 'failed', 'confirmed_status' => 'failed']);
        // Hindi binibilang: ang attachment na sadyang hindi sinasagot, at ang standby na kopya ng Botcake (ang Forbeast ang sumasagot).
        $this->chat('1001', '7004', '2026-10-21 10:07:00', ['_ignored' => true]);
        $this->chat('1001', '7002', '2026-10-21 10:05:00', ['_standby' => true]);
        // Wala sa bot (walang flow ang Page Tres): hiwalay na bilang.
        $this->chat('1003', '7005', '2026-10-21 10:10:00');
        // 11:00: isang mabilis na sagot (5 s).
        $this->chat('1002', '7006', '2026-10-21 11:00:00');
        $this->reply('1002', '7006', '2026-10-21 11:00:05');
        // Kahapon: 3 s.
        $this->chat('1002', '7007', '2026-10-20 09:00:00');
        $this->reply('1002', '7007', '2026-10-20 09:00:03');
    }

    private function out(array $q = []): array
    {
        Cache::flush();

        return app(ResponseSpeed::class)->output($q);
    }

    public function test_S_182_1_the_hour_row_uses_the_first_reply_actually_sent(): void
    {
        $h = $this->out()['hours'][10];

        $this->assertSame(10, $h['hour']);
        $this->assertSame(3, $h['messages']);
        $this->assertSame(2, $h['answered']);
        $this->assertSame(1, $h['unanswered']);
        $this->assertEquals(46, $h['avg']);
        $this->assertEquals(46, $h['median']);
        $this->assertSame(72, $h['p90']);  // ang kumpirmadong oras (10:06:12), hindi ang pagtanggap (10:06:10)
        $this->assertSame(1, $h['within_30']);
        $this->assertSame(1, $h['within_60']);
        $this->assertSame(2, $h['within_300']);
        $this->assertEquals(33.3, $h['pct_30']);
        $this->assertSame(0, $h['unconfirmed']); // lahat ng sagot ay may kumpirmadong oras

        $t = $this->out()['total'];
        $this->assertSame([4, 3, 1], [$t['messages'], $t['answered'], $t['unanswered']]);
    }

    public function test_S_182_1_a_reply_without_send_status_uses_its_recorded_time_and_is_marked(): void
    {
        DB::table('chatbot_replies')->where('client_ref', 'ref-7002')->update(['confirmed_at' => null, 'confirmed_status' => null]);
        $h = $this->out()['hours'][10];

        $this->assertSame(70, $h['p90']);
        $this->assertSame(1, $h['unconfirmed']);
    }

    public function test_S_182_2_yesterday_and_the_day_arrows_move_by_one_day(): void
    {
        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $html = $this->get('/chatbot/speed')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#href="/chatbot/speed\?date=2026-10-20"[^>]*>Yesterday<#', $html);
        $this->assertStringNotContainsString('date=2026-10-22', $html); // walang → lampas ngayon

        $y = $this->out(['date' => '2026-10-20']);
        $this->assertSame('2026-10-20', $y['filters']['date']);
        $this->assertSame(['2026-10-19', '2026-10-21'], [$y['dates']['prev'], $y['dates']['next']]);
        $this->assertSame(1, $y['total']['messages']);
        $this->assertSame(3, $y['hours'][9]['median']);
        $this->assertNotNull($y['hours'][23]); // tapos na ang buong araw

        $html = $this->get('/chatbot/speed?date=2026-10-20')->assertOk()->getContent();
        $this->assertStringContainsString('date=2026-10-19', $html);
        $this->assertStringContainsString('date=2026-10-21', $html);
        // Ang petsa sa hinaharap o sira ay ngayon.
        $this->assertSame('2026-10-21', $this->out(['date' => '2026-10-25'])['filters']['date']);
        foreach (['2026-02-30', ['x'], '<script>', '2026-10-1'] as $bad) $this->assertSame('2026-10-21', $this->out(['date' => $bad])['filters']['date']);
        $this->assertNull($this->out(['page_id' => ['1002']])['filters']['page_id']);
        $this->get('/chatbot/speed?date[]=x&page_id[]=1')->assertOk();
    }

    public function test_S_182_1_a_message_just_before_midnight_counts_in_hour_23_with_its_reply_after_midnight(): void
    {
        $this->chat('1002', '7020', '2026-10-20 23:59:50');
        $this->reply('1002', '7020', '2026-10-21 00:00:10');
        $this->chat('1002', '7021', '2026-10-20 08:00:00');
        $this->reply('1002', '7021', '2026-10-21 08:00:01'); // lampas 24 oras: hindi na sagot

        $y = $this->out(['date' => '2026-10-20']);
        $this->assertSame([1, 1, 20], [$y['hours'][23]['messages'], $y['hours'][23]['answered'], $y['hours'][23]['median']]);
        $this->assertSame([1, 0], [$y['hours'][8]['messages'], $y['hours'][8]['answered']]);
    }

    public function test_S_182_5_messages_refused_with_the_bot_off_are_shown_only_without_filters(): void
    {
        Cache::put('botcake-chat:refused:2026-10-21:bot_off', 2);
        Cache::put('gateway-events:2026-10-21:bot_off', 3);
        $s = app(ResponseSpeed::class);

        $this->assertSame(5, $s->output([])['refused_off']);
        $this->assertNull($s->output(['page_id' => '1002'])['refused_off']);
    }

    public function test_S_182_3_provider_split_and_filters(): void
    {
        $o = $this->out();
        $this->assertSame([2, 1], [$o['by_bot']['Forbeast']['messages'], $o['by_bot']['Forbeast']['answered']]);
        $this->assertSame([2, 2], [$o['by_bot']['Botcake']['messages'], $o['by_bot']['Botcake']['answered']]);

        $fb = $this->out(['bot' => 'Forbeast']);
        $this->assertSame([2, 1, 72], [$fb['hours'][10]['messages'], $fb['hours'][10]['answered'], $fb['hours'][10]['median']]);

        $page = $this->out(['page_id' => '1002']);
        $this->assertSame([1, 20], [$page['hours'][10]['messages'], $page['hours'][10]['median']]);
        $this->assertSame(2, $page['total']['messages']);
        $this->assertNull($this->out(['bot' => 'nope', 'page_id' => '1x'])['filters']['bot']);
    }

    public function test_S_182_4_hours_after_now_are_blank(): void
    {
        $o = $this->out();
        $this->assertNotNull($o['hours'][12]); // ang oras ngayon
        $this->assertSame(0, $o['hours'][11]['unanswered']);
        foreach (range(13, 23) as $h) $this->assertNull($o['hours'][$h], "hour $h");
        $this->assertSame(0, $o['hours'][3]['messages']);
        $this->assertNull($o['hours'][3]['median']);
    }

    public function test_S_182_5_chats_on_pages_off_the_bot_are_counted_apart(): void
    {
        $o = $this->out();
        $this->assertSame(1, $o['hours'][10]['not_on_bot']);
        $this->assertSame(1, $o['total']['not_on_bot']);
        $this->assertSame(1, $this->out(['page_id' => '1003'])['total']['not_on_bot']);
        $this->assertSame(0, $this->out(['page_id' => '1003'])['total']['messages']);
    }

    public function test_S_182_6_access_is_like_bot_health(): void
    {
        $this->actingAs($this->user('Data Encoder', 'enc@example.test'))->get('/chatbot/speed')->assertForbidden();

        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $html = $this->get('/chatbot/speed')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#href="http://localhost/chatbot/speed"[^>]*>\s*Speed#', $html); // nasa nav ng Chatbot
        $this->assertStringContainsString('id="sp-data"', $html);
    }

    public function test_S_182_8_the_table_scrolls_inside_its_box_on_a_phone(): void
    {
        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $html = $this->get('/chatbot/speed')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<div class="[^"]*overflow-x-auto[^"]*"[^>]*>\s*<table#', $html);
    }

    public function test_today_is_cached_two_minutes_and_past_days_longer(): void
    {
        Cache::flush();
        $s = app(ResponseSpeed::class);
        $this->assertSame(3, $s->output([])['hours'][10]['messages']);
        $this->chat('1002', '7010', '2026-10-21 10:20:00');
        $this->assertSame(3, $s->output([])['hours'][10]['messages']);
        $this->travel(121)->seconds();
        $this->assertSame(4, $s->output([])['hours'][10]['messages']);
        // Bawat filter ay may sariling cache.
        $this->assertSame(2, $s->output(['page_id' => '1002'])['hours'][10]['messages']);
        $this->assertSame(2, $s->output(['bot' => 'Forbeast'])['hours'][10]['messages']);
        $this->assertSame(120, ResponseSpeed::TODAY_CACHE_SECONDS);
        $this->assertGreaterThan(120, ResponseSpeed::PAST_CACHE_SECONDS);
    }
}
