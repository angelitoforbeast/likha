<?php

namespace Tests\Feature\Chatblast;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang Chatblast bilang sariling app: iisang layout at nav para sa To send, History, Messages, Sequences at Page settings, ang
 * /page-settings na lumipat sa /chatblast/page-settings, at ang iisang Chatblast link sa main nav ng Likha.
 */
class ChatblastAppTest extends ChatblastTestCase
{
    public static function oldLinks(): array
    {
        return [
            'page'         => ['/page-settings?a=1&b=two', '/chatblast/page-settings?a=1&b=two'],
            'walang query' => ['/page-settings', '/chatblast/page-settings'],
            'sub-page'     => ['/page-settings/customers?page=Page%20Uno', '/chatblast/page-settings/customers?page=Page%20Uno'],
            // Ang path ay galing sa user: dinadala nang naka-encode pa rin, sa app host at sa ilalim ng /chatblast/page-settings.
            'ibang host'   => ['/page-settings/%2F%2Fevil.example/x', '/chatblast/page-settings/%2F%2Fevil.example/x'],
            'naka-encode'  => ['/page-settings/customers%3Fpage=x', '/chatblast/page-settings/customers%3Fpage=x'],
            'bagong linya' => ['/page-settings/a%0D%0ALocation:%20x', '/chatblast/page-settings/a%0D%0ALocation:%20x'],
        ];
    }

    /** @dataProvider oldLinks */
    public function test_S_056_2_an_old_page_settings_link_moves_permanently_with_its_query(string $old, string $new): void
    {
        $this->actingAs($this->user());

        $this->get($old)->assertStatus(301)->assertRedirect($new);
    }

