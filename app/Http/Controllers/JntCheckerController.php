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
    $request->validate([
        'excel_file' => 'required|mimes:xlsx,xls',
    ]);

    function normalizeText($text) {
        return str_replace('Ã±', 'ñ', $text);
    }

    $path = $request->file('excel_file')->getRealPath();
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    $sheet = $spreadsheet->getActiveSheet();
    $data = $sheet->toArray(null, true, true, true);

    $rows = array_slice($data, 1); // skip header
    $results = [];
    $lookupKeys = [];

    // Step 1: Prepare results and lookup keys
    foreach ($rows as $row) {
        $sender = normalizeText(trim($row['S'] ?? ''));
        $receiver = normalizeText(trim($row['F'] ?? ''));
        $item = normalizeText(trim($row['X'] ?? ''));
        $rawCod = preg_replace('/[^0-9.]/', '', $row['M'] ?? '0');
        $cod = floatval($rawCod);

        $mappedPage = normalizeText(trim(PageSenderMapping::where('SENDER_NAME', $sender)->value('PAGE') ?? ''));

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

    // Step 2: Batch query for all possible matches
    $matches = \App\Models\MacroOutput::select('PAGE', 'FULL NAME', 'ITEM_NAME', 'COD')
        ->get()
        ->map(function ($row) {
            return strtolower($row->PAGE . '|' . $row->{'FULL NAME'} . '|' . $row->ITEM_NAME . '|' . floatval($row->COD));
        })->toArray();

    // Step 3: Match each one
    foreach ($results as &$res) {
        if (in_array($res['key'], $matches)) {
            $res['matched'] = true;
        }
        unset($res['key']);
    }

    // Step 4: Count summary
    $matchedCount = collect($results)->where('matched', true)->count();
    $notMatchedCount = collect($results)->where('matched', false)->count();

    // Step 5: Sort so unmatched first
    $results = collect($results)->sortBy('matched')->values()->all();

    return view('jnt.checker', [
        'results' => $results,
        'matchedCount' => $matchedCount,
        'notMatchedCount' => $notMatchedCount,
    ]);
}


}
