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

    /** DELETE /conversation/tracker/view/{id} — delete one row. */
    public function destroyRow($id)
    {
        $row = ConversationTracker::findOrFail($id);
        $row->delete();
        return redirect()->back()->with('status', "🗑️ Row #{$id} deleted.");
    }

    /**
     * GET /conversation/tracker/stats
     *
     * Aggregates per-FLOW counts from response_tracker text. Mirrors the
     * user's Google Sheets formula:
     *   FLOW · TOTAL · REPLIED · REPLIED CELLS · CUSTOMER ORDERED
     */
    public function stats(Request $request)
    {
        $pageName  = trim((string) $request->query('page_name', ''));
        $fromDate  = trim((string) $request->query('from_date', ''));
        $toDate    = trim((string) $request->query('to_date', ''));
        $replyFlag = strtoupper(trim((string) $request->query('reply_flag', 'CUSTOMER_REPLY')));
        $orderFlag = strtoupper(trim((string) $request->query('order_flag', 'CUSTOMER ORDERED')));

        if ($replyFlag === '') $replyFlag = 'CUSTOMER_REPLY';
        if ($orderFlag === '') $orderFlag = 'CUSTOMER ORDERED';

        $query = ConversationTracker::query()
            ->whereNotNull('response_tracker')
            ->where('response_tracker', '<>', '');

        if ($pageName !== '' && strtolower($pageName) !== 'all') {
            $query->where('page_name', $pageName);
        }
        if ($fromDate !== '') $query->where('subscription_date', '>=', $fromDate . ' 00:00:00');
        if ($toDate !== '')   $query->where('subscription_date', '<=', $toDate . ' 23:59:59');

        // Stream-friendly chunking — keeps memory bounded for big datasets.
        $flowTotal       = []; // F => count of rows where F appears
        $flowReplied     = []; // F => count of REPLIED events
        $flowRepliedCell = []; // F => count of rows where F had ≥1 REPLIED
        $flowOrdered     = []; // F => count of CUSTOMER ORDERED events
        $totalRows       = 0;

        $query->select('response_tracker')
            ->orderBy('id')
            ->chunk(500, function ($chunk) use (&$flowTotal, &$flowReplied, &$flowRepliedCell, &$flowOrdered, &$totalRows, $replyFlag, $orderFlag) {
                foreach ($chunk as $row) {
                    $totalRows++;
                    $parsed = self::parseResponseTracker($row->response_tracker, $replyFlag, $orderFlag);

                    foreach ($parsed['unique_flows'] as $f) {
                        $flowTotal[$f] = ($flowTotal[$f] ?? 0) + 1;
                    }
                    foreach ($parsed['replied_events'] as $f) {
                        $flowReplied[$f] = ($flowReplied[$f] ?? 0) + 1;
                    }
                    foreach ($parsed['replied_unique'] as $f) {
                        $flowRepliedCell[$f] = ($flowRepliedCell[$f] ?? 0) + 1;
                    }
                    foreach ($parsed['ordered_events'] as $f) {
                        $flowOrdered[$f] = ($flowOrdered[$f] ?? 0) + 1;
                    }
                }
            });

        // Combine into rows
        $allFlows = array_unique(array_merge(
            array_keys($flowTotal),
            array_keys($flowReplied),
            array_keys($flowRepliedCell),
            array_keys($flowOrdered)
        ));

        // Sort: LOOP N (numeric), then MAIN FLOW, then SEQUENCE N (numeric), then alphabetical.
        usort($allFlows, function ($a, $b) {
            $weight = function (string $f): array {
                if (preg_match('/^LOOP\s*0*(\d+)$/', $f, $m))     return [0, (int) $m[1], $f];
                if ($f === 'MAIN FLOW')                              return [1, 0, $f];
                if (preg_match('/^SEQUENCE\s*0*(\d+)$/', $f, $m)) return [2, (int) $m[1], $f];
                return [3, 0, $f];
            };
            $wa = $weight($a); $wb = $weight($b);
            if ($wa[0] !== $wb[0]) return $wa[0] <=> $wb[0];
            if ($wa[1] !== $wb[1]) return $wa[1] <=> $wb[1];
            return strcmp($wa[2], $wb[2]);
        });

        $statsRows = [];
        foreach ($allFlows as $f) {
            $statsRows[] = [
                'flow'          => $f,
                'total'         => $flowTotal[$f]       ?? 0,
                'replied'       => $flowReplied[$f]     ?? 0,
                'replied_cells' => $flowRepliedCell[$f] ?? 0,
                'ordered'       => $flowOrdered[$f]     ?? 0,
            ];
        }

        $pages = ConversationTracker::select('page_name')
            ->whereNotNull('page_name')->distinct()->orderBy('page_name')->pluck('page_name');

        return view('conversation_tracker.stats', [
            'statsRows'  => $statsRows,
            'totalRows'  => $totalRows,
            'pages'      => $pages,
            'pageName'   => $pageName,
            'fromDate'   => $fromDate,
            'toDate'     => $toDate,
            'replyFlag'  => $replyFlag,
            'orderFlag'  => $orderFlag,
        ]);
    }

    /**
     * Parse a single response_tracker text block into events. Mirrors the
     * GSheets formula's logic line-by-line.
     *
     * Returns:
     *   - unique_flows[]    — flows seen in this row (deduped, for TOTAL)
     *   - replied_events[]  — flow at each reply line (counted, for REPLIED)
     *   - replied_unique[]  — unique flows that had ≥1 reply (deduped, for REPLIED CELLS)
     *   - ordered_events[]  — flow at each order line (counted, for CUSTOMER ORDERED)
     */
    public static function parseResponseTracker(?string $text, string $replyFlag, string $orderFlag): array
    {
        $out = [
            'unique_flows'   => [],
            'replied_events' => [],
            'replied_unique' => [],
            'ordered_events' => [],
        ];
        if ($text === null || trim($text) === '') return $out;

        $replyFlagU = strtoupper($replyFlag);
        $orderFlagU = strtoupper($orderFlag);
        $replyPat = '/^' . preg_quote($replyFlagU, '/') . ':?/i';

        $lines = preg_split('/\R/', $text);
        $activeFlow      = null;
        $rowFlowsSet     = [];
        $rowRepliedSet   = [];

        foreach ($lines as $line) {
            $upper = strtoupper(trim($line));
            if ($upper === '') continue;

            // [FLOW NAME] tag — set active flow + register in row's flow set.
            if (preg_match('/^\[([^\]]+)\]/', $upper, $m)) {
                $name = trim(preg_replace('/\s+/', ' ', $m[1]));
                if ($name !== '' && $name !== 'TOTAL') {
                    $activeFlow = $name;
                    $rowFlowsSet[$name] = true;
                }
                continue;
            }

            if ($activeFlow === null) continue;

            // Reply line: prefix match, with optional colon.
            if (preg_match($replyPat, $upper)) {
                $out['replied_events'][] = $activeFlow;
                $rowRepliedSet[$activeFlow] = true;
                continue;
            }

            // Order line: line equals order_flag exactly (after upper+trim).
            if ($upper === $orderFlagU) {
                $out['ordered_events'][] = $activeFlow;
            }
        }

        $out['unique_flows']   = array_keys($rowFlowsSet);
        $out['replied_unique'] = array_keys($rowRepliedSet);
        return $out;
    }
}
