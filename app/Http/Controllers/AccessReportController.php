<?php

namespace App\Http\Controllers;

use App\Support\AccessRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 294: /owner/access-report (CEO only). What RecordPageAccess counted over the last N days (1-30, default 7):
 * one row per role, page pattern and decision, with the nav link it maps to, hits, distinct people and last time.
 */
class AccessReportController extends Controller
{
    private const DECISIONS = [AccessRule::WOULD_BLOCK => 'Would block', AccessRule::UNMAPPED => 'Unmapped'];

    public function index(Request $request, AccessRule $rule)
    {
        if (!AccessRule::isCeo(Auth::user()?->employeeProfile?->role)) abort(403);

        $days = filter_var($request->query('days'), FILTER_VALIDATE_INT);
        $days = $days === false ? 7 : max(1, min(30, $days));
        $role = trim((string) $request->query('role', ''));
        $decision = (string) $request->query('decision', '');
        if (!isset(self::DECISIONS[$decision])) $decision = '';

        $from = now()->copy()->setTimezone('Asia/Manila')->subDays($days - 1)->toDateString();

        $rows = collect();
        $roles = collect();
        if (Schema::hasTable('access_records')) {
            $base = DB::table('access_records')->where('day', '>=', $from);
            $roles = (clone $base)->distinct()->orderBy('role')->pluck('role');

            $rows = (clone $base)
                ->when($role !== '', fn ($q) => $q->where('role', $role))
                ->when($decision !== '', fn ($q) => $q->where('decision', $decision))
                ->groupBy('role', 'path_pattern', 'decision')
                ->selectRaw('role, path_pattern, decision, SUM(hits) AS hits, COUNT(DISTINCT user_id) AS people, MAX(last_at) AS last_at')
                ->orderByDesc('hits')
                ->orderBy('role')
                ->orderBy('path_pattern')
                ->get()
                ->each(function ($row) use ($rule) {
                    $row->nav_label = $rule->linkFor($row->path_pattern)['label'] ?? null;
                });
        }

        return view('owner.access-report', [
            'rows'      => $rows,
            'roles'     => $roles,
            'days'      => $days,
            'role'      => $role,
            'decision'  => $decision,
            'decisions' => self::DECISIONS,
            'mode'      => $rule->mode(),
        ]);
    }
}
