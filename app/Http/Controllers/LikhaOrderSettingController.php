<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\LikhaOrderSetting;
use Google_Client;
use Google_Service_Sheets;

class LikhaOrderSettingController extends Controller
{
    public function settings()
    {
        $settings = LikhaOrderSetting::orderBy('id')->get();
        return view('likha_order.import_settings', compact('settings'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'sheet_url' => 'required|string',
            'range' => 'required|string',
        ]);

        [$sheetId, $title] = $this->extractAndFetchTitle($request->sheet_url);

        LikhaOrderSetting::create([
            'sheet_url' => $request->sheet_url,
            'sheet_id' => $sheetId,
            'spreadsheet_title' => $title,
            'range' => $request->range,
        ]);

        return redirect()->back()->with('status', '✅ Sheet setting added.');
    }

    public function update(Request $request, $id)
    {
        $setting = LikhaOrderSetting::findOrFail($id);

        $request->validate([
            'sheet_url' => 'required|string',
            'range' => 'required|string',
        ]);

        [$sheetId, $title] = $this->extractAndFetchTitle($request->sheet_url);

        $setting->update([
            'sheet_url' => $request->sheet_url,
            'sheet_id' => $sheetId,
            'spreadsheet_title' => $title,
            'range' => $request->range,
        ]);

        return redirect()->back()->with('status', '✅ Sheet setting updated.');
    }

    public function destroy($id)
    {
        $setting = LikhaOrderSetting::findOrFail($id);
        $setting->delete();

        return redirect()->back()->with('status', '🗑️ Sheet setting deleted.');
    }

    private function extractAndFetchTitle(string $url): array
    {
        $sheetId = $this->extractSheetIdFromUrl($url);
        if (!$sheetId) {
            // fallback: maybe user pasted the raw id
            $sheetId = trim($url);
        }

        $title = null;
        try {
            $client = new Google_Client();
            $client->setAuthConfig(storage_path('app/credentials.json'));
            $client->addScope(Google_Service_Sheets::SPREADSHEETS_READONLY);

            $service = new Google_Service_Sheets($client);
            $spreadsheet = $service->spreadsheets->get($sheetId);
            $title = $spreadsheet->getProperties()->getTitle();
        } catch (\Throwable $e) {
            // If title fetch fails, still save the setting; show placeholder
            $title = 'Unknown Spreadsheet (check API/permissions)';
        }

        return [$sheetId, $title];
    }

    private function extractSheetIdFromUrl(string $url): ?string
    {
        // Typical format: https://docs.google.com/spreadsheets/d/{SHEET_ID}/edit#gid=0
        if (preg_match('~/spreadsheets/d/([a-zA-Z0-9-_]+)~', $url, $m)) {
            return $m[1];
        }
        return null;
    }
}
