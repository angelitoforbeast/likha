<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Checker2Setting;
use Google\Client;
use Google\Service\Sheets;

class Checker2GsheetController extends Controller
{
    public function settings()
    {
        $settings = Checker2Setting::all();
        return view('checker_2.gsheet.settings', compact('settings'));
    }

    public function index()
    {
        $settings = Checker2Setting::all();
        return view('checker_2.gsheet.settings', compact('settings'));
    }

    public function destroy($id)
    {
        $setting = Checker2Setting::findOrFail($id);
        $setting->delete();

        return redirect()->route('checker2.settings.index')->with('success', 'Deleted successfully.');
    }

    public function store(Request $request)
    {
        $request->validate([
            'sheet_url' => 'required|url',
            'sheet_range' => 'required|string',
        ]);

        $spreadsheetId = $this->extractSpreadsheetId($request->sheet_url);
        $sheetName = $this->getGsheetName($spreadsheetId);

        Checker2Setting::create([
            'sheet_url' => $request->sheet_url,
            'sheet_range' => $request->sheet_range,
            'gsheet_name' => $sheetName,
        ]);

        return redirect()->back()->with('success', 'Setting added!');
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'sheet_url' => 'required|url',
            'sheet_range' => 'required|string',
        ]);

        $spreadsheetId = $this->extractSpreadsheetId($request->sheet_url);
        $sheetName = $this->getGsheetName($spreadsheetId);

        $setting = Checker2Setting::findOrFail($id);
        $setting->sheet_url = $request->sheet_url;
        $setting->sheet_range = $request->sheet_range;
        $setting->gsheet_name = $sheetName;
        $setting->save();

        return redirect()->route('checker2.settings.index')->with('success', 'Setting updated successfully!');
    }

    public function deleteSetting($id)
    {
        Checker2Setting::where('id', $id)->delete();
        return redirect()->back()->with('success', 'Setting deleted successfully!');
    }

    private function extractSpreadsheetId($url)
    {
        preg_match('/\/d\/([a-zA-Z0-9-_]+)/', $url, $matches);
        return $matches[1] ?? null;
    }
    public function showImportPage()
{
    $settings = Checker2Setting::all();
    return view('checker_2.gsheet.import', compact('settings'));
}

    private function getGsheetName($spreadsheetId)
{
    try {
        $client = new Client();
        $client->setApplicationName('Laravel GSheet');
        $client->setScopes([Sheets::SPREADSHEETS_READONLY]);
        $client->setAuthConfig(storage_path('app/credentials.json'));
        $client->setAccessType('offline');

        $service = new Sheets($client);
        $spreadsheet = $service->spreadsheets->get($spreadsheetId);

        return $spreadsheet->getProperties()->getTitle(); // <-- Ito ang gsheet name
    } catch (\Exception $e) {
        logger('Failed to fetch gsheet name: ' . $e->getMessage());
        return null;
    }
}

}
