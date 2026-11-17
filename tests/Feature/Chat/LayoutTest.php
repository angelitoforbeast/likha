<?php

namespace Tests\Feature\Chat;

/**
 * /chat in the sidebar layout (spec 295 follow-up; 296 result, ruling 10): the page is one of the layout's full-width
 * pages, so the chat panel is not inside the padded container, and the panel is placed under the top bar and beside
 * the sidebar, not under the sidebar's <nav>.
 */
class LayoutTest extends ChatTestCase
{
    public function test_chat_renders_full_width_in_the_layout(): void
    {
        $html = $this->actingAs($this->person('Marketing', 'Ana'))->get('/chat')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div class="w-full px-0">\s*(<style>|<div id="lc")/', $html);
        $this->assertStringNotContainsString('mx-auto max-w-7xl px-4 py-6', $html);
    }

    public function test_the_panel_sits_under_the_top_bar_and_beside_the_sidebar(): void
    {
        $html = $this->actingAs($this->person('Marketing', 'Ana'))->get('/chat')->assertOk()->getContent();

        $this->assertStringContainsString('class="lk-topbar"', $html);
        $this->assertStringContainsString("document.querySelector('.lk-topbar')", $html);
        $this->assertMatchesRegularExpression('/@media \(min-width: 1024px\) \{\s*#lc \{ left: var\(--lk-w, 0px\); \}\s*html\[data-nav-rail\] #lc \{ left: var\(--lk-rail, 0px\); \}/', $html);
    }
}
