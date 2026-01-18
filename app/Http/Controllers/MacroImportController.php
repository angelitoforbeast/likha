<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\MacroGsheetSetting;
use App\Models\MacroImportRun;
use App\Jobs\ImportMacroFromGoogleSheet;

class MacroGsheetController extends Controller
{
    // =========================
    // SETTINGS PAGE
    // GET /macro/gsheet/settings
    // =========================
    public function settings()
    {
        $settings = MacroGsheetSetting::orderBy('id')->get();
        return view('macro.gsheet.settings', compact('settings'));
    }

    // POST /macro/gsheet/settings
    public function storeSetting(Request $request)
    {
        $data = $request->validate([
            'sheet_url'    => 'required|string|max:2000',
            'sheet_range'  => 'required|string|max:255',
            'gsheet_name'  => 'nullable|string|max:255',
        ]);

        MacroGsheetSetting::create($data);

        return redirect()->back()->with('success', 'Setting added.');
    }

    // PUT /macro/settings/{id}
    public function update(Request $request, $id)
    {
        $setting = MacroGsheetSetting::findOrFail($id);

        $data = $request->validate([
            'sheet_url'    => 'required|string|max:2000',
            'sheet_range'  => 'required|string|max:255',
            'gsheet_name'  => 'nullable|string|max:255',
        ]);

        $setting->update($data);

        return redirect()->back()->with('success', 'Setting updated.');
    }

    // DELETE /macro/gsheet/settings/{id}
    public function deleteSetting($id)
    {
        $setting = MacroGsheetSetting::findOrFail($id);
        $setting->delete();

        return redirect()->back()->with('success', 'Setting deleted.');
    }

    // =========================
    // IMPORT UI
    // GET /macro/gsheet/import
    // =========================
    public function showImport(Request $request)
    {
        $settings = MacroGsheetSetting::orderBy('id')->get();

        // ✅ Global last run attempt (success or fail)
        $lastAttemptRun = MacroImportRun::orderByDesc('id')->first();

        // ✅ Last successful run
        $lastSuccessRun = MacroImportRun::where('status', 'done')
            ->orderByDesc('finished_at')
            ->first();

        // ✅ Per-setting last imported (persist after refresh)
        $lastImportedMap = $this->buildLastImportedMap();

        return view('macro.gsheet.import', compact(
            'settings',
            'lastAttemptRun',
            'lastSuccessRun',
            'lastImportedMap'
        ));
    }

    // =========================
    // START IMPORT
    // POST /macro/gsheet/import
    // =========================
    public function import(Request $request)
    {
        $run = MacroImportRun::create([
            'status'     => 'queued',
            'message'    => 'Queued…',
            'started_at' => now(),
        ]);

        dispatch(new ImportMacroFromGoogleSheet($run->id, auth()->id()));

        // ✅ redirect to UI with run_id so polling starts
        return redirect()->route('macro.import.view', ['run_id' => $run->id])
            ->with('success', 'Import queued. Run #' . $run->id);
    }

    // =========================
    // STATUS ENDPOINT
    // GET /macro/gsheet/import/status?run_id=123
    // =========================
    public function status(Request $request)
    {
        $runId = (int) $request->query('run_id');
        if (!$runId) {
            return response()->json([
                'ok' => false,
                'message' => 'Missing run_id',
            ], 422);
        }

        $run = MacroImportRun::findOrFail($runId);

        // ✅ Load per-sheet items (MacroImportRunItem preferred; MacroImportRunSheet fallback)
        [$items, $totalSettings, $processedSettings] = $this->loadRunItems($runId, $run);

        return response()->json([
            'ok' => true,
            'run' => [
                'id' => $run->id,
                'status' => $run->status,
                'message' => $run->message,

                'total_settings' => $totalSettings,
                'processed_settings' => $processedSettings,

                'total_processed' => $run->total_processed ?? 0,
                'total_inserted'  => $run->total_inserted ?? 0,
                'total_updated'   => $run->total_updated ?? 0,

                'started_at'  => optional($run->started_at ?? $run->created_at)->toDateTimeString(),
                'finished_at' => optional($run->finished_at)->toDateTimeString(),
            ],
            'items' => $items,
        ]);
    }

    // =========================
    // OPTIONAL: INDEX + DELETE ALL
    // (keep if your routes point here)
    // =========================
    public function index()
    {
        // your existing index view (macro_output editor) if needed
        return view('macro.gsheet.index');
    }

