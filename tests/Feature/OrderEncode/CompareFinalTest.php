<?php

namespace Tests\Feature\OrderEncode;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;

/** Order-encode:compare-final, ang paghahambing ng encode ng Likha sa final na order sa macro_output. */
class CompareFinalTest extends FinalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function run15(): void
    {
        Artisan::call('order-encode:compare-final');
    }

    public function test_S_133_10_it_waits_for_the_macro_import_then_compares(): void
    {
        $m = $this->macro(['STATUS' => null, 'APP SCRIPT CHECKER' => null, 'PROVINCE' => null, 'CITY' => null, 'BARANGAY' => null]);
        $o = $this->encoded($m);

        $this->run15();
        $this->assertNull($o->fresh()->final_compare);

        $m->update(['PROVINCE' => 'CEBU']); // isa sa tatlo ay sapat na
        $this->run15();
        $this->assertSame('done', $o->fresh()->final_compare['state']);
    }

    public function test_S_133_11_it_marks_each_field_and_proceed_and_records_edits_and_status(): void
    {
        $same = $this->encoded($this->macro(['PROVINCE' => 'cebu', 'CITY' => ' cebu-city ', 'BARANGAY' => 'Lahuñ', 'ADDRESS' => 'BLK 5 Lot 3 Lahug!']), ['barangay' => 'LAHUN']);
        $diff = $this->encoded($this->macro(['BARANGAY' => 'APAS', 'ITEM_NAME' => '3 x GLOW SERUM', 'COD' => '1200', 'STATUS' => 'HOLD', 'APP SCRIPT CHECKER' => '❌', 'edited_barangay' => true, 'edited_cod' => true]));

        $this->run15();

        $a = $same->fresh()->final_compare;
        $this->assertSame('done', $a['state']);
        $this->assertEquals(array_fill_keys(['province', 'city', 'barangay', 'address_line', 'item', 'cod', 'proceed', 'checker'], 'same'), $a['fields']);
        $this->assertTrue($a['likha_proceed']);
        $this->assertTrue($a['final_proceed']);
        $this->assertFalse($a['edited']);
        $this->assertSame('PROCEED', $a['status']);

        $b = $diff->fresh()->final_compare;
        $this->assertEquals(['barangay' => 'different', 'item' => 'different', 'cod' => 'different', 'proceed' => 'different', 'checker' => 'different', 'province' => 'same'],
            array_intersect_key($b['fields'], array_flip(['barangay', 'item', 'cod', 'proceed', 'checker', 'province'])));
        $this->assertTrue($b['likha_proceed']);
        $this->assertFalse($b['final_proceed']);
        $this->assertTrue($b['edited']);
        $this->assertSame(['edited_barangay', 'edited_cod'], $b['edited_flags']);
        $this->assertSame('HOLD', $b['status']);
        $this->assertSame('APAS', $b['final']['barangay']);
    }

    public function test_S_133_12_a_blank_on_one_side_is_labelled_not_called_equal(): void
    {
        $o = $this->encoded($this->macro(['CITY' => '']), ['barangay' => '']);

        $this->run15();

        $f = $o->fresh()->final_compare['fields'];
        $this->assertSame(['final_blank', 'likha_blank', 'same'], [$f['city'], $f['barangay'], $f['province']]);
    }

    public function test_S_133_13_after_72_hours_without_a_filled_order_it_is_sheet_never_finished(): void
    {
        $blank = ['STATUS' => null, 'APP SCRIPT CHECKER' => null, 'PROVINCE' => null];
        $old = $this->encoded($this->macro($blank));
        $old->forceFill(['created_at' => now()->subHours(73)])->save();
        $young = $this->encoded($this->macro($blank));
        $young->forceFill(['created_at' => now()->subHours(71)])->save();

        $this->run15();

        $this->assertSame('sheet_never_finished', $old->fresh()->final_compare['state']);
        $this->assertNull($young->fresh()->final_compare);
    }

    public function test_S_133_14_only_finished_import_and_linked_flow_shadow_rows_are_compared_and_only_once(): void
    {
        $flowLinked = $this->encoded($this->macro(), more: ['mode' => 'shadow']);
        $flowUnlinked = $this->encoded(null, more: ['mode' => 'shadow']);
        $live = $this->encoded($this->macro(), more: ['mode' => 'live']);
        $queued = $this->encoded($this->macro(), more: ['status' => 'queued']);
        $failed = $this->encoded($this->macro(), more: ['status' => 'failed']);
        $done = $this->encoded($this->macro());

        $this->run15();

        $this->assertNotNull($flowLinked->fresh()->final_compare);
        $this->assertNotNull($done->fresh()->final_compare);
        foreach ([$flowUnlinked, $live, $queued, $failed] as $o) $this->assertNull($o->fresh()->final_compare);

        $first = $done->fresh()->final_compare['at'];
        Carbon::setTestNow('2026-10-23 13:00:00');
        $this->run15();
        $this->assertSame($first, $done->fresh()->final_compare['at']);
    }

    public function test_S_133_15_the_sheet_compare_of_a_flow_shadow_row_is_left_alone(): void
    {
        $o = $this->encoded($this->macro(), more: ['mode' => 'shadow', 'compare_state' => 'waiting', 'compare' => ['name' => 'same']]);

        $this->run15();

        $o = $o->fresh();
        $this->assertSame(['waiting', ['name' => 'same']], [$o->compare_state, $o->compare]);
        $this->assertSame('done', $o->final_compare['state']);
    }
}
