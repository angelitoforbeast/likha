<?php

namespace App\Services\CardStatement;

/**
 * Ang resulta ng CardMatcher bilang side-by-side: bawat araw (sale date ng card; Meta lang = petsa ng Meta),
 * bawat row = card line(s) | marka | Meta record(s). Ang umuulit na amount sa isang araw ay isang row
 * ("3 x 100.00 = 300.00" sa bawat panig). Credit at hindi-ads na linya ay wala rito (hindi sila itinutugma).
 * Ang total ng araw ay ang suma ng mga row nito, kaya laging tugma sa nakikita.
 * Status ng row: matched, card_only (wala sa Meta), meta_only (wala sa card), group_off (grupo, hindi tally).
 */
class SideBySide
{
    public const STATUSES = ['matched', 'card_only', 'meta_only', 'group_off'];

    public static function days(array $result, array $lines, array $metaById): array
    {
        $rows = [];    // [day, order, row]
        $groups = [];
        foreach ($lines as $l) {
            if (!$l['is_ads'] || $l['is_credit']) continue;
            $r = $result['lines'][$l['id']] ?? null;
            if ($r === null) continue;
            $day = $l['sale_date'];

            if ($r['match_kind'] === 'group') {
                $groups[$r['group_key']][] = $l;
            } elseif (in_array($r['match_kind'], ['sale_date', 'post_date', 'near'], true) && isset($metaById[$r['payment_activity_id']])) {
                $rows[] = [$day, [0, $l['line_no']], self::row('matched', [$l], [$metaById[$r['payment_activity_id']]]) + ['match_kind' => $r['match_kind'], 'match_days' => $r['match_days']]];
            } else {
                $rows[] = [$day, [0, $l['line_no']], self::row('card_only', [$l], [])];
            }
        }

        foreach ($groups as $key => $ls) {
            $g = $result['groups'][$key];
            $meta = array_values(array_filter(array_map(fn ($id) => $metaById[$id] ?? null, $g['meta_ids'])));
            $text = fn (int $n, string $total) => "{$n} x {$g['amount']} = {$total}";
            $row = self::row($g['tallied'] ? 'matched' : 'group_off', $ls, $meta);
            $row['group'] = [
                'text'      => $text($g['card_count'], $g['card_total']),
                'card_text' => $text($g['card_count'], $g['card_total']),
                'meta_text' => $text($g['meta_count'], $g['meta_total']),
                'more'      => $g['tallied'] ? null : ($g['card_count'] > $g['meta_count'] ? 'card' : 'meta'),
                'shift'     => $g['shift'], // may kinuha sa ±1 araw
            ];
            $rows[] = [$g['date'], [0, min(array_column($ls, 'line_no'))], $row];
        }

        // Meta lang: ang umuulit na amount sa isang araw ay isang row din ("3 x 7.00 = 21.00").
        $missing = [];
        foreach ($result['meta_missing'] as $m) $missing[$m['date'] . '|' . self::money($m['amount'])][] = $m;
        foreach ($missing as $ms) {
            $row = self::row('meta_only', [], $ms);
            if (count($ms) > 1) {
                $text = count($ms) . ' x ' . self::money($ms[0]['amount']) . ' = ' . self::fromCents($row['meta_cents']);
                $row['group'] = ['text' => $text, 'card_text' => null, 'meta_text' => $text, 'more' => 'meta'];
            }
            $rows[] = [$ms[0]['date'], [1, $ms[0]['id']], $row];
        }

        $days = [];
        foreach ($rows as [$day, $order, $row]) $days[$day ?? ''][] = [$order, $row];
        uksort($days, fn ($a, $b) => [$a === '', $a] <=> [$b === '', $b]); // walang petsa sa dulo

        $out = [];
        foreach ($days as $day => $dayRows) {
            usort($dayRows, fn ($a, $b) => $a[0] <=> $b[0]);
            $dayRows = array_column($dayRows, 1);
            $card = array_sum(array_column($dayRows, 'card_cents'));
            $meta = array_sum(array_column($dayRows, 'meta_cents'));
            $out[] = [
                'date' => $day === '' ? null : $day, 'rows' => $dayRows,
                'card_total' => self::fromCents($card), 'meta_total' => self::fromCents($meta), 'diff' => self::fromCents($card - $meta),
            ];
        }

        return $out;
    }

    /**
     * Mga row ng isang status lang ('' = lahat), at isang page ng buong mga araw: nagdadagdag ng araw hanggang
     * umabot sa $maxRows, kaya hindi nahahati ang isang araw at nananatiling magaan ang page kahit 1,000+ linya.
     */
    public static function page(array $days, string $status, int $page, int $maxRows = 150): array
    {
        $chunks = [];
        $current = [];
        $count = 0;
        foreach ($days as $d) {
            if ($status !== '') $d['rows'] = array_values(array_filter($d['rows'], fn ($r) => $r['status'] === $status));
            if ($d['rows'] === []) continue;
            $current[] = $d;
            $count += count($d['rows']);
            if ($count >= $maxRows) { $chunks[] = $current; $current = []; $count = 0; }
        }
        if ($current !== []) $chunks[] = $current;

        return ['days' => $chunks[$page - 1] ?? [], 'pages' => max(1, count($chunks)), 'page' => $page];
    }

    private static function row(string $status, array $card, array $meta): array
    {
        return [
            'status' => $status, 'card' => $card, 'meta' => $meta, 'group' => null, 'match_kind' => null, 'match_days' => null,
            'card_cents' => array_sum(array_map(fn ($l) => self::cents($l['amount']), $card)),
            'meta_cents' => array_sum(array_map(fn ($m) => self::cents($m['amount']), $meta)),
        ];
    }

    private static function cents(string|int|float $v): int
    {
        return (int) round(((float) $v) * 100);
    }

    private static function money(string|int|float $v): string
    {
        return self::fromCents(self::cents($v));
    }

    private static function fromCents(int $c): string
    {
        return number_format($c / 100, 2, '.', '');
    }
}
