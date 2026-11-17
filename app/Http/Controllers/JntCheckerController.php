<?php

namespace App\Http\Controllers;

use App\Jobs\RunJntCheckerUpdate;
use App\Jobs\RunJntCheckerUpload;
use App\Services\Jnt\JntCheckerMatcher;
use App\Services\Jnt\JntCheckerRuns;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * J&T Order Checker (/jnt/checker).
 *
 * Spec 283: ang mabigat na trabaho (pagbasa ng Excel/ZIP at pagtutugma, at ang pagsulat ng WAYBILL) ay
 * tumatakbo sa queue job; ang mga web request dito ay nag-iimbak, nagpapadala ng job at nagbabasa ng
 * estado lang, para hindi na tumama sa 60 s ng nginx (504). Ang pagtutugma mismo ay nasa
 * App\Services\Jnt\JntCheckerMatcher, hindi binago.
 */
class JntCheckerController extends Controller
{
    public const EXPIRED = 'This check expired, please upload again.';
    public const NOTHING_TO_UPDATE = 'No matched rows with WAYBILL found from the last upload.';

    /** Gaano katagal ituturing na "parehong pindot" ang parehong file + petsa habang hindi pa tapos. */
    private const SAME_UPLOAD_SECONDS = 900;

    public function __construct(private JntCheckerRuns $runs) {}

    public function index(Request $request)
    {
        $runId = $request->query('run') ?: session('jnt_checker_run');
        $vars = ['run' => null, 'runExpired' => false, 'runError' => null, 'runSuccess' => null, 'update' => null];

        if (!$runId) {
            return view('jnt.checker', $vars);
        }

        $state = JntCheckerRuns::isValidId((string) $runId) ? $this->runs->get((string) $runId) : null;
        if ($state === null) {
            return view('jnt.checker', array_merge($vars, ['runExpired' => true]));
        }
        $this->ensureOwner($state);

        $state = $this->withStaleCheck($state);
        $vars['run'] = $this->publicState($state);
        $vars['filter_date_start'] = $state['filter_date_start'] ?? null;
        $vars['filter_date_end'] = $state['filter_date_end'] ?? null;

        if (in_array($state['status'], [JntCheckerRuns::STATUS_ERROR, JntCheckerRuns::STATUS_FAILED], true)) {
            $vars['runError'] = $state['error'];
            return view('jnt.checker', $vars);
        }
        if ($state['status'] !== JntCheckerRuns::STATUS_DONE) {
            return view('jnt.checker', $vars); // waiting to start / running: page polls the status
        }

        $data = $this->runs->readResult($state['id']);
        if ($data === null) {
            return view('jnt.checker', array_merge($vars, ['run' => null, 'runExpired' => true]));
        }

        unset($data['updatable']);
        // Ang blade ay nagbabasa ng $row->PAGE atbp., kaya objects ulit (dati Eloquent models sa session).
        $data['notInExcelRows'] = collect($data['notInExcelRows'] ?? [])->map(fn ($r) => (object) $r);

        $vars['runSuccess'] = $state['success'] ?? null;
        $vars['update'] = $this->updateState($state['id']);

        return view('jnt.checker', array_merge($vars, $data));
    }

    /** Estado lang ng run para sa polling ng page: cache read, walang query sa database. */
    public function status(string $run)
    {
        if (!JntCheckerRuns::isValidId($run)) abort(404);

        $state = $this->runs->get($run);
        if ($state === null) {
            return response()->json(['status' => 'expired', 'update' => null]);
        }
        $this->ensureOwner($state);
        $state = $this->withStaleCheck($state);

        return response()->json(array_merge($this->publicState($state), ['update' => $this->updateState($run)]));
    }

