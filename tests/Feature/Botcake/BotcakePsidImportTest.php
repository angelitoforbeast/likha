<?php

namespace Tests\Feature\Botcake;

use App\Http\Controllers\BotcakePsidGsheetController;
use App\Jobs\ImportBotcakePsidFromGoogleSheet;
use App\Models\BotcakePsid;
use App\Models\BotcakePsidImportRun;
use Illuminate\Support\Facades\Cache;

class BotcakePsidImportTest extends BotcakePsidTestCase
{
    private function runImport(bool $cleanSheet = false): BotcakePsidImportRun
    {
        $run = BotcakePsidImportRun::create(['status' => 'running', 'last_message' => 'Queued...']);
        (new ImportBotcakePsidFromGoogleSheet(null, $run->id, $cleanSheet))->handle();
        return $run->fresh();
    }

    public function test_psid_with_existing_order_is_written_recorded_matched_and_marked_imported(): void
    {
        $order = $this->order('Page One', 'Juan Test');
        $this->sheetRows([['Page One', 'juan test', 'PSID-A', '10:00 15-10-2026']]);

        $run = $this->runImport();

        $this->assertSame('PSID-A', $order->fresh()->botcake_psid); // case-insensitive na match, gaya dati
        $rec = BotcakePsid::sole();
        $this->assertSame('matched', $rec->status);
        $this->assertSame(1, $rec->matched_rows);
        $this->assertSame((int) $this->setting->id, (int) $rec->setting_id);
        $this->assertSame('10:00 15-10-2026', $rec->sheet_date);
        $this->assertNotNull($rec->matched_at);
        $this->assertSame('imported', $this->statusJ(2));
        $this->assertSame(1, $run->total_imported);
        $this->assertSame('done', $run->status);
    }

    public function test_psid_without_order_waits_and_macro_output_is_untouched(): void
    {
        $other = $this->order('Page One', 'Iba Na Tao');
        $this->sheetRows([['Page One', 'Juan Test', 'PSID-A', '10:00 15-10-2026']]);

        $run = $this->runImport();

        $this->assertNull($other->fresh()->botcake_psid);
        $this->assertSame('waiting', BotcakePsid::sole()->status);
        $this->assertSame('waiting', $this->statusJ(2));
        $this->assertSame(1, $run->total_waiting);
    }

    public function test_waiting_psid_is_attached_when_the_order_arrives_on_a_run_without_new_rows(): void
    {
        $this->sheetRows([['Page One', 'Juan Test', 'PSID-A', '']]);
        $this->runImport();

        $order = $this->order('Page One', 'Juan Test');
        $run = $this->runImport(); // "waiting" na ang J: walang bagong sheet row

        $this->assertSame('PSID-A', $order->fresh()->botcake_psid);
        $rec = BotcakePsid::sole();
        $this->assertSame('matched', $rec->status);
        $this->assertSame(1, $rec->matched_rows);
        $this->assertSame(1, $run->total_matched_from_waiting);
    }

    public function test_waiting_only_fills_empty_rows_of_the_order(): void
    {
        $this->sheetRows([['Page One', 'Juan Test', 'PSID-A', '']]);
        $this->runImport();

        $empty  = $this->order('Page One', 'Juan Test');
        $filled = $this->order('Page One', 'Juan Test', 'PSID-A');
        $filled->forceFill(['updated_at' => now()->subDay()])->save();
        $before = $filled->fresh()->updated_at;

        $this->runImport();

        $this->assertSame('PSID-A', $empty->fresh()->botcake_psid);
        $this->assertSame(1, BotcakePsid::sole()->matched_rows); // ang may laman na ay hindi sinulatan
        $this->assertEquals($before, $filled->fresh()->updated_at);
    }

    public function test_waiting_for_more_than_30_days_expires_and_is_no_longer_tried(): void
    {
        $this->sheetRows([['Page One', 'Juan Test', 'PSID-A', '']]);
        $this->runImport();

        $this->travel(31)->days();
        $run = $this->runImport();
        $this->assertSame('expired', BotcakePsid::sole()->status);
        $this->assertSame(1, $run->total_expired);

        $order = $this->order('Page One', 'Juan Test');
        $this->runImport();
        $this->assertSame('expired', BotcakePsid::sole()->status);
        $this->assertNull($order->fresh()->botcake_psid);
    }

    public function test_two_psids_for_one_customer_in_the_sheet_are_a_conflict_and_nothing_is_written(): void
    {
        $this->sheetRows([
            ['Page One', 'Juan Test', 'PSID-A', ''],
            ['Page One', 'JUAN TEST', 'PSID-B', ''],
        ]);
        $this->runImport();

        $order = $this->order('Page One', 'Juan Test');
        $run = $this->runImport();

        $this->assertNull($order->fresh()->botcake_psid);
        $this->assertSame(['conflict', 'conflict'], BotcakePsid::orderBy('id')->pluck('status')->all());
        $this->assertSame('waiting', $this->statusJ(2)); // naisulat na noong unang run
        $this->assertSame('conflict', $this->statusJ(3));
        $this->assertSame(0, $run->total_matched_from_waiting);
    }

