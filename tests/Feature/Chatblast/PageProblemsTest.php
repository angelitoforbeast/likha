<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\BotHealth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 169: ang page na pumapalya ay makikita agad. Ang "Pages with problems" sa /chatbot/health (huling 60 minuto,
 * kada page) at ang pulang banner sa lahat ng screen ng Chatbot at Chatblast habang may pulang page. Ngayon: 2026-10-27 12:00.
 */
class PageProblemsTest extends ChatblastTestCase
{
    private const RESTRICTED = 'Botcake said "You are temporarily restricted from sending messages" (HTTP 200)';

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
        $this->travelTo('2026-10-27 12:00:00');
        foreach (['Patricia Manalo' => '1001', 'Page Dos' => '1002', 'Page Tres' => '1003', 'Page Kwatro' => '1004', 'Page Singko' => '1005'] as $name => $id) {
            $this->page($name, $id);
            DB::table('botcake_chats')->insert(['page_id' => $id, 'page_name' => $name, 'psid' => '9' . $id, 'received_at' => '2026-10-27 08:00:00']);
        }
    }

    private function send(string $page, string $at, string $status = 'sent', ?string $error = null, ?int $http = null, ?string $raw = null, int $psid = 7001): void
    {
        DB::table('chatbot_replies')->insert(['page_id' => (int) $page, 'psid' => $psid, 'node_id' => 'n2', 'kind' => 'send',
            'status' => $status, 'error' => $error, 'http' => $http, 'raw' => $raw, 'sent_at' => $at]);
    }

    /** $n na padala ng page, isa kada minuto mula $from; ang unang $failed ay pumalya sa $error. */
    private function sends(string $page, int $n, int $failed = 0, string $error = 'Botcake HTTP 500', ?int $http = 500, string $from = '2026-10-27 11:20:00'): void
    {
        for ($i = 0; $i < $n; $i++) {
            $at = date('Y-m-d H:i:s', strtotime($from) + 60 * $i);
            $i < $failed ? $this->send($page, $at, 'failed', $error, $http) : $this->send($page, $at);
        }
    }

    private function problems(): array
    {
        Cache::flush();

        return collect(app(BotHealth::class)->problems())->keyBy('page_id')->all();
    }

    /** Isang nav link gaya ng sa /owner/nav-settings, naka-check para sa mga role na ibinigay. */
    private function navLink(string $key, string $url, array $roles): void
    {
        if (!Schema::hasTable('nav_links')) {
            Artisan::call('migrate', ['--path' => 'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php', '--force' => true]);
        }
        $id = DB::table('nav_links')->insertGetId(['key' => $key, 'label' => $key, 'route_url' => $url, 'icon' => 'fa-solid fa-link', 'active_pattern' => ltrim($url, '/') . '*',
            'is_active' => true, 'sort_order' => DB::table('nav_links')->count() + 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($roles as $role) {
            DB::table('nav_link_role_visibility')->insert(['nav_link_id' => $id, 'role' => $role, 'is_visible' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Ang banner ng mga problema (spec 184, pumalit sa banner na ito) bilang text, o null. */
    private function banner(string $html): ?string
    {
        return preg_match('#<div[^>]*data-alert="problems"[^>]*>(.*?)</ol>#s', $html, $m)
            ? trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace(['#<span aria-hidden="true">.*?</span>#', '#<span data-ack-(controls|back)[^>]*>.*?</span>#s'], '', $m[1])))) : null;
    }

    public function test_S_169_1_a_restricted_page_is_red_and_the_banner_names_it_with_the_since_time(): void
    {
        $this->send('1001', '2026-10-27 10:30:00', 'failed', self::RESTRICTED, 200); // lampas 60 minuto: hindi kasama
        foreach (['11:17:05', '11:20:00', '11:30:00', '11:40:00', '11:55:00'] as $t) $this->send('1001', '2026-10-27 ' . $t, 'failed', self::RESTRICTED, 200);
        $this->sends('1001', 5, 0, from: '2026-10-27 11:56:00'); // 5 of 10 (50%): pula ayon sa % ng restricted

        $p = $this->problems()['1001'];
        $this->assertSame('red', $p['light']);
        $this->assertSame('Page restricted by Facebook', $p['reason']);
        $this->assertSame(5, $p['failed']);
        $this->assertSame('2026-10-27 11:17:05', $p['first_at']);
        $this->assertSame('2026-10-27 11:55:00', $p['last_at']);

        $this->actingAs($this->user());
        foreach (['/chatbot/inbox', '/chatbot/health', '/chatblast/page-settings'] as $url) {
            $banner = $this->banner($this->get($url)->assertOk()->getContent());
            $this->assertSame('⚠ 1 page with problems (1 urgent) Patricia Manalo 🔴 Page restricted by Facebook: 5 of 10 sends failed since 11:17 Health Inbox', $banner, $url);
        }
        $this->assertStringContainsString('href="/chatbot/health?page_id=1001#page-problems"', $this->get('/chatbot/inbox')->getContent());
    }

    /** Ang mga bagong dahilan: restricted (text o subcode 1893063) at permission (#2022, #200, code 10); ang (#10) na window ay window pa rin. */
    public function test_S_169_1_restricted_and_permission_failures_get_their_own_reason(): void
    {
        $cases = [
            [self::RESTRICTED, null, 200, 'Page restricted by Facebook'],
            ['Botcake refused it (HTTP 400)', '{"error":{"message":"x","code":10,"error_subcode":1893063}}', 400, 'Page restricted by Facebook'],
            ['Forbeast could not send: "Blocked" (#10) subcode 1893063', null, null, 'Page restricted by Facebook'],
            ['Botcake said "(#2022) This action is not allowed" (HTTP 400)', null, 400, 'Permission error (#2022 / code 10 / #200)'],
            ['Botcake said "(#200) Requires pages_messaging permission" (HTTP 400)', null, 400, 'Permission error (#2022 / code 10 / #200)'],
            ['Botcake said "Application does not have permission for this action" (HTTP 200)', null, 200, 'Permission error (#2022 / code 10 / #200)'],
            ['Botcake refused it (HTTP 400)', '{"error":{"message":"x","type":"OAuthException","code":10}}', 400, 'Permission error (#2022 / code 10 / #200)'],
            ['Botcake said "(#10) Outside the allowed window." (HTTP 400)', '{"error":{"message":"(#10) This message is sent outside of allowed window.","code":10}}', 400, 'Outside 24-hour window (#10)'],
            ['Botcake said "(#551) This person isn\'t available right now." (HTTP 200)', null, 200, 'Customer not reachable (#551 / isn\'t available)'],
        ];
        foreach ($cases as $i => [$error, $raw, $http]) $this->send('1001', date('Y-m-d H:i:s', strtotime('2026-10-27 11:30:00') + $i), 'failed', $error, $http, $raw);

        Cache::flush();
        $listed = collect(app(BotHealth::class)->output([])['failures'])->sortBy('id')->pluck('reason')->values()->all();
        $this->assertSame(array_column($cases, 3), $listed);
    }

    public function test_S_169_2_failed_percent_of_a_page_with_enough_sends_makes_it_red_or_amber_and_the_thresholds_are_editable(): void
    {
        $this->sends('1002', 12, 4); // 33.3%
        $this->sends('1003', 12, 2);
        $this->send('1003', '2026-10-27 11:50:00', 'partial', 'Forbeast sent 1 of 2', null); // padala, hindi pumalya: 2 / 13 = 15.4%
        $this->sends('1005', 10, 3); // eksaktong 30% sa eksaktong 10 padala
        $this->sends('1004', 9, 4); // 9 padala lang: kulang para sa %

        $p = $this->problems();
        $this->assertSame(['red', 33.3, 12, 4, 'Botcake error (HTTP 5xx)'], [$p['1002']['light'], $p['1002']['failed_pct'], $p['1002']['sends'], $p['1002']['failed'], $p['1002']['reason']]);
        $this->assertSame(['amber', 15.4, 13], [$p['1003']['light'], $p['1003']['failed_pct'], $p['1003']['sends']]);
        $this->assertSame(['red', 30.0], [$p['1005']['light'], $p['1005']['failed_pct']]);
        $this->assertArrayNotHasKey('1004', $p);
        $this->assertSame(['1002', '1005', '1003'], array_map('strval', array_keys($p))); // ang pula muna

        $this->actingAs($this->user());
        $this->post('/chatbot/health/thresholds', ['t' => ['page_failed_pct' => ['amber' => 20, 'red' => 50], 'page_min_sends' => ['amber' => 9, 'red' => '']]])->assertRedirect();
        $p = $this->problems();
        $this->assertSame('amber', $p['1002']['light']);
        $this->assertArrayNotHasKey('1003', $p);
        $this->assertSame('amber', $p['1004']['light']); // 44.4% na may 9 padala
        $this->post('/chatbot/health/thresholds', ['t' => ['page_blocked' => ['amber' => 5, 'red' => 2]]])->assertSessionHasErrors();
    }

    public function test_S_169_3_customer_not_reachable_does_not_count_toward_failed_percent(): void
    {
        $this->sends('1002', 12, 6, 'Botcake said "(#551) This person isn\'t available right now." (HTTP 200)', 200);
        $this->send('1002', '2026-10-27 11:50:00', 'failed', 'Botcake HTTP 502', 502); // 1 sa 13 = 7.7%

        $this->assertSame([], $this->problems());
        $this->actingAs($this->user());
        $this->assertNull($this->banner($this->get('/chatbot/inbox')->getContent()));
    }

    public function test_S_169_4_no_problems_means_no_banner_and_an_empty_table(): void
    {
        $this->sends('1001', 20, 1); // 5%
        $this->send('1002', '2026-10-27 10:00:00', 'failed', self::RESTRICTED, 200); // lampas 60 minuto
        $this->send('1002', '2026-10-27 10:30:00', 'failed', self::RESTRICTED, 200);
        $this->send('1002', '2026-10-27 10:50:00', 'failed', self::RESTRICTED, 200);
        $this->sends('1003', 12, 12, 'Bot paused', null); // skipped ang tingin: ginawa sa ibaba
        DB::table('chatbot_replies')->where('page_id', 1003)->update(['status' => 'skipped']);

        $this->assertSame([], $this->problems());
        $this->actingAs($this->user());
        $html = $this->get('/chatbot/health')->assertOk()->getContent();
        $this->assertNull($this->banner($html));
        $this->assertStringContainsString('No page problems in the last hour', $html);
    }

    public function test_S_169_5_a_row_opens_the_latest_failures_of_its_page(): void
    {
        $long = 'Botcake said "' . str_repeat('x', 200) . '" (HTTP 500)';
        for ($i = 0; $i < 25; $i++) $this->send('1002', date('Y-m-d H:i:s', strtotime('2026-10-27 11:30:00') + $i), 'failed', $i === 24 ? $long : 'Botcake HTTP 500', 500, null, 8000 + $i);
        $this->send('1001', '2026-10-27 11:31:00', 'failed', self::RESTRICTED, 200); // ibang page

        $p = $this->problems()['1002'];
        $this->assertCount(20, $p['failures']);
        $first = $p['failures'][0];
        $this->assertSame(['2026-10-27 11:30:24', 'Botcake error (HTTP 5xx)'], [$first['at'], $first['reason']]);
        $this->assertSame(150, mb_strlen($first['error']));
        $this->assertSame('/chatbot/inbox?page_id=1002&psid=8024', $first['inbox']);
        $this->assertSame('2026-10-27 11:30:05', $p['failures'][19]['at']);

        $this->actingAs($this->user());
        $html = $this->get('/chatbot/health')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<button[^>]*aria-controls="pp-failures-1002"#', $html);
        preg_match('#<tr[^>]*id="pp-failures-1002"[^>]*>(.*?)</tr>\s*(<tr[^>]*data-problem|</tbody>)#s', $html, $m);
        $this->assertNotEmpty($m);
        $this->assertSame(20, substr_count($m[1], 'href="/chatbot/inbox?page_id=1002&amp;psid='));
        $this->assertStringNotContainsString('Patricia', $m[1]);
    }

    public function test_S_169_6_roles_without_the_chatbot_menu_see_no_banner(): void
    {
        foreach (['11:20:00', '11:30:00', '11:40:00'] as $t) $this->send('1001', '2026-10-27 ' . $t, 'failed', self::RESTRICTED, 200);
        $this->navLink('page_settings_new', '/chatblast/page-settings', ['Marketing', 'Marketing - OIC']);
        $this->navLink('chatblast', '/chatblast', ['Marketing - OIC']);

        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->assertNull($this->banner($this->get('/chatblast/page-settings')->assertOk()->getContent()));

        $this->actingAs($this->user('Marketing - OIC', 'oic@example.test'));
        $this->assertStringContainsString('Patricia Manalo 🟠', (string) $this->banner($this->get('/chatblast/page-settings')->assertOk()->getContent()));
    }

    /** Spec 184: ang banner ng mga problema ay naglilista ng lahat ng page (wala nang "+N more"). */
    public function test_S_169_9_the_banner_lists_every_failing_page_and_is_cached_up_to_a_minute(): void
    {
        foreach (['1001', '1002', '1003', '1004'] as $page) {
            foreach (['11:20:00', '11:30:00', '11:40:00'] as $t) $this->send($page, '2026-10-27 ' . $t, 'failed', self::RESTRICTED, 200);
        }
        $this->actingAs($this->user());
        $banner = $this->banner($this->get('/chatbot')->getContent());
        $this->assertSame(4, substr_count($banner, 'Page restricted by Facebook'));
        $this->assertStringStartsWith('⚠ 4 pages with problems (4 urgent)', $banner);

        foreach (['11:50:00', '11:51:00', '11:52:00'] as $t) $this->send('1005', '2026-10-27 ' . $t, 'failed', self::RESTRICTED, 200);
        $this->assertStringStartsWith('⚠ 4 pages', $this->banner($this->get('/chatbot')->getContent())); // naka-cache pa
        $this->travel(61)->seconds();
        $this->assertStringStartsWith('⚠ 5 pages', $this->banner($this->get('/chatbot')->getContent()));
    }

    /** $n na padala ng page sa huling oras, isa kada 10 segundo mula 11:05; ang unang $blocked ay restricted. */
    private function restricted(string $page, int $n, int $blocked): void
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['page_id' => (int) $page, 'psid' => 7001 + $i, 'node_id' => 'n2', 'kind' => 'send', 'status' => $i < $blocked ? 'failed' : 'sent',
                'error' => $i < $blocked ? self::RESTRICTED : null, 'http' => $i < $blocked ? 200 : null, 'raw' => null,
                'sent_at' => date('Y-m-d H:i:s', strtotime('2026-10-27 11:05:00') + 10 * $i)];
        }
        foreach (array_chunk($rows, 100) as $chunk) DB::table('chatbot_replies')->insert($chunk);
    }

    public function test_S_238_1_1_three_of_303_restricted_is_amber_not_red(): void
    {
        $this->restricted('1001', 303, 3); // 1%
        $p = $this->problems()['1001'];
        $this->assertSame(['amber', 3, 303, 'Page restricted by Facebook'], [$p['send_light'], $p['blocked'], $p['sends'], $p['reason']]);

        $this->actingAs($this->user());
        $this->assertStringContainsString('Patricia Manalo 🟠 Page restricted by Facebook: 3 of 303 sends failed since 11:05', (string) $this->banner($this->get('/chatbot')->getContent()));
    }

    public function test_S_238_1_2_ten_of_303_restricted_is_red_by_count(): void
    {
        $this->restricted('1001', 303, 10); // 3.3%: kulang sa 5%, pula dahil sa bilang
        $this->assertSame('red', $this->problems()['1001']['send_light']);
    }

    public function test_S_238_1_3_six_of_100_restricted_is_red_by_percent(): void
    {
        $this->restricted('1001', 100, 6); // 6 ay kulang sa 10, pero 6% ≥ 5%
        $this->assertSame('red', $this->problems()['1001']['send_light']);
    }

    public function test_S_238_1_4_with_too_few_sends_the_percent_does_not_count_and_the_count_decides(): void
    {
        $this->restricted('1001', 8, 4); // 50%, pero 8 padala lang (kailangan 10)
        $this->assertSame('amber', $this->problems()['1001']['send_light']);
    }

    public function test_S_238_1_5_saved_thresholds_without_the_new_key_take_the_default_and_the_form_shows_and_saves_it(): void
    {
        $old = BotHealth::defaultThresholds();
        unset($old['page_blocked_pct']);
        \App\Models\AppSetting::set(BotHealth::SETTING, json_encode($old));
        $this->assertSame(['amber' => 0, 'red' => 5], BotHealth::thresholds()['page_blocked_pct']);

        $this->restricted('1001', 100, 6);
        $this->assertSame('red', $this->problems()['1001']['send_light']);

        $this->actingAs($this->user());
        $html = $this->get('/chatbot/health')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<tr data-rule="page_blocked_pct">.*?restricted or permission failures, % of sends.*?name="t\[page_blocked_pct\]\[red\]" value="5"#s', $html);
        $this->post('/chatbot/health/thresholds', ['t' => ['page_blocked_pct' => ['amber' => 0, 'red' => 10]]])->assertRedirect();
        $this->assertSame(['amber' => 0, 'red' => 10], BotHealth::thresholds()['page_blocked_pct']);
        $this->assertSame('amber', $this->problems()['1001']['send_light']);
    }

    public function test_S_238_1_6_a_page_going_from_red_to_amber_turns_orange_and_orange_still_counts_as_urgent(): void
    {
        $this->restricted('1001', 100, 6);
        $this->actingAs($this->user());
        $this->assertStringStartsWith('⚠ 1 page with problems (1 urgent) Patricia Manalo 🔴', (string) $this->banner($this->get('/chatbot')->getContent()));

        $this->post('/chatbot/health/thresholds', ['t' => ['page_blocked_pct' => ['amber' => 0, 'red' => 10]]])->assertRedirect();
        $this->assertStringStartsWith('⚠ 1 page with problems (1 urgent) Patricia Manalo 🟠', (string) $this->banner($this->get('/chatbot')->getContent()));
    }
}
