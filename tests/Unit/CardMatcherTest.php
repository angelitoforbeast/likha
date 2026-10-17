<?php

namespace Tests\Unit;

use App\Services\CardStatement\CardMatcher;
use PHPUnit\Framework\TestCase;

/** Pagtutugma ng card statement lines sa Meta records ng iisang card (walang shared id). */
class CardMatcherTest extends TestCase
{
    private int $lineId = 0;
    private int $metaId = 0;

    private function line(?string $sale, string $amount, ?string $post = null, bool $credit = false, bool $ads = true): array
    {
        return ['id' => ++$this->lineId, 'sale_date' => $sale, 'post_date' => $post, 'amount' => $amount, 'is_credit' => $credit, 'is_ads' => $ads];
    }

    private function meta(string $date, string $amount): array
    {
        return ['id' => ++$this->metaId, 'date' => $date, 'amount' => $amount, 'transaction_id' => 'T' . $this->metaId, 'ad_account' => 'A1'];
    }

    private function kinds(array $result): array
    {
        return array_map(fn ($r) => [$r['match_kind'], $r['payment_activity_id'], $r['match_days']], $result['lines']);
    }

    public function test_S_049_6_the_card_with_the_most_matching_amounts_is_suggested(): void
    {
        $lines = [$this->line('2026-09-09', '6278.17'), $this->line('2026-09-10', '1500.00'), $this->line('2026-09-11', '320.40')];

        $out = CardMatcher::suggest($lines, [
            'Visa ···· 1111'       => [$this->meta('2026-09-09', '6278.17')],
            'Mastercard ···· 2222' => [$this->meta('2026-09-10', '1500.00'), $this->meta('2026-09-12', '320.40'), $this->meta('2026-09-09', '999.99')],
            'Visa ···· 3333'       => [],
        ]);

        $this->assertSame('Mastercard ···· 2222', $out['label']);
        $this->assertSame(['Mastercard ···· 2222' => 2, 'Visa ···· 1111' => 1, 'Visa ···· 3333' => 0], $out['scores']);
        $this->assertNull(CardMatcher::suggest($lines, ['Visa ···· 3333' => []])['label'], 'walang tugma = walang mungkahi');
        $this->assertSame('Amex ···· 4444', CardMatcher::suggest($lines, [
            'Visa ···· 1111' => [$this->meta('2026-09-09', '6278.17')], 'Amex ···· 4444' => [$this->meta('2026-09-10', '1500.00')],
        ])['label'], 'tabla → alphabetical');
    }

    public function test_S_049_7_same_amount_on_the_sale_date_then_on_the_post_date(): void
    {
        $l1 = $this->line('2026-09-09', '6278.17', '2026-09-10');
        $l2 = $this->line('2026-09-11', '450.00', '2026-09-12');
        $m1 = $this->meta('2026-09-09', '6278.17');
        $m2 = $this->meta('2026-09-12', '450.00');

        $out = CardMatcher::match([$l1, $l2], [$m1, $m2]);

        $this->assertSame([$l1['id'] => ['sale_date', $m1['id'], 0], $l2['id'] => ['post_date', $m2['id'], 1]], $this->kinds($out));
        $this->assertSame([], $out['meta_missing']);
    }

    public function test_S_049_8_within_two_days_is_near_three_days_is_not(): void
    {
        $lines = [$this->line('2026-09-10', '111.11'), $this->line('2026-09-10', '222.22'), $this->line('2026-09-10', '333.33')];
        $meta  = [$this->meta('2026-09-09', '111.11'), $this->meta('2026-09-12', '222.22'), $this->meta('2026-09-13', '333.33')];

        $out = CardMatcher::match($lines, $meta);

        $this->assertSame([
            1 => ['near', 1, -1],
            2 => ['near', 2, 2],
            3 => ['none', null, null],
        ], $this->kinds($out));
        $this->assertSame([], array_column($out['meta_missing'], 'id'), '2026-09-13 ay labas sa period ng statement (09-10 lang)');
    }

