<?php

namespace App\Http\Controllers;

use App\Models\NightRunStep;
use App\Services\NightAstraRun;
use App\Support\NightRunSettings;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Night run (handoff 007, spec §6.6 at §8): settings ng gabi, "Run now", "Retry failed" at ang listahan ng
 * mga row ng isang gabi. LAHAT ay CEO lang (403 sa iba), at bawat input ay validated bago gamitin.
 * Ang mismong patakaran ng run ay nasa App\Services\NightAstraRun.
 */
class NightRunController extends Controller
{
    private const TZ = 'Asia/Manila';

    /** CEO check — same pattern as MacroCheckerController::isCeo() (ang totoong role, hindi ang "view as"). */
    private function isCeo(): bool
    {
        $role = preg_replace('/\s+/u', ' ', trim((string) (Auth::user()?->employeeProfile?->role ?? '')));
        return preg_match('/^ceo$/iu', $role) === 1;
    }

    /** POST /encoder/checker_1/settings/night */
    public function settings(Request $request)
    {
        if (!$this->isCeo()) abort(403);

        // Checkbox ang mga switch: walang field = off. Sariling error bag (`night`) para hindi madoble ang listahan sa page.
        $validated = $request->validateWithBag('night', [
            'night_macro_import_enabled' => 'nullable|boolean',
            'night_likha_import_enabled' => 'nullable|boolean',
            'night_astra_enabled'        => 'nullable|boolean',
            'night_import_time_1'        => 'required|date_format:H:i',
            'night_import_time_2'        => 'required|date_format:H:i',
            'night_astra_time'           => 'required|date_format:H:i',
            'night_astra_stop_time'      => 'required|date_format:H:i',
            'night_astra_max_rows'       => 'required|integer|min:1|max:' . NightRunSettings::MAX_ROWS_LIMIT,
        ]);

        if ($validated['night_import_time_1'] >= $validated['night_import_time_2']) {
            return back()->withInput()->withErrors(['night_import_time_1' => 'The first import time must be earlier than the second import time.'], 'night');
        }
        if ($validated['night_astra_time'] >= $validated['night_astra_stop_time']) {
            return back()->withInput()->withErrors(['night_astra_time' => 'The Astra time must be earlier than the stop time.'], 'night');
        }

        foreach (NightRunSettings::SWITCHES as $switch) {
            $validated[$switch] = $request->boolean($switch);
        }
        $validated['night_astra_max_rows'] = (int) $validated['night_astra_max_rows'];
        NightRunSettings::save($validated);

        return redirect()->route('encoder.checker1.settings')->with('night_settings_saved', true);
    }

    /** POST /encoder/checker_1/ai-checker/night/run-now — `date` = petsa ng mga order (kahapon sa Manila o mas maaga). */
    public function runNow(Request $request, NightAstraRun $night)
    {
        if (!$this->isCeo()) abort(403);

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . now(self::TZ)->subDay()->toDateString()],
        ]);

        return $this->backToLogs($night->runNow($validated['date']));
    }

    /** POST /encoder/checker_1/ai-checker/night/{step}/retry-failed */
    public function retryFailed(NightAstraRun $night, $step)
    {
        if (!$this->isCeo()) abort(403);

        return $this->backToLogs($night->retryFailed($this->astraStep($step)));
    }

    /**
     * GET /encoder/checker_1/ai-checker/night/{step}/rows — JSON ng mga row ng gabi, ayon sa pagkakasunod ng run.
     * Ang page at item ay text mula sa customer/sheet: data lang ito (x-text sa page, hindi HTML).
     */
    public function rows($step)
    {
        if (!$this->isCeo()) abort(403);

        $step = $this->astraStep($step);
        $time = fn ($ts) => $ts ? Carbon::parse($ts)->timezone(self::TZ)->format('Y-m-d H:i') : null;

        $rows = DB::table('night_astra_rows as r')
            ->leftJoin('macro_output as m', 'm.id', '=', 'r.macro_output_id')
            ->leftJoin('ai_checker_logs as l', 'l.id', '=', 'r.log_id')
            ->where('r.step_id', $step->id)
            ->orderBy('r.id')
            ->get([
                'r.id', 'r.macro_output_id', 'm.PAGE as page', 'm.ITEM_NAME as item', 'r.state', 'r.code', 'r.proceed',
                'r.reason', 'r.attempts', 'r.started_at', 'r.finished_at', 'r.log_id', 'l.created_at as log_created_at',
            ])
            ->map(fn ($row) => [
                'id'              => (int) $row->id,
                'macro_output_id' => (int) $row->macro_output_id,
                'page'            => $row->page,
                'item'            => $row->item,
                'state'           => $row->state,
                'code'            => $row->code,
                'proceed'         => (bool) $row->proceed,
                'reason'          => $row->reason,
                'attempts'        => (int) $row->attempts,
                'started_at'      => $time($row->started_at),
                'finished_at'     => $time($row->finished_at),
                'log_id'          => $row->log_id === null ? null : (int) $row->log_id,
                // Ang existing na answers page, naka-filter sa order at sa petsa (Manila) ng log entry.
                'log_url'         => $row->log_id !== null && $row->log_created_at
                    ? route('macro_checker.answers', [
                        'date' => Carbon::parse($row->log_created_at)->timezone(self::TZ)->format('Y-m-d'),
                        'mid'  => (int) $row->macro_output_id,
                    ])
                    : null,
            ]);

        return response()->json(['ok' => true, 'step_id' => (int) $step->id, 'rows' => $rows->values()]);
    }

    /** Ang step ay dapat numeric at kind `astra`; kung hindi, 404. */
    private function astraStep($id): NightRunStep
    {
        $step = ctype_digit((string) $id) && Schema::hasTable('night_run_steps')
            ? NightRunStep::where('id', (int) $id)->where('kind', NightAstraRun::KIND)->first()
            : null;
        if (!$step) abort(404);

        return $step;
    }

    /** @param array{ok: bool, message: string} $result */
    private function backToLogs(array $result)
    {
        return redirect()->route('macro_checker.logs')->with($result['ok'] ? 'success' : 'error', $result['message']);
    }
}
