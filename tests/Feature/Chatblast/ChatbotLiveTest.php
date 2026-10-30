<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\LiveFeed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Spec 190, /chatbot/live: ang animated na mapa ng ginagawa ng bot ngayon. Ngayon ay 2026-10-21 12:30 (Manila); ang huling
 * 5 minuto ay mula 12:25. Page Uno (1001) ay Forbeast, Page Dos (1002) ay Botcake.
 */
class ChatbotLiveTest extends ChatblastTestCase
{
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
        ]]);
        $this->travelTo('2026-10-21 12:30:00');
        foreach (['Page Uno' => '1001', 'Page Dos' => '1002'] as $name => $id) $this->page($name, $id);
        $this->seedDay();
    }

    private function chat(string $page, string $psid, string $at, array $payload = []): int
    {
        return DB::table('botcake_chats')->insertGetId(['page_id' => $page, 'page_name' => 'x', 'psid' => $psid, 'name' => 'Juan Dela Cruz',
            'message' => 'Magkano po ito?', 'received_at' => $at, 'payload' => json_encode($payload)]);
    }

    private function reply(string $page, string $at, array $extra = []): int
    {
        $via = $page === '1001' ? 'forbeast' : 'botcake';

        return DB::table('chatbot_replies')->insertGetId($extra + ['page_id' => (int) $page, 'psid' => 7001, 'node_id' => 'n2', 'kind' => 'send',
            'content' => json_encode([['type' => 'text', 'text' => 'Hello Juan Dela Cruz']]), 'status' => 'sent', 'sent_at' => $at, 'via' => $via]
            + ($via === 'botcake' ? ['confirmed_at' => $at, 'confirmed_status' => 'sent'] : []));
    }

    private function ai(string $page, string $at, ?string $answered, array $extra = []): int
    {
        return DB::table('chatbot_ai_requests')->insertGetId($extra + ['request_id' => (string) \Illuminate\Support\Str::uuid(), 'page_id' => (int) $page, 'psid' => 7001,
            'chat_id' => 1, 'flow_id' => 1, 'node_id' => 'n3', 'page_name' => 'x', 'model' => 'gpt-test', 'messages' => '[]', 'max_tokens' => 100,
            'context' => '{}', 'status' => $answered === null ? 'pending' : 'answered', 'deadline_at' => $at, 'answered_at' => $answered,
            'answer' => 'Ang sagot kay Juan', 'created_at' => $at, 'updated_at' => $at]);
    }

    private function botOrder(string $page, string $at, string $type = 'order'): int
    {
        return DB::table('chatbot_events')->insertGetId(['page_id' => (int) $page, 'psid' => 7001, 'type' => $type, 'detail' => '{}', 'created_at' => $at]);
    }

    private function seedDay(): void
    {
        // Mensahe: 3 ngayon (2 sa huling 5 minuto); hindi ang standby na kopya at ang kahapon.
        $this->chat('1001', '7001', '2026-10-21 10:00:00');
        $this->chat('1001', '7002', '2026-10-21 12:27:00');
        $this->chat('1002', '7003', '2026-10-21 12:28:00');
        $this->chat('1001', '7002', '2026-10-21 12:27:00', ['_standby' => true]);
        $this->chat('1001', '7009', '2026-10-20 23:59:00');
        // Sagot ng bot: 3 kumpirmado (2 sa huling 5 minuto), 1 naipadala pero hindi pa kumpirmado; hindi ang pumalya, test o manual.
        $this->reply('1001', '2026-10-21 10:00:20', ['confirmed_at' => '2026-10-21 10:00:22', 'confirmed_status' => 'sent']);
        $this->reply('1001', '2026-10-21 12:27:10', ['confirmed_at' => '2026-10-21 12:27:12', 'confirmed_status' => 'sent']);
        $this->reply('1002', '2026-10-21 12:28:05');
        $this->reply('1001', '2026-10-21 12:29:00');
        $this->reply('1001', '2026-10-21 12:29:10', ['status' => 'failed', 'confirmed_status' => 'failed']);
        $this->reply('1002', '2026-10-21 12:29:20', ['status' => 'test']);
        $this->reply('1002', '2026-10-21 12:29:30', ['kind' => 'manual']);
        $this->reply('1002', '2026-10-20 23:00:00'); // kahapon
        // Follow-up (sequence step na naipadala): 2 (1 sa huling 5 minuto); hindi ang pumalya.
        $this->reply('1001', '2026-10-21 11:00:00', ['sequence_id' => 5, 'sequence_step' => 1, 'confirmed_at' => '2026-10-21 11:00:02', 'confirmed_status' => 'sent']);
        $this->reply('1002', '2026-10-21 12:26:00', ['sequence_id' => 5, 'sequence_step' => 2]);
        $this->reply('1002', '2026-10-21 12:26:30', ['sequence_id' => 5, 'sequence_step' => 3, 'status' => 'failed']);
        // AI: 3 sagot (2 sa huling 5 minuto); hindi ang hinihintay pa, ang pumalya at ang Encode order.
        $this->ai('1001', '2026-10-21 10:00:05', '2026-10-21 10:00:08');
        $this->ai('1002', '2026-10-21 12:28:01', '2026-10-21 12:28:03');
        $this->ai('1001', '2026-10-21 12:26:00', '2026-10-21 12:26:01', ['status' => 'sync']);
        $this->ai('1001', '2026-10-21 12:29:00', null);
        $this->ai('1002', '2026-10-21 12:29:01', '2026-10-21 12:29:09', ['status' => 'failed', 'error' => 'timeout']);
        $this->ai('1002', '2026-10-21 12:29:02', '2026-10-21 12:29:04', ['model' => \App\Services\OrderEncode\FlowEncode::MODEL]);
        // Order: 2 (1 sa huling 5 minuto); hindi ang ibang event.
        $this->botOrder('1001', '2026-10-21 11:30:00');
        $this->botOrder('1002', '2026-10-21 12:29:30');
        $this->botOrder('1002', '2026-10-21 12:29:31', 'sequence');
    }

    private function feed(array $q = []): array
    {
        Cache::flush();

        return app(LiveFeed::class)->feed($q);
    }

    public function test_S_190_1_the_page_and_feed_open_for_a_role_that_sees_speed_and_403_otherwise(): void
    {
        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        $this->get('/chatbot/live')->assertForbidden();
        $this->getJson('/chatbot/live/feed')->assertForbidden();

        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $html = $this->get('/chatbot/live')->assertOk()->getContent();
        $this->assertStringContainsString('data-tv-exit', $html); // TV mode: walang nav; ang link sa nav ay nasa ibang Chatbot page (ChatbotTabsTest)
        $this->assertMatchesRegularExpression('#data-live-count="messages"[^>]*>3<#', $html);
        $this->assertStringContainsString('data-live-board', $html);
        $this->assertStringContainsString('id="live-data"', $html);
        $this->getJson('/chatbot/live/feed')->assertOk()->assertJsonPath('counts.messages.today', 3);
    }

    public function test_S_190_2_the_counters_equal_the_days_rows_and_the_last_five_minutes(): void
    {
        $c = $this->feed()['counts'];

        $this->assertSame(['today' => 3, 'recent' => 2], $c['messages']);
        $this->assertSame(['today' => 3, 'recent' => 2], $c['confirmed']);
        $this->assertSame(['today' => 1, 'recent' => 1], $c['unconfirmed']);
        $this->assertSame(['today' => 2, 'recent' => 1], $c['follow_ups']);
        $this->assertSame(['today' => 3, 'recent' => 2], $c['ai']);
        $this->assertSame(['today' => 2, 'recent' => 1], $c['orders']);
        $this->assertSame('12:30:00', $c['as_of']);

        // Hindi lalampas sa 10 segundo ang tanda ng mga bilang: sa loob nito ay ang naka-cache, lampas ay bago.
        $this->chat('1002', '7008', '2026-10-21 12:30:00');
        $this->travel(4)->seconds();
        $this->assertSame(3, app(LiveFeed::class)->counts(null)['messages']['today']);
        $this->travel(7)->seconds();
        $c = app(LiveFeed::class)->counts(null);
        $this->assertSame([4, '12:30:11'], [$c['messages']['today'], $c['as_of']]);
    }

    public function test_S_190_3_given_the_cursor_only_newer_events_come_in_time_order_typed_and_without_names_or_text(): void
    {
        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        // Unang poll: walang cursor = ang dulo ngayon, walang event.
        $first = $this->getJson('/chatbot/live/feed')->assertOk()->json();
        $this->assertSame([], $first['events']);
        $this->assertSame(0, $first['more']);

        $this->travelTo('2026-10-21 12:30:20');
        $this->chat('1001', '7004', '2026-10-21 12:30:01');
        $this->ai('1002', '2026-10-21 12:30:02', '2026-10-21 12:30:03');
        $this->reply('1002', '2026-10-21 12:30:05');
        $this->reply('1001', '2026-10-21 12:30:07', ['sequence_id' => 5, 'sequence_step' => 1]);
        // Ang naunang padala (12:29) ay kinumpirma ng Meta ngayon lang: event sa oras ng kumpirmasyon.
        $late = DB::table('chatbot_replies')->where('sent_at', '2026-10-21 12:29:00')->value('id');
        DB::table('chatbot_replies')->where('id', $late)->update(['confirmed_at' => '2026-10-21 12:30:08', 'confirmed_status' => 'sent']);
        $this->botOrder('1001', '2026-10-21 12:30:09');
        $this->reply('1001', '2026-10-21 12:30:09'); // Forbeast, hindi pa kumpirmado: walang event pa

        $res = $this->getJson('/chatbot/live/feed?' . http_build_query(['cursor' => $first['cursor']]))->assertOk();
        $out = $res->json();
        $this->assertSame([
            ['message', '2026-10-21 12:30:01', 'Page Uno'],
            ['ai', '2026-10-21 12:30:03', 'Page Dos'],
            ['confirmed', '2026-10-21 12:30:05', 'Page Dos'],
            ['follow_up', '2026-10-21 12:30:07', 'Page Uno'],
            ['confirmed', '2026-10-21 12:30:08', 'Page Uno'],
            ['order', '2026-10-21 12:30:09', 'Page Uno'],
        ], array_map(fn ($e) => [$e['type'], $e['at'], $e['page']], $out['events']));
        $this->assertSame((int) $late, $out['events'][4]['id']);
        foreach ($out['events'] as $e) $this->assertSame(['type', 'at', 'page', 'id', 'bot', ...($e['type'] === 'order' ? ['amount'] : [])], array_keys($e)); // ang bot: ang naghatid o sumagot
        // Ang event ay walang pangalan; ang pangalan ng customer ay nasa notes ng bot lang (spec 200). Walang text o PSID kahit saan.
        $this->assertStringNotContainsString('Juan', json_encode($out['events']));
        foreach (['Magkano', 'sagot', 'Hello', '7004'] as $private) $this->assertStringNotContainsString($private, $res->getContent());
        $this->assertSame(0, $out['more']);

        // Ang susunod na poll mula sa bagong cursor: wala nang uulit. Ang kumpirmasyon na 3 segundo pa lang ang tanda ay
        // hinihintay (LAG_SECONDS), at lalabas sa susunod na poll kahit mas maaga ang oras nito kaysa sa poll.
        $open = DB::table('chatbot_replies')->where('sent_at', '2026-10-21 12:30:09')->value('id');
        DB::table('chatbot_replies')->where('id', $open)->update(['confirmed_at' => '2026-10-21 12:30:17', 'confirmed_status' => 'sent']);
        $next = $this->getJson('/chatbot/live/feed?' . http_build_query(['cursor' => $out['cursor']]))->json();
        $this->assertSame([], $next['events']);
        $this->travelTo('2026-10-21 12:30:25');
        $this->assertSame([['confirmed', '2026-10-21 12:30:17', (int) $open]],
            array_map(fn ($e) => [$e['type'], $e['at'], $e['id']], $this->getJson('/chatbot/live/feed?' . http_build_query(['cursor' => $next['cursor']]))->json('events')));
        // Ang sira o lampas na cursor ay ang dulo ngayon (walang event, walang error).
        foreach ([['chat' => '99999999'], ['ok' => '2099-01-01 00:00:00|1'], ['ok' => '2026-02-30 25:99:99|1'], ['ai' => '2026-10-21 12:00:00|1x'], ['chat' => '<script>'], ['ai' => ['x']], 'x'] as $bad) {
            $this->getJson('/chatbot/live/feed?' . http_build_query(['cursor' => $bad]))->assertOk()->assertJsonPath('events', []);
        }
    }

    public function test_S_190_4_more_than_the_cap_returns_the_newest_events_and_a_count_of_the_rest(): void
    {
        $cursor = $this->feed()['cursor'];
        $this->travelTo('2026-10-21 12:40:00');
        $ids = [];
        for ($i = 0; $i < 230; $i++) $ids[] = $this->chat('1001', (string) (8000 + $i), date('Y-m-d H:i:s', strtotime('2026-10-21 12:30:01') + $i));

        $out = $this->feed(['cursor' => $cursor]);
        $this->assertCount(LiveFeed::CAP, $out['events']);
        $this->assertSame(30, $out['more']);
        $this->assertSame(array_slice($ids, -200), array_column($out['events'], 'id')); // ang pinakabago, ayon sa oras
        $this->assertSame([], $this->feed(['cursor' => $out['cursor']])['events']);

        // Lampas sa CAP kapag pinagsama ang mga pinagmulan: 150 mensahe + 100 follow-up → ang pinakabagong 200, +50.
        $cursor = $out['cursor'];
        $this->travelTo('2026-10-21 12:50:00');
        for ($i = 0; $i < 150; $i++) $this->chat('1002', (string) (9000 + $i), date('Y-m-d H:i:s', strtotime('2026-10-21 12:41:00') + $i));
        for ($i = 0; $i < 100; $i++) $this->reply('1002', date('Y-m-d H:i:s', strtotime('2026-10-21 12:44:00') + $i), ['sequence_id' => 5, 'sequence_step' => 1]);
        $out = $this->feed(['cursor' => $cursor]);
        $this->assertCount(200, $out['events']);
        $this->assertSame(50, $out['more']);
        $this->assertSame(['message' => 100, 'follow_up' => 100], array_count_values(array_column($out['events'], 'type')));
        $at = array_column($out['events'], 'at');
        $sorted = $at;
        sort($sorted);
        $this->assertSame($sorted, $at);
    }

    public function test_S_190_5_the_page_filter_limits_counters_and_events(): void
    {
        $c = $this->feed(['page_id' => '1001'])['counts'];
        $this->assertSame(['today' => 2, 'recent' => 1], $c['messages']);
        $this->assertSame(['today' => 2, 'recent' => 1], $c['confirmed']);
        $this->assertSame(['today' => 1, 'recent' => 1], $c['unconfirmed']);
        $this->assertSame(['today' => 1, 'recent' => 0], $c['follow_ups']);
        $this->assertSame(['today' => 2, 'recent' => 1], $c['ai']);
        $this->assertSame(['today' => 1, 'recent' => 0], $c['orders']);
        $this->assertSame(0, $this->feed(['page_id' => '1003'])['counts']['messages']['today']);

        $cursor = $this->feed(['page_id' => '1001'])['cursor'];
        $this->travelTo('2026-10-21 12:30:20');
        $this->chat('1001', '7004', '2026-10-21 12:30:01');
        $this->chat('1002', '7005', '2026-10-21 12:30:02');
        $this->botOrder('1002', '2026-10-21 12:30:03');
        $this->ai('1001', '2026-10-21 12:30:04', '2026-10-21 12:30:05');
        $out = $this->feed(['page_id' => '1001', 'cursor' => $cursor]);
        $this->assertSame([['message', 'Page Uno'], ['ai', 'Page Uno']], array_map(fn ($e) => [$e['type'], $e['page']], $out['events']));

        // Ang sirang page filter ay lahat (5 mensahe na ngayon).
        foreach (['abc', '<b>', ['1001']] as $bad) $this->assertSame(5, $this->feed(['page_id' => $bad])['counts']['messages']['today']);
        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $html = $this->get('/chatbot/live?page_id=1001')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-live-count="messages"[^>]*>3<#', $html);
        $this->assertMatchesRegularExpression('#<option value="1001" selected#', $html);
    }

    public function test_S_190_8_reduced_motion_turns_off_travelling_pulses(): void
    {
        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $html = $this->get('/chatbot/live')->assertOk()->getContent();
        // Ang CSS ay walang animation sa reduced motion, at ang script ay hindi gumagawa ng pulse kapag reduced (matchMedia).
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $html);
        $this->assertStringContainsString("matchMedia('(prefers-reduced-motion: reduce)')", $html);
    }
}
