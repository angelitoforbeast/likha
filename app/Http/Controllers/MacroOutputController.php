<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MacroOutput;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Models\DownloadedMacroOutputLog;


class MacroOutputController extends Controller
{

    public function download(Request $request)
{

    DownloadedMacroOutputLog::create([
        'timestamp' => $request->input('date'),
        'page' => $request->input('PAGE'),
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

    // Step 2: Only allow download if all filtered rows have STATUS filled
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
    $proceedQuery = (clone $query)->where('STATUS', 'PROCEED');

    $records = $proceedQuery->select(
        'FULL NAME',
        'PHONE NUMBER',
        'ADDRESS',
        'PROVINCE',
        'CITY',
        'BARANGAY',
        'ITEM_NAME',
        'COD'
    )->get();

    if ($records->isEmpty()) {
        return back()->with('error', 'Download FAILED: No entries marked as "PROCEED" in the filtered results.');
    }

    // Step 4: Generate filename
    $pagePart = $request->PAGE ? preg_replace('/[^a-zA-Z0-9_]/', '_', $request->PAGE) : 'AllPages';
    $datePart = $request->date ?? now()->format('Y-m-d');
    $timePart = now()->format('H-i-s');
    $filename = "{$pagePart}_{$datePart}_{$timePart}.csv";

    // Step 5: Prepare CSV content
    $handle = fopen('php://temp', 'w+');

    // Load first 7 rows from Excel template
    $templatePath = resource_path('templates/exptemplete.xls');
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($templatePath);
    $sheet = $spreadsheet->getActiveSheet();
    $templateData = $sheet->rangeToArray('A1:N8', null, true, true, false);

    foreach ($templateData as $row) {
        fputcsv($handle, $row);
    }

    // Step 6: Append actual data rows
    foreach ($records as $row) {
        fputcsv($handle, [
            $row->{'FULL NAME'},
            $row->{'PHONE NUMBER'},
            $row->ADDRESS,
            $row->PROVINCE,
            $row->CITY,
            $row->BARANGAY,
            'EZ',
             $row->{'ITEM_NAME'},
            '0.5', // Weight (kg)
            strtok($row->ITEM_NAME, ' '), // First word only// Total parcels(*)
            '549', // Parcel Value
            $row->COD,
            null  // Remarks
        ]);
    }

    // Step 7: Output CSV
    rewind($handle);
    $content = stream_get_contents($handle);
    fclose($handle);

    return response($content, 200, [
        'Content-Type' => 'text/csv',
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

} elseif ($phoneCounts[$phone] > 1) {
    $phoneInvalid = true;
}


        $results[] = [
            'id' => $record->id,
            'invalid_fields' => array_filter([
                'PROVINCE' => !$isValid && !in_array($prov, $validProvinces),
                'CITY' => !$isValid && !in_array($city, $validCities),
                'BARANGAY' => !$isValid && !in_array($brgy, $validBarangays),
                'PHONE NUMBER' => $phoneInvalid,
            ]),
        ];
    }

    return response()->json($results);
}


    public function summary(Request $request)
{
    $start = $request->start_date;
    $end = $request->end_date;

    // Step 1: Get all records (filtered by PAGE if needed)
    $query = MacroOutput::query()->whereNotNull('PAGE');

    if ($request->filled('PAGE')) {
        $query->where('PAGE', $request->PAGE);
    }

    $records = $query->select('TIMESTAMP', 'PAGE', 'STATUS')->get();

    // Step 2: Group and format in PHP
    $summary = [];
    $totalCounts = [
        'PROCEED' => 0,
        'CANNOT PROCEED' => 0,
        'ODZ' => 0,
        'BLANK' => 0,
        'TOTAL' => 0,
    ];

    foreach ($records as $record) {
        // Extract date part from "00:03 01-07-2025"
        $parts = explode(' ', $record->TIMESTAMP);
        $datePart = $parts[1] ?? null;

        if (!$datePart) continue;

        $formattedDate = \Carbon\Carbon::createFromFormat('d-m-Y', $datePart)->format('Y-m-d');

        // Filter by start/end date if provided
        if ($start && $formattedDate < $start) continue;
        if ($end && $formattedDate > $end) continue;

        $status = $record->STATUS ?: 'BLANK';
        $page = $record->PAGE;

        if (!isset($summary[$formattedDate])) {
            $summary[$formattedDate] = [];
        }

        if (!isset($summary[$formattedDate][$page])) {
            $summary[$formattedDate][$page] = [
                'PROCEED' => 0,
                'CANNOT PROCEED' => 0,
                'ODZ' => 0,
                'BLANK' => 0,
                'TOTAL' => 0,
            ];
        }

        $summary[$formattedDate][$page][$status]++;
        $summary[$formattedDate][$page]['TOTAL']++;

        $totalCounts[$status]++;
        $totalCounts['TOTAL']++;
    }

// Sort page names alphabetically inside each date group
foreach ($summary as &$pages) {
    ksort($pages);
}
// Get logs grouped by date and page
$logs = DownloadedMacroOutputLog::query()
    ->select('timestamp', 'page', 'downloaded_by', 'downloaded_at')
    ->get()
    ->groupBy(fn($log) => $log->timestamp . '|' . $log->page);

// Attach downloaded_by and downloaded_at to each summary entry
foreach ($summary as $date => &$pages) {
    foreach ($pages as $page => &$counts) {
        $key = $date . '|' . $page;
        $latestLog = $logs->has($key) ? $logs[$key]->sortByDesc('downloaded_at')->first() : null;

        $counts['downloaded_by'] = $latestLog->downloaded_by ?? null;
        $counts['downloaded_at'] = $latestLog->downloaded_at ?? null;
    }
}
unset($pages);

return view('macro_output.summary', compact('summary', 'totalCounts'));

}



    
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'FULL NAME' => 'required|string|max:255',
            'PHONE NUMBER' => 'required|string|max:100',
            'ADDRESS' => 'required|string',
            'PROVINCE' => 'required|string|max:255',
            'CITY' => 'required|string|max:255',
            'BARANGAY' => 'required|string|max:255',
            'STATUS' => 'nullable|string|max:255',
        ]);

