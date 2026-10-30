<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakePsid;
use App\Services\Chatbot\LiveFeed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Spec 200, /chatbot/live bilang Jarvis HUD: ang notes ng bawat bot (nag-chat ngayon, sagot ng AI, average na reply, order at
 * halaga, huling ginawa) at ang 4 na pinakabagong customer nito na may pangalan. Ngayon ay 2026-10-21 12:30 (Manila); ang
 * huling 5 minuto ay mula 12:25. Page Uno (1001) at Page Dos (1002); naka-link ang Forbeast at ang Incepxionbot.
 */
class ChatbotLiveJarvisTest extends ChatblastTestCase
{
    private const SPEED = 'https://forbeast.example.test/api/v1/site/reply-speed*';
    private const AI_REPLY = '[{"type":"text","text":"Opo, 499 po","ai_text":"Opo, 499 po"}]';

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
            'database/migrations/2026_11_13_100000_widen_via_on_chatbot_replies.php',
        ]]);
        $this->travelTo('2026-10-21 12:30:00');
        foreach (['Page Uno' => '1001', 'Page Dos' => '1002'] as $name => $id) $this->page($name, $id);
        config(['services.gateway.url' => 'https://forbeast.example.test/api', 'services.forbeast_site.key' => 'fbs_test_key',
            'services.forbeast_site.secret' => 'site-secret', 'services.incepxionbot.url' => 'https://ixb.example.test']);
        Http::fake([self::SPEED => Http::response(['date' => '2026-10-21', 'page_id' => null, 'timezone' => 'Asia/Manila', 'hours' => [],
            'total' => ['messages' => 40, 'bot_answered' => 38, 'person_answered' => 2, 'unanswered' => 0, 'avg_s' => 2.4, 'median_s' => 2, 'p90_s' => 4, 'max_s' => 9, 'meta_to_forbeast_avg_s' => 1.1],
            'generated_at' => '2026-10-21T04:30:00Z'])]);
    }

    private function chat(string $page, string $psid, string $at, ?string $via, ?string $name = null, array $payload = []): int
    {
        return DB::table('botcake_chats')->insertGetId(['page_id' => $page, 'page_name' => 'x', 'psid' => $psid, 'name' => $name,
            'message' => 'Magkano po? 09171234567 Blk 5 Lot 3', 'received_at' => $at,
            'payload' => json_encode($payload + ($via === null ? [] : ['source' => 'gateway', '_via' => $via]))]);
    }

    private function reply(string $page, string $psid, string $at, string $via, array $extra = []): int
    {
        return DB::table('chatbot_replies')->insertGetId($extra + ['page_id' => (int) $page, 'psid' => (int) $psid, 'node_id' => 'n2', 'kind' => 'send',
            'content' => '[{"type":"text","text":"Salamat po"}]', 'status' => 'sent', 'sent_at' => $at, 'via' => $via, 'confirmed_at' => $at, 'confirmed_status' => 'sent']);
    }

    private function ai(int $chat, string $page, string $psid, string $answered, array $extra = []): int
    {
        return DB::table('chatbot_ai_requests')->insertGetId($extra + ['request_id' => (string) \Illuminate\Support\Str::uuid(), 'page_id' => (int) $page, 'psid' => (int) $psid,
            'chat_id' => $chat, 'flow_id' => 1, 'node_id' => 'n3', 'page_name' => 'x', 'model' => 'gpt-test', 'messages' => '[]', 'max_tokens' => 100,
            'context' => '{}', 'status' => 'answered', 'deadline_at' => $answered, 'answered_at' => $answered, 'answer' => 'Opo, 499 po',
            'created_at' => date('Y-m-d H:i:s', strtotime($answered) - 3), 'updated_at' => $answered]);
    }

    private function botOrder(string $page, string $psid, string $at, string $via, ?string $cod = null): int
    {
        $run = substr(md5($page . $psid . $at), 0, 8);
        $id = DB::table('chatbot_events')->insertGetId(['page_id' => (int) $page, 'psid' => (int) $psid, 'type' => 'order',
            'detail' => json_encode(['run' => $run, 'via' => $via]), 'created_at' => $at]);
        if ($cod !== null) {
            $macro = DB::table('macro_output')->insertGetId(['PAGE' => $page === '1001' ? 'Page Uno' : 'Page Dos', 'COD' => $cod, 'botcake_psid' => $psid]);
            DB::table('order_encodes')->insert(['page' => $page === '1001' ? 'Page Uno' : 'Page Dos', 'psid' => (int) $psid, 'run_id' => $run, 'flow_id' => 1,
                'spreadsheet_id' => 'node', 'tab' => 'Orders', 'mode' => 'live', 'status' => 'done', 'input' => '{"page_id":"0"}', 'macro_output_id' => $macro,
                'created_at' => $at, 'updated_at' => $at]);
        }

        return $id;
    }

    /**
     * Ang araw. Forbeast: Ana (1001/7001) nag-chat 12:27 at 12:28:30, sagot na hindi AI 12:27:05; Ben (1001/7002) chat 12:10,
     * AI 12:10:05, sagot na AI 12:10:06, order 12:12 (COD 1,149); Cara (1002/7003) chat 12:28, AI 12:28:05, sagot na AI 12:28:06;
     * 7004 (walang pangalan sa chat, "Dan Uy" sa Botcake) chat 09:00; Eve (1001/7005) chat 08:00 (ikalima: wala sa listahan).
     * Botcake: 1002/7020 (walang pangalan kahit saan) chat 12:26, sagot 12:26:10, order 12:29 (walang COD pa); Ana ay may
     * follow-up din mula sa Botcake 11:00. Incepxionbot: Fe (1002/7030) chat 12:29, order 12:29:30 (COD 2,050.50). Hindi
     * binibilang: ang standby na kopya, kahapon, ang AI na may error at ang AI ng Encode order.
     */
    private function seedDay(): void
    {
        $this->chat('1001', '7001', '2026-10-21 12:27:00', 'forbeast', 'Ana Reyes');
        $this->chat('1001', '7001', '2026-10-21 12:28:30', 'forbeast', 'Ana Reyes');
        $this->reply('1001', '7001', '2026-10-21 12:27:05', 'forbeast');
        $ben = $this->chat('1001', '7002', '2026-10-21 12:10:00', 'forbeast', 'Ben Cruz');
        $this->ai($ben, '1001', '7002', '2026-10-21 12:10:05');
        $this->reply('1001', '7002', '2026-10-21 12:10:06', 'forbeast', ['content' => self::AI_REPLY]);
        $this->botOrder('1001', '7002', '2026-10-21 12:12:00', 'forbeast', '1,149');
        $cara = $this->chat('1002', '7003', '2026-10-21 12:28:00', 'forbeast', 'Cara Lim');
        $this->ai($cara, '1002', '7003', '2026-10-21 12:28:05');
        $this->reply('1002', '7003', '2026-10-21 12:28:06', 'forbeast', ['content' => self::AI_REPLY]);
        $this->chat('1001', '7004', '2026-10-21 09:00:00', 'forbeast');
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Dan Uy', 'psid' => '7004', 'status' => 'matched']);
        BotcakePsid::create(['page' => 'Page Dos', 'fb_name' => 'Not Dan', 'psid' => '7004', 'status' => 'matched']);
        $this->chat('1001', '7005', '2026-10-21 08:00:00', 'forbeast', 'Eve Tan');

        $this->chat('1002', '7020', '2026-10-21 12:26:00', 'botcake');
        $this->reply('1002', '7020', '2026-10-21 12:26:10', 'botcake');
        $this->botOrder('1002', '7020', '2026-10-21 12:29:00', 'botcake');
        $this->reply('1001', '7001', '2026-10-21 11:00:00', 'botcake', ['sequence_id' => 5, 'sequence_step' => 1]);

        $this->chat('1002', '7030', '2026-10-21 12:29:00', 'incepxionbot', 'Fe Go');
        $this->botOrder('1002', '7030', '2026-10-21 12:29:30', 'incepxionbot', '2050.50');

        // Hindi binibilang.
        $this->chat('1001', '7009', '2026-10-21 12:29:00', 'forbeast', 'Standby Copy', ['_standby' => true]);
        $old = $this->chat('1001', '7010', '2026-10-20 23:59:00', 'forbeast', 'Yesterday Person');
        $this->ai($old, '1001', '7010', '2026-10-20 23:59:05');
        $this->reply('1002', '7011', '2026-10-20 22:00:00', 'botcake');
        $this->botOrder('1002', '7011', '2026-10-20 22:00:00', 'botcake', '300');
        $this->ai($cara, '1002', '7003', '2026-10-21 12:28:10', ['error' => 'timeout']);
        $this->ai($cara, '1002', '7003', '2026-10-21 12:28:20', ['model' => \App\Services\OrderEncode\FlowEncode::MODEL]);
    }

    private function notes(array $q = []): array
    {
        return app(LiveFeed::class)->feed($q)['counts']['notes'];
    }

    /** Ang mga numero lang ng notes (walang listahan ng customer). */
    private static function numbers(array $note): array
    {
        return array_diff_key($note, ['customers' => 1]);
    }

    public function test_S_200_1_each_bots_notes_equal_the_hand_counted_values_with_the_page_and_bot_filter_and_a_dormant_bot_is_zero(): void
    {
        $this->seedDay();
        $n = $this->notes();

        $this->assertSame(['linked' => true, 'chatting' => 2, 'ai' => 2, 'avg' => 2.4, 'orders' => 1, 'value' => 1149,
            'last' => ['type' => 'message', 'page' => 'Page Uno', 'at' => '12:28']], self::numbers($n['forbeast']));
        $this->assertSame(['linked' => true, 'chatting' => 1, 'ai' => 0, 'avg' => null, 'orders' => 1, 'value' => 0,
            'last' => ['type' => 'order', 'page' => 'Page Dos', 'at' => '12:29']], self::numbers($n['botcake']));
        $this->assertSame(['linked' => true, 'chatting' => 1, 'ai' => 0, 'avg' => null, 'orders' => 1, 'value' => 2050.5,
            'last' => ['type' => 'order', 'page' => 'Page Dos', 'at' => '12:29']], self::numbers($n['incepxionbot']));

        // Page filter: Page Uno lang.
        $uno = $this->notes(['page_id' => '1001']);
        $this->assertSame(['linked' => true, 'chatting' => 1, 'ai' => 1, 'avg' => 2.4, 'orders' => 1, 'value' => 1149,
            'last' => ['type' => 'message', 'page' => 'Page Uno', 'at' => '12:28']], self::numbers($uno['forbeast']));
        $this->assertSame(['linked' => true, 'chatting' => 0, 'ai' => 0, 'avg' => null, 'orders' => 0, 'value' => 0,
            'last' => ['type' => 'follow_up', 'page' => 'Page Uno', 'at' => '11:00']], self::numbers($uno['botcake']));
        $this->assertSame(0, $uno['incepxionbot']['orders']);

        // Bot filter: ang ibang bot ay walang laman; ang napili ay buo pa rin.
        $only = $this->notes(['bot' => 'botcake']);
        $this->assertSame(self::numbers($n['botcake']), self::numbers($only['botcake']));
        foreach (['forbeast', 'incepxionbot'] as $b) {
            $this->assertSame(['linked' => true, 'chatting' => 0, 'ai' => 0, 'avg' => null, 'orders' => 0, 'value' => 0, 'last' => null, 'customers' => []], $only[$b], $b);
        }

        // Hindi naka-link ang Incepxionbot: dormant, puro zero, walang customer.
        config(['services.incepxionbot.url' => '']);
        Cache::flush();
        $this->assertSame(['linked' => false, 'chatting' => 0, 'ai' => 0, 'avg' => null, 'orders' => 0, 'value' => 0, 'last' => null, 'customers' => []],
            $this->notes()['incepxionbot']);
    }

    public function test_S_200_2_the_latest_customers_are_the_four_newest_per_bot_with_their_tag_and_time_and_never_another_days(): void
    {
        $this->seedDay();
        $n = $this->notes();

        $this->assertSame([
            ['name' => 'Ana R.', 'tag' => 'CHATTING', 'at' => '12:28'],
            ['name' => 'Cara L.', 'tag' => 'AI', 'at' => '12:28'],
            ['name' => 'Ben C.', 'tag' => 'ORDERED', 'at' => '12:12'],
            ['name' => 'Dan U.', 'tag' => 'CHATTING', 'at' => '09:00'],
        ], $n['forbeast']['customers']);
        // Ana ay customer din ng Botcake (follow-up 11:00): nasa dalawang bot.
        $this->assertSame([
            ['name' => 'Customer · Page Dos', 'tag' => 'ORDERED', 'at' => '12:29'],
            ['name' => 'Ana R.', 'tag' => 'CHATTING', 'at' => '11:00'],
        ], $n['botcake']['customers']);
        $this->assertSame([['name' => 'Fe G.', 'tag' => 'ORDERED', 'at' => '12:29']], $n['incepxionbot']['customers']);
        $this->assertSame([['name' => 'Ana R.', 'tag' => 'CHATTING', 'at' => '11:00']], $this->notes(['page_id' => '1001'])['botcake']['customers']);

        // Sa susunod na refresh: ang bagong chat ni Eve ay nasa itaas, ang pinakaluma (Dan) ay wala na; ang sagot na AI kay
        // Ana ay ginagawa siyang AI. Ang kahapon ay hindi kailanman lumalabas.
        $this->travel(11)->seconds();
        $this->chat('1001', '7005', '2026-10-21 12:30:05', 'forbeast', 'Eve Tan');
        $this->reply('1001', '7001', '2026-10-21 12:30:02', 'forbeast', ['content' => self::AI_REPLY]);
        $this->assertSame([
            ['name' => 'Eve T.', 'tag' => 'CHATTING', 'at' => '12:30'],
            ['name' => 'Ana R.', 'tag' => 'AI', 'at' => '12:30'],
            ['name' => 'Cara L.', 'tag' => 'AI', 'at' => '12:28'],
            ['name' => 'Ben C.', 'tag' => 'ORDERED', 'at' => '12:12'],
        ], $this->notes()['forbeast']['customers']);

        // Bagong araw: ang mga customer kahapon ay wala na.
        $this->travelTo('2026-10-22 00:00:30');
        foreach ($this->notes() as $bot => $note) $this->assertSame([], $note['customers'], $bot);
    }

    public function test_S_200_3_a_stored_name_is_shown_else_customer_and_the_page_short_name_and_nothing_private_leaves_the_server(): void
    {
        $this->page('Likha Home Essentials Official', '1003');
        $this->chat('1003', '24680135791', '2026-10-21 12:20:00', 'forbeast');
        $this->chat('1001', '13579246801', '2026-10-21 12:21:00', 'forbeast', '');
        $this->chat('1001', '13579246801', '2026-10-21 12:10:00', 'forbeast', 'Maria <b>Santos</b>'); // ang huling may pangalan
        $this->chat('1001', '97531864201', '2026-10-21 12:22:00', 'forbeast', 'Old Name');
        $this->chat('1001', '97531864201', '2026-10-21 12:23:00', 'forbeast', 'New Name');

        $this->assertSame(['New N.', 'Maria S.', 'Customer · Likha Home Essent…'], array_column($this->notes()['forbeast']['customers'], 'name'));

        // Ang JSON ng feed at ang page: walang PSID, telepono, address o text ng mensahe; ang pangalan ay escaped sa page.
        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $json = $this->getJson('/chatbot/live/feed')->assertOk()->getContent();
        $html = $this->get('/chatbot/live')->assertOk()->getContent();
        foreach ([$json, $html] as $body) {
            foreach (['24680135791', '13579246801', '97531864201', '0917', 'Blk 5', 'Magkano'] as $private) $this->assertStringNotContainsString($private, $body, $private);
        }
        $this->assertStringContainsString('New N.', $json);
        $this->assertStringNotContainsString('Santos', $json); // spec 205: unang pangalan at inisyal lang
        $this->assertStringNotContainsString('Maria <b>Santos', $html);
    }

    public function test_S_200_6_the_page_renders_every_section_of_the_hud_with_its_hooks_and_every_number_of_spec_199(): void
    {
        $this->seedDay();
        config(['services.incepxionbot.url' => '']); // Bot 2 dormant
        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $html = $this->get('/chatbot/live')->assertOk()->getContent();

        // Header: pangalan, live, page at bot filter, orasan ng Manila, 3D, TV mode, labas.
        foreach (['<h1>LIKHA LIVE</h1>', 'data-live-status="live"', 'data-live-page', 'data-live-bot', 'data-live-clock', 'data-live-updated',
            'data-live-3d', 'aria-pressed="false"', 'data-live-fullscreen', 'data-tv-exit'] as $s) $this->assertStringContainsString($s, $html, $s);
        // Ang reactor: umiikot na singsing, 60 guhit ng 5-minutong orasan, scan band, gintong core na may halaga, order, +N at Last.
        foreach (['rot rot-a', 'rot rot-b', 'rot rot-c', 'class="scanb"', 'data-live-core', 'Order value today · COD', 'data-live-last-order', 'data-live-shock', 'data-live-coins', 'data-live-plus'] as $s) {
            $this->assertStringContainsString($s, $html, $s);
        }
        $this->assertSame(60, preg_match_all('#<line [^>]*/>#', explode('</g>', explode('<g data-live-ticks', $html, 2)[1], 2)[0]));
        $this->assertStringContainsString('Last: <tspan data-live-last-order>Page Dos · 12:29 · Bot 2</tspan>', $html);
        // Bawat numero ng spec 199 ay nasa sariling hook, na may tamang laman.
        foreach (['replies' => '4', 'orders' => '3', 'peso' => '3,200', 'messages' => '8', 'ai' => '2', 'follow_ups' => '1', 'active' => '4', 'signals' => '11',
            'in-forbeast' => '6', 'out-forbeast' => '3', 'in-botcake' => '1', 'out-botcake' => '1', 'in-incepxionbot' => '1', 'out-incepxionbot' => '0', 'in-unknown' => '0', 'out-unknown' => '0',
            'reply_time' => '2.4'] as $key => $v) {
            $this->assertMatchesRegularExpression('#data-live-count="' . $key . '"[^>]*>' . preg_quote($v, '#') . '<#', $html, $key);
        }
        foreach (['data-live-recent="replies"', 'data-live-recent="orders"', 'data-live-note="reply_time"', 'data-live-unit', 'data-live-unknown="in"', 'data-live-unknown="out"',
            'data-live-kpi="orders"', 'data-live-seq="0"', 'data-live-log'] as $s) $this->assertStringContainsString($s, $html, $s);
        foreach (['Replies today', 'Avg reply time', 'BOT 1', 'Orders today', 'Active chats', 'INQUIRIES RECEIVED TODAY', 'AI ANSWERS', 'FOLLOW-UPS SENT', 'signals · last 5 min', 'LIVE ACTIVITY'] as $s) {
            $this->assertStringContainsString($s, $html, $s);
        }
        // Ang mga bot: hub na may IN / OUT at pipe papasok at palabas, at NOTES; ang dormant ay dashed (data-linked 0), hindi pula.
        foreach (['forbeast' => '1', 'incepxionbot' => '0', 'botcake' => '1'] as $bot => $on) {
            $this->assertMatchesRegularExpression('#data-bot-row="' . $bot . '" data-linked="' . $on . '"#', $html, $bot);
            foreach (['-in', '-out'] as $dir) $this->assertSame(1, substr_count($html, 'data-path="' . $bot . $dir . '"'), $bot . $dir);
            $this->assertStringContainsString('data-note="' . $bot . '"', $html);
        }
        foreach (['BOT 1 · FORBEAST', 'BOT 2 · INCEPXIONBOT', 'BOTCAKE · FALLBACK', 'DORMANT', 'none while not linked', 'Latest customers', 'Last activity'] as $s) $this->assertStringContainsString($s, $html, $s);
        // Ang notes ng Bot 1 mula sa server (unang laman bago ang poll).
        $notes = explode('data-note="incepxionbot"', explode('data-note="forbeast"', $html, 2)[1], 2)[0];
        foreach (['data-n="chatting">2<', 'data-n="ai">2<', 'data-n="avg">2.4 s<', 'data-n="orders">1<', 'data-n="value">₱1,149<', 'data-n="last">Inquiry · Page Uno · 12:28<',
            '<b>Ana R.</b><em class="t-chatting">CHATTING</em><time>12:28</time>', '<b>Ben C.</b><em class="t-ordered">ORDERED</em><time>12:12</time>'] as $s) {
            $this->assertStringContainsString($s, $notes, $s);
        }
        // Dalawang kulay lang, walang pula; walang blur, backdrop-filter, font o script mula sa labas.
        foreach (['#ff6262', '--bad', 'backdrop-filter', 'filter: blur', 'fonts.googleapis', 'cdn.'] as $s) $this->assertStringNotContainsString($s, $html, $s);
    }

    public function test_S_200_4_the_query_count_of_a_poll_does_not_grow_with_events_or_customers(): void
    {
        $count = function (bool $cold) {
            if ($cold) Cache::flush();
            $n = 0;
            DB::listen(function () use (&$n) { $n++; });
            app(LiveFeed::class)->feed(['cursor' => ['chat' => '0', 'seq' => '0', 'order' => '0', 'ok' => '2026-10-21 12:00:00|0', 'ai' => '2026-10-21 12:00:00|0']]);

            return $n;
        };
        $this->seedDay();
        [$oneCold, $oneWarm] = [$count(true), 0];
        $this->travel(11)->seconds();
        $oneWarm = $count(false);
        $this->travelTo('2026-10-21 12:30:00');
        for ($i = 100; $i < 160; $i++) {
            $chat = $this->chat('1001', (string) (8000 + $i), '2026-10-21 12:2' . ($i % 10) . ':00', $i % 3 ? 'forbeast' : 'botcake', $i % 2 ? 'Name ' . $i : null);
            $this->ai($chat, '1001', (string) (8000 + $i), '2026-10-21 12:29:0' . ($i % 10));
            $this->reply('1001', (string) (8000 + $i), '2026-10-21 12:29:1' . ($i % 10), $i % 3 ? 'forbeast' : 'botcake', $i % 2 ? ['content' => self::AI_REPLY] : []);
            $this->botOrder('1001', (string) (8000 + $i), '2026-10-21 12:29:2' . ($i % 10), $i % 3 ? 'forbeast' : 'botcake', $i % 4 ? (string) (100 + $i) : null);
        }
        $manyCold = $count(true);
        $this->travel(11)->seconds();
        $manyWarm = $count(false);

        $this->assertSame([$oneCold, $oneWarm], [$manyCold, $manyWarm]);
    }

    // ── Spec 205: ang radar ng bawat bot (Jarvis E) ──────────────────────────────────────────────────────────────────────

    private function radar(array $q = []): array
    {
        return app(LiveFeed::class)->feed($q)['counts']['radar'];
    }

    /** Ang radar na walang key (ang key ay sinusuri nang hiwalay): [ago, state, new, name] kada tuldok. */
    private static function dots(array $list): array
    {
        return array_map(fn ($d) => [$d['ago'], $d['s'], $d['new'], $d['name']], $list);
    }

    private static function key(string $page, string $psid): string
    {
        return substr(hash_hmac('sha256', $page . '|' . $psid, (string) config('app.key')), 0, 12);
    }

    public function test_S_205_2_each_bots_radar_is_its_customers_of_the_last_five_minutes_with_state_and_new_and_the_filters_apply(): void
    {
        $this->seedDay();
        // Ben (may order kanina) ay nag-message ulit 12:29:40: hindi bago, ginto. Dee 12:26: ang AI niya ay may error lang, kaya chat.
        $this->chat('1001', '7002', '2026-10-21 12:29:40', 'forbeast', 'Ben Cruz');
        $dee = $this->chat('1001', '7040', '2026-10-21 12:26:00', 'forbeast', 'Dee Santos');
        $this->ai($dee, '1001', '7040', '2026-10-21 12:26:05', ['error' => 'timeout']);
        $r = $this->radar();

        // Pinakabago muna; ago = segundo mula sa huling mensahe hanggang 12:30:00.
        $this->assertSame([[20, 'order', false, null], [90, 'chat', true, 'Ana R.'], [120, 'ai', true, 'Cara L.'], [240, 'chat', true, 'Dee S.']], self::dots($r['forbeast']));
        $this->assertSame([[240, 'order', true, null]], self::dots($r['botcake'])); // walang pangalan kahit saan: walang tag
        $this->assertSame([[60, 'order', true, 'Fe G.']], self::dots($r['incepxionbot']));
        $this->assertSame([self::key('1001', '7002'), self::key('1001', '7001'), self::key('1002', '7003'), self::key('1001', '7040')], array_column($r['forbeast'], 'k'));
        $this->assertSame(count($r['forbeast']), app(LiveFeed::class)->feed([])['counts']['notes']['forbeast']['chatting']);

        // Page filter: Page Uno lang.
        $uno = $this->radar(['page_id' => '1001']);
        $this->assertSame([[20, 'order', false, null], [90, 'chat', true, 'Ana R.'], [240, 'chat', true, 'Dee S.']], self::dots($uno['forbeast']));
        $this->assertSame([], $uno['botcake']);

        // Bot filter: ang ibang bot ay walang tuldok.
        $only = $this->radar(['bot' => 'botcake']);
        $this->assertSame([[], [], [[240, 'order', true, null]]], [$only['forbeast'], $only['incepxionbot'], self::dots($only['botcake'])]);

        // Dormant na Bot 2: walang tuldok. Lampas 5 minuto: wala na sa radar.
        config(['services.incepxionbot.url' => '']);
        Cache::flush();
        $this->assertSame([], $this->radar()['incepxionbot']);
        $this->travelTo('2026-10-21 12:34:30');
        $this->assertSame([[290, 'order', false, null]], self::dots($this->radar()['forbeast']));
    }

    public function test_S_205_5_names_are_first_name_and_initial_only_escaped_and_nothing_private_is_in_the_json_or_the_page(): void
    {
        $this->chat('1001', '13579246801', '2026-10-21 12:27:00', 'forbeast', 'Maria <b>Santos</b> Dela Cruz');
        $this->chat('1001', '24680135791', '2026-10-21 12:28:00', 'forbeast', '"Joy" & Co');
        $this->chat('1001', '97531864201', '2026-10-21 12:29:00', 'forbeast', 'Ñino');
        $this->chat('1001', '86420975311', '2026-10-21 12:29:30', 'forbeast', '  ');

        $this->assertSame(['', 'Ñino', '"Joy" C.', 'Maria C.'], array_map(fn ($d) => (string) $d['name'], $this->radar()['forbeast']));
        $this->assertSame(['Customer · Page Uno', 'Ñino', '"Joy" C.', 'Maria C.'], array_column(app(LiveFeed::class)->feed([])['counts']['notes']['forbeast']['customers'], 'name'));

        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $json = $this->getJson('/chatbot/live/feed')->assertOk()->getContent();
        $html = $this->get('/chatbot/live')->assertOk()->getContent();
        foreach ([$json, $html] as $body) {
            foreach (['13579246801', '24680135791', '97531864201', '86420975311', 'Santos', 'Dela', '0917', 'Blk 5', 'Magkano'] as $private) $this->assertStringNotContainsString($private, $body, $private);
        }
        $this->assertStringContainsString('Maria C.', $json);
        // Sa page ang JSON ng unang laman ay naka-escape (walang hilaw na < o " mula sa pangalan).
        $this->assertStringNotContainsString('"Joy"', $html);
        $this->assertStringContainsString('\u0022Joy\u0022 C.', $html);
    }

    public function test_S_205_6_the_query_count_of_a_poll_does_not_grow_with_radar_customers(): void
    {
        $count = function (bool $cold) {
            if ($cold) Cache::flush();
            $n = 0;
            DB::listen(function () use (&$n) { $n++; });
            $f = app(LiveFeed::class)->feed(['cursor' => ['chat' => '0', 'seq' => '0', 'order' => '0', 'ok' => '2026-10-21 12:00:00|0', 'ai' => '2026-10-21 12:00:00|0']]);

            return [$n, count($f['counts']['radar']['forbeast']) + count($f['counts']['radar']['botcake'])];
        };
        $this->seedDay();
        $one = [$count(true)];
        $this->travel(11)->seconds();
        $one[] = $count(false);
        $this->travelTo('2026-10-21 12:30:00');
        for ($i = 0; $i < 90; $i++) {
            $psid = (string) (9000 + $i);
            $chat = $this->chat('1001', $psid, '2026-10-21 12:2' . (6 + $i % 4) . ':' . str_pad((string) ($i % 60), 2, '0', STR_PAD_LEFT), $i % 3 ? 'forbeast' : 'botcake', 'Name ' . $i . ' Last');
            if ($i % 2) $this->ai($chat, '1001', $psid, '2026-10-21 12:29:0' . ($i % 10));
            if ($i % 5 === 0) $this->botOrder('1001', $psid, '2026-10-21 12:29:3' . ($i % 10), $i % 3 ? 'forbeast' : 'botcake');
        }
        $many = [$count(true)];
        $this->travel(11)->seconds();
        $many[] = $count(false);

        $this->assertSame(array_column($one, 0), array_column($many, 0));
        $this->assertSame([3, 3], array_column($one, 1));
        $this->assertSame([93, 93], array_column($many, 1));
    }
}