    public function deleteAll()
    {
        // implement if you already have it
        // Example: DB::table('macro_output')->truncate();
        return redirect()->back()->with('success', 'Deleted all.');
    }

    // =========================
    // INTERNAL HELPERS
    // =========================

    private function buildLastImportedMap(): array
    {
        // Prefer MacroImportRunItem if exists
        if (class_exists(\App\Models\MacroImportRunItem::class)) {
            $Model = \App\Models\MacroImportRunItem::class;

            // finished_at column
            $q = $Model::query()
                ->where('status', 'done')
                ->whereNotNull('finished_at')
                ->select('setting_id', DB::raw('MAX(finished_at) as last_success_at'))
                ->groupBy('setting_id')
                ->pluck('last_success_at', 'setting_id')
                ->toArray();

            // stringify timestamps
            foreach ($q as $k => $v) {
                $q[$k] = is_string($v) ? $v : (optional($v)->toDateTimeString() ?? (string)$v);
            }
            return $q;
        }

        // Fallback MacroImportRunSheet
        if (class_exists(\App\Models\MacroImportRunSheet::class)) {
            $Model = \App\Models\MacroImportRunSheet::class;

            $q = $Model::query()
                ->where('status', 'done')
                ->whereNotNull('finished_at')
                ->select('setting_id', DB::raw('MAX(finished_at) as last_success_at'))
                ->groupBy('setting_id')
                ->pluck('last_success_at', 'setting_id')
                ->toArray();

            foreach ($q as $k => $v) {
                $q[$k] = is_string($v) ? $v : (optional($v)->toDateTimeString() ?? (string)$v);
            }
            return $q;
        }

        return [];
    }

    private function loadRunItems(int $runId, MacroImportRun $run): array
    {
        $items = [];
        $totalSettings = (int) ($run->total_settings ?? 0);
        $processedSettings = 0;

        // Prefer MacroImportRunItem
        if (class_exists(\App\Models\MacroImportRunItem::class)) {
            $Model = \App\Models\MacroImportRunItem::class;

            $rows = $Model::where('run_id', $runId)->get();

            $items = $rows->map(function ($rs) {
                $finishedAt = $rs->finished_at ? optional($rs->finished_at)->toDateTimeString() : null;

                return [
                    'setting_id' => $rs->setting_id,
                    'status'     => $rs->status,

                    // tolerate column name variants
                    'processed'  => $rs->processed_count ?? $rs->processed ?? 0,
                    'inserted'   => $rs->inserted_count ?? $rs->inserted ?? 0,
                    'updated'    => $rs->updated_count ?? $rs->updated ?? 0,
                    'skipped'    => $rs->skipped_count ?? $rs->skipped ?? 0,

                    'message'    => $rs->message,
                    'finished_at'=> $finishedAt,
                ];
            })->values()->all();

            $processedSettings = collect($items)->filter(function ($it) {
                $s = strtolower($it['status'] ?? '');
                return in_array($s, ['done', 'failed'], true);
            })->count();

            if ($totalSettings === 0) $totalSettings = count($items);

            return [$items, $totalSettings, $processedSettings];
        }

        // Fallback MacroImportRunSheet
        if (class_exists(\App\Models\MacroImportRunSheet::class)) {
            $Model = \App\Models\MacroImportRunSheet::class;

            $rows = $Model::where('run_id', $runId)->get();

            $items = $rows->map(function ($rs) {
                $finishedAt = $rs->finished_at ? optional($rs->finished_at)->toDateTimeString() : null;

                return [
                    'setting_id' => $rs->setting_id,
                    'status'     => $rs->status,
                    'processed'  => $rs->processed_count ?? $rs->processed ?? 0,
                    'inserted'   => $rs->inserted_count ?? $rs->inserted ?? 0,
                    'updated'    => $rs->updated_count ?? $rs->updated ?? 0,
                    'skipped'    => $rs->skipped_count ?? $rs->skipped ?? 0,
                    'message'    => $rs->message,
                    'finished_at'=> $finishedAt,
                ];
            })->values()->all();

            $processedSettings = collect($items)->filter(function ($it) {
                $s = strtolower($it['status'] ?? '');
                return in_array($s, ['done', 'failed'], true);
            })->count();

            if ($totalSettings === 0) $totalSettings = count($items);

            return [$items, $totalSettings, $processedSettings];
        }

        return [$items, $totalSettings, $processedSettings];
    }
}
