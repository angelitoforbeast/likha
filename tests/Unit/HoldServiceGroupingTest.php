<?php

namespace Tests\Unit;

use App\Models\ItemSupplierQuote;
use App\Services\HoldService;
use PHPUnit\Framework\TestCase;

class HoldServiceGroupingTest extends TestCase
{
    public function test_groups_variants_into_base_item_units(): void
    {
        $rows = [
            (object) ['item_name' => '1 x Hand Grip', 'hold_count' => 3],
            (object) ['item_name' => '2 x HAND GRIP', 'hold_count' => 4],   // 4 orders × 2 = 8 units
            (object) ['item_name' => 'Yoga Mat',      'hold_count' => 1],
            (object) ['item_name' => '   ',           'hold_count' => 9],   // walang pangalan → skip
        ];

        $map = (new HoldService())->groupUnitsByBaseItem($rows);

        $this->assertSame([
            'hand grip' => ['name' => 'Hand Grip', 'units' => 11, 'variants' => ['1 x Hand Grip' => 3, '2 x HAND GRIP' => 8]],
            'yoga mat'  => ['name' => 'Yoga Mat',  'units' => 1,  'variants' => ['Yoga Mat' => 1]],
        ], $map);
    }

    public function test_group_key_matches_the_quote_and_po_key(): void
    {
        $svc = new HoldService();
        foreach (['1 x Hand Grip', '2× hand  grip', 'HAND GRIP', '10 X  Yoga   Mat'] as $raw) {
            $key = array_key_first($svc->groupUnitsByBaseItem([(object) ['item_name' => $raw, 'hold_count' => 1]]));
            $this->assertSame(ItemSupplierQuote::keyFor($raw), $key, $raw);
        }
    }
}
