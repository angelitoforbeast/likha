<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Models\MacroOutput;
use App\Models\PageSenderMapping;

class JntCheckerController extends Controller
{
    public function index()
    {
        return view('jnt.checker', [
            'results'              => session('results'),
            'matchedCount'         => session('matchedCount'),
            'notMatchedCount'      => session('notMatchedCount'),
            'notInExcelCount'      => session('notInExcelCount'),
            'notInExcelRows'       => session('notInExcelRows'),
            'filter_date_start'    => session('filter_date_start'),
            'filter_date_end'      => session('filter_date_end'),
            'updatableCount'       => session('updatableCount'),

            // optional (safe kahit di mo pa ginagamit sa Blade)
            'mappingMissingCount'  => session('mappingMissingCount'),
            'perfectMatch'         => session('perfectMatch'),
        ]);
    }

    public function upload(Request $request)
    {
        Log::info('📥 Entered upload() function');

        $request->validate([
            'excel_file' => 'required|mimes:xlsx,xls',
        ]);

        $path = $request->file('excel_file')->getRealPath();

        try {
            $spreadsheet = IOFactory::load($path);
            $sheet = $spreadsheet->getActiveSheet();
            $data = $sheet->toArray(null, true, true, true);
            Log::info('📊 Successfully loaded Excel and converted to array.');
        } catch (\Throwable $e) {
            Log::error('❌ Excel load error: ' . $e->getMessage());
            return back()->with('error', 'Failed to read the Excel file. Make sure it is a valid XLSX/XLS.');
        }

        $data = array_values($data); // reindex to 0-based
        if (empty($data) || !isset($data[0])) {
            Log::info('⛔ Header row missing or unreadable.');
            return back()->with('error', 'Excel file has no header row.');
        }

        Log::info('✅ Header Row:', $data[0]);

        // =========================
        // Step 1: Build header map
        // =========================
        $headers = $data[0];
        $headerMap = [];
        foreach ($headers as $col => $value) {
            $normalized = strtolower(trim(preg_replace('/\s+/', ' ', (string)($value ?? ''))));
            if ($normalized !== '') {
                $headerMap[$normalized] = $col;
            }
        }
        Log::info('✅ Detected Headers:', array_keys($headerMap));

        // =========================
        // Step 2: Resolve required columns (with aliases)
        // =========================
        $senderCol   = $this->resolveHeader($headerMap, [
            'sender name', 'sender', 'shipper', 'sendername',
        ]);

        $receiverCol = $this->resolveHeader($headerMap, [
            'receiver cellphone', 'receiver cell phone', 'receiver phone', 'receiver mobile',
            'consignee phone', 'consignee cellphone', 'phone number', 'contact number', 'receiver number',
        ]);

        $itemCol     = $this->resolveHeader($headerMap, [
            'item name', 'item', 'product', 'product name', 'itemname',
        ]);

        $codCol      = $this->resolveHeader($headerMap, [
            'cod', 'cod amt', 'cod amount', 'cod value', 'cod amount (php)', 'cod amt (php)',
        ]);

        $missing = [];
        if (!$senderCol)   $missing[] = 'Sender Name';
        if (!$receiverCol) $missing[] = 'Receiver Cellphone';
        if (!$itemCol)     $missing[] = 'Item Name';
        if (!$codCol)      $missing[] = 'COD';

        if (count($missing)) {
            return back()->with('error', 'Missing column(s): ' . implode(', ', $missing));
        }

        // Waybill/Tracking optional detection (common variants)
        $waybillCol = $this->resolveHeader($headerMap, [
            'waybill', 'waybill no', 'waybill number',
            'tracking no', 'tracking number', 'tracking',
            'airwaybill', 'awb', 'awb no', 'awb number',
        ]);

        // =========================
        // Step 3: Preload Sender -> Page mapping (NO N+1)
        // =========================
        $senderToPage = PageSenderMapping::select('SENDER_NAME', 'PAGE')->get()
            ->mapWithKeys(function ($m) {
                $sender = strtolower($this->normText($m->SENDER_NAME));
                $page   = $this->normText($m->PAGE);
                return [$sender => $page];
            })
            ->all();

        // =========================
        // Step 4: Parse Excel rows
        // =========================
        $rows = array_slice($data, 1);
        $results = [];

        foreach ($rows as $row) {
            $sender   = $this->normText($row[$senderCol] ?? '');
            $receiver = $this->normPhone($row[$receiverCol] ?? '');
            $item     = $this->normText($row[$itemCol] ?? '');
            $cod      = $this->normCod($row[$codCol] ?? '0');
            $waybill  = $waybillCol ? $this->normText($row[$waybillCol] ?? '') : '';

            $mappedPage = $senderToPage[strtolower($sender)] ?? '';

            // Strict: key ONLY if may mapped page. Pag wala, treat as mapping-missing.
            $key = $mappedPage
                ? $this->makeKey($mappedPage, $receiver, $item, $cod)
                : null;

            $results[] = [
                'sender'      => $sender,
                'page'        => $mappedPage ?: '❌ Not Found in Mapping',
                'receiver'    => $receiver,
                'item'        => $item,
                'cod'         => $cod,      // string like "299.00"
                'waybill'     => $waybill,
                'matched'     => false,
                'matched_id'  => null,      // IMPORTANT for 1-to-1
                'key'         => $key,      // temp; will unset later
            ];
        }

        // =========================
        // Step 5: Filter MacroOutput by date + status
        // =========================
        $start = $request->input('filter_date_start');
        $end   = $request->input('filter_date_end');

        $query = MacroOutput::select('id', 'PAGE', 'PHONE NUMBER', 'ITEM_NAME', 'COD', 'TIMESTAMP', 'FULL NAME')
            ->where('STATUS', 'PROCEED');

        $driver = DB::getDriverName();

        if ($start && $end) {
            try {
                if ($driver === 'pgsql') {
                    $query->whereRaw(
                        "to_date(substring(\"TIMESTAMP\" from '.{10}$'), 'DD-MM-YYYY') BETWEEN ? AND ?",
                        [$start, $end]
                    );
                } else {
                    $query->whereBetween(
                        DB::raw("STR_TO_DATE(RIGHT(`TIMESTAMP`, 10), '%d-%m-%Y')"),
                        [date('Y-m-d', strtotime($start)), date('Y-m-d', strtotime($end))]
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('⚠️ Invalid date range.', ['err' => $e->getMessage()]);
            }
        } elseif ($start) {
            try {
                if ($driver === 'pgsql') {
                    $query->whereRaw(
                        "to_date(substring(\"TIMESTAMP\" from '.{10}$'), 'DD-MM-YYYY') = ?",
                        [$start]
                    );
                } else {
                    $query->where(
                        DB::raw("STR_TO_DATE(RIGHT(`TIMESTAMP`, 10), '%d-%m-%Y')"),
                        '=',
                        date('Y-m-d', strtotime($start))
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('⚠️ Invalid single date.', ['err' => $e->getMessage()]);
            }
        }

        Log::info('🗓️ Date Filter Start:', ['start' => $start]);
        Log::info('🗓️ Date Filter End:', ['end' => $end]);
        Log::info('📦 MacroOutput count after date + status filter:', ['count' => $query->count()]);

        $macroOutputRecords = $query->get();

        // =========================
        // Step 6: Build Macro multiset (key -> list of ids)
        // =========================
        $macroKeyToIds = [];
        foreach ($macroOutputRecords as $mo) {
            $k = $this->makeKey(
                $mo->PAGE,
                $mo->{'PHONE NUMBER'},
                $mo->ITEM_NAME,
                $mo->COD
            );
            $macroKeyToIds[$k][] = (int) $mo->id;
        }

        // Cursor per key = how many ids already assigned
        $keyCursor = [];

        // =========================
        // Step 7: STRICT 1-to-1 match (count-aware allocation)
        // =========================
        $mappingMissingCount = 0;

        foreach ($results as &$res) {
            if (!$res['key']) {
                $mappingMissingCount++;
                $res['matched'] = false;
                $res['matched_id'] = null;
                continue;
            }

            $k = $res['key'];

            if (!isset($macroKeyToIds[$k])) {
                // No such key in Macro at all
                $res['matched'] = false;
                $res['matched_id'] = null;
                continue;
            }

            $i = $keyCursor[$k] ?? 0;

            if (!isset($macroKeyToIds[$k][$i])) {
                // Macro has this key but duplicates are exhausted (Excel duplicates > Macro duplicates)
                $res['matched'] = false;
                $res['matched_id'] = null;
                continue;
            }

            // Assign exactly ONE id for this Excel row
            $res['matched'] = true;
            $res['matched_id'] = $macroKeyToIds[$k][$i];
            $keyCursor[$k] = $i + 1;
        }
        unset($res);

        // =========================
        // Step 8: Not in Excel (EXTRA in Macro) = leftover ids after allocation
        // =========================
        $extraMacroIds = [];
        foreach ($macroKeyToIds as $k => $ids) {
            $used = $keyCursor[$k] ?? 0;
            $count = count($ids);

            if ($used < $count) {
                // push remaining ids without array_slice (less memory)
                for ($i = $used; $i < $count; $i++) {
                    $extraMacroIds[] = $ids[$i];
                }
            }
        }

        $notInExcelRows = $macroOutputRecords->filter(function ($row) use ($extraMacroIds) {
            // Using in_array on potentially large arrays is slower;
            // but extraMacroIds is usually much smaller than macroOutputRecords.
            return in_array((int)$row->id, $extraMacroIds, true);
        })->values();

        $notInExcelCount = $notInExcelRows->count();

        // =========================
        // Step 9: Summary counts + sorting
        // =========================
        $matchedCount    = collect($results)->where('matched', true)->count();
        $notMatchedCount = collect($results)->where('matched', false)->count();

        // Sort unmatched first
        $results = collect($results)->sortBy('matched')->values()->all();

        // Remove temp key from results before session
        foreach ($results as &$r) unset($r['key']);
        unset($r);

        // =========================
        // Step 10: Updatable rows (STRICT: only assigned matched_id)
        // matched + has waybill + has mapped page
        // =========================
        $updatable = collect($results)
            ->filter(fn($r) =>
                !empty($r['matched']) &&
                !empty($r['matched_id']) &&
                !empty($r['waybill']) &&
                !empty($r['page']) &&
                $r['page'] !== '❌ Not Found in Mapping'
            )
            ->map(fn($r) => [
                'id' => (int)$r['matched_id'],
                'waybill' => $r['waybill'],
            ])
            ->values()
            ->all();

        session(['jnt_updatable' => $updatable]);

        $uniqueUpdatableCount = count(array_unique(array_column($updatable, 'id')));

        // Perfect match definition: no missing in macro, no extra in macro, no missing mapping
        $perfectMatch = ($notMatchedCount === 0) && ($notInExcelCount === 0) && ($mappingMissingCount === 0);

        return redirect()
            ->route('jnt.checker')
            ->with([
                'results'              => $results,
                'matchedCount'         => $matchedCount,
                'notMatchedCount'      => $notMatchedCount,
                'notInExcelCount'      => $notInExcelCount,
                'notInExcelRows'       => $notInExcelRows,
                'filter_date_start'    => $start,
                'filter_date_end'      => $end,
                'updatableCount'       => $uniqueUpdatableCount,

                // optional diagnostics
                'mappingMissingCount'  => $mappingMissingCount,
                'perfectMatch'         => $perfectMatch,
            ]);
    }

    public function update(Request $request)
    {
        $updatable = session('jnt_updatable', []);

        if (empty($updatable)) {
            return back()->with('error', 'No matched rows with WAYBILL found from the last upload.');
        }

        // Group MacroOutput IDs by WAYBILL para isang update per waybill
        $groups = [];
        foreach ($updatable as $u) {
            if (!isset($u['id'], $u['waybill'])) {
                continue;
            }
            $groups[$u['waybill']][] = (int) $u['id'];
        }

        $updated = 0;

        foreach ($groups as $waybill => $ids) {
            $ids = array_values(array_unique($ids));

            foreach (array_chunk($ids, 200) as $chunk) {
                $affected = MacroOutput::whereIn('id', $chunk)
                    ->update(['waybill' => $waybill]);

                $updated += $affected;
            }
        }

        return back()->with('success', "WAYBILL updated for {$updated} row(s).");
    }

    // ============================================================
    // Helpers
    // ============================================================
    private function resolveHeader(array $headerMap, array $aliases): ?string
    {
        foreach ($aliases as $a) {
            $k = strtolower(trim(preg_replace('/\s+/', ' ', $a)));
            if (isset($headerMap[$k])) return $headerMap[$k];
        }
        return null;
    }

    private function normText($v): string
    {
        $v = (string) $v;
        $v = str_replace('Ã±', 'ñ', $v);
        $v = trim(preg_replace('/\s+/', ' ', $v));
        return $v;
    }

    private function normPhone($v): string
    {
        $p = preg_replace('/\D+/', '', (string) $v);

        // 63xxxxxxxxxx -> 0xxxxxxxxxx
        if (str_starts_with($p, '63') && strlen($p) === 12) $p = '0' . substr($p, 2);
        // 9xxxxxxxxx -> 09xxxxxxxxx
        if (strlen($p) === 10 && str_starts_with($p, '9')) $p = '0' . $p;

        return $p;
    }

    private function normCod($v): string
    {
        $raw = preg_replace('/[^0-9.]/', '', (string) $v);
        if ($raw === '' || $raw === '.') return '0.00';
        return number_format((float) $raw, 2, '.', '');
    }

    private function makeKey($page, $phone, $item, $cod): string
    {
        return strtolower($this->normText($page)) . '|' .
            $this->normPhone($phone) . '|' .
            strtolower($this->normText($item)) . '|' .
            $this->normCod($cod);
    }
}
