<?php

namespace Tests\Feature\Nav;

/**
 * NV-3: the command palette lists every link the person can see (the same list as the sidebar), with its section,
 * and nothing else.
 */
class NavPaletteTest extends NavTestCase
{
    public function test_NV3_the_palette_lists_exactly_the_links_each_role_can_see(): void
    {
        foreach (self::ROLES as $role) {
            $html = $this->actingAs($this->user($role))->get('/nav-probe')->assertOk()->getContent();
            $palette = collect($this->paletteData($html));

            $this->assertEqualsCanonicalizing(
                collect($this->sidebarLinks($html))->unique()->values()->all(),
                $palette->pluck('url')->all(),
                $role
            );
        }
    }

    public function test_NV3_each_entry_carries_label_section_url_and_icon(): void
    {
        $html = $this->actingAs($this->user('CEO'))->get('/nav-probe')->getContent();
        $finance = collect($this->paletteData($html))->firstWhere('url', '/finance');

        $this->assertSame('Finance', $finance['label']);
        $this->assertSame('Money', $finance['section']);
        $this->assertSame('fa-solid fa-wallet', $finance['icon']);
        $this->assertSame($this->linkId('/finance'), $finance['id']);
    }

    public function test_NV3_a_data_encoder_cannot_find_a_ceo_page_in_the_palette(): void
    {
        $html = $this->actingAs($this->user('Data Encoder'))->get('/nav-probe')->getContent();
        $urls = collect($this->paletteData($html))->pluck('url');

        $this->assertNotContains('/finance', $urls);
        $this->assertNotContains('/owner/nav-settings', $urls);
        $this->assertContains('/jnt_upload', $urls);
    }

    public function test_NV3_view_as_gives_the_palette_of_the_previewed_role(): void
    {
        $html = $this->actingAs($this->user('CEO'))->withSession(['nav_view_as_role' => 'Data Encoder'])->get('/nav-probe')->getContent();

        $this->assertNotContains('/finance', collect($this->paletteData($html))->pluck('url'));
    }

    public function test_NV3_the_palette_dialog_and_search_button_are_on_the_page(): void
    {
        $html = $this->actingAs($this->user('Marketing'))->get('/nav-probe')->getContent();

        $this->assertMatchesRegularExpression('/role="dialog"[^>]*aria-label="Go to a page"/', $html);
        $this->assertStringContainsString('data-nav-search', $html);
    }
}
