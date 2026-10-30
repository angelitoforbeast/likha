<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\LiveFeed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Spec 199, /chatbot/live bilang neural network: ang halaga ng order (ang COD ng na-encode na order), ang kabuuang halaga
 * ngayong araw, ang top 3 na sequence at ang bot filter. Ngayon ay 2026-10-21 12:30 (Manila); ang huling 5 minuto ay mula
 * 12:25. Page Uno (1001) ay Forbeast, Page Dos (1002) ay Botcake.
 */
class ChatbotLiveNetworkTest extends ChatblastTestCase
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
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
            'database/migrations/2026_11_04_100000_create_order_encodes_table.php',
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
            'database/migrations/2026_11_11_100000_add_real_send_to_chatbot_replies.php',
        ]]);
        $this->travelTo('2026-10-21 12:30:00');
        foreach (['Page Uno' => '1001', 'Page Dos' => '1002'] as $name => $id) $this->page($name, $id);
    }

    private function chat(string $page, string $psid, string $at, string $via): int
    {
        return DB::table('botcake_chats')->insertGetId(['page_id' => $page, 'page_name' => 'x', 'psid' => $psid, 'name' => 'Juan Dela Cruz',
            'message' => 'Magkano po ito?', 'received_at' => $at, 'payload' => json_encode(['source' => 'gateway', '_via' => $via])]);
    }

    private function reply(string $page, string $at, string $via, array $extra = []): int
    {
        return DB::table('chatbot_replies')->insertGetId($extra + ['page_id' => (int) $page, 'psid' => 7001, 'node_id' => 'n2', 'kind' => 'send',
            'content' => '[]', 'status' => 'sent', 'sent_at' => $at, 'via' => $via, 'confirmed_at' => $at, 'confirmed_status' => 'sent']);
    }

    private function botOrder(string $page, int $psid, string $at, string $run, string $via = 'forbeast'): int
    {
        return DB::table('chatbot_events')->insertGetId(['page_id' => (int) $page, 'psid' => $psid, 'type' => 'order',
            'detail' => json_encode(['run' => $run, 'via' => $via]), 'created_at' => $at]);
    }

    /** Ang order row (macro_output) at ang encode nito (order_encodes); $cod null = walang macro row pa. */
    private function encode(string $page, int $psid, string $run, string $at, string $status = 'done', ?string $cod = '1149', ?int $macro = null, array $input = []): int
    {
        if ($macro === null && $cod !== null) {
            $macro = DB::table('macro_output')->insertGetId(['PAGE' => $page, 'COD' => $cod, 'botcake_psid' => (string) $psid]);
        }

        return DB::table('order_encodes')->insertGetId(['page' => $page, 'psid' => $psid, 'run_id' => $run, 'flow_id' => 1, 'spreadsheet_id' => 'node',
            'tab' => 'Orders', 'mode' => 'live', 'status' => $status, 'input' => json_encode($input + ['page_id' => '0']), 'macro_output_id' => $macro,
            'created_at' => $at, 'updated_at' => $at]);
    }

    private function feed(array $q = []): array
    {
        return app(LiveFeed::class)->feed($q);
    }

    /** Ang mga order event mula sa cursor 0: id => amount. */
    private function amounts(array $q = []): array
    {
        $events = $this->feed($q + ['cursor' => ['order' => '0']])['events'];

        $out = array_column(array_filter($events, fn ($e) => $e['type'] === 'order'), 'amount', 'id');
        ksort($out);

        return $out;
    }

    /** Ang araw: 4 na order (2 may halaga), at mga encode na hindi dapat magpahiram ng COD. */
    private function seedOrders(): array
    {
        $ids = [];
        // May encode sa parehong takbo na tapos at may COD: 1,149 (lampas sa huling 5 minuto, nasa 15 minuto ng event).
        $ids['paid'] = $this->botOrder('1001', 7001, '2026-10-21 12:16:00', 'aaaa1111');
        $this->encode('Page Uno', 7001, 'aaaa1111', '2026-10-21 12:16:00', 'done', '1,149');
        // Walang encode: null. Ang ibang customer sa parehong page, at ang parehong PSID sa ibang page, ay hindi nagpapahiram.
        $ids['none'] = $this->botOrder('1001', 7002, '2026-10-21 12:27:00', 'bbbb2222');
        $this->encode('Page Uno', 7004, 'zzzz0000', '2026-10-21 12:27:30', 'done', '999');
        $this->encode('Page Dos', 7002, 'yyyy0000', '2026-10-21 12:27:30', 'done', '555');
        // Tapos ang encode pero walang COD: null.
        $ids['blank'] = $this->botOrder('1001', 7003, '2026-10-21 12:28:00', 'cccc3333');
        $this->encode('Page Uno', 7003, 'cccc3333', '2026-10-21 12:28:00', 'done', '');
        // Upload to macro sa takbo ng order, Encode order sa susunod na takbo sa parehong row: ang COD ng row na iyon.
        $ids['later_run'] = $this->botOrder('1002', 7005, '2026-10-21 12:29:00', 'dddd4444', 'botcake');
        $macro = DB::table('macro_output')->insertGetId(['PAGE' => 'Page Dos', 'COD' => '2050.50', 'botcake_psid' => '7005']);
        $this->encode('Page Dos', 7005, 'dddd4444', '2026-10-21 12:29:00', 'uploaded', null, $macro, ['upload' => 'insert']);
        $this->encode('Page Dos', 7005, 'eeee5555', '2026-10-21 12:29:30', 'done', null, $macro);

        return $ids;
    }

    public function test_S_199_1_an_order_carries_the_cod_of_its_encoded_order_and_never_another_customers(): void
    {
        $ids = $this->seedOrders();
        // Ang ikalawang order ng parehong customer na wala pang sariling encode: hindi hinihiram ang COD ng naunang order.
        $ids['second'] = $this->botOrder('1001', 7001, '2026-10-21 12:20:00', 'kkkk1111');
        // Ang "second order not accepted": ang row ay sa ibang order, kaya walang halaga.
        $ids['refused'] = $this->botOrder('1002', 7007, '2026-10-21 12:21:00', 'mmmm1111');
        $this->encode('Page Dos', 7007, 'mmmm1111', '2026-10-21 12:21:00', 'done', '880', null, ['not_accepted' => 99]);
        // Ang encode ng naunang order (11:00) na dumating pagkatapos ng susunod na order: sa naunang order pa rin, hindi ipinapahiram.
        $this->botOrder('1001', 7010, '2026-10-21 11:00:00', 'nnnn1111');
        $this->encode('Page Uno', 7010, 'nnnn1111', '2026-10-21 12:22:00', 'done', '640');
        $ids['late'] = $this->botOrder('1001', 7010, '2026-10-21 12:18:00', 'pppp1111');

        $this->assertSame([$ids['paid'] => 1149, $ids['none'] => null, $ids['blank'] => null, $ids['later_run'] => 2050.5, $ids['second'] => null, $ids['refused'] => null, $ids['late'] => null],
            $this->amounts());
    }

    public function test_S_199_2_an_order_encoded_later_gets_its_amount_on_a_later_read_and_counts_once(): void
    {
        $this->seedOrders();
        $id = $this->botOrder('1002', 7006, '2026-10-21 12:29:40', 'ffff6666', 'botcake');
        $enc = $this->encode('Page Dos', 7006, 'ffff6666', '2026-10-21 12:29:40', 'queued', null);
        $this->assertNull($this->amounts()[$id]);
        $this->assertSame(3199.5, $this->feed()['counts']['order_value']['today']);

        // Natapos ang encode: ang susunod na basa ay may halaga, at ang kabuuan ay tumaas nang isang beses.
        $macro = DB::table('macro_output')->insertGetId(['PAGE' => 'Page Dos', 'COD' => '300', 'botcake_psid' => '7006']);
        DB::table('order_encodes')->where('id', $enc)->update(['status' => 'done', 'macro_output_id' => $macro]);
        $this->travel(11)->seconds();
        $this->assertSame(300, $this->amounts()[$id]);
        $this->assertSame(3499.5, $this->feed()['counts']['order_value']['today']);
        $this->travel(11)->seconds();
        $this->assertSame(3499.5, $this->feed()['counts']['order_value']['today']);
        $this->travel(11)->seconds();
        $this->assertSame(3499.5, $this->feed()['counts']['order_value']['today']);
    }

    public function test_S_199_3_the_order_value_is_the_sum_of_known_amounts_today_and_in_the_last_five_minutes_per_page(): void
    {
        $this->seedOrders();
        $this->botOrder('1001', 7008, '2026-10-20 23:50:00', 'gggg7777'); // kahapon: hindi kasama
        $this->encode('Page Uno', 7008, 'gggg7777', '2026-10-20 23:50:00', 'done', '700');

        $this->assertSame(['today' => 3199.5, 'recent' => 2050.5], $this->feed()['counts']['order_value']);
        $this->assertSame(['today' => 1149, 'recent' => 0], $this->feed(['page_id' => '1001'])['counts']['order_value']);
        $this->assertSame(['today' => 2050.5, 'recent' => 2050.5], $this->feed(['page_id' => '1002'])['counts']['order_value']);
    }

    public function test_S_199_4_the_top_three_sequences_are_todays_real_names_and_counts_by_count(): void
    {
        $seq = fn (string $name) => DB::table('chatbot_sequences')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        [$remind, $last, $promo, $old] = [$seq('Pa-remind'), $seq('Last call'), $seq('Promo'), $seq('Old')];
        // Ang naayos na bahagi (umaga) at ang buhay na bahagi (huling minuto) ay pinagsasama.
        $this->reply('1001', '2026-10-21 09:00:00', 'forbeast', ['sequence_id' => $remind]);
        $this->reply('1001', '2026-10-21 12:26:00', 'forbeast', ['sequence_id' => $remind]);
        $this->reply('1002', '2026-10-21 12:27:00', 'botcake', ['sequence_id' => $remind]);
        $this->reply('1002', '2026-10-21 10:00:00', 'botcake', ['sequence_id' => $promo]);
        $this->reply('1002', '2026-10-21 12:28:00', 'botcake', ['sequence_id' => $promo]);
        $this->reply('1001', '2026-10-21 12:28:00', 'forbeast', ['sequence_id' => $last]);
        $this->reply('1001', '2026-10-21 12:28:30', 'forbeast', ['sequence_id' => $last, 'status' => 'failed']);
        $this->reply('1001', '2026-10-20 12:00:00', 'forbeast', ['sequence_id' => $old]); // kahapon
        $this->reply('1001', '2026-10-20 12:00:05', 'forbeast', ['sequence_id' => $old]);

        $this->assertSame([['name' => 'Pa-remind', 'n' => 3], ['name' => 'Promo', 'n' => 2], ['name' => 'Last call', 'n' => 1]],
            $this->feed()['counts']['sequences']);
        $this->assertSame([['name' => 'Pa-remind', 'n' => 2], ['name' => 'Last call', 'n' => 1]], $this->feed(['page_id' => '1001'])['counts']['sequences']);
        Cache::flush();
        DB::table('chatbot_replies')->whereNotNull('sequence_id')->delete();
        $this->assertSame([], $this->feed()['counts']['sequences']);
    }

    public function test_S_199_5_the_bot_filter_narrows_the_per_bot_counts_and_the_events(): void
    {
        $this->chat('1001', '7001', '2026-10-21 12:27:00', 'forbeast');
        $this->chat('1001', '7002', '2026-10-21 12:27:10', 'incepxionbot');
        $this->chat('1002', '7003', '2026-10-21 12:27:20', 'botcake');
        $this->reply('1001', '2026-10-21 12:27:05', 'forbeast');
        $this->reply('1002', '2026-10-21 12:27:25', 'botcake');
        $this->botOrder('1002', 7003, '2026-10-21 12:27:30', 'hhhh8888', 'botcake');
        $cursor = ['chat' => '0', 'seq' => '0', 'order' => '0', 'ok' => '2026-10-21 12:00:00|0', 'ai' => '2026-10-21 12:00:00|0'];
        $read = function (array $q) use ($cursor) {
            $f = $this->feed($q + ['cursor' => $cursor]);

            return [$f['counts']['bots'], array_map(fn ($e) => [$e['type'], $e['bot']], $f['events'])];
        };

        [$bots, $events] = $read(['bot' => 'botcake']);
        $this->assertSame(['in' => ['forbeast' => 0, 'incepxionbot' => 0, 'botcake' => 1, 'unknown' => 0], 'out' => ['forbeast' => 0, 'incepxionbot' => 0, 'botcake' => 1, 'unknown' => 0]], $bots);
        $this->assertSame([['message', 'botcake'], ['confirmed', 'botcake'], ['order', 'botcake']], $events);
        [$bots, $events] = $read(['bot' => 'forbeast']);
        $this->assertSame(['forbeast' => 1, 'incepxionbot' => 0, 'botcake' => 0, 'unknown' => 0], $bots['in']);
        $this->assertSame([['message', 'forbeast'], ['confirmed', 'forbeast']], $events);

        $all = $read([]);
        $this->assertSame(['forbeast' => 1, 'incepxionbot' => 1, 'botcake' => 1, 'unknown' => 0], $all[0]['in']);
        $this->assertCount(6, $all[1]);
        $this->assertSame($all, $read(['bot' => 'all']));
        $this->assertSame($all, $read(['bot' => 'nonsense']));
        $this->assertSame($all, $read(['bot' => ['forbeast']]));
    }

    public function test_S_199_6_the_page_renders_every_section_with_its_data_hooks_and_keeps_every_number_of_the_scoreboard(): void
    {
        $this->seedOrders();
        $seq = DB::table('chatbot_sequences')->insertGetId(['name' => 'Pa-remind', 'created_at' => now(), 'updated_at' => now()]);
        $this->reply('1001', '2026-10-21 12:26:00', 'forbeast', ['sequence_id' => $seq]);
        $this->chat('1001', '7001', '2026-10-21 12:27:00', 'forbeast');
        DB::table('botcake_chats')->insert(['page_id' => '1001', 'page_name' => 'x', 'psid' => '7009', 'name' => 'Juan Dela Cruz', 'message' => 'Magkano po ito?',
            'received_at' => '2026-10-21 12:27:30', 'payload' => null]); // walang bot na naitala
        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $html = $this->get('/chatbot/live?bot=botcake')->assertOk()->getContent();

        // Header: pangalan, live, page at bot filter, orasan ng Manila, TV mode at ang labas.
        foreach (['<h1>LIKHA LIVE</h1>', 'data-live-status="live"', 'data-live-page', 'name="bot"', 'data-live-clock', 'data-live-fullscreen', 'data-tv-exit',
            'value="botcake" selected', 'Bot 2 · Incepxionbot', '"bot":"botcake"'] as $s) $this->assertStringContainsString($s, $html);
        // KPI strip (at bawat bilang ng scoreboard): ang numero ay nasa mismong hook.
        foreach (['replies' => '0', 'orders' => '4', 'messages' => '2', 'follow_ups' => '1', 'ai' => '0', 'peso' => '3,200', 'signals' => '6',
            'in-forbeast' => '0', 'in-botcake' => '0', 'out-forbeast' => '0', 'in-unknown' => '0'] as $key => $v) {
            $this->assertMatchesRegularExpression('#data-live-count="' . $key . '"[^>]*>' . preg_quote($v, '#') . '<#', $html, $key);
        }
        foreach (['data-live-recent="replies"', 'data-live-recent="orders"', 'data-live-count="reply_time"', 'data-live-unit', 'data-live-note="reply_time"',
            'data-live-count="active"', 'data-live-unknown="in"', 'data-live-unknown="out"', 'data-live-last-order', 'data-live-plus', 'data-live-kpi="orders"'] as $s) {
            $this->assertStringContainsString($s, $html, $s);
        }
        // Ang HUD (spec 200): ang kabuuang inquiry, ang core at ang orasan nito, AI, follow-ups, ang tatlong bot na may in at out na path.
        foreach (['INQUIRIES RECEIVED TODAY', 'FOLLOW-UPS SENT', 'AI ANSWERS', 'signals · last 5 min', 'BOT 1 · FORBEAST', 'BOT 2 · INCEPXIONBOT',
            'BOTCAKE · FALLBACK', 'DORMANT', ' orders · ', 'Order value today', 'LIVE ACTIVITY', 'Listening for new events'] as $s) {
            $this->assertStringContainsString($s, $html, $s);
        }
        foreach (['seq', 'ai', 'forbeast-in', 'forbeast-out', 'incepxionbot-in', 'incepxionbot-out', 'botcake-in', 'botcake-out'] as $p) $this->assertSame(1, substr_count($html, 'data-path="' . $p . '"'), $p);
        $this->assertSame(60, preg_match_all('#<line [^>]*/>#', explode('</g>', explode('<g data-live-ticks', $html, 2)[1], 2)[0]));
        $this->assertMatchesRegularExpression('#data-live-seq="0"[^>]*><span>Pa-remind</span><b>1</b>#', $html);
        $this->assertMatchesRegularExpression('#data-bot-row="incepxionbot"[^>]*data-linked="[01]"#', $html);
        $this->assertStringContainsString('data-live-log', $html);
        // Privacy at TV: walang pangalan o mensahe ng customer; walang font o script mula sa labas; walang blur.
        foreach (['Juan Dela Cruz', 'Magkano', 'fonts.googleapis', 'cdn.', 'backdrop-filter', 'filter: blur'] as $s) $this->assertStringNotContainsString($s, $html, $s);
        $this->assertStringContainsString('js/chatbot-live-rules.js', $html);
    }

    public function test_S_199_8_the_query_count_of_a_poll_does_not_grow_with_the_number_of_events(): void
    {
        $count = function () {
            Cache::flush();
            $n = 0;
            DB::listen(function () use (&$n) { $n++; });
            $this->feed(['cursor' => ['chat' => '0', 'seq' => '0', 'order' => '0', 'ok' => '2026-10-21 12:00:00|0', 'ai' => '2026-10-21 12:00:00|0']]);

            return $n;
        };
        $this->botOrder('1001', 7001, '2026-10-21 12:26:00', 'run00001');
        $this->encode('Page Uno', 7001, 'run00001', '2026-10-21 12:26:00');
        $one = $count();
        for ($i = 2; $i <= 40; $i++) {
            $this->botOrder('1001', 7000 + $i, '2026-10-21 12:26:' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'run' . str_pad((string) $i, 5, '0', STR_PAD_LEFT));
            $this->encode('Page Uno', 7000 + $i, 'run' . str_pad((string) $i, 5, '0', STR_PAD_LEFT), '2026-10-21 12:26:00', 'done', (string) (100 * $i));
            $this->chat('1001', (string) (8000 + $i), '2026-10-21 12:27:00', 'forbeast');
        }
        DB::flushQueryLog();
        $many = $count();

        $this->assertSame($one, $many);
    }
}
