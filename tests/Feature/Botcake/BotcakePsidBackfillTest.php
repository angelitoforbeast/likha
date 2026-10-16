<?php

namespace Tests\Feature\Botcake;

use App\Models\BotcakePsid;

class BotcakePsidBackfillTest extends BotcakePsidTestCase
{
    private function oldSheet(): void
    {
        $this->sheetRows([
            ['Page One', 'May Order', 'PSID-1', 'd1', '', '', '', '', '', 'not existing'],
            ['Page One', 'Wala Pa', 'PSID-2', 'd2', '', '', '', '', '', 'not existing'],
            ['Page One', 'Na Import', 'PSID-3', 'd3', '', '', '', '', '', 'imported'],
            ['Page One', '', 'PSID-4', 'd4', '', '', '', '', '', 'not existing'], // kulang: hindi itatala
        ]);
    }

    public function test_dry_run_writes_nothing_and_prints_the_counts(): void
    {
        $order = $this->order('Page One', 'May Order');
        $this->oldSheet();
        $before = $this->currentSheet();

        $this->artisan('botcake:psid-backfill')
            ->expectsOutputToContain('Setting #' . $this->setting->id . ' (Test PSID sheet / PSID): 2 na "not existing" row ang itatala bilang waiting')
            ->expectsOutputToContain('Dry run: walang isinulat')
            ->assertSuccessful();

        $this->assertSame(0, BotcakePsid::count());
        $this->assertSame($before, $this->currentSheet());
        $this->assertNull($order->fresh()->botcake_psid);
    }

    public function test_apply_records_waiting_marks_the_sheet_and_matches_once(): void
    {
        $order = $this->order('Page One', 'May Order');
        $this->oldSheet();

        $this->artisan('botcake:psid-backfill', ['--apply' => true])
            ->expectsOutputToContain('Matched: 1')
            ->assertSuccessful();

        $this->assertSame(['PSID-1' => 'matched', 'PSID-2' => 'waiting', 'PSID-3' => 'matched'], BotcakePsid::orderBy('psid')->pluck('status', 'psid')->all());
        $this->assertSame('PSID-1', $order->fresh()->botcake_psid);
        $this->assertSame(['waiting', 'waiting', 'imported', 'not existing'], array_map(fn ($n) => $this->statusJ($n), [2, 3, 4, 5]));
    }

    public function test_old_imported_rows_are_backfilled_as_matched_without_writing_macro_output_and_deleted_at_0300(): void
    {
        $this->order('Page One', 'Luma Order', 'PSID-L');
        $this->order('Page One', 'Luma Order', 'PSID-L');
        $empty = $this->order('Page One', 'Luma Order', null, now()->subDays(60)->toDateString());
        \App\Models\BotcakePsid::create([
            'setting_id' => $this->setting->id, 'page' => 'Page One', 'fb_name' => 'Iba Psid',
            'psid' => 'PSID-OLD', 'sheet_date' => 'x', 'status' => 'matched', 'first_seen_at' => now(),
        ]);
        $this->sheetRows([
            ['Page One', 'Luma Order', 'PSID-L', 'd1', '', '', '', '', '', 'imported'],
            ['Page One', 'Iba Psid', 'PSID-NEW', 'd2', '', '', '', '', '', 'imported'],
            ['Page One', 'Bago Lang', 'PSID-3', '', '', '', '', '', '', ''],
        ]);

        $this->artisan('botcake:psid-backfill')
            ->expectsOutputToContain('2 na "imported" row ang itatala bilang matched')
            ->assertSuccessful();
        $this->assertSame(1, BotcakePsid::count());

        $this->artisan('botcake:psid-backfill', ['--apply' => true])->assertSuccessful();

        $rec = BotcakePsid::where('psid', 'PSID-L')->sole();
        $this->assertSame('matched', $rec->status);
        $this->assertSame(2, $rec->matched_rows); // dalawang row ang may hawak na nito
        $this->assertSame('d1', $rec->sheet_date);
        $this->assertNull($empty->fresh()->botcake_psid); // walang sinulat sa macro_output
        $this->assertSame('conflict', BotcakePsid::where('psid', 'PSID-NEW')->sole()->status);
        $this->assertSame(['imported', 'imported', ''], array_map(fn ($n) => $this->statusJ($n), [2, 3, 4]));

        $run = \App\Models\BotcakePsidImportRun::create(['status' => 'running']);
        (new \App\Jobs\ImportBotcakePsidFromGoogleSheet(null, $run->id, true))->handle();

        $this->assertSame([[2, 3]], $this->sheet->deletedRanges);
        $this->assertSame(['PSID', 'PSID-3'], array_values(array_map(fn ($r) => $r[2], $this->currentSheet())));
    }
}
