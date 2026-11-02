<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\ProblemsPanel;
use App\Services\ChatblastSetupCheck;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Spec 221: ang X ng setup check ay urgent (orange) lang sa page na pinapadalhan ng chatblast at may order sa huling
 * ORDER_DAYS araw; ang walang order ay puti ("Quiet"). Ngayon: 2026-10-27 12:00 (Manila).
 */
class QuietSetupTest extends ChatblastTestCase
{
    private const X = 'No flow named UPDATE SHIPPING STATUS 2026';

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
        $this->page('Page Uno', '1001');
    }

    private function check(string $page, string $x = self::X): void
    {
        app(PageSettings::class)->set($page, 'botcake', ChatblastSetupCheck::CHECK_KEY, json_encode(['at' => '2026-10-27 11:00:00', 'ok' => false, 'x' => [$x]]), false, null, true);
    }

    private function sale(string $page, int $daysAgo): void
    {
        DB::table('macro_output')->insert(['PAGE' => $page, 'fb_name' => 'Buyer', 'ts_date' => now('Asia/Manila')->subDays($daysAgo)->toDateString(),
            'created_at' => now(), 'updated_at' => now()]);
    }

    /** Ang buod na linya at ang mga linya ng setup kada page (kulay at text). */
    private function banner(): ?array
    {
        $html = $this->get('/chatbot/inbox')->assertOk()->getContent();
        if (!preg_match('#<div[^>]*data-alert="problems"[^>]*>(.*?)</ol>\s*</div>#s', $html, $m)) return null;
        $clean = fn ($s) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($s))));
        preg_match('#<button[^>]*>(.*?)</button>#s', $m[1], $b);
        $pages = [];
        preg_match_all('#<li data-page="([^"]*)" data-level="(\w+)"[^>]*>(.*?)</ul>\s*</li>#s', $m[1], $rows, PREG_SET_ORDER);
        foreach ($rows as [, $page, $level, $body]) {
            preg_match_all('#<li data-level="(\w+)"[^>]*>(.*?)</li>#s', $body, $lines, PREG_SET_ORDER);
            $pages[html_entity_decode($page)] = ['level' => $level, 'lines' => array_map(fn ($l) => ['level' => $l[1], 'text' => $clean($l[2])], $lines)];
        }

        return ['line' => $clean(preg_replace('#<span aria-hidden="true">.*?</span>#', '', $b[1] ?? '')), 'pages' => $pages];
    }

    public function test_S_221_1_1_a_sending_page_with_an_order_in_the_last_14_days_is_orange(): void
    {
        $this->check('Page Uno');
        $this->sale('Page Uno', 13); // ang pinakalumang araw ng window

        $this->actingAs($this->user());
        $b = $this->banner();
        $this->assertSame('⚠ 1 page with problems (1 urgent)', $b['line']);
        $this->assertSame([['level' => 'orange', 'text' => '🟠 Setup: ' . self::X . ' Page settings']], $b['pages']['Page Uno']['lines']);
    }

    public function test_S_221_1_2_a_sending_page_with_no_order_in_14_days_is_white_and_not_urgent(): void
    {
        $this->check('Page Uno');
        $this->sale('Page Uno', 14); // isang araw bago ang window

        $this->actingAs($this->user());
        $b = $this->banner();
        $this->assertSame('⚠ 1 page with problems (0 urgent)', $b['line']);
        $this->assertSame('white', $b['pages']['Page Uno']['level']);
        $this->assertSame([['level' => 'white', 'text' => '⚪ Quiet (no order in 14 days), setup incomplete: ' . self::X . ' Page settings']],
            $b['pages']['Page Uno']['lines']);
        $this->assertSame(14, ProblemsPanel::ORDER_DAYS, 'a named constant');
    }

    public function test_S_221_1_3_a_quiet_page_that_gets_an_order_is_orange_again_within_the_cache_time(): void
    {
        $this->check('Page Uno');
        $this->actingAs($this->user());
        $this->assertSame('white', $this->banner()['pages']['Page Uno']['level']);

        $this->sale('Page Uno', 0);
        $this->travel(ProblemsPanel::CACHE_SECONDS + 1)->seconds();
        $b = $this->banner();
        $this->assertSame('⚠ 1 page with problems (1 urgent)', $b['line']);
        $this->assertSame('orange', $b['pages']['Page Uno']['level']);
        $this->assertStringStartsWith('🟠 Setup: ', $b['pages']['Page Uno']['lines'][0]['text']);
    }

    public function test_S_221_1_4_page_names_that_differ_in_case_or_spacing_are_matched_like_page_key(): void
    {
        $this->page('Mega  Store', '1002'); // dobleng espasyo sa settings
        $this->check('Page Uno');
        $this->check('Mega  Store');
        $this->sale('  page   UNO ', 2);
        $this->sale('MEGA STORE', 1);

        $this->actingAs($this->user());
        $b = $this->banner();
        $this->assertSame('⚠ 2 pages with problems (2 urgent)', $b['line']);
        $this->assertSame(['orange', 'orange'], array_column($b['pages'], 'level'));
    }

    public function test_S_221_1_5_eighty_pages_take_one_grouped_order_query_per_banner_build_within_the_cache(): void
    {
        for ($i = 2; $i <= 81; $i++) {
            $this->page('Page ' . $i, (string) (2000 + $i));
            $this->check('Page ' . $i);
            if ($i % 2 === 0) $this->sale('Page ' . $i, $i % 14);
        }

        $orderQueries = [];
        DB::listen(function ($q) use (&$orderQueries) {
            if (preg_match('#\bfrom\s+["`]?macro_output\b#i', $q->sql)) $orderQueries[] = $q->sql; // ang table mismo, hindi ang Schema::hasTable
        });
        $this->actingAs($this->user());
        $b = $this->banner();
        $this->assertCount(1, $orderQueries, 'one grouped query, not one per page: ' . implode(' | ', $orderQueries));
        $this->assertSame('⚠ 80 pages with problems (40 urgent)', $b['line']);
        $this->assertSame('orange', $b['pages']['Page 2']['level']);
        $this->assertSame('white', $b['pages']['Page 3']['level']);

        $this->banner();
        $this->assertCount(1, $orderQueries, 'the next build within 60 s reads the cache');
    }
}
