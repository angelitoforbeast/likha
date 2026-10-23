<?php

namespace Tests\Feature\OrderEncode;

use App\Models\AppSetting;
use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Services\OrderEncode\Settings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Spec 135: order-encode:backtest ine-encode ulit ang naka-save na input sa kasalukuyang rules at inihahambing sa final na order, pagkatapos ng Validate. */
class BacktestTest extends EncodeRunTestCase
{
    /** @return array<string, array{0: string, 1: string}> label => [Likha, Sheet] */
    private function table(string $output): array
    {
        $rows = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\|\s*(Orders|PROCEED|Correct|Wrong|Pending|Blank address)\s*\|\s*(\S+)\s*\|\s*(\S+)\s*\|/', $line, $m)) $rows[$m[1]] = [$m[2], $m[3]];
        }

        return $rows;
    }

    /** Isang naka-save na input ng naunang test, at ang final na order nito sa macro_output. */
    private function stored(string $cxd, string $name, array $final = [], string $mode = 'backtest2'): MacroOutput
    {
        $m = MacroOutput::create($final + ['TIMESTAMP' => '10:15 23-10-2026', 'PAGE' => self::PAGE, 'fb_name' => $name, 'FULL NAME' => $name, 'PHONE NUMBER' => '9' . random_int(100000000, 999999999),
            'PROVINCE' => 'METRO-MANILA', 'CITY' => 'QUEZON-CITY', 'BARANGAY' => 'BUNGAD', 'ADDRESS' => 'Blk 5 Lot 3', 'ITEM_NAME' => '2 x GLOW SERUM', 'COD' => '998',
            'SHOP DETAILS' => "ITEM: GLOW SERUM\nQUANTITY: 2\nPRICE: ₱998", 'STATUS' => 'PROCEED', 'APP SCRIPT CHECKER' => '✅']);
        OrderEncode::create(['page' => self::PAGE, 'spreadsheet_id' => self::ORDERS, 'tab' => 'All Orders', 'row_number' => 100 + $m->id, 'mode' => $mode, 'status' => 'done',
            'macro_output_id' => $m->id, 'input' => ['source' => 'import', 'row' => $this->withCxd($cxd, [2 => $name])]]);

        return $m;
    }

    private function seeded(): array
    {
        $this->writeList(self::bungadRows());
        AppSetting::set(Settings::RULES, 'improved');
        $this->fakeGpt(['name_addr' => ['full_name' => '', 'address_line1' => '']]);

        $a = $this->stored($this->cxd('Metro Manila', 'Quezon City', 'Bungad', 'Ana Cruz', '09171230001'), 'Ana Cruz', ['PHONE NUMBER' => '9171230001']);                  // tama
        $b = $this->stored($this->cxd('Metro Manila', 'Quezon City', 'Bungad', 'Bea Lim', '09171230002'), 'Bea Lim', ['PHONE NUMBER' => '9171230002', 'BARANGAY' => 'PROJECT 7']); // mali: Project 7 ang final
        $c = $this->stored('', 'Cai Ong', ['PHONE NUMBER' => '9171230003', 'ADDRESS' => '']);                                                                                // walang address
        $d = $this->stored($this->cxd('Metro Manila', 'Quezon City', 'Bungad', 'Dan Lee', 'walang numero'), 'Dan Lee', ['PHONE NUMBER' => '9171230004']);                      // walang phone

        return compact('a', 'b', 'c', 'd');
    }

    private function sheetOutput(array $o): void
    {
        Schema::create('tmp_sheet_out', function (Blueprint $t) {
            $t->unsignedBigInteger('macro_output_id');
            foreach (['province', 'city', 'barangay', 'address', 'final'] as $c) $t->string($c)->nullable();
        });
        foreach ([[$o['a'], 'BUNGAD', 'Blk 5', '✅'], [$o['b'], 'PROJECT 7', 'Blk 5', '✅'], [$o['c'], 'BUNGAD', '', '❌'], [$o['d'], 'BUNGAD', 'Blk 5', '✅']] as [$m, $brgy, $addr, $final]) {
            DB::table('tmp_sheet_out')->insert(['macro_output_id' => $m->id, 'province' => 'METRO-MANILA', 'city' => 'QUEZON-CITY', 'barangay' => $brgy, 'address' => $addr, 'final' => $final]);
        }
    }

    public function test_S_135_50_it_re_encodes_the_stored_inputs_into_a_new_mode_and_reports_likha_and_the_sheet_after_validate(): void
    {
        $o = $this->seeded();
        $this->sheetOutput($o);

        $this->assertSame(0, Artisan::call('order-encode:backtest', ['--source-mode' => 'backtest2']));

        $this->assertSame([
            'Orders' => ['4', '4'], 'PROCEED' => ['2', '3'], 'Correct' => ['1', '3'], 'Wrong' => ['1', '0'], 'Pending' => ['1', '0'], 'Blank address' => ['1', '1'],
        ], $this->table(Artisan::output()));

        $copies = OrderEncode::where('mode', 'like', 'backtest-%')->get();
        $this->assertCount(4, $copies);
        $this->assertSame(4, $copies->where('status', 'done')->count());
        $this->assertSame(4, OrderEncode::where('mode', 'backtest2')->count()); // ang pinagmulan ay hindi ginagalaw
        $this->assertSame('BUNGAD', $copies->firstWhere('macro_output_id', $o['b']->id)->outputs['barangay']);
        $this->assertSame('PROJECT 7', $o['b']->fresh()->BARANGAY); // hindi sinusulat ang macro_output
    }

    public function test_S_135_51_without_tmp_sheet_out_the_sheet_column_is_skipped_and_limit_cuts_the_run(): void
    {
        $this->seeded();

        Artisan::call('order-encode:backtest', ['--source-mode' => 'backtest2', '--limit' => 2]);
        $out = Artisan::output();

        $rows = $this->table($out);
        $this->assertSame(['2', '-'], $rows['Orders']);
        $this->assertSame(['1', '-'], $rows['Correct']);
        $this->assertStringContainsString('tmp_sheet_out not found', $out);
        $this->assertSame(2, OrderEncode::where('mode', 'like', 'backtest-%')->count());
    }

    public function test_S_135_52_it_says_so_when_there_are_no_stored_inputs_and_never_reads_google(): void
    {
        $this->artisan('order-encode:backtest', ['--source-mode' => 'nothing-here'])->expectsOutputToContain('No stored inputs')->assertSuccessful();

        $this->seeded();
        Artisan::call('order-encode:backtest', ['--source-mode' => 'backtest2']);
        $this->assertSame([], $this->google->calls); // ang fake na sheet ay walang natawag
    }
}
