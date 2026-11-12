<?php

namespace Tests\Feature\AdsDailyOutput;

use Illuminate\Support\Facades\DB;

/**
 * Spec 268: each row of /ads_manager/daily-output shows the item the ad advertised (the unique campaign-name match,
 * else the page's main item of the day), that item's promo, and the page's main flow picture in effect then. New,
 * Back on and budget rows read day D; Turned off rows read D-1 (the day the ad last spent). Data shaped like live
 * 2026-11-12.
 */
class AdsDailyOutputItemTest extends AdsDailyOutputTestCase
{
    private const CHLOE = ['c-chloe', 'Chloe Santiago | SALES VID 1-1-3 | 1000 | SIDEMIRROR | FB'];

    private const FAYE = ['c-faye', '1000-Body Lotion - F'];

    private const KARLA = ['c-karla', 'Karla Chavez | SALES VID 1-1-1 | 1000 | EGGMOLD | FB'];

    private const PATRICIA = ['c-pat', 'Patricia Mercado | SALES VID 1-1-2 | 1000 | RSTICKER | FB'];

    private const FAN = ['c-fan', 'Page Fan | FAN | FB'];

    private const NONE = ['c-none', 'Page None | WIDGET | FB'];

    private function seedLive(): void
    {
        $d = self::D;
        $p = self::PREV;

        // New: Chloe Santiago SIDEMIRROR x3, Faye Navarro Body Lotion x1.
        foreach (['ch1', 'ch2', 'ch3'] as $ad) {
            $this->report($d, 'Chloe Santiago', self::CHLOE, ['s-chloe', 'Chloe set'], $ad, 100);
            $this->catalog($ad, $d, 'Chloe Santiago', self::CHLOE, ['s-chloe', 'Chloe set']);
        }
        $this->report($d, 'Faye Navarro', self::FAYE, ['s-faye', 'Faye set'], 'fy1', 50);
        $this->catalog('fy1', $d, 'Faye Navarro', self::FAYE, ['s-faye', 'Faye set']);
        // Back on: Karla Chavez EGGMOLD.
        $this->report($p, 'Karla Chavez', self::KARLA, ['s-karla', 'Karla set'], 'k1', 0);
        $this->report($d, 'Karla Chavez', self::KARLA, ['s-karla', 'Karla set'], 'k1', 150);
        $this->catalog('k1', '2026-10-20', 'Karla Chavez', self::KARLA, ['s-karla', 'Karla set']);
        // Turned off: Patricia Mercado RSTICKER (spent 11-11, nothing 11-12).
        $this->report($p, 'Patricia Mercado', self::PATRICIA, ['s-pat', 'Patricia set'], 'pt1', 80);
        $this->report($d, 'Patricia Mercado', self::PATRICIA, ['s-pat', 'Patricia set'], 'pt1', 0);
        // Budget up on a page whose campaign names its item; and an off ad on a page with no item at all.
        $this->report($p, 'Page Fan', self::FAN, ['s-fan', 'Fan set'], 'f1', 100, 500);
        $this->report($d, 'Page Fan', self::FAN, ['s-fan', 'Fan set'], 'f1', 200, 1000);
        $this->report($p, 'Page None', self::NONE, ['s-none', 'None set'], 'z1', 40);

        // daily_page_primary_item as on live. Karla has no 11-12 row yet (the day's first order not encoded).
        $this->primary('2026-11-12', 'Chloe Santiago', '1 x SIDE MIRROR');
        $this->primary('2026-11-12', 'Faye Navarro', '1 x BODY LOTION');
        $this->primary('2026-11-11', 'Karla Chavez', '1 x EGG FRYING MOLD');
        $this->primary('2026-11-11', 'Patricia Mercado', '1 x REFLECTIVE STICKER');
        // Patricia sells something else today: the off row still reads 11-11, the day the ad last spent.
        $this->primary('2026-11-12', 'Patricia Mercado', '1 x CAR WAX');
        // The Fan page's main item is another product: the campaign's own match wins.
        $this->primary('2026-11-12', 'Page Fan', '1 x TUMBLER');

        // page_item_settings: Chloe and Faye have no row for their item (promo not set yet); another item of Chloe's
        // has a promo that must not leak.
        $this->item('Chloe Santiago', '1 x PHONE HOLDER', '149 FREE SF', '2026-10-01');
        $this->item('Karla Chavez', '1 x EGG FRYING MOLD', '99+100(SF)= 199', '2026-10-29');
        $this->item('Patricia Mercado', '1 x REFLECTIVE STICKER', '99+100(SF)= 199', '2026-11-10');
        $this->item('Patricia Mercado', '1 x CAR WAX', '299 FREE SF', '2026-11-01');
        $this->item('Page Fan', '1 x FAN', '399', '2026-11-01');
        $this->item('Page Fan', '1 x FAN', '449', '2026-11-13'); // later: not in effect on 11-12
        $this->item('Page Fan', '1 x TUMBLER', '199', '2026-11-01');

        // Main flow pictures (page_settings chatbot/picture). Chloe: current since 11-12 11:05:57, the previous one
        // from 11-09 until then. Patricia: replaced today at 08:00 (the off row shows the one of 11-11).
        $this->picture('Chloe Santiago', 'https://botcake.pro/i/chloe-old.jpg', '2026-11-09 14:00:00', '2026-11-12 11:05:57');
        $this->picture('Chloe Santiago', 'https://botcake.pro/i/chloe-new.jpg', '2026-11-12 11:05:57');
        $this->picture('Patricia Mercado', 'https://botcake.pro/i/pat-old.jpg?a=1&b=2', '2026-11-01 09:00:00', '2026-11-12 08:00:00');
        $this->picture('Patricia Mercado', 'https://botcake.pro/i/pat-new.jpg', '2026-11-12 08:00:00');
        // Karla: not an http(s) URL → placeholder. Faye: none.
        $this->picture('Karla Chavez', 'javascript:alert(1)', '2026-11-01 09:00:00');
        // Another provider's or key's picture is not the main flow picture.
        $this->picture('Page Fan', 'https://botcake.pro/i/fan.jpg', '2026-11-01 09:00:00');
        DB::table('page_settings')->insert([
            'page_name' => 'Faye Navarro', 'provider' => 'botcake', 'key' => 'picture', 'value' => 'https://botcake.pro/i/x.jpg',
            'is_secret' => false, 'active' => true, 'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-01 09:00:00',
        ]);
    }

