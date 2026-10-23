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

    private function data(bool $perDate): array
    {
        return [
            'byItem' => collect([(object) ['item_name' => '1 x BRACELET', 'hold_count' => 5]]), 'holdsCount' => 5, 'q' => '',
            'includeBlank' => false, 'uiDate' => null, 'rangeSta' => '2026-10-01', 'rangeEnd' => '2026-10-15',
            'dateRange' => '2026-10-01 to 2026-10-15', 'group' => 'item_name', 'itemsWithHoldsCount' => 1, 'perDate' => $perDate,
            'pivotRows' => collect([['label' => '1 x BRACELET', 'total' => 5, 'dates' => ['2026-10-01' => 5]]]),
            'dateKeys' => ['2026-10-01'], 'colTotals' => ['2026-10-01' => 5], 'grandTotal' => 5,
            'monthGroups' => [['label' => 'October 2026', 'count' => 1]], 'dayLabels' => ['2026-10-01' => '1'],
            'lookbackDays' => 3, 'asOfDateStr' => '2026-10-15', 'recentMap' => [], 'recentGrand' => 0,
        ];
    }

    public function test_the_per_date_table_scrolls_inside_its_own_box_with_fixed_first_columns(): void
    {
        $html = (string) $this->view('jnt.hold', $this->data(true));

        $this->assertMatchesRegularExpression('/<div class="hold-scroll">\s*<table id="holdTable"/', $html);
        $this->assertStringContainsString('class="hold-c1 p-2 border-b">1 x BRACELET', $html);
        $this->assertStringContainsString('class="hold-c1 text-left p-2 border-t">Totals', $html);
        $this->assertStringContainsString('.hold-scroll thead { position: sticky; top: 0;', $html);
    }

    public function test_the_list_without_per_date_keeps_its_columns_together(): void
    {
        $html = (string) $this->view('jnt.hold', $this->data(false));

        $this->assertStringContainsString('<table id="holdTable" class="text-sm">', $html);
        $this->assertStringNotContainsString('<table id="holdTable" class="min-w-full', $html);
        $this->assertStringContainsString('1 x BRACELET', $html);
    }
}