    /** Isang nav link gaya ng sa /owner/nav-settings, naka-check para sa mga role na ibinigay. */
    private function navLink(string $key, string $label, string $url, array $roles): void
    {
        if (!Schema::hasTable('nav_links')) {
            Artisan::call('migrate', ['--path' => 'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php', '--force' => true]);
        }
        $id = DB::table('nav_links')->insertGetId([
            'key' => $key, 'label' => $label, 'route_url' => $url, 'icon' => 'fa-solid fa-link', 'active_pattern' => ltrim($url, '/') . '*',
            'is_active' => true, 'sort_order' => DB::table('nav_links')->count() + 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($roles as $role) {
            DB::table('nav_link_role_visibility')->insert(['nav_link_id' => $id, 'role' => $role, 'is_visible' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_S_056_4_page_settings_access_follows_nav_settings_under_the_old_and_the_new_path(): void
    {
        $this->navLink('page_settings_new', 'Page Settings', '/chatblast/page-settings', ['Marketing']);

        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->get('/chatblast/page-settings')->assertOk();
        $this->get('/chatblast/page-settings/psid-coverage')->assertOk();
        $this->getJson('/chatblast/page-settings/grid')->assertOk();
        $this->get('/chatblast')->assertNotFound(); // hindi naka-check ang /chatblast

        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        $this->get('/chatblast/page-settings')->assertNotFound();
        $this->getJson('/chatblast/page-settings/grid')->assertNotFound();
    }

    /** Ang mga href (walang ulit: desktop at mobile menu) ng main nav na papunta sa chatblast o page settings. */
    private function mainNavChatblastLinks(string $role): array
    {
        $this->actingAs($this->user($role, uniqid('u') . '@example.test'));
        $html = (string) $this->blade('<x-layout>body</x-layout>');
        preg_match_all('#<a\s[^>]*href="([^"]*(?:chatblast|page-settings)[^"]*)"#', $html, $m);

        return array_values(array_unique(array_map(fn ($u) => parse_url($u, PHP_URL_PATH), $m[1])));
    }

    public function test_S_056_6_the_main_nav_shows_one_chatblast_link(): void
    {
        $this->navLink('chatblast', 'Chatblast', '/chatblast', ['Marketing - OIC']);
        $this->navLink('page_settings', 'Page Settings', '/page-settings', ['Marketing - OIC', 'Marketing']);

        $this->assertSame(['/chatblast'], $this->mainNavChatblastLinks('Marketing - OIC'), 'parehong naka-check: iisang link');
        $this->assertSame(['/chatblast/page-settings'], $this->mainNavChatblastLinks('Marketing'), 'page settings lang: doon ang link');
        $this->assertStringContainsString('Chatblast', (string) $this->blade('<x-layout>body</x-layout>'));
        $this->assertStringNotContainsString('Page Settings', (string) $this->blade('<x-layout>body</x-layout>'));
        $this->assertSame([], $this->mainNavChatblastLinks('Data Encoder'), 'wala sa dalawa: walang link');
    }

    public static function pages(): array
    {
        return [
            'to send'       => ['/chatblast', 'To send'],
            'history'       => ['/chatblast/history', 'History'],
            'messages'      => ['/chatblast/campaigns', 'Messages'],
            'sequences'     => ['/chatblast/sequence', 'Sequences'],
            'page settings' => ['/chatblast/page-settings', 'Page settings'],
            'customers'     => ['/chatblast/page-settings/customers?page=Page%20Uno', 'Page settings'],
            'psid coverage' => ['/chatblast/page-settings/psid-coverage', 'Page settings'],
            'orders'        => ['/chatblast/page-settings/psid-coverage/orders?page_name=Page%20Uno', 'Page settings'],
        ];
    }

    /** @dataProvider pages */
    public function test_S_056_1_each_page_has_the_chatblast_layout_and_nav_and_no_main_likha_nav(string $url, string $active): void
    {
        $this->navLink('main_only', 'Main Likha Link', '/ads_manager/report', ['CEO']);
        $this->actingAs($this->user());

        $html = $this->get($url)->assertOk()->getContent();

        $this->assertStringContainsString('data-chatblast-app', $html);
        foreach (['To send', 'History', 'Messages', 'Sequences', 'Page settings'] as $item) {
            $this->assertStringContainsString('data-cb-nav="' . $item . '"', $html);
        }
        preg_match_all('#data-cb-nav="([^"]+)"[^>]*aria-current="page"#', $html, $m);
        $this->assertSame([$active], $m[1], 'iisa ang naka-highlight');
        $this->assertStringContainsString('← Likha', $html);
        $this->assertStringNotContainsString('Log out', $html, 'walang Log out sa Chatblast');
        $this->assertStringContainsString('CEO User', $html);
        $this->assertStringNotContainsString('Main Likha Link', $html);
    }

    public static function scrollPages(): array
    {
        return [
            'to send'       => ['/chatblast', true],
            'history'       => ['/chatblast/history', true],
            'messages'      => ['/chatblast/campaigns', false],
            'sequences'     => ['/chatblast/sequence', false],
            'page settings' => ['/chatblast/page-settings', true],
        ];
    }

    /** @dataProvider scrollPages */
    public function test_S_060_1_the_data_area_is_the_only_scroll_container_under_a_fixed_header(string $url, bool $table): void
    {
        $this->actingAs($this->user());

        $html = $this->get($url)->assertOk()->getContent();

        // Buong taas ang page at hindi ito ang nag-i-scroll; ang data lang (iisang cb-scroll) ang may sariling scroll.
        $this->assertMatchesRegularExpression('#<body class="[^"]*\bh-screen\b[^"]*\boverflow-hidden\b#', $html);
        $this->assertMatchesRegularExpression('#class="[^"]*\bcb-page\b#', $html);
        $this->assertSame(1, preg_match_all('#class="[^"]*\bcb-scroll\b#', $html), 'iisang scroll container');
        $this->assertStringContainsString('.cb-scroll thead th { @apply sticky top-0', $html);
        if ($table) {
            $this->assertMatchesRegularExpression('#class="[^"]*\bcb-scroll\b[^"]*"[^>]*>\s*<table#', $html, 'ang table ang laman ng scroll container');
        }
    }

    /** @dataProvider listPages */
    public function test_S_060_4_the_chip_row_holds_the_status_and_a_test_psid_button_that_opens_a_modal(string $url): void
    {
        $this->actingAs($this->user());

        $html = $this->get($url)->assertOk()->getContent();

        // Iisang hanay: ang mga chip, ang "Sending: …" sa kanan, at ang Test PSID na button sa dulo.
        $this->assertMatchesRegularExpression('#data-cb-chip-row.*id="chips".*id="state".*id="test-psid-open"#s', $html);
        $this->assertSame(1, substr_count($html, 'data-cb-chip-row'));
        $chipRow = substr($html, strpos($html, 'data-cb-chip-row'), strpos($html, 'id="test-psid-open"') - strpos($html, 'data-cb-chip-row'));
        $this->assertStringNotContainsString('<table', $chipRow);
        // Ang picker ay nasa modal; wala nang disclosure na nagpapahaba sa itaas.
        $this->assertMatchesRegularExpression('#id="test-psid-modal"[^>]*role="dialog"#', $html);
        $this->assertStringContainsString('id="test-psid-form"', $html);
        $this->assertStringNotContainsString('<details', $html);
    }

    public static function listPages(): array
    {
        return ['to send' => ['/chatblast'], 'history' => ['/chatblast/history']];
    }

    public function test_S_056_5_the_chatblast_nav_shows_only_what_the_role_may_open(): void
    {
        $this->navLink('page_settings', 'Page Settings', '/page-settings', ['Marketing']);
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));

        $html = $this->get('/chatblast/page-settings')->assertOk()->getContent();

        $this->assertStringContainsString('data-cb-nav="Page settings"', $html);
        foreach (['To send', 'History', 'Messages', 'Sequences'] as $item) {
            $this->assertStringNotContainsString('data-cb-nav="' . $item . '"', $html);
        }
        $this->get('/chatblast')->assertNotFound();
    }
}
