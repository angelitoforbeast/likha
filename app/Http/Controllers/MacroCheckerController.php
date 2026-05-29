<?php

namespace App\Http\Controllers;

use App\Jobs\RunMacroCheckerBatch;
use App\Models\MacroOutput;
use App\Services\MacroChecker;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Endpoints sa /encoder/checker_1/ai-checker/* — drives the CHECKER_11_1
 * PHP port (App\Services\MacroChecker) from the encoder/checker_1 view.
 *
 * Three endpoints:
 *   POST  start              → resolves blank rows in the current view filter,
 *                              dispatches RunMacroCheckerBatch, returns run_id
 *   GET   status?run_id=X    → reads progress from Cache for the polling modal
 *   POST  stop?run_id=X      → sets a stop flag in Cache; job exits at next row
 *   POST  run-row/{id}       → per-row sync (temporary; aalisin pag stable)
 *   GET   count              → returns count of blank rows under the current
 *                              filter (used to populate the toolbar button label)
 */
class MacroCheckerController extends Controller
{
    /**
     * Build the "blank rows" base query using the same date/PAGE filters
     * as MacroOutputController::index() so the user is operating on the
     * same set they're looking at.
     */
    private function blankRowsQuery(Request $request)
    {
        $tz   = 'Asia/Manila';
        $date = $request->filled('date') ? $request->date : now($tz)->subDay()->toDateString();
        $formattedDMY = Carbon::parse($date, $tz)->format('d-m-Y');

        $tsType = null;
        try { $tsType = Schema::getColumnType('macro_output', 'ts_date'); } catch (\Throwable $e) {}

        $q = MacroOutput::query()
            ->where(function ($qq) use ($date, $formattedDMY, $tsType, $tz) {
                $qq->where(function ($a) use ($date, $tsType, $tz) {
                    $a->whereNotNull('ts_date');
                    if ($tsType === 'date') {
                        $a->where('ts_date', '=', $date);
                    } else {
                        $start = Carbon::parse($date, $tz)->startOfDay()->toDateTimeString();
                        $end   = Carbon::parse($date, $tz)->endOfDay()->toDateTimeString();
                        $a->whereBetween('ts_date', [$start, $end]);
                    }
                })->orWhere(function ($b) use ($formattedDMY) {
                    $b->whereNull('ts_date')
                      ->whereNotNull('TIMESTAMP')
                      ->where('TIMESTAMP', 'LIKE', "%{$formattedDMY}%");
                });
            });

        if ($request->filled('PAGE')) {
            $q->where('PAGE', $request->PAGE);
        }

        // Skip criteria (per user): process row kung
        //   1. may chat (all_user_input non-empty)
        //   2. STATUS is blank (huwag galawin yung PROCEED/CANNOT PROCEED/ODZ)
        //   3. ANY of (FULL NAME/PHONE/ADDRESS/PROVINCE/CITY/BARANGAY) is blank
        // Matches the INCOMPLETE pill filter sa /encoder/checker_1 view.
        $wrap = fn (string $col) => DB::getQueryGrammar()->wrap($col);

        $q->whereNotNull('all_user_input')->where('all_user_input', '<>', '');

        $STATUS = $wrap('STATUS');
        $q->where(function ($s) use ($STATUS) {
            $s->whereNull('STATUS')->orWhereRaw("TRIM({$STATUS}) = ''");
        });

        $q->where(function ($a) use ($wrap) {
            $cols = ['PROVINCE', 'CITY', 'BARANGAY', 'PHONE NUMBER', 'FULL NAME'];
            foreach ($cols as $c) {
                $w = $wrap($c);
                $a->orWhereNull($c)->orWhereRaw("TRIM({$w}) = ''");
            }
        });

        return $q;
    }

    /** GET /encoder/checker_1/ai-checker/count?date=YYYY-MM-DD[&PAGE=…] */
    public function count(Request $request)
    {
        return response()->json([
            'ok'    => true,
            'count' => $this->blankRowsQuery($request)->count(),
        ]);
    }

