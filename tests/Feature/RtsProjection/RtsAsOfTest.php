<?php

namespace Tests\Feature\RtsProjection;

use App\Services\RtsProjection;
use Carbon\Carbon;

/**
 * Projection "as of" isang nakaraang araw: ang state ng bawat order at ang p(edad) ay galing lang sa status logs na
 * alam na noong araw na iyon (walang silip sa hinaharap). Ngayon = 2026-10-23 12:00 Manila.
 */
class RtsAsOfTest extends RtsProjectionTestCase
{
    private function learningOrders(): void
    {
        // Natutunan: 40 na-deliver noong Set 5 (alam na noong Okt 15), 40 na-return lang noong Okt 20 (hindi pa alam noong Okt 15).
        $this->orders(40, 'Page L', 'Item L', '2026-09-01 10:00:00', 'Delivered',
            [['In Transit', '2026-09-01 12:00:00'], ['Delivered', '2026-09-05 12:00:00']]);
        $this->orders(40, 'Page L', 'Item L', '2026-09-01 10:00:00', 'Returned',
            [['In Transit', '2026-09-01 12:00:00'], ['Returned', '2026-10-20 12:00:00']]);
    }

    public function test_S_197_P1_5_as_of_a_past_date_uses_only_statuses_and_rates_known_then(): void
    {
        $this->learningOrders();
        // Ang page+item na tinatantya: submitted Okt 12, 10 na-deliver Okt 18, 10 na-return Okt 14.
        $this->orders(10, 'Page X', 'Item Y', '2026-10-12 10:00:00', 'Delivered',
            [['In Transit', '2026-10-12 12:00:00'], ['Delivered', '2026-10-18 12:00:00']]);
        $this->orders(10, 'Page X', 'Item Y', '2026-10-12 10:00:00', 'Returned',
            [['In Transit', '2026-10-12 12:00:00'], ['Returned', '2026-10-14 12:00:00']]);
        // Order pagkatapos ng araw na iyon: wala pa noon.
        $this->orders(5, 'Page X', 'Item Y', '2026-10-17 10:00:00', 'Delivered',
            [['In Transit', '2026-10-17 12:00:00'], ['Delivered', '2026-10-19 12:00:00']]);
        // Ang naka-save na rate (ngayon) ay hindi dapat gamitin ng as-of.
        $this->rates(array_fill(0, 15, 0.9));

        $asOf = Carbon::parse('2026-10-15 23:59:59', 'Asia/Manila');
        $rows = (new RtsProjection())->rows('2026-10-01', '2026-10-23', $asOf);
        $r = $rows['page x||'.\App\Services\ItemAliasResolver::normalize('Item Y')];

        $this->assertSame([20, 0, 10, 10], [$r['matched'], $r['delivered'], $r['returned'], $r['in_transit']]);
        // p(edad 3) noong Okt 15 = 0 (ang mga na-deliver lang ang tapos noon) → (10 + 10×0) ÷ 20.
        $this->assertEqualsWithDelta(50.0, $r['projected_rts'], 0.001);

        // Ngayon (walang as-of): ang kasalukuyang status at lahat ng 25 order.
        $now = (new RtsProjection())->rows('2026-10-01', '2026-10-23')['page x||'.\App\Services\ItemAliasResolver::normalize('Item Y')];
        $this->assertSame([25, 15, 10, 0], [$now['matched'], $now['delivered'], $now['returned'], $now['in_transit']]);
    }

    public function test_S_197_P1_5_rate_table_as_of_learns_only_from_orders_final_by_then(): void
    {
        $this->learningOrders();
        $p = new RtsProjection();

        $then = $p->learnTable(Carbon::parse('2026-10-15 23:59:59', 'Asia/Manila'), true);
        $this->assertSame(40, $then['pooled'][0]['n']);
        $this->assertEqualsWithDelta(0.0, $then['pooled'][0]['p'], 0.0001);

        $now = $p->learn();
        $this->assertSame(80, $now['pooled'][0]['n']);
        $this->assertEqualsWithDelta(0.5, $now['pooled'][0]['p'], 0.0001);
    }
}
