<?php

namespace App\Http\Controllers;

use App\Models\FeeSetting;
use App\Models\ItemImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

/**
 * /item — Item-first HOLD view (3-level: Item → Page → Campaign).
 *
 *  - Item + Page HOLD totals: same source/logic as /jnt/hold
 *    (macro_output waybills na WALA sa from_jnts = on hold), within a date range.
 *  - Campaign level: reuse ng existing /owner/private breakdown (via iframe),
 *    naka-key sa page_key = LOWER(TRIM(PAGE)).
 *  - Bawat item may photo (upload/View/Change) — naka-store sa item_images.
 *
 * Access: CEO, Marketing, Marketing - OIC (same gate as /owner/private).
 */
class ItemController extends Controller
{
    private const MO_TABLE       = 'macro_output';
    private const FJ_TABLE       = 'from_jnts';
    private const MO_ITEM_COL    = 'ITEM_NAME';
    private const MO_WAYBILL_COL = 'waybill';
    private const FJ_WAYBILL_COL = 'waybill_number';

    private function getNormalizedRole(): string
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';
        return preg_replace('/\s+/u', ' ', trim((string) $raw));
    }

    private function checkAccess(): void
    {
        $role = $this->getNormalizedRole();
        if (!in_array($role, ['CEO', 'Marketing - OIC', 'Marketing'], true)) {
            abort(404);
        }
    }

    /**
     * GET /item — the page.
     *
     * The /item view is based on owner/private's table (item→page→campaign) with
     * an ITEM aggregate tier on top. It needs the SAME view data as
     * OwnerPrivateController@index (pages, role/viewAs, column configs, fees).
     * Replicated here so owner/private stays untouched. Date defaults + per-page
     * rows are resolved client-side (fetches owner.private.item-summary).
     */
    public function index(Request $request)
    {
        $this->checkAccess();

        $driver = DB::getDriverName();
        $trimFn = $driver === 'pgsql' ? 'BTRIM' : 'TRIM';

        $pages = DB::table('ads_manager_reports')
            ->whereNotNull('page_name')
            ->selectRaw("$trimFn(page_name) AS page_name")
            ->distinct()->orderBy('page_name')->pluck('page_name')->toArray();

        $role           = $this->getNormalizedRole();
        $isCEO          = $role === 'CEO';
        $isMarketingOIC = $role === 'Marketing - OIC';

        $viewAs = strtolower(trim((string) $request->input('view_as', 'ceo')));
        if (!in_array($viewAs, ['ceo', 'marketing'], true)) $viewAs = 'ceo';
        $effectiveIsCEO = $isCEO && $viewAs === 'ceo';

        $viewRoleForCols = ($isCEO && $viewAs === 'marketing') ? 'Marketing' : $role;
        $colsCtrl = new \App\Http\Controllers\OwnerColumnSettingsController();
        $ownerPrivateColsConfig  = $colsCtrl->loadConfig('owner_private', $viewRoleForCols);
        $campaignsColsConfig     = $colsCtrl->loadConfig('campaigns', $viewRoleForCols);
        $breakevenTargetPct      = $colsCtrl->loadBreakevenTargetPct();
        $colFormatRules          = $colsCtrl->loadColFormat('owner_private')['byCol'] ?? [];
        $campaignsColFormatRules = $colsCtrl->loadColFormat('campaigns')['byCol'] ?? [];

        $host  = strtolower((string) $request->getHost());
        $today = (new \DateTime('now', new \DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $feeShipping = FeeSetting::getRate('shipping_fee_per_order', $host, $today);
        $feeCodRate  = FeeSetting::getRate('cod_fee_rate',           $host, $today);
        $feeVatRate  = FeeSetting::getRate('cod_fee_vat_rate',       $host, $today);

        return view('item.index', compact(
            'pages', 'isCEO', 'isMarketingOIC', 'viewAs', 'effectiveIsCEO',
            'ownerPrivateColsConfig', 'campaignsColsConfig',
            'breakevenTargetPct', 'colFormatRules', 'campaignsColFormatRules',
            'feeShipping', 'feeCodRate', 'feeVatRate'
        ));
    }

    /** GET /item/data — item + page HOLD totals (JSON). */
    public function data(Request $request)
    {
        $this->checkAccess();

        [$startAt, $endAt] = $this->parseRange((string) $request->input('date_range', ''));
        $q = trim((string) $request->input('q', ''));

        $driver  = DB::connection()->getDriverName();
        $qcol    = fn ($c) => $driver === 'pgsql' ? "mo.\"$c\"" : "mo.`$c`";
        $moItem  = $qcol(self::MO_ITEM_COL);
        $moPage  = $qcol('PAGE');
        $moTs    = $qcol('TIMESTAMP');
        $tsExpr  = $driver === 'pgsql'
            ? "to_timestamp($moTs, 'HH24:MI DD-MM-YYYY')"
            : "STR_TO_DATE($moTs, '%H:%i %d-%m-%Y')";
        $likeOp  = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';

        // HOLD base: macro_output waybills NOT in from_jnts (not yet shipped) within range.
        $base = DB::table(self::MO_TABLE . ' as mo')
            ->leftJoin(self::FJ_TABLE . ' as fj', 'fj.' . self::FJ_WAYBILL_COL, '=', 'mo.' . self::MO_WAYBILL_COL)
            ->whereNull('fj.' . self::FJ_WAYBILL_COL)
            ->whereRaw('NULLIF(TRIM(mo.' . self::MO_WAYBILL_COL . "), '') IS NOT NULL");

        if ($startAt && $endAt) {
            $base->whereBetween(DB::raw($tsExpr), [$startAt, $endAt]);
        }
        if ($q !== '') {
            $base->where(function ($w) use ($q, $likeOp, $moItem, $moPage) {
                $w->whereRaw("$moItem $likeOp ?", ["%{$q}%"])
                  ->orWhereRaw("$moPage $likeOp ?", ["%{$q}%"]);
            });
        }

        // Item + Page hold counts in one pass.
        $rows = (clone $base)
            ->selectRaw("$moItem as item_name, $moPage as page, COUNT(*) as hold_count")
            ->groupByRaw("$moItem, $moPage")
            ->get();

        // Build item → pages tree.
        $items = [];
        foreach ($rows as $r) {
            $item = trim((string) ($r->item_name ?? '')) ?: '—';
            $page = trim((string) ($r->page ?? '')) ?: '—';
            $cnt  = (int) $r->hold_count;
            if (!isset($items[$item])) $items[$item] = ['item_name' => $item, 'total_hold' => 0, 'pages' => []];
            $items[$item]['total_hold'] += $cnt;
            $items[$item]['pages'][] = [
                'page'       => $page,
                'page_key'   => mb_strtolower(trim($page)),
                'total_hold' => $cnt,
            ];
        }

        // Merge images.
        $imgMap = [];
        try {
            foreach (ItemImage::whereIn('item_name', array_keys($items))->get() as $img) {
                if ($img->image_path) $imgMap[$img->item_name] = url(Storage::disk('public')->url($img->image_path));
            }
        } catch (\Throwable $e) { /* table missing pre-migration → walang image */ }

        $out = array_values($items);
        foreach ($out as &$it) {
            usort($it['pages'], fn ($a, $b) => $b['total_hold'] <=> $a['total_hold']);
            $it['image_url'] = $imgMap[$it['item_name']] ?? null;
        }
        unset($it);
        usort($out, fn ($a, $b) => $b['total_hold'] <=> $a['total_hold']);

        return response()->json([
            'ok'          => true,
            'items'       => $out,
            'total_items' => count($out),
            'total_hold'  => array_sum(array_column($out, 'total_hold')),
        ]);
    }

    /** GET /item/images — map ng item_name → public photo URL (para sa item tier). */
    public function images(Request $request)
    {
        $this->checkAccess();
        $map = [];
        try {
            if (Schema::hasTable('item_images')) {
                foreach (ItemImage::whereNotNull('image_path')->get() as $img) {
                    $map[$img->item_name] = url(Storage::disk('public')->url($img->image_path));
                }
            }
        } catch (\Throwable $e) { /* table wala pa — walang photo */ }

        return response()->json(['ok' => true, 'images' => $map]);
    }

    /**
     * GET /item/suppliers — supplier(s) + PINAKABAGONG unit cost kada item, galing
     * Supply Finance (supply_order_items → supply_orders → suppliers). Keyed by
     * item_key (base item na lowercase, walang "N x" prefix — same normalization
     * ng SupplyFinanceController::itemKey). Discount lines (unit_cost <= 0) ay
     * hindi kasama. Isang entry kada supplier = latest PO niya para sa item.
     */
    public function suppliers(Request $request)
    {
        $this->checkAccess();
        // CEO LANG ang makakakita ng supplier + presyo (data-layer: hindi lang UI hide —
        // walang ibabalik sa hindi-CEO, same pattern ng item_value_ceo).
        if ($this->getNormalizedRole() !== 'CEO') {
            return response()->json(['ok' => true, 'suppliers' => []]);
        }
        $map = [];
        try {
            if (Schema::hasTable('supply_order_items') && Schema::hasTable('supply_orders') && Schema::hasTable('suppliers')) {
                $rows = DB::table('supply_order_items as i')
                    ->join('supply_orders as o', 'o.id', '=', 'i.supply_order_id')
                    ->join('suppliers as s', 's.id', '=', 'o.supplier_id')
                    ->where('i.unit_cost', '>', 0)
                    ->orderByDesc('o.order_date')->orderByDesc('i.id')
                    ->get(['i.item_key', 's.id as supplier_id', 's.name as supplier', 'i.unit_cost', 'o.order_date', 'o.order_no']);
                $seen = [];
                foreach ($rows as $r) {
                    $k = (string) $r->item_key;
                    if (isset($seen[$k][$r->supplier_id])) continue; // latest lang kada supplier
                    $seen[$k][$r->supplier_id] = true;
                    $map[$k][] = [
                        'supplier'   => $r->supplier,
                        'unit_cost'  => (float) $r->unit_cost,
                        'order_date' => (string) $r->order_date,
                        'order_no'   => $r->order_no,
                    ];
                }
            }
        } catch (\Throwable $e) { /* supply tables wala pa — walang supplier */ }

        return response()->json(['ok' => true, 'suppliers' => $map]);
    }

    /**
     * GET /item/photo — LISTAHAN ng mga item para pamahalaan ang photos.
     * SAME universe as /item (union: owner/private running items + jnt/hold>0),
     * date-scoped — binubuo client-side (fetch item.data + owner.private.item-summary
     * + item.images). Mga WALANG image = nasa itaas. Optional ?item=<name> → highlight.
     * Date range = galing sa ?start_date/?end_date (default: same as /item / /jnt/hold).
     */
    public function photoForm(Request $request)
    {
        $this->checkAccess();

        $valid = fn ($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
        $today = Carbon::now('Asia/Manila')->toDateString();
        $first = Carbon::now('Asia/Manila')->startOfMonth()->subMonth()->toDateString();

        $start = (string) $request->query('start_date', '');
        $end   = (string) $request->query('end_date', '');
        if (!$valid($start)) $start = $first;
        if (!$valid($end))   $end   = $today;
        if ($start > $end)   [$start, $end] = [$end, $start];

        return view('item.photo', [
            'focus'     => trim((string) $request->query('item', '')),
            'startDate' => $start,
            'endDate'   => $end,
            'isCEO'     => $this->getNormalizedRole() === 'CEO', // supplier/presyo column = CEO lang
        ]);
    }

    /** POST /item/photo — i-save ang na-upload na photo, tapos balik sa form. */
    public function photoStore(Request $request)
    {
        $this->checkAccess();
        if (! Schema::hasTable('item_images')) {
            return back()->with('photo_error', 'item_images table wala pa — patakbuhin: php artisan migrate --force');
        }
        $data = $request->validate([
            'item_name' => 'required|string|max:255',
            'image'     => 'required|image|mimes:jpg,jpeg,png,webp|max:10240',
        ]);

        $existing = ItemImage::where('item_name', $data['item_name'])->first();
        if ($existing && $existing->image_path) {
            try { Storage::disk('public')->delete($existing->image_path); } catch (\Throwable $e) {}
        }

        $path = $request->file('image')->store('item-images', 'public');
        ItemImage::updateOrCreate(
            ['item_name' => $data['item_name']],
            ['image_path' => $path, 'updated_by' => Auth::id()]
        );

        return redirect()
            ->route('item.photo', ['item' => $data['item_name']])
            ->with('photo_ok', 'Na-upload ang photo para sa "' . $data['item_name'] . '".');
    }

    /** POST /item/image/delete — tanggalin ang photo ng isang item (AJAX). */
    public function deleteImage(Request $request)
    {
        $this->checkAccess();
        $data = $request->validate(['item_name' => 'required|string|max:255']);
        if (! Schema::hasTable('item_images')) {
            return response()->json(['ok' => false, 'message' => 'item_images table wala pa'], 200);
        }
        $rec = ItemImage::where('item_name', $data['item_name'])->first();
        if ($rec) {
            if ($rec->image_path) {
                try { Storage::disk('public')->delete($rec->image_path); } catch (\Throwable $e) {}
            }
            $rec->delete();
        }
        return response()->json(['ok' => true]);
    }

    /** POST /item/image — upload/palit ng item photo (AJAX; legacy inline). */
    public function uploadImage(Request $request)
    {
        $this->checkAccess();
        if (! Schema::hasTable('item_images')) {
            return response()->json(['ok' => false, 'message' => 'item_images table wala pa — patakbuhin: php artisan migrate --force'], 200);
        }
        $data = $request->validate([
            'item_name' => 'required|string|max:255',
            'image'     => 'required|image|mimes:jpg,jpeg,png,webp|max:10240',
        ]);

        // Delete old file kung meron.
        $existing = ItemImage::where('item_name', $data['item_name'])->first();
        if ($existing && $existing->image_path) {
            try { Storage::disk('public')->delete($existing->image_path); } catch (\Throwable $e) {}
        }

        $path = $request->file('image')->store('item-images', 'public');
        ItemImage::updateOrCreate(
            ['item_name' => $data['item_name']],
            ['image_path' => $path, 'updated_by' => Auth::id()]
        );

        return response()->json(['ok' => true, 'url' => url(Storage::disk('public')->url($path))]);
    }

    /** date_range "YYYY-MM-DD to YYYY-MM-DD" → [startAt, endAt] datetime strings. */
    private function parseRange(string $range): array
    {
        if ($range === '') return [null, null];
        $parts = preg_split('/\s+(?:to|-)\s+/i', $range);
        $s = $parts[0] ?? null;
        $e = $parts[1] ?? $parts[0] ?? null;
        try {
            $startAt = $s ? Carbon::createFromFormat('Y-m-d', $s)->startOfDay()->format('Y-m-d H:i:s') : null;
            $endAt   = $e ? Carbon::createFromFormat('Y-m-d', $e)->endOfDay()->format('Y-m-d H:i:s') : null;
            return [$startAt, $endAt];
        } catch (\Throwable $ex) {
            return [null, null];
        }
    }
}