    /** @return array the campaign entry (item, promo, picture, rows) of one list */
    private function entry($response, string $kind, string $page, string $campaign): array
    {
        return $response->viewData('lists')[$kind][$page][$campaign];
    }

    public function test_S1_1_and_S1_3_the_item_is_the_campaign_match_else_the_main_item_of_the_day_used(): void
    {
        $this->seedLive();

        $r = $this->open();

        $chloe = $this->entry($r, 'new', 'Chloe Santiago', self::CHLOE[1]);
        $this->assertSame(['1 x SIDE MIRROR', 'main', '2026-11-12'], [$chloe['item'], $chloe['item_from'], $chloe['item_day']]);
        $faye = $this->entry($r, 'new', 'Faye Navarro', self::FAYE[1]);
        $this->assertSame(['1 x BODY LOTION', 'main'], [$faye['item'], $faye['item_from']]);
        // Back on, D = 11-12 has no row yet: the latest earlier row (11-11), marked.
        $karla = $this->entry($r, 'on', 'Karla Chavez', self::KARLA[1]);
        $this->assertSame(['1 x EGG FRYING MOLD', 'main', '2026-11-11', '2026-11-12'], [$karla['item'], $karla['item_from'], $karla['item_day'], $karla['item_target']]);
        // Turned off reads D-1, not today's main item.
        $pat = $this->entry($r, 'off', 'Patricia Mercado', self::PATRICIA[1]);
        $this->assertSame(['1 x REFLECTIVE STICKER', 'main', '2026-11-11', '2026-11-11'], [$pat['item'], $pat['item_from'], $pat['item_day'], $pat['item_target']]);
        // Budget: the unique campaign match beats the page's main item.
        $fan = $this->entry($r, 'budget_up', 'Page Fan', self::FAN[1]);
        $this->assertSame(['1 x FAN', 'campaign', null], [$fan['item'], $fan['item_from'], $fan['item_day']]);
        // No match and no main item: no item.
        $this->assertNull($this->entry($r, 'off', 'Page None', self::NONE[1])['item']);

        $r->assertSeeInOrder(['data-list="new"', 'Chloe Santiago', '1 x SIDE MIRROR', '(main item)'], false);
        $r->assertSeeInOrder(['data-list="on"', 'Karla Chavez', '1 x EGG FRYING MOLD', '(main item, kahapon)'], false);
    }

