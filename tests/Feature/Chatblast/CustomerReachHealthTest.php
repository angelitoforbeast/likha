<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\BotHealth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * /chatbot/health: ang bilang ngayon, kada page, ng padalang nilaktawan dahil hindi maabot ang customer (bot at Chatblast),
 * at hindi ito bilang na pumalya sa mga ilaw, sa kada oras at sa listahan ng pumalya. Nakatakdang data, bilang ng kamay.
 */
class CustomerReachHealthTest extends ChatblastTestCase
{
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
        $this->travelTo('2026-11-05 12:00:00');
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '1002');
        // Ngayon: Page Uno 2 bot (isang #551, isang #10) + 1 Chatblast; Page Dos 1 bot. Hindi kasama: ang switched off,
        // ang kahapon, ang ibang dahilan ng Chatblast, at ang pumalya (hindi skipped) na may parehong text.
        $this->reply('1001', '2026-11-05 08:00:00', 'skipped', "Customer can't be reached (#551) since 2026-11-04 10:00");
        $this->reply('1001', '2026-11-05 09:00:00', 'skipped', "Customer can't be reached (#10) since 2026-11-05 07:00");
        $this->reply('1002', '2026-11-05 10:00:00', 'skipped', "Customer can't be reached (#551) since 2026-11-01 10:00");
        $this->reply('1001', '2026-11-05 10:30:00', 'skipped', 'Replies are switched off');
        $this->reply('1001', '2026-11-04 23:59:59', 'skipped', "Customer can't be reached (#551) since 2026-11-01 10:00");
        $this->reply('1001', '2026-11-05 11:00:00', 'failed', 'Botcake said "(#551) This person isn\'t available right now." (HTTP 200)');
        $this->reply('1001', '2026-11-05 11:30:00', 'sent', null);
        $this->blast('Page Uno', "Customer can't be reached (#551) since 2026-11-04 10:00", '2026-11-05 09:30:00');
        $this->blast('Page Uno', 'Page daily cap reached (3000 today); send again tomorrow', '2026-11-05 09:31:00');
        $this->blast('Page Dos', "Customer can't be reached (#551) since 2026-11-04 10:00", '2026-11-04 22:00:00');
    }

    private function reply(string $page, string $at, string $status, ?string $error): void
    {
        DB::table('chatbot_replies')->insert(['page_id' => (int) $page, 'psid' => 7001, 'node_id' => 'n2', 'kind' => 'send', 'status' => $status,
            'error' => $error, 'http' => null, 'sent_at' => $at]);
    }

    private function blast(string $page, string $reason, string $at): void
    {
        DB::table('botcake_messages')->insert(['page_name' => $page, 'waybill' => uniqid('WB'), 'kind' => 'delivering', 'psid' => '7001', 'status' => 'skipped',
            'reason' => $reason, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function test_S_234_4_2_the_count_per_page_today_matches_the_skipped_rows_with_that_reason(): void
    {
        $out = app(BotHealth::class)->output([]);

        $this->assertSame([
            ['page' => 'Page Uno', 'page_id' => '1001', 'bot' => 2, 'chatblast' => 1],
            ['page' => 'Page Dos', 'page_id' => '1002', 'bot' => 1, 'chatblast' => 0],
        ], $out['unreachable_skips']);
    }

    public function test_S_234_4_1_health_shows_the_skipped_sends_per_page_today(): void
    {
        $this->actingAs($this->user('CEO', 'ceo@example.test'));

        $res = $this->get('/chatbot/health')->assertOk();

        $res->assertSeeInOrder(["Skipped today: customer can't be reached", 'Page Uno', '2', '1', '3', 'Page Dos', '1', '0', '1']);
    }

    public function test_S_234_4_2_these_skips_are_not_counted_as_failed_sends(): void
    {
        $out = app(BotHealth::class)->output([]);

        // Ngayon: 1 sent, 1 failed (#551) at 1 skipped na switched off (ang dating bilang: hindi "sent" ay failed).
        $this->assertSame([1, 2], [$out['tiles']['today']['sent'], $out['tiles']['today']['failed']]);
        $this->assertSame(0, $out['tiles']['today']['reasons']['window'], 'ang (#10) ng laktaw ay wala sa window');
        $this->assertSame(2, array_sum(array_column($out['hourly'], 'failed')));
        // Pinakabago muna.
        $this->assertSame(['Botcake said "(#551) This person isn\'t available right now." (HTTP 200)', 'Replies are switched off'], array_column($out['failures'], 'error'));
    }
}
