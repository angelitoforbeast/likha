<?php

namespace Tests\Feature\AdsDailyOutput;

/**
 * Spec 266 S1: /ads_manager/daily-output shows, for one day, the new ads, the ads turned off, the ads back on, the
 * budget changes and what is running, grouped by page and campaign with the promo, plus a 14-day table.
 */
class AdsDailyOutputPageTest extends AdsDailyOutputTestCase
{
    private const MIRROR = ['c-mir', 'Page Mirror | SALES VID 1-1-3 | 1000 | SIDEMIRROR | FB'];

    private const LOTION = ['c-lot', '1000-Body Lotion - F'];

    private const EGG = ['c-egg', 'Page Egg | EGGMOLD | FB'];

    private const TUMBLER1 = ['c-t1', 'Page Tumbler | TUMBLER | FB'];

    private const TUMBLER2 = ['c-t2', 'Page Tumbler | TUMBLER 2 | FB'];

    private const BAG = ['c-bag', 'Page Bag | BAG | FB'];

    private const FAN = ['c-fan', 'Page Fan | FAN | FB'];

    /** A small 2026-11-12 shaped like the live one (new, off, back on, budget changes, running, noise). */
    private function seedDay(): void
    {
        $d = self::D;
        $p = self::PREV;

        // New: three Mirror ads first seen and spending today, one Lotion ad first seen today with no spend yet.
        foreach (['n1' => 100, 'n2' => 120, 'n3' => 80] as $ad => $spend) {
            $this->report($d, 'Page Mirror', self::MIRROR, ['s-mir', 'Mirror set'], $ad, $spend, 300); // budget only today: no change
            $this->catalog($ad, $d, 'Page Mirror', self::MIRROR, ['s-mir', 'Mirror set']);
        }
        $this->report($d, 'Page Lotion', self::LOTION, ['s-lot', 'Lotion set'], 'n4', 0);
        $this->catalog('n4', $d, 'Page Lotion', self::LOTION, ['s-lot', 'Lotion set']);

        // Back on: an old Egg ad with 0 spend yesterday and spend today.
        $this->report($p, 'Page Egg', self::EGG, ['s-egg', 'Egg set'], 'b1', 0);
        $this->report($d, 'Page Egg', self::EGG, ['s-egg', 'Egg set'], 'b1', 150);
        $this->catalog('b1', '2026-11-01', 'Page Egg', self::EGG, ['s-egg', 'Egg set']);
        // Spending today, none yesterday, but not in ad_catalog: neither new nor back on (never guessed).
        $this->report($d, 'Page Egg', self::EGG, ['s-egg', 'Egg set'], 'x1', 50);

        // Turned off: 4 ads in 3 campaigns; o3 has no row today at all.
        $this->report($p, 'Page Tumbler', self::TUMBLER1, ['s-t1', 'T1 set'], 'o1', 300, null, '2026-11-12 18:47:00');
        $this->report($d, 'Page Tumbler', self::TUMBLER1, ['s-t1', 'T1 set'], 'o1', 0, null, '2026-11-12 10:05:00');
        $this->report($p, 'Page Tumbler', self::TUMBLER1, ['s-t1', 'T1 set'], 'o2', 200);
        $this->report($d, 'Page Tumbler', self::TUMBLER1, ['s-t1', 'T1 set'], 'o2', 0);
        $this->report($p, 'Page Tumbler', self::TUMBLER2, ['s-t2', 'T2 set'], 'o3', 50);
        $this->report($p, 'Page Bag', self::BAG, ['s-bag', 'Bag set'], 'o4', 80);
        $this->report($d, 'Page Bag', self::BAG, ['s-bag', 'Bag set'], 'o4', 0);

        // Running both days with the same budget: no event.
        $this->report($p, 'Page Bag', self::BAG, ['s-bag2', 'Bag set 2'], 'r1', 100, 500);
        $this->report($d, 'Page Bag', self::BAG, ['s-bag2', 'Bag set 2'], 'r1', 90, 500);

        // Budget up (one ad set, two ads) and budget down.
        $this->report($p, 'Page Fan', self::FAN, ['s-fan', 'Fan set'], 'f1', 100, 500);
        $this->report($d, 'Page Fan', self::FAN, ['s-fan', 'Fan set'], 'f1', 200, 1000);
        $this->report($p, 'Page Fan', self::FAN, ['s-fan', 'Fan set'], 'f2', 50, 500);
        $this->report($d, 'Page Fan', self::FAN, ['s-fan', 'Fan set'], 'f2', 60, 1000);
        $this->report($p, 'Page Fan', self::FAN, ['s-fan2', 'Fan set 2'], 'f3', 40, 1000);
        $this->report($d, 'Page Fan', self::FAN, ['s-fan2', 'Fan set 2'], 'f3', 30, 800);

        // Noise: spend two days ago only; a row without ad_id; a row tomorrow.
        $this->report('2026-11-10', 'Page Bag', self::BAG, ['s-bag', 'Bag set'], 'z1', 100);
        $this->report($p, 'Page Bag', self::BAG, ['s-bag', 'Bag set'], '', 999);
        $this->report('2026-11-13', 'Page Bag', self::BAG, ['s-bag', 'Bag set'], 'o4', 70);

        // Promos: the latest effective_date <= day wins; two items with the same promo still match one promo.
        $this->item('Page Mirror', '2 x SIDE MIRROR', '50 OFF', '2026-10-01');
        $this->item('Page Mirror', '2 x SIDE MIRROR', '99+100(SF)= 199', '2026-11-01');
        $this->item('Page Mirror', '2 x SIDE MIRROR', '1 PESO', '2026-11-13');
        $this->item('Page Mirror', '1 x SIDE MIRROR', '99+100(SF)= 199', '2026-11-05');
        $this->item('Page Lotion', '1 x BODY LOTION', '149 FREE SF', '2026-11-01');
        // Two different promos match the Tumbler campaigns: shown as "—".
        $this->item('Page Tumbler', '1 x TUMBLER', '199', '2026-11-01');
        $this->item('Page Tumbler', '2 x TUMBLER', '299', '2026-11-01');
    }