    public function test_S1_2_and_S1_3_the_promo_is_the_promo_of_that_item(): void
    {
        $this->seedLive();

        $r = $this->open();

        // The live examples.
        $this->assertNull($this->entry($r, 'new', 'Chloe Santiago', self::CHLOE[1])['promo']);
        $this->assertNull($this->entry($r, 'new', 'Faye Navarro', self::FAYE[1])['promo']);
        $this->assertSame('99+100(SF)= 199', $this->entry($r, 'on', 'Karla Chavez', self::KARLA[1])['promo']);
        $this->assertSame('99+100(SF)= 199', $this->entry($r, 'off', 'Patricia Mercado', self::PATRICIA[1])['promo']);
        // The latest effective_date <= the day used.
        $this->assertSame('399', $this->entry($r, 'budget_up', 'Page Fan', self::FAN[1])['promo']);
        $this->assertNull($this->entry($r, 'off', 'Page None', self::NONE[1])['promo']);

        $html = $r->getContent();
        $this->assertSame(2, substr_count($html, 'walang promo na naka-set')); // Chloe and Faye
        $r->assertDontSee('149 FREE SF'); // Chloe's other item
        $r->assertDontSee('299 FREE SF'); // Patricia's item of today
        $r->assertDontSee('449');
        $r->assertSeeInOrder(['data-list="off"', 'Page None', 'data-col="item"', '—', 'data-col="promo"', '—'], false);

        // An item whose latest promo is "NONE" (cleared) counts as not set.
        $this->item('Karla Chavez', '1 x EGG FRYING MOLD', 'NONE', '2026-11-12');
        $this->assertNull($this->entry($this->open(), 'on', 'Karla Chavez', self::KARLA[1])['promo']);
    }

    public function test_S2_the_main_flow_picture_in_effect_then_as_a_safe_thumbnail(): void
    {
        $this->seedLive();

        $r = $this->open();

        // New today: the picture in effect now (set 11:05:57 today).
        $this->assertSame('https://botcake.pro/i/chloe-new.jpg', $this->entry($r, 'new', 'Chloe Santiago', self::CHLOE[1])['picture']);
        // Turned off: the picture at the end of 11-11 (replaced today at 08:00), the URL printed escaped.
        $this->assertSame('https://botcake.pro/i/pat-old.jpg?a=1&b=2', $this->entry($r, 'off', 'Patricia Mercado', self::PATRICIA[1])['picture']);
        // No picture, a non-http value, another provider: none.
        $this->assertNull($this->entry($r, 'new', 'Faye Navarro', self::FAYE[1])['picture']);
        $this->assertNull($this->entry($r, 'on', 'Karla Chavez', self::KARLA[1])['picture']);
        $this->assertSame('https://botcake.pro/i/fan.jpg', $this->entry($r, 'budget_up', 'Page Fan', self::FAN[1])['picture']);

        $r->assertSee('<a href="https://botcake.pro/i/chloe-new.jpg" target="_blank" rel="noopener noreferrer"', false);
        $r->assertSee('<img src="https://botcake.pro/i/chloe-new.jpg"', false);
        $r->assertSee('loading="lazy" referrerpolicy="no-referrer"', false);
        $r->assertSee('src="https://botcake.pro/i/pat-old.jpg?a=1&amp;b=2"', false);
        $r->assertDontSee('pat-new.jpg');
        $r->assertDontSee('chloe-old.jpg');
        $r->assertDontSee('javascript:alert');
        $r->assertDontSee('botcake.pro/i/x.jpg');
        $this->assertSame(3, substr_count($r->getContent(), 'data-test="no-picture"')); // Faye, Karla, Page None

        // A past day: the picture in effect at the end of that day (Chloe's previous one on 11-10).
        $this->catalog('ch0', '2026-11-10', 'Chloe Santiago', self::CHLOE, ['s-chloe', 'Chloe set']);
        $this->report('2026-11-10', 'Chloe Santiago', self::CHLOE, ['s-chloe', 'Chloe set'], 'ch0', 30);
        $past = $this->open('?day=2026-11-10');
        $this->assertSame('https://botcake.pro/i/chloe-old.jpg', $this->entry($past, 'new', 'Chloe Santiago', self::CHLOE[1])['picture']);
        // Before any picture was set: none.
        $this->catalog('ch9', '2026-11-05', 'Chloe Santiago', self::CHLOE, ['s-chloe', 'Chloe set']);
        $this->assertNull($this->entry($this->open('?day=2026-11-05'), 'new', 'Chloe Santiago', self::CHLOE[1])['picture']);
    }
}
