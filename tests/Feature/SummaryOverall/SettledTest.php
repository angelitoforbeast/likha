<?php

namespace Tests\Feature\SummaryOverall;

use Illuminate\Support\Facades\DB;

/**
 * Gaano na ka-final ang bawat numero: Realized (actual Net Profit), Expected (Projected Net Profit),
 * Pending = Expected − Realized, Settled % = (delivered + returned + for return) ÷ proceed,
 * final = walang hold at walang in transit. Mga inaasahan: bilang sa kamay mula sa fixture.
 */
class SettledTest extends SummaryOverallTestCase
{
    private function rows(string $page): array
    {
        [$json] = $this->fetchData(['start_date' => self::START, 'end_date' => self::END, 'page_name' => $page]);

        return $json['ads_daily'];
    }

    public function test_all_pages_rows_and_total_carry_realized_expected_pending_settled_and_final(): void
    {
        $this->seedFixture();
        $rows = array_column($this->rows('all'), null, 'page');

        // [realized, expected, pending, settled %, final]
        $expected = [
            'Page Alpha' => [863.3968, 2431.984, 1568.5872, 5 / 9 * 100, false],   // hold 3
            'Page Beta'  => [804.0, 2553.16, 1749.16, 2 / 4 * 100, false],         // hold 1, in transit 1
            'Page Gamma' => [-150.0, -150.0, 0.0, null, true],                     // ads lang, walang order
            'TOTAL'      => [1517.3968, 4835.144, 3317.7472, 7 / 13 * 100, false],
        ];
        foreach ($expected as $page => [$realized, $exp, $pending, $settled, $final]) {
            $r = $rows[$page];
            $this->assertEqualsWithDelta($realized, $r['realized_net_profit'], 1e-6, "$page realized");
            $this->assertEqualsWithDelta($exp, $r['expected_net_profit'], 1e-6, "$page expected");
            $this->assertEqualsWithDelta($pending, $r['pending_net_profit'], 1e-6, "$page pending");
            $this->assertEqualsWithDelta($settled, $r['settled_pct'], 1e-6, "$page settled");
            $this->assertSame($final, $r['is_final'], "$page final");
        }
    }

    public function test_single_page_days_carry_the_fields(): void
    {
        $this->seedFixture();
        $rows = $this->rows('Page Alpha');

        // Oct 1: proceed 6, delivered 1 + returned 1 + for return 1. Oct 2: proceed 3, delivered 2.
        $this->assertEqualsWithDelta(50.0, $rows[0]['settled_pct'], 1e-6);
        $this->assertEqualsWithDelta(2 / 3 * 100, $rows[1]['settled_pct'], 1e-6);
        foreach ($rows as $r) {
            $this->assertFalse($r['is_final']);
            $this->assertEqualsWithDelta($r['expected_net_profit'] - $r['realized_net_profit'], $r['pending_net_profit'], 1e-6);
        }
        $this->assertEqualsWithDelta($rows[0]['net_profit'], $rows[0]['realized_net_profit'], 1e-6);
        $this->assertEqualsWithDelta($rows[0]['projected_net_profit'], $rows[0]['expected_net_profit'], 1e-6);
    }

    public function test_row_is_final_when_nothing_is_on_hold_or_in_transit(): void
    {
        $this->seedFixture();
        // Page Beta: ang hold ay hindi na proceed, at delivered na ang WB09
        DB::table('macro_output')->where('PAGE', 'Page Beta')->whereNull('waybill')->update(['STATUS' => 'Cannot Proceed']);
        DB::table('from_jnts')->where('waybill_number', 'WB09')->update(['status' => 'Delivered']);

        $beta = array_column($this->rows('all'), null, 'page')['Page Beta'];

        $this->assertTrue($beta['is_final']);
        $this->assertEqualsWithDelta(100.0, $beta['settled_pct'], 1e-6);
    }
}
