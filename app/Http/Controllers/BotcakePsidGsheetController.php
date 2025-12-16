<?php

namespace App\Http\Controllers;

use App\Jobs\ImportBotcakePsidFromGoogleSheet;
use App\Models\BotcakePsidImportRun;
use App\Models\BotcakePsidSetting;
use Google\Client as GoogleClient;
use Google\Service\Sheets;
use Illuminate\Http\Request;

class BotcakePsidGsheetController extends Controller
{
    public function showImport()
    {
        $settings = BotcakePsidSetting::all();

        // If user refreshed, we can show latest run (optional)
        $latestRun = BotcakePsidImportRun::latest('id')->first();

        return view('botcake.psid.import', [
            'settings'  => $settings,
            'latestRun' => $latestRun,
        ]);
    }

    public function import(Request $request)
    {
        $request->validate([
            'cutoff_datetime' => ['nullable', 'date'],
        ]);

        $cutoff = $request->input('cutoff_datetime');

        try {
            // create run row for UI tracking
            $run = BotcakePsidImportRun::create([
                'status'         => 'running',
                'cutoff_datetime'=> $cutoff,
                'last_message'   => 'Queued...',
            ]);

            dispatch(
                (new ImportBotcakePsidFromGoogleSheet($cutoff, $run->id))
                    ->onConnection('database')
                    ->onQueue('default')
            );

            return redirect()
                ->back()
                ->with('success', '⏳ PSID import started. Live status is shown below.')
                ->with('run_id', $run->id);

        } catch (\Exception $e) {
            return back()->with('error', '❌ Failed to dispatch PSID import job: ' . $e->getMessage());
        }
    }

    /**
     * GET /botcake/psid/import/status/{runId}
     * JSON endpoint for polling UI.
     */
    public function status($runId)
    {
        $run = BotcakePsidImportRun::with(['events' => function ($q) {
            $q->latest('id')->limit(30);
        }])->findOrFail($runId);

        return response()->json([
            'id'               => $run->id,
            'status'           => $run->status,
            'cutoff_datetime'  => $run->cutoff_datetime,
            'k1_value'         => $run->k1_value,
            'seed_row'         => $run->seed_row,
            'selected_start_row'=> $run->selected_start_row,
            'batch_no'         => $run->batch_no,
            'batch_start_row'  => $run->batch_start_row,
            'batch_end_row'    => $run->batch_end_row,
            'next_scan_from'   => $run->next_scan_from,
            'total_imported'   => $run->total_imported,
            'total_not_existing'=> $run->total_not_existing,
            'total_skipped'    => $run->total_skipped,
            'current_setting_id'=> $run->current_setting_id,
            'current_gsheet_name'=> $run->current_gsheet_name,
            'current_sheet_name'=> $run->current_sheet_name,
            'last_message'     => $run->last_message,
            'last_error'       => $run->last_error,
            'updated_at'       => optional($run->updated_at)->toDateTimeString(),
            'events'           => $run->events->map(function ($e) {
                return [
                    'type'        => $e->type,
                    'batch_no'    => $e->batch_no,
                    'start_row'   => $e->start_row,
                    'end_row'     => $e->end_row,
                    'rows'        => $e->rows_in_batch,
                    'imported'    => $e->imported,
                    'not_existing'=> $e->not_existing,
                    'skipped'     => $e->skipped,
                    'gsheet_name' => $e->gsheet_name,
                    'sheet_name'  => $e->sheet_name,
                    'message'     => $e->message,
                    'time'        => optional($e->created_at)->toDateTimeString(),
                ];
            })->values(),
        ]);
    }

    // ===== settings methods (same as your existing) =====

    private function extractSpreadsheetId($url)
    {
        preg_match('/\/d\/([a-zA-Z0-9-_]+)/', $url, $matches);
        return $matches[1] ?? null;
    }

    public function settings()
    {
        $settings = BotcakePsidSetting::all();
        return view('botcake.psid.settings', compact('settings'));
    }

    public function storeSetting(Request $request)
    {
        $request->validate([
            'sheet_url'   => 'required|url',
            'sheet_range' => 'required|string',
        ]);

        $sheetId = $this->extractSpreadsheetId($request->sheet_url);
        if (!$sheetId) return back()->with('error', 'Invalid Google Sheet URL.');

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
            return back()->with('error', 'Failed to fetch sheet name: ' . $e->getMessage());
        }

        BotcakePsidSetting::create([
            'gsheet_name' => $actualName,
            'sheet_url'   => $request->sheet_url,
            'sheet_range' => $request->sheet_range,
        ]);

        return back()->with('success', 'PSID setting saved and sheet name synced.');
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'sheet_url'   => 'required|url',
            'sheet_range' => 'required|string',
        ]);

        $sheetId = $this->extractSpreadsheetId($request->sheet_url);
        if (!$sheetId) return back()->with('error', 'Invalid Google Sheet URL.');

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
            return back()->with('error', 'Failed to fetch sheet name: ' . $e->getMessage());
        }

        $setting = BotcakePsidSetting::findOrFail($id);
        $setting->update([
            'gsheet_name' => $actualName,
            'sheet_url'   => $request->sheet_url,
            'sheet_range' => $request->sheet_range,
        ]);

        return back()->with('success', 'PSID setting updated and name synced from GSheet.');
    }

    public function deleteSetting($id)
    {
        BotcakePsidSetting::findOrFail($id)->delete();
        return back()->with('success', 'PSID setting deleted.');
    }
}