    public function test_S_049_9_one_to_one_closest_date_first_and_exact_before_near(): void
    {
        // Linya A (09-10) at B (09-11), parehong 777.00; Meta 09-11 at 09-12.
        // Exact pass: B ↔ 09-11. Near pass: A ↔ 09-12 (2 araw), hindi na makukuha ang 09-11.
        $lines = [$this->line('2026-09-10', '777.00'), $this->line('2026-09-11', '777.00'), $this->line('2026-09-12', '50.00')];
        $meta  = [$this->meta('2026-09-11', '777.00'), $this->meta('2026-09-12', '777.00'), $this->meta('2026-09-12', '50.00')];

        $out = CardMatcher::match($lines, $meta);

        $this->assertSame([
            1 => ['near', 2, 2],
            2 => ['sale_date', 1, 0],
            3 => ['sale_date', 3, 0],
        ], $this->kinds($out));

        // Dalawang linya, isang record: isa lang ang tutugma, ang pinakamalapit.
        $this->lineId = $this->metaId = 0;
        $out = CardMatcher::match([$this->line('2026-09-08', '90.00'), $this->line('2026-09-11', '90.00')], [$this->meta('2026-09-10', '90.00')]);
        $this->assertSame([1 => ['none', null, null], 2 => ['near', 1, -1]], $this->kinds($out));
    }

    public function test_S_049_10_repeated_amounts_on_a_day_are_tallied_as_a_group(): void
    {
        $lines = [
            $this->line('2026-09-09', '100.00'), $this->line('2026-09-09', '100.00'), $this->line('2026-09-09', '100.00'),
            $this->line('2026-09-10', '100.00'), $this->line('2026-09-10', '100.00'),
            $this->line('2026-09-11', '100.00'),
            $this->line('2026-09-12', '250.00'), // isa sa card, dalawa sa Meta → grupo pa rin, hindi tally
        ];
        $meta = [
            $this->meta('2026-09-09', '100.00'), $this->meta('2026-09-09', '100.00'), $this->meta('2026-09-09', '100.00'),
            $this->meta('2026-09-10', '100.00'), $this->meta('2026-09-10', '100.00'), $this->meta('2026-09-10', '100.00'),
            $this->meta('2026-09-11', '100.00'),
            $this->meta('2026-09-12', '250.00'), $this->meta('2026-09-12', '250.00'),
        ];

        $out = CardMatcher::match($lines, $meta);

        $this->assertSame([
            '2026-09-09|100.00' => ['date' => '2026-09-09', 'amount' => '100.00', 'card_count' => 3, 'meta_count' => 3, 'card_total' => '300.00', 'meta_total' => '300.00', 'tallied' => true, 'meta_ids' => [1, 2, 3], 'shift' => null],
            '2026-09-10|100.00' => ['date' => '2026-09-10', 'amount' => '100.00', 'card_count' => 2, 'meta_count' => 3, 'card_total' => '200.00', 'meta_total' => '300.00', 'tallied' => false, 'meta_ids' => [4, 5, 6], 'shift' => null],
            '2026-09-12|250.00' => ['date' => '2026-09-12', 'amount' => '250.00', 'card_count' => 1, 'meta_count' => 2, 'card_total' => '250.00', 'meta_total' => '500.00', 'tallied' => false, 'meta_ids' => [8, 9], 'shift' => null],
        ], $out['groups']);
        $this->assertSame(['group', 'group', 'group', 'group', 'group', 'sale_date', 'group'], array_column($out['lines'], 'match_kind'), 'isang 100 lang sa 09-11 = one-to-one');
        $this->assertSame('2026-09-10|100.00', $out['lines'][4]['group_key']);
        $this->assertSame([], $out['meta_missing'], 'ang sobrang Meta 100 ay nasa grupo, hindi "wala sa card"');
        $this->assertSame(['tallied' => 1, 'untallied' => 2], [
            'tallied' => $out['totals']['groups_tallied'], 'untallied' => $out['totals']['groups_untallied'],
        ]);
        $this->assertSame([4, '400.00'], [$out['totals']['matched_count'], $out['totals']['matched_total']], '3 (tallied) + 1 one-to-one; 1 sa 09-11');
        $t = $out['totals'];
        $this->assertSame(['450.00', '800.00'], [$t['groups_untallied_card_total'], $t['groups_untallied_meta_total']], '09-10: 200 vs 300; 09-12: 250 vs 500');
        $this->assertSame(
            (float) $t['difference_total'],
            round((float) $t['no_meta_total'] - (float) $t['no_card_total'] + (float) $t['groups_untallied_card_total'] - (float) $t['groups_untallied_meta_total'], 2),
            'ang chips ay sumasama sa kabuuang diperensya'
        );
    }

