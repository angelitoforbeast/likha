<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CancelDetectorSetting;
use Google_Client;
use Google_Service_Sheets;
use Illuminate\Support\Facades\Log;

/**
 * Settings CRUD para sa /conversation/cancel-detector sheet sources.
 * Mirror ng ConversationTrackerSettingController but for the cancel
 * detector feature + adds archive/unarchive support.
 */
class CancelDetectorSettingController extends Controller
{
    public function settings()
    {
        $settings = CancelDetectorSetting::orderBy('id')->get();
        return view('cancel_detector.settings', compact('settings'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'sheet_url'           => 'required|string',
            'selected_sheet_name' => 'required|string',
            'range'               => 'nullable|string',
        ]);

        [$sheetId, $title] = $this->extractAndFetchTitle($request->sheet_url);

        CancelDetectorSetting::create([
            'sheet_url'           => $request->sheet_url,
            'sheet_id'            => $sheetId,
            'spreadsheet_title'   => $title,
            'selected_sheet_name' => trim($request->selected_sheet_name),
            'range'               => trim($request->range) ?: 'A2:N',
            'is_archived'         => false,
        ]);

        return redirect()->back()->with('status', '✅ Sheet setting added.');
    }

    public function update(Request $request, $id)
    {
        $setting = CancelDetectorSetting::findOrFail($id);

        $request->validate([
            'sheet_url'           => 'required|string',
            'selected_sheet_name' => 'required|string',
            'range'               => 'nullable|string',
            'is_archived'         => 'nullable|boolean',
        ]);

        [$sheetId, $title] = $this->extractAndFetchTitle($request->sheet_url);

        $setting->update([
            'sheet_url'           => $request->sheet_url,
            'sheet_id'            => $sheetId,
            'spreadsheet_title'   => $title,
            'selected_sheet_name' => trim($request->selected_sheet_name),
            'range'               => trim($request->range) ?: 'A2:N',
            'is_archived'         => (bool) $request->input('is_archived', $setting->is_archived),
        ]);

        return redirect()->back()->with('status', '✅ Sheet setting updated.');
    }

    public function destroy($id)
    {
        $setting = CancelDetectorSetting::findOrFail($id);
        $setting->delete();
        return redirect()->back()->with('status', '🗑️ Sheet setting deleted.');
    }

    /**
     * POST /conversation/cancel-detector/settings/{id}/toggle-archive
     * Quick toggle without going through the full edit form.
     */
    public function toggleArchive($id)
    {
        $setting = CancelDetectorSetting::findOrFail($id);
        $setting->update(['is_archived' => !$setting->is_archived]);
        $label = $setting->is_archived ? 'archived' : 'unarchived';
        return redirect()->back()->with('status', "✅ Setting #{$id} {$label}.");
    }

    /**
     * GET /conversation/cancel-detector/settings/fetch-sheets?sheet_url=...
     * Returns: { ok: true, sheet_id, title, sheets: [name, name, ...] }
     */
    public function fetchSheetTabs(Request $request)
    {
        $url = (string) $request->query('sheet_url', '');
        if ($url === '') {
            return response()->json(['ok' => false, 'error' => 'sheet_url is required'], 400);
        }

        $sheetId = $this->extractSheetIdFromUrl($url) ?: trim($url);
        if (!$sheetId) {
            return response()->json(['ok' => false, 'error' => 'Could not extract sheet_id from URL'], 400);
        }

        try {
            $client = new Google_Client();
            $client->setAuthConfig(storage_path('app/credentials.json'));
            $client->addScope(Google_Service_Sheets::SPREADSHEETS_READONLY);

            $service = new Google_Service_Sheets($client);
            $spreadsheet = $service->spreadsheets->get($sheetId);

            $title = $spreadsheet->getProperties()->getTitle();
            $tabNames = [];
            foreach ($spreadsheet->getSheets() as $tab) {
                $tabNames[] = $tab->getProperties()->getTitle();
            }

            return response()->json([
                'ok'       => true,
                'sheet_id' => $sheetId,
                'title'    => $title,
                'sheets'   => $tabNames,
            ]);
        } catch (\Throwable $e) {
            Log::error('fetchSheetTabs error: ' . $e->getMessage());
            return response()->json([
                'ok'    => false,
                'error' => 'Could not load sheet — check that the sheet is shared with the service account.',
                'detail'=> $e->getMessage(),
            ], 500);
        }
    }

    private function extractAndFetchTitle(string $url): array
    {
        $sheetId = $this->extractSheetIdFromUrl($url) ?: trim($url);

        $title = null;
        try {
            $client = new Google_Client();
            $client->setAuthConfig(storage_path('app/credentials.json'));
            $client->addScope(Google_Service_Sheets::SPREADSHEETS_READONLY);

            $service = new Google_Service_Sheets($client);
            $spreadsheet = $service->spreadsheets->get($sheetId);
            $title = $spreadsheet->getProperties()->getTitle();
        } catch (\Throwable $e) {
            $title = 'Unknown Spreadsheet (check API/permissions)';
        }

        return [$sheetId, $title];
    }

    private function extractSheetIdFromUrl(string $url): ?string
    {
        if (preg_match('~/spreadsheets/d/([a-zA-Z0-9-_]+)~', $url, $m)) {
            return $m[1];
        }
        return null;
    }
}
