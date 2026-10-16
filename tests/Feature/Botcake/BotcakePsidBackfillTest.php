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

        $this->assertSame(['PSID-1' => 'matched', 'PSID-2' => 'waiting'], BotcakePsid::orderBy('psid')->pluck('status', 'psid')->all());
        $this->assertSame('PSID-1', $order->fresh()->botcake_psid);
        $this->assertSame(['waiting', 'waiting', 'imported', 'not existing'], array_map(fn ($n) => $this->statusJ($n), [2, 3, 4, 5]));
    }
}