    /**
     * POST /encoder/checker_1/ai-checker/start
     * Body: { date, PAGE, ids?: [optional explicit list] }
     */
    public function start(Request $request)
    {
        $explicitIds = $request->input('ids');
        if (is_array($explicitIds) && !empty($explicitIds)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $explicitIds), fn ($v) => $v > 0)));
        } else {
            $ids = $this->blankRowsQuery($request)
                ->limit(500) // safety cap per run
                ->pluck('id')
                ->toArray();
        }

        if (empty($ids)) {
            return response()->json([
                'ok'    => false,
                'error' => 'No blank rows na pwedeng i-process sa current view filter.',
            ], 422);
        }

        $runId = (string) Str::uuid();
        $user  = Auth::user();

        // Pre-seed the cache so the status endpoint has something to return immediately.
        Cache::put(RunMacroCheckerBatch::CACHE_PREFIX . $runId, [
            'status'       => 'queued',
            'total'        => count($ids),
            'processed'    => 0,
            'fixed'        => 0,
            'partial'      => 0,
            'failed'       => 0,
            'started_at'   => now()->toDateTimeString(),
            'finished_at'  => null,
            'message'      => null,
            'triggered_by' => $user?->email,
        ], RunMacroCheckerBatch::TTL_SECONDS);

        RunMacroCheckerBatch::dispatch($runId, $ids, $user?->id, $user?->email);

        return response()->json([
            'ok'     => true,
            'run_id' => $runId,
            'count'  => count($ids),
        ]);
    }

    /** GET /encoder/checker_1/ai-checker/status?run_id=X */
    public function status(Request $request)
    {
        $runId = (string) $request->query('run_id', '');
        if ($runId === '') {
            return response()->json(['ok' => false, 'error' => 'Missing run_id'], 422);
        }

        $progress = Cache::get(RunMacroCheckerBatch::CACHE_PREFIX . $runId);
        if ($progress === null) {
            return response()->json([
                'ok'    => false,
                'error' => 'Run not found or expired',
            ], 404);
        }

        return response()->json(['ok' => true, 'run' => $progress, 'run_id' => $runId]);
    }

    /** POST /encoder/checker_1/ai-checker/stop?run_id=X */
    public function stop(Request $request)
    {
        $runId = (string) $request->input('run_id', $request->query('run_id', ''));
        if ($runId === '') {
            return response()->json(['ok' => false, 'error' => 'Missing run_id'], 422);
        }

        Cache::put(RunMacroCheckerBatch::STOP_PREFIX . $runId, true, RunMacroCheckerBatch::TTL_SECONDS);

        return response()->json(['ok' => true, 'message' => 'Stop signal sent. Job will exit after the current row.']);
    }

    /**
     * POST /encoder/checker_1/ai-checker/run-row/{id}
     *
     * SYNC per-row trigger — temporary (per user spec). Processes one row
     * immediately and returns the result. Aalisin pag stable na yung batch.
     */
    public function runRow($id)
    {
        $row = MacroOutput::find((int) $id);
        if (!$row) {
            return response()->json(['ok' => false, 'error' => 'Row not found'], 404);
        }

        $maps = MacroChecker::loadAddressMaps();
        if (empty($maps['provincesSet'])) {
            return response()->json(['ok' => false, 'error' => 'jnt_address.txt missing or empty'], 500);
        }

        try {
            $result = (new MacroChecker)->processRow((int) $id, $maps);
            // Re-read so frontend gets the actual updated values
            $row = MacroOutput::find((int) $id);
            return response()->json([
                'ok'     => true,
                'result' => $result,
                'row'    => [
                    'id'           => $row->id,
                    'FULL NAME'    => $row->{'FULL NAME'},
                    'PHONE NUMBER' => $row->{'PHONE NUMBER'},
                    'ADDRESS'      => $row->ADDRESS,
                    'PROVINCE'     => $row->PROVINCE,
                    'CITY'         => $row->CITY,
                    'BARANGAY'     => $row->BARANGAY,
                    'APP SCRIPT CHECKER' => $row->{'APP SCRIPT CHECKER'},
                    'STATUS'       => $row->STATUS,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
