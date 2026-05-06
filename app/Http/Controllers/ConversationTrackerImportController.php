<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Jobs\ImportConversationTrackerFromGoogleSheet;
use App\Models\ConversationTracker;
use App\Models\ConversationTrackerSetting;
use App\Models\ConversationTrackerRun;
use App\Models\ConversationTrackerRunSheet;
use App\Models\ConversationFlowContent;

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
     * GET /conversation/tracker/compare
     *
     * Side-by-side comparison ng up to 4 flow variants. User picks 2-4
     * flow names (e.g., MAIN FLOW vs MAIN FLOW2, or SEQUENCE 1.1 vs 1.2)
     * → backend partitions data by which variant each row triggered →
     * returns per-set per-flow stats for sectional comparison.
     */
    public function compare(Request $request)
    {
        $pageName  = trim((string) $request->query('page_name', ''));
        $fromDate  = trim((string) $request->query('from_date', ''));
        $toDate    = trim((string) $request->query('to_date', ''));
        $replyFlag = strtoupper(trim((string) $request->query('reply_flag', 'CUSTOMER_REPLY')));
        $orderFlag = strtoupper(trim((string) $request->query('order_flag', 'CUSTOMER ORDERED')));
        if ($replyFlag === '') $replyFlag = 'CUSTOMER_REPLY';
        if ($orderFlag === '') $orderFlag = 'CUSTOMER ORDERED';

        // Variants from query: ?variants[]=MAIN+FLOW&variants[]=MAIN+FLOW2
        $variants = $request->query('variants', []);
        if (!is_array($variants)) $variants = [];
        $variants = array_values(array_filter(array_map(fn($v) => trim((string) $v), $variants), fn($v) => $v !== ''));
        // Walang cap — pwedeng unlimited sets

        // Build base query (shared filters apply to all sets)
        $baseQuery = function () use ($pageName, $fromDate, $toDate) {
            $q = ConversationTracker::query()
                ->whereNotNull('response_tracker')
                ->where('response_tracker', '<>', '');
            if ($pageName !== '' && strtolower($pageName) !== 'all') {
                $q->where('page_name', $pageName);
            }
            if ($fromDate !== '') $q->where('subscription_date', '>=', $fromDate . ' 00:00:00');
            if ($toDate !== '')   $q->where('subscription_date', '<=', $toDate . ' 23:59:59');
            return $q;
        };

        // For each variant, partition rows + compute stats
        $sets = [];
        foreach ($variants as $variant) {
            $variantU = strtoupper($variant);
            $stats = [
                'flow_total'         => [],
                'flow_replied'       => [],
                'flow_replied_cells' => [],
                'flow_ordered'       => [],
                'rows_in_set'        => 0,
            ];

            $baseQuery()
                ->select('response_tracker')
                ->orderBy('id')
                ->chunk(500, function ($chunk) use (&$stats, $variantU, $replyFlag, $orderFlag) {
                    foreach ($chunk as $row) {
                        $rt = (string) $row->response_tracker;
                        // Set membership: row contains the variant flow tag
                        if (stripos($rt, '[' . $variantU . ']') === false) continue;
                        $stats['rows_in_set']++;

                        $parsed = self::parseResponseTracker($rt, $replyFlag, $orderFlag);
                        foreach ($parsed['unique_flows'] as $f) {
                            $stats['flow_total'][$f] = ($stats['flow_total'][$f] ?? 0) + 1;
                        }
                        foreach ($parsed['replied_events'] as $f) {
                            $stats['flow_replied'][$f] = ($stats['flow_replied'][$f] ?? 0) + 1;
                        }
                        foreach ($parsed['replied_unique'] as $f) {
                            $stats['flow_replied_cells'][$f] = ($stats['flow_replied_cells'][$f] ?? 0) + 1;
                        }
                        foreach ($parsed['ordered_events'] as $f) {
                            $stats['flow_ordered'][$f] = ($stats['flow_ordered'][$f] ?? 0) + 1;
                        }
                    }
                });

            $sets[] = [
                'variant'  => $variant,
                'rows'     => $stats['rows_in_set'],
                'flow_total'         => $stats['flow_total'],
                'flow_replied'       => $stats['flow_replied'],
                'flow_replied_cells' => $stats['flow_replied_cells'],
                'flow_ordered'       => $stats['flow_ordered'],
            ];
        }

        // Combine all flows seen across sets — alphabetical sort
        $allFlows = [];
        foreach ($sets as $s) {
            $allFlows = array_unique(array_merge(
                $allFlows,
                array_keys($s['flow_total']),
                array_keys($s['flow_replied']),
                array_keys($s['flow_replied_cells']),
                array_keys($s['flow_ordered'])
            ));
        }
        // Sort: picked variants FIRST (in user's picking order), then the rest
        // (LOOP → MAIN → SEQUENCE → alphabetical).
        $variantPositions = [];
        foreach ($variants as $idx => $v) {
            $variantPositions[strtoupper(trim($v))] = $idx;
        }

        usort($allFlows, function ($a, $b) use ($variantPositions) {
            $aU = strtoupper(trim($a));
            $bU = strtoupper(trim($b));
            $aIsPicked = isset($variantPositions[$aU]);
            $bIsPicked = isset($variantPositions[$bU]);

            // Picked variants come first
            if ($aIsPicked && !$bIsPicked) return -1;
            if (!$aIsPicked && $bIsPicked) return 1;
            if ($aIsPicked && $bIsPicked) {
                return $variantPositions[$aU] <=> $variantPositions[$bU];
            }

            // Both unpicked — apply standard category sort
            $weight = function (string $f): array {
                if (preg_match('/^LOOP\s*0*(\d+)$/i', $f, $m))     return [0, (int) $m[1], $f];
                if (preg_match('/^MAIN\s*FLOW/i', $f))                return [1, 0, $f];
                if (preg_match('/^SEQUENCE\s*0*(\d+(?:\.\d+)?)$/i', $f, $m)) return [2, (float) $m[1] * 100, $f];
                return [3, 0, $f];
            };
            $wa = $weight($a); $wb = $weight($b);
            if ($wa[0] !== $wb[0]) return $wa[0] <=> $wb[0];
            if ($wa[1] !== $wb[1]) return $wa[1] <=> $wb[1];
            return strcmp($wa[2], $wb[2]);
        });

        // Build the comparison matrix: per flow, per set, with all metrics
        $comparison = [];
        foreach ($allFlows as $flow) {
            $row = ['flow' => $flow, 'sets' => []];
            foreach ($sets as $s) {
                $total       = $s['flow_total'][$flow] ?? 0;
                $replied     = $s['flow_replied'][$flow] ?? 0;
                $repliedCell = $s['flow_replied_cells'][$flow] ?? 0;
                $ordered     = $s['flow_ordered'][$flow] ?? 0;
                $row['sets'][] = [
                    'variant'        => $s['variant'],
                    'total'          => $total,
                    'replied'        => $replied,
                    'replied_cells'  => $repliedCell,
                    'ordered'        => $ordered,
                    'reply_rate'     => $total > 0 ? round($repliedCell / $total * 100, 1) : null,
                    'conv_total'     => $total > 0 ? round($ordered / $total * 100, 1) : null,
                    'conv_replied'   => $repliedCell > 0 ? round($ordered / $repliedCell * 100, 1) : null,
                    'has_data'       => ($total + $replied + $repliedCell + $ordered) > 0,
                ];
            }
            $comparison[] = $row;
        }

        // Get all unique flow names sa entire dataset for dropdown
        $allFlowNames = $this->getAllFlowNames($pageName, $fromDate, $toDate, $replyFlag, $orderFlag);

        // Pages list para sa filter dropdown
        $pages = ConversationTracker::select('page_name')
            ->whereNotNull('page_name')->distinct()->orderBy('page_name')->pluck('page_name');

        // Fetch flow content (bubbles) per picked variant — para ipakita sa header
        $bubblesByVariant = [];
        if (!empty($variants) && $pageName !== '' && strtolower($pageName) !== 'all') {
            $contents = \App\Models\ConversationFlowContent::where('page_name', $pageName)
                ->whereIn('flow_name', $variants)
                ->get();
            foreach ($contents as $c) {
                $bubblesByVariant[$c->flow_name] = is_array($c->bubbles) ? $c->bubbles : [];
            }
        }

        return view('conversation_tracker.compare', [
            'comparison'        => $comparison,
            'sets'              => $sets,
            'allFlowNames'      => $allFlowNames,
            'pages'             => $pages,
            'pageName'          => $pageName,
            'fromDate'          => $fromDate,
            'toDate'            => $toDate,
            'replyFlag'         => $replyFlag,
            'orderFlag'         => $orderFlag,
            'variants'          => $variants,
            'bubblesByVariant'  => $bubblesByVariant,
        ]);
    }

    /**
     * Detect all unique flow names sa response_tracker — for compare dropdown.
     * Cached briefly kasi expensive (full scan of response_tracker column).
     */
    private function getAllFlowNames(string $pageName, string $fromDate, string $toDate, string $replyFlag, string $orderFlag): array
    {
        $cacheKey = 'ct_compare_flow_names:' . md5("{$pageName}|{$fromDate}|{$toDate}|{$replyFlag}|{$orderFlag}");
        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 60, function () use ($pageName, $fromDate, $toDate, $replyFlag, $orderFlag) {
            $q = ConversationTracker::query()
                ->whereNotNull('response_tracker')
                ->where('response_tracker', '<>', '');
            if ($pageName !== '' && strtolower($pageName) !== 'all') {
                $q->where('page_name', $pageName);
            }
            if ($fromDate !== '') $q->where('subscription_date', '>=', $fromDate . ' 00:00:00');
            if ($toDate !== '')   $q->where('subscription_date', '<=', $toDate . ' 23:59:59');

            $allFlows = [];
            $q->select('response_tracker')->chunk(500, function ($chunk) use (&$allFlows, $replyFlag, $orderFlag) {
                foreach ($chunk as $row) {
                    $parsed = self::parseResponseTracker((string) $row->response_tracker, $replyFlag, $orderFlag);
                    foreach ($parsed['unique_flows'] as $f) {
                        $allFlows[$f] = true;
                    }
                }
            });

            $names = array_keys($allFlows);
            sort($names, SORT_NATURAL | SORT_FLAG_CASE);
            return $names;
        });
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

    /**
     * GET /conversation/tracker/flows
     * Flow templates editor — auto-detects flow names per page from imported
     * response_tracker text, then loads any saved bubbles content.
     */
    public function flowsIndex(Request $request)
    {
        $pages = ConversationTracker::select('page_name')
            ->whereNotNull('page_name')->distinct()->orderBy('page_name')->pluck('page_name');

        $selectedPage = (string) $request->query('page', '');
        if ($selectedPage === '' && $pages->isNotEmpty()) {
            $selectedPage = (string) $pages->first();
        }

        $flowNames = [];
        $contentsByFlow = [];

        if ($selectedPage !== '') {
            // Auto-detect flow names from this page's imports.
            $set = [];
            ConversationTracker::where('page_name', $selectedPage)
                ->whereNotNull('response_tracker')
                ->where('response_tracker', '<>', '')
                ->select('response_tracker')
                ->orderBy('id')
                ->chunk(500, function ($chunk) use (&$set) {
                    foreach ($chunk as $r) {
                        if (preg_match_all('/\[([^\]]+)\]/', $r->response_tracker, $m)) {
                            foreach ($m[1] as $f) {
                                $name = strtoupper(trim(preg_replace('/\s+/', ' ', $f)));
                                if ($name !== '' && $name !== 'TOTAL') {
                                    $set[$name] = true;
                                }
                            }
                        }
                    }
                });

            $flowNames = array_keys($set);
            // Natural sort: LOOP, MAIN FLOW, SEQUENCE, others.
            usort($flowNames, function ($a, $b) {
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

            // Load existing flow contents for this page.
            $contentsByFlow = ConversationFlowContent::where('page_name', $selectedPage)
                ->get()
                ->keyBy('flow_name')
                ->map(fn ($r) => $r->bubbles ?: [])
                ->toArray();
        }

        return view('conversation_tracker.flows', [
            'pages'          => $pages,
            'selectedPage'   => $selectedPage,
            'flowNames'      => $flowNames,
            'contentsByFlow' => $contentsByFlow,
        ]);
    }

    /**
     * POST /conversation/tracker/flows/save
     * Upserts the bubbles array for a (page, flow). Returns JSON.
     */
    public function flowsSave(Request $request)
    {
        $request->validate([
            'page_name' => 'required|string|max:255',
            'flow_name' => 'required|string|max:255',
            'bubbles'   => 'present|array',
            'bubbles.*.type' => 'required|in:text,image,video',
            'bubbles.*.text' => 'nullable|string',
            'bubbles.*.url'  => 'nullable|string',
            'bubbles.*.caption' => 'nullable|string',
        ]);

        $row = ConversationFlowContent::updateOrCreate(
            [
                'page_name' => $request->input('page_name'),
                'flow_name' => $request->input('flow_name'),
            ],
            [
                'bubbles' => $request->input('bubbles', []),
            ]
        );

        return response()->json([
            'ok'         => true,
            'id'         => $row->id,
            'saved_at'   => now()->toIso8601String(),
            'bubble_count' => count($row->bubbles ?: []),
        ]);
    }

    /**
     * POST /conversation/tracker/flows/upload
     * Accepts an image or video file (multipart/form-data, field "file")
     * + a "kind" indicator ("image" or "video"). Saves to public storage
     * under /conversation_flows and returns the public URL.
     */
    public function flowsUpload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:51200', // 50MB max (covers video)
            'kind' => 'required|in:image,video',
        ]);

        $kind = $request->input('kind');
        $file = $request->file('file');

        // Type-specific size + MIME check.
        if ($kind === 'image') {
            $request->validate([
                'file' => 'mimes:jpg,jpeg,png,gif,webp|max:5120', // 5MB
            ]);
        } else { // video
            $request->validate([
                'file' => 'mimes:mp4,webm,mov|max:51200', // 50MB
            ]);
        }

        $ext = strtolower($file->getClientOriginalExtension());
        $name = (string) Str::uuid() . '.' . $ext;
        $path = $file->storeAs('conversation_flows', $name, 'public');
        // $path = "conversation_flows/<uuid>.ext"; storage:link makes it
        // accessible at /storage/conversation_flows/<uuid>.ext

        return response()->json([
            'ok'   => true,
            'url'  => '/storage/' . $path,
            'kind' => $kind,
            'size' => $file->getSize(),
        ]);
    }
}
