<?php

namespace Tests\Feature\Jnt;

use Tests\TestCase;

/**
 * Ang heading ng /jnt/hold. View render lang ito: MySQL-only ang SQL ng JntHoldController
 * (STR_TO_DATE), kaya hindi ito tumatakbo sa sqlite ng tests.
 */
class JntHoldHeadingTest extends TestCase
{
    public function test_S_025_1_the_heading_reads_jnt_hold_item(): void
    {
        $view = $this->view('jnt.hold', [
            'byItem' => collect(), 'holdsCount' => 0, 'q' => '', 'includeBlank' => false,
            'uiDate' => null, 'rangeSta' => '2026-10-01', 'rangeEnd' => '2026-10-15',
            'dateRange' => '2026-10-01 to 2026-10-15', 'group' => 'item_name',
            'itemsWithHoldsCount' => 0, 'perDate' => true, 'pivotRows' => collect(), 'dateKeys' => [],
            'colTotals' => [], 'grandTotal' => 0, 'monthGroups' => [], 'dayLabels' => [],
            'lookbackDays' => 3, 'asOfDateStr' => '2026-10-15', 'recentMap' => [], 'recentGrand' => 0,
        ]);

        $view->assertSee('JNT Hold Item');
        $view->assertDontSee('JNT Hold Items');
    }
}
