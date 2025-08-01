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
    $spreadsheet = IOFactory::load($path);
    $sheet = $spreadsheet->getActiveSheet();
    $data = $sheet->toArray(null, true, true, true);

    $rows = array_slice($data, 1); // skip header
    $results = [];
    $lookupKeys = [];

    // Step 1: Build normalized keys and collect mapping info
    foreach ($rows as $row) {
        $sender = normalizeText(trim($row['S'] ?? ''));
        $receiver = normalizeText(trim($row['F'] ?? ''));
        $item = normalizeText(trim($row['X'] ?? ''));
        $rawCod = preg_replace('/[^0-9.]/', '', $row['M'] ?? '0');
        $cod = floatval($rawCod);

        $mappedPage = normalizeText(trim(PageSenderMapping::where('sender_name', $sender)->value('page') ?? ''));

        $key = strtolower($mappedPage . '|' . $receiver . '|' . $item . '|' . $cod);
        $lookupKeys[] = $key;

        $results[] = [
            'sender' => $sender,
            'page' => $mappedPage ?: '❌ Not Found in Mapping',
            'receiver' => $receiver,
            'item' => $item,
            'cod' => $cod,
            'key' => $key,
            'matched' => false, // temporary
        ];
    }

    // Step 2: Fetch all matches from DB in one query
    $matches = MacroOutput::select('PAGE', 'FULL NAME', 'ITEM_NAME', 'COD')
        ->get()
        ->map(function ($row) {
            return strtolower($row->PAGE . '|' . $row->{'FULL NAME'} . '|' . $row->ITEM_NAME . '|' . floatval($row->COD));
        })->toArray();

    // Step 3: Match each result in PHP
    foreach ($results as &$res) {
        if (in_array($res['key'], $matches)) {
            $res['matched'] = true;
        }
        unset($res['key']); // cleanup
    }

    return view('jnt.checker', ['results' => $results]);
}

}
