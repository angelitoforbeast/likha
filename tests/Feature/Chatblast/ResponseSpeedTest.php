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

    /** Ang tawag sa AI ng takbo ng isang chat (created → answered). */
    private function ai(int $chat, string $at, ?string $answered, string $page = '1001', string $model = 'm'): void
    {
        DB::table('chatbot_ai_requests')->insert(['request_id' => uniqid('r', true), 'page_id' => (int) $page, 'psid' => 7002, 'chat_id' => $chat, 'flow_id' => 1,
            'node_id' => 'n3', 'page_name' => 'Page Uno', 'model' => $model, 'messages' => '[]', 'max_tokens' => 10, 'context' => '{}',
            'status' => $answered ? 'answered' : 'failed', 'attempts' => 1, 'deadline_at' => $at, 'answered_at' => $answered, 'created_at' => $at, 'updated_at' => $at]);
    }

    /** Ang chat na tinutugunan ng sagot (ang run ng sagot ay ang sa chat na ito), hindi ang standby na kopya. */
    private function linkReply(string $psid, string $replyAt): int
    {
        $chat = (int) DB::table('botcake_chats')->where('psid', $psid)->orderBy('id')->value('id');
        DB::table('chatbot_replies')->where('psid', (int) $psid)->where('sent_at', $replyAt)->update(['chat_id' => $chat]);

        return $chat;
    }

    public function test_S_182_9_each_hour_splits_replies_with_ai_from_flow_only_and_shows_the_ai_time(): void
    {
        // 7002 (Forbeast, 72 s): dalawang tawag sa AI ng takbo nito, 30 s + 18 s = 48 s. 7001 (Botcake, 20 s): flow lang.
        $chat = $this->linkReply('7002', '2026-10-21 10:06:10');
        $this->linkReply('7001', '2026-10-21 10:05:20');
        $this->ai($chat, '2026-10-21 10:05:02', '2026-10-21 10:05:32');
        $this->ai($chat, '2026-10-21 10:05:40', '2026-10-21 10:05:58');
        $this->ai($chat, '2026-10-21 10:05:40', '2026-10-21 10:09:00', model: \App\Services\OrderEncode\FlowEncode::MODEL); // hindi tawag sa AI

        $h = $this->out()['hours'][10];
        $this->assertSame(['n' => 1, 'avg' => 72.0, 'median' => 72, 'p90' => 72, 'max' => 72], $h['with_ai']);
        $this->assertSame(['n' => 1, 'avg' => 20.0, 'median' => 20, 'p90' => 20, 'max' => 20], $h['no_ai']);
        $this->assertSame(['avg' => 48.0, 'p90' => 48, 'max' => 48], $h['ai_time']);
        $this->assertSame(1, $this->out()['total']['with_ai']['n']);
    }

    public function test_S_182_10_max_is_shown_per_hour_and_for_the_day(): void
    {
        $o = $this->out();
        $this->assertSame([72, 5, 72], [$o['hours'][10]['max'], $o['hours'][11]['max'], $o['total']['max']]);
        $this->assertNull($o['hours'][3]['max']);

        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $this->assertStringContainsString('>Max<', $this->get('/chatbot/speed')->assertOk()->getContent());
    }

    /** Isang step ng sequence: ang event ng takbo (kailan due, kailan tumakbo) at ang padala nito. */
    private function step(int $seq, string $psid, string $op, string $due, string $ranAt, ?string $pushed = null, ?string $metaAt = null, string $page = '1001'): void
    {
        DB::table('chatbot_events')->insert(['page_id' => (int) $page, 'psid' => (int) $psid, 'type' => 'sequence', 'created_at' => $ranAt,
            'detail' => json_encode(['op' => $op, 'name' => 'Follow up', 'step' => 2, 'sequence_id' => $seq, 'due' => $due])]);
        if ($pushed) $this->reply($page, $psid, $pushed, ['sequence_id' => $seq, 'sequence_step' => 2]
            + ($metaAt ? ['confirmed_at' => $metaAt, 'confirmed_status' => 'sent'] : []));
    }

    public function test_S_182_11_sequence_steps_show_scheduled_to_pushed_and_pushed_to_meta_with_the_slowest(): void
    {
        $seq = DB::table('chatbot_sequences')->insertGetId(['name' => 'Follow up', 'active' => true, 'exit_on_reply' => true]);
        $this->step($seq, '7101', 'ran', '2026-10-21 10:00:00', '2026-10-21 10:01:02', '2026-10-21 10:01:00', '2026-10-21 10:03:00');
        $this->step($seq, '7102', 'ran', '2026-10-21 10:10:00', '2026-10-21 10:10:21', '2026-10-21 10:10:20', '2026-10-21 10:10:25');
        $this->step($seq, '7103', 'skipped', '2026-10-21 10:20:00', '2026-10-21 10:20:01');
        $this->step($seq, '7104', 'failed', '2026-10-21 11:00:00', '2026-10-21 11:00:01');
        $this->step($seq, '7105', 'ran', '2026-10-21 11:30:00', '2026-10-21 11:30:04', '2026-10-21 11:30:03', page: '1002'); // Botcake: sent = pushed

        $s = $this->out()['sequences'];
        $h = $s['hours'][10];
        $this->assertSame([3, 2, 0, 1], [$h['due'], $h['sent'], $h['failed'], $h['skipped']]);
        $this->assertSame(['n' => 2, 'avg' => 40.0, 'p90' => 60, 'max' => 60], $h['scheduled_to_pushed']);
        $this->assertSame(['n' => 2, 'avg' => 62.5, 'p90' => 120, 'max' => 120], $h['pushed_to_meta']);
        $this->assertSame([1, 1, 0], [$s['hours'][11]['failed'], $s['hours'][11]['botcake'], $s['hours'][11]['pushed_to_meta']['n']]); // Botcake: wala sa pushed → Meta
        $this->assertSame(2, $s['total']['pushed_to_meta']['n']);
        $this->assertSame(['n' => 3, 'max' => 60], array_intersect_key($s['total']['scheduled_to_pushed'], ['n' => 1, 'max' => 1]));
        foreach (range(13, 23) as $hr) $this->assertNull($s['hours'][$hr]);

        $slow = $s['slowest'][0];
        $this->assertSame(['Page Uno', 'Follow up', 2, '2026-10-21 10:00:00', '2026-10-21 10:01:00', '2026-10-21 10:03:00', 60, 120, 180],
            [$slow['page'], $slow['sequence'], $slow['step'], $slow['scheduled'], $slow['pushed'], $slow['sent'], $slow['scheduled_to_pushed'], $slow['pushed_to_meta'], $slow['delay']]);
        $this->assertSame([false, true], [$slow['sent_is_pushed'], collect($s['slowest'])->firstWhere('page', 'Page Dos')['sent_is_pushed']]);
        $this->assertSame(1, $this->out(['page_id' => '1002'])['sequences']['total']['due']);

        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $html = $this->get('/chatbot/speed')->assertOk()->getContent();
        $this->assertStringContainsString('data-table="sequences"', $html);
        $this->assertStringContainsString('data-table="sequence-slowest"', $html);
    }

    public function test_S_182_11_the_scheduled_time_is_the_original_due_not_the_last_retry(): void
    {
        // Step 1 ng "Follow up" ay 60 minuto pagkatapos ng subscribe (09:00) → due 10:00. Naghintay ito sa AI (retry kada
        // minuto), kaya ang next_send_at noong tumakbo ay 10:04; ang padala ay 10:05:00, ang Meta 10:05:10.
        $seq = DB::table('chatbot_sequences')->insertGetId(['name' => 'Follow up', 'active' => true, 'exit_on_reply' => true]);
        DB::table('chatbot_sequence_steps')->insert(['sequence_id' => $seq, 'position' => 1, 'delay_amount' => 60, 'delay_unit' => 'minutes', 'active' => true]);
        DB::table('chatbot_events')->insert(['page_id' => 1001, 'psid' => 7201, 'type' => 'sequence', 'created_at' => '2026-10-21 09:00:30',
            'detail' => json_encode(['op' => 'subscribed', 'name' => 'Follow up'])]);
        DB::table('chatbot_events')->insert(['page_id' => 1001, 'psid' => 7201, 'type' => 'sequence', 'created_at' => '2026-10-21 10:05:02',
            'detail' => json_encode(['op' => 'ran', 'name' => 'Follow up', 'step' => 1, 'sequence_id' => $seq, 'due' => '2026-10-21 10:04:00'])]);
        $this->reply('1001', '7201', '2026-10-21 10:05:00', ['sequence_id' => $seq, 'sequence_step' => 1, 'confirmed_at' => '2026-10-21 10:05:10', 'confirmed_status' => 'sent']);

        $s = $this->out()['sequences'];
        $this->assertSame(['n' => 1, 'avg' => 300.0, 'p90' => 300, 'max' => 300], $s['hours'][10]['scheduled_to_pushed']);
        $this->assertSame(['2026-10-21 10:00:00', 310], [$s['slowest'][0]['scheduled'], $s['slowest'][0]['delay']]);
    }

    // ── spec 185: ang mga chart ──────────────────────────────────────────────────────────────────────────────────────

    private ?object $ceo = null;

    /** Ang screen bilang CEO: ang data ng mga chart (ang JSON sa sp-data) at ang response (ang data ng mga table). */
    private function screen(string $query = ''): array
    {
        Cache::flush();
        $this->actingAs($this->ceo ??= $this->user('CEO', 'ceo-charts@example.test'));
        $res = $this->get('/chatbot/speed' . ($query !== '' ? '?' . $query : ''))->assertOk();
        $this->assertSame(1, preg_match('#<script type="application/json" id="sp-data">(.*?)</script>#s', $res->getContent(), $m));

        return [json_decode($m[1], true), $res];
    }

    /** Ang isang column ng isang table bilang serye ng chart: null ang oras na blangko (hindi pa naaabot). */
    private static function col(array $rows, callable $get): array
    {
        return array_map(fn ($r) => $r === null ? null : $get($r), $rows);
    }

    /** Ang mga sequence step ng 10:00 ngayon (pushed → Meta 120 s at 5 s) sa Page Uno. */
    private function seedSteps(): void
    {
        $seq = DB::table('chatbot_sequences')->insertGetId(['name' => 'Follow up', 'active' => true, 'exit_on_reply' => true]);
        $this->step($seq, '7101', 'ran', '2026-10-21 10:00:00', '2026-10-21 10:01:02', '2026-10-21 10:01:00', '2026-10-21 10:03:00');
        $this->step($seq, '7102', 'ran', '2026-10-21 10:10:00', '2026-10-21 10:10:21', '2026-10-21 10:10:20', '2026-10-21 10:10:25');
    }

    /** Ang AI ng sagot ng 7002 (Forbeast, 72 s): 30 s + 18 s; ang 7001 (Botcake, 20 s) ay flow lang. */
    private function seedAi(): void
    {
        $chat = $this->linkReply('7002', '2026-10-21 10:06:10');
        $this->linkReply('7001', '2026-10-21 10:05:20');
        $this->ai($chat, '2026-10-21 10:05:02', '2026-10-21 10:05:32');
        $this->ai($chat, '2026-10-21 10:05:40', '2026-10-21 10:05:58');
    }

    public function test_S_185_1_chart_1_has_average_median_and_p90_equal_to_the_hour_table(): void
    {
        [$c, $res] = $this->screen();
        $hours = $res->viewData('hours');

        $this->assertSame(['avg', 'median', 'p90'], array_keys($c['reply']['series']));
        foreach (['avg', 'median', 'p90'] as $k) $this->assertEquals(self::col($hours, fn ($r) => $r[$k]), $c['reply']['series'][$k], $k);
        $this->assertEquals([46, 46, 72], [$c['reply']['series']['avg'][10], $c['reply']['series']['median'][10], $c['reply']['series']['p90'][10]]);
        $this->assertNull($c['reply']['series']['avg'][3]);  // walang mensahe: puwang, hindi 0
        $this->assertNull($c['reply']['series']['avg'][13]); // hindi pa naaabot: puwang
        $this->assertSame(self::col($hours, fn ($r) => $r['answered']), $c['reply']['counts']['answered']);
        $this->assertStringContainsString('id="sp-chart-reply"', $res->getContent());
    }

    public function test_S_185_2_chart_2_with_ai_and_no_ai_series_equal_the_ai_table(): void
    {
        $this->seedAi();
        [$c, $res] = $this->screen();
        $hours = $res->viewData('hours');
        $s = $c['ai']['series'];

        $this->assertSame(['with_ai_avg', 'no_ai_avg', 'with_ai_median', 'no_ai_median', 'ai_time_avg'], array_keys($s));
        foreach (['with_ai', 'no_ai'] as $g) {
            $this->assertEquals(self::col($hours, fn ($r) => $r[$g]['avg']), $s[$g . '_avg'], $g);
            $this->assertEquals(self::col($hours, fn ($r) => $r[$g]['median']), $s[$g . '_median'], $g);
        }
        $this->assertEquals(self::col($hours, fn ($r) => $r['ai_time']['avg']), $s['ai_time_avg']);
        $this->assertEquals([72, 20, 72, 20, 48], [$s['with_ai_avg'][10], $s['no_ai_avg'][10], $s['with_ai_median'][10], $s['no_ai_median'][10], $s['ai_time_avg'][10]]);
        $this->assertNull($s['with_ai_avg'][11]); // 11:00 ay flow lang: walang punto ang With AI
        $this->assertSame([1, 1], [$c['ai']['counts']['with AI'][10], $c['ai']['counts']['no AI'][10]]);
        $this->assertStringContainsString('id="sp-chart-ai"', $res->getContent());
    }

    public function test_S_185_5_chart_4_matches_the_sequences_pushed_to_meta_columns(): void
    {
        $this->seedSteps();
        [$c, $res] = $this->screen();
        $rows = $res->viewData('sequences')['hours'];

        $this->assertSame(['avg', 'p90'], array_keys($c['seq']['series']));
        foreach (['avg', 'p90'] as $k) $this->assertEquals(self::col($rows, fn ($r) => $r['pushed_to_meta'][$k]), $c['seq']['series'][$k], $k);
        $this->assertEquals([62.5, 120], [$c['seq']['series']['avg'][10], $c['seq']['series']['p90'][10]]);
        $this->assertSame(self::col($rows, fn ($r) => $r['pushed_to_meta']['n']), $c['seq']['counts']['steps']);
        $this->assertStringContainsString('id="sp-chart-seq"', $res->getContent());
    }

    public function test_S_185_6_the_page_bot_and_day_filters_change_all_charts(): void
    {
        // Page Uno ay nasa Forbeast (ang Bot filter ng mga sequence ay ayon sa bot ng page).
        DB::table('page_settings')->insert(['page_name' => 'Page Uno', 'provider' => 'chatbot', 'key' => 'provider', 'value' => 'gateway', 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00']);
        $this->seedAi();
        $this->seedSteps();
        [$all] = $this->screen();

        foreach (['page_id=1002', 'bot=Botcake', 'date=2026-10-20'] as $q) {
            [$c, $res] = $this->screen($q);
            $hours = $res->viewData('hours');
            $this->assertEquals(self::col($hours, fn ($r) => $r['avg']), $c['reply']['series']['avg'], "$q reply");
            $this->assertEquals(self::col($hours, fn ($r) => $r['with_ai']['avg']), $c['ai']['series']['with_ai_avg'], "$q ai");
            $this->assertEquals(self::col($res->viewData('push')['hours'], fn ($r) => $r['avg']), $c['push']['series']['avg'], "$q push");
            $this->assertEquals(self::col($res->viewData('sequences')['hours'], fn ($r) => $r['pushed_to_meta']['avg']), $c['seq']['series']['avg'], "$q seq");
            foreach (['reply', 'ai', 'push', 'seq'] as $k) $this->assertNotEquals($all[$k]['series'], $c[$k]['series'], "$q changes $k");
        }
        [$fb] = $this->screen('bot=Forbeast');
        $this->assertSame([72, 72, 2, 120], [$fb['reply']['series']['avg'][10], $fb['ai']['series']['with_ai_avg'][10], $fb['push']['series']['max'][10], $fb['seq']['series']['p90'][10]]);
        [$bc] = $this->screen('bot=Botcake');
        $this->assertSame([], array_filter([...$bc['push']['series']['avg'], ...$bc['seq']['series']['avg']], fn ($v) => $v !== null));
    }

    public function test_S_185_7_access_as_before(): void
    {
        $this->actingAs($this->user('Data Encoder', 'enc@example.test'))->get('/chatbot/speed')->assertForbidden();
        $html = $this->actingAs($this->user('CEO', 'ceo@example.test'))->get('/chatbot/speed')->assertOk()->getContent();
        foreach (['reply', 'ai', 'push', 'seq'] as $k) $this->assertStringContainsString('id="sp-chart-' . $k . '"', $html);
    }

    public function test_S_185_9_each_chart_sits_with_its_table_and_fits_a_phone(): void
    {
        $html = $this->actingAs($this->user('CEO', 'ceo@example.test'))->get('/chatbot/speed')->assertOk()->getContent();
        // Spec 188: ang chart ay nasa itaas na ng sariling table (walang ibang table sa pagitan), sa kahong sumusunod sa
        // lapad ng screen.
        foreach (['hours' => 'reply', 'ai' => 'ai', 'push' => 'push', 'sequences' => 'seq'] as $table => $chart) {
            $this->assertMatchesRegularExpression('#<div class="relative h-\d+ w-full[^"]*">\s*<canvas id="sp-chart-' . $chart . '"(?:(?!data-table=).)*?data-table="' . $table . '"#s', $html, $chart);
        }
    }

    public function test_S_185_10_each_chart_has_the_previous_day_as_reference(): void
    {
        $this->seedAi();
        $this->seedSteps();
        // Kahapon (2026-10-20): 09:00 ay 3 s; 10:00 may sagot na 40 s, may AI (10 s), at sequence step na 30 s sa Meta.
        $this->chat('1001', '7401', '2026-10-20 10:00:00');
        $this->reply('1001', '7401', '2026-10-20 10:00:40', ['confirmed_at' => '2026-10-20 10:00:40', 'confirmed_status' => 'sent']);
        $this->ai($this->linkReply('7401', '2026-10-20 10:00:40'), '2026-10-20 10:00:10', '2026-10-20 10:00:20');
        $seq = (int) DB::table('chatbot_sequences')->value('id');
        $this->step($seq, '7402', 'ran', '2026-10-20 10:00:00', '2026-10-20 10:00:02', '2026-10-20 10:00:01', '2026-10-20 10:00:31');

        $check = function (string $query, string $prevDate): array {
            [$c] = $this->screen($query);
            $p = $this->out(['date' => $prevDate]);
            $this->assertSame($prevDate, $c['prev_date']);
            foreach (['avg', 'median', 'p90'] as $k) $this->assertEquals(self::col($p['hours'], fn ($r) => $r[$k]), $c['reply']['prev'][$k], "reply $k");
            foreach (['with_ai', 'no_ai'] as $g) foreach (['avg', 'median'] as $k) $this->assertEquals(self::col($p['hours'], fn ($r) => $r[$g][$k]), $c['ai']['prev'][$g . '_' . $k], "ai $g $k");
            $this->assertEquals(self::col($p['hours'], fn ($r) => $r['ai_time']['avg']), $c['ai']['prev']['ai_time_avg']);
            foreach (['avg', 'p90'] as $k) $this->assertEquals(self::col($p['sequences']['hours'], fn ($r) => $r['pushed_to_meta'][$k]), $c['seq']['prev'][$k], "seq $k");
            foreach (['avg', 'p90', 'max'] as $k) $this->assertEquals(self::col($p['push']['hours'], fn ($r) => $r[$k]), $c['push']['prev'][$k], "push $k");
            $this->assertSame(self::col($p['hours'], fn ($r) => $r['answered']), $c['reply']['prev_counts']['answered']);

            return $c;
        };
        $c = $check('', '2026-10-20');
        $this->assertEquals([40, 3], [$c['reply']['prev']['avg'][10], $c['reply']['prev']['avg'][9]]);
        $this->assertEquals([40, 10, 30], [$c['ai']['prev']['with_ai_avg'][10], $c['ai']['prev']['ai_time_avg'][10], $c['seq']['prev']['avg'][10]]);
        $this->assertSame(0, $c['reply']['prev_counts']['answered'][23]);
        // Ang "Yesterday" (2026-10-20) ay inihahambing sa 2026-10-19; ang filter ay sumasama sa reference.
        $y = $check('date=2026-10-20', '2026-10-19');
        $this->assertEquals(40, $y['reply']['series']['avg'][10]);
        $this->assertSame([], array_filter($y['reply']['prev']['avg'], fn ($v) => $v !== null));
        [$page] = $this->screen('page_id=1002');
        $this->assertEquals(3, $page['reply']['prev']['avg'][9]);
        $this->assertNull($page['reply']['prev']['avg'][10]); // ang 40 s ay sa Page Uno
        $this->assertStringContainsString('dotted = 2026-10-20', $this->get('/chatbot/speed')->getContent());
    }

    /** Ang mga padala ng bot noong 2026-10-20 10:00: tatlo sa Forbeast (3 s, 30 s, hindi pa kumpirmado) at isa sa Botcake. */
    private function seedPushes(): void
    {
        $this->reply('1001', '7301', '2026-10-20 10:00:00', ['confirmed_at' => '2026-10-20 10:00:03', 'confirmed_status' => 'sent']);
        $this->reply('1001', '7302', '2026-10-20 10:00:10', ['confirmed_at' => '2026-10-20 10:00:40', 'confirmed_status' => 'partial']);
        $this->reply('1001', '7303', '2026-10-20 10:00:20'); // hindi pa kumpirmado
        $this->reply('1002', '7304', '2026-10-20 10:00:30'); // Botcake: walang oras ng Meta
        // Hindi kasama: ang sequence step (nasa Sequences), ang test, ang pumalya sa Meta, at ang padala ng ibang araw.
        $this->reply('1001', '7305', '2026-10-20 10:00:40', ['sequence_id' => 5, 'sequence_step' => 1, 'confirmed_at' => '2026-10-20 10:09:00', 'confirmed_status' => 'sent']);
        $this->reply('1001', '7306', '2026-10-20 10:00:50', ['status' => 'test']);
        $this->reply('1001', '7307', '2026-10-20 10:00:55', ['status' => 'failed', 'confirmed_status' => 'failed']);
        $this->reply('1001', '7308', '2026-10-19 10:00:00', ['confirmed_at' => '2026-10-19 10:05:00', 'confirmed_status' => 'sent']);
    }

    public function test_S_185_3_replies_pushed_to_sent_at_meta_by_hour_with_botcake_apart(): void
    {
        $this->seedPushes();
        $p = $this->out(['date' => '2026-10-20'])['push'];
        $h = $p['hours'][10];

        $this->assertSame(10, $h['hour']);
        $this->assertSame([3, 2, 1, 1], [$h['pushed'], $h['measured'], $h['unconfirmed'], $h['botcake']]);
        $this->assertEquals(16.5, $h['avg']);
        $this->assertEquals(16.5, $h['median']);
        $this->assertSame([30, 30], [$h['p90'], $h['max']]);
        // Ang araw: dagdag ang sagot ng Botcake nang 09:00:03.
        $this->assertSame([3, 2, 1, 2], [$p['total']['pushed'], $p['total']['measured'], $p['total']['unconfirmed'], $p['total']['botcake']]);
        $this->assertSame([0, 0, 1], [$p['hours'][9]['pushed'], $p['hours'][9]['measured'], $p['hours'][9]['botcake']]);
        $this->assertNull($p['hours'][9]['avg']);
        // Ngayon: ang 10:06:10 ng Forbeast (kumpirmado 10:06:12) ay 2 s; ang mga oras pagkatapos ng ngayon ay blangko.
        $today = $this->out()['push'];
        $this->assertSame([1, 2], [$today['hours'][10]['measured'], $today['hours'][10]['max']]);
        foreach (range(13, 23) as $hr) $this->assertNull($today['hours'][$hr]);
        // Ang page filter.
        $this->assertSame([0, 1], [$this->out(['date' => '2026-10-20', 'page_id' => '1002'])['push']['hours'][10]['pushed'], $this->out(['date' => '2026-10-20', 'page_id' => '1002'])['push']['hours'][10]['botcake']]);

        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $html = $this->get('/chatbot/speed?date=2026-10-20')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-table="push".*?<tr data-hour="10">\s*<td[^>]*>10:00</td>\s*<td[^>]*>3</td>\s*<td[^>]*>2</td>\s*<td[^>]*>16\.5 s</td>#s', $html);
    }

    public function test_S_185_4_chart_3_series_equal_the_push_table(): void
    {
        $this->seedPushes();
        [$c, $res] = $this->screen('date=2026-10-20');
        $rows = $res->viewData('push')['hours'];

        $this->assertSame(['avg', 'p90', 'max'], array_keys($c['push']['series']));
        foreach (['avg', 'p90', 'max'] as $k) $this->assertEquals(self::col($rows, fn ($r) => $r[$k]), $c['push']['series'][$k], $k);
        $this->assertEquals([16.5, 30, 30], [$c['push']['series']['avg'][10], $c['push']['series']['p90'][10], $c['push']['series']['max'][10]]);
        $this->assertSame(self::col($rows, fn ($r) => $r['measured']), $c['push']['counts']['measured']);
        $this->assertSame(self::col($rows, fn ($r) => $r['unconfirmed']), $c['push']['counts']['not confirmed']);
        // Ang reference: ang 2026-10-19 (5 minuto sa Meta nang 10:00).
        $this->assertSame(300, $c['push']['prev']['max'][10]);
        $this->assertStringContainsString('id="sp-chart-push"', $res->getContent());
    }

    public function test_S_185_3_a_push_just_before_midnight_counts_in_hour_23_and_the_bot_filter_keeps_its_own_column(): void
    {
        $this->seedPushes();
        $this->reply('1001', '7309', '2026-10-20 23:59:58', ['confirmed_at' => '2026-10-21 00:00:04', 'confirmed_status' => 'sent']);

        $h = $this->out(['date' => '2026-10-20'])['push']['hours'][23];
        $this->assertSame([1, 1, 6], [$h['pushed'], $h['measured'], $h['max']]);
        $this->assertSame(0, $this->out()['push']['hours'][0]['pushed']); // hindi rin sa susunod na araw

        $fb = $this->out(['date' => '2026-10-20', 'bot' => 'Forbeast'])['push']['hours'][10];
        $this->assertSame([3, 2, 0], [$fb['pushed'], $fb['measured'], $fb['botcake']]);
        $bc = $this->out(['date' => '2026-10-20', 'bot' => 'Botcake'])['push']['hours'][10];
        $this->assertSame([0, 0, 1], [$bc['pushed'], $bc['measured'], $bc['botcake']]);
    }

    public function test_an_entry_cached_by_the_earlier_screen_is_not_read(): void
    {
        // Ang hugis bago ang 185 (walang push): ang lumang key ay hindi na binabasa pagkatapos ng deploy.
        $f = ResponseSpeed::filters([]);
        Cache::flush();
        Cache::put('response-speed:' . md5(json_encode($f)), ['filters' => $f, 'hours' => [], 'total' => [], 'by_bot' => [], 'sequences' => []], 120);

        $this->assertArrayHasKey('push', app(ResponseSpeed::class)->output([]));
    }

    /** Ang mga <details> ng screen: [key => [buong tag, laman]]. */
    private static function details(string $html): array
    {
        preg_match_all('#(<details\b[^>]*data-sp-table="([^"]+)"[^>]*>)(.*?)</details>#s', $html, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $d) $out[$d[2]] = [$d[1], $d[3]];

        return $out;
    }

    /** Ang buod sa ilalim ng heading ng isang section, bilang plain text. */
    private static function summary(string $html, string $key): string
    {
        preg_match('#<p\b[^>]*data-sp-summary="' . $key . '"[^>]*>(.*?)</p>#s', $html, $m);

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[1] ?? ''), ENT_QUOTES)));
    }

    public function test_S_188_1_the_charts_show_and_every_table_is_in_a_closed_details(): void
    {
        $this->seedSteps();
        $html = $this->actingAs($this->user('CEO', 'ceo@example.test'))->get('/chatbot/speed')->assertOk()->getContent();
        $d = self::details($html);

        $this->assertSame(['hours', 'ai', 'push', 'sequences', 'sequence-slowest'], array_keys($d));
        foreach ($d as $key => [$tag, $body]) {
            $this->assertDoesNotMatchRegularExpression('#\sopen\b#', $tag, "$key is closed by default");
            $this->assertStringContainsString('data-table="' . $key . '"', $body, "$key table is inside its details");
            $this->assertMatchesRegularExpression('#<summary\b[^>]*>.*Show .*Hide .*</summary>#s', $body, "$key has Show / Hide");
        }
        // Wala nang table sa labas ng mga details; ang apat na chart ay nasa labas, nakikita.
        $outside = preg_replace('#<details\b.*?</details>#s', '', $html);
        $this->assertStringNotContainsString('<table', $outside);
        foreach (['reply', 'ai', 'push', 'seq'] as $k) $this->assertStringContainsString('<canvas id="sp-chart-' . $k . '"', $outside);
        // Ang heading, ang buod at ang chart ay nauuna sa table ng bawat section.
        foreach (['hours' => ['h-hours', 'reply'], 'ai' => ['h-ai', 'ai'], 'push' => ['h-push', 'push'], 'sequences' => ['h-seq', 'seq']] as $table => [$h, $chart]) {
            $this->assertMatchesRegularExpression('#id="' . $h . '".*?data-sp-summary="' . $table . '".*?<canvas id="sp-chart-' . $chart . '".*?<details\b[^>]*data-sp-table="' . $table . '"#s', $html, $table);
        }
        // Ang bukas o sarado ay naaalala ng browser (localStorage, sa loob ng try/catch).
        $this->assertMatchesRegularExpression('#try\s*\{[^}]*localStorage#', $html);
    }

    public function test_S_188_2_the_summary_line_shows_each_tables_day_totals(): void
    {
        $this->seedAi();
        $this->seedSteps();
        $res = $this->actingAs($this->user('CEO', 'ceo@example.test'))->get('/chatbot/speed')->assertOk();
        $html = $res->getContent();
        $sec = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.') . ' s';

        $this->assertSame('Day: 4 messages · 3 answered · avg 32.3 s · median 20 s · p90 72 s · max 72 s', self::summary($html, 'hours'));

        $t = $res->viewData('total');
        $g = fn ($k, $label) => "$label {$t[$k]['n']} answered · avg {$sec($t[$k]['avg'])} · median {$sec($t[$k]['median'])} · p90 {$sec($t[$k]['p90'])} · max {$sec($t[$k]['max'])}";
        $this->assertSame('Day: ' . $g('with_ai', 'with AI') . '; ' . $g('no_ai', 'no AI') . "; AI time avg {$sec($t['ai_time']['avg'])} · p90 {$sec($t['ai_time']['p90'])} · max {$sec($t['ai_time']['max'])}", self::summary($html, 'ai'));
        $this->assertStringStartsWith('Day: with AI 1 answered · avg 72 s', self::summary($html, 'ai'));
        $this->assertStringEndsWith('AI time avg 48 s · p90 48 s · max 48 s', self::summary($html, 'ai'));

        $p = $res->viewData('push')['total'];
        $this->assertSame("Day: {$p['pushed']} pushed (Forbeast) · {$p['measured']} measured · avg {$sec($p['avg'])} · median {$sec($p['median'])} · p90 {$sec($p['p90'])} · max {$sec($p['max'])} · {$p['unconfirmed']} not confirmed yet · {$p['botcake']} Botcake (no Meta time)", self::summary($html, 'push'));
        $this->assertStringContainsString('measured · avg 2 s', self::summary($html, 'push'));

        $s = $res->viewData('sequences')['total'];
        $this->assertSame("Day: {$s['due']} due · {$s['sent']} sent · {$s['failed']} failed · {$s['skipped']} skipped; scheduled → pushed avg {$sec($s['scheduled_to_pushed']['avg'])} · p90 {$sec($s['scheduled_to_pushed']['p90'])} · max {$sec($s['scheduled_to_pushed']['max'])}; pushed → sent at Meta: {$s['pushed_to_meta']['n']} steps · avg {$sec($s['pushed_to_meta']['avg'])} · p90 {$sec($s['pushed_to_meta']['p90'])} · max {$sec($s['pushed_to_meta']['max'])}", self::summary($html, 'sequences'));
        $this->assertStringEndsWith('2 steps · avg 62.5 s · p90 120 s · max 120 s', self::summary($html, 'sequences'));
    }

    public function test_S_188_3_opening_a_table_shows_the_same_table_with_its_notes(): void
    {
        $this->seedSteps();
        $html = $this->actingAs($this->user('CEO', 'ceo@example.test'))->get('/chatbot/speed')->assertOk()->getContent();
        $d = self::details($html);

        // Ang table ay ang dati (sa kahong nag-i-scroll, may Day na row), at ang paliwanag nito ay kasama sa loob.
        $notes = ['hours' => 'Hour = when the customer', 'ai' => 'With AI = the first reply', 'push' => "The bot's replies on Forbeast", 'sequences' => 'Scheduled = when the step was due'];
        foreach ($notes as $key => $note) {
            $this->assertMatchesRegularExpression('#<div class="cb-card overflow-x-auto">\s*<table class="cb-table min-w-\[\d+rem\]" data-table="' . $key . '">#', $d[$key][1], $key);
            $this->assertMatchesRegularExpression('#<tfoot>\s*<tr class="font-semibold"[^>]*><td>Day</td>#', $d[$key][1], "$key Day row");
            $this->assertStringContainsString($note, html_entity_decode($d[$key][1], ENT_QUOTES), "$key notes");
        }
        $this->assertMatchesRegularExpression('#data-table="hours".*<tr data-hour="10">\s*<td[^>]*>10:00</td>\s*<td[^>]*>3</td>\s*<td[^>]*>2</td>#s', $d['hours'][1]);
        $this->assertStringContainsString('Follow up', $d['sequence-slowest'][1]);
    }

    public function test_S_188_6_only_the_average_series_are_shown_by_default(): void
    {
        $this->seedAi();
        $this->seedSteps();
        [$c, $res] = $this->screen();

        // Ang average lang ang nakikita sa simula; ang iba ay nasa legend pero nakatago (pati ang sa naunang araw).
        $this->assertSame(['avg'], $c['reply']['shown']);
        $this->assertSame(['with_ai_avg', 'no_ai_avg'], $c['ai']['shown']);
        $this->assertSame(['avg'], $c['push']['shown']);
        $this->assertSame(['avg'], $c['seq']['shown']);
        foreach (['reply', 'ai', 'push', 'seq'] as $k) $this->assertSame([], array_diff($c[$k]['shown'], array_keys($c[$k]['series'])), $k);
        // Ang chart script ang gumagamit nito: nakatago ang serye na wala sa shown.
        $this->assertStringContainsString('hidden: !c.shown.includes(key)', $res->getContent());
    }

    public function test_S_188_7_the_axis_top_is_about_three_times_the_typical_average_and_a_spike_is_clamped(): void
    {
        $day = fn (array $set) => array_replace(array_fill(0, 24, null), $set);
        // Karaniwang 6 s kada oras at isang oras na 363 s: ang taas ay 3 × 6 = 18 → 20 s; ang 363 ay lampas (iginuguhit sa taas).
        $spike = $day(array_fill(0, 10, 6) + [10 => 363]);
        $this->assertSame(20, ResponseSpeed::axisTop($spike, []));
        // Kaunti pa ang oras ngayon (wala pang 3): ang naunang araw ang batayan.
        $this->assertSame(100, ResponseSpeed::axisTop($day([0 => 2, 1 => 500]), $day(array_fill(0, 8, 30))));
        // Hindi bababa sa 10 s; walang data: 10 s.
        $this->assertSame(10, ResponseSpeed::axisTop($day(array_fill(0, 12, 1)), []));
        $this->assertSame(10, ResponseSpeed::axisTop($day([]), $day([])));
        // Malinis na numero pataas: 3 × 41 = 123 → 150.
        $this->assertSame(150, ResponseSpeed::axisTop($day(array_fill(0, 5, 41)), []));

        // Ang screen: kada chart ay may taas mula sa (mga) average nito. Ngayon: 46 s at 5 s (2 oras; ang kahapon ay 1 oras
        // lang kaya ngayon pa rin) → median 25.5 × 3 = 76.5 → 80 s.
        [$c, $res] = $this->screen();
        $this->assertSame(80, $c['reply']['top']);
        foreach (['reply', 'ai', 'push', 'seq'] as $k) $this->assertGreaterThanOrEqual(10, $c[$k]['top'], $k);
        // Ang chart script: ang taas ang max ng axis, ang lampas ay iginuguhit sa taas na may tunay na value bilang label.
        $this->assertMatchesRegularExpression('#max:\s*c\.top#', $res->getContent());
        $this->assertStringContainsString('Math.min(v, c.top)', $res->getContent());
    }
}
