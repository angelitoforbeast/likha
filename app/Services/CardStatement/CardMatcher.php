<?php

namespace App\Services\CardStatement;

/**
 * Tugma ng card statement lines sa Meta records ng iisang card, walang shared id (amount + petsa lang):
 *  1. Amount na umuulit sa isang araw (hal. 100.00 nang ≥2 sa card o sa Meta) → isang grupo bawat araw: bilang vs bilang.
 *  2. Ang iba, one-to-one sa eksaktong amount: parehong sale date, tapos post date, tapos ±1..2 araw (pinakamalapit muna).
 *     Bawat pass ay sa lahat ng linya bago ang susunod, kaya hindi naaagaw ng malapit ang eksaktong tugma.
 *  3. Natira: linya = "Nasa card pero wala sa Meta"; Meta record sa loob ng period (unang..huling sale date)
 *     = "Nasa Meta pero wala sa card". Credit at hindi-ads na linya ay nakalista lang, hindi itinutugma.
 * Lines: [id, sale_date, post_date, amount, is_credit, is_ads]; Meta: [id, date, amount, transaction_id, ad_account].
 */
class CardMatcher
{
    public const NEAR_DAYS = 2;

    public static function match(array $lines, array $meta): array
    {
        $period = self::period($lines);
        $out    = [];
        $charges = [];
        $undated = [];
        foreach ($lines as $l) {
            $kind = !$l['is_ads'] ? 'not_ads' : ($l['is_credit'] ? 'credit' : null);
            $out[$l['id']] = ['match_kind' => $kind, 'payment_activity_id' => null, 'group_key' => null, 'match_days' => null];
            if ($kind === null && $l['sale_date'] !== null) $charges[] = $l;
            elseif ($kind === null) { $out[$l['id']]['match_kind'] = 'none'; $undated[] = $l; } // walang petsa: hindi matutugma, pero nasa card
        }

        // 1. Mga grupo: (petsa, amount) na ≥2 sa card (sale date) o ≥2 sa Meta.
        $count = [];
        foreach ($charges as $l) $count[self::key($l['sale_date'], $l['amount'])]['card'][] = $l;
        foreach ($meta as $m) $count[self::key($m['date'], $m['amount'])]['meta'][] = $m;
        $groups = [];
        $used   = [];
        foreach ($count as $key => $sides) {
            $c = $sides['card'] ?? [];
            $m = $sides['meta'] ?? [];
            if ($c === [] || (count($c) < 2 && count($m) < 2)) continue;
            [$date, $amount] = explode('|', $key);
            $groups[$key] = [
                'date' => $date, 'amount' => $amount, 'card_count' => count($c), 'meta_count' => count($m),
                'card_total' => self::money(self::cents($amount) * count($c)), 'meta_total' => self::money(self::cents($amount) * count($m)),
                'tallied' => count($c) === count($m),
                'meta_ids' => array_column($m, 'id'), 'shift' => null,
            ];
            foreach ($c as $l) $out[$l['id']] = ['match_kind' => 'group', 'payment_activity_id' => null, 'group_key' => $key, 'match_days' => null];
            foreach ($m as $r) $used[$r['id']] = true;
        }
        ksort($groups);

        // 1b. Grupong kulang ang isang panig: ang parehong amount mula sa susunod na araw, tapos sa nakaraan
        //     (hal. Meta na may petsang isang araw pagkatapos ng sale date). Hindi kinukuha ang kailangan ng
        //     sariling one-to-one ng araw na iyon: may reserba para sa bawat bukas na linya / record doon.
        $openOn = fn (string $d, int $cents) => array_values(array_filter($charges, fn ($l) => $out[$l['id']]['match_kind'] === null && $l['sale_date'] === $d && self::cents($l['amount']) === $cents));
        $freeOn = fn (string $d, int $cents) => array_values(array_filter($meta, fn ($m) => !isset($used[$m['id']]) && $m['date'] === $d && self::cents($m['amount']) === $cents));
        foreach ($groups as $key => &$g) {
            $cents = self::cents($g['amount']);
            foreach ([1, -1] as $off) {
                $d = gmdate('Y-m-d', strtotime($g['date'] . ' 00:00:00 UTC') + $off * 86400);
                $short = $g['card_count'] - $g['meta_count'];
                if ($short > 0) {
                    $take = array_slice($freeOn($d, $cents), count($openOn($d, $cents)), $short);
                    foreach ($take as $m) { $used[$m['id']] = true; $g['meta_ids'][] = $m['id']; }
                    $g['meta_count'] += count($take);
                } elseif ($short < 0) {
                    $take = array_slice($openOn($d, $cents), count($freeOn($d, $cents)), -$short);
                    foreach ($take as $l) $out[$l['id']] = ['match_kind' => 'group', 'payment_activity_id' => null, 'group_key' => $key, 'match_days' => $off];
                    $g['card_count'] += count($take);
                } else {
                    break;
                }
                if (!empty($take)) $g['shift'] ??= $off;
            }
            $g['card_total'] = self::money($cents * $g['card_count']);
            $g['meta_total'] = self::money($cents * $g['meta_count']);
            $g['tallied'] = $g['card_count'] === $g['meta_count'];
        }
        unset($g);

        // 2. One-to-one sa eksaktong amount.
        $open = array_values(array_filter($charges, fn ($l) => $out[$l['id']]['match_kind'] === null));
        $byAmount = [];
        foreach ($meta as $m) if (!isset($used[$m['id']])) $byAmount[self::cents($m['amount'])][] = $m;
        foreach ($byAmount as &$list) usort($list, fn ($a, $b) => $a['id'] <=> $b['id']);
        unset($list);

        foreach (['sale_date', 'post_date'] as $field) {
            foreach ($open as $l) {
                if ($out[$l['id']]['match_kind'] !== null || $l[$field] === null) continue;
                foreach ($byAmount[self::cents($l['amount'])] ?? [] as $m) {
                    if (isset($used[$m['id']]) || $m['date'] !== $l[$field]) continue;
                    $used[$m['id']] = true;
                    $out[$l['id']] = ['match_kind' => $field, 'payment_activity_id' => $m['id'], 'group_key' => null, 'match_days' => self::days($l['sale_date'], $m['date'])];
                    break;
                }
            }
        }

        $pairs = [];
        foreach ($open as $i => $l) {
            if ($out[$l['id']]['match_kind'] !== null) continue;
            foreach ($byAmount[self::cents($l['amount'])] ?? [] as $m) {
                if (isset($used[$m['id']])) continue;
                $d = self::days($l['sale_date'], $m['date']);
                if ($d !== 0 && abs($d) <= self::NEAR_DAYS) $pairs[] = [abs($d), $i, $m['id'], $d, $l['id']];
            }
        }
        sort($pairs);
        foreach ($pairs as [, , $metaId, $d, $lineId]) {
            if (isset($used[$metaId]) || $out[$lineId]['match_kind'] !== null) continue;
            $used[$metaId] = true;
            $out[$lineId] = ['match_kind' => 'near', 'payment_activity_id' => $metaId, 'group_key' => null, 'match_days' => $d];
        }
        foreach ($open as $l) $out[$l['id']]['match_kind'] ??= 'none';

        // 3. Wala sa card, per araw at totals — Meta sa loob lang ng period.
        $inPeriod = fn (?string $d) => $period[0] !== null && $d !== null && $d >= $period[0] && $d <= $period[1];
        $missing = array_values(array_filter($meta, fn ($m) => !isset($used[$m['id']]) && $inPeriod($m['date'])));
        // Meta na binibilang sa totals: nasa period, o tumugma kahit 1–2 araw labas (kaya tugma ang summary sa mga araw).
        $counted = fn (array $m) => $inPeriod($m['date']) || isset($used[$m['id']]);

        $days = [];
        foreach ($charges as $l) $days[$l['sale_date']]['card'] = ($days[$l['sale_date']]['card'] ?? 0) + self::cents($l['amount']);
        foreach ($meta as $m) if ($counted($m)) $days[$m['date']]['meta'] = ($days[$m['date']]['meta'] ?? 0) + self::cents($m['amount']);
        ksort($days);
        $days = array_map(fn ($d) => [
            'card' => self::money($d['card'] ?? 0), 'meta' => self::money($d['meta'] ?? 0), 'diff' => self::money(($d['card'] ?? 0) - ($d['meta'] ?? 0)),
        ], $days);

        $sum = fn (array $ls) => array_sum(array_map(fn ($l) => self::cents($l['amount']), $ls));
        $matched = array_filter($charges, fn ($l) => in_array($out[$l['id']]['match_kind'], ['sale_date', 'post_date', 'near'], true)
            || ($out[$l['id']]['match_kind'] === 'group' && $groups[$out[$l['id']]['group_key']]['tallied']));
        $noMeta = array_merge(array_filter($charges, fn ($l) => $out[$l['id']]['match_kind'] === 'none'), $undated);
        $cardTotal = $sum($charges) + $sum($undated);
        $metaTotal = array_sum(array_map(fn ($m) => self::cents($m['amount']), array_filter($meta, $counted)));

        return [
            'period'       => $period,
            'lines'        => $out,
            'groups'       => $groups,
            'meta_missing' => $missing,
            'days'         => $days,
            'totals'       => [
                'ads_count'        => count($charges) + count($undated),
                'card_total'       => self::money($cardTotal),
                'meta_total'       => self::money($metaTotal),
                'matched_count'    => count($matched),
                'matched_total'    => self::money($sum($matched)),
                'no_meta_count'    => count($noMeta),
                'no_meta_total'    => self::money($sum($noMeta)),
                'no_card_count'    => count($missing),
                'no_card_total'    => self::money(array_sum(array_map(fn ($m) => self::cents($m['amount']), $missing))),
                'difference_total' => self::money($cardTotal - $metaTotal),
                'groups_tallied'   => count(array_filter($groups, fn ($g) => $g['tallied'])),
                'groups_untallied' => count(array_filter($groups, fn ($g) => !$g['tallied'])),
                'groups_untallied_card_total' => self::money(array_sum(array_map(fn ($g) => $g['tallied'] ? 0 : self::cents($g['card_total']), $groups))),
                'groups_untallied_meta_total' => self::money(array_sum(array_map(fn ($g) => $g['tallied'] ? 0 : self::cents($g['meta_total']), $groups))),
            ],
        ];
    }

