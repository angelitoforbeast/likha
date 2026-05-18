<?php

namespace App\Http\Controllers;

use App\Models\NavLink;
use App\Models\NavLinkRoleVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * CEO-managed nav settings. Controls which roles see which navlinks via a
 * matrix UI at /owner/nav-settings.
 *
 * Backend: nav_links (catalog) + nav_link_role_visibility (per-role checkbox).
 * Layout reads visibility via NavLink::visibleFor($role) when rendering the
 * top nav, replacing the old hardcoded @if($role) blocks.
 */
class NavSettingsController extends Controller
{
    /** Roles supported in the matrix. Matches employee_profiles.role values. */
    public const ROLES = [
        'CEO',
        'Marketing - OIC',
        'Marketing',
        'Data Encoder - OIC',
        'Data Encoder',
    ];

    /**
     * Strict CEO-only access. Aborts 403 for any other role / anonymous user.
     */
    private function checkAccess(): void
    {
        $role = Auth::user()?->employeeProfile?->role ?? null;
        if ($role !== 'CEO') {
            abort(403, 'Nav Settings is CEO-only.');
        }
    }

    public function index()
    {
        $this->checkAccess();

        $links = NavLink::with('roleVisibility')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // visibilityMap[link_id][role] = bool
        $visibilityMap = [];
        foreach ($links as $link) {
            foreach ($link->roleVisibility as $v) {
                $visibilityMap[$link->id][$v->role] = (bool) $v->is_visible;
            }
        }

        return view('owner.nav_settings', [
            'links'         => $links,
            'roles'         => self::ROLES,
            'visibilityMap' => $visibilityMap,
            'saved'         => session('nav_settings_saved', false),
        ]);
    }

    public function save(Request $request)
    {
        $this->checkAccess();

        $validated = $request->validate([
            // visibility[link_id][role] = '1'|'0' (checkbox)
            'visibility' => 'required|array',
            // sort_order[link_id] = integer (from drag-reorder or manual input)
            'sort_order' => 'nullable|array',
            'sort_order.*' => 'nullable|integer|min:0|max:9999',
        ]);

        $visibilityPayload = $validated['visibility'];
        $sortPayload       = $validated['sort_order'] ?? [];
        $now = now();

        DB::transaction(function () use ($visibilityPayload, $sortPayload, $now) {
            // Upsert visibility per (link, role). Missing entries treated as false.
            foreach (array_keys($visibilityPayload) as $linkId) {
                $perRole = (array) ($visibilityPayload[$linkId] ?? []);
                foreach (self::ROLES as $role) {
                    $isVisible = !empty($perRole[$role]);
                    NavLinkRoleVisibility::updateOrCreate(
                        ['nav_link_id' => (int) $linkId, 'role' => $role],
                        ['is_visible' => $isVisible, 'updated_at' => $now]
                    );
                }
            }

            // Update sort_order per link. Only touches rows present sa payload.
            foreach ($sortPayload as $linkId => $order) {
                NavLink::where('id', (int) $linkId)
                    ->update(['sort_order' => (int) $order, 'updated_at' => $now]);
            }
        });

        return redirect()
            ->route('owner.nav-settings')
            ->with('nav_settings_saved', true);
    }

    /**
     * CEO-only "view as role" toggle for the top nav. Stores in session so
     * the choice persists across page loads until cleared. The layout reads
     * this and renders the nav as if the CEO were the chosen role — without
     * actually changing the CEO's auth/permissions (preview only).
     */
    public function setViewAs(Request $request)
    {
        $this->checkAccess();
        $role = (string) $request->input('role', '');
        if ($role === '' || $role === 'CEO') {
            // Clear → revert to actual role view.
            session()->forget('nav_view_as_role');
        } elseif (in_array($role, self::ROLES, true)) {
            session(['nav_view_as_role' => $role]);
        }
        return back();
    }
}
