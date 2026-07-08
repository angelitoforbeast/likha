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

        // running/queued run (para sa Force-stop button)
        $running = MacroImportRun::whereIn('status', ['queued', 'running'])->latest('id')->first();

        return view('macro.gsheet.import', compact('settings', 'latestRun', 'running'));
    }

    // Cancel/Force-stop ang running/queued run.
    //  - BUHAY (may kamakailang progress): mag-request ng graceful cancel — hihinto
    //    ang job sa susunod na sheet (chine-check nito ang cancel_requested).
    //  - STALE/dead (walang progress nang matagal): i-fail agad — walang worker na
    //    mag-o-overwrite, kaya diretsong maaalis ang harang.
    public function cancelImport(Request $request)
    {
        $run = MacroImportRun::whereIn('status', ['queued', 'running'])->latest('id')->first();
        if (!$run) {
            return back()->with('success', 'Walang running/queued run na na-stop.');
        }

        // Palaging mag-request ng cancel (pipiliin ng job kung buhay ito)
        $run->cancel_requested = true;
        $run->save();

        // Stale? (walang update nang >10 min) → dead na, i-fail agad
        $stale = $run->updated_at && $run->updated_at->lt(now()->subMinutes(10));
        if ($stale) {
            $run->update(['status' => 'failed', 'finished_at' => now()]);
            MacroImportRunItem::where('run_id', $run->id)
                ->whereIn('status', ['queued', 'running', 'processing'])
                ->update(['status' => 'failed']);

            return back()->with('success', "🛑 Force-stopped ang stuck Run #{$run->id} (walang progress nang matagal). Pwede nang mag-import ulit.");
        }

        return back()->with('success', "🛑 Cancel requested para sa Run #{$run->id} — hihinto ito pagkatapos ng kasalukuyang sheet.");
    }

    public function import(Request $request)
    {
        try {
            // ✅ one run at a time (recommended)
            $running = MacroImportRun::whereIn('status', ['queued', 'running'])->latest('id')->first();
            if ($running) {
                return back()->with('error', "May running import pa (Run #{$running->id}). Hintayin muna matapos.");
            }

            // Skip archived settings — they stay configured but won't be imported.
            // Manage at /macro/gsheet/settings to archive/unarchive.
            $settings = MacroGsheetSetting::where('is_archived', false)->get();

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

    public function settings(Request $request)
    {
        // Default: hide archived (cleaner view). Toggle via ?show_archived=1.
        $showArchived = $request->boolean('show_archived');
        $settings = MacroGsheetSetting::query()
            ->when(!$showArchived, fn ($q) => $q->where('is_archived', false))
            ->orderBy('is_archived')
            ->orderBy('id')
            ->get();
        $archivedCount = MacroGsheetSetting::where('is_archived', true)->count();
        return view('macro.gsheet.settings', compact('settings', 'showArchived', 'archivedCount'));
    }

    public function archive($id)
    {
        $setting = MacroGsheetSetting::findOrFail($id);
        $setting->update(['is_archived' => true]);
        return back()->with('success', '📦 Sheet archived — will be skipped on import.');
    }

    public function unarchive($id)
    {
        $setting = MacroGsheetSetting::findOrFail($id);
        $setting->update(['is_archived' => false]);
        return back()->with('success', '↩️ Sheet unarchived — will be included on next import.');
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
