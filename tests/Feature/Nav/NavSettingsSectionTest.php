<?php

namespace Tests\Feature\Nav;

use App\Models\NavLink;
use App\Support\NavSections;
use Illuminate\Support\Facades\DB;

/**
 * NV-1: nav settings gets a Section per link (CEO only), and Reset (NavLink::defaultData) carries every link with its
 * section, including the links the migrations added after May 2026.
 */
class NavSettingsSectionTest extends NavTestCase
{
    /** The save form as the page posts it: every row's current visibility, order and section. */
    private function form(array $sectionOverrides = []): array
    {
        $visibility = [];
        foreach (DB::table('nav_link_role_visibility')->where('is_visible', true)->get() as $v) {
            $visibility[$v->nav_link_id][$v->role] = '1';
        }
        $sort = [];
        $section = [];
        foreach (DB::table('nav_links')->get() as $l) {
            $sort[$l->id] = $l->sort_order;
            $section[$l->id] = $sectionOverrides[$l->id] ?? $l->section;
        }

        return ['visibility' => $visibility, 'sort_order' => $sort, 'section' => $section];
    }

    public function test_NV1_the_page_shows_a_section_dropdown_per_link(): void
    {
        $html = $this->actingAs($this->user('CEO'))->get('/owner/nav-settings')->assertOk()->getContent();
        $id = $this->linkId('/finance');

        $this->assertMatchesRegularExpression('/<select[^>]*name="section\[' . $id . '\]"/', $html);
        $this->assertMatchesRegularExpression('/name="section\[' . $id . '\]".*?<option value="Money"\s+selected/s', $html);
        foreach (NavSections::names() as $name) {
            $this->assertStringContainsString('<option value="' . $name . '"', $html);
        }
    }

    public function test_NV1_the_CEO_moves_a_link_to_another_section_and_visibility_is_kept(): void
    {
        $id = $this->linkId('/astra');
        $before = $this->visibleUrls();

        $this->actingAs($this->user('CEO'))->post('/owner/nav-settings', $this->form([$id => 'Marketing']))
            ->assertRedirect('/owner/nav-settings');

        $this->assertSame('Marketing', DB::table('nav_links')->where('id', $id)->value('section'));
        $this->assertSame($before, $this->visibleUrls());
    }

    public function test_NV1_an_unknown_section_is_refused(): void
    {
        $id = $this->linkId('/astra');

        $this->actingAs($this->user('CEO'))->post('/owner/nav-settings', $this->form([$id => 'Hacked']))
            ->assertSessionHasErrors();
        $this->assertSame('AI', DB::table('nav_links')->where('id', $id)->value('section'));
    }

    public function test_NV1_only_the_CEO_can_change_a_section(): void
    {
        $id = $this->linkId('/astra');
        foreach (['Marketing - OIC', 'Marketing', 'Data Encoder - OIC', 'Data Encoder'] as $role) {
            $this->actingAs($this->user($role))->post('/owner/nav-settings', $this->form([$id => 'Money']))->assertForbidden();
            $this->actingAs($this->user($role))->get('/owner/nav-settings')->assertForbidden();
        }
        $this->assertSame('AI', DB::table('nav_links')->where('id', $id)->value('section'));
    }

    public function test_NV1_a_route_added_from_nav_settings_gets_a_section(): void
    {
        $this->actingAs($this->user('CEO'))->post('/owner/nav-settings/add-route', ['url' => '/jnt/orders-probe-x'])->assertRedirect();

        $this->assertSame('More', DB::table('nav_links')->where('route_url', '/jnt/orders-probe-x')->value('section'));
    }

    public function test_NV1_default_data_has_a_section_on_every_row_and_every_link_the_migrations_add(): void
    {
        $defaults = collect(NavLink::defaultData());

        foreach ($defaults as $d) {
            $this->assertContains($d['section'] ?? null, NavSections::names(), $d['key']);
        }
        foreach (['users_admin', 'supply_finance', 'playbook', 'chatblast', 'chatbot', 'storage', 'encoding_timetable', 'ads_daily_output', 'botcake_accounts', 'prompt_generator', 'jnt_config'] as $key) {
            $this->assertNotNull($defaults->firstWhere('key', $key), "defaultData misses $key");
        }
        // Every link a migration made is in the catalog, with the same url.
        foreach (DB::table('nav_links')->whereNotIn('key', array_column(self::HAND_ROWS, 0))->get() as $row) {
            $this->assertSame($row->route_url, $defaults->firstWhere('key', $row->key)['route_url'] ?? null, $row->key);
        }
    }

    public function test_NV1_reset_writes_every_default_with_its_section_and_its_visibility(): void
    {
        DB::table('nav_links')->update(['section' => 'More']);
        DB::table('nav_links')->where('key', 'playbook')->delete();

        $this->actingAs($this->user('CEO'))->post('/owner/nav-settings/reset')->assertRedirect('/owner/nav-settings');

        foreach (NavLink::defaultData() as $d) {
            $row = DB::table('nav_links')->where('key', $d['key'])->first();
            $this->assertNotNull($row, $d['key']);
            $this->assertSame($d['section'], $row->section, $d['key']);
            $visible = DB::table('nav_link_role_visibility')->where('nav_link_id', $row->id)->where('is_visible', true)->pluck('role')->all();
            $this->assertEqualsCanonicalizing($d['visible'], $visible, $d['key']);
        }
        $this->assertSame('Marketing', DB::table('nav_links')->where('key', 'playbook')->value('section'));
    }

    public function test_NV1_default_visibility_matches_what_the_migrations_seed(): void
    {
        // A fresh database's visibility (migrations only) is what Reset restores, link by link.
        $defaults = collect(NavLink::defaultData())->keyBy('key');
        foreach (DB::table('nav_links')->whereNotIn('key', array_column(self::HAND_ROWS, 0))->get() as $row) {
            $visible = DB::table('nav_link_role_visibility')->where('nav_link_id', $row->id)->where('is_visible', true)->pluck('role')->all();
            $this->assertEqualsCanonicalizing($defaults[$row->key]['visible'], $visible, $row->key);
        }
    }
}
