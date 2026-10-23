<?php

namespace App\Console\Commands;

use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Services\MacroChecker;
use App\Services\OrderEncode\Runner;
use App\Services\OrderEncode\Settings;
use App\Services\OrderEncode\Text;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Backtest ng encoder: ang mga naka-save na input ng naunang test (mga row na may --source-mode) ay ine-encode ulit sa kasalukuyang
 * rules papunta sa bagong mode (backtest-<oras>), saka ipinapakita, para sa Likha at (kung may tmp_sheet_out) sa output ng sheet:
 * orders, PROCEED, correct, wrong, pending, blank address, lahat PAGKATAPOS ng MacroChecker::validateRow (gaya ng Validate button).
 * May tunay na tawag sa GPT kapag may key ang makina: patakbuhin lang sa server.
 *
 * Ang input ay binabasa bilang row ng import (A..H), kaya walang tawag sa Google Sheets. Hindi ginagalaw ang macro_output.
 */
class OrderEncodeBacktest extends Command
{
    protected $signature = 'order-encode:backtest {--source-mode=backtest2 : the mode of the stored inputs} {--limit= : encode only the first N orders}';
    protected $description = 'Re-encode the stored inputs of an earlier test under the current rules and compare with the final orders (after the Validate rules)';

    public function handle(Runner $runner, Settings $settings): int
    {
        if (!Schema::hasTable('order_encodes')) {
            $this->warn('order_encodes does not exist yet (run the migration).');

            return self::SUCCESS;
        }
        $source = trim((string) $this->option('source-mode'));
        $limit = $this->option('limit') !== null && $this->option('limit') !== '' ? max(1, (int) $this->option('limit')) : null;
        $from = OrderEncode::where('mode', $source)->orderBy('id')->when($limit, fn ($q) => $q->limit($limit))->get();
        if ($from->isEmpty()) {
            $this->warn("No stored inputs with mode \"{$source}\".");

            return self::SUCCESS;
        }

        $mode = 'backtest-' . now('Asia/Manila')->format('YmdHis');
        $this->info("Re-encoding {$from->count()} orders from \"{$source}\" into \"{$mode}\" (rules: " . $settings->rules() . ').');
        $new = [];
        foreach ($from as $o) {
            // tab = ang bagong mode: ang unique key (sheet, tab, row) ay hindi nagbabanggaan sa pinagmulan.
            $copy = OrderEncode::create([
                'page' => $o->page, 'spreadsheet_id' => $o->spreadsheet_id, 'tab' => $mode, 'row_number' => $o->row_number, 'mode' => $mode, 'status' => 'queued',
                'macro_output_id' => $o->macro_output_id, 'input' => ['row' => (array) ($o->input['row'] ?? []), 'source' => 'import', 'backtest_of' => $o->id],
            ]);
            try {
                $runner->run($copy);
            } catch (Throwable $e) {
                $runner->fail($copy, $e->getMessage());
            }
            $new[] = $copy->id;
        }

        $this->report($mode, $new);

        return self::SUCCESS;
    }

    /** @param array<int, int> $ids */
    private function report(string $mode, array $ids): void
    {
        $maps = MacroChecker::loadAddressMaps();
        $checker = new MacroChecker();
        $macro = MacroOutput::whereIn('id', OrderEncode::whereIn('id', $ids)->pluck('macro_output_id')->filter()->all())->get()->keyBy('id');
        $sheet = $this->sheetOutput();
        $likha = $this->counter();
        $theirs = $this->counter();
        $failed = 0;

        foreach (OrderEncode::whereIn('id', $ids)->orderBy('id')->cursor() as $o) {
            $final = $macro[$o->macro_output_id] ?? null;
            if ($o->status !== 'done') {
                $failed++;
                continue;
            }
            $out = (array) $o->outputs;
            $this->count($likha, $checker, $maps, $final, [
                'PROVINCE' => (string) ($out['province'] ?? ''), 'CITY' => (string) ($out['city'] ?? ''), 'BARANGAY' => (string) ($out['barangay'] ?? ''),
                'FULL NAME' => (string) ($out['name'] ?? ''), 'PHONE NUMBER' => self::tenDigits((string) ($out['phone'] ?? '')), 'ADDRESS' => (string) ($out['address_line'] ?? ''),
            ], (bool) ($out['proceed'] ?? false), (string) ($out['item'] ?? ''), (string) ($out['cod'] ?? ''));

            if ($sheet !== null && ($s = $sheet[$o->macro_output_id] ?? null) !== null) {
                $this->count($theirs, $checker, $maps, $final, [
                    'PROVINCE' => $s['province'], 'CITY' => $s['city'], 'BARANGAY' => $s['barangay'], 'FULL NAME' => $s['name'] ?? (string) ($final?->{'FULL NAME'} ?? ''),
                    'PHONE NUMBER' => self::tenDigits($s['phone'] ?? (string) ($final?->{'PHONE NUMBER'} ?? '')), 'ADDRESS' => $s['address'],
                ], in_array($s['final'], ['✅', 'PROCEED'], true), $s['item'] ?? (string) ($final?->ITEM_NAME ?? ''), $s['cod'] ?? (string) ($final?->COD ?? ''));
            }
        }

        $rows = [];
        foreach (['orders' => 'Orders', 'proceed' => 'PROCEED', 'correct' => 'Correct', 'wrong' => 'Wrong', 'pending' => 'Pending', 'blank' => 'Blank address'] as $k => $label) {
            $rows[] = [$label, $likha[$k], $sheet === null ? '-' : $theirs[$k]];
        }
        $this->line('');
        $this->line("Mode: {$mode} (after the Validate rules; correct = final STATUS PROCEED and the same location, N = Ñ)" . ($failed ? "; {$failed} orders failed to encode and are not counted" : ''));
        $this->table(['', 'Likha', 'Sheet (tmp_sheet_out)'], $rows);
        if ($sheet === null) $this->line('tmp_sheet_out not found or without the usable columns: the sheet column is skipped.');
    }

