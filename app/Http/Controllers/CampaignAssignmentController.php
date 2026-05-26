<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manual campaign ownership tagging — used by /ads_manager/campaigns/history.
 *
 * Endpoints:
 *   GET  /ads_manager/campaigns/assignments?campaign_ids=X,Y  → bulk fetch
 *   POST /ads_manager/campaigns/assignments                    → create/update + log
 *   GET  /ads_manager/campaigns/assignments/history?campaign_id=X → audit log
 *   GET  /ads_manager/employees                                → dropdown list
 *
 * Access:
 *   - Read endpoints: any /ads_manager accessor
 *   - Write endpoint: CEO + Marketing-OIC only (mirrors saveItemSetting gate)
 */
class CampaignAssignmentController extends Controller
{
    /** Read gate — anyone with /ads_manager access (mirrors existing pattern). */
    private function checkReadAccess(): void
    {
        $raw  = Auth::user()?->employeeProfile?->role ?? '';
        $norm = preg_replace('/\s+/u', ' ', trim((string) $raw));
        $allowed = ['ceo', 'marketing\s*[-–—]\s*oic', 'marketing'];
        $ok = false;
        foreach ($allowed as $pat) {
            if (preg_match('/^' . $pat . '$/iu', $norm) === 1) { $ok = true; break; }
        }
        if (!$ok) abort(404);
    }

    /** Write gate — CEO + Marketing-OIC only. */
    private function checkWriteAccess(): void
    {
        $raw  = Auth::user()?->employeeProfile?->role ?? '';
        $norm = preg_replace('/\s+/u', ' ', trim((string) $raw));
        $isCEO  = preg_match('/^ceo$/iu', $norm) === 1;
        $isMOIC = preg_match('/^marketing\s*[-–—]\s*oic$/iu', $norm) === 1;
        if (!($isCEO || $isMOIC)) abort(403, 'Only CEO + Marketing-OIC can manage assignments.');
    }

