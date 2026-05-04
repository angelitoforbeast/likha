<?php

namespace App\Http\Controllers;

use App\Models\BulkUploadRun;
use App\Models\UploadLogV2;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * /queue-manager — system-wide queue dashboard for CEO + MOIC.
 *
 *   GET    /queue-manager                   UI
 *   GET    /queue-manager/data              JSON for live polling
 *   POST   /queue-manager/restart-workers   queue:restart (graceful)
 *   POST   /queue-manager/clear-pending     TRUNCATE jobs
 *   POST   /queue-manager/clear-failed      TRUNCATE failed_jobs
 *   POST   /queue-manager/nuclear-reset     all of above + mark v2 runs cancelled
 */
class QueueManagerController extends Controller
{
    /** Allow CEO + Marketing - OIC. */
    private function checkAccess(): void
    {
        $raw  = Auth::user()?->employeeProfile?->role ?? '';
        $norm = preg_replace('/\s+/u', ' ', trim((string) $raw));
        $isCEO  = preg_match('/^ceo$/iu', $norm) === 1;
        $isMOIC = preg_match('/^marketing\s*[-–—]\s*oic$/iu', $norm) === 1;
        if (!($isCEO || $isMOIC)) abort(404);
    }

    private function checkCEO(): void
    {
        $raw  = Auth::user()?->employeeProfile?->role ?? '';
        $norm = preg_replace('/\s+/u', ' ', trim((string) $raw));
        if (preg_match('/^ceo$/iu', $norm) !== 1) abort(404);
    }

    private function isCurrentUserCEO(): bool
    {
        $raw  = Auth::user()?->employeeProfile?->role ?? '';
        $norm = preg_replace('/\s+/u', ' ', trim((string) $raw));
        return preg_match('/^ceo$/iu', $norm) === 1;
    }

    public function index()
    {
        $this->checkAccess();

        return view('queue_manager.index', [
            'isCEO' => $this->isCurrentUserCEO(),
            'data'  => $this->collectData(),
        ]);
    }

    /** Live-refresh JSON for the page polling. */
    public function data()
    {
        $this->checkAccess();
        return response()->json($this->collectData());
    }

