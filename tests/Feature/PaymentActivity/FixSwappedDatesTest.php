<?php

namespace Tests\Feature\PaymentActivity;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** payments:fix-swapped-dates: hanapin at itama ang baligtad na day/month. Pekeng ids at halaga. */
class FixSwappedDatesTest extends TestCase
{
    private const BACKUP = 'payment_activity_ads_manager_bak_20261018_2130';

    /** [id, stored date, created_at, amount, expected date] */
    private const ROWS = [
        [9001, '2026-01-10', '2026-10-09 10:00:00', 1000.00,   '2026-10-01'], // Oct 1 stored as Jan 10
        [9002, '2026-03-10', '2026-10-09 10:00:00', 340669.22, '2026-10-03'], // Oct 3 stored as Mar 10
        [9003, '2026-04-10', '2026-10-09 10:00:00', 2000.00,   '2026-10-04'], // Oct 4 stored as Apr 10
        [9004, '2028-07-05', '2026-06-05 10:00:00', 300.00,    '2026-05-31'], // rollover
        [9005, '2027-01-02', '2026-02-20 10:00:00', 400.00,    '2026-02-13'], // rollover
        [9006, '2025-12-11', '2025-12-12 10:00:00', 500.00,    '2025-12-11'], // ambiguous, kept
        [9007, '2026-02-03', '2026-03-04 10:00:00', 600.00,    '2026-03-02'], // ambiguous, swapped
        [9008, '2026-10-15', '2026-10-16 10:00:00', 700.00,    '2026-10-15'], // day above 12, kept
        [9009, '2027-04-12', '2026-01-05 10:00:00', 800.00,    '2025-12-28'], // rollover from the year before created_at
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-18 21:30:00');

        Artisan::call('migrate', ['--path' => [
            'database/migrations/2025_10_19_131712_create_payment_activity_ads_manager_table.php',
        ]]);

        foreach (self::ROWS as [$id, $date, $created, $amount]) {
            DB::table('payment_activity_ads_manager')->insert([
                'id' => $id, 'date' => $date, 'transaction_id' => "txn-{$id}", 'amount' => $amount,
                'ad_account' => '1000000000000001', 'payment_method' => 'Visa ···· 0000',
                'source_filename' => 'invoice.csv', 'created_at' => $created, 'updated_at' => '2026-10-10 08:00:00',
            ]);
        }
    }

    private function dates(): array
    {
        return DB::table('payment_activity_ads_manager')->orderBy('id')->pluck('date', 'id')->all();
    }

    public function test_S_063_9_dry_run_lists_the_known_swapped_rows_and_changes_nothing(): void
    {
        $before = $this->dates();

        $this->assertSame(0, Artisan::call('payments:fix-swapped-dates'));
        $out = Artisan::output();
        foreach (['7 row(s)', '345,769.22', '#9002  2026-03-10 → 2026-10-03  340,669.22  card 0000'] as $text) {
            $this->assertStringContainsString($text, $out);
        }

        $this->assertSame($before, $this->dates());
        $this->assertFalse(Schema::hasTable(self::BACKUP));

        $files = Storage::disk('local')->files('payment_date_fixes');
        $this->assertCount(1, $files);
        $csv = array_map('str_getcsv', array_filter(explode("\n", Storage::disk('local')->get($files[0]))));
        $this->assertCount(8, $csv); // header + 7
        $this->assertSame(['id', 'stored', 'corrected', 'amount', 'card_last4', 'created_at', 'source_filename'], $csv[0]);
        $changed = array_column(array_slice($csv, 1), 2, 0);
        $expected = [];
        foreach (self::ROWS as [$id, $date, , , $want]) {
            if ($date !== $want) $expected[(string) $id] = $want;
        }
        $this->assertEquals($expected, $changed);
    }

    public function test_S_063_10_apply_backs_up_the_table_then_changes_only_date(): void
    {
        $before = DB::table('payment_activity_ads_manager')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->artisan('payments:fix-swapped-dates', ['--apply' => true, '--expect' => 7])->assertSuccessful();

        $this->assertTrue(Schema::hasTable(self::BACKUP));
        $this->assertSame(
            array_column($before, 'date', 'id'),
            DB::table(self::BACKUP)->orderBy('id')->pluck('date', 'id')->all()
        );

        $after = DB::table('payment_activity_ads_manager')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        foreach (self::ROWS as $i => [$id, , , , $want]) {
            $this->assertSame($want, $after[$i]['date'], "id {$id}");
            $this->assertSame(array_diff_key($before[$i], ['date' => 1]), array_diff_key($after[$i], ['date' => 1]), "id {$id}");
        }
    }

    public function test_S_063_11_apply_refuses_when_the_count_differs_from_expect(): void
    {
        $before = $this->dates();

        $this->artisan('payments:fix-swapped-dates', ['--apply' => true, '--expect' => 6])
            ->expectsOutputToContain('expected 6')
            ->assertFailed();

        $this->assertSame($before, $this->dates());
        $this->assertFalse(Schema::hasTable(self::BACKUP));
    }
}