    /**
     * GET /ads_manager/campaigns/assignments?campaign_ids=X,Y,Z
     *
     * Bulk fetch current assignments for given campaign IDs. Returns map keyed
     * by campaign_id so frontend can stitch into render loop. Walang result for
     * unassigned campaigns (frontend treats missing as "Unassigned").
     */
    public function list(Request $request)
    {
        $this->checkReadAccess();

        $raw = trim((string) $request->query('campaign_ids', ''));
        if ($raw === '') return response()->json(['ok' => true, 'assignments' => new \stdClass()]);

        $ids = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $raw)),
            fn($s) => $s !== ''
        )));
        if (empty($ids)) return response()->json(['ok' => true, 'assignments' => new \stdClass()]);
        if (count($ids) > 500) abort(422, 'Too many campaign_ids (max 500 per request)');

        if (!Schema::hasTable('campaign_assignments')) {
            return response()->json(['ok' => true, 'assignments' => new \stdClass()]);
        }

        // Single query: get assignments + JOIN employee_profiles + JOIN users
        $rows = DB::table('campaign_assignments as ca')
            ->leftJoin('employee_profiles as ep', 'ep.id', '=', 'ca.assigned_employee_id')
            ->leftJoin('users as u', 'u.id', '=', 'ca.assigned_by_user_id')
            ->leftJoin('employee_profiles as ebp', 'ebp.user_id', '=', 'u.id')
            ->whereIn('ca.campaign_id', $ids)
            ->select([
                'ca.campaign_id',
                'ca.assigned_employee_id',
                'ca.note',
                'ca.assigned_at',
                'ca.updated_at',
                'ep.name as employee_name',
                'ep.role as employee_role',
                DB::raw('COALESCE(ebp.name, u.name) as assigned_by_name'),
            ])
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->campaign_id] = [
                'campaign_id'      => $r->campaign_id,
                'employee_id'      => $r->assigned_employee_id ? (int) $r->assigned_employee_id : null,
                'employee_name'    => $r->employee_name ?: ($r->assigned_employee_id ? '(deleted)' : null),
                'employee_role'    => $r->employee_role,
                'note'             => $r->note,
                'assigned_by_name' => $r->assigned_by_name,
                'updated_at'       => $r->updated_at,
            ];
        }

        return response()->json(['ok' => true, 'assignments' => (object) $out]);
    }

    /**
     * POST /ads_manager/campaigns/assignments
     *
     * Body: { campaign_id, employee_id (or null), note }
     *   - employee_id = null → "unassign" (sets to null, still keeps audit row)
     *
     * Behavior:
     *   1. Read current assignment for the campaign (or null kung wala pa)
     *   2. Upsert with new value
     *   3. Insert log row with old → new
     */
    public function save(Request $request)
    {
        $this->checkWriteAccess();

        $validated = $request->validate([
            'campaign_id'  => 'required|string|max:191',
            'employee_id'  => 'nullable|integer|min:1',
            'note'         => 'nullable|string|max:255',
        ]);

        $campaignId = trim((string) $validated['campaign_id']);
        $newEmployeeId = isset($validated['employee_id']) ? (int) $validated['employee_id'] : null;
        $note = isset($validated['note']) ? trim((string) $validated['note']) : null;
        if ($note === '') $note = null;

        // Verify employee exists (kung not-null) + belongs sa allowed roles
        if ($newEmployeeId !== null) {
            $ep = DB::table('employee_profiles')->where('id', $newEmployeeId)->first(['id', 'role']);
            if (!$ep) {
                return response()->json(['ok' => false, 'message' => 'Employee not found'], 422);
            }
            // Soft enforcement of role filter (matches dropdown logic)
            $roleNorm = preg_replace('/\s+/u', ' ', trim((string) $ep->role));
            $isAllowedRole = preg_match('/^(ceo|marketing\s*[-–—]\s*oic|marketing)$/iu', $roleNorm) === 1;
            if (!$isAllowedRole) {
                return response()->json(['ok' => false, 'message' => 'Employee role not allowed for campaign assignment'], 422);
            }
        }

        $userId = Auth::id();
        $now = now();

        // Read current state (for audit log)
        $current = DB::table('campaign_assignments')->where('campaign_id', $campaignId)->first(['assigned_employee_id']);
        $oldEmployeeId = $current ? (int) $current->assigned_employee_id : null;

        try {
            DB::table('campaign_assignments')->updateOrInsert(
                ['campaign_id' => $campaignId],
                [
                    'assigned_employee_id' => $newEmployeeId,
                    'assigned_by_user_id'  => $userId,
                    'note'                 => $note,
                    'assigned_at'          => $now,
                    'updated_at'           => $now,
                    'created_at'           => $current ? null : $now,
                ]
            );

            // Audit log — best effort, doesn't block save kung mag-fail
            try {
                DB::table('campaign_assignments_log')->insert([
                    'campaign_id'        => $campaignId,
                    'old_employee_id'    => $oldEmployeeId,
                    'new_employee_id'    => $newEmployeeId,
                    'changed_by_user_id' => $userId,
                    'note'               => $note,
                    'created_at'         => $now,
                ]);
            } catch (\Throwable $e) {
                \Log::warning('campaign_assignments_log insert failed: ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Save failed: ' . $e->getMessage()], 500);
        }

        // Return updated state — frontend can refresh row without re-fetch
        $refreshed = DB::table('campaign_assignments as ca')
            ->leftJoin('employee_profiles as ep', 'ep.id', '=', 'ca.assigned_employee_id')
            ->where('ca.campaign_id', $campaignId)
            ->select(['ca.campaign_id', 'ca.assigned_employee_id', 'ca.note', 'ca.updated_at',
                      'ep.name as employee_name', 'ep.role as employee_role'])
            ->first();

        return response()->json([
            'ok' => true,
            'assignment' => [
                'campaign_id'   => $campaignId,
                'employee_id'   => $refreshed && $refreshed->assigned_employee_id ? (int) $refreshed->assigned_employee_id : null,
                'employee_name' => $refreshed->employee_name ?? null,
                'employee_role' => $refreshed->employee_role ?? null,
                'note'          => $refreshed->note ?? null,
                'updated_at'    => $refreshed->updated_at ?? null,
            ],
        ]);
    }

    /**
     * GET /ads_manager/campaigns/assignments/history?campaign_id=X
     *
     * Returns audit log for a specific campaign, latest first.
     */
    public function history(Request $request)
    {
        $this->checkReadAccess();

        $campaignId = trim((string) $request->query('campaign_id', ''));
        if ($campaignId === '') abort(422, 'campaign_id required');

        if (!Schema::hasTable('campaign_assignments_log')) {
            return response()->json(['ok' => true, 'history' => []]);
        }

        $rows = DB::table('campaign_assignments_log as l')
            ->leftJoin('employee_profiles as oep', 'oep.id', '=', 'l.old_employee_id')
            ->leftJoin('employee_profiles as nep', 'nep.id', '=', 'l.new_employee_id')
            ->leftJoin('users as u', 'u.id', '=', 'l.changed_by_user_id')
            ->leftJoin('employee_profiles as ebp', 'ebp.user_id', '=', 'u.id')
            ->where('l.campaign_id', $campaignId)
            ->orderByDesc('l.id')
            ->limit(100)
            ->select([
                'l.id',
                'l.created_at',
                'l.note',
                'oep.name as old_employee_name',
                'oep.role as old_employee_role',
                'nep.name as new_employee_name',
                'nep.role as new_employee_role',
                DB::raw('COALESCE(ebp.name, u.name) as changed_by_name'),
            ])
            ->get();

        return response()->json(['ok' => true, 'history' => $rows]);
    }

    /**
     * GET /ads_manager/employees
     *
     * Returns list of employees na pwedeng i-assign — filtered to roles:
     * CEO, Marketing-OIC, Marketing. Returns: [{ id, name, role }, ...]
     */
    public function employees(Request $request)
    {
        $this->checkReadAccess();

        if (!Schema::hasTable('employee_profiles')) {
            return response()->json(['ok' => true, 'employees' => []]);
        }

        // Fuzzy role match — handles variations sa spacing/dashes ng "Marketing - OIC"
        $rows = DB::table('employee_profiles')
            ->whereRaw("
                LOWER(TRIM(REGEXP_REPLACE(role, '\\\\s+', ' '))) REGEXP
                '^(ceo|marketing\\\\s*[-–—]\\\\s*oic|marketing)$'
            ")
            ->orderByRaw("
                CASE
                    WHEN LOWER(role) LIKE 'ceo%' THEN 1
                    WHEN LOWER(role) LIKE 'marketing%oic%' THEN 2
                    ELSE 3
                END,
                name ASC
            ")
            ->get(['id', 'name', 'role']);

        return response()->json(['ok' => true, 'employees' => $rows]);
    }
}
