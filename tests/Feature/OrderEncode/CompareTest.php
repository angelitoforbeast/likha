<?php

namespace Tests\Feature\OrderEncode;

use App\Models\OrderEncode;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;

/** order-encode:compare: ang resulta ng Likha laban sa DATABASE row ng sheet (mismong row number) at sa MIRRORED O/P. */
class CompareTest extends OrderEncodeTestCase
{
    private const ROW = 23823;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 12:00:00');
        $this->configurePage('shadow');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function done(int $row = self::ROW, int $minutesOld = 20, array $outputs = []): OrderEncode
    {
        $o = OrderEncode::create([
            'page' => self::PAGE, 'psid' => 7001, 'spreadsheet_id' => self::ORDERS, 'tab' => 'All Orders', 'row_number' => $row, 'mode' => 'shadow', 'status' => 'done',
            'input' => ['row' => [], 'fields' => ['date' => '10:15 23-10-2026', 'page' => 'Page Uno']],
            'outputs' => $outputs + ['name' => 'Ana Cruz', 'phone' => '09171234567', 'address_line' => 'Blk 5 Lot 3', 'province' => 'CEBU', 'city' => 'CEBU-CITY',
                'barangay' => 'LAHUG', 'item' => '2 x GLOW SERUM', 'cod' => '998', 'r' => '✅', 's' => '✅', 'w' => '', 'final' => '✅', 'proceed' => true],
        ]);
        $o->forceFill(['created_at' => now()->subMinutes($minutesOld)])->save();

        return $o;
    }

    /** Ang DATABASE row A..W gaya ng nababasa ng Sheets API (ang phone ay nawawalan ng 0, ang presyo ay numero). */
    private function sheetRow(array $over = [], int $row = self::ROW, array $mirror = ['✅', 'PROCEED']): void
    {
        $cells = $over + ['A' => '10:15 23-10-2026', 'B' => 'Ana Cruz', 'C' => '9171234567', 'D' => 'Blk 5 Lot 3', 'E' => 'CEBU', 'F' => 'CEBU-CITY', 'G' => 'LAHUG',
            'H' => '2 x GLOW SERUM', 'I' => '998', 'J' => 'Page Uno', 'R' => '✅', 'S' => '✅'];
        $values = [];
        foreach (range('A', 'W') as $c) $values[] = $cells[$c] ?? '';
        $this->google->put(self::AFTER, "DATABASE!A{$row}:W{$row}", [$values]);
        $this->google->put(self::AFTER, "'DATABASE - MIRRORED'!O{$row}:P{$row}", [$mirror]);
    }

    private function compare(): int
    {
        return Artisan::call('order-encode:compare');
    }

    public function test_S_130_19_an_order_that_matches_the_sheet_is_equal_in_every_field(): void
    {
        $o = $this->done();
        $this->sheetRow();

        $this->assertSame(0, $this->compare());

        $o->refresh();
        $this->assertSame(['done', true], [$o->compare_state, $o->compare_equal]);
        $this->assertSame(['same'], array_values(array_unique($o->compare)));
        $this->assertCount(13, $o->compare);
        $this->assertSame('✅', $o->sheet['database'][17]);
        $this->assertSame(['✅', 'PROCEED'], $o->sheet['mirrored']);
    }

    public static function differences(): array
    {
        return [
            'province differs' => [['E' => 'ABRA'], [], 'province', 'different'],
            'name case does not matter' => [['B' => 'ANA CRUZ'], [], 'name', 'same'],
            'phone leading zero does not matter' => [['C' => '09171234567'], [], 'phone', 'same'],
            'price as number or text' => [['I' => '998.00'], [], 'cod', 'same'],
            'sheet blank' => [['D' => ''], [], 'address_line', 'sheet_blank'],
            'likha blank' => [[], ['barangay' => ''], 'barangay', 'likha_blank'],
            'both blank' => [['H' => ''], ['item' => ''], 'item', 'same'],
            'w differs' => [['W' => 'City'], ['w' => 'Barangay'], 'w', 'different'],
            'w only in the sheet' => [['W' => 'City'], [], 'w', 'likha_blank'],
        ];
    }

