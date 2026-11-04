<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\ForbeastWatch;
use App\Services\ChatblastSetupCheck;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 184: iisang banner ng mga problema sa lahat ng screen ng Chatbot at Chatblast, sarado muna ("⚠ N pages with
 * problems (U urgent)"); pag-click, bawat page at lahat ng problema nito, may kulay at link. Ngayon: 2026-10-27 12:00.
 */
class ProblemsPanelTest extends ChatblastTestCase
{
    private const RESTRICTED = 'Botcake said "You are temporarily restricted from sending messages" (HTTP 200)';
    private const PAGES = ['Page Uno' => '1001', 'Page Dos' => '1002', 'Page Tres' => '1003', 'Page Kwatro' => '1004'];

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
        foreach (self::PAGES as $name => $id) {
            $this->page($name, $id);
            DB::table('page_settings')->insert(['page_name' => $name, 'provider' => 'chatbot', 'key' => 'flow_id', 'value' => '1', 'active' => true,
                'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00']);
            // May order sa huling 14 araw: ang X ng setup ng page na pinapadalhan ay urgent pa rin (spec 221).
            DB::table('macro_output')->insert(['PAGE' => $name, 'ts_date' => '2026-10-27']);
        }
    }

    private function check(string $page, array $x): void
    {
        app(PageSettings::class)->set($page, 'botcake', ChatblastSetupCheck::CHECK_KEY, json_encode(['at' => '2026-10-27 11:00:00', 'ok' => false, 'x' => $x]), false, null, true);
    }

    private function chat(string $id, string $psid, string $at, array $payload = []): void
    {
        DB::table('botcake_chats')->insert(['page_id' => $id, 'page_name' => array_search($id, self::PAGES, true), 'psid' => $psid, 'message' => 'hi',
            'payload' => json_encode($payload ?: (object) []), 'received_at' => $at]);
    }

    private function reply(string $id, string $psid, string $at, string $status = 'sent', ?string $error = null): void
    {
        DB::table('chatbot_replies')->insert(['page_id' => (int) $id, 'psid' => (int) $psid, 'node_id' => 'n1', 'kind' => 'send', 'status' => $status,
            'error' => $error, 'http' => $error ? 200 : null, 'sent_at' => $at]);
    }

    /** Ang mga page sa listahan, bawat isa: pangalan, kulay at ang mga linya (kulay, text, mga link). */
    private function panel(string $url = '/chatbot/inbox'): ?array
    {
        $html = $this->get($url)->assertOk()->getContent();
        if (!preg_match('#<div[^>]*data-alert="problems"[^>]*>(.*?)</ol>#s', $html, $m)) return null;
        $clean = fn ($s) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($s))));
        preg_match('#<button[^>]*>(.*?)</button>#s', $m[1], $b);
        $pages = [];
        // Ang mga button ng Acknowledge/Done (at ang tanda ng bumalik na ack) ay hindi bahagi ng text ng linya.
        $m[1] = (string) preg_replace('#<span data-ack-(controls|back)[^>]*>.*?</span>#s', '', $m[1]);
        preg_match_all('#<li data-page="([^"]*)" data-level="(\w+)"[^>]*>(.*?)</ul>\s*</li>#s', $m[1], $rows, PREG_SET_ORDER);
        foreach ($rows as [, $page, $level, $body]) {
            preg_match_all('#<li data-level="(\w+)"[^>]*>(.*?)</li>#s', $body, $lines, PREG_SET_ORDER);
            $pages[] = ['page' => html_entity_decode($page), 'level' => $level, 'lines' => array_map(function ($l) use ($clean) {
                preg_match_all('#href="([^"]+)"#', $l[2], $h);
                return ['level' => $l[1], 'text' => $clean($l[2]), 'links' => array_map('html_entity_decode', $h[1])];
            }, $lines)];
        }

        return ['line' => $clean(preg_replace('#<span aria-hidden="true">.*?</span>#', '', $b[1] ?? '')), 'button' => $b[0] ?? '', 'html' => $m[0], 'pages' => $pages];
    }

    public function test_S_184_1_the_collapsed_line_counts_pages_and_urgent_ones_and_the_list_is_ordered_by_severity(): void
    {
        $this->check('Page Tres', ['No custom field named message in Botcake']);
        DB::table('chatblast_campaigns')->update(['pages' => json_encode(['Page Uno', 'Page Dos'])]); // Page Tres: hindi pinapadalhan
        $this->check('Page Dos', ['2 flows named UPDATE SHIPPING STATUS 2026']);
        foreach (['11:20:00', '11:30:00', '11:40:00'] as $t) $this->reply('1001', '7001', '2026-10-27 ' . $t, 'failed', self::RESTRICTED);

        $this->actingAs($this->user());
        foreach (['/chatbot/inbox', '/chatblast/page-settings'] as $url) {
            $p = $this->panel($url);
            $this->assertSame('⚠ 3 pages with problems (2 urgent)', $p['line'], $url);
        }
        $this->assertSame(['Page Uno', 'Page Dos', 'Page Tres'], array_column($p['pages'], 'page'));
        $this->assertSame(['red', 'orange', 'white'], array_column($p['pages'], 'level'));
        $this->assertStringContainsString('🔴 Page restricted by Facebook: 3 of 3 sends failed since 11:20', $p['pages'][0]['lines'][0]['text']);
        $this->assertSame(['/chatbot/health?page_id=1001#page-problems', '/chatbot/inbox?page_id=1001'], $p['pages'][0]['lines'][0]['links']);

        // Sarado muna, at ang pagbukas ay naaalala ng browser (localStorage, sa loob ng try/catch).
        $this->assertStringContainsString('aria-expanded="false"', $p['button']);
        $this->assertMatchesRegularExpression('#<ol id="cb-problems-list"[^>]*hidden#', $p['html']);
        $html = $this->get('/chatbot/inbox')->getContent();
        $this->assertMatchesRegularExpression('#try \{[^}]*localStorage#', $html);
        $this->assertStringNotContainsString('data-alert="page-problems"', $html, 'the old banners are replaced');
        $this->assertStringNotContainsString('data-alert="setup-problems"', $html);
    }

    public function test_S_184_2_a_page_with_a_setup_x_unanswered_customers_and_a_watcher_move_shows_all_three_with_links(): void
    {
        $this->check('Page Uno', ['No flow named UPDATE SHIPPING STATUS 2026']);
        Cache::put('chat-echoes:page:1001', now()->getTimestamp(), 3600); // naka-hook sa echo ng Forbeast
        foreach (['8001', '8002', '8003', '8004'] as $i => $psid) $this->chat('1001', $psid, '2026-10-27 11:4' . $i . ':00');
        $this->travelTo('2026-10-27 10:15:00');
        app(ForbeastWatch::class)->event('Page Uno', ForbeastWatch::SWITCHED, 'botcake', 'health', 'no answer for 3 checks');
        $this->travelTo('2026-10-27 05:30:00');
        app(ForbeastWatch::class)->event('Page Dos', ForbeastWatch::HELD, null, 'inbound', 'held: Likha ingest lag 90 s'); // lampas 6 oras
        $this->travelTo('2026-10-27 12:00:00');

        $this->actingAs($this->user());
        $p = $this->panel();
        $this->assertSame('⚠ 1 page with problems (1 urgent)', $p['line']);
        $this->assertSame('Page Uno', $p['pages'][0]['page']);
        $lines = $p['pages'][0]['lines'];
        $this->assertCount(3, $lines);
        $this->assertSame(['red', 'orange', 'orange'], array_column($lines, 'level'));
        $this->assertStringStartsWith('🔴 4 customers not answered in 5 min', $lines[0]['text']);
        $this->assertSame(['/chatbot/inbox?page_id=1001&psid=8004', '/chatbot/health?page_id=1001#who-answered'], $lines[0]['links']);
        $this->assertStringStartsWith('🟠 Moved to Botcake at 10:15: Forbeast health check failed: no answer for 3 checks', $lines[1]['text']);
        $this->assertSame(['/chatbot/pages?changes=Page+Uno&field=auto_switch'], $lines[1]['links']);
        $this->assertStringStartsWith('🟠 Setup: No flow named UPDATE SHIPPING STATUS 2026', $lines[2]['text']);
        $this->assertSame(['/chatblast/page-settings?q=Page+Uno'], $lines[2]['links']);
    }

    public function test_S_184_3_a_setup_x_on_a_page_chatblast_does_not_send_to_is_white_not_orange(): void
    {
        DB::table('chatblast_campaigns')->update(['pages' => json_encode(['Page Dos'])]);
        $this->check('Page Uno', ['No custom field named message in Botcake']);

        $this->actingAs($this->user());
        $p = $this->panel();
        $this->assertSame('⚠ 1 page with problems (0 urgent)', $p['line']);
        $this->assertSame([['level' => 'white', 'text' => '⚪ Not sending, setup incomplete: No custom field named message in Botcake Page settings',
            'links' => ['/chatblast/page-settings?q=Page+Uno']]], $p['pages'][0]['lines']);
    }

    /** 6 customer kada araw sa 11:30 (huling 7 araw), sinagot sa $usual segundo; ngayong araw sa $now segundo. */
    public static function speeds(): array
    {
        return [
            '70 s vs usual 4 s: slow' => [4, 70, '🟠 Slow: median first reply 70 s in the last hour (usual 4 s at this hour)'],
            '12 s vs usual 4 s: 3× is slow' => [4, 12, '🟠 Slow: median first reply 12 s in the last hour (usual 4 s at this hour)'],
            '11 s vs usual 4 s: not slow' => [4, 11, null],
            '60 s vs usual 50 s: 60 s is slow' => [50, 60, '🟠 Slow: median first reply 60 s in the last hour (usual 50 s at this hour)'],
        ];
    }

    /** @dataProvider speeds */
    public function test_S_184_4_a_page_much_slower_than_its_usual_at_this_hour_is_slow(int $usual, int $now, ?string $line): void
    {
        for ($d = 0; $d <= 7; $d++) {
            for ($i = 0; $i < 6; $i++) {
                $at = strtotime('2026-10-27 11:30:00') - $d * 86400 + $i * 60;
                $this->chat('1002', (string) (5000 + $i), date('Y-m-d H:i:s', $at));
                $this->reply('1002', (string) (5000 + $i), date('Y-m-d H:i:s', $at + ($d === 0 ? $now : $usual)));
            }
        }

        $this->actingAs($this->user());
        $p = $this->panel();
        if ($line === null) {
            $this->assertNull($p);
            return;
        }
        $this->assertSame([['level' => 'orange', 'text' => $line . ' Speed', 'links' => ['/chatbot/speed?page_id=1002']]], $p['pages'][0]['lines']);
    }

    public function test_S_184_5_no_chats_fires_only_for_a_bot_on_page_with_its_usual_volume_at_this_hour(): void
    {
        // Huling 7 araw, 11:00–12:00: Uno 25 kada oras (bot On), Dos 25 (bot Off), Tres 5, Kwatro 25 pero may chat sa 11:45 ngayon.
        foreach (['1001' => 25, '1002' => 25, '1003' => 5, '1004' => 25] as $id => $n) {
            for ($d = 1; $d <= 7; $d++) {
                for ($i = 0; $i < $n; $i++) $this->chat($id, (string) (6000 + $i), date('Y-m-d H:i:s', strtotime('2026-10-27 11:00:00') - $d * 86400 + $i * 120));
            }
        }
        $this->chat('1004', '6999', '2026-10-27 11:45:00');
        $this->chat('1001', '6998', '2026-10-27 11:20:00'); // lampas 30 minuto: wala pa ring chat sa huling 30
        $this->chat('1001', '6997', '2026-10-27 11:50:00', ['_ad_open' => true]); // S-204.6: ang pagbukas ng ad ay hindi chat
        app(PageSettings::class)->set('Page Dos', 'chatbot', 'active', '0', false, null, true);

        $this->actingAs($this->user());
        $p = $this->panel();
        $this->assertSame(['Page Uno'], array_column($p['pages'], 'page'));
        $this->assertSame([['level' => 'white', 'text' => '⚪ No chats in the last 30 min (usually 25 an hour) Inbox Speed',
            'links' => ['/chatbot/inbox?page_id=1001', '/chatbot/speed?page_id=1001']]], $p['pages'][0]['lines']);
    }

    public function test_S_184_6_no_problems_means_no_banner_and_it_is_cached_up_to_a_minute(): void
    {
        $this->reply('1001', '7001', '2026-10-27 11:30:00');
        $this->actingAs($this->user());
        $this->assertNull($this->panel());
        $this->assertNull($this->panel('/chatblast/page-settings'));

        $this->check('Page Uno', ['No flow named X']);
        $this->assertNull($this->panel(), 'cached');
        $this->travel(61)->seconds();
        $this->assertSame('⚠ 1 page with problems (1 urgent)', $this->panel()['line']);
    }

    public function test_S_184_7_roles_without_the_chatbot_menu_see_nothing(): void
    {
        $this->check('Page Uno', ['No flow named X']);
        if (!Schema::hasTable('nav_links')) {
            Artisan::call('migrate', ['--path' => 'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php', '--force' => true]);
        }
        $id = DB::table('nav_links')->insertGetId(['key' => 'page_settings_new', 'label' => 'Page settings', 'route_url' => '/chatblast/page-settings', 'icon' => 'fa-solid fa-link',
            'active_pattern' => 'chatblast/page-settings*', 'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('nav_link_role_visibility')->insert(['nav_link_id' => $id, 'role' => 'Marketing', 'is_visible' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->assertNull($this->panel('/chatblast/page-settings'));
    }

    public function test_S_184_9_the_collapsed_line_stays_one_line_and_the_list_scrolls_on_a_phone(): void
    {
        $this->check('Page Uno', ['No flow named X']);
        $this->actingAs($this->user());
        $p = $this->panel();
        $this->assertMatchesRegularExpression('#class="[^"]*\btruncate\b[^"]*"#', $p['button'], 'one line, cut with … when too long');
        $this->assertMatchesRegularExpression('#<ol id="cb-problems-list" class="[^"]*\bmax-h-\[60vh\][^"]*\boverflow-y-auto\b#', $p['html']);
        $this->assertStringContainsString('break-words', $p['html']);
    }

    /** Ang kind at level ng unang linya ng isang page sa banner. */
    private function firstKind(string $page): array
    {
        $html = $this->get('/chatbot/inbox')->getContent();
        preg_match('#data-status="ack" data-page="' . preg_quote($page, '#') . '" data-kind="([0-9a-f]{12})" data-level="(\w+)"#', $html, $m);

        return [$m[1] ?? null, $m[2] ?? null];
    }

    public function test_acknowledge_with_a_note_hides_a_line_until_it_gets_worse_and_records_who(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $this->check('Page Dos', ['2 flows named UPDATE SHIPPING STATUS 2026']);
        $this->assertSame('⚠ 1 page with problems (1 urgent)', $this->panel()['line']);
        [$kind, $level] = $this->firstKind('Page Dos');
        $this->assertNotNull($kind);

        // Walang note: hindi tinatanggap.
        $this->postJson('/chatbot/problems/ack', ['page' => 'Page Dos', 'kind' => $kind, 'status' => 'ack', 'note' => ''])->assertStatus(422);
        $this->postJson('/chatbot/problems/ack', ['page' => 'Not Listed', 'kind' => $kind, 'status' => 'ack', 'note' => 'x'])->assertStatus(422);
        $this->postJson('/chatbot/problems/ack', ['page' => 'Page Dos', 'kind' => $kind, 'status' => 'ack', 'note' => 'Franz is fixing the flow names', 'level' => $level])->assertOk();

        $p = $this->panel();
        $this->assertSame('⚠ 0 pages with problems (0 urgent)', $p['line']);
        $this->assertStringContainsString('Acknowledged and done (1)', $p['html']);
        $this->assertStringContainsString('Franz is fixing the flow names', $p['html']);
        $row = \App\Models\PageSetting::where('provider', 'chatbot')->where('key', 'problem_ack.' . $kind)->where('active', true)->sole();
        $this->assertSame($user->id, (int) $row->created_by, 'who acknowledged it is on the row');

        // Pagkalipas ng 1 araw (walang piniling petsa): bumabalik, may tanda kung sino ang nag-ack.
        $this->travel(25)->hours();
        Cache::flush();
        $back = $this->get('/chatbot/inbox')->getContent();
        $this->assertStringContainsString('the acknowledgement ended', $back);

        // Undo: tinatanggal ang ack.
        $this->postJson('/chatbot/problems/ack', ['page' => 'Page Dos', 'kind' => $kind, 'status' => 'clear'])->assertOk();
        $this->assertNull(app(\App\Services\Chatbot\ProblemAcks::class)->state('Page Dos', $kind, 'orange'));
    }

    public function test_done_starts_the_count_from_zero_so_only_failures_after_it_bring_the_line_back(): void
    {
        $this->actingAs($this->user());
        foreach (['11:20:00', '11:30:00', '11:40:00'] as $t) $this->reply('1001', '7001', '2026-10-27 ' . $t, 'failed', self::RESTRICTED);
        [$kind, $level] = $this->firstKind('Page Uno');
        $this->postJson('/chatbot/problems/ack', ['page' => 'Page Uno', 'kind' => $kind, 'status' => 'done', 'note' => 'ads paused', 'level' => $level])->assertOk();
        $this->assertSame('⚠ 0 pages with problems (0 urgent)', $this->panel()['line']);

        // 12:31: ang 11:40 na bigo (bago ang Done) ay nasa huling 60 minuto pa, kaya hindi ito bilang: nakatago pa.
        $this->travel(31)->minutes();
        Cache::flush();
        $this->assertSame('⚠ 0 pages with problems (0 urgent)', $this->panel()['line'], 'only what failed before Done: still hidden');

        // 13:10: lumabas na sa window ang mga bigo bago ang Done; mga bagong bigo pagkatapos ng Done ang nagpapabalik dito.
        $this->travelTo('2026-10-27 13:10:00');
        foreach (['12:50:00', '13:00:00', '13:05:00'] as $t) $this->reply('1001', '7001', '2026-10-27 ' . $t, 'failed', self::RESTRICTED);
        Cache::flush();
        $html = $this->get('/chatbot/inbox')->getContent();
        $this->assertStringContainsString('new since it was marked done', $html);
        $this->assertStringContainsString('"ads paused"', $html);
    }

    public function test_done_on_a_state_like_an_incomplete_setup_comes_back_after_30_minutes_while_it_is_still_incomplete(): void
    {
        $this->actingAs($this->user());
        $this->check('Page Dos', ['2 flows named UPDATE SHIPPING STATUS 2026']);
        [$kind, $level] = $this->firstKind('Page Dos');
        $this->postJson('/chatbot/problems/ack', ['page' => 'Page Dos', 'kind' => $kind, 'status' => 'done', 'note' => 'renamed', 'level' => $level])->assertOk();
        $this->assertSame('⚠ 0 pages with problems (0 urgent)', $this->panel()['line']);
        $this->travel(31)->minutes();
        Cache::flush();
        $this->assertStringContainsString('still happening after it was marked done', $this->get('/chatbot/inbox')->getContent());
    }

    public function test_the_kind_ignores_counts_and_times_and_an_ack_until_a_date_or_a_worse_level_returns_it(): void
    {
        $a = \App\Services\Chatbot\ProblemAcks::kind('Page restricted by Facebook: 57 of 58 sends failed since 16:08');
        $this->assertSame($a, \App\Services\Chatbot\ProblemAcks::kind('Page restricted by Facebook: 60 of 61 sends failed since 16:40'));
        $this->assertNotSame($a, \App\Services\Chatbot\ProblemAcks::kind('Moved to Botcake at 15:16: Meta connection error'));

        $acks = app(\App\Services\Chatbot\ProblemAcks::class);
        $user = $this->user();
        $this->assertSame(['ok' => false, 'error' => 'Pick a time in the future.'], $acks->set('Page Uno', $a, 'ack', 'until restriction ends', '2026-10-01', 'orange', $user));
        $this->assertTrue($acks->set('Page Uno', $a, 'ack', 'restricted until Dec 1', '2026-12-01', 'orange', $user)['ok']);
        $this->assertTrue($acks->state('Page Uno', $a, 'orange')['hidden']);
        $this->assertSame('2026-12-01 23:59', $acks->state('Page Uno', $a, 'orange')['until']);
        $this->assertSame(['hidden' => false, 'back' => 'got worse since it was acknowledged'], array_intersect_key($acks->state('Page Uno', $a, 'red'), ['hidden' => 1, 'back' => 1]));
    }

    public function test_the_ceo_acknowledges_or_marks_all_done_with_one_note_and_no_one_else_can(): void
    {
        $this->check('Page Dos', ['2 flows named UPDATE SHIPPING STATUS 2026']);
        foreach (['11:20:00', '11:30:00', '11:40:00'] as $t) $this->reply('1001', '7001', '2026-10-27 ' . $t, 'failed', self::RESTRICTED);
        $this->actingAs($this->user());
        $html = $this->get('/chatbot/inbox')->getContent();
        $this->assertStringContainsString('Acknowledge all</button>', $html);
        $this->assertStringContainsString('Done all</button>', $html);
        $this->assertStringContainsString('id="cb-ack-form"', $html, 'one form with the note and how long');
        $this->assertStringNotContainsString('prompt(', $html, 'no browser prompts');

        $this->postJson('/chatbot/problems/ack-all', ['status' => 'ack', 'note' => ''])->assertStatus(422);
        $this->postJson('/chatbot/problems/ack-all', ['status' => 'ack', 'note' => 'all seen, fixing tomorrow', 'until' => '2026-10-30'])
            ->assertOk()->assertJson(['saved' => 2]);
        Cache::flush();
        $p = $this->panel();
        $this->assertSame('⚠ 0 pages with problems (0 urgent)', $p['line']);
        $this->assertStringContainsString('Acknowledged and done (2)', $p['html']);

        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->postJson('/chatbot/problems/ack-all', ['status' => 'done', 'note' => 'x'])->assertForbidden();
    }
}
