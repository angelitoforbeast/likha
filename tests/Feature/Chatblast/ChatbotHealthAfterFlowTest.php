<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\BotHealth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Spec 153: ang Bot Health ay bumibilang lang ng chat na dumating habang may flow ang page (chatbot.flow_id na may value,
 * ayon sa history ng row). Bago ang flow, ang sariling bot ng Botcake ang sumasagot, na hindi namin nakikita.
 * Ngayon: 2026-10-26.
 */
class ChatbotHealthAfterFlowTest extends ChatblastTestCase
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
        $this->travelTo('2026-10-26 20:00:00');
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '1002');
        $this->page('Page Tres', '1003');
    }

    private function flow(string $page, string $value, string $from, ?string $until = null): void
    {
        DB::table('page_settings')->insert(['page_name' => $page, 'provider' => 'chatbot', 'key' => 'flow_id', 'value' => $value, 'is_secret' => false,
            'active' => $until === null, 'replaced_at' => $until, 'created_at' => $from, 'updated_at' => $from]);
    }

    /** Isang chat, at ang sagot nito $secs segundo pagkatapos (null = walang sagot). */
    private function chat(string $pageId, string $at, ?int $secs = 5): int
    {
        $id = DB::table('botcake_chats')->insertGetId(['page_id' => $pageId, 'page_name' => 'x', 'psid' => (string) random_int(1, 1e9), 'received_at' => $at]);
        if ($secs !== null) {
            DB::table('chatbot_replies')->insert(['page_id' => (int) $pageId, 'psid' => 7001, 'chat_id' => $id, 'node_id' => 'n2', 'kind' => 'send',
                'status' => 'sent', 'sent_at' => date('Y-m-d H:i:s', strtotime($at) + $secs)]);
        }

        return $id;
    }

    private function out(array $q = []): array
    {
        Cache::flush();

        return app(BotHealth::class)->output($q);
    }

    public function test_S_153_1_chats_before_the_page_got_its_flow_are_not_counted(): void
    {
        $this->flow('Page Uno', '77', '2026-10-26 17:33:00');
        $this->chat('1001', '2026-10-26 10:00:00', null); // sariling bot ng Botcake: hindi namin nakikita ang sagot
        $this->chat('1001', '2026-10-26 10:05:00', 900); // sinagot pa rin namin nang huli, pero bago ang flow
        $this->chat('1001', '2026-10-26 17:40:00', 7);
        $this->chat('1001', '2026-10-26 17:50:00', null);

        $out = $this->out();
        $t = $out['tiles']['today'];
        $this->assertSame(2, $t['inbound']);
        $this->assertSame(1, $t['answered']);
        $this->assertSame(1, $t['not_answered']);
        $this->assertEquals(50.0, $t['answered_pct']);
        $this->assertEquals(7, $t['reply_max']);
        $this->assertSame(0, $out['hourly'][10]['answered']);
        $this->assertSame(1, $out['hourly'][17]['answered']);
        $this->assertSame([7], array_column($out['slow'], 'seconds'));
        $this->assertSame(2, $out['compare'][0]['today']['inbound']); // Botcake
    }

    public function test_S_153_2_a_removed_flow_stops_the_count_and_a_page_without_a_flow_counts_none(): void
    {
        $this->flow('Page Uno', '77', '2026-10-26 08:00:00', '2026-10-26 15:00:00');
        $this->flow('Page Uno', '', '2026-10-26 15:00:00'); // ang pumalit: walang flow
        $this->chat('1001', '2026-10-26 09:00:00');
        $this->chat('1001', '2026-10-26 14:59:00');
        $this->chat('1001', '2026-10-26 15:00:00'); // sa oras ng palit: wala nang flow
        $this->chat('1001', '2026-10-26 16:00:00');
        $this->chat('1002', '2026-10-26 11:00:00'); // Page Dos: walang flow kailanman

        $this->assertSame(2, $this->out()['tiles']['today']['inbound']);
        $this->assertSame(0, $this->out(['page_id' => '1002'])['tiles']['today']['inbound']);
    }

    public function test_S_153_3_the_shape_of_a_page_getting_its_flow_late_gives_the_answered_share_of_chats_after_flows(): void
    {
        // Page Uno: flow mula 17:33, 30 chat bago (hindi namin sinagot); Page Dos at Tres: may flow buong araw.
        $this->flow('Page Uno', '77', '2026-10-26 17:33:00');
        $this->flow('Page Dos', '78', '2026-10-01 00:00:00');
        $this->flow('Page Tres', '79', '2026-10-01 00:00:00');
        for ($i = 0; $i < 30; $i++) $this->chat('1001', sprintf('2026-10-26 10:%02d:00', $i), null);
        for ($i = 0; $i < 5; $i++) $this->chat('1001', sprintf('2026-10-26 18:%02d:00', $i));
        for ($i = 0; $i < 10; $i++) $this->chat('1002', sprintf('2026-10-26 11:%02d:00', $i));
        for ($i = 0; $i < 5; $i++) $this->chat('1003', sprintf('2026-10-26 12:%02d:00', $i), $i === 0 ? null : 5);

        $t = $this->out()['tiles']['today'];
        $this->assertSame(20, $t['inbound']);
        $this->assertSame(19, $t['answered']);
        $this->assertEquals(95.0, $t['answered_pct']); // dati: 19 / 50 = 38 %
    }
}
