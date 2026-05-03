<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Jobs\ImportConversationTrackerFromGoogleSheet;
use App\Models\ConversationTracker;
use App\Models\ConversationTrackerSetting;
use App\Models\ConversationTrackerRun;
use App\Models\ConversationTrackerRunSheet;

class ConversationTrackerImportController extends Controller
{
    public function index(Request $request)
    {
        $settings = ConversationTrackerSetting::orderBy('id')->get();

        $lastAttemptRun = ConversationTrackerRun::orderByDesc('id')->first();
        $lastSuccessRun = ConversationTrackerRun::where('status', 'done')
            ->orderByDesc('finished_at')
            ->first();

        $lastImportedMap = ConversationTrackerRunSheet::query()
            ->where('status', 'done')
            ->whereNotNull('finished_at')
            ->select('setting_id', DB::raw('MAX(finished_at) as last_success_at'))
            ->groupBy('setting_id')
            ->pluck('last_success_at', 'setting_id')
            ->toArray();

        return view('conversation_tracker.import', compact(
            'settings',
            'lastAttemptRun',
            'lastSuccessRun',
            'lastImportedMap'
        ));
    }

    /** AJAX start import. */
    public function start(Request $request)
    {
        $settings = ConversationTrackerSetting::orderBy('id')->get();
        if ($settings->isEmpty()) {
            return response()->json(['ok' => false, 'error' => 'No settings configured.'], 422);
        }

        $run = ConversationTrackerRun::create([
            'status'         => 'running',
            'total_settings' => $settings->count(),
            'started_at'     => now(),
        ]);

        foreach ($settings as $s) {
            ConversationTrackerRunSheet::create([
                'run_id'     => $run->id,
                'setting_id' => $s->id,
                'status'     => 'queued',
            ]);
        }

        ImportConversationTrackerFromGoogleSheet::dispatch($run->id);

        return response()->json(['ok' => true, 'run_id' => $run->id]);
    }

    /** AJAX polling. */
    public function status(Request $request)
    {
        $runId = (int) $request->query('run_id');
        $run = ConversationTrackerRun::with(['sheets.setting'])->findOrFail($runId);

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

    /** GET / DELETE /conversation/tracker/view */
    public function view(Request $request)
    {
        if ($request->isMethod('delete')) {
            ConversationTracker::truncate();
            return redirect('/conversation/tracker/view')->with('status', '🗑️ All records deleted.');
        }

        $query = ConversationTracker::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%$search%")
                  ->orWhere('phone_number', 'like', "%$search%")
                  ->orWhere('page_name', 'like', "%$search%");
            });
        }

        if ($request->filled('page_name')) {
            $query->where('page_name', $request->input('page_name'));
        }

        if ($request->filled('subscription_date')) {
            $query->whereDate('subscription_date', $request->input('subscription_date'));
        }

        $rows = $query->latest('id')->paginate(100)->appends($request->query());
        $pages = ConversationTracker::select('page_name')
            ->whereNotNull('page_name')->distinct()->orderBy('page_name')->pluck('page_name');

        return view('conversation_tracker.view', compact('rows', 'pages'));
    }
}
