<?php

namespace Tests\Feature\Chat;

/** OL-1 (spec 304): the lock line is for the owner only; staff never receive it in their HTML. */
class OwnerOnlyLockTest extends ChatTestCase
{
    private const PANE = 'The owner can read every conversation in Likha Chat.';

    private const HEADER = '<div class="lc-lock">';

    public function test_staff_get_no_lock_line_in_the_empty_pane_or_the_thread_header(): void
    {
        $ana = $this->person('Data Encoder', 'Ana Cruz');
        $oic = $this->person('Marketing - OIC', 'Olive Ortiz');
        $c = $this->direct($ana, $oic);

        foreach ([$ana, $oic] as $staff) {
            foreach (['/chat', "/chat?c={$c->id}"] as $url) {
                $this->actingAs($staff)->get($url)->assertOk()
                    ->assertDontSee('The owner can read every conversation')
                    ->assertDontSee(self::HEADER, false)
                    ->assertDontSee('fa-solid fa-lock', false);
            }
        }
    }

    public function test_the_ceo_sees_the_line_in_both_places_as_before(): void
    {
        $ceo = $this->ceo();
        $c = $this->direct($this->person(), $this->person());

        foreach (['/chat', "/chat?c={$c->id}"] as $url) {
            $html = $this->actingAs($ceo)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('<i class="fa-solid fa-lock" aria-hidden="true"></i> ' . self::PANE . '</p>', $html, 'the empty pane');
            $this->assertStringContainsString(self::HEADER . '<i class="fa-solid fa-lock" aria-hidden="true"></i> The owner can read every conversation in Likha Chat</div>', $html, 'the thread header');
        }
    }

    public function test_nothing_else_on_the_page_changes(): void
    {
        // An OIC (may create groups, like the CEO) so the only CEO-only parts are the filter and the lock lines.
        $oic = $this->person('Marketing - OIC', 'Ana Cruz');
        $ceo = $this->ceo('Ana Cruz');

        // The chat itself, from its root to the end of its script (the site menu around it differs by role anyway).
        $chat = fn (string $html): string => substr($html, strpos($html, '<div id="lc" '), strrpos($html, '</script>') - strpos($html, '<div id="lc" '));
        $strip = fn (string $html): string => preg_replace([
            '~\s*<p style="margin: 0; font-size: 13px"><i class="fa-solid fa-lock".*?</p>~s',
            '~\s*<div class="lc-lock">.*?</div>~s',
            '~\s*<div class="lc-seg" role="group" aria-label="Which conversations">.*?</div>~s',
            '~const BOOT = .*?;\n~',
            '~(csrf-token" content=|name="_token" value=)"[^"]*"~',
        ], ['', '', '', '', '$1""'], $chat($html));

        $staffHtml = $this->actingAs($oic)->get('/chat')->getContent();
        $ceoHtml = $this->actingAs($ceo)->get('/chat')->getContent();

        $this->assertNotSame($staffHtml, $ceoHtml);
        $this->assertSame($strip($ceoHtml), $strip($staffHtml));
    }
}
