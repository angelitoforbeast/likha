<?php

namespace App\Http\Controllers;

use App\Jobs\ImportBotcakePsidFromGoogleSheet;
use App\Models\BotcakePsidSetting;
use Google\Client as GoogleClient;
use Google\Service\Sheets;
use Illuminate\Http\Request;

class BotcakePsidGsheetController extends Controller
{
    // ========== IMPORT PAGES / ACTIONS ==========

    /**
     * GET /botcake/psid/import
     * Ipapakita yung import page + cutoff datetime form.
     */
    public function showImport()
    {
        $settings = BotcakePsidSetting::all();

        return view('botcake.psid.import', [
            'settings' => $settings,
        ]);
    }

    /**
     * POST /botcake/psid/import/run
     * Tatakbo yung Job, optional cutoff datetime.
     */
    public function import(Request $request)
    {
        // optional lang, pwede blank
        $request->validate([
            'cutoff_datetime' => ['nullable', 'date'],
        ]);

        $cutoff = $request->input('cutoff_datetime'); // string or null

        try {
            // Job na may optional cutoff (On or Before yung Column D)
            dispatch(new ImportBotcakePsidFromGoogleSheet($cutoff));

            return back()->with(
                'success',
                '⏳ PSID import started. Please wait a few moments.'
            );
        } catch (\Exception $e) {
            return back()->with(
                'error',
                '❌ Failed to dispatch PSID import job: ' . $e->getMessage()
            );
        }
    }

    // ========== SETTINGS (same pattern as MacroGsheetController) ==========

    private function extractSpreadsheetId($url)
    {
        preg_match('/\/d\/([a-zA-Z0-9-_]+)/', $url, $matches);
        return $matches[1] ?? null;
    }

    /**
     * GET /botcake/psid/settings
     */
    public function settings()
    {
        $settings = BotcakePsidSetting::all();
        return view('botcake.psid.settings', compact('settings'));
    }

    /**
     * POST /botcake/psid/settings
     * Create new setting row.
     */
    public function storeSetting(Request $request)
    {
        $request->validate([
            'sheet_url'   => 'required|url',
            'sheet_range' => 'required|string',
        ]);

        $sheetId = $this->extractSpreadsheetId($request->sheet_url);
        if (!$sheetId) {
            return back()->with('error', 'Invalid Google Sheet URL.');
        }

        // Fetch actual sheet title
        try {
            $client = new GoogleClient();
            $client->setApplicationName('Laravel Botcake PSID');
            $client->setScopes([Sheets::SPREADSHEETS_READONLY]);
            $client->setAuthConfig(storage_path('app/credentials.json'));
            $client->setAccessType('offline');

            $service    = new Sheets($client);
            $sheetMeta  = $service->spreadsheets->get($sheetId);
            $actualName = $sheetMeta->getProperties()->getTitle();
        } catch (\Exception $e) {
            return back()->with(
                'error',
                'Failed to fetch sheet name: ' . $e->getMessage()
            );
        }

        BotcakePsidSetting::create([
            'gsheet_name' => $actualName,
            'sheet_url'   => $request->sheet_url,
            'sheet_range' => $request->sheet_range,
        ]);

        return back()->with(
            'success',
            'PSID setting saved and sheet name synced.'
        );
    }

    /**
     * POST /botcake/psid/settings/{id}
     * Update existing setting row.
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'sheet_url'   => 'required|url',
            'sheet_range' => 'required|string',
        ]);

        $sheetId = $this->extractSpreadsheetId($request->sheet_url);
        if (!$sheetId) {
            return back()->with('error', 'Invalid Google Sheet URL.');
        }

        try {
            $client = new GoogleClient();
            $client->setApplicationName('Laravel Botcake PSID');
            $client->setScopes([Sheets::SPREADSHEETS_READONLY]);
            $client->setAuthConfig(storage_path('app/credentials.json'));
            $client->setAccessType('offline');

            $service    = new Sheets($client);
            $sheetMeta  = $service->spreadsheets->get($sheetId);
            $actualName = $sheetMeta->getProperties()->getTitle();
        } catch (\Exception $e) {
            return back()->with(
                'error',
                'Failed to fetch sheet name: ' . $e->getMessage()
            );
        }

        $setting = BotcakePsidSetting::findOrFail($id);
        $setting->update([
            'gsheet_name' => $actualName,
            'sheet_url'   => $request->sheet_url,
            'sheet_range' => $request->sheet_range,
        ]);

        return back()->with(
            'success',
            'PSID setting updated and name synced from GSheet.'
        );
    }

    /**
     * POST /botcake/psid/settings/{id}/delete
     */
    public function deleteSetting($id)
    {
        BotcakePsidSetting::findOrFail($id)->delete();
        return back()->with('success', 'PSID setting deleted.');
    }
}
