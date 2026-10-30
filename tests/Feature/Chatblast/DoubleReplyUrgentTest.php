<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\ChatEchoes;
use App\Services\Chatbot\ProblemsPanel;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Spec 202: ang dobleng sagot ay urgent (orange) lang kapag ang main flow ng Chatbot Builder (o Botcake) at ang main flow
 * ng Likha bot ay parehong sumagot sa BAGONG customer; ang iba (lumang customer sa sequence o loop ng CBB) ay puti.
 * Ngayon: 2026-10-27 12:00; ang window ay 11:00–12:00.
 */
class DoubleReplyUrgentTest extends ChatblastTestCase
{
    private const APP = ['chatbot_builder' => '573598697063667', 'botcake' => '556376998159104', 'forbeast' => '1115162827653315', 'person' => '263902037430900'];
    private const MAIN_FLOW = 7;

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
        Cache::put('chat-echoes:page:1001', now()->getTimestamp(), 3600); // naka-hook sa echo ng Forbeast
    }

    private function chat(string $psid, string $at, string $page = '1001'): void
    {
        DB::table('botcake_chats')->insert(['page_id' => $page, 'page_name' => 'Page Uno', 'psid' => $psid, 'message' => 'hi',
            'payload' => json_encode(['source' => 'gateway']), 'received_at' => $at]);
    }

    private function echo(string $psid, string $sender, string $at): void
    {
        DB::table('chatbot_events')->insert(['page_id' => 1001, 'psid' => (int) $psid, 'type' => ChatEchoes::TYPE, 'created_at' => $at,
            'detail' => json_encode(['mid' => 'm_' . $psid . '_' . $sender . '_' . $at, 'app_id' => self::APP[$sender], 'sender' => $sender, 'hash' => 'x', 'text' => 'Hi'])]);
    }

    private function reply(string $psid, string $at, ?int $flow = self::MAIN_FLOW, ?int $sequence = null): void
    {
        DB::table('chatbot_replies')->insert(['page_id' => 1001, 'psid' => (int) $psid, 'flow_id' => $flow, 'node_id' => 'n1', 'kind' => 'send',
            'status' => 'sent', 'sent_at' => $at, 'sequence_id' => $sequence, 'sequence_step' => $sequence ? 1 : null]);
    }

    /** Isang customer na sinagot ng CBB at ng Likha bot (dalawang echo sa loob ng 60 s) $s segundo pagkatapos ng chat niya sa 11:MM. */
    private function doubled(string $psid, string $hhmm, ?int $flow = self::MAIN_FLOW, ?int $sequence = null, string $cbb = 'chatbot_builder'): void
    {
        $this->chat($psid, '2026-10-27 ' . $hhmm . ':00');
        $this->echo($psid, $cbb, '2026-10-27 ' . $hhmm . ':05');
        $this->reply($psid, '2026-10-27 ' . $hhmm . ':08', $flow, $sequence);
        $this->echo($psid, 'forbeast', '2026-10-27 ' . $hhmm . ':10');
    }

    private function row(): ?array
    {
        Cache::forget(ChatEchoes::HEALTH_CACHE);

        return collect(app(ChatEchoes::class)->health()['pages'])->firstWhere('page_id', '1001');
    }

    /** Ang mga linya ng Page Uno sa panel (kulay, text, mga link). */
    private function lines(): array
    {
        Cache::forget(ChatEchoes::HEALTH_CACHE);
        $page = collect(app(ProblemsPanel::class)->panel()['pages'] ?? [])->firstWhere('page', 'Page Uno');

        return $page['lines'] ?? [];
    }

    private function doubleLines(): array
    {
        return array_values(array_filter($this->lines(), fn ($l) => str_contains($l['text'], 'double') || str_contains($l['text'], 'leftover')));
    }

    public function test_S_202_1a_new_customers_answered_by_cbb_and_the_main_flow_make_the_page_orange_with_three_example_links(): void
    {
        foreach (['7001' => '11:20', '7002' => '11:30', '7003' => '11:40', '7004' => '11:50'] as $psid => $t) $this->doubled($psid, $t);

        $lines = $this->doubleLines();
        $this->assertCount(1, $lines);
        $this->assertSame('orange', $lines[0]['level']);
        $this->assertSame('Main flow double: 4 new customers answered by both CBB and the Likha bot in the last 60 min (new customers: 4, answered by CBB: 4)', $lines[0]['text']);
        $this->assertSame([
            ['Example 1', '/chatbot/inbox?page_id=1001&psid=7004'], ['Example 2', '/chatbot/inbox?page_id=1001&psid=7003'], ['Example 3', '/chatbot/inbox?page_id=1001&psid=7002'],
            ['Health', '/chatbot/health?page_id=1001#who-answered'],
        ], $lines[0]['links'], 'up to 3 examples, latest first; links only, no names or text');
        $this->assertSame(1, app(ProblemsPanel::class)->panel()['urgent']);
        $this->actingAs($this->user());
        $html = $this->get('/chatbot/inbox')->assertOk()->getContent();
        $this->assertStringContainsString('href="/chatbot/inbox?page_id=1001&amp;psid=7004"', $html);
        $this->assertMatchesRegularExpression('#data-help="doubles">[^<]*A customer already known to Chatbot Builder but new to Likha counts as new#', $html);

        // Isa lang: "1 new customer".
        DB::table('botcake_chats')->whereIn('psid', ['7001', '7002', '7003'])->delete();
        DB::table('chatbot_events')->whereIn('psid', [7001, 7002, 7003])->delete();
        $this->assertStringStartsWith('Main flow double: 1 new customer answered by both', $this->doubleLines()[0]['text']);
    }

    public function test_S_202_1b_a_sequence_send_or_another_flow_is_never_the_main_flow_but_an_active_ad_group_flow_is(): void
    {
        $this->doubled('7101', '11:20', self::MAIN_FLOW, 3); // sequence send ng main flow
        $this->doubled('7102', '11:30', 99);                 // ibang flow
        $this->doubled('7103', '11:40', 8);                  // ang flow ng ad group (Ad-based na page)
        $this->doubled('7104', '11:45', null);               // walang flow (hal. Inbox ng tao sa Likha)
        $s = app(PageSettings::class);
        $s->set('Page Uno', 'adgroup', 'mode', 'ad', false, null, true);
        $s->set('Page Uno', 'adgroup', 'g1.name', 'Ad A', false, null, true);
        $s->set('Page Uno', 'adgroup', 'g1.flow_id', '8', false, null, true);

        $row = $this->row();
        $this->assertSame([1, 3, 4], [$row['main_doubles'], $row['leftovers'], $row['doubles']]);
        $this->assertStringContainsString('psid=7103', $row['main_examples'][0]['inbox']);

        // Page-based na page: ang flow ng group ay hindi tumatakbo, kaya hindi main flow.
        $s->set('Page Uno', 'adgroup', 'mode', 'page', false, null, true);
        $this->assertSame([0, 4], [$this->row()['main_doubles'], $this->row()['leftovers']]);
        $this->assertSame(['white'], array_column($this->doubleLines(), 'level'));
    }

    public function test_S_202_2a_old_customers_in_a_cbb_loop_get_a_white_leftover_line_and_no_orange(): void
    {
        // May chat kahapon (luma sa Likha).
        $this->chat('7201', '2026-10-26 10:00:00');
        $this->doubled('7201', '11:20');
        // Walang naunang chat, pero may echo ng CBB (loop) bago ang window: kilala na ng CBB.
        $this->echo('7202', 'chatbot_builder', '2026-10-27 09:00:00');
        $this->doubled('7202', '11:30');
        // May echo ng Botcake sa loob ng window bago ang unang chat.
        $this->echo('7203', 'botcake', '2026-10-27 11:35:00');
        $this->doubled('7203', '11:42', self::MAIN_FLOW, null, 'botcake');

        $lines = $this->doubleLines();
        $this->assertSame(['white'], array_column($lines, 'level'));
        $this->assertSame('3 leftover CBB replies to old customers (sequence/loop) in the last 60 min (new customers: 0, answered by CBB: 0)', $lines[0]['text']);
        $this->assertSame([['Inbox', '/chatbot/inbox?page_id=1001&psid=7203'], ['Health', '/chatbot/health?page_id=1001#who-answered']], $lines[0]['links']);
        $this->assertSame(0, app(ProblemsPanel::class)->panel()['urgent'], 'not urgent');

        DB::table('chatbot_events')->where('psid', '!=', 7201)->delete();
        DB::table('botcake_chats')->where('psid', '!=', '7201')->delete();
        $this->assertSame('1 leftover CBB reply to old customers (sequence/loop) in the last 60 min (new customers: 0, answered by CBB: 0)', $this->doubleLines()[0]['text']);
    }

    public function test_S_202_3a_bot_health_shows_the_same_split_with_the_same_numbers(): void
    {
        $this->doubled('7301', '11:20');                    // main-flow double
        $this->chat('7302', '2026-10-20 10:00:00');
        $this->doubled('7302', '11:30');                    // leftover
        $this->chat('7303', '2026-10-27 11:40:00');         // bago, sinagot lang ng Likha bot
        $this->echo('7303', 'forbeast', '2026-10-27 11:40:04');

        $this->actingAs($this->user());
        $res = $this->get('/chatbot/health')->assertOk();
        $row = collect($res->viewData('echoes')['pages'])->firstWhere('page_id', '1001');
        $this->assertSame([1, 1, 2, 2, 1], [$row['main_doubles'], $row['leftovers'], $row['doubles'], $row['new_customers'], $row['cbb_new']]);
        $html = $res->getContent();
        $this->assertMatchesRegularExpression('#<th[^>]*>Main flow doubles</th>#', $html);
        $this->assertMatchesRegularExpression('#<th[^>]*>Leftover doubles</th>#', $html);
        $this->assertMatchesRegularExpression('#data-main-doubles="1"#', $html);
        $this->assertMatchesRegularExpression('#data-leftovers="1"#', $html);
        $this->assertStringContainsString('2 new, 1 answered by CBB', $html);
        $this->assertStringContainsString('psid=7301', $html);
        $this->assertStringContainsString('known to Chatbot Builder but new to Likha counts as new', $html);

        $panel = array_column($this->doubleLines(), 'text');
        $this->assertSame(['Main flow double: 1 new customer answered by both CBB and the Likha bot in the last 60 min (new customers: 2, answered by CBB: 1)',
            '1 leftover CBB reply to old customers (sequence/loop) in the last 60 min'], $panel);
    }

    public function test_S_202_4a_a_page_whose_cbb_never_answers_new_customers_is_never_orange_for_doubles(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $psid = (string) (7400 + $i);
            $this->chat($psid, '2026-10-27 11:' . (10 + $i * 4) . ':00');
            $this->echo($psid, 'forbeast', '2026-10-27 11:' . (10 + $i * 4) . ':03');
            $this->reply($psid, '2026-10-27 11:' . (10 + $i * 4) . ':02');
        }
        for ($i = 0; $i < 6; $i++) {
            $psid = (string) (7450 + $i);
            $this->chat($psid, '2026-10-25 09:00:00');
            $this->doubled($psid, '11:' . (12 + $i * 7));
        }

        $lines = $this->doubleLines();
        $this->assertSame(['white'], array_column($lines, 'level'));
        $this->assertSame('6 leftover CBB replies to old customers (sequence/loop) in the last 60 min (new customers: 10, answered by CBB: 0)', $lines[0]['text']);
        $this->assertSame(0, app(ProblemsPanel::class)->panel()['urgent']);
    }

    public function test_S_202_5a_the_build_query_count_does_not_grow_with_pairs_or_customers(): void
    {
        $this->doubled('7501', '11:10');
        $this->chat('7502', '2026-10-20 10:00:00');
        $this->doubled('7502', '11:12');
        $count = function () {
            Cache::forget(ChatEchoes::HEALTH_CACHE);
            app(ChatEchoes::class)->health(); // ang cache ng settings ay mainit na
            Cache::forget(ChatEchoes::HEALTH_CACHE);
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(ChatEchoes::class)->health();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $one = $count();

        for ($i = 0; $i < 20; $i++) {
            $this->doubled((string) (7600 + $i), '11:' . (14 + $i * 2));
            $this->chat((string) (7700 + $i), '2026-10-20 10:00:00');
            $this->doubled((string) (7700 + $i), '11:' . (15 + $i * 2));
        }
        $this->assertSame([21, 21], [$this->row()['main_doubles'], $this->row()['leftovers']]);
        $this->assertSame($one, $count(), 'same queries for 2 and 42 pairs');
    }

    public function test_S_202_6a_the_line_shows_new_customers_and_how_many_cbb_answered(): void
    {
        $this->doubled('7601', '11:20');                                                          // CBB + main flow
        $this->chat('7602', '2026-10-27 11:30:00');
        $this->echo('7602', 'chatbot_builder', '2026-10-27 11:30:04');                             // CBB lang
        $this->chat('7603', '2026-10-27 11:40:00');                                               // walang CBB
        $this->chat('7604', '2026-10-27 10:40:00');                                               // bago ang window: hindi bago
        $this->chat('7604', '2026-10-27 11:41:00');

        $row = $this->row();
        $this->assertSame([3, 2, 1], [$row['new_customers'], $row['cbb_new'], $row['main_doubles']]);
        $this->assertStringEndsWith('(new customers: 3, answered by CBB: 2)', $this->doubleLines()[0]['text']);
    }
}
