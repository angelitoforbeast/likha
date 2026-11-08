<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\ProblemsPanel;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;

/**
 * Spec 245: the expanded problems banner splits its list into Red, Orange, White and All tabs. The panel is faked so
 * the lines and their order are exactly what panel() would hand the layout. Now: 2026-10-27 12:00.
 */
class ProblemTabsTest extends ChatblastTestCase
{
    private const URLS = ['/chatbot/inbox', '/chatblast/page-settings'];

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
    }

    private static function line(string $level, string $text, string $kind, array $extra = []): array
    {
        return ['level' => $level, 'text' => $text, 'links' => [['Inbox', '/chatbot/inbox?k=' . $kind]], 'kind' => $kind] + $extra;
    }

    /** Red, orange and white lines on four pages, in panel() order, one line that came back after an ack, and one acknowledged line. */
    private static function mixed(): array
    {
        return [
            'pages' => [
                ['page' => 'Page Uno', 'level' => 'red', 'lines' => [self::line('red', 'Uno red', 'a00000000001'), self::line('orange', 'Uno orange', 'a00000000002')]],
                ['page' => 'Page Dos', 'level' => 'red', 'lines' => [self::line('red', 'Dos red', 'a00000000003')]],
                ['page' => 'Page Tres', 'level' => 'orange', 'lines' => [self::line('orange', 'Tres orange', 'a00000000004', ['ack' => ['status' => 'ack', 'by' => 'CEO User',
                    'at' => 'Oct 27 10:00', 'note' => 'on it', 'back' => 'got worse since it was acknowledged', 'until' => null, 'hidden' => false]]), self::line('white', 'Tres white', 'a00000000005')]],
                ['page' => 'Page Kwatro', 'level' => 'white', 'lines' => [self::line('white', 'Kwatro white', 'a00000000006')]],
            ],
            'urgent' => 3,
            'doubles' => false,
            'acked' => [['page' => 'Page Singko', 'lines' => [self::line('orange', 'Singko done', 'a00000000007', ['ack' => ['status' => 'done', 'by' => 'CEO User',
                'at' => 'Oct 27 09:00', 'note' => 'fixed it', 'back' => null, 'until' => null, 'hidden' => true]])]]],
        ];
    }

    private function fake(?array $panel): void
    {
        $this->app->instance(ProblemsPanel::class, new class(app(PageSettings::class), $panel) extends ProblemsPanel {
            public function __construct(PageSettings $settings, private ?array $data)
            {
                parent::__construct($settings);
            }

            public function panel(): ?array
            {
                return $this->data;
            }
        });
    }

    /** The banner from the page: the collapsed line, the tabs, and the pages with their lines as rendered (hidden or not). */
    private function banner(string $url): ?array
    {
        $html = $this->get($url)->assertOk()->getContent();
        if (!preg_match('#<div[^>]*data-alert="problems"[^>]*>(.*?)</ol>#s', $html, $m)) return null;
        $clean = fn ($s) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($s))));
        preg_match('#<button[^>]*>(.*?)</button>#s', $m[1], $b);
        preg_match('#<div id="cb-problems-tabs"([^>]*)>(.*?)</div>#s', $m[1], $bar);
        preg_match_all('#<button([^>]*role="tab"[^>]*)>(.*?)</button>#s', $bar[2] ?? '', $ts, PREG_SET_ORDER);
        $tabs = [];
        foreach ($ts as [, $attrs, $label]) {
            preg_match('#data-tab="(\w+)"#', $attrs, $t);
            preg_match('#aria-selected="(\w+)"#', $attrs, $sel);
            $tabs[$t[1]] = ['label' => $clean($label), 'selected' => $sel[1] ?? null, 'disabled' => (bool) preg_match('#\sdisabled\b#', $attrs), 'attrs' => $attrs];
        }
        $body = (string) preg_replace('#<span[^>]*\bdata-ack-(controls|back)[^>]*>.*?</span>#s', '', $m[1]);
        preg_match_all('#<li data-page="([^"]*)" data-level="(\w+)"([^>]*)>(.*?)</ul>\s*</li>#s', $body, $rows, PREG_SET_ORDER);
        $pages = [];
        foreach ($rows as [, $page, , $attrs, $inner]) {
            preg_match_all('#<li data-level="(\w+)" data-kind="([^"]*)"([^>]*)>(.*?)</li>#s', $inner, $ls, PREG_SET_ORDER);
            $pages[] = ['page' => html_entity_decode($page), 'hidden' => (bool) preg_match('#\shidden\b#', $attrs),
                'lines' => array_map(fn ($l) => ['level' => $l[1], 'text' => $clean($l[4]), 'hidden' => (bool) preg_match('#\shidden\b#', $l[3])], $ls)];
        }

        return ['line' => $clean(preg_replace('#<span aria-hidden="true">.*?</span>#', '', $b[1] ?? '')), 'button' => $b[0] ?? '', 'bar' => $bar[1] ?? null,
            'tabs' => $tabs, 'pages' => $pages, 'html' => $m[0], 'page' => $html];
    }

    /** What the server shows: the pages and lines not hidden. */
    private static function shown(array $banner): array
    {
        $out = [];
        foreach ($banner['pages'] as $p) {
            if ($p['hidden']) continue;
            $out[] = [$p['page'], array_column(array_filter($p['lines'], fn ($l) => !$l['hidden']), 'text')];
        }

        return $out;
    }

    /** What a tab shows by the script's rule: a line when the tab is All or the line's level, a page when one of its lines shows. */
    private static function tab(array $banner, string $tab): array
    {
        $out = [];
        foreach ($banner['pages'] as $p) {
            $lines = array_column(array_filter($p['lines'], fn ($l) => $tab === 'all' || $l['level'] === $tab), 'text');
            if ($lines) $out[] = [$p['page'], array_values($lines)];
        }

        return $out;
    }

    public function test_S_245_1_the_tabs_count_lines_per_level_and_red_opens_first_on_both_screens(): void
    {
        $this->fake(self::mixed());
        $this->actingAs($this->user());
        foreach (self::URLS as $url) {
            $b = $this->banner($url);
            $this->assertSame(['red', 'orange', 'white', 'all'], array_keys($b['tabs']), $url);
            $this->assertSame(['🔴 Red (2)', '🟠 Orange (2)', '⚪ White (2)', 'All (6)'], array_column($b['tabs'], 'label'), $url);
            $this->assertSame(['true', 'false', 'false', 'false'], array_column($b['tabs'], 'selected'), $url);
            $this->assertSame([false, false, false, false], array_column($b['tabs'], 'disabled'), $url);
            $this->assertMatchesRegularExpression('#role="tablist"#', (string) $b['bar']);
            $this->assertMatchesRegularExpression('#\shidden\b#', (string) $b['bar'], 'collapsed: the tabs are hidden with the list');
            foreach ($b['tabs'] as $t) $this->assertStringContainsString('aria-controls="cb-problems-list"', $t['attrs']);
            $this->assertMatchesRegularExpression('#<ol id="cb-problems-list"[^>]*role="tabpanel"#', $b['html']);
        }
    }

    public function test_S_245_1_each_level_tab_shows_only_its_lines_grouped_by_page_in_order(): void
    {
        $this->fake(self::mixed());
        $this->actingAs($this->user());
        $b = $this->banner('/chatbot/inbox');

        // The open Red tab is already applied by the server.
        $red = [['Page Uno', ['🔴 Uno red Inbox']], ['Page Dos', ['🔴 Dos red Inbox']]];
        $this->assertSame($red, self::shown($b));
        $this->assertSame($red, self::tab($b, 'red'));
        // The script's rule over the markup gives the other tabs.
        $this->assertSame([['Page Uno', ['🟠 Uno orange Inbox']], ['Page Tres', ['🟠 Tres orange Inbox']]], self::tab($b, 'orange'));
        $this->assertSame([['Page Tres', ['⚪ Tres white Inbox']], ['Page Kwatro', ['⚪ Kwatro white Inbox']]], self::tab($b, 'white'));
        $html = $b['page'];
        $this->assertStringContainsString("const on = tab === 'all' || line.dataset.level === tab;", $html);
        $this->assertStringContainsString('page.hidden = !any;', $html);
    }

    public function test_S_245_1_the_all_tab_is_todays_list(): void
    {
        $this->fake(self::mixed());
        $this->actingAs($this->user());
        $b = $this->banner('/chatbot/inbox');
        $this->assertSame([
            ['Page Uno', ['🔴 Uno red Inbox', '🟠 Uno orange Inbox']],
            ['Page Dos', ['🔴 Dos red Inbox']],
            ['Page Tres', ['🟠 Tres orange Inbox', '⚪ Tres white Inbox']],
            ['Page Kwatro', ['⚪ Kwatro white Inbox']],
        ], self::tab($b, 'all'));
        $this->assertSame(['Page Uno', 'Page Dos', 'Page Tres', 'Page Kwatro'], array_column($b['pages'], 'page'), 'one list, every page in panel() order');
    }

    public function test_S_245_1_lines_keep_their_links_buttons_ack_marks_and_the_acknowledged_section_in_every_tab(): void
    {
        $this->fake(self::mixed());
        $this->actingAs($this->user());
        $b = $this->banner('/chatbot/inbox');
        $html = $b['html'];
        foreach (range(1, 6) as $i) {
            $kind = sprintf('a%011d', $i);
            $this->assertStringContainsString('href="/chatbot/inbox?k=' . $kind . '"', $html);
            $this->assertMatchesRegularExpression('#<button type="button" class="cb-ack [^"]*" data-status="ack" data-page="[^"]+" data-kind="' . $kind . '" data-level="(red|orange|white)">Acknowledge</button>#', $html);
            $this->assertMatchesRegularExpression('#data-status="done" data-page="[^"]+" data-kind="' . $kind . '" data-level="(red|orange|white)">Done</button>#', $html);
        }
        $this->assertStringContainsString('data-ack-back>(Acknowledged by CEO User Oct 27 10:00: "on it"; got worse since it was acknowledged)', $html);
        // The acknowledged section is outside the pages, so no tab hides it.
        $this->assertMatchesRegularExpression('#<li class="pt-2" data-acked>#', $html);
        $this->assertStringContainsString('Acknowledged and done (1)', $html);
        $this->assertStringContainsString('data-status="clear" data-page="Page Singko" data-kind="a00000000007">Undo</button>', $html);
        $this->assertStringContainsString("list.querySelectorAll('li[data-page]')", $b['page'], 'the script filters only the page rows');
    }

    public function test_S_245_1_a_tab_with_no_lines_is_disabled_and_the_first_level_with_lines_opens(): void
    {
        $orange = self::mixed();
        $orange['pages'] = [
            ['page' => 'Page Tres', 'level' => 'orange', 'lines' => [self::line('orange', 'Tres orange', 'a00000000004'), self::line('white', 'Tres white', 'a00000000005')]],
            ['page' => 'Page Kwatro', 'level' => 'white', 'lines' => [self::line('white', 'Kwatro white', 'a00000000006')]],
        ];
        $orange['urgent'] = 1;
        $this->fake($orange);
        $this->actingAs($this->user());
        $b = $this->banner('/chatbot/inbox');
        $this->assertSame(['🔴 Red (0)', '🟠 Orange (1)', '⚪ White (2)', 'All (3)'], array_column($b['tabs'], 'label'));
        $this->assertSame([true, false, false, false], array_column($b['tabs'], 'disabled'));
        $this->assertSame(['false', 'true', 'false', 'false'], array_column($b['tabs'], 'selected'));
        $this->assertSame([['Page Tres', ['🟠 Tres orange Inbox']]], self::shown($b));
    }

    public function test_S_245_edge_only_white_lines_open_the_white_tab(): void
    {
        $white = self::mixed();
        $white['pages'] = [['page' => 'Page Kwatro', 'level' => 'white', 'lines' => [self::line('white', 'Kwatro white', 'a00000000006')]]];
        $white['urgent'] = 0;
        $white['acked'] = [];
        $this->fake($white);
        $this->actingAs($this->user());
        $b = $this->banner('/chatbot/inbox');
        $this->assertSame(['🔴 Red (0)', '🟠 Orange (0)', '⚪ White (1)', 'All (1)'], array_column($b['tabs'], 'label'));
        $this->assertSame([true, true, false, false], array_column($b['tabs'], 'disabled'));
        $this->assertSame(['false', 'false', 'true', 'false'], array_column($b['tabs'], 'selected'));
        $this->assertSame([['Page Kwatro', ['⚪ Kwatro white Inbox']]], self::shown($b));
    }

    public function test_S_245_edge_only_acknowledged_lines_open_all_and_no_problems_means_no_banner(): void
    {
        $acked = self::mixed();
        $acked['pages'] = [];
        $acked['urgent'] = 0;
        $this->fake($acked);
        $this->actingAs($this->user());
        $b = $this->banner('/chatbot/inbox');
        $this->assertSame('⚠ 0 pages with problems (0 urgent)', $b['line']);
        $this->assertSame(['🔴 Red (0)', '🟠 Orange (0)', '⚪ White (0)', 'All (0)'], array_column($b['tabs'], 'label'));
        $this->assertSame(['false', 'false', 'false', 'true'], array_column($b['tabs'], 'selected'));
        $this->assertSame([true, true, true, false], array_column($b['tabs'], 'disabled'), 'the open tab is never disabled');
        $this->assertStringContainsString('Acknowledged and done (1)', $b['html']);

        $this->fake(null);
        foreach (self::URLS as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('data-alert="problems"', $html, $url);
            $this->assertStringNotContainsString('cb-problems-tabs', $html, $url);
        }
    }

    public function test_S_245_2_the_collapsed_line_and_its_colours_stay_as_they_are(): void
    {
        $this->fake(self::mixed());
        $this->actingAs($this->user());
        foreach (self::URLS as $url) {
            $b = $this->banner($url);
            $this->assertSame('⚠ 4 pages with problems (3 urgent)', $b['line'], $url);
            $this->assertSame('<button type="button" id="cb-problems-toggle" aria-expanded="false" aria-controls="cb-problems-list"' . "\n"
                . '                class="block w-full truncate px-4 py-1.5 text-left font-semibold sm:px-6">⚠ 4 pages with problems (3 urgent) <span aria-hidden="true">▾</span></button>', $b['button']);
            $this->assertMatchesRegularExpression('#<div class="shrink-0 border-b border-red-300 bg-red-600 text-white text-sm" role="alert" data-alert="problems">#', $b['html']);
        }

        $white = self::mixed();
        $white['pages'] = [['page' => 'Page Kwatro', 'level' => 'white', 'lines' => [self::line('white', 'Kwatro white', 'a00000000006')]]];
        $white['urgent'] = 0;
        $this->fake($white);
        $b = $this->banner('/chatbot/inbox');
        $this->assertSame('⚠ 1 page with problems (0 urgent)', $b['line']);
        $this->assertMatchesRegularExpression('#<div class="shrink-0 border-b border-slate-300 bg-slate-100 text-slate-800 text-sm" role="alert" data-alert="problems">#', $b['html']);
    }

    /** No browser test in this repo: the remembered tab is proven by the script hook. */
    public function test_S_245_3_the_open_tab_is_remembered_in_local_storage_inside_try_catch(): void
    {
        $this->fake(self::mixed());
        $this->actingAs($this->user());
        $html = $this->get('/chatbot/inbox')->assertOk()->getContent();
        $this->assertMatchesRegularExpression("#try \\{[^}]*localStorage\\.getItem\\('cb-problems-tab'\\)[^}]*\\} catch \\(e\\) \\{\\}#", $html);
        $this->assertMatchesRegularExpression("#try \\{ localStorage\\.setItem\\('cb-problems-tab', t\\.dataset\\.tab\\); \\} catch \\(e\\) \\{\\}#", $html);
        // A stored tab that is unknown or has no lines now is ignored: the server's default stays open.
        $this->assertStringContainsString('tabs.find(x => x.dataset.tab === saved && !x.disabled)', $html);
    }
}
