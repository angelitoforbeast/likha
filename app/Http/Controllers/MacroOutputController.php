<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MacroOutput;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\DB;
class MacroOutputController extends Controller
{

    public function download(Request $request)
{
    $query = DB::table('macro_output');

    // Step 1: Apply filters
    if ($request->has('date') && $request->date) {
        $formatted = date('d-m-Y', strtotime($request->date));
        $query->where('TIMESTAMP', 'like', "%$formatted");
    }

    if ($request->has('PAGE') && $request->PAGE) {
        $query->where('PAGE', $request->PAGE);
    }

    // Step 2: Block download if any STATUS is blank
    $incomplete = (clone $query)
        ->where(function ($q) {
            $q->whereNull('STATUS')->orWhere('STATUS', '');
        })
        ->exists();

    if ($incomplete) {
        return back()->with('error', 'Download FAILED: Some entries in the filtered results are missing a STATUS.');
    }

    // Step 3: Fetch data
    $records = $query->select(
        'FULL NAME',
        'PHONE NUMBER',
        'ADDRESS',
        'PROVINCE',
        'CITY',
        'BARANGAY',
        'ITEM_NAME',
        'COD'
    )->get();

    // Step 4: Generate filename
    $pagePart = $request->PAGE ? preg_replace('/[^a-zA-Z0-9_]/', '_', $request->PAGE) : 'AllPages';
    $datePart = $request->date ?? now()->format('Y-m-d');
    $timePart = now()->format('H-i-s');
    $filename = "{$pagePart}_{$datePart}_{$timePart}.csv";

    // Step 5: Prepare CSV content
    $handle = fopen('php://temp', 'w+');

    // Load first 7 rows from template Excel
    $templatePath = resource_path('templates/exptemplete.xls'); // <-- upload your Excel here
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
            $row->ITEM_NAME,
            '0.5', // Weight (kg)
            explode(' ', trim($row->ITEM_NAME))[0] ?? null, // Total parcels(*)
            '549', // Parcel Value
            $row->COD,
            null  // Remarks
        ]);
    }

    // Step 7: Download as CSV
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
    $records = MacroOutput::whereIn('id', $ids)->get(['id', 'PROVINCE', 'CITY', 'BARANGAY']);

    $results = [];

    foreach ($records as $record) {
        $prov = strtolower(trim($record->PROVINCE));
        $city = strtolower(trim($record->CITY));
        $brgy = strtolower(trim($record->BARANGAY));
        $fullKey = "$prov|$city|$brgy";

        $isValid = isset($validMap[$fullKey]);

        $results[] = [
            'id' => $record->id,
            'invalid_fields' => $isValid ? [] : [
                'PROVINCE' => !in_array($prov, $validProvinces),
                'CITY' => !in_array($city, $validCities),
                'BARANGAY' => !in_array($brgy, $validBarangays),
            ],
        ];
    }

    return response()->json($results);
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
    $query = MacroOutput::query();

    // Default to yesterday if no date is provided
    $date = $request->filled('date') ? $request->date : now()->subDay()->toDateString();
    $formattedDate = \Carbon\Carbon::parse($date)->format('d-m-Y');
    $query->where('TIMESTAMP', 'LIKE', "%$formattedDate");

    // Filter by PAGE
    if ($request->filled('PAGE')) {
        $query->where('PAGE', $request->PAGE);
    }

    // Fetch records
    $records = $query->select(
        'id',
        'FULL NAME',
        'PHONE NUMBER',
        'ADDRESS',
        'PROVINCE',
        'CITY',
        'BARANGAY',
        'STATUS',
        'PAGE',
        'TIMESTAMP',
        'all_user_input',
        'HISTORICAL LOGS',
        'APP SCRIPT CHECKER',
        'edited_full_name',
        'edited_phone_number',
        'edited_address',
        'edited_province',
        'edited_city',
        'edited_barangay'
    )->orderByDesc('id')->paginate(100);

    // Populate page options
    $pages = MacroOutput::where('TIMESTAMP', 'LIKE', "%$formattedDate")
        ->select('PAGE')
        ->distinct()
        ->orderBy('PAGE')
        ->pluck('PAGE');

    return view('macro_output.index', compact('records', 'pages', 'date'));
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
