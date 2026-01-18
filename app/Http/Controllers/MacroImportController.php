<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\MacroGsheetSetting;
use App\Models\MacroImportRun;
use App\Models\MacroImportRunSheet; // ✅ if meron ka nito (recommended)
use App\Jobs\ImportMacroFromGoogleSheet;

class MacroImportController extends Controller
{
    // GET /macro/gsheet/import
    public function index()
    {
        $settings = MacroGsheetSetting::orderBy('id')->get();

        // ✅ Global last import info
        $lastAttemptRun = MacroImportRun::orderByDesc('id')->first();

        $lastSuccessRun = MacroImportRun::where('status', 'done')
            ->orderByDesc('finished_at')
            ->first();

        // ✅ Per-setting last imported map (if you have MacroImportRunSheet table)
        // If you don't have MacroImportRunSheet, set empty map and only show global.
        $lastImportedMap = [];
        if (class_exists(\App\Models\MacroImportRunSheet::class)) {
            $lastImportedMap = MacroImportRunSheet::query()
                ->where('status', 'done')
                ->whereNotNull('finished_at')
                ->select('setting_id', DB::raw('MAX(finished_at) as last_success_at'))
                ->groupBy('setting_id')
                ->pluck('last_success_at', 'setting_id')
                ->toArray();
        }

        return view('macro.gsheet.import', compact(
            'settings',
            'lastAttemptRun',
            'lastSuccessRun',
            'lastImportedMap'
        ));
    }

    // POST /macro/gsheet/import/start
    public function start(Request $request)
    {
        $run = MacroImportRun::create([
            'status' => 'queued',
            'message' => 'Queued…',
            'started_at' => now(),
        ]);

        dispatch(new ImportMacroFromGoogleSheet($run->id));

        return response()->json([
            'ok' => true,
            'run_id' => $run->id, // ✅ make consistent with JS
        ]);
    }

    // GET /macro/gsheet/import/status?run_id=123
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

        // ✅ If you have per-sheet run items, return them for table updates
        $items = [];
        if (class_exists(\App\Models\MacroImportRunSheet::class)) {
            $items = MacroImportRunSheet::where('run_id', $runId)
                ->get()
                ->map(function ($rs) {
                    return [
                        'setting_id' => $rs->setting_id,
                        'status' => $rs->status,
                        'processed' => $rs->processed_count ?? 0,
                        'inserted' => $rs->inserted_count ?? 0,
                        'updated' => $rs->updated_count ?? 0,
                        'skipped' => $rs->skipped_count ?? 0,
                        'message' => $rs->message,
                        'finished_at' => optional($rs->finished_at)->toDateTimeString(),
                    ];
                })
                ->values();
        }

        // ✅ processed_settings for run summary (if items exist)
        $processedSettings = 0;
        $totalSettings = $run->total_settings ?? null;

        if (!empty($items)) {
            $processedSettings = collect($items)->filter(function ($it) {
                $s = strtolower($it['status'] ?? '');
                return in_array($s, ['done', 'failed'], true);
            })->count();

            if ($totalSettings === null) {
                $totalSettings = count($items);
            }
        }

        return response()->json([
            'ok' => true,
            'run' => [
                'id' => $run->id,
                'status' => $run->status,
                'message' => $run->message,
                'total_settings' => $totalSettings ?? 0,
                'processed_settings' => $processedSettings,

                'total_processed' => $run->total_processed ?? 0,
                'total_inserted' => $run->total_inserted ?? 0,
                'total_updated' => $run->total_updated ?? 0,

                // show readable times
                'started_at' => optional($run->started_at ?? $run->created_at)->toDateTimeString(),
                'finished_at' => optional($run->finished_at)->toDateTimeString(),
            ],
            'items' => $items,
        ]);
    }
}