    public function upload(Request $request, JntCheckerMatcher $matcher)
    {
        Log::info('📥 Entered upload() function');

        $request->validate([
            'excel_file'        => 'required|mimes:xlsx,xls,zip',
            'filter_date_start' => 'required|date',
        ]);

        $uploaded = $request->file('excel_file');
        $ext = strtolower($uploaded->getClientOriginalExtension());
        $start = $request->input('filter_date_start');
        $end = $request->input('filter_date_end');

        if ($ext === 'zip') {
            // Mabilis na check (listahan lang ng laman, walang extract) para agad ang parehong error gaya ng dati.
            $zipError = $matcher->zipPrecheckError($uploaded->getRealPath());
            if ($zipError !== null) {
                return back()->with('error', $zipError);
            }
        }

        $userId = (int) $request->user()->id;

        // Dobleng pindot / dalawang tab na parehong file at petsa habang hindi pa tapos ang una:
        // iisang run lang, para isang beses lang ang trabaho at iisa ang resulta.
        $sameKey = 'jnt_checker:same_upload:' . $userId . ':'
            . sha1(sha1_file($uploaded->getRealPath()) . '|' . $ext . '|' . $start . '|' . $end);
        $existing = Cache::get($sameKey);
        if (is_string($existing)) {
            $prev = $this->runs->get($existing);
            if ($prev && in_array($prev['status'], [JntCheckerRuns::STATUS_QUEUED, JntCheckerRuns::STATUS_RUNNING], true)) {
                session(['jnt_checker_run' => $existing]);
                return redirect()->route('jnt.checker', ['run' => $existing]);
            }
        }

        $runId = $this->runs->create($userId, $start, $end);
        if (!Cache::add($sameKey, $runId, self::SAME_UPLOAD_SECONDS)) {
            $other = Cache::get($sameKey);
            $prev = is_string($other) ? $this->runs->get($other) : null;
            if ($prev && in_array($prev['status'], [JntCheckerRuns::STATUS_QUEUED, JntCheckerRuns::STATUS_RUNNING], true)) {
                session(['jnt_checker_run' => $other]);
                return redirect()->route('jnt.checker', ['run' => $other]);
            }
            Cache::put($sameKey, $runId, self::SAME_UPLOAD_SECONDS);
        }

        $workDir = $this->runs->workDir($runId);
        if (!is_dir($workDir)) @mkdir($workDir, 0775, true);
        $fileName = 'upload.' . $ext;
        $uploaded->move($workDir, $fileName);

        RunJntCheckerUpload::dispatch(
            $runId,
            $fileName,
            $ext,
            (string) $uploaded->getClientOriginalName(),
            $start,
            $end,
            strtolower((string) $request->getHost()),
        );

        session(['jnt_checker_run' => $runId]);

        return redirect()->route('jnt.checker', ['run' => $runId]);
    }

    public function update(Request $request)
    {
        $runId = (string) ($request->input('run') ?: session('jnt_checker_run'));
        if ($runId === '' || !JntCheckerRuns::isValidId($runId)) {
            return back()->with('error', self::NOTHING_TO_UPDATE);
        }

        $state = $this->runs->get($runId);
        if ($state === null) {
            return redirect()->route('jnt.checker', ['run' => $runId]);
        }
        $this->ensureOwner($state);

        $result = $state['status'] === JntCheckerRuns::STATUS_DONE ? $this->runs->readResult($runId) : null;
        if ($result === null && $state['status'] === JntCheckerRuns::STATUS_DONE) {
            return redirect()->route('jnt.checker', ['run' => $runId]); // result file gone: expired message
        }
        if (empty($result['updatable'])) {
            return redirect()->route('jnt.checker', ['run' => $runId])->with('error', self::NOTHING_TO_UPDATE);
        }

        // Isang Update lang kada upload: ang pangalawang click o tab ay tumitingin lang sa estado ng una.
        // Pagkatapos ng bigong Update, puwedeng ulitin: pareho ang isusulat, kaya ligtas.
        if ($this->runs->claimUpdate($runId)) {
            RunJntCheckerUpdate::dispatch($runId);
        } elseif (in_array($this->updateState($runId)['status'] ?? null, [JntCheckerRuns::STATUS_FAILED, JntCheckerRuns::STATUS_ERROR], true)) {
            $this->runs->resetUpdate($runId);
            RunJntCheckerUpdate::dispatch($runId);
        }

        return redirect()->route('jnt.checker', ['run' => $runId]);
    }

    private function ensureOwner(array $state): void
    {
        if ((int) ($state['user_id'] ?? 0) !== (int) auth()->id()) {
            abort(404);
        }
    }

    /** Hindi iiwang umiikot: ang "running" na lampas sa timeout ng job ay ipinapakitang bigo. */
    private function withStaleCheck(array $state): array
    {
        if ($state['status'] === JntCheckerRuns::STATUS_RUNNING
            && now()->timestamp - (int) ($state['started_at'] ?? $state['created_at']) > JntCheckerRuns::RUNNING_STALE_SECONDS) {
            $state['status'] = JntCheckerRuns::STATUS_FAILED;
            $state['error'] = 'The check stopped: it took longer than 10 minutes.';
        }

        return $state;
    }

    private function publicState(array $state): array
    {
        return [
            'id'             => $state['id'],
            'status'         => $state['status'],
            'error'          => $state['error'] ?? null,
            'waited_seconds' => max(0, now()->timestamp - (int) $state['created_at']),
        ];
    }

    private function updateState(string $runId): ?array
    {
        $u = $this->runs->getUpdate($runId);
        if ($u === null) return null;

        if ($u['status'] === JntCheckerRuns::STATUS_RUNNING
            && now()->timestamp - (int) ($u['started_at'] ?? $u['created_at']) > JntCheckerRuns::RUNNING_STALE_SECONDS) {
            $u['status'] = JntCheckerRuns::STATUS_FAILED;
            $u['error'] = 'The update stopped: it took longer than 10 minutes.';
        }

        return [
            'status'         => $u['status'],
            'count'          => $u['count'],
            'error'          => $u['error'],
            'waited_seconds' => max(0, now()->timestamp - (int) $u['created_at']),
        ];
    }
}
