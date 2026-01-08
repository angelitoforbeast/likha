<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\MacroGsheetSetting;
use App\Models\MacroOutput;
use App\Models\MacroImportRun;
use App\Models\MacroImportRunItem;
use App\Jobs\ImportMacroFromGoogleSheet;

class MacroGsheetController extends Controller
{
    public function showImport()
    {
        $settings = MacroGsheetSetting::all();

        // Optional: show latest run
        $latestRun = MacroImportRun::latest('id')->first();

        return view('macro.gsheet.import', compact('settings', 'latestRun'));
    }

    public function import(Request $request)
    {
        try {
            // ✅ one run at a time (recommended)
            $running = MacroImportRun::whereIn('status', ['queued', 'running'])->latest('id')->first();
            if ($running) {
                return back()->with('error', "May running import pa (Run #{$running->id}). Hintayin muna matapos.");
            }

            $settings = MacroGsheetSetting::all();

            $run = MacroImportRun::create([
                'started_by'        => auth()->id(),
                'status'            => 'queued',
                'started_at'        => now(),
                'total_settings'    => $settings->count(),
                'processed_settings'=> 0,
                'total_processed'   => 0,
                'total_inserted'    => 0,
                'total_updated'     => 0,
                'total_skipped'     => 0,
                'message'           => null,
            ]);

            // Create per-setting items snapshot
            foreach ($settings as $s) {
                MacroImportRunItem::create([
                    'run_id'      => $run->id,
                    'setting_id'  => $s->id,
                    'gsheet_name' => $s->gsheet_name,
                    'sheet_url'   => $s->sheet_url,
                    'sheet_range' => $s->sheet_range,
                    'status'      => 'queued',
                    'processed'   => 0,
                    'inserted'    => 0,
                    'updated'     => 0,
                    'skipped'     => 0,
                    'message'     => null,
                    'started_at'  => null,
                    'finished_at' => null,
                ]);
            }

            // Dispatch job with run id
            ImportMacroFromGoogleSheet::dispatch($run->id, auth()->id());

            // Redirect back w/ run id so UI can poll
            return redirect()
                ->route('macro.import.view', ['run_id' => $run->id])
                ->with('success', "⏳ Import started (Run #{$run->id}).");

        } catch (\Throwable $e) {
            Log::error("❌ Failed to dispatch macro import job: ".$e->getMessage());
            return back()->with('error', '❌ Failed to dispatch import job: ' . $e->getMessage());
        }
    }

    // ✅ Polling endpoint
    public function status(Request $request)
    {
        $runId = $request->query('run_id');
        if (!$runId) {
            return response()->json(['ok' => false, 'message' => 'Missing run_id'], 400);
        }

        $run = MacroImportRun::find($runId);
        if (!$run) {
            return response()->json(['ok' => false, 'message' => 'Run not found'], 404);
        }

        $items = MacroImportRunItem::where('run_id', $runId)
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'ok' => true,
            'run' => [
                'id'                => $run->id,
                'status'            => $run->status,
                'started_at'        => optional($run->started_at)->toDateTimeString(),
                'finished_at'       => optional($run->finished_at)->toDateTimeString(),
                'total_settings'    => $run->total_settings,
                'processed_settings'=> $run->processed_settings,
                'total_processed'   => $run->total_processed,
                'total_inserted'    => $run->total_inserted,
                'total_updated'     => $run->total_updated,
                'total_skipped'     => $run->total_skipped,
                'message'           => $run->message,
            ],
            'items' => $items->map(function ($it) {
                return [
                    'id'         => $it->id,
                    'setting_id' => $it->setting_id,
                    'gsheet_name'=> $it->gsheet_name,
                    'sheet_url'  => $it->sheet_url,
                    'sheet_range'=> $it->sheet_range,
                    'status'     => $it->status,
                    'processed'  => (int)$it->processed,
                    'inserted'   => (int)$it->inserted,
                    'updated'    => (int)$it->updated,
                    'skipped'    => (int)$it->skipped,
                    'message'    => $it->message,
                ];
            }),
        ]);
    }

    // =======================
    // Existing methods (kept)
    // =======================

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
