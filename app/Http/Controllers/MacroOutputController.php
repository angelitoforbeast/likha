<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MacroOutput;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Models\DownloadedMacroOutputLog;
use Illuminate\Support\Facades\Schema;

class MacroOutputController extends Controller
{

    public function download(Request $request)
    {
        // ✅ Only Marketing - OIC can actually enable "download_all"
        // Normalize role (spaces, case, at uri ng dash: -, –, —)
$userRoleRaw = Auth::user()?->employeeProfile?->role ?? '';
$roleNorm = preg_replace('/\s+/u', ' ', trim((string)$userRoleRaw));
$isMarketingOIC = preg_match('/^marketing\s*[-–—]\s*oic$/iu', $roleNorm) === 1;

// Tanggapin '1' / on / true / boolean
$dlRaw = $request->input('download_all');
$dlParam = $request->boolean('download_all') || in_array($dlRaw, ['1', 1, 'on', 'true'], true);

// Final guard
$downloadAll = $isMarketingOIC && $dlParam;

        DownloadedMacroOutputLog::create([
            'timestamp'     => $request->input('date'),
            'page'          => $request->input('PAGE'),
            'downloaded_by' => Auth::user()?->name ?? 'Unknown',
            'downloaded_at' => Carbon::now(),
        ]);

        $query = DB::table('macro_output');

        // Step 1: Apply filters
        if ($request->has('date') && $request->date) {
            $formatted = date('d-m-Y', strtotime($request->date));
            $query->where('TIMESTAMP', 'like', "%$formatted");
        }

        if ($request->has('PAGE') && $request->PAGE) {
            $query->where('PAGE', $request->PAGE);
        }

        // Step 2: Validation & status restriction (only when NOT download_all)
        if (!$downloadAll) {
            // Only allow download if all filtered rows (excluding CANNOT PROCEED) have valid fields
            $hasMissingFields = (clone $query)->where(function ($q) {
                $q->where(function ($subQ) {
                    // ✅ Kung hindi "CANNOT PROCEED", kailangan valid lahat ng fields
                    $subQ->whereNotIn('STATUS', ['CANNOT PROCEED'])
                        ->where(function ($innerQ) {
                            $innerQ->whereNull('STATUS')->orWhere('STATUS', '')
                                ->orWhereNull('ITEM_NAME')->orWhere('ITEM_NAME', '')
                                ->orWhereRaw('CHAR_LENGTH("ITEM_NAME") > 20')
                                ->orWhereNull('COD')->orWhere('COD', '');
                        });
                });
            })->exists();

            if ($hasMissingFields) {
                return back()->with('error', 'Download FAILED: Some entries are missing STATUS, ITEM NAME, or COD.');
            }

            // Step 3: Restrict to only rows marked as "PROCEED"
            $query->where('STATUS', 'PROCEED');
        }

        // Step 3.5: Select fields (now including fb_name)
        $records = $query->select(
            'FULL NAME',
            'PHONE NUMBER',
            'ADDRESS',
            'PROVINCE',
            'CITY',
            'BARANGAY',
            'ITEM_NAME',
            'COD',
            'fb_name'
        )->get();

        if ($records->isEmpty()) {
            return back()->with('error', 'Download FAILED: No entries found for the selected filters.');
        }

        // Step 4: Generate filename
        $pagePart = $request->PAGE ? preg_replace('/[^a-zA-Z0-9_]/', '_', $request->PAGE) : 'AllPages';
        $datePart = $request->date ?? now()->format('Y-m-d');
        $timePart = now()->format('H-i-s');
        $filename = "{$pagePart}_{$datePart}_{$timePart}.csv";

        // Step 5: Prepare CSV content
        $handle = fopen('php://temp', 'w+');

        // Load first rows from Excel template (kept as-is)
        $templatePath = resource_path('templates/exptemplete.xls');
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($templatePath);
        $sheet = $spreadsheet->getActiveSheet();
        $templateData = $sheet->rangeToArray('A1:N8', null, true, true, false);

        foreach ($templateData as $row) {
            fputcsv($handle, $row);
        }

        // Step 6: Append actual data rows
        // Keep column count consistent with template: add fb_name + remarks
        foreach ($records as $row) {
            fputcsv($handle, [
                $row->{'FULL NAME'},          // 1
                $row->{'PHONE NUMBER'},       // 2
                $row->ADDRESS,                // 3
                $row->PROVINCE,               // 4
                $row->CITY,                   // 5
                $row->BARANGAY,               // 6
                'EZ',                         // 7 (Courier code)
                $row->{'ITEM_NAME'},          // 8
                '0.5',                        // 9  Weight (kg)
                strtok($row->ITEM_NAME, ' '), // 10 Total parcels(*) placeholder
                '549',                        // 11 Parcel Value
                $row->COD,                    // 12 COD
                $row->fb_name,                // 13 ✅ FB NAME
                null                          // 14 Remarks (kept)
            ]);
        }

        // Step 7: Output CSV
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return response($content, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename={$filename}",
        ]);
    }

    public function edit($id)
    {
        $record = MacroOutput::findOrFail($id);
        return view('macro_output.edit', compact('record'));
    }

    public function validateItems(Request $request)
    {
        $ids = $request->input('ids', []);
        $records = MacroOutput::whereIn('id', $ids)->get(['id', 'ITEM_NAME', 'COD']);

        $results = [];

        foreach ($records as $record) {
            $invalids = [];

            if (is_null($record->ITEM_NAME) || trim($record->ITEM_NAME) === ''|| mb_strlen($record->ITEM_NAME) > 20) {
                $invalids['ITEM_NAME'] = true;
            }

            if (is_null($record->COD) || trim($record->COD) === '') {
                $invalids['COD'] = true;
            }

            $results[] = [
                'id' => $record->id,
                'invalid_fields' => $invalids,
            ];
        }

        return response()->json($results);
    }

    public function validateAddresses(Request $request)
    {
        $filePath = resource_path('views/macro_output/jnt_address.txt');

        $validMap = [];
        $validProvinces = [];
        $validCities = [];
        $validBarangays = [];

        if (file_exists($filePath)) {
            $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $parts = array_map('trim', explode('|', $line));
                if (count($parts) !== 3 || strtolower($parts[0]) === 'province') continue;
                [$prov, $city, $brgy] = $parts;

                $key = strtolower("$prov|$city|$brgy");
                $validMap[$key] = true;
                $validProvinces[] = strtolower($prov);
                $validCities[] = strtolower($city);
                $validBarangays[] = strtolower($brgy);
            }
        }

        $validProvinces = array_unique($validProvinces);
        $validCities = array_unique($validCities);
        $validBarangays = array_unique($validBarangays);

        // ✅ Limit validation to provided IDs
        $ids = $request->input('ids', []);
        $records = MacroOutput::whereIn('id', $ids)->get([
            'id', 'PROVINCE', 'CITY', 'BARANGAY', 'PHONE NUMBER'
        ]);

        // Collect all phone numbers to check for duplicates
        $phoneCounts = [];
        foreach ($records as $record) {
            $phone = trim($record->{'PHONE NUMBER'});
            if ($phone !== '') {
                $phoneCounts[$phone] = ($phoneCounts[$phone] ?? 0) + 1;
            }
        }

        $results = [];

        foreach ($records as $record) {
            $prov = strtolower(trim($record->PROVINCE));
            $city = strtolower(trim($record->CITY));
            $brgy = strtolower(trim($record->BARANGAY));
            $phone = trim($record->{'PHONE NUMBER'});
            $fullKey = "$prov|$city|$brgy";

            $isValid = isset($validMap[$fullKey]);

            // PHONE NUMBER validation
            $phoneInvalid = false;

            if ($phone === '' || is_null($phone)) {
                $phoneInvalid = true;

            } elseif (!preg_match('/^9\d{9}$/', $phone)) {
                $phoneInvalid = true;

            } elseif ($phone === '9123456789') {
                $phoneInvalid = true;

            } elseif (($phoneCounts[$phone] ?? 0) > 1) {
                $phoneInvalid = true;
            }

            $results[] = [
                'id' => $record->id,
                'invalid_fields' => array_filter([
                    'PROVINCE'      => !$isValid && !in_array($prov, $validProvinces),
                    'CITY'          => !$isValid && !in_array($city, $validCities),
                    'BARANGAY'      => !$isValid && !in_array($brgy, $validBarangays),
                    'PHONE NUMBER'  => $phoneInvalid,
                ]),
            ];
        }

        return response()->json($results);
    }

    public function summary(Request $request)
{
    $start = $request->start_date;
    $end   = $request->end_date;

    // ✅ Default: kung walang date range, today lang
    if (!$start && !$end) {
        $start = $end = now()->toDateString(); // 'Y-m-d'
    }

    // Convert to Carbon
    $startDate = \Carbon\Carbon::parse($start)->startOfDay();
    $endDate   = \Carbon\Carbon::parse($end)->endOfDay();

    // ✅ Safety: huwag payagan sobrang laking range (hal. > 60 days)
    if ($startDate->diffInDays($endDate) > 60) {
        return back()->with('error', 'Please select a date range of 60 days or less for the summary.');
    }

    // Gumawa ng list ng mga araw sa range
    $dateDMYList = []; // format: 'd-m-Y' (para sa TIMESTAMP)
    $dateMapDMYtoYMD = []; // '01-07-2025' => '2025-07-01'

    $cursor = $startDate->copy();
    while ($cursor->lte($endDate)) {
        $dmy = $cursor->format('d-m-Y');
        $ymd = $cursor->format('Y-m-d');
        $dateDMYList[] = $dmy;
        $dateMapDMYtoYMD[$dmy] = $ymd;
        $cursor->addDay();
    }

    // ✅ Query: MacroOutput rows lang na may PAGE at sakop ng piniling dates
    $query = MacroOutput::query()
        ->whereNotNull('PAGE')
        ->where(function ($q) use ($dateDMYList) {
            foreach ($dateDMYList as $dmy) {
                // TIMESTAMP sample: "00:03 01-07-2025" → LIKE "%01-07-2025"
                $q->orWhere('TIMESTAMP', 'like', "%{$dmy}");
            }
        });

    // (Optional) kung gusto mo mag-filter per PAGE in future:
    if ($request->filled('PAGE')) {
        $query->where('PAGE', $request->PAGE);
    }

    // Piliin lang yung kailangan sa summary
    $records = $query->select('TIMESTAMP', 'PAGE', 'STATUS', 'waybill')->get();

    $summary = [];
    $totalCounts = [
        'PROCEED'           => 0,
        'CANNOT PROCEED'    => 0,
        'ODZ'               => 0,
        'BLANK'             => 0,
        'TOTAL'             => 0,
        'MATCHED_WAYBILLS'  => 0,
        'SCANNED_WAYBILLS'  => 0,
    ];

    // ✅ I-track lahat ng waybills sa filtered records para i-limit yung from_jnts query
    $allWaybillSet = []; // associative: waybill => true

    foreach ($records as $record) {
        // TIMESTAMP example: "00:03 01-07-2025"
        $parts = explode(' ', $record->TIMESTAMP);
        $dateDMY = $parts[1] ?? null;
        if (!$dateDMY) {
            continue;
        }

        // Kunin ang Y-m-d gamit yung precomputed map
        if (!isset($dateMapDMYtoYMD[$dateDMY])) {
            continue; // hindi sakop ng piniling date range
        }
        $formattedDate = $dateMapDMYtoYMD[$dateDMY]; // 'Y-m-d'

        $status = $record->STATUS ?: 'BLANK';
        $page   = $record->PAGE;

        if (!isset($summary[$formattedDate])) {
            $summary[$formattedDate] = [];
        }

        if (!isset($summary[$formattedDate][$page])) {
            $summary[$formattedDate][$page] = [
                'PROCEED'          => 0,
                'CANNOT PROCEED'   => 0,
                'ODZ'              => 0,
                'BLANK'            => 0,
                'TOTAL'            => 0,
                'WAYBILLS'         => [],
                'downloaded_by'    => null,
                'downloaded_at'    => null,
                'SCANNED_WAYBILLS' => 0,
                'MATCHED_WAYBILLS' => 0,
            ];
        }

        // Increment per-status at total
        if (!isset($summary[$formattedDate][$page][$status])) {
            $summary[$formattedDate][$page][$status] = 0;
        }
        $summary[$formattedDate][$page][$status]++;
        $summary[$formattedDate][$page]['TOTAL']++;

        // Track total counts (for TOTAL row)
        if (!isset($totalCounts[$status])) {
            $totalCounts[$status] = 0;
        }
        $totalCounts[$status]++;
        $totalCounts['TOTAL']++;

        // Waybill tracking
        if (!empty($record->waybill)) {
            $wb = (string) $record->waybill;
            $summary[$formattedDate][$page]['WAYBILLS'][] = $wb;
            $allWaybillSet[$wb] = true;
        }
    }

    // Sort page names alphabetically inside each date group
    foreach ($summary as &$pages) {
        ksort($pages);
    }
    unset($pages);

    // ✅ Fetch download logs grouped by date+page
    $logs = DownloadedMacroOutputLog::query()
        ->select('timestamp', 'page', 'downloaded_by', 'downloaded_at')
        ->get()
        ->groupBy(fn($log) => $log->timestamp . '|' . $log->page);

    // ✅ Limit from_jnts query to waybills lang na nasa summary date range
    $existingWaybillSet = [];

    if (!empty($allWaybillSet)) {
        $waybillList = array_keys($allWaybillSet); // unique waybill strings

        $existingWaybills = DB::table('from_jnts')
            ->whereIn('waybill_number', $waybillList)
            ->pluck('waybill_number')
            ->map('strval')
            ->toArray();

        $existingWaybillSet = array_flip($existingWaybills); // for fast lookup: isset(...)
    }

    // Attach downloaded_by, downloaded_at, and waybill counts per (date,page)
    foreach ($summary as $date => &$pages) {
        foreach ($pages as $page => &$counts) {
            $key = $date . '|' . $page;
            $latestLog = $logs->has($key)
                ? $logs[$key]->sortByDesc('downloaded_at')->first()
                : null;

            $counts['downloaded_by'] = $latestLog->downloaded_by ?? null;
            $counts['downloaded_at'] = $latestLog->downloaded_at ?? null;

            $waybills = $counts['WAYBILLS'] ?? [];

            // Matched Waybills = bilang ng may waybill sa macro_output
            $matched = count($waybills);
            $counts['MATCHED_WAYBILLS'] = $matched;
            $totalCounts['MATCHED_WAYBILLS'] += $matched;

            // Scanned Waybills = ilan sa mga waybill na 'yan ang meron sa from_jnts
            $scanned = 0;
            foreach ($waybills as $wb) {
                if (isset($existingWaybillSet[$wb])) {
                    $scanned++;
                }
            }
            $counts['SCANNED_WAYBILLS'] = $scanned;
            $totalCounts['SCANNED_WAYBILLS'] += $scanned;
        }
    }
    unset($pages);

    return view('macro_output.summary', compact('summary', 'totalCounts', 'start', 'end'));
}



    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'FULL NAME'   => 'required|string|max:255',
            'PHONE NUMBER'=> 'required|string|max:100',
            'ADDRESS'     => 'required|string',
            'PROVINCE'    => 'required|string|max:255',
            'CITY'        => 'required|string|max:255',
            'BARANGAY'    => 'required|string|max:255',
            'STATUS'      => 'nullable|string|max:255',
        ]);

        $record = MacroOutput::findOrFail($id);
        $record->update($validated);

        return redirect()->back()->with('success', 'Record updated successfully.');
    }

    public function index(Request $request)
{
    $date = $request->filled('date') ? $request->date : now()->subDay()->toDateString();

    $baseQuery = MacroOutput::query()->where('ts_date', $date);

    if ($request->filled('PAGE')) {
        $baseQuery->where('PAGE', $request->PAGE);
    }

    // ✅ Cross-DB safe column quoting for raw SQL
    $driver = DB::connection()->getDriverName();
    $STATUS = $driver === 'pgsql' ? '"STATUS"' : 'STATUS';

    $c = (clone $baseQuery)->selectRaw("
        COUNT(*) as TOTAL,
        SUM(CASE WHEN {$STATUS} = 'PROCEED' THEN 1 ELSE 0 END) as PROCEED,
        SUM(CASE WHEN {$STATUS} = 'CANNOT PROCEED' THEN 1 ELSE 0 END) as CANNOT_PROCEED,
        SUM(CASE WHEN {$STATUS} = 'ODZ' THEN 1 ELSE 0 END) as ODZ,
        SUM(CASE WHEN {$STATUS} IS NULL OR {$STATUS} = '' THEN 1 ELSE 0 END) as BLANK
    ")->first();

    $statusCounts = [
        'TOTAL'          => (int) ($c->TOTAL ?? 0),
        'PROCEED'        => (int) ($c->PROCEED ?? 0),
        'CANNOT PROCEED' => (int) ($c->CANNOT_PROCEED ?? 0),
        'ODZ'            => (int) ($c->ODZ ?? 0),
        'BLANK'          => (int) ($c->BLANK ?? 0),
    ];

    $recordQuery = (clone $baseQuery);

    if ($request->filled('status_filter')) {
        if ($request->status_filter === 'BLANK') {
            $recordQuery->where(function ($q) {
                $q->whereNull('STATUS')->orWhere('STATUS', '');
            });
        } else {
            $recordQuery->where('STATUS', $request->status_filter);
        }
    }

    $selectCols = [
        'id', 'FULL NAME', 'PHONE NUMBER', 'ADDRESS',
        'PROVINCE', 'CITY', 'BARANGAY', 'STATUS',
        'PAGE', 'TIMESTAMP', 'all_user_input',
        'HISTORICAL LOGS', 'APP SCRIPT CHECKER',
        'edited_full_name', 'edited_phone_number', 'edited_address',
        'edited_province', 'edited_city', 'edited_barangay',
        'ITEM_NAME','COD','edited_item_name','edited_cod','status_logs'
    ];

    $perPage = $request->filled('PAGE') ? 200 : 100;

    $records = $recordQuery
        ->select($selectCols)
        ->orderByDesc('id')
        ->simplePaginate($perPage)
        ->withQueryString();

    $records->through(function ($r) {
        return $this->attachHighlightTokens($r);
    });

    // ✅ Pages dropdown uses the composite index (ts_date, PAGE)
    $pages = MacroOutput::query()
        ->where('ts_date', $date)
        ->select('PAGE')
        ->distinct()
        ->orderBy('PAGE')
        ->pluck('PAGE');

    $paginateOnlyWhenAll = true;

    return view('macro_output.index', compact('records', 'pages', 'date', 'statusCounts', 'paginateOnlyWhenAll'));
}



private function tokenizeLocation($raw, string $type): array
{
    $s = trim((string) $raw);
    if ($s === '') return [];

    // normalize spaces + remove common wrappers
    $s = str_replace("\xC2\xA0", ' ', $s); // nbsp
    $s = preg_replace('/[(){}\[\]"“”\'`]+/u', ' ', $s);

    // remove prefixes depende sa type
    if ($type === 'brgy') {
        $s = preg_replace('/^(brgy\.?|barangay|bgy|brg)\s*/iu', '', $s);
    } elseif ($type === 'city') {
        $s = preg_replace('/^(city\s+of|city|municipality\s+of|municipality|mun\.?)\s*/iu', '', $s);
    } elseif ($type === 'prov') {
        $s = preg_replace('/^(province\s+of|prov\.?)\s*/iu', '', $s);
    }

    $stop = [
        'brgy'=>true,'barangay'=>true,'bgy'=>true,'brg'=>true,
        'city'=>true,'of'=>true,'province'=>true,'prov'=>true,
        'municipality'=>true,'mun'=>true,
    ];

    // split into tokens (unicode letters/digits incl ñ)
    $parts = preg_split('/[^\p{L}\p{N}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY);

    $uniq = [];
    foreach ($parts as $p) {
        $t = trim($p);
        if ($t === '') continue;

        $low = mb_strtolower($t, 'UTF-8');
        if (isset($stop[$low])) continue;

        // skip single-digit tokens
        if (mb_strlen($t, 'UTF-8') === 1 && preg_match('/^\d$/u', $t)) continue;

        // keep token if length>=2 OR is number length>=2
        if (mb_strlen($t, 'UTF-8') < 2 && !preg_match('/^\d{2,}$/u', $t)) continue;

        $uniq[$low] = $t; // keep original token but unique by lowercase
    }

    $tokens = array_values($uniq);

    // longest-first para mas okay yung highlight
    usort($tokens, fn($a,$b) => mb_strlen($b,'UTF-8') <=> mb_strlen($a,'UTF-8'));

    return $tokens;
}

private function attachHighlightTokens($r)
{
    $r->brgy_tokens = $this->tokenizeLocation($r->{'BARANGAY'} ?? '', 'brgy');
    $r->city_tokens = $this->tokenizeLocation($r->{'CITY'} ?? '', 'city');
    $r->prov_tokens = $this->tokenizeLocation($r->{'PROVINCE'} ?? '', 'prov');
    return $r;
}

    public function updateField(Request $request)
{
    $request->validate([
        'id'    => 'required|integer',
        'field' => 'required|string',
        'value' => 'nullable|string',
    ]);

    $record = \App\Models\MacroOutput::findOrFail($request->id);

    $field = $request->field;
    $newValue = (string)($request->value ?? '');
    $oldValue = (string)($record->{$field} ?? '');

    // sanitize for pipe-based logs (avoid breaking parsing)
    $safe = function ($v) {
        $v = (string)$v;
        $v = str_replace(["\r", "\n"], ' ', $v);
        $v = str_replace('|', '/', $v);
        return trim($v);
    };

    // Only log if actual change
    if ($newValue !== $oldValue) {
        $user = auth()->user()?->name ?? 'Unknown User';
        $ts   = now()->format('Y-m-d H:i:s');

        $userS  = $safe($user);
        $tsS    = $safe($ts);
        $fieldS = $safe($field);
        $oldS   = $safe($oldValue);
        $newS   = $safe($newValue);

        // Mark as edited once
        $editFlags = [
            'FULL NAME'     => 'edited_full_name',
            'PHONE NUMBER'  => 'edited_phone_number',
            'ADDRESS'       => 'edited_address',
            'PROVINCE'      => 'edited_province',
            'CITY'          => 'edited_city',
            'BARANGAY'      => 'edited_barangay',
            'ITEM_NAME'     => 'edited_item_name',
            'COD'           => 'edited_cod',
        ];

        if ($field === 'STATUS') {
            // ✅ STATUS LOGS format: ts|user|NEW_STATUS
            $logEntry = "{$tsS}|{$userS}|{$newS}\n";
            $record->status_logs = trim($logEntry . ($record->status_logs ?? ''));
        } else {
            // ✅ HIST LOGS format: ts|user|FIELD|OLD|NEW
            $logEntry = "{$tsS}|{$userS}|{$fieldS}|{$oldS}|{$newS}\n";
            $record->{'HISTORICAL LOGS'} = trim($logEntry . ($record->{'HISTORICAL LOGS'} ?? ''));
        }

        // Save updated field
        $record->{$field} = $newValue;

        // set edited flag
        if (array_key_exists($field, $editFlags)) {
            $flag = $editFlags[$field];
            if (!$record->{$flag}) {
                $record->{$flag} = true;
            }
        }

        $record->save();
    }

    return response()->json(['status' => 'success']);
}



    public function bulkUpdate(Request $request)
    {
        foreach ($request->input('records', []) as $id => $fields) {
            \App\Models\MacroOutput::where('id', $id)->update($fields);
        }

        return redirect()->back()->with('success', 'All updates saved!');
    }
}
