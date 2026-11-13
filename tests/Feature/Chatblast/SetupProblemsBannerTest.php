<?php

namespace Tests\Feature\Chatblast;

use App\Services\ChatblastSetupCheck;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 171: ang pulang banner ng setup check sa lahat ng screen ng Chatbot at Chatblast habang may page na may X, "token
 * invalid" o "Sent N, none delivered". Ang grey na "Check failed" ay hindi. Ngayon: 2026-10-27 12:00.
 */
class SetupProblemsBannerTest extends ChatblastTestCase
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
        $this->travelTo('2026-10-27 12:00:00');
        foreach (['Page Uno' => '1001', 'Page Dos' => '1002', 'Page Tres' => '1003', 'Page Kwatro' => '1004', 'Page Singko' => '1005'] as $name => $id) {
            $this->page($name, $id);
            DB::table('botcake_chats')->insert(['page_id' => $id, 'page_name' => $name, 'psid' => '9' . $id, 'received_at' => '2026-10-27 08:00:00']);
            // May order sa huling 14 araw: ang X ng setup ng page na pinapadalhan ay urgent pa rin (spec 221).
            DB::table('macro_output')->insert(['PAGE' => $name, 'ts_date' => '2026-10-27']);
        }
    }

    private function check(string $page, array $value): void
    {
        $value += ['at' => '2026-10-27 11:00:00', 'ok' => true, 'x' => [], 'flow' => ['id' => '5501', 'ok' => true], 'field' => ['id' => '7701', 'ok' => true]];
        app(PageSettings::class)->set($page, 'botcake', ChatblastSetupCheck::CHECK_KEY, json_encode($value), false, null, true);
    }

    /** Ang banner ng mga problema (spec 184, pumalit sa banner na ito) bilang text, o null; the spec 245 tab row is not part of the list text. */
    private function banner(string $html): ?string
    {
        return preg_match('#<div[^>]*data-alert="problems"[^>]*>(.*?)</ol>#s', $html, $m)
            ? trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace(['#<span aria-hidden="true">.*?</span>#', '#<span data-ack-(controls|back)[^>]*>.*?</span>#s', '#<div id="cb-problems-tabs"[^>]*>.*?</div>#s'], '', $m[1])))) : null;
    }

    public function test_S_171_4_a_page_with_an_x_shows_on_chatbot_and_chatblast_screens_with_the_reason_and_the_link(): void
    {
        $this->check('Page Uno', ['ok' => false, 'x' => ['2 flows named UPDATE SHIPPING STATUS 2026']]);

        $this->actingAs($this->user());
        foreach (['/chatbot/inbox', '/chatblast/page-settings'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertSame('⚠ 1 page with problems (1 urgent) Page Uno 🟠 No shipping status setup: 2 flows named UPDATE SHIPPING STATUS 2026 Page settings', $this->banner($html), $url);
            $this->assertMatchesRegularExpression('#data-alert="problems".*?href="/chatblast/page-settings\?q=Page\+Uno"#s', $html, 'the page opens filtered');
        }
        $this->check('Page Dos', ['ok' => false, 'x' => ['2 flows named <b>X</b>']]);
        $this->travel(61)->seconds();
        $html = $this->get('/chatbot/inbox')->getContent();
        $this->assertStringContainsString('&lt;b&gt;X&lt;/b&gt;', $html, 'Botcake text is escaped');
        $this->assertStringNotContainsString('<b>X</b>', $html);
    }

    public function test_S_171_4_token_invalid_and_none_delivered_show_and_a_grey_check_failed_does_not(): void
    {
        $this->check('Page Uno', ['error' => 'Botcake: no answer']);
        $this->actingAs($this->user());
        $this->assertNull($this->banner($this->get('/chatbot/inbox')->getContent()), 'Check failed only: no banner');

        $this->check('Page Dos', ['error' => 'token invalid']);
        $this->check('Page Tres', []);
        app(PageSettings::class)->set('Page Tres', 'botcake', ChatblastSetupCheck::DELIVERY_KEY, json_encode(['flow_id' => '5501', 'status' => 'not_delivered', 'sent' => 3]), false, null, true);
        $this->travel(61)->seconds(); // naka-cache hanggang isang minuto
        $this->assertSame('⚠ 2 pages with problems (2 urgent) Page Dos 🟠 Setup: token invalid Page settings Page Tres 🟠 Setup: Sent 3, none delivered (Botcake) Page settings',
            $this->banner($this->get('/chatbot/inbox')->getContent()));
    }

    /** Spec 184: lahat ng page ay nasa listahan (wala nang "+N more"). */
    public function test_S_171_4_every_page_is_listed_and_no_problems_means_no_banner(): void
    {
        $this->actingAs($this->user());
        $this->assertNull($this->banner($this->get('/chatblast/page-settings')->getContent()), 'no check: no banner');
        foreach (['Page Uno', 'Page Dos', 'Page Tres', 'Page Kwatro', 'Page Singko'] as $p) $this->check($p, []);
        $this->assertNull($this->banner($this->get('/chatblast/page-settings')->getContent()), 'all passing: no banner');

        foreach (['Page Uno', 'Page Dos', 'Page Tres', 'Page Kwatro'] as $p) $this->check($p, ['ok' => false, 'x' => ['No custom field named message in Botcake']]);
        $this->travel(61)->seconds();
        $banner = (string) $this->banner($this->get('/chatblast/page-settings')->getContent());
        $this->assertSame(4, substr_count($banner, 'No custom field named message in Botcake'));
        $this->assertStringStartsWith('⚠ 4 pages with problems (4 urgent)', $banner);
    }

    public function test_S_171_5_roles_without_the_chatbot_menu_see_no_banner(): void
    {
        $this->check('Page Uno', ['ok' => false, 'x' => ['2 flows named UPDATE SHIPPING STATUS 2026']]);
        if (!Schema::hasTable('nav_links')) {
            Artisan::call('migrate', ['--path' => 'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php', '--force' => true]);
        }
        $id = DB::table('nav_links')->insertGetId(['key' => 'page_settings_new', 'label' => 'Page settings', 'route_url' => '/chatblast/page-settings', 'icon' => 'fa-solid fa-link',
            'active_pattern' => 'chatblast/page-settings*', 'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('nav_link_role_visibility')->insert(['nav_link_id' => $id, 'role' => 'Marketing', 'is_visible' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->assertNull($this->banner($this->get('/chatblast/page-settings')->assertOk()->getContent()));
    }

    public function test_S_171_6_both_banners_show_the_failing_pages_one_first(): void
    {
        foreach (['11:20:00', '11:30:00', '11:40:00'] as $t) {
            DB::table('chatbot_replies')->insert(['page_id' => 1002, 'psid' => 7001, 'node_id' => 'n2', 'kind' => 'send', 'status' => 'failed',
                'error' => 'Botcake said "You are temporarily restricted from sending messages" (HTTP 200)', 'http' => 200, 'sent_at' => '2026-10-27 ' . $t]);
        }
        for ($i = 0; $i < 7; $i++) { // 3 of 10 (30%): pula ayon sa % ng restricted
            DB::table('chatbot_replies')->insert(['page_id' => 1002, 'psid' => 7001, 'node_id' => 'n2', 'kind' => 'send', 'status' => 'sent', 'sent_at' => '2026-10-27 11:4' . $i . ':30']);
        }
        $this->check('Page Uno', ['ok' => false, 'x' => ['2 flows named UPDATE SHIPPING STATUS 2026']]);

        $this->actingAs($this->user());
        $html = $this->get('/chatbot/inbox')->assertOk()->getContent();
        // Spec 184: iisang banner; ang pulang page ang nauuna.
        $this->assertSame('⚠ 2 pages with problems (2 urgent) Page Dos 🔴 Page restricted by Facebook: 3 of 10 sends failed since 11:20 Health Inbox '
            . 'Page Uno 🟠 No shipping status setup: 2 flows named UPDATE SHIPPING STATUS 2026 Page settings', $this->banner($html));
    }
}
