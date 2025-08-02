<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Models\MacroOutput;
use App\Models\PageSenderMapping;

class JntCheckerController extends Controller
{
    public function index()
    {
        return view('jnt.checker');
    }

    public function upload(Request $request)
{
    \Log::info('📥 Entered upload() function');

    $request->validate([
        'excel_file' => 'required|mimes:xlsx,xls',
    ]);

    function normalizeText($text) {
        return str_replace('Ã±', 'ñ', $text);
    }

    $path = $request->file('excel_file')->getRealPath();
    try {
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    $sheet = $spreadsheet->getActiveSheet();
    $data = $sheet->toArray(null, true, true, true);
    \Log::info('📊 Successfully loaded Excel and converted to array.');
} catch (\Throwable $e) {
    \Log::error('❌ Excel load error: ' . $e->getMessage());
    return back()->with('error', 'Failed to read the Excel file. Make sure it is a valid XLSX/XLS.');
}


    $data = array_values($data); // reindex to 0-based

if (empty($data) || !isset($data[0])) {
    \Log::info('⛔ Header row missing or unreadable.');
    return back()->with('error', 'Excel file has no header row.');
}

\Log::info('✅ Header Row:', $data[0]);

    \Log::info('check header');
    // Step 1: Build case-insensitive header map
    $headers = $data[0]; // first row = headers
    $headerMap = [];
    foreach ($headers as $col => $value) {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $value ?? '')));
        $headerMap[$normalized] = $col;
    }

    \Log::info('✅ Detected Headers:', array_keys($headerMap)); // log all headers found

    $requiredHeaders = ['sender name', 'receiver cellphone', 'item name', 'cod'];
    $missing = array_filter($requiredHeaders, fn($h) => !isset($headerMap[$h]));

    if (count($missing)) {
        return back()->with('error', 'Missing column(s): ' . implode(', ', $missing));
    }

    // Step 2: Required columns (dynamic detection)
    $senderCol = $headerMap['sender name'];
    $receiverCol = $headerMap['receiver cellphone'];
    $itemCol = $headerMap['item name'];
    $codCol = $headerMap['cod'];

    // Step 3: Parse and prepare
    $rows = array_slice($data, 1); // skip header row
    $results = [];
    $lookupKeys = [];

    foreach ($rows as $row) {
        $sender = normalizeText(trim($row[$senderCol] ?? ''));
        $receiver = normalizeText(trim($row[$receiverCol] ?? ''));
        $item = normalizeText(trim($row[$itemCol] ?? ''));
        $rawCod = preg_replace('/[^0-9.]/', '', $row[$codCol] ?? '0');
        $cod = floatval($rawCod);

        $mappedPage = normalizeText(trim(
            \App\Models\PageSenderMapping::where('SENDER_NAME', $sender)->value('PAGE') ?? ''
        ));

        $key = strtolower($mappedPage . '|' . $receiver . '|' . $item . '|' . $cod);
        $lookupKeys[] = $key;

        $results[] = [
            'sender' => $sender,
            'page' => $mappedPage ?: '❌ Not Found in Mapping',
            'receiver' => $receiver,
            'item' => $item,
            'cod' => $cod,
            'key' => $key,
            'matched' => false,
        ];
    }

    // Step 4: Get all MacroOutput keys
    $matches = \App\Models\MacroOutput::select('PAGE', 'PHONE NUMBER', 'ITEM_NAME', 'COD')
        ->get()
        ->map(function ($row) {
            return strtolower($row->PAGE . '|' . $row->{'PHONE NUMBER'} . '|' . $row->ITEM_NAME . '|' . floatval($row->COD));
        })->toArray();

    // Step 5: Match each key
    foreach ($results as &$res) {
        if (in_array($res['key'], $matches)) {
            $res['matched'] = true;
        }
        unset($res['key']);
    }

    // Step 6: Summary
    $matchedCount = collect($results)->where('matched', true)->count();
    $notMatchedCount = collect($results)->where('matched', false)->count();

    // Step 7: Sort unmatched first
    $results = collect($results)->sortBy('matched')->values()->all();

    return view('jnt.checker', [
        'results' => $results,
        'matchedCount' => $matchedCount,
        'notMatchedCount' => $notMatchedCount,
    ]);
}



}