    /** @return array<string, array<string, array>> page => campaign => {promo, rows} of one list */
    private function list($response, string $kind): array
    {
        return $response->viewData('lists')[$kind];
    }

    public function test_S1_1_today_shows_the_summary_tiles_and_the_last_sync_of_the_day(): void
    {
        $this->seedDay();

        $response = $this->open();

        $response->assertOk();
        $this->assertSame(self::D, $response->viewData('day'));
        $this->assertSame([
            'new' => 4, 'off' => 4, 'off_campaigns' => 3, 'on' => 1, 'budget_up' => 1, 'budget_down' => 1,
            'running_ads' => 9, 'running_pages' => 4,
        ], $response->viewData('tiles'));

        // Only today's rows count for the last sync (yesterday's rows were rewritten later at 18:47).
        $this->assertSame('2026-11-12 18:46:00', $response->viewData('syncedAt'));
        $response->assertSeeInOrder(['Huling sync:', 'Nov 12 18:46', 'hindi pa buo ang araw']);
        $response->assertSeeInOrder(['Turned off', '4', 'sa 3 campaigns']);
        $response->assertSeeInOrder(['Running', '9', 'ads sa', '4', 'pages']);
    }

    public function test_S1_1_a_date_picker_and_previous_and_next_day(): void
    {
        $this->seedDay();

        $today = $this->open();
        $today->assertSee('name="day"', false);
        $today->assertSee('value="2026-11-12"', false);
        $today->assertSee(self::URL.'?day=2026-11-11', false);
        $this->assertNull($today->viewData('nextDay')); // no "next" after today
        $today->assertDontSee('data-test="next-day"', false);

        $yesterday = $this->open('?day=2026-11-11');
        $this->assertSame(self::PREV, $yesterday->viewData('day'));
        $this->assertSame(self::D, $yesterday->viewData('nextDay'));
        $yesterday->assertSee(self::URL.'?day=2026-11-12', false);
        $yesterday->assertSee(self::URL.'?day=2026-11-10', false);
        $yesterday->assertDontSee('hindi pa buo ang araw');
        // On 11-11 the only move is z1 (spent on 11-10, nothing on 11-11).
        $this->assertSame(1, $yesterday->viewData('tiles')['off']);

        $bad = $this->open('?day=nope');
        $this->assertSame(self::D, $bad->viewData('day'));
    }

