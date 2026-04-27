<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

/**
 * /owner/column-settings — CEO-only page for managing column visibility +
 * order across two tables:
 *
 *   - "owner_private"  → /owner/private main per-page summary table.
 *   - "campaigns"      → /ads_manager/campaigns table AND the inline
 *                        campaigns/adsets/ads expand inside /owner/private.
 *
 * Settings are stored as JSON in the existing `app_settings` table (one row
 * per key). Globally shared (no per-user rows) — matches the pattern used
 * by the supply-table state, so changes propagate to everyone.
 *
 * Saved JSON shape (per table):
 *   {
 *     "order":  [ "<col_id>", "<col_id>", ... ],   // explicit visual order
 *     "hidden": [ "<col_id>", "<col_id>", ... ]    // list of columns to hide
 *   }
 *
 * Unknown ids in either array are tolerated on read (filtered out at apply
 * time) so a deploy that adds/removes columns won't break stored configs.
 */
class OwnerColumnSettingsController extends Controller
{
    private const KEY_OWNER_PRIVATE = 'owner_private_cols';
    private const KEY_CAMPAIGNS     = 'campaigns_cols';
    // Breakeven CPP target % (single integer/decimal stored as string).
    // Default = 5 (i.e. 5% Proj.% target). Used by /owner/private to label
    // and compute the "Breakeven CPP (N%)" column.
    private const KEY_BREAKEVEN_PCT = 'owner_breakeven_target_pct';
    // Per-column conditional formatting rules — JSON map keyed by column id.
    // Shape: { "<col_id>": [ {"op":">=","value":30,"bg":"#fecaca","bold":true,"label":"High"}, ... ] }
    private const KEY_COL_FORMAT    = 'owner_private_col_format';

    /**
     * Column catalog: every column the user can show/hide/reorder per table.
     * IDs here match what each view's render code looks up. Keep in sync.
     */
    public const CATALOG = [
        'owner_private' => [
            ['id' => 'adspent',       'label' => 'Adspent'],
            ['id' => 'orders',        'label' => 'Orders'],
            ['id' => 'cpp',           'label' => 'CPP'],
            ['id' => 'proceed',       'label' => 'Proceed'],
            ['id' => 'pcpp',          'label' => 'P.CPP'],
            ['id' => 'tcpr',          'label' => 'TCPR (Pending Rate)'],
            ['id' => 'breakeven_cpp', 'label' => 'Breakeven CPP'],
            ['id' => 'proj_profit',   'label' => 'Proj.Profit'],
            ['id' => 'per_order',     'label' => '/Order'],
            ['id' => 'proj_pct',      'label' => 'Proj.%'],
            ['id' => 'proj_pct_1d',   'label' => 'Proj.%(1D)'],
            ['id' => 'proj_pct_3d',   'label' => 'Proj.%(3D)'],
            ['id' => 'proj_pct_7d',   'label' => 'Proj.%(7D)'],
            ['id' => 'proj_prof_1d',  'label' => 'Proj.Profit(1D)'],
            ['id' => 'proj_prof_3d',  'label' => 'Proj.Profit(3D)'],
            ['id' => 'proj_prof_7d',  'label' => 'Proj.Profit(7D)'],
            ['id' => 'jnt_rts',       'label' => 'RTS%'],
            ['id' => 'jnt_del',       'label' => 'Del%'],
            ['id' => 'jnt_transit',   'label' => 'Transit%'],
            ['id' => 'rts_set',       'label' => 'Set RTS%'],
            ['id' => 'price',         'label' => 'Price'],
            ['id' => 'item_val',      'label' => 'Item Val.'],
            ['id' => 'ship',          'label' => 'Ship'],
            ['id' => 'cod_fee',       'label' => 'COD Fee'],
        ],
        'campaigns' => [
            ['id' => 'on',             'label' => 'Off / On'],
            ['id' => 'name',           'label' => 'Name (Campaign / Ad set / Ad)'],
            ['id' => 'first_started',  'label' => 'First launched'],
            ['id' => 'days_running',   'label' => 'Days running'],
            ['id' => 'latest_started', 'label' => 'Latest start'],
            ['id' => 'spend',          'label' => 'Amount spent'],
            ['id' => 'cpm_1000',       'label' => 'CPM (per 1,000)'],
            ['id' => 'cpm_msg',        'label' => 'Cost per messaging'],
            ['id' => 'cpr',            'label' => 'Cost per result'],
            ['id' => 'cpp',            'label' => 'Cost per purchase'],
            ['id' => 'impressions',    'label' => 'Impressions'],
            ['id' => 'messages',       'label' => 'Messages'],
            ['id' => 'purchases',      'label' => 'Purchases'],
        ],
    ];

