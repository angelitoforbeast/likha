<?php

namespace App\Http\Controllers;

use App\Models\MacroOutput;
use App\Services\MacroChecker;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Endpoints sa /encoder/checker_1/ai-checker/* — drives the CHECKER_11_1
 * PHP port (App\Services\MacroChecker) from the encoder/checker_1 view.
 *
 * Batch processing is FRONTEND-DRIVEN — JS loops through IDs sequentially,
 * calling /run-row/{id} per item. Same UX as clicking per-row AI Fix one at
 * a time. No background job, no cache polling.
 *
 * Endpoints:
 *   GET   count          → count of INCOMPLETE rows in current filter
 *   POST  start          → returns IDs to process (frontend loops them)
 *   POST  run-row/{id}   → process ONE row (used per-row + by batch loop)
 */
class MacroCheckerController extends Controller
{
    /**
     * Role gate — AI Checker + AI Fix endpoints are limited sa CEO / Marketing /
     * Marketing - OIC. Iba pa roles get 403.
     *
     * Uses EFFECTIVE role — honors CEO's "View as <role>" override
     * (session('nav_view_as_role')) so when CEO is previewing as Data Encoder,
     * backend ALSO blocks (matches what the previewed role would see).
     * Same pattern as layout.blade.php at MacroOutputController::index.
     *
     * Returns null if allowed, else a 403 JsonResponse to be returned by caller.
     */
    private function checkRole()
    {
        if (\App\Support\AiCheckerAccess::allows()) {
            return null; // allowed (default role O nasa extra allowlist)
        }
        return response()->json([
            'ok'    => false,
            'error' => 'Forbidden — AI Checker is limited to Marketing, Marketing - OIC, CEO, at mga pinayagang user.',
        ], 403);
    }

    /** CEO-only gate (para sa access management — pagbibigay ng permission). */
    private function isCeo(): bool
    {
        $role = preg_replace('/\s+/u', ' ', trim((string) (Auth::user()?->employeeProfile?->role ?? '')));
        return preg_match('/^ceo$/iu', $role) === 1;
    }

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
        if ($r = $this->checkRole()) return $r;