    /** @return array{orders: int, proceed: int, correct: int, wrong: int, pending: int, blank: int} */
    private function counter(): array
    {
        return ['orders' => 0, 'proceed' => 0, 'correct' => 0, 'wrong' => 0, 'pending' => 0, 'blank' => 0];
    }

    /**
     * Isang order sa isang column ng ulat. PROCEED = ang encode ay ✅ at ang Validate (hard at soft) ay walang tutol.
     * @param array<string, int> $c
     * @param array<string, string> $f
     */
    private function count(array &$c, MacroChecker $checker, array $maps, ?MacroOutput $final, array $f, bool $encoded, string $item, string $cod): void
    {
        $c['orders']++;
        if (trim($f['ADDRESS']) === '') {
            $c['blank']++;

            return;
        }
        $row = $final ? clone $final : new MacroOutput();
        $row->ITEM_NAME = $item;
        $row->COD = $cod;
        $gate = $checker->validateRow($row, $f, $maps, true);
        if (!$encoded || $gate['hard'] || $gate['soft']) {
            $c['pending']++;

            return;
        }
        $c['proceed']++;
        $same = $final && self::sameLocation($f, $final);
        if ($same && strtoupper(trim((string) $final->STATUS)) === 'PROCEED') $c['correct']++;
        else $c['wrong']++;
    }

    private static function sameLocation(array $f, MacroOutput $final): bool
    {
        foreach (['PROVINCE' => fn ($v) => Text::normProv($v), 'CITY' => fn ($v) => Text::normPlace($v), 'BARANGAY' => fn ($v) => Text::normPlace($v)] as $col => $norm) {
            if ($norm((string) $f[$col]) !== $norm((string) $final->{$col})) return false;
        }

        return true;
    }

    /**
     * Ang output ng sheet sa tmp_sheet_out (kung nariyan): macro_output_id (o order_id) at ang mga column province, city, barangay, address,
     * final (✅ = PROCEED); opsyonal: name, phone, item, cod. Hindi sinusulat. null kung wala ang table o kulang ang mga column.
     * @return array<int, array<string, string>>|null
     */
    private function sheetOutput(): ?array
    {
        if (!Schema::hasTable('tmp_sheet_out')) return null;
        $cols = array_map('strtolower', Schema::getColumnListing('tmp_sheet_out'));
        $pick = fn (array $names) => collect($names)->first(fn ($n) => in_array($n, $cols, true));
        $map = ['id' => $pick(['macro_output_id', 'order_id']), 'province' => $pick(['province']), 'city' => $pick(['city']), 'barangay' => $pick(['barangay']),
            'address' => $pick(['address', 'address_line']), 'final' => $pick(['final', 'checker', 'app_script_checker', 'status'])];
        if (in_array(null, $map, true)) return null;
        $optional = ['name' => $pick(['name', 'full_name']), 'phone' => $pick(['phone', 'phone_number']), 'item' => $pick(['item', 'item_name']), 'cod' => $pick(['cod'])];

        $out = [];
        foreach (DB::table('tmp_sheet_out')->get() as $r) {
            $r = array_change_key_case((array) $r, CASE_LOWER);
            $row = ['province' => (string) $r[$map['province']], 'city' => (string) $r[$map['city']], 'barangay' => (string) $r[$map['barangay']],
                'address' => (string) $r[$map['address']], 'final' => trim((string) $r[$map['final']])];
            foreach ($optional as $k => $col) if ($col !== null) $row[$k] = (string) $r[$col];
            $out[(int) $r[$map['id']]] = $row;
        }

        return $out;
    }

    private static function tenDigits(string $phone): string
    {
        $d = preg_replace('/\D/', '', $phone) ?? '';

        return strlen($d) >= 10 ? substr($d, -10) : $d;
    }
}