    public function test_S_049_10_a_group_short_on_one_side_takes_the_same_amount_from_one_day_after_or_before(): void
    {
        // Meta ay may petsang isang araw pagkatapos ng sale date.
        $lines = [$this->line('2026-09-09', '100.00'), $this->line('2026-09-09', '100.00'), $this->line('2026-09-09', '100.00')];
        $meta  = [$this->meta('2026-09-10', '100.00'), $this->meta('2026-09-10', '100.00'), $this->meta('2026-09-10', '100.00')];
        $out = CardMatcher::match($lines, $meta);
        $g = $out['groups']['2026-09-09|100.00'];
        $this->assertSame([3, 3, true, [1, 2, 3], 1], [$g['card_count'], $g['meta_count'], $g['tallied'], $g['meta_ids'], $g['shift']]);
        $this->assertSame([], $out['meta_missing']);

        // Card na kulang: ang card line ng susunod na araw ay sumasama sa grupo.
        $this->lineId = $this->metaId = 0;
        $lines = [$this->line('2026-09-09', '100.00'), $this->line('2026-09-09', '100.00'), $this->line('2026-09-10', '100.00')];
        $meta  = [$this->meta('2026-09-09', '100.00'), $this->meta('2026-09-09', '100.00'), $this->meta('2026-09-09', '100.00')];
        $out = CardMatcher::match($lines, $meta);
        $g = $out['groups']['2026-09-09|100.00'];
        $this->assertSame([3, 3, true, 1], [$g['card_count'], $g['meta_count'], $g['tallied'], $g['shift']]);
        $this->assertSame(['group', '2026-09-09|100.00'], [$out['lines'][3]['match_kind'], $out['lines'][3]['group_key']]);

        // Hindi inaagaw ang one-to-one ng kabilang araw: 09-10 ay may sariling card line at Meta record.
        $this->lineId = $this->metaId = 0;
        $lines = [$this->line('2026-09-09', '100.00'), $this->line('2026-09-09', '100.00'), $this->line('2026-09-10', '100.00')];
        $meta  = [$this->meta('2026-09-10', '100.00')];
        $out = CardMatcher::match($lines, $meta);
        $this->assertSame([2, 0, false, null], [$out['groups']['2026-09-09|100.00']['card_count'], $out['groups']['2026-09-09|100.00']['meta_count'], $out['groups']['2026-09-09|100.00']['tallied'], $out['groups']['2026-09-09|100.00']['shift']]);
        $this->assertSame(['sale_date', 1], [$out['lines'][3]['match_kind'], $out['lines'][3]['payment_activity_id']]);
    }