    /**
     * Default visibility per column when nothing has been saved yet.
     * Only the trimmed-down "Recommended" subset starts visible — the rest
     * default to hidden so the inline expand panel fits without horizontal
     * scroll. User can flip more on via /owner/column-settings.
     */
    public const DEFAULT_VISIBLE = [
        'owner_private' => [
            // Initial defaults derived from existing /owner/private layout.
            // Everything is visible by default — admins shrink as needed.
            'adspent', 'orders', 'cpp', 'proceed', 'pcpp',
            'tcpr', 'breakeven_cpp',
            'proj_profit', 'per_order', 'proj_pct',
            'proj_pct_1d', 'proj_pct_3d', 'proj_pct_7d',
            'proj_prof_1d', 'proj_prof_3d', 'proj_prof_7d',
            'jnt_rts', 'jnt_del', 'jnt_transit',
            'rts_set', 'price', 'item_val', 'ship', 'cod_fee',
        ],
        'campaigns' => [
            // Reduced default to keep the inline expand panel within the
            // /owner/private viewport (no horizontal scroll). Heavier columns
            // start hidden — admin can re-enable per the settings page.
            'on', 'name',
            'first_started', 'days_running', 'latest_started',
            'spend', 'cpp', 'impressions', 'purchases',
        ],
    ];

    private function checkAccess(): void
    {
        $roleRaw  = Auth::user()?->employeeProfile?->role ?? '';
        $roleNorm = preg_replace('/\s+/u', ' ', trim((string) $roleRaw));
        $isCEO    = preg_match('/^ceo$/iu', $roleNorm) === 1;
        if (!$isCEO) abort(404);
    }

    public function index()
    {
        $this->checkAccess();

        $cf = $this->loadColFormat();   // ['groups'=>[...], 'byCol'=>[...]]
        return view('owner.column_settings', [
            'catalog'          => self::CATALOG,
            'defaultVisible'   => self::DEFAULT_VISIBLE,
            'savedOwnerPrivate'=> $this->loadConfig('owner_private'),
            'savedCampaigns'   => $this->loadConfig('campaigns'),
            'breakevenTargetPct' => $this->loadBreakevenTargetPct(),
            // Editor uses the groups shape (shared rules across columns).
            'colFormatGroups'  => $cf['groups'] ?? [],
        ]);
    }

    /** GET-side helper: current Breakeven CPP target % (default 5). */
    public function loadBreakevenTargetPct(): float
    {
        $row = DB::table('app_settings')->where('key', self::KEY_BREAKEVEN_PCT)->first(['value']);
        if ($row && is_numeric($row->value)) return (float) $row->value;
        return 5.0;
    }