    public function test_incoming_psid_that_differs_from_the_one_on_the_customers_orders_is_a_conflict(): void
    {
        $old = $this->order('Page One', 'Juan Test', 'PSID-A');
        $new = $this->order('Page One', 'Juan Test');
        $this->sheetRows([['Page One', 'Juan Test', 'PSID-B', '']]);

        $run = $this->runImport();

        $this->assertSame('PSID-A', $old->fresh()->botcake_psid);
        $this->assertNull($new->fresh()->botcake_psid); // walang reorder copy kapag may conflict
        $this->assertSame('conflict', BotcakePsid::sole()->status);
        $this->assertSame('conflict', $this->statusJ(2));
        $this->assertSame(1, $run->total_conflicts);
        $this->assertSame(0, $run->total_reorder_copies);
    }

    public function test_reorder_gets_the_customers_single_known_psid_within_30_days(): void
    {
        $this->order('Page One', 'Juan Test', 'PSID-A', now()->subDays(90)->toDateString());
        $reorder  = $this->order('Page One', 'Juan Test');
        $oldEmpty = $this->order('Page One', 'Juan Test', null, now()->subDays(45)->toDateString());
        $this->order('Page One', 'Dalawa Psid', 'PSID-X');
        $this->order('Page One', 'Dalawa Psid', 'PSID-Y');
        $twoPsids = $this->order('Page One', 'Dalawa Psid');
        $this->sheetRows([]);

        $run = $this->runImport();

        $this->assertSame('PSID-A', $reorder->fresh()->botcake_psid);
        $this->assertNull($oldEmpty->fresh()->botcake_psid);
        $this->assertNull($twoPsids->fresh()->botcake_psid);
        $this->assertSame(1, $run->total_reorder_copies);
    }

    public function test_daily_clean_deletes_only_recorded_rows_with_matching_content_bottom_to_top_then_imports(): void
    {
        foreach (['PSID-1' => 'matched', 'PSID-4' => 'waiting', 'PSID-5' => 'matched', 'PSID-6' => 'conflict', 'PSID-7' => 'waiting', 'PSID-8' => 'expired'] as $psid => $status) {
            BotcakePsid::create([
                'setting_id' => $this->setting->id, 'page' => 'Page One', 'fb_name' => 'Tao ' . $psid,
                'psid' => $psid, 'sheet_date' => 'D' . $psid, 'status' => $status, 'first_seen_at' => now(),
            ]);
        }
        $this->sheetRows([
            ['Page One', 'Tao PSID-1', 'PSID-1', 'DPSID-1', '', '', '', '', '', 'imported'],     // row 2: buburahin
            ['Page One', 'Bago Lang', 'PSID-3', '', '', '', '', '', '', ''],                      // row 3: blank J, maiiwan
            ['Page One', 'Tao PSID-4', 'PSID-4', 'DPSID-4', '', '', '', '', '', 'waiting'],      // row 4: buburahin
            ['Page One', 'Tao PSID-5', 'PSID-5', 'IBANG PETSA', '', '', '', '', '', 'imported'], // row 5: iba ang laman, maiiwan
            ['Page One', 'Tao PSID-6', 'PSID-6', 'DPSID-6', '', '', '', '', '', 'conflict'],     // row 6: buburahin
            ['Page One', 'Tao PSID-7', 'PSID-7', 'DPSID-7', '', '', '', '', '', 'waiting'],      // row 7: buburahin
            ['Page One', 'Tao PSID-8', 'PSID-8', 'DPSID-8', '', '', '', '', '', 'expired'],      // row 8: dobleng expired, buburahin
        ]);

        $run = $this->runImport(cleanSheet: true);

        $this->assertSame([[6, 8], [4, 4], [2, 2]], $this->sheet->deletedRanges);
        $this->assertSame(1, $this->sheet->deleteCalls);
        $sheet = $this->currentSheet();
        $this->assertSame(['PSID', 'PSID-3', 'PSID-5'], array_values(array_map(fn ($r) => $r[2], $sheet)));
        $this->assertSame('waiting', $sheet[2][9]); // tumakbo ang import pagkatapos ng pagbura
        $this->assertSame(5, $run->total_deleted_rows);
    }

    public function test_second_import_while_one_is_running_is_skipped(): void
    {
        $this->sheetRows([['Page One', 'Juan Test', 'PSID-A', '']]);
        $lock = Cache::lock(ImportBotcakePsidFromGoogleSheet::LOCK_KEY, 60);
        $this->assertTrue($lock->get());

        $run = $this->runImport();

        $this->assertSame(0, BotcakePsid::count());
        $this->assertSame('', $this->statusJ(2));
        $this->assertSame('done', $run->status);
        $lock->release();
    }

    public function test_status_endpoint_returns_the_new_counts(): void
    {
        $counts = [
            'total_waiting' => 3, 'total_matched_from_waiting' => 2, 'total_expired' => 1,
            'total_conflicts' => 4, 'total_reorder_copies' => 5, 'total_deleted_rows' => 6,
        ];
        $run = BotcakePsidImportRun::create(['status' => 'done'] + $counts);

        $data = (new BotcakePsidGsheetController())->status($run->id)->getData(true);

        foreach ($counts as $key => $value) {
            $this->assertSame($value, $data[$key] ?? null, $key);
        }
    }
}