    public function test_S1_2_each_list_is_grouped_by_page_then_campaign_with_ad_set_ads_and_spend(): void
    {
        $this->seedDay();

        $response = $this->open();

        $off = $this->list($response, 'off');
        $this->assertSame(['Page Bag', 'Page Tumbler'], array_keys($off));
        $this->assertSame([self::TUMBLER2[1], self::TUMBLER1[1]], array_keys($off['Page Tumbler'])); // natural order of the names
        $this->assertSame(
            [['ad_set' => 'T1 set', 'ads' => 2, 'prev_spend' => 500.0, 'spend' => 0.0, 'prev_budget' => null, 'budget' => null]],
            $off['Page Tumbler'][self::TUMBLER1[1]]['rows'],
        );
        $this->assertSame(1, $off['Page Tumbler'][self::TUMBLER2[1]]['rows'][0]['ads']);
        $this->assertSame(50.0, $off['Page Tumbler'][self::TUMBLER2[1]]['rows'][0]['prev_spend']);
        $this->assertSame(80.0, $off['Page Bag'][self::BAG[1]]['rows'][0]['prev_spend']);

        $new = $this->list($response, 'new');
        $this->assertSame(['Page Lotion', 'Page Mirror'], array_keys($new));
        $mirror = $new['Page Mirror'][self::MIRROR[1]]['rows'][0];
        $this->assertSame(['Mirror set', 3, 0.0, 300.0], [$mirror['ad_set'], $mirror['ads'], $mirror['prev_spend'], $mirror['spend']]);
        $this->assertSame(0.0, $new['Page Lotion'][self::LOTION[1]]['rows'][0]['spend']);

        $on = $this->list($response, 'on');
        $this->assertSame(['Page Egg'], array_keys($on));
        $this->assertSame(1, $on['Page Egg'][self::EGG[1]]['rows'][0]['ads']); // b1 only, not x1
        $this->assertSame(150.0, $on['Page Egg'][self::EGG[1]]['rows'][0]['spend']);

        $up = $this->list($response, 'budget_up')['Page Fan'][self::FAN[1]]['rows'];
        $this->assertSame(
            [['ad_set' => 'Fan set', 'ads' => 2, 'prev_spend' => 150.0, 'spend' => 260.0, 'prev_budget' => 500.0, 'budget' => 1000.0]],
            $up,
        );
        $down = $this->list($response, 'budget_down')['Page Fan'][self::FAN[1]]['rows'];
        $this->assertSame([1000.0, 800.0], [$down[0]['prev_budget'], $down[0]['budget']]);

        // The HTML: one table per kind, with the old → new budget and the spend.
        $response->assertSeeInOrder(['data-list="new"', 'Page Lotion', 'Page Mirror', 'data-list="off"', 'Page Bag', 'Page Tumbler', 'T1 set', '₱500.00', '₱0.00'], false);
        $response->assertSeeInOrder(['data-list="budget_up"', 'Fan set', '₱500 → ₱1,000', '₱150.00', '₱260.00'], false);
        $response->assertSeeInOrder(['data-list="budget_down"', 'Fan set 2', '₱1,000 → ₱800'], false);
    }

    public function test_S1_3_the_promo_is_shown_only_on_a_unique_match_with_the_latest_effective_date(): void
    {
        $this->seedDay();

        $response = $this->open();

        // SIDEMIRROR → "2 x SIDE MIRROR" and "1 x SIDE MIRROR", both "99+100(SF)= 199" on 11-12 (not the 11-13 one).
        $this->assertSame('99+100(SF)= 199', $this->list($response, 'new')['Page Mirror'][self::MIRROR[1]]['promo']);
        // "1000-Body Lotion - F" → "1 x BODY LOTION".
        $this->assertSame('149 FREE SF', $this->list($response, 'new')['Page Lotion'][self::LOTION[1]]['promo']);
        // TUMBLER matches two items with different promos: never guessed.
        $this->assertNull($this->list($response, 'off')['Page Tumbler'][self::TUMBLER1[1]]['promo']);
        // No item for the page: none.
        $this->assertNull($this->list($response, 'on')['Page Egg'][self::EGG[1]]['promo']);
        $response->assertSee('99+100(SF)= 199');
        $response->assertDontSee('1 PESO');

        // On 11-05 the older promo was in effect (the mirror ads are not new then, so read it on a day they are).
        $this->catalog('n9', '2026-10-15', 'Page Mirror', self::MIRROR, ['s-mir', 'Mirror set']);
        $this->report('2026-10-15', 'Page Mirror', self::MIRROR, ['s-mir', 'Mirror set'], 'n9', 10);
        $old = $this->open('?day=2026-10-15');
        $this->assertSame('50 OFF', $this->list($old, 'new')['Page Mirror'][self::MIRROR[1]]['promo']);
    }