    /**
     * Conditional formatting groups. Each group has:
     *   - cols:  string[]   — columns that share these rules
     *   - rules: array of   — { op, value, bg, bold, label }
     *
     * Returns shape:
     *   {
     *     "groups": [ { "cols":[...], "rules":[...] }, ... ],
     *     "byCol":  { "<col_id>": [ rule, ... ], ... }   ← flattened for the view
     *   }
     *
     * Backwards compat: if storage is in the old `{col_id: [rules]}` shape,
     * we convert each col into its own group on load (no schema upgrade
     * required — first save in the new UI persists the new shape).
     */
    public function loadColFormat(): array
    {
        $empty = ['groups' => [], 'byCol' => []];
        $row = DB::table('app_settings')->where('key', self::KEY_COL_FORMAT)->first(['value']);
        if (!$row || !$row->value) return $empty;
        $decoded = json_decode($row->value, true);
        if (!is_array($decoded)) return $empty;

        $allowedIds = array_column(self::CATALOG['owner_private'], 'id');
        $allowedSet = array_flip($allowedIds);
        $allowedOps = ['>', '>=', '=', '<=', '<'];

        $cleanRule = function ($r) use ($allowedOps) {
            if (!is_array($r)) return null;
            $op = (string) ($r['op'] ?? '');
            if (!in_array($op, $allowedOps, true)) return null;
            if (!is_numeric($r['value'] ?? null)) return null;
            $bg = trim((string) ($r['bg'] ?? '#fee2e2'));
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $bg)) $bg = '#fee2e2';
            return [
                'op'    => $op,
                'value' => (float) $r['value'],
                'bg'    => strtolower($bg),
                'bold'  => !empty($r['bold']),
                'label' => mb_substr(trim((string) ($r['label'] ?? '')), 0, 40),
            ];
        };

        // Detect new (groups) vs legacy (byCol map) shape.
        $groups = [];
        if (isset($decoded['groups']) && is_array($decoded['groups'])) {
            foreach ($decoded['groups'] as $g) {
                if (!is_array($g)) continue;
                $cols = [];
                foreach ((array)($g['cols'] ?? []) as $c) {
                    if (is_string($c) && isset($allowedSet[$c]) && !in_array($c, $cols, true)) $cols[] = $c;
                }
                $rules = [];
                foreach ((array)($g['rules'] ?? []) as $r) {
                    $cr = $cleanRule($r);
                    if ($cr) $rules[] = $cr;
                    if (count($rules) >= 20) break;
                }
                if (!empty($cols) && !empty($rules)) {
                    $groups[] = ['cols' => $cols, 'rules' => $rules];
                }
                if (count($groups) >= 30) break;
            }
        } else {
            // Legacy {col_id: [rules]} → one group per column.
            foreach ($decoded as $colId => $rules) {
                if (!is_string($colId) || !isset($allowedSet[$colId]) || !is_array($rules)) continue;
                $clean = [];
                foreach ($rules as $r) {
                    $cr = $cleanRule($r);
                    if ($cr) $clean[] = $cr;
                    if (count($clean) >= 20) break;
                }
                if (!empty($clean)) {
                    $groups[] = ['cols' => [$colId], 'rules' => $clean];
                }
            }
        }

        // Build flat byCol view (one column may legitimately appear in many
        // groups — we concatenate so the view evaluates them in group order).
        $byCol = [];
        foreach ($groups as $g) {
            foreach ($g['cols'] as $c) {
                if (!isset($byCol[$c])) $byCol[$c] = [];
                foreach ($g['rules'] as $r) $byCol[$c][] = $r;
            }
        }

        return ['groups' => $groups, 'byCol' => $byCol];
    }

    /** POST /owner/column-settings/breakeven-pct — single number 0..100. */
    public function saveBreakevenPct(Request $request)
    {
        $this->checkAccess();
        $val = $request->input('value');
        if (!is_numeric($val)) return response()->json(['ok' => false, 'error' => 'Invalid number'], 422);
        $val = (float) $val;
        if ($val < 0 || $val > 100) return response()->json(['ok' => false, 'error' => 'Out of range'], 422);
        DB::table('app_settings')->updateOrInsert(
            ['key' => self::KEY_BREAKEVEN_PCT],
            ['value' => (string) $val, 'updated_at' => now(), 'created_at' => now()]
        );
        return response()->json(['ok' => true, 'value' => $val]);
    }

    /**
     * POST /owner/column-settings/col-format — replaces the full groups list.
     * Body: { groups: [ { cols:[...], rules:[...] }, ... ] }
     * Stored shape: { "groups": [...] } (no byCol persisted — derived on read).
     */
    public function saveColFormat(Request $request)
    {
        $this->checkAccess();
        $payload = $request->input('groups');
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($payload)) return response()->json(['ok' => false, 'error' => 'Invalid payload'], 422);

        $allowedIds = array_column(self::CATALOG['owner_private'], 'id');
        $allowedSet = array_flip($allowedIds);
        $allowedOps = ['>', '>=', '=', '<=', '<'];

        $groups = [];
        foreach ($payload as $g) {
            if (!is_array($g)) continue;
            $cols = [];
            foreach ((array)($g['cols'] ?? []) as $c) {
                if (is_string($c) && isset($allowedSet[$c]) && !in_array($c, $cols, true)) $cols[] = $c;
            }
            $rules = [];
            foreach ((array)($g['rules'] ?? []) as $r) {
                if (!is_array($r)) continue;
                $op = (string) ($r['op'] ?? '');
                if (!in_array($op, $allowedOps, true)) continue;
                if (!is_numeric($r['value'] ?? null)) continue;
                $bg = trim((string) ($r['bg'] ?? '#fee2e2'));
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $bg)) $bg = '#fee2e2';
                $rules[] = [
                    'op'    => $op,
                    'value' => (float) $r['value'],
                    'bg'    => strtolower($bg),
                    'bold'  => !empty($r['bold']),
                    'label' => mb_substr(trim((string) ($r['label'] ?? '')), 0, 40),
                ];
                if (count($rules) >= 20) break;
            }
            if (!empty($cols) && !empty($rules)) {
                $groups[] = ['cols' => $cols, 'rules' => $rules];
            }
            if (count($groups) >= 30) break;
        }

        DB::table('app_settings')->updateOrInsert(
            ['key' => self::KEY_COL_FORMAT],
            ['value' => json_encode(['groups' => $groups], JSON_UNESCAPED_SLASHES),
             'updated_at' => now(), 'created_at' => now()]
        );

        // Return groups + flattened byCol so the client can refresh its state
        // without a full reload.
        $byCol = [];
        foreach ($groups as $g) {
            foreach ($g['cols'] as $c) {
                if (!isset($byCol[$c])) $byCol[$c] = [];
                foreach ($g['rules'] as $r) $byCol[$c][] = $r;
            }
        }
        return response()->json(['ok' => true, 'groups' => $groups, 'byCol' => $byCol]);
    }

    /**
     * POST /owner/column-settings/save
     * Body: { table: 'owner_private'|'campaigns', order: [...], hidden: [...] }
     */
    public function save(Request $request)
    {
        $this->checkAccess();

        $table = (string) $request->input('table', '');
        if (!array_key_exists($table, self::CATALOG)) {
            return response()->json(['ok' => false, 'error' => 'Unknown table'], 422);
        }

        $allowedIds = array_column(self::CATALOG[$table], 'id');
        $allowedSet = array_flip($allowedIds);

        $clean = function (array $arr) use ($allowedSet) {
            $out  = [];
            $seen = [];
            foreach ($arr as $v) {
                if (!is_string($v)) continue;
                $v = trim($v);
                if ($v === '' || isset($seen[$v]) || !isset($allowedSet[$v])) continue;
                $seen[$v] = true;
                $out[] = $v;
            }
            return $out;
        };

        $order  = $clean((array) $request->input('order',  []));
        $hidden = $clean((array) $request->input('hidden', []));

        $payload = ['order' => $order, 'hidden' => $hidden];

        $key = $this->keyFor($table);
        DB::table('app_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($payload, JSON_UNESCAPED_SLASHES), 'updated_at' => now(), 'created_at' => now()]
        );

        return response()->json(['ok' => true, 'config' => $payload]);
    }

    /**
     * Load resolved config for a given table — applies catalog filtering and
     * fills in defaults so callers can rely on the shape even when no row
     * has been saved yet.
     */
    public function loadConfig(string $table): array
    {
        if (!array_key_exists($table, self::CATALOG)) {
            return ['order' => [], 'hidden' => []];
        }

        $row = DB::table('app_settings')->where('key', $this->keyFor($table))->first(['value']);
        $allowedIds = array_column(self::CATALOG[$table], 'id');
        $allowedSet = array_flip($allowedIds);

        $order = $allowedIds; // default: catalog order
        $hidden = [];

        if ($row && $row->value) {
            $decoded = json_decode($row->value, true);
            if (is_array($decoded)) {
                if (!empty($decoded['order']) && is_array($decoded['order'])) {
                    $valid = [];
                    foreach ($decoded['order'] as $id) {
                        if (is_string($id) && isset($allowedSet[$id])) $valid[] = $id;
                    }
                    // Append any catalog ids not in the saved order so new
                    // columns added to the catalog after the save still appear.
                    foreach ($allowedIds as $id) {
                        if (!in_array($id, $valid, true)) $valid[] = $id;
                    }
                    $order = $valid;
                }
                if (!empty($decoded['hidden']) && is_array($decoded['hidden'])) {
                    foreach ($decoded['hidden'] as $id) {
                        if (is_string($id) && isset($allowedSet[$id])) $hidden[] = $id;
                    }
                }
            }
        } else {
            // Nothing saved → derive `hidden` from default-visible list.
            $vis = array_flip(self::DEFAULT_VISIBLE[$table] ?? $allowedIds);
            $hidden = array_values(array_filter($allowedIds, fn($id) => !isset($vis[$id])));
        }

        return ['order' => $order, 'hidden' => array_values(array_unique($hidden))];
    }

    private function keyFor(string $table): string
    {
        return $table === 'owner_private' ? self::KEY_OWNER_PRIVATE : self::KEY_CAMPAIGNS;
    }
}