        $record = MacroOutput::findOrFail($id);
        $record->update($validated);

        return redirect()->back()->with('success', 'Record updated successfully.');
    }

    public function index(Request $request)
{
    // STEP 1
    $date = $request->filled('date') ? $request->date : now()->subDay()->toDateString();
    $formattedDate = \Carbon\Carbon::parse($date)->format('d-m-Y');

    $baseQuery = MacroOutput::query()->where('TIMESTAMP', 'LIKE', "%$formattedDate");

    if ($request->filled('PAGE')) {
        $baseQuery->where('PAGE', $request->PAGE);
    }

    // STEP 2: status counts
    $filteredRecords = (clone $baseQuery)->get();

    $statusCounts = [
        'TOTAL'           => $filteredRecords->count(),
        'PROCEED'         => $filteredRecords->where('STATUS', 'PROCEED')->count(),
        'CANNOT PROCEED'  => $filteredRecords->where('STATUS', 'CANNOT PROCEED')->count(),
        'ODZ'             => $filteredRecords->where('STATUS', 'ODZ')->count(),
        'BLANK'           => $filteredRecords->filter(fn($rec) => trim((string) $rec->STATUS) === '')->count(),
    ];

    // STEP 3: records + conditional pagination
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
        'ITEM_NAME','COD','edited_item_name','edited_cod'
    ];

    // ✅ paginateOnlyWhenAll = true kung walang PAGE filter (ibig sabihin "All")
    $paginateOnlyWhenAll = !$request->filled('PAGE');

    if ($paginateOnlyWhenAll) {
        $records = $recordQuery->select($selectCols)->orderByDesc('id')->paginate(100);
    } else {
        // No pagination kapag may PAGE na pinili
        $records = $recordQuery->select($selectCols)->orderByDesc('id')->get();
    }

    // PAGE options
    $pages = MacroOutput::where('TIMESTAMP', 'LIKE', "%$formattedDate")
        ->select('PAGE')->distinct()->orderBy('PAGE')->pluck('PAGE');

    return view('macro_output.index', compact('records', 'pages', 'date', 'statusCounts', 'paginateOnlyWhenAll'));
}






    public function updateField(Request $request)
{
    $request->validate([
        'id' => 'required|integer',
        'field' => 'required|string',
        'value' => 'nullable|string',
    ]);

    $record = \App\Models\MacroOutput::findOrFail($request->id);

    $field = $request->field;
    $newValue = $request->value;
    $oldValue = $record->{$field};

    // Only log if there's an actual change
    if ($newValue !== $oldValue) {
        $user = auth()->user()?->name ?? 'Unknown User';
        $timestamp = now()->format('Y-m-d H:i:s');

        if ($field !== 'STATUS') {
            $logEntry = "[{$timestamp}] {$user} updated {$field}: \"{$oldValue}\" → \"{$newValue}\"\n";
            $record->{'HISTORICAL LOGS'} = trim($logEntry . ($record->{'HISTORICAL LOGS'} ?? ''));
        }

        // Save updated field
        $record->{$field} = $newValue;

        // Mark as edited once (only if not already true)
        $editFlags = [
            'FULL NAME' => 'edited_full_name',
            'PHONE NUMBER' => 'edited_phone_number',
            'ADDRESS' => 'edited_address',
            'PROVINCE' => 'edited_province',
            'CITY' => 'edited_city',
            'BARANGAY' => 'edited_barangay',
            'ITEM_NAME' => 'edited_item_name',
            'COD' => 'edited_cod',
        ];

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
