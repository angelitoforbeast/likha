<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MacroGsheetSetting;
use App\Models\MacroOutput;
use Google_Client;
use Google\Service\Sheets;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Jobs\ImportMacroFromGooglesheet;

class MacroGsheetController extends Controller
{
    public function import(Request $request)
{
    try {
        dispatch(new \App\Jobs\ImportMacroFromGoogleSheet());
        return back()->with('success', '⏳ Import started. Please wait a few moments.');
    } catch (\Exception $e) {
        return back()->with('error', '❌ Failed to dispatch import job: ' . $e->getMessage());
    }
}




    private function extractSpreadsheetId($url)
    {
        preg_match('/\/d\/([a-zA-Z0-9-_]+)/', $url, $matches);
        return $matches[1] ?? null;
    }

    public function update(Request $request, $id)
{
    $request->validate([
        'sheet_url' => 'required|url',
        'sheet_range' => 'required|string',
    ]);

    $sheetId = $this->extractSpreadsheetId($request->sheet_url);
    if (!$sheetId) {
        return back()->with('error', 'Invalid Google Sheet URL.');
    }

    // Fetch actual GSheet name (title)
    try {
        $client = new \Google_Client();
        $client->setApplicationName('Laravel GSheet');
        $client->setScopes([\Google\Service\Sheets::SPREADSHEETS_READONLY]);
        $client->setAuthConfig(storage_path('app/credentials.json'));
        $client->setAccessType('offline');

        $service = new \Google\Service\Sheets($client);
        $sheetMetadata = $service->spreadsheets->get($sheetId);
        $actualName = $sheetMetadata->getProperties()->getTitle();

    } catch (\Exception $e) {
        return back()->with('error', 'Failed to fetch sheet name: ' . $e->getMessage());
    }

    // Update in DB
    $setting = MacroGsheetSetting::findOrFail($id);
    $setting->update([
        'gsheet_name' => $actualName,
        'sheet_url' => $request->sheet_url,
        'sheet_range' => $request->sheet_range,
    ]);

    return back()->with('success', 'Setting updated and name synced from GSheet.');
}



    public function settings()
    {
        $settings = MacroGsheetSetting::all();
        return view('macro.gsheet.settings', compact('settings'));
    }

    public function storeSetting(Request $request)
{
    $request->validate([
        'sheet_url' => 'required|url',
        'sheet_range' => 'required|string',
    ]);

    $sheetId = $this->extractSpreadsheetId($request->sheet_url);
    if (!$sheetId) {
        return back()->with('error', 'Invalid Google Sheet URL.');
    }

    // Fetch the actual sheet name from Google Sheets
    try {
        $client = new \Google_Client();
        $client->setApplicationName('Laravel GSheet');
        $client->setScopes([\Google\Service\Sheets::SPREADSHEETS_READONLY]);
        $client->setAuthConfig(storage_path('app/credentials.json'));
        $client->setAccessType('offline');

        $service = new \Google\Service\Sheets($client);
        $sheetMetadata = $service->spreadsheets->get($sheetId);
        $actualName = $sheetMetadata->getProperties()->getTitle();

    } catch (\Exception $e) {
        return back()->with('error', 'Failed to fetch sheet name: ' . $e->getMessage());
    }

    // Save to DB
    MacroGsheetSetting::create([
        'gsheet_name' => $actualName,
        'sheet_url' => $request->sheet_url,
        'sheet_range' => $request->sheet_range,
    ]);

    return back()->with('success', 'New setting saved and sheet name synced.');
}



    public function deleteSetting($id)
    {
        MacroGsheetSetting::findOrFail($id)->delete();
        return back()->with('success', 'Setting deleted.');
    }

    public function index()
{
    $records = MacroOutput::latest()->paginate(500);
    $totalCount = MacroOutput::count();

    return view('macro.gsheet.index', compact('records', 'totalCount'));
}


    public function deleteAll()
    {
        MacroOutput::truncate();
        return back()->with('success', 'All records deleted successfully.');
    }
}
