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
            'links'              => $links,
            'roles'              => self::ROLES,
            'visibilityMap'      => $visibilityMap,
            'saved'              => session('nav_settings_saved', false),
            'unregisteredRoutes' => NavLink::unregisteredRoutes(),
        ]);
    }

    public function save(Request $request)
    {
        $this->checkAccess();

        $validated = $request->validate([
            // visibility[link_id][role] = '1' (checkbox). Unchecked checkboxes
            // are NOT submitted by HTML — so the link_id may be missing entirely
            // from this array when all its checkboxes are off. We compensate by
            // iterating ALL known nav_links below.
            'visibility' => 'nullable|array',
            // sort_order[link_id] = integer (from drag-reorder or manual input)
            'sort_order' => 'nullable|array',
            'sort_order.*' => 'nullable|integer|min:0|max:9999',
        ]);

        $visibilityPayload = $validated['visibility'] ?? [];
        $sortPayload       = $validated['sort_order'] ?? [];
        $now = now();

        DB::transaction(function () use ($visibilityPayload, $sortPayload, $now) {
            // Iterate ALL nav_links — para mag-save din yung "all-unchecked" rows.
            // If the row's id isn't sa $visibilityPayload, we treat it as
            // "everything false" (which is the correct intent: user unchecked all
            // boxes for that row).
            $allLinkIds = NavLink::pluck('id');
            foreach ($allLinkIds as $linkId) {
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
     * Register a previously-undiscovered route as a NavLink. Defaults the new
     * link to CEO-only visibility (safest — CEO can grant to other roles via
     * the matrix). Derives label / key from the URL path; admin can rename
     * sa /owner/nav-settings later (currently no rename UI but DB-edit works).
     */
    public function addRoute(Request $request)
    {
        $this->checkAccess();

        $validated = $request->validate([
            'url'   => 'required|string|max:255',
            'label' => 'nullable|string|max:100',
        ]);

        $url = '/' . ltrim($validated['url'], '/');
        if (str_contains($url, '{')) {
            return back()->withErrors(['url' => 'Cannot add parameterized routes.']);
        }
        // Bail if already exists
        if (NavLink::where('route_url', $url)->exists()) {
            return back()->withErrors(['url' => 'Already registered.']);
        }

        // Derive key from URL: '/foo/bar/baz' → 'foo_bar_baz'
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($url, '/')));
        $key = substr($key, 0, 80);
        // Avoid key collisions: append number suffix if needed.
        $baseKey = $key;
        $suffix = 2;
        while (NavLink::where('key', $key)->exists()) {
            $key = $baseKey . '_' . $suffix++;
        }

        // Derive label: capitalize last path segment, replace separators
        $label = $validated['label'] ?? ucwords(str_replace(['-', '_', '/'], ' ', trim($url, '/')));
        $label = substr($label, 0, 100);

        $now = now();
        $maxSort = (int) NavLink::max('sort_order');
        $link = NavLink::create([
            'key'            => $key,
            'label'          => $label,
            'route_url'      => $url,
            // Auto-guess icon from URL keywords (NavIconGuesser maps common
            // route segments to Font Awesome classes). Falls back to a generic
            // chain link when no keyword matches.
            'icon'           => \App\Services\NavIconGuesser::guess($url),
            'active_pattern' => ltrim($url, '/') . '*',
            'sort_order'     => $maxSort + 1,
            'is_active'      => true,
        ]);

        // Default visibility: CEO only
        foreach (self::ROLES as $role) {
            NavLinkRoleVisibility::create([
                'nav_link_id' => $link->id,
                'role'        => $role,
                'is_visible'  => $role === 'CEO',
            ]);
        }

        return redirect()
            ->route('owner.nav-settings')
            ->with('nav_settings_saved', true);
    }

    /**
     * Reset ALL nav settings to factory defaults — pull from
     * NavLink::defaultData() (canonical seed). Wipes existing visibility +
     * sort_order, re-inserts. Also adds any links that exist in defaultData
     * but not yet sa DB (handy after adding a new link to the catalog).
     *
     * Existing links NOT in defaultData are LEFT ALONE (e.g., CEO manually
     * added one via direct DB insert — we don't delete random extras).
     */
    public function reset()
    {
        $this->checkAccess();

        $defaults = NavLink::defaultData();
        $now = now();
        $allRoles = self::ROLES;

        DB::transaction(function () use ($defaults, $now, $allRoles) {
            $sort = 1;
            foreach ($defaults as $d) {
                $visible = $d['visible'];
                $base = [
                    'label'          => $d['label'],
                    'route_url'      => $d['route_url'],
                    'icon'           => $d['icon'],
                    'active_pattern' => $d['active_pattern'],
                    'sort_order'     => $sort,
                    'is_active'      => true,
                    'updated_at'     => $now,
                ];
                // Upsert by `key` — preserves existing IDs (so audit / references stay valid).
                $link = NavLink::firstOrNew(['key' => $d['key']]);
                $link->fill($base);
                if (!$link->exists) $link->created_at = $now;
                $link->save();

                // Reset visibility per role: visible if listed sa defaults, else false.
                foreach ($allRoles as $role) {
                    NavLinkRoleVisibility::updateOrCreate(
                        ['nav_link_id' => $link->id, 'role' => $role],
                        ['is_visible'  => in_array($role, $visible, true), 'updated_at' => $now]
                    );
                }
                $sort++;
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
