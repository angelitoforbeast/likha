<?php

namespace Tests\Feature\Nav;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * NV-2: the labelled sidebar. Sections per role, an empty state for a role with no links, View as, the active link,
 * the slim top bar, and every branch of the layout still rendering.
 */
class SidebarTest extends NavTestCase
{
    private const EIGHT = ['Home', 'Orders', 'Shipping', 'Marketing', 'Chat', 'AI', 'Money', 'Settings'];

    public function test_NV2_H_the_CEO_sees_all_eight_sections_in_order_and_More_last(): void
    {
        $html = $this->actingAs($this->user('CEO'))->get('/nav-probe')->assertOk()->getContent();

        $this->assertSame([...self::EIGHT, 'More'], $this->sidebarSections($html));
        $this->assertContains('/profit-calculator', $this->sidebarLinks($html, 'More'));
        $this->assertSame(['/finance', '/finance/supply'], $this->sidebarLinks($html, 'Money'));
    }

    public function test_NV2_H_a_data_encoder_sees_only_the_sections_that_hold_their_links(): void
    {
        $html = $this->actingAs($this->user('Data Encoder'))->get('/nav-probe')->assertOk()->getContent();

        $this->assertSame(['Home', 'Orders', 'Shipping', 'Chat', 'Settings'], $this->sidebarSections($html));
        $this->assertEqualsCanonicalizing(['/encoder/checker_1', '/data_encoder/mes-segregator', '/jnt/address'], $this->sidebarLinks($html, 'Orders'));
        $this->assertSame(['/jnt_upload'], $this->sidebarLinks($html, 'Shipping'));
        $this->assertStringNotContainsString('data-nav-link="/finance"', $html);
    }

    public function test_NV2_every_role_sees_exactly_the_links_it_saw_before_now_grouped(): void
    {
        foreach (self::ROLES as $role) {
            $expected = \App\Support\ChatblastNav::mainLinks(\App\Models\NavLink::visibleFor($role))
                ->map(fn ($l) => $l->route_url)->sort()->values()->all();
            $html = $this->actingAs($this->user($role))->get('/nav-probe')->assertOk()->getContent();
            $shown = collect($this->sidebarLinks($html))->unique()->sort()->values()->all();

            $this->assertSame($expected, $shown, $role);
        }
    }

    public function test_NV2_a_role_with_no_links_sees_an_empty_state_not_a_broken_page(): void
    {
        $html = $this->actingAs($this->user('Warehouse'))->get('/nav-probe')->assertOk()->getContent();

        $this->assertSame([], $this->sidebarSections($html));
        $this->assertStringContainsString('data-nav-empty', $html);
        $this->assertStringContainsString('probe body', $html);

        $noRole = $this->actingAs($this->user(null))->get('/nav-probe')->assertOk()->getContent();
        $this->assertStringContainsString('data-nav-empty', $noRole);
    }

    public function test_NV2_a_section_the_CEO_moved_a_link_to_is_used(): void
    {
        DB::table('nav_links')->where('route_url', '/astra')->update(['section' => 'Marketing']);
        $html = $this->actingAs($this->user('CEO'))->get('/nav-probe')->getContent();

        $this->assertContains('/astra', $this->sidebarLinks($html, 'Marketing'));
        $this->assertNotContains('/astra', $this->sidebarLinks($html, 'AI'));
    }

    public function test_NV2_E_view_as_changes_the_nav_only(): void
    {
        $ceo = $this->user('CEO', 'Owner Person');
        $html = $this->actingAs($ceo)->withSession(['nav_view_as_role' => 'Data Encoder'])->get('/nav-probe')->assertOk()->getContent();

        $this->assertSame(['Home', 'Orders', 'Shipping', 'Chat', 'Settings'], $this->sidebarSections($html));
        // The avatar menu is still the CEO's, with View as and the preview mark.
        $this->assertStringContainsString('Owner Person', $html);
        $this->assertMatchesRegularExpression('/<select[^>]*name="role"/', $html);
        $this->assertMatchesRegularExpression('/<option value="Data Encoder"\s+selected/', $html);
        $this->assertStringContainsString('data-nav-preview', $html);
    }

    public function test_NV2_E_view_as_is_ignored_for_anyone_but_the_CEO(): void
    {
        $html = $this->actingAs($this->user('Data Encoder'))->withSession(['nav_view_as_role' => 'CEO'])->get('/nav-probe')->getContent();

        $this->assertNotContains('Money', $this->sidebarSections($html));
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*name="role"/', $html);
    }

    public function test_NV2_the_active_page_is_marked(): void
    {
        $html = $this->actingAs($this->user('CEO'))->get('/ads_manager/report-probe')->getContent();

        $this->assertMatchesRegularExpression('/data-nav-link="\/ads_manager\/report"[^>]*aria-current="page"/', $html);
        $this->assertSame(1, preg_match_all('/aria-current="page"/', $html));
    }

    public function test_NV2_the_top_bar_holds_the_title_search_and_avatar_menu_and_the_old_bar_is_gone(): void
    {
        $html = $this->actingAs($this->user('Marketing', 'Juan Cruz'))->get('/nav-probe')->getContent();

        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*Probe\s*<\/h1>/', $html);
        $this->assertStringContainsString('data-nav-search', $html);
        $this->assertStringContainsString('Juan Cruz', $html);
        $this->assertMatchesRegularExpression('/action="[^"]*\/logout"/', $html);
        $this->assertStringNotContainsString('bg-gray-800', $html);
        $this->assertStringNotContainsString('static.vecteezy.com', $html);
    }

    /** One path per branch of the layout: no header + full width, full width, padded wide, default. */
    public static function layoutBranches(): array
    {
        return [
            'checklist (no header, full width)' => ['/checklist'],
            'jnt_upload_v2 (full width)' => ['/jnt_upload_v2'],
            'cpp (padded wide)' => ['/cpp'],
            'boardroom (no header, full width)' => ['/boardroom'],
            'default' => ['/nav-probe'],
        ];
    }

    #[DataProvider('layoutBranches')]
    public function test_NV2_E_every_branch_of_the_layout_renders_with_the_sidebar(string $path): void
    {
        if ($path !== '/nav-probe') {
            Route::middleware(['web', 'auth'])->get($path, fn () => Blade::render('<x-layout><x-slot name="heading">Branch</x-slot>branch body</x-layout>'));
        }
        foreach (['CEO', 'Data Encoder'] as $role) {
            $html = $this->actingAs($this->user($role))->get($path)->assertOk()->getContent();
            $this->assertStringContainsString('data-nav-sidebar', $html);
            $this->assertNotEmpty($this->sidebarSections($html));
        }
    }

    public function test_NV2_E_the_nav_settings_page_renders_in_the_new_layout(): void
    {
        $html = $this->actingAs($this->user('CEO'))->get('/owner/nav-settings')->assertOk()->getContent();

        $this->assertStringContainsString('data-nav-sidebar', $html);
        $this->assertContains('/owner/nav-settings', $this->sidebarLinks($html, 'Settings'));
    }
}