    /** Snapshot of queue state. */
    private function collectData(): array
    {
        // Counters
        $pending = (int) DB::table('jobs')->count();
        $failed  = (int) DB::table('failed_jobs')->count();

        // Per-class breakdown using JSON_EXTRACT on payload
        // (works on MySQL 5.7+, MariaDB 10.2+).
        $byClass = DB::table('jobs')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.displayName')) as class_name, COUNT(*) as cnt")
            ->groupBy('class_name')
            ->orderByDesc('cnt')
            ->limit(50)
            ->get()
            ->map(fn($r) => [
                'class' => $r->class_name ?: '(unknown)',
                'count' => (int) $r->cnt,
            ])
            ->all();

        // Recent pending (latest 50)
        $recentJobs = DB::table('jobs')
            ->selectRaw("id, queue, attempts,
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.displayName')) as class_name,
                available_at, reserved_at, created_at")
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(function ($r) {
                return [
                    'id'           => (int) $r->id,
                    'class'        => $r->class_name ?: '(unknown)',
                    'queue'        => $r->queue,
                    'attempts'     => (int) $r->attempts,
                    'reserved'     => $r->reserved_at !== null,
                    'created_at'   => $r->created_at ? Carbon::createFromTimestamp($r->created_at)->setTimezone('Asia/Manila')->format('Y-m-d H:i:s') : null,
                    'available_at' => $r->available_at ? Carbon::createFromTimestamp($r->available_at)->setTimezone('Asia/Manila')->format('Y-m-d H:i:s') : null,
                ];
            })
            ->all();

        // Recent failed (latest 20)
        $recentFailed = DB::table('failed_jobs')
            ->selectRaw("id, connection, queue,
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.displayName')) as class_name,
                exception, failed_at")
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(function ($r) {
                $exc = (string) ($r->exception ?? '');
                $excHead = strtok($exc, "\n") ?: '';
                return [
                    'id'        => (int) $r->id,
                    'class'     => $r->class_name ?: '(unknown)',
                    'queue'     => $r->queue,
                    'failed_at' => $r->failed_at,
                    'error'     => mb_substr($excHead, 0, 300),
                ];
            })
            ->all();

        // Active workers — count via shell_exec (Linux only)
        $workersCount = $this->countActiveWorkers();

        return [
            'counters' => [
                'pending'        => $pending,
                'failed'         => $failed,
                'active_workers' => $workersCount,
            ],
            'by_class'      => $byClass,
            'recent_jobs'   => $recentJobs,
            'recent_failed' => $recentFailed,
            'now'           => now('Asia/Manila')->toDateTimeString(),
        ];
    }

    /**
     * Count "php artisan queue:work" processes via shell_exec.
     * Returns null kapag not Linux o disabled ang shell_exec.
     */
    private function countActiveWorkers(): ?int
    {
        if (!function_exists('shell_exec')) return null;
        if (PHP_OS_FAMILY !== 'Linux') return null;

        try {
            // pgrep -fc returns count of matching processes; fallback sa pgrep+wc kapag walang -c
            $out = @shell_exec('pgrep -fc "artisan queue:work" 2>/dev/null');
            if ($out === null) return null;
            $n = (int) trim((string) $out);
            return $n >= 0 ? $n : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** POST /queue-manager/restart-workers — graceful queue:restart. */
    public function restartWorkers(Request $request)
    {
        $this->checkAccess();

        try {
            Artisan::call('queue:restart');
            Log::info('queue-manager: workers restart signal sent by ' . (Auth::user()?->email ?? 'unknown'));
            return response()->json(['ok' => true, 'message' => 'Workers will exit after their current job.']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /** POST /queue-manager/clear-pending — TRUNCATE jobs. */
    public function clearPending(Request $request)
    {
        $this->checkAccess();
        $request->validate(['confirm' => 'required|in:YES_CLEAR_PENDING']);

        $before = (int) DB::table('jobs')->count();
        DB::table('jobs')->truncate();

        // Also mark in-flight V2 runs as cancelled (they have no jobs left to process)
        $runsAffected = BulkUploadRun::where('type', 'jnt_v2')
            ->whereIn('status', ['processing', 'queued', 'precheck'])
            ->update([
                'status'      => 'cancelled',
                'finished_at' => Carbon::now('Asia/Manila'),
                'message'     => 'Cancelled by queue-manager clear-pending action',
            ]);

        UploadLogV2::whereIn('status', ['queued', 'processing'])
            ->update(['status' => 'cancelled']);

        Log::warning('queue-manager: cleared pending jobs by ' . (Auth::user()?->email ?? 'unknown') . " — {$before} jobs cleared");

        return response()->json([
            'ok'             => true,
            'cleared'        => $before,
            'runs_cancelled' => $runsAffected,
        ]);
    }

    /** POST /queue-manager/clear-failed — TRUNCATE failed_jobs. */
    public function clearFailed(Request $request)
    {
        $this->checkAccess();
        $request->validate(['confirm' => 'required|in:YES_CLEAR_FAILED']);

        $before = (int) DB::table('failed_jobs')->count();
        DB::table('failed_jobs')->truncate();

        Log::info('queue-manager: cleared failed jobs by ' . (Auth::user()?->email ?? 'unknown') . " — {$before} jobs cleared");

        return response()->json(['ok' => true, 'cleared' => $before]);
    }

    /** POST /queue-manager/nuclear-reset — combo (CEO only). */
    public function nuclearReset(Request $request)
    {
        $this->checkCEO();
        $request->validate(['confirm' => 'required|in:YES_NUCLEAR_RESET']);

        $pendingBefore = (int) DB::table('jobs')->count();
        $failedBefore  = (int) DB::table('failed_jobs')->count();

        DB::table('jobs')->truncate();
        DB::table('failed_jobs')->truncate();

        Artisan::call('queue:restart');

        $runsAffected = BulkUploadRun::where('type', 'jnt_v2')
            ->whereIn('status', ['processing', 'queued', 'precheck'])
            ->update([
                'status'      => 'cancelled',
                'finished_at' => Carbon::now('Asia/Manila'),
                'message'     => 'Cancelled by queue-manager nuclear-reset',
            ]);

        UploadLogV2::whereIn('status', ['queued', 'processing'])
            ->update(['status' => 'cancelled']);

        Log::warning('queue-manager: NUCLEAR reset by ' . (Auth::user()?->email ?? 'unknown'), [
            'pending_cleared' => $pendingBefore,
            'failed_cleared'  => $failedBefore,
            'runs_cancelled'  => $runsAffected,
        ]);

        return response()->json([
            'ok'              => true,
            'pending_cleared' => $pendingBefore,
            'failed_cleared'  => $failedBefore,
            'runs_cancelled'  => $runsAffected,
        ]);
    }
}
