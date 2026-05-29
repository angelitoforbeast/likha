<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Jobs\ImportCancelDetectorFromGoogleSheet;
use App\Models\CancelDetector;
use App\Models\CancelDetectorSetting;
use App\Models\CancelDetectorRun;
use App\Models\CancelDetectorRunSheet;

/**
 * /conversation/cancel-detector — Phase 1: upload-only.
 *
 * Pulls 5 columns (page_name, name, phone_number, shop_details, conversation)
 * from configured Google Sheets and stores them sa cancel_detectors table.
 * AI classification (ai_analysis field) is left null — Phase 2 job will
 * fill it asynchronously by sending each conversation to OpenAI.
 */
class CancelDetectorImportController extends Controller
{
    /** GET /conversation/cancel-detector — index page with sheet list + last-run summary. */
    public function index(Request $request)
    {
        // Skip archived settings — kept for reference pero hindi na polled.
        $settings = CancelDetectorSetting::where('is_archived', false)
            ->orderBy('id')->get();

        $lastAttemptRun = CancelDetectorRun::orderByDesc('id')->first();
        $lastSuccessRun = CancelDetectorRun::where('status', 'done')
            ->orderByDesc('finished_at')
            ->first();

        // Per-setting last-success timestamp for "Last Imported" column in UI.
        $lastImportedMap = CancelDetectorRunSheet::query()
            ->where('status', 'done')
            ->whereNotNull('finished_at')
            ->where('processed_count', '>', 0)
            ->select('setting_id', DB::raw('MAX(finished_at) as last_success_at'))
            ->groupBy('setting_id')
            ->pluck('last_success_at', 'setting_id')
            ->toArray();

        return view('cancel_detector.import', compact(
            'settings',
            'lastAttemptRun',
            'lastSuccessRun',
            'lastImportedMap'
        ));
    }

    /** AJAX POST /conversation/cancel-detector/start — dispatch the import job. */
    public function start(Request $request)
    {
        $settings = CancelDetectorSetting::where('is_archived', false)
            ->orderBy('id')->get();
        if ($settings->isEmpty()) {
            return response()->json([
                'ok'    => false,
                'error' => 'No active settings configured. Add one sa /conversation/cancel-detector/settings.',
            ], 422);
        }

        $run = CancelDetectorRun::create([
            'status'         => 'running',
            'total_settings' => $settings->count(),
            'started_at'     => now(),
        ]);

        foreach ($settings as $s) {
            CancelDetectorRunSheet::create([
                'run_id'     => $run->id,
                'setting_id' => $s->id,
                'status'     => 'queued',
            ]);
        }

        ImportCancelDetectorFromGoogleSheet::dispatch($run->id);

        return response()->json(['ok' => true, 'run_id' => $run->id]);
    }

    /** AJAX GET /conversation/cancel-detector/status?run_id=X — polling endpoint. */
    public function status(Request $request)
    {
        $runId = (int) $request->query('run_id');
        $run   = CancelDetectorRun::with(['sheets.setting'])->findOrFail($runId);

        return response()->json([
            'run' => [
                'id'              => $run->id,
                'status'          => $run->status,
                'total_settings'  => $run->total_settings,
                'total_processed' => $run->total_processed,
                'total_inserted'  => $run->total_inserted,
                'total_updated'   => $run->total_updated,
                'total_skipped'   => $run->total_skipped,
                'total_failed'    => $run->total_failed,
                'message'         => $run->message,
                'started_at'      => optional($run->started_at)->toDateTimeString(),
                'finished_at'     => optional($run->finished_at)->toDateTimeString(),
            ],
            'sheets' => $run->sheets->map(function ($rs) {
                return [
                    'setting_id'          => $rs->setting_id,
                    'status'              => $rs->status,
                    'processed'           => $rs->processed_count,
                    'inserted'            => $rs->inserted_count,
                    'updated'             => $rs->updated_count,
                    'skipped'             => $rs->skipped_count,
                    'message'             => $rs->message,
                    'finished_at'         => optional($rs->finished_at)->toDateTimeString(),
                    'spreadsheet_title'   => $rs->setting?->spreadsheet_title,
                    'sheet_url'           => $rs->setting?->sheet_url,
                    'selected_sheet_name' => $rs->setting?->selected_sheet_name,
                    'range'               => $rs->setting?->range,
                ];
            }),
        ]);
    }

    /**
     * GET / DELETE /conversation/cancel-detector/view — paginated data table.
     * DELETE without ID = truncate all rows.
     */
    public function view(Request $request)
    {
        if ($request->isMethod('delete')) {
            CancelDetector::truncate();
            return redirect('/conversation/cancel-detector/view')
                ->with('status', '🗑️ All records deleted.');
        }

        $query = CancelDetector::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%$search%")
                  ->orWhere('phone_number', 'like', "%$search%")
                  ->orWhere('page_name', 'like', "%$search%")
                  ->orWhere('shop_details', 'like', "%$search%")
                  ->orWhere('conversation', 'like', "%$search%");
            });
        }

        if ($request->filled('page_name')) {
            $query->where('page_name', $request->input('page_name'));
        }

        // ai_analysis filter — 'cancel', 'not_cancel', 'unknown', 'pending' (NULL).
        if ($request->filled('ai_analysis')) {
            $val = $request->input('ai_analysis');
            if ($val === 'pending') {
                $query->whereNull('ai_analysis');
            } else {
                $query->where('ai_analysis', $val);
            }
        }

        $rows  = $query->latest('id')->paginate(100)->appends($request->query());
        $pages = CancelDetector::select('page_name')
            ->whereNotNull('page_name')->distinct()->orderBy('page_name')->pluck('page_name');

        return view('cancel_detector.view', compact('rows', 'pages'));
    }

    /** DELETE /conversation/cancel-detector/view/{id} — delete one row. */
    public function destroyRow($id)
    {
        $row = CancelDetector::findOrFail($id);
        $row->delete();
        return redirect()->back()->with('status', "🗑️ Row #{$id} deleted.");
    }
}