    /**
     * Mungkahing card: ang payment method na may pinakamaraming ads charge na may kaparehong amount sa loob ng ±2 araw.
     * @param array<string, list<array>> $metaByCard
     * @return array{label: ?string, scores: array<string, int>}
     */
    public static function suggest(array $lines, array $metaByCard): array
    {
        $scores = [];
        foreach ($metaByCard as $label => $meta) {
            $hits = 0;
            foreach ($lines as $l) {
                if (!$l['is_ads'] || $l['is_credit'] || $l['sale_date'] === null) continue;
                foreach ($meta as $m) {
                    if (self::cents($m['amount']) === self::cents($l['amount']) && abs(self::days($l['sale_date'], $m['date'])) <= self::NEAR_DAYS) { $hits++; break; }
                }
            }
            $scores[(string) $label] = $hits;
        }
        uksort($scores, fn ($a, $b) => [$scores[$b], $a] <=> [$scores[$a], $b]);
        $best = array_key_first($scores);

        return ['label' => $best !== null && $scores[$best] > 0 ? $best : null, 'scores' => $scores];
    }

    /** [unang sale date, huling sale date] ng lahat ng may petsang linya. */
    public static function period(array $lines): array
    {
        $dates = array_values(array_filter(array_column($lines, 'sale_date')));
        if ($dates === []) return [null, null];
        sort($dates);

        return [$dates[0], end($dates)];
    }

    private static function key(string $date, string $amount): string
    {
        return $date . '|' . self::money(self::cents($amount));
    }

    private static function cents(string|int|float $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private static function days(string $from, string $to): int
    {
        return intdiv(strtotime($to . ' 00:00:00 UTC') - strtotime($from . ' 00:00:00 UTC'), 86400);
    }
}
