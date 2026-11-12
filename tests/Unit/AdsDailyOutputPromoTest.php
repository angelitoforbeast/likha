<?php

namespace Tests\Unit;

use App\Http\Controllers\AdsDailyOutputController as Page;
use PHPUnit\Framework\TestCase;

/**
 * Spec 266 S1.3: the campaign → promo matcher. Each "|" part of the campaign name is normalized (uppercase, no spaces,
 * no leading "<n> x ") and matched to the page's items when equal or one contains the other; a promo is returned only
 * when exactly one distinct promo matches.
 */
class AdsDailyOutputPromoTest extends TestCase
{
    public function test_normalize_drops_case_spaces_and_the_leading_quantity(): void
    {
        $this->assertSame('SIDEMIRROR', Page::normalize('2 x SIDE MIRROR'));
        $this->assertSame('BODYLOTION', Page::normalize('1 x Body Lotion'));
        $this->assertSame('SIDEMIRROR', Page::normalize(' SIDEMIRROR '));
        $this->assertSame('2XLSHIRT', Page::normalize('2XL SHIRT')); // not a quantity
    }

    public function test_sidemirror_matches_2_x_side_mirror(): void
    {
        $this->assertSame('99+100(SF)= 199', Page::promoFor(
            'Page Mirror | SALES VID 1-1-3 | 1000 | SIDEMIRROR | FB',
            ['2 x SIDE MIRROR' => '99+100(SF)= 199', '1 x TUMBLER' => '149'],
        ));
    }

    public function test_body_lotion_with_price_and_suffix_matches_1_x_body_lotion(): void
    {
        $this->assertSame('149 FREE SF', Page::promoFor('1000-Body Lotion - F', ['1 x BODY LOTION' => '149 FREE SF']));
        $this->assertSame('149 FREE SF', Page::promoFor('Page Lotion | 1000-Body Lotion - F | FB', ['1 x BODY LOTION' => '149 FREE SF']));
    }

    public function test_no_match_gives_no_promo(): void
    {
        $this->assertNull(Page::promoFor('Page Egg | EGGMOLD | FB', ['2 x SIDE MIRROR' => '199', '1 x BODY LOTION' => '149']));
        $this->assertNull(Page::promoFor('Page Egg | EGGMOLD | FB', []));
    }

    public function test_two_different_promos_give_no_promo_but_the_same_promo_twice_is_kept(): void
    {
        $this->assertNull(Page::promoFor('X | SIDEMIRROR | FB', ['1 x SIDE MIRROR' => '199', '2 x SIDE MIRROR' => '299']));
        $this->assertSame('199', Page::promoFor('X | SIDEMIRROR | FB', ['1 x SIDE MIRROR' => '199', '2 x SIDE MIRROR' => '199']));
    }

    public function test_short_and_numeric_parts_are_not_matched(): void
    {
        // "FB" and "1000" would otherwise be "contained" in item names.
        $this->assertNull(Page::promoFor('X | 1000 | FB', ['FBCOVER' => '99', '1000 PCS TAPE' => '50']));
        $this->assertNull(Page::promoFor('X | SIDEMIRROR | FB', ['2 x SIDE MIRROR' => '']));
    }
}