    public function test_S_049_10_two_groups_next_to_the_same_day_never_share_a_record(): void
    {
        $once = function (array $out, array $lines) {
            $metaIds = array_merge(...array_values(array_column($out['groups'], 'meta_ids')));
            $this->assertSame($metaIds, array_values(array_unique($metaIds)), 'bawat Meta record, isang grupo lang');
            foreach ($out['groups'] as $key => $g) {
                $this->assertSame($g['card_count'], count(array_filter($out['lines'], fn ($r) => $r['group_key'] === $key)), "card_count = mga linya ng {$key}");
            }
            $t = $out['totals'];
            $this->assertSame((float) $t['difference_total'], round((float) $t['no_meta_total'] - (float) $t['no_card_total'] + (float) $t['groups_untallied_card_total'] - (float) $t['groups_untallied_meta_total'], 2));
        };

        // Meta kulang sa dalawang grupo, iisang record sa gitnang araw.
        $lines = [$this->line('2026-09-09', '100.00'), $this->line('2026-09-09', '100.00'), $this->line('2026-09-11', '100.00'), $this->line('2026-09-11', '100.00')];
        $meta  = [$this->meta('2026-09-09', '100.00'), $this->meta('2026-09-10', '100.00'), $this->meta('2026-09-11', '100.00')];
        $out = CardMatcher::match($lines, $meta);
        $once($out, $lines);
        $this->assertSame([true, false, '100.00'], [$out['groups']['2026-09-09|100.00']['tallied'], $out['groups']['2026-09-11|100.00']['tallied'], $out['totals']['difference_total']]);

        // Card kulang sa dalawang grupo, iisang linya sa gitnang araw.
        $this->lineId = $this->metaId = 0;
        $lines = [$this->line('2026-09-09', '100.00'), $this->line('2026-09-10', '100.00'), $this->line('2026-09-11', '100.00')];
        $meta  = [$this->meta('2026-09-09', '100.00'), $this->meta('2026-09-09', '100.00'), $this->meta('2026-09-11', '100.00'), $this->meta('2026-09-11', '100.00')];
        $out = CardMatcher::match($lines, $meta);
        $once($out, $lines);
        $this->assertSame('-100.00', $out['totals']['difference_total']);

        // Isang araw bago: Meta ng 100.00 ay may petsang isang araw bago ang sale date.
        $this->lineId = $this->metaId = 0;
        $out = CardMatcher::match([$this->line('2026-09-10', '100.00'), $this->line('2026-09-10', '100.00')], [$this->meta('2026-09-09', '100.00'), $this->meta('2026-09-09', '100.00')]);
        $this->assertSame([true, -1], [$out['groups']['2026-09-10|100.00']['tallied'], $out['groups']['2026-09-10|100.00']['shift']]);
    }

    public function test_S_049_11_per_day_card_and_meta_totals_with_the_difference(): void
    {
        $lines = [
            $this->line('2026-09-09', '1000.00'), $this->line('2026-09-09', '250.50'),
            $this->line('2026-09-10', '80.00'),
            $this->line('2026-09-10', '40.00', null, true),         // credit: hindi kasama sa card total
            $this->line('2026-09-10', '5000.00', null, false, false), // hindi ads
            $this->line(null, '30.00'),                               // ads na walang petsa: wala sa Meta, kasama sa card total
        ];
        $meta = [$this->meta('2026-09-09', '1000.00'), $this->meta('2026-09-09', '250.50'), $this->meta('2026-09-11', '60.00')];

        $out = CardMatcher::match($lines, $meta);

        $this->assertSame([
            '2026-09-09' => ['card' => '1250.50', 'meta' => '1250.50', 'diff' => '0.00'],
            '2026-09-10' => ['card' => '80.00', 'meta' => '0.00', 'diff' => '80.00'],
        ], $out['days'], '09-11 ay labas sa period (09-09..09-10)');
        $this->assertSame(['credit', 'not_ads'], [$out['lines'][4]['match_kind'], $out['lines'][5]['match_kind']]);
        $this->assertSame(
            ['ads_count' => 4, 'card_total' => '1360.50', 'meta_total' => '1250.50', 'matched_count' => 2, 'matched_total' => '1250.50', 'no_meta_count' => 2, 'no_meta_total' => '110.00', 'no_card_count' => 0, 'no_card_total' => '0.00', 'difference_total' => '110.00', 'groups_tallied' => 0, 'groups_untallied' => 0, 'groups_untallied_card_total' => '0.00', 'groups_untallied_meta_total' => '0.00'],
            $out['totals']
        );
    }

    public function test_S_049_12_meta_records_outside_the_statement_range_are_not_missing_from_the_card(): void
    {
        $lines = [$this->line('2026-09-05', '10.00'), $this->line('2026-09-20', '20.00')];
        $meta  = [
            $this->meta('2026-09-03', '999.00'), // bago ang unang sale date
            $this->meta('2026-09-05', '10.00'),
            $this->meta('2026-09-12', '345.00'), // nasa loob, walang linya
            $this->meta('2026-09-20', '20.00'),
            $this->meta('2026-09-21', '888.00'), // pagkatapos ng huling sale date
        ];

        $out = CardMatcher::match($lines, $meta);

        $this->assertSame(['2026-09-05', '2026-09-20'], $out['period']);
        $this->assertSame([3], array_column($out['meta_missing'], 'id'));
        $this->assertSame(['1', '345.00'], [(string) $out['totals']['no_card_count'], $out['totals']['no_card_total']]);
    }
}