        return response()->json([
            'ok'    => true,
            'count' => $this->blankRowsQuery($request)->count(),
        ]);
    }

    /**
     * POST /encoder/checker_1/ai-checker/start
     * Returns the list of IDs to process. Frontend mismo ang nag-loop sequentially
     * (calls /run-row/{id} per item) — same UX behavior as clicking per-row AI Fix
     * one at a time. No background job, no cache polling.
     *
     * Body: { date, PAGE, ids?: [optional explicit list] }
     */
    public function start(Request $request)
    {
        if ($r = $this->checkRole()) return $r;

        $explicitIds = $request->input('ids');
        if (is_array($explicitIds) && !empty($explicitIds)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $explicitIds), fn ($v) => $v > 0)));
        } else {
            $ids = $this->blankRowsQuery($request)
                ->limit(500) // safety cap per run
                ->orderBy('id')
                ->pluck('id')
                ->toArray();
        }

        if (empty($ids)) {
            return response()->json([
                'ok'    => false,
                'error' => 'No blank rows na pwedeng i-process sa current view filter.',
            ], 422);
        }

        return response()->json([
            'ok'       => true,
            'ids'      => $ids,
            'count'    => count($ids),
            'batch_id' => (string) Str::uuid(),   // ginagamit ng frontend para sa per-batch logs
        ]);
    }

    /**
     * POST /encoder/checker_1/ai-checker/run-row/{id}
     *
     * SYNC per-row trigger — temporary (per user spec). Processes one row
     * immediately and returns the result. Aalisin pag stable na yung batch.
     */
    public function runRow(Request $request, $id)
    {
        if ($r = $this->checkRole()) return $r;

        $row = MacroOutput::find((int) $id);
        if (!$row) {
            return response()->json(['ok' => false, 'error' => 'Row not found'], 404);
        }

        $maps = MacroChecker::loadAddressMaps();
        if (empty($maps['provincesSet'])) {
            return response()->json(['ok' => false, 'error' => 'jnt_address.txt missing or empty'], 500);
        }

        // Logging context — 'batch' (AI Checker) o 'single' (AI Fix per row).
        $source     = $request->input('source') === 'batch' ? 'batch' : 'single';
        $batchId    = $request->input('batch_id') ?: null;
        $batchTotal = (int) $request->input('batch_total', 0);
        $t0         = microtime(true);

        try {
            $result = (new MacroChecker)->processRow((int) $id, $maps);
            $durationMs = (int) round((microtime(true) - $t0) * 1000);
            // Re-read so frontend gets the actual updated values
            $row = MacroOutput::find((int) $id);

            $code      = (string) ($result['final_code'] ?? '');
            $allFilled = ($result['all_filled'] ?? true) ? true : false;
            $outcome   = ($code === '✅' && $allFilled) ? 'fixed' : 'partial';

            $this->writeLog([
                'source'          => $source,
                'batch_id'        => $batchId,
                'batch_total'     => $batchTotal > 0 ? $batchTotal : null,
                'macro_output_id' => (int) $id,
                'page'            => $row->PAGE ?? null,
                'item'            => $row->{'ITEM_NAME'} ?? null,
                'final_code'      => $code !== '' ? $code : null,
                'all_filled'      => $allFilled,
                'outcome'         => $outcome,
                'duration_ms'     => $durationMs,
            ]);

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
            $durationMs = (int) round((microtime(true) - $t0) * 1000);
            $this->writeLog([
                'source'          => $source,
                'batch_id'        => $batchId,
                'batch_total'     => $batchTotal > 0 ? $batchTotal : null,
                'macro_output_id' => (int) $id,
                'page'            => $row->PAGE ?? null,
                'item'            => $row->{'ITEM_NAME'} ?? null,
                'final_code'      => '❌',
                'all_filled'      => false,
                'outcome'         => 'failed',
                'duration_ms'     => $durationMs,
            ]);
            return response()->json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /** Insert ng isang per-row AI log — best-effort (di sisirain ang run-row). */
    private function writeLog(array $data): void
    {
        try {
            // Defensive caps — para hindi mag-fail ang insert dahil sa haba ng value.
            foreach (['final_code' => 64, 'page' => 255, 'item' => 255] as $k => $max) {
                if (isset($data[$k]) && is_string($data[$k])) {
                    $data[$k] = mb_substr($data[$k], 0, $max);
                }
            }
            DB::table('ai_checker_logs')->insert(array_merge($data, [
                'user_id'    => Auth::id(),
                'user_name'  => mb_substr((string) (Auth::user()?->name ?? Auth::user()?->email ?? 'unknown'), 0, 255),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        } catch (\Throwable $e) {
            // Huwag ipa-fail ang run-row, pero i-log na (hindi na tahimik) para ma-debug.
            Log::warning('AI_CHECKER_LOG_FAIL', ['error' => $e->getMessage()]);
        }
    }

    /**
     * GET /encoder/checker_1/ai-checker/logs — standalone logs page (walang
     * hyperlink sa checker_1). Per-batch summary (group by batch_id) + recent
     * single AI Fix entries. Auto-prune: panatilihin ang huling 90 araw.
     */
    public function logs(Request $request)
    {
        if ($this->checkRole()) abort(403);

        $batches = collect();
        $singles = collect();

        if (Schema::hasTable('ai_checker_logs')) {
            // Auto-prune (>90 araw).
            try {
                DB::table('ai_checker_logs')->where('created_at', '<', now()->subDays(90))->delete();
            } catch (\Throwable $e) {}

            // Per-batch summary.
            $batches = DB::table('ai_checker_logs')
                ->whereNotNull('batch_id')
                ->selectRaw("
                    batch_id,
                    MAX(user_name) AS user_name,
                    MAX(batch_total) AS target,
                    COUNT(*) AS processed,
                    SUM(CASE WHEN outcome = 'fixed'   THEN 1 ELSE 0 END) AS fixed,
                    SUM(CASE WHEN outcome = 'partial' THEN 1 ELSE 0 END) AS partial,
                    SUM(CASE WHEN outcome = 'failed'  THEN 1 ELSE 0 END) AS failed,
                    MIN(created_at) AS started_at,
                    MAX(created_at) AS finished_at,
                    AVG(duration_ms) AS avg_ms,
                    SUM(duration_ms) AS total_ms
                ")
                ->groupBy('batch_id')
                ->orderByDesc(DB::raw('MAX(created_at)'))
                ->limit(100)
                ->get();

            // Distinct pages per batch (multi-page ang batch kapag walang PAGE filter).
            // Engine-agnostic — hiwalay na query imbes na GROUP_CONCAT/STRING_AGG.
            $batchIds = $batches->pluck('batch_id')->filter()->all();
            $pagesByBatch = [];
            if (!empty($batchIds)) {
                $pageRows = DB::table('ai_checker_logs')
                    ->whereIn('batch_id', $batchIds)
                    ->whereNotNull('page')->where('page', '<>', '')
                    ->select('batch_id', 'page')->distinct()->get();
                foreach ($pageRows as $pr) {
                    $pagesByBatch[$pr->batch_id][] = $pr->page;
                }
            }
            foreach ($batches as $b) {
                $b->pages = $pagesByBatch[$b->batch_id] ?? [];
            }

            // Recent single AI Fix entries.
            $singles = DB::table('ai_checker_logs')
                ->whereNull('batch_id')
                ->orderByDesc('id')
                ->limit(200)
                ->get();
        }

        return view('encoder.ai_checker_logs', [
            'batches' => $batches,
            'singles' => $singles,
            'isCeo'   => $this->isCeo(),   // para sa "Manage access" link (CEO lang)
        ]);
    }

    /**
     * GET /encoder/checker_1/ai-checker/access — CEO-only. Set kung sino-sinong
     * EXTRA users (bukod sa default roles) ang pwedeng gumamit ng AI Checker.
     */
    public function accessEdit()
    {
        if (!$this->isCeo()) abort(403);

        $allowedIds = \App\Support\AiCheckerAccess::allowedUserIds();

        $users = \App\Models\User::with('employeeProfile')
            ->orderBy('name')
            ->get()
            ->map(function ($u) use ($allowedIds) {
                $role = $u->employeeProfile?->role;
                return (object) [
                    'id'           => (int) $u->id,
                    'name'         => $u->name,
                    'email'        => $u->email,
                    'role'         => $role,
                    'role_allowed' => \App\Support\AiCheckerAccess::roleAllowed($role),
                    'in_allowlist' => in_array((int) $u->id, $allowedIds, true),
                ];
            });

        return view('encoder.ai_checker_access', ['users' => $users]);
    }

    /** POST /encoder/checker_1/ai-checker/access — i-save ang extra allowlist (CEO-only). */
    public function accessUpdate(Request $request)
    {
        if (!$this->isCeo()) abort(403);

        \App\Support\AiCheckerAccess::setAllowedUserIds((array) $request->input('user_ids', []));

        return redirect()
            ->route('macro_checker.access')
            ->with('success', '✅ Na-update ang AI Checker access (extra users).');
    }
}
