<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\LiveFeed;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Spec 196, /chatbot/live bilang scoreboard sa TV: input → process → output → orders, kada bot. Ngayon ay
 * 2026-10-21 12:30 (Manila); ang huling 5 minuto ay mula 12:25. Page Uno (1001) ay sa Forbeast, Page Dos (1002) sa
 * Incepxionbot, Page Tres (1003) sa Botcake.
 */
class ChatbotLiveScoreboardTest extends ChatblastTestCase
{
    private const SPEED = 'https://forbeast.example.test/api/v1/site/reply-speed*';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
            'database/migrations/2026_11_11_100000_add_real_send_to_chatbot_replies.php',
            'database/migrations/2026_11_13_100000_widen_via_on_chatbot_replies.php',
        ]]);
        $this->travelTo('2026-10-21 12:30:00');
        foreach (['Page Uno' => '1001', 'Page Dos' => '1002', 'Page Tres' => '1003'] as $name => $id) $this->page($name, $id);
        config(['services.gateway.url' => 'https://forbeast.example.test/api', 'services.forbeast_site.key' => 'fbs_test_key',
            'services.forbeast_site.secret' => 'site-secret', 'services.incepxionbot.url' => 'https://ixb.example.test']);
        $this->seedDay();
        Cache::flush();
    }

    private function chat(string $page, string $psid, string $at, ?array $payload): int
    {
        return DB::table('botcake_chats')->insertGetId(['page_id' => $page, 'page_name' => 'x', 'psid' => $psid, 'name' => 'Juan Dela Cruz',
            'message' => 'Magkano po?', 'received_at' => $at, 'payload' => $payload === null ? null : json_encode($payload)]);
    }

    private function reply(string $page, string $via, string $at, array $extra = []): int
    {
        return DB::table('chatbot_replies')->insertGetId($extra + ['page_id' => (int) $page, 'psid' => 7001, 'node_id' => 'n2', 'kind' => 'send',
            'content' => '[]', 'status' => 'sent', 'sent_at' => $at, 'via' => $via, 'confirmed_at' => $at, 'confirmed_status' => 'sent']);
    }

    private function botOrder(string $page, string $at, string $via): int
    {
        return DB::table('chatbot_events')->insertGetId(['page_id' => (int) $page, 'psid' => 7001, 'type' => 'order', 'detail' => json_encode(['via' => $via]), 'created_at' => $at]);
    }

    private function seedDay(): void
    {
        // Pasok, may marka ng pinagmulan: Forbeast 3, Incepxionbot 2, Botcake 1.
        $this->chat('1001', '7001', '2026-10-21 10:00:00', ['source' => 'gateway', '_via' => 'forbeast']);
        $this->chat('1001', '7002', '2026-10-21 12:27:00', ['source' => 'gateway', '_via' => 'forbeast']);
        $this->chat('1001', '7002', '2026-10-21 12:28:00', ['source' => 'gateway', '_via' => 'forbeast']);
        $this->chat('1002', '7003', '2026-10-21 11:00:00', ['source' => 'gateway', 'gateway' => 'incepxionbot', '_via' => 'incepxionbot']);
        $this->chat('1002', '7010', '2026-10-21 12:29:00', ['source' => 'gateway', 'gateway' => 'incepxionbot', '_via' => 'incepxionbot']);
        $this->chat('1003', '7020', '2026-10-21 12:26:00', ['_via' => 'botcake']);
        // Bago nagkaroon ng marka: ayon sa dating payload (gateway / gateway na incepxionbot / Botcake); walang payload = unknown.
        $this->chat('1001', '7004', '2026-10-21 09:00:00', ['source' => 'gateway']);
        $this->chat('1002', '7005', '2026-10-21 09:10:00', ['source' => 'gateway', 'gateway' => 'incepxionbot']);
        $this->chat('1003', '7006', '2026-10-21 09:20:00', ['source' => 'default_reply']);
        $this->chat('1003', '7007', '2026-10-21 09:30:00', null);
        // Hindi binibilang: ang standby na kopya at kahapon.
        $this->chat('1001', '7002', '2026-10-21 12:27:00', ['_standby' => true, '_via' => 'botcake']);
        $this->chat('1001', '7009', '2026-10-20 23:59:00', ['_via' => 'forbeast']);

        // Labas (sagot na kumpirmado ng Meta): Forbeast 2 (+1 hinihintay pa), Incepxionbot 3, Botcake 1.
        $this->reply('1001', 'forbeast', '2026-10-21 10:00:20');
        $this->reply('1001', 'forbeast', '2026-10-21 12:27:10');
        $this->reply('1001', 'forbeast', '2026-10-21 12:29:00', ['confirmed_at' => null, 'confirmed_status' => null]);
        $this->reply('1002', 'incepxionbot', '2026-10-21 11:00:05');
        $this->reply('1002', 'incepxionbot', '2026-10-21 12:29:05');
        $this->reply('1002', 'incepxionbot', '2026-10-21 12:29:30');
        $this->reply('1003', 'botcake', '2026-10-21 12:26:10');
        // Hindi labas: ang follow-up (sequence), ang pumalya at kahapon.
        $this->reply('1001', 'forbeast', '2026-10-21 11:30:00', ['sequence_id' => 5, 'sequence_step' => 1]);
        $this->reply('1002', 'incepxionbot', '2026-10-21 12:20:00', ['status' => 'failed', 'confirmed_status' => 'failed']);
        $this->reply('1002', 'incepxionbot', '2026-10-20 22:00:00');

        // Order: 2 ngayon; ang huli ay Page Dos 12:29:30.
        $this->botOrder('1001', '2026-10-21 11:30:00', 'forbeast');
        $this->botOrder('1002', '2026-10-21 12:29:30', 'incepxionbot');
    }

    private function fakeSpeed(mixed $answer): void
    {
        Http::fake([self::SPEED => $answer]);
    }

    private static function speedDay(?float $avg): array
    {
        $total = ['messages' => 40, 'bot_answered' => 38, 'person_answered' => 2, 'unanswered' => 0, 'avg_s' => $avg, 'median_s' => 2, 'p90_s' => 4, 'max_s' => 9, 'meta_to_forbeast_avg_s' => 1.1];

        return ['date' => '2026-10-21', 'page_id' => null, 'timezone' => 'Asia/Manila', 'hours' => [['hour' => 10] + $total], 'total' => $total, 'generated_at' => '2026-10-21T04:30:00Z'];
    }

    private function counts(?string $page = null): array
    {
        return app(LiveFeed::class)->counts($page);
    }

    public function test_S_196_1_the_four_big_numbers_and_the_per_bot_input_and_output_are_todays_real_counts(): void
    {
        $this->fakeSpeed(Http::response(self::speedDay(2.4)));
        $c = $this->counts();

        $this->assertSame(['today' => 6, 'recent' => 4], $c['confirmed']); // Replies today
        $this->assertSame(['state' => 'ok', 'seconds' => 2.4], $c['reply_time']);
        $this->assertSame(['today' => 2, 'recent' => 1], $c['orders']);
        $this->assertSame(3, $c['active']); // 1001/7002, 1002/7010, 1003/7020
        $this->assertSame(['forbeast' => 4, 'incepxionbot' => 3, 'botcake' => 2, 'unknown' => 1], $c['bots']['in']);
        $this->assertSame(['forbeast' => 2, 'incepxionbot' => 3, 'botcake' => 1, 'unknown' => 0], $c['bots']['out']);
        $this->assertSame(['today' => 10, 'recent' => 4], $c['messages']);
        $this->assertSame(['page' => 'Page Dos', 'at' => '12:29', 'bot' => 'incepxionbot'], $c['last_order']);
        $this->assertSame(['forbeast' => true, 'incepxionbot' => true], $c['linked']);

        // Ang page: ang apat na malaking numero at ang mga hanay ng bawat bot, mula sa parehong bilang.
        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $html = $this->get('/chatbot/live')->assertOk()->getContent();
        foreach (['replies' => '6', 'reply_time' => '2.4', 'orders' => '2', 'active' => '3', 'in-forbeast' => '4', 'in-incepxionbot' => '3',
            'in-botcake' => '2', 'out-forbeast' => '2', 'out-incepxionbot' => '3', 'out-botcake' => '1', 'ai' => '0', 'follow_ups' => '1'] as $key => $n) {
            $this->assertMatchesRegularExpression('#data-live-count="' . $key . '"[^>]*>' . preg_quote($n, '#') . '<#', $html, $key);
        }
        foreach (['INQUIRIES', 'LIKHA', 'AI CORE', 'FOLLOW-UP', 'ORDERS', 'BOT 1 · FORBEAST', 'BOT 2 · INCEPXIONBOT', 'BOTCAKE · FALLBACK', 'NEURAL MAP', 'Replies today', 'Avg reply time', 'Orders today', 'Active chats', 'LATEST'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringContainsString('Page Dos · 12:29 · Bot 2', $html); // ang huling order
        // Ang neural map: ang path ng bawat bot papunta sa core, at ng core papunta sa Follow-up at Orders (malapad at makitid).
        foreach (['forbeast', 'incepxionbot', 'botcake', 'fu', 'ord'] as $path) $this->assertSame(2, substr_count($html, 'data-path="' . $path . '"'), $path);
    }

    public function test_S_196_1_on_incepxion_the_title_and_core_carry_the_site_name(): void
    {
        $this->fakeSpeed(Http::response(self::speedDay(2.4)));
        config(['app.url' => 'https://incepxion.pro']);
        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $html = $this->get('/chatbot/live')->assertOk()->getContent();

        $this->assertStringContainsString('<h1>INCEPXION LIVE</h1>', $html);
        $this->assertMatchesRegularExpression('#class="nm-core-name"[^>]*>INCEPXION<#', $html);
        $this->assertStringNotContainsString('LIKHA', $html);
    }

    public function test_S_196_2_replies_sent_through_incepxionbot_land_in_bot_2s_row_and_events_carry_the_bot(): void
    {
        $this->fakeSpeed(Http::response(self::speedDay(2.4)));
        $before = $this->counts()['bots']['out'];
        $cursor = app(LiveFeed::class)->feed([])['cursor'];

        $this->travelTo('2026-10-21 12:30:20');
        $this->reply('1002', 'incepxionbot', '2026-10-21 12:30:05');
        $this->chat('1002', '7011', '2026-10-21 12:30:06', ['source' => 'gateway', 'gateway' => 'incepxionbot', '_via' => 'incepxionbot']);
        $this->chat('1003', '7021', '2026-10-21 12:30:07', ['_via' => 'botcake']);
        $this->botOrder('1002', '2026-10-21 12:30:08', 'incepxionbot');
        Cache::flush();

        $out = app(LiveFeed::class)->feed(['cursor' => $cursor]);
        $this->assertSame(array_replace($before, ['incepxionbot' => $before['incepxionbot'] + 1]), $out['counts']['bots']['out']);
        $this->assertSame([
            ['confirmed', 'Page Dos', 'incepxionbot'],
            ['message', 'Page Dos', 'incepxionbot'],
            ['message', 'Page Tres', 'botcake'],
            ['order', 'Page Dos', 'incepxionbot'],
        ], array_map(fn ($e) => [$e['type'], $e['page'], $e['bot']], $out['events']));
        foreach ($out['events'] as $e) $this->assertSame(['type', 'at', 'page', 'id', 'bot'], array_keys($e));
    }

    public function test_S_196_4_the_counters_come_from_a_5_s_shared_cache_and_a_poll_never_rescans_the_day(): void
    {
        $this->fakeSpeed(Http::response(self::speedDay(2.4)));
        $this->counts(); // ang unang bilang: ang buong araw, isang beses

        $queries = [];
        DB::listen(function ($q) use (&$queries) { $queries[] = [$q->sql, $q->bindings]; });
        $this->travel(4)->seconds();
        $this->counts();
        $this->counts('1001'); // ibang filter: sariling cache (unang bilang nito)
        $n = count($queries);
        $this->counts();
        $this->counts('1001');
        $this->assertSame($n, count($queries), 'within 5 s every viewer gets the cached counters');
        $countQueries = function () use (&$queries) { return array_values(array_filter($queries, fn ($q) => preg_match('/\b(sum|count)\(/i', $q[0]))); };

        // Pagkatapos ng 5 s: kinukuwenta ulit, pero ang mga row lang mula sa huling hangganan (walang buong araw).
        $queries = [];
        $this->travel(11)->seconds();
        $c = $this->counts();
        $this->assertSame(['today' => 6, 'recent' => 4], $c['confirmed']);
        $this->assertNotEmpty($countQueries());
        foreach ($countQueries() as [$sql, $bindings]) {
            $this->assertNotContains('2026-10-21 00:00:00', $bindings, 'a poll does not count from the start of the day: ' . $sql);
        }

        // Ang running total ay sumusulong: ang bagong row sa naayos nang bahagi ay binibilang nang isang beses.
        $this->chat('1001', '7030', '2026-10-21 12:30:16', ['source' => 'gateway', '_via' => 'forbeast']);
        $this->travelTo('2026-10-21 12:50:00');
        $this->assertSame(5, $this->counts()['bots']['in']['forbeast']);
        $this->travel(6)->seconds();
        $this->travel(5)->seconds();
        $this->assertSame(5, $this->counts()['bots']['in']['forbeast']);
        $this->travelTo('2026-10-21 13:20:00');
        $this->assertSame(['forbeast' => 5, 'incepxionbot' => 3, 'botcake' => 2, 'unknown' => 1], $this->counts()['bots']['in']);
        $this->assertSame(['today' => 6, 'recent' => 0], $this->counts()['confirmed']);

        // Bagong araw: mula sa simula.
        $this->travelTo('2026-10-22 00:00:30');
        $this->assertSame(['forbeast' => 0, 'incepxionbot' => 0, 'botcake' => 0, 'unknown' => 0], $this->counts()['bots']['in']);
    }

    public function test_S_196_5_forbeast_down_or_not_linked_shows_a_dash_and_the_rest_works(): void
    {
        $this->fakeSpeed(Http::response(['error' => 'boom'], 500));
        $c = $this->counts();
        $this->assertSame(['state' => 'error', 'seconds' => null], $c['reply_time']);
        $this->assertSame(['today' => 6, 'recent' => 4], $c['confirmed']);

        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        Cache::flush();
        $html = $this->get('/chatbot/live')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-live-count="reply_time"[^>]*>—<#', $html);
        $this->assertStringContainsString('Forbeast did not answer', $html);
        $this->assertMatchesRegularExpression('#data-live-count="replies"[^>]*>6<#', $html);

        // Walang Forbeast (gaya ng Incepxion): "—", walang tawag, at ang hanay ng Bot 1 ay "not linked".
        Http::fake();
        config(['services.gateway.url' => '', 'services.forbeast_site.key' => '', 'services.forbeast_site.secret' => '']);
        Cache::flush();
        $c = $this->counts();
        $this->assertSame(['state' => 'not_linked', 'seconds' => null], $c['reply_time']);
        $this->assertSame(['forbeast' => false, 'incepxionbot' => true], $c['linked']);
        Http::assertNothingSent();
        $html = $this->get('/chatbot/live')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-bot-row="forbeast"[^>]*data-linked="0"#', $html);
        $this->assertStringContainsString('not linked', $html);
    }

    public function test_S_196_5_a_page_not_on_forbeast_or_a_timeout_also_shows_a_dash_with_the_right_note(): void
    {
        $this->fakeSpeed(Http::response(['error' => 'unknown_page'], 404));
        $this->assertSame(['state' => 'not_on_forbeast', 'seconds' => null], $this->counts('1003')['reply_time']);
        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        Cache::flush();
        $this->assertStringContainsString('This page is not on Forbeast', $this->get('/chatbot/live?page_id=1003')->assertOk()->getContent());

        Cache::flush();
        Http::fake([self::SPEED => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out')]);
        $c = $this->counts();
        $this->assertSame(['state' => 'error', 'seconds' => null], $c['reply_time']);
        $this->assertSame(6, $c['confirmed']['today']);
    }

    public function test_S_196_4_a_late_confirmation_counts_once_after_the_settled_part_is_recounted(): void
    {
        $this->fakeSpeed(Http::response(self::speedDay(2.4)));
        // Ipinadala 12:00, kumpirmado lang ng Meta pagkalipas ng 20 minuto (lampas na sa naayos na hangganan).
        $late = $this->reply('1001', 'forbeast', '2026-10-21 12:00:00', ['confirmed_at' => null, 'confirmed_status' => null]);
        $this->travelTo('2026-10-21 12:40:00');
        $this->assertSame(['forbeast' => 2, 'incepxionbot' => 3, 'botcake' => 1, 'unknown' => 0], $this->counts()['bots']['out']);
        DB::table('chatbot_replies')->where('id', $late)->update(['confirmed_at' => '2026-10-21 12:20:00', 'confirmed_status' => 'sent']);

        $this->travelTo('2026-10-21 13:20:00'); // lampas 30 minuto mula sa huling buong bilang
        $this->assertSame(['forbeast' => 3, 'incepxionbot' => 3, 'botcake' => 1, 'unknown' => 0], $this->counts()['bots']['out']);
        $this->travel(11)->seconds();
        $this->assertSame(['today' => 7, 'recent' => 0], $this->counts()['confirmed']);
    }

    public function test_S_196_6_tv_mode_hides_the_navigation_and_banners_with_a_small_exit_link(): void
    {
        $this->fakeSpeed(Http::response(self::speedDay(2.4)));
        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $html = $this->get('/chatbot/live')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-app-switch', $html);
        $this->assertStringNotContainsString('data-cb-nav=', $html);
        $this->assertStringNotContainsString('data-alert="problems"', $html);
        $this->assertStringNotContainsString('cdn.tailwindcss.com', $html);
        $this->assertMatchesRegularExpression('#<a [^>]*href="http://localhost/chatbot/speed"[^>]*data-tv-exit#', $html);
        $this->assertStringContainsString('data-tv', $html);
    }

    public function test_S_196_7_the_layout_reflows_at_phone_width_without_sideways_scroll(): void
    {
        $this->fakeSpeed(Http::response(self::speedDay(2.4)));
        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $html = $this->get('/chatbot/live')->assertOk()->getContent();
        // Ang malapad na ayos ay sa 901 px pataas lang; sa ilalim nito ay isang column, at walang lumalampas sa lapad.
        $this->assertStringContainsString('@media (max-width: 900px)', $html);
        $this->assertStringContainsString('overflow-x: hidden', $html);
        $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $html);
    }
}
