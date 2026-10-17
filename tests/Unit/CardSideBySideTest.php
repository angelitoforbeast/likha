<?php

namespace Tests\Unit;

use App\Services\CardStatement\CardMatcher;
use App\Services\CardStatement\SideBySide;
use PHPUnit\Framework\TestCase;

/** Side-by-side na view: card | marka | Meta, bawat araw (sale date), may total bawat araw, filter at page ng mga araw. */
class CardSideBySideTest extends TestCase
{
    private int $lineId = 0;
    private int $metaId = 0;

    private function line(?string $sale, string $amount, bool $ads = true, bool $credit = false): array
    {
        $id = ++$this->lineId;

        return ['id' => $id, 'line_no' => $id, 'sale_date' => $sale, 'post_date' => $sale, 'description' => 'FACEBK *CODE' . $id, 'amount' => $amount, 'is_credit' => $credit, 'is_ads' => $ads];
    }

    private function meta(string $date, string $amount): array
    {
        $id = ++$this->metaId;

        return ['id' => $id, 'date' => $date, 'amount' => $amount, 'transaction_id' => 'txn-' . $id, 'ad_account' => 'act-' . $id];
    }

    private function build(array $lines, array $meta): array
    {
        return SideBySide::days(CardMatcher::match($lines, $meta), $lines, array_column($meta, null, 'id'));
    }

    /** Bawat row: [status, card line ids, Meta ids, group text o null]. */
    private function shape(array $days): array
    {
        return array_map(fn ($d) => [
            'date' => $d['date'],
            'rows' => array_map(fn ($r) => [$r['status'], array_column($r['card'], 'id'), array_column($r['meta'], 'id'), $r['group']['text'] ?? null], $d['rows']),
            'totals' => [$d['card_total'], $d['meta_total'], $d['diff']],
        ], $days);
    }

    public function test_S_049_17_rows_pair_card_and_meta_by_day_with_groups_one_sided_rows_and_day_totals(): void
    {
        $lines = [
            $this->line('2026-09-10', '500.00'),                                         // 1: tugma, parehong araw
            $this->line('2026-09-09', '100.00'), $this->line('2026-09-09', '100.00'), $this->line('2026-09-09', '100.00'), // 2-4: grupo
            $this->line('2026-09-09', '6278.17'),                                        // 5: tugma, Meta isang araw pagkatapos
            $this->line('2026-09-10', '42.00'),                                          // 6: card lang
            $this->line('2026-09-10', '9.00', false),                                    // 7: hindi ads → wala sa side-by-side
            $this->line('2026-09-10', '15.00', true, true),                              // 8: credit → wala rin
            $this->line(null, '30.00'),                                                  // 9: ads na walang petsa
        ];
        $meta = [
            $this->meta('2026-09-10', '500.00'),
            $this->meta('2026-09-09', '100.00'), $this->meta('2026-09-09', '100.00'), $this->meta('2026-09-09', '100.00'),
            $this->meta('2026-09-10', '6278.17'),
            $this->meta('2026-09-10', '77.00'), // Meta lang
        ];

        $this->assertSame([
            ['date' => '2026-09-09', 'rows' => [
                ['matched', [2, 3, 4], [2, 3, 4], '3 x 100.00 = 300.00'],
                ['matched', [5], [5], null],
            ], 'totals' => ['6578.17', '6578.17', '0.00']],
            ['date' => '2026-09-10', 'rows' => [
                ['matched', [1], [1], null],
                ['card_only', [6], [], null],
                ['meta_only', [], [6], null],
            ], 'totals' => ['542.00', '577.00', '-35.00']],
            ['date' => null, 'rows' => [
                ['card_only', [9], [], null],
            ], 'totals' => ['30.00', '0.00', '30.00']],
        ], $this->shape($this->build($lines, $meta)));
    }

    public function test_S_049_17_an_untallied_group_shows_both_counts_and_which_side_has_more(): void
    {
        $lines = [$this->line('2026-09-09', '100.00'), $this->line('2026-09-09', '100.00')];
        $meta  = [$this->meta('2026-09-09', '100.00'), $this->meta('2026-09-09', '100.00'), $this->meta('2026-09-09', '100.00')];

        $row = $this->build($lines, $meta)[0]['rows'][0];

        $this->assertSame(['group_off', '2 x 100.00 = 200.00', '3 x 100.00 = 300.00', 'meta'], [
            $row['status'], $row['group']['card_text'], $row['group']['meta_text'], $row['group']['more'],
        ]);
    }

    public function test_S_049_18_filter_by_status_and_page_by_whole_days(): void
    {
        $lines = [];
        $meta = [];
        // 30 araw × 40 linya = 1,200 linya; bawat araw: 39 tugma + 1 card lang.
        for ($d = 1; $d <= 30; $d++) {
            $date = sprintf('2026-09-%02d', $d);
            for ($i = 1; $i <= 40; $i++) {
                $amount = sprintf('%d.%02d', $d * 1000 + $i, $i);
                $lines[] = $this->line($date, $amount);
                if ($i < 40) $meta[] = $this->meta($date, $amount);
            }
        }
        $days = $this->build($lines, $meta);

        $page = SideBySide::page($days, '', 1, 150);
        $this->assertSame(['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04'], array_column($page['days'], 'date'), 'buong araw lang; titigil kapag ≥150 rows');
        $this->assertSame(8, $page['pages']);
        $this->assertSame(['2026-09-29', '2026-09-30'], array_column(SideBySide::page($days, '', 8, 150)['days'], 'date'));
        $this->assertSame([], SideBySide::page($days, '', 99, 150)['days'], 'lampas na page = wala');

        $only = SideBySide::page($days, 'card_only', 1, 150);
        $this->assertSame([1, 30], [$only['pages'], count($only['days'])], '30 rows lang, isang page');
        $this->assertSame(['card_only'], array_unique(array_merge(...array_map(fn ($d) => array_column($d['rows'], 'status'), $only['days']))));
        $this->assertSame(['40828.20', '39787.80', '1040.40'], [$only['days'][0]['card_total'], $only['days'][0]['meta_total'], $only['days'][0]['diff']], 'naka-filter man, buong araw pa rin ang total');
    }
}