    #[DataProvider('differences')]
    public function test_S_130_20_each_field_is_compared_after_trim_with_its_own_rule(array $sheet, array $ours, string $field, string $expected): void
    {
        $o = $this->done(outputs: $ours);
        $this->sheetRow($sheet);

        $this->compare();

        $o->refresh();
        $this->assertSame($expected, $o->compare[$field]);
        $this->assertSame($expected === 'same', $o->compare_equal);
    }

    public function test_S_130_21_it_waits_while_the_checker_has_not_reached_the_row_and_compares_later(): void
    {
        $o = $this->done();
        $this->sheetRow(['S' => '']);
        $this->compare();
        $this->assertSame(['waiting', null], [$o->fresh()->compare_state, $o->fresh()->compare]);

        $this->sheetRow();
        $this->compare();
        $this->assertSame('done', $o->fresh()->compare_state);
    }

    public function test_S_130_22_a_row_the_encoder_has_not_written_yet_also_waits(): void
    {
        $o = $this->done();

        $this->compare();

        $this->assertSame('waiting', $o->fresh()->compare_state);
    }

    public function test_S_130_23_another_order_on_that_row_is_a_row_mismatch(): void
    {
        $a = $this->done();
        $b = $this->done(23824);
        $this->sheetRow(['J' => 'Page Dos']);
        $this->sheetRow(['A' => '09:00 22-10-2026'], 23824);

        $this->compare();

        $this->assertSame(['row_mismatch', 'row_mismatch'], [$a->fresh()->compare_state, $b->fresh()->compare_state]);
        $this->assertNull($a->fresh()->compare_equal);
    }

    public function test_S_130_24_the_timestamp_matches_in_other_formats(): void
    {
        $o = $this->done();
        $this->sheetRow(['A' => '2026-10-23 10:15:00']);

        $this->compare();

        $this->assertSame('done', $o->fresh()->compare_state);
    }

    public function test_S_130_25_after_48_hours_a_row_the_sheet_never_finished_is_sheet_not_done(): void
    {
        $old = $this->done(23800, 49 * 60);
        $done = $this->done(23801, 49 * 60);
        $done->update(['compare_state' => 'done', 'compare_equal' => true]);

        $this->compare();

        $this->assertSame('sheet_not_done', $old->fresh()->compare_state);
        $this->assertSame('done', $done->fresh()->compare_state);
        $this->assertSame([], $this->google->calls); // walang binasa para sa kanila
    }

    public function test_S_130_26_a_young_row_a_failed_encode_and_other_modes_are_left_alone(): void
    {
        $young = $this->done(23810, 10);
        $failed = $this->done(23811);
        $failed->update(['status' => 'failed']);
        $live = $this->done(23812);
        $live->update(['mode' => 'live', 'row_number' => null]);

        $this->compare();

        $this->assertSame([null, null, null], [$young->fresh()->compare_state, $failed->fresh()->compare_state, $live->fresh()->compare_state]);
        $this->assertSame([], $this->google->calls);
    }

    public function test_S_130_27_the_rows_of_one_sheet_are_read_in_one_batch(): void
    {
        $this->done(23820);
        $this->done(23821);
        $this->sheetRow([], 23820);
        $this->sheetRow([], 23821);

        $this->compare();

        $this->assertSame(['batch 4'], $this->google->calls);
        $this->assertSame(2, OrderEncode::where('compare_state', 'done')->count());
    }

    public function test_S_130_28_when_google_refuses_nothing_changes_and_the_command_says_so(): void
    {
        $o = $this->done();
        $this->google->fail = true;

        $this->assertSame(1, $this->compare());

        $this->assertNull($o->fresh()->compare_state);
    }
}
