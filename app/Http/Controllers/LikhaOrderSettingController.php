<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\LikhaOrderSetting;
use Google_Client;
use Google_Service_Sheets;

class LikhaOrderSettingController extends Controller
{
    public function settings(Request $request)
    {
        // Default: hide archived (cleaner view). User can toggle via ?show_archived=1.
        $showArchived = $request->boolean('show_archived');
        $settings = LikhaOrderSetting::query()
            ->when(!$showArchived, fn ($q) => $q->where('is_archived', false))
            // Archived rows sort to the bottom when shown.
            ->orderBy('is_archived')
            ->orderBy('id')
            ->get();
        $archivedCount = LikhaOrderSetting::where('is_archived', true)->count();
        return view('likha_order.import_settings', compact('settings', 'showArchived', 'archivedCount'));
    }

    public function archive($id)
    {
        $setting = LikhaOrderSetting::findOrFail($id);
        $setting->update(['is_archived' => true]);
        return redirect()->back()->with('status', '📦 Sheet archived — will be skipped on import.');
    }

    public function unarchive($id)
    {
        $setting = LikhaOrderSetting::findOrFail($id);
        $setting->update(['is_archived' => false]);
        return redirect()->back()->with('status', '↩️ Sheet unarchived — will be included on next import.');
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
