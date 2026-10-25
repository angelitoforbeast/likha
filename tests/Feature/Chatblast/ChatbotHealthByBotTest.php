<?php

namespace Tests\Feature\Chatblast;

use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * /chatbot/health ayon sa bot: ang filter na Bot (ang provider ng page ngayon; walang setting = Botcake) at ang
 * paghahambing kada bot para sa ngayon at kahapon. Page Uno (1001) ay Forbeast; Page Dos (1002) ay walang provider.
 */
class ChatbotHealthByBotTest extends ChatblastTestCase
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
            'database/migrations/2026_11_08_100000_create_chatbot_comment_events_table.php',
        ]]);
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '1002');
        $s = app(PageSettings::class);
        $s->set('Page Uno', 'chatbot', 'provider', 'gateway');
        $this->travelTo('2026-10-20 15:00:00');
        $s->set('Page Uno', 'chatbot', 'auto_switch', json_encode(['result' => 'switched', 'from' => 'gateway', 'to' => 'botcake', 'at' => now()->toIso8601String()]), false, null, true);
        $this->travelTo('2026-10-21 12:00:00');
        $s->set('Page Uno', 'chatbot', 'auto_switch', json_encode(['result' => 'switched', 'from' => 'gateway', 'to' => 'botcake', 'at' => now()->toIso8601String()]), false, null, true);
        $s->set('Page Uno', 'chatbot', 'auto_switch', json_encode(['result' => 'failed', 'from' => 'gateway', 'to' => null]), false, null, true);
        $this->seed149();
    }

    private function chat(string $page, string $at): int
    {
        return DB::table('botcake_chats')->insertGetId(['page_id' => $page, 'page_name' => $page === '1001' ? 'Page Uno' : 'Page Dos', 'psid' => '7001', 'received_at' => $at]);
    }

    private function reply(string $page, ?int $chat, string $at, string $status = 'sent', ?string $error = null, ?int $http = null): void
    {
        DB::table('chatbot_replies')->insert(['page_id' => (int) $page, 'psid' => 7001, 'chat_id' => $chat, 'node_id' => 'n2', 'kind' => 'send',
            'status' => $status, 'error' => $error, 'http' => $http, 'sent_at' => $at]);
    }

    private function ai(string $page, string $at, string $status): void
    {
        DB::table('chatbot_ai_requests')->insert(['request_id' => uniqid('r', true), 'page_id' => (int) $page, 'psid' => 7001, 'flow_id' => 1, 'node_id' => 'n3',
            'page_name' => 'x', 'model' => 'm', 'messages' => '[]', 'max_tokens' => 10, 'context' => '{}', 'status' => $status, 'attempts' => 1,
            'deadline_at' => $at, 'error' => $status === 'answered' ? null : 'boom', 'answered_at' => $status === 'answered' ? $at : null, 'created_at' => $at, 'updated_at' => $at]);
    }

    private function seed149(): void
    {
        // Forbeast (1001) ngayon: 2 chat, sagot sa 4 s at 20 s, isang "not reachable"; AI 1 ok at 1 failed; 2 comment.
        $a = $this->chat('1001', '2026-10-21 09:00:00');
        $this->reply('1001', $a, '2026-10-21 09:00:04');
        $b = $this->chat('1001', '2026-10-21 09:10:00');
        $this->reply('1001', $b, '2026-10-21 09:10:01', 'failed', 'Gateway said "(#551) This person isn\'t available right now." (HTTP 400)', 400);
        $this->reply('1001', $b, '2026-10-21 09:10:20');
        $this->ai('1001', '2026-10-21 09:00:02', 'answered');
        $this->ai('1001', '2026-10-21 09:10:02', 'failed');
        foreach (['sent', 'failed'] as $i => $status) {
            DB::table('chatbot_comment_events')->insert(['page_id' => 1001, 'comment_id' => 'c' . $i, 'status' => $status, 'received_at' => '2026-10-21 08:00:00']);
        }
        // Botcake (1002, walang provider) ngayon: 2 chat, isa ang nasagot sa 10 s, isang 502; kahapon: 1 chat, 6 s.
        $d = $this->chat('1002', '2026-10-21 10:00:00');
        $this->reply('1002', $d, '2026-10-21 10:00:10');
        $this->chat('1002', '2026-10-21 10:30:00');
        $this->reply('1002', $d, '2026-10-21 10:40:00', 'failed', 'Botcake HTTP 502', 502);
        $y = $this->chat('1002', '2026-10-20 09:00:00');
        $this->reply('1002', $y, '2026-10-20 09:00:06');
    }

    public function test_S_149_15_the_bot_filter_scopes_the_tiles_and_a_page_without_a_provider_counts_as_botcake(): void
    {
        $this->actingAs($this->user());

        $tiles = fn (string $q) => $this->get('/chatbot/health' . $q)->assertOk()->viewData('tiles')['today'];
        $pick = fn (array $t) => [$t['inbound'], $t['sent'], $t['failed'], $t['ai_calls'], $t['comments_in']];
        $this->assertSame([4, 3, 2, 2, 2], $pick($tiles('')));
        $this->assertSame([2, 2, 1, 2, 2], $pick($tiles('?bot=Forbeast')));
        $this->assertSame([2, 1, 1, 0, 0], $pick($tiles('?bot=Botcake')));
        $this->assertSame([4, 3, 2, 2, 2], $pick($tiles('?bot=Nope')), 'an unknown bot is ignored');
        $this->assertSame(['Page Dos'], array_column($this->get('/chatbot/health?bot=Botcake')->viewData('failures'), 'page'), 'the lists are scoped too');

        $html = $this->get('/chatbot/health?bot=Forbeast')->getContent();
        $this->assertStringContainsString('<option value="Forbeast" selected>Forbeast</option>', $html);
        $this->assertStringContainsString('<option value="">Lahat</option>', $html);
    }

    public function test_S_149_16_the_comparison_has_one_row_per_bot_for_today_and_yesterday(): void
    {
        $this->actingAs($this->user());

        $compare = $this->get('/chatbot/health')->assertOk()->viewData('compare');

        $this->assertSame(['Botcake', 'Forbeast'], array_column($compare, 'bot'));
        $this->assertSame([
            'inbound' => 2, 'answered_pct' => 50.0, 'reply_median' => 10, 'reply_p90' => 10, 'failed_pct_excl' => 50.0,
            'top_reasons' => [['Botcake error (HTTP 5xx)', 1]], 'ai_failed_pct' => null, 'comments_in' => 0, 'comments_replied' => 0, 'auto_switches' => 0,
        ], $compare[0]['today']);
        $this->assertSame([1, 100.0, 6], [$compare[0]['yesterday']['inbound'], $compare[0]['yesterday']['answered_pct'], $compare[0]['yesterday']['reply_median']]);
        $this->assertSame([
            'inbound' => 2, 'answered_pct' => 100.0, 'reply_median' => 12, 'reply_p90' => 20, 'failed_pct_excl' => 0.0,
            'top_reasons' => [['Customer not reachable (#551 / isn\'t available)', 1]], 'ai_failed_pct' => 50.0, 'comments_in' => 2, 'comments_replied' => 1, 'auto_switches' => 1,
        ], $compare[1]['today']);
        $this->assertSame(1, $compare[1]['yesterday']['auto_switches']);
        $this->assertStringContainsString('Compare bots', $this->get('/chatbot/health')->getContent());
    }
}
