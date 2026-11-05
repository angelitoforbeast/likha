<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\ChatEchoes;
use App\Services\Chatbot\ProblemsPanel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ang CBB ay ang Chatbot Builder lang: ang echo ng Botcake sa page na Botcake ang route ay ang sagot mismo ng Likha bot
 * (ipinadala sa API ng Botcake), kaya hindi ito dobleng sagot ng ibang bot. Ngayon: 2026-10-27 12:00; ang window ay 11:00–12:00.
 */
class CbbEchoTest extends ChatblastTestCase
{
    private const APP = ['chatbot_builder' => '573598697063667', 'botcake' => '556376998159104', 'forbeast' => '1115162827653315'];
    private const MAIN_FLOW = 7;
    private const PSID = '7001';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_inbox_indexes.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
            'database/migrations/2026_10_29_100000_create_botcake_send_requests_table.php',
            'database/migrations/2026_10_30_098000_add_raw_to_chatbot_replies.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
            'database/migrations/2026_11_06_100000_add_chat_index_to_chatbot_replies.php',
            'database/migrations/2026_11_08_100000_create_chatbot_comment_events_table.php',
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
        ]]);
        $this->travelTo('2026-10-27 12:00:00');
        $this->page('Page Uno', '1001');
        DB::table('page_settings')->insert(['page_name' => 'Page Uno', 'provider' => 'chatbot', 'key' => 'flow_id', 'value' => (string) self::MAIN_FLOW,
            'active' => true, 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00']);
        Cache::put('chat-echoes:page:1001', now()->getTimestamp(), 3600);
        // Bagong customer: ang unang chat niya ay nasa window.
        DB::table('botcake_chats')->insert(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => self::PSID, 'message' => 'hi',
            'payload' => json_encode(['source' => 'gateway']), 'received_at' => '2026-10-27 11:30:00']);
    }

    private function echo(string $sender, string $at, int $n = 0): void
    {
        DB::table('chatbot_events')->insert(['page_id' => 1001, 'psid' => (int) self::PSID, 'type' => ChatEchoes::TYPE, 'created_at' => $at,
            'detail' => json_encode(['mid' => 'm_' . $sender . '_' . $n, 'app_id' => self::APP[$sender], 'sender' => $sender, 'hash' => 'x', 'text' => 'Hi'])]);
    }

    /** Ang main-flow na padala ng Likha bot, 0–1 s pagkatapos ng chat, bawat isa ay may echo ng Botcake. */
    private function likhaSendsViaBotcake(): void
    {
        foreach ([0, 1, 2, 3] as $i) {
            DB::table('chatbot_replies')->insert(['page_id' => 1001, 'psid' => (int) self::PSID, 'flow_id' => self::MAIN_FLOW, 'node_id' => 'n' . $i, 'kind' => 'send',
                'status' => 'sent', 'sent_at' => '2026-10-27 11:30:0' . $i, 'sequence_id' => null, 'sequence_step' => null]);
            $this->echo('botcake', '2026-10-27 11:30:0' . $i, $i);
        }
    }

    private function row(): ?array
    {
        Cache::forget(ChatEchoes::HEALTH_CACHE);

        return collect(app(ChatEchoes::class)->health()['pages'])->firstWhere('page_id', '1001');
    }

    private function mainDoubleLines(): array
    {
        Cache::forget(ChatEchoes::HEALTH_CACHE);
        $page = collect(app(ProblemsPanel::class)->panel()['pages'] ?? [])->firstWhere('page', 'Page Uno');

        return array_values(array_filter($page['lines'] ?? [], fn ($l) => str_contains($l['text'], 'Main flow double')));
    }

    public function test_S_235_1_1_botcake_echoes_of_likha_sends_are_not_a_main_flow_double(): void
    {
        $this->likhaSendsViaBotcake();

        $row = $this->row();
        $this->assertSame([0, 0], [$row['main_doubles'] ?? 0, $row['leftovers'] ?? 0]); // walang row kapag walang doble
        $this->assertSame([], $this->mainDoubleLines());
    }

    public function test_S_235_1_2_a_chatbot_builder_echo_with_likha_sends_is_a_main_flow_double(): void
    {
        $this->likhaSendsViaBotcake();
        $this->echo('chatbot_builder', '2026-10-27 11:30:02');

        $this->assertSame(1, $this->row()['main_doubles']);
        $this->assertCount(1, $this->mainDoubleLines());
    }

    public function test_S_235_1_3_a_botcake_echo_on_a_gateway_page_is_labelled_botcake_and_never_cbb(): void
    {
        $this->echo('botcake', '2026-10-27 11:30:03');
        $this->echo('forbeast', '2026-10-27 11:30:05');

        $this->assertSame('Botcake', ChatEchoes::who(['app_id' => self::APP['botcake'], 'sender' => 'botcake'])[1]);
        $this->assertSame('Bot (Forbeast)', ChatEchoes::latest('1001', self::PSID)['label']);
        $this->echo('botcake', '2026-10-27 11:30:09', 1);
        $this->assertSame('Botcake', ChatEchoes::latest('1001', self::PSID)['label']);

        $row = $this->row();
        $this->assertStringContainsString('Botcake', $row['double_examples'][0]['senders']);
        $this->assertSame([0, 0, 0], [$row['cbb_new'], $row['leftovers'], $row['main_doubles']]);
        $this->assertGreaterThan(0, $row['doubles'], 'a Botcake + Bot pair still counts in doubles');
    }
}