    public function test_S1_4_a_14_day_table_newest_first_each_day_links_to_that_day(): void
    {
        $this->seedDay();
        // Older moves inside the window (11-12 back to 10-30) and one just outside it.
        $c = ['c-old', 'Page Old | OLD | FB'];
        $s = ['s-old', 'Old set'];
        // 11-09: two ads turned off (spent 11-08, nothing 11-09).
        $this->report('2026-11-08', 'Page Old', $c, $s, 'q1', 10);
        $this->report('2026-11-08', 'Page Old', $c, $s, 'q2', 10);
        $this->report('2026-11-09', 'Page Old', $c, $s, 'q2', 0);
        // 11-07: one ad back on (first seen 10-20, 0 on 11-06), and a budget change 200 → 250 on 11-07.
        $this->catalog('q3', '2026-10-20', 'Page Old', $c, $s);
        $this->report('2026-11-06', 'Page Old', $c, $s, 'q3', 0, 200);
        $this->report('2026-11-07', 'Page Old', $c, $s, 'q3', 30, 250);
        // 10-30 (first day of the window): new ad, and an ad off (spent 10-29, the day before the window).
        $this->catalog('q4', '2026-10-30', 'Page Old', $c, $s);
        $this->report('2026-10-29', 'Page Old', $c, $s, 'q5', 25);
        // 10-29 is outside the window: its new ad is not counted.
        $this->catalog('q6', '2026-10-29', 'Page Old', $c, $s);

        $response = $this->open();

        $mini = $response->viewData('mini');
        $this->assertCount(14, $mini);
        $this->assertSame('2026-11-12', $mini[0]['day']);
        $this->assertSame('2026-10-30', $mini[13]['day']);
        $byDay = collect($mini)->keyBy('day');

        // Today's row is the same as the tiles.
        $tiles = $response->viewData('tiles');
        $this->assertSame(
            [$tiles['new'], $tiles['off'], $tiles['on'], $tiles['budget_up'], $tiles['budget_down']],
            [$byDay['2026-11-12']['new'], $byDay['2026-11-12']['off'], $byDay['2026-11-12']['on'], $byDay['2026-11-12']['budget_up'], $byDay['2026-11-12']['budget_down']],
        );
        $this->assertSame(1, $byDay['2026-11-11']['off']); // z1
        $this->assertSame(2, $byDay['2026-11-09']['off']);
        $this->assertSame(1, $byDay['2026-11-07']['on']);
        $this->assertSame(1, $byDay['2026-11-07']['budget_up']);
        $this->assertSame(['new' => 1, 'off' => 1], ['new' => $byDay['2026-10-30']['new'], 'off' => $byDay['2026-10-30']['off']]);
        $this->assertSame(0, $byDay['2026-11-05']['new'] + $byDay['2026-11-05']['off'] + $byDay['2026-11-05']['on'] + $byDay['2026-11-05']['budget_up'] + $byDay['2026-11-05']['budget_down']);

        $response->assertSeeInOrder(['data-mini="2026-11-12"', 'data-mini="2026-11-11"', 'data-mini="2026-10-30"'], false);
        $response->assertSee(self::URL.'?day=2026-11-07', false);
        $response->assertSee(self::URL.'?day=2026-10-30', false);

        // Opening a day from the table shows that day's lists.
        $day = $this->open('?day=2026-11-09');
        $this->assertSame(2, $day->viewData('tiles')['off']);
        $this->assertSame('2026-11-09', $day->viewData('mini')[0]['day']);
        $this->assertSame(2, $day->viewData('mini')[0]['off']);
    }

    public function test_S1_5_an_empty_day_shows_walang_galaw_in_every_list(): void
    {
        $this->seedDay();

        $response = $this->open('?day=2026-09-01');

        $response->assertOk();
        $this->assertSame([
            'new' => 0, 'off' => 0, 'off_campaigns' => 0, 'on' => 0, 'budget_up' => 0, 'budget_down' => 0,
            'running_ads' => 0, 'running_pages' => 0,
        ], $response->viewData('tiles'));
        $this->assertNull($response->viewData('syncedAt'));
        $this->assertSame(5, substr_count($response->getContent(), 'Walang galaw'));
        $response->assertSee('Huling sync: <span class="font-mono">—</span>', false);
    }
}
