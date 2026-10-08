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
    private const MO_ITEM_COL    = 'ITEM_NAME';

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

        // Eksaktong string lang: ?layout=old = lumang table; ?layout=suppliers = lumang table na may
        // suppliers group, para sa CEO view lang. Kapag hindi CEO view, kapareho ito ng ?layout=old
        // (walang suppliers markup na lalabas). Lahat ng iba = bagong layout.
        $layout          = $request->query('layout');
        $layoutSuppliers = $layout === 'suppliers' && $effectiveIsCEO;
        $layoutOld       = $layout === 'old' || $layout === 'suppliers';

        return view('item.index', compact(
            'pages', 'isCEO', 'isMarketingOIC', 'viewAs', 'effectiveIsCEO', 'layoutOld', 'layoutSuppliers',
            'ownerPrivateColsConfig', 'campaignsColsConfig',
            'breakevenTargetPct', 'colFormatRules', 'campaignsColFormatRules',
            'feeShipping', 'feeCodRate', 'feeVatRate'
        ));
    }

    /** GET /item/data — item + page HOLD totals (JSON). */
    public function data(Request $request)
    {
        $this->checkAccess();

        [$startDate, $endDate] = $this->parseRange((string) $request->input('date_range', ''));
        $q = trim((string) $request->input('q', ''));

        $driver  = DB::connection()->getDriverName();
        $qcol    = fn ($c) => $driver === 'pgsql' ? "mo.\"$c\"" : "mo.`$c`";
        $moItem  = $qcol(self::MO_ITEM_COL);
        $moPage  = $qcol('PAGE');
        $likeOp  = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';

        // HOLD base: macro_output waybills NOT in from_jnts (not yet shipped), hindi cancelled,
        // within range (ts_date) — isang depinisyon sa HoldService (shared ng /item/worklist).
        $base = app(\App\Services\HoldService::class)->liveHoldQuery($startDate, $endDate);

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

    /** supply_orders.status na "hindi pa dumarating / hindi pa nabibilang" = open (inaprubahan ng reviewer). */
    private const OPEN_PO_STATUSES = ['ordered', 'delivered'];

    /**
     * GET /item/worklist?start_date&end_date — SOURCING WORKLISTS (CEO LANG).
     * Isang row kada base item na may live HOLD sa range, naka-classify sa isa sa apat na
     * listahan (SourcingClassifier): hanapan / may_quote / i_order / naka_order.
     * PO line = supply_order_items na ordered_qty > 0 at unit_cost >= 0 (hindi discount line);
     * open qty = Σ (ordered − received) sa POs na status ordered|delivered.
     * Lahat ng key = ItemSupplierQuote::keyFor / HoldService grouping (parehong resulta).
     */
    public function worklist(Request $request)
    {
        $this->checkAccess();
        // CEO LANG (data-layer, same pattern ng suppliers()/quotes()).
        if ($this->getNormalizedRole() !== 'CEO') {
            return response()->json(['ok' => true, 'counts' => new \stdClass(), 'items' => []]);
        }

        // Totoong petsa lang (hindi 2026-09-99) — kung hindi, default range.
        $valid = fn ($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)
            && checkdate((int) substr($s, 5, 2), (int) substr($s, 8, 2), (int) substr($s, 0, 4));
        $start = (string) $request->query('start_date', '');
        $end   = (string) $request->query('end_date', '');
        if (!$valid($start)) $start = Carbon::now('Asia/Manila')->startOfMonth()->subMonth()->toDateString();
        if (!$valid($end))   $end   = Carbon::now('Asia/Manila')->toDateString();
        if ($start > $end)   [$start, $end] = [$end, $start];

        $svc    = app(\App\Services\HoldService::class);
        $moItem = DB::getDriverName() === 'pgsql' ? 'mo."' . self::MO_ITEM_COL . '"' : 'mo.`' . self::MO_ITEM_COL . '`';
        $rows   = $svc->liveHoldQuery($start, $end)
            ->selectRaw("$moItem as item_name, COUNT(*) as hold_count")
            ->groupByRaw($moItem)
            ->get();
        $hold = array_filter($svc->groupUnitsByBaseItem($rows), fn ($i) => $i['units'] > 0);

        $counts = array_fill_keys(\App\Services\SourcingClassifier::LISTS, 0);
        if (!$hold) return response()->json(['ok' => true, 'counts' => $counts, 'items' => []]);

        $keyFor = fn ($n) => \App\Models\ItemSupplierQuote::keyFor((string) $n);
        $today  = Carbon::now('Asia/Manila')->startOfDay();

        // ── PO lines (Supply Finance) ──
        $po = []; // key => ['has_line'=>true, 'suppliers'=>[sid=>row], 'open'=>[...]]
        try {
            if (Schema::hasTable('supply_order_items') && Schema::hasTable('supply_orders') && Schema::hasTable('suppliers')) {
                $lines = DB::table('supply_order_items as i')
                    ->join('supply_orders as o', 'o.id', '=', 'i.supply_order_id')
                    ->join('suppliers as s', 's.id', '=', 'o.supplier_id')
                    ->where('i.ordered_qty', '>', 0)
                    ->where('i.unit_cost', '>=', 0)
                    ->orderByDesc('o.order_date')->orderByDesc('i.id')
                    ->get(['i.item_name', 'i.ordered_qty', 'i.received_qty', 'i.unit_cost',
                           'o.id as order_id', 'o.order_date', 'o.status', 's.id as supplier_id', 's.name as supplier']);
                foreach ($lines as $l) {
                    $k = $keyFor($l->item_name);
                    if (!isset($hold[$k])) continue;
                    $po[$k] ??= ['suppliers' => [], 'open' => null];
                    $date = substr((string) $l->order_date, 0, 10);
                    if ((float) $l->unit_cost > 0 && !isset($po[$k]['suppliers'][$l->supplier_id])) {
                        $po[$k]['suppliers'][$l->supplier_id] = [
                            'source' => 'po', 'supplier' => $l->supplier, 'price' => (float) $l->unit_cost,
                            'date' => $date, 'photo_url' => null,
                        ];
                    }
                    if (in_array((string) $l->status, self::OPEN_PO_STATUSES, true)) {
                        $ordered  = (int) $l->ordered_qty;
                        $received = (int) ($l->received_qty ?? 0);
                        $o = $po[$k]['open'] ?? ['order_ids' => [], 'supplier' => null, 'order_date' => null,
                                                  'ordered_qty' => 0, 'received_qty' => 0, 'open_qty' => 0];
                        $o['order_ids'][$l->order_id] = true;
                        $o['ordered_qty']  += $ordered;
                        $o['received_qty'] += $received;
                        $o['open_qty']     += max(0, $ordered - $received);
                        // Pinakamatagal nang naghihintay (lines ay date desc → huling makita = pinakaluma).
                        $o['supplier']   = $l->supplier;
                        $o['order_date'] = $date;
                        $po[$k]['open'] = $o;
                    }
                }
            }
        } catch (\Throwable $e) { /* supply tables wala pa — walang PO */ }

        // ── Quotes ──
        $quotes = []; // key => [row...]
        try {
            if (Schema::hasTable('item_supplier_quotes')) {
                $qrows = \App\Models\ItemSupplierQuote::query()->with('supplier')
                    ->whereIn('item_key', array_keys($hold))->orderBy('price')->get();
                foreach ($qrows as $q) {
                    $quotes[$q->item_key][] = [
                        'source'    => 'quote',
                        'supplier'  => $q->supplier?->name ?? ('#' . $q->supplier_id),
                        'price'     => $q->price !== null ? (float) $q->price : null,
                        'date'      => optional($q->updated_at)->format('Y-m-d'),
                        'photo_url' => $q->photo_path ? url(Storage::disk('public')->url($q->photo_path)) : null,
                    ];
                }
            }
        } catch (\Throwable $e) { /* wala pang table */ }

        // ── Lead time (supply_item_settings, keyed by item_name) ──
        $lead = [];
        try {
            if (Schema::hasTable('supply_item_settings')) {
                foreach (DB::table('supply_item_settings')->get(['item_name', 'lead_time_days']) as $s) {
                    $k = $keyFor($s->item_name);
                    if (isset($hold[$k]) && $s->lead_time_days !== null) $lead[$k] = (int) $s->lead_time_days;
                }
            }
        } catch (\Throwable $e) { /* wala pang table */ }

        // ── Item photos (item_images, keyed by raw variant name) ──
        $imgMap = [];
        try {
            if (Schema::hasTable('item_images')) {
                $names = [];
                foreach ($hold as $h) foreach (array_keys($h['variants']) as $n) $names[] = $n;
                foreach (ItemImage::whereIn('item_name', $names)->whereNotNull('image_path')->get() as $img) {
                    $imgMap[$img->item_name] = url(Storage::disk('public')->url($img->image_path));
                }
            }
        } catch (\Throwable $e) { /* wala pang table */ }

        $items = [];
        foreach ($hold as $k => $h) {
            $variants = [];
            foreach ($h['variants'] as $n => $u) $variants[] = ['name' => (string) $n, 'units' => (int) $u];
            usort($variants, fn ($a, $b) => [$b['units'], $a['name']] <=> [$a['units'], $b['name']]);

            $photoName = $variants[0]['name'];
            foreach ($variants as $v) { if (isset($imgMap[$v['name']])) { $photoName = $v['name']; break; } }

            $open    = $po[$k]['open'] ?? null;
            $openPo  = $open ? [
                'orders'         => count($open['order_ids']),
                'supplier'       => $open['supplier'],
                'order_date'     => $open['order_date'],
                'days_since'     => (int) Carbon::parse($open['order_date'], 'Asia/Manila')->startOfDay()->diffInDays($today),
                'ordered_qty'    => $open['ordered_qty'],
                'received_qty'   => $open['received_qty'],
                'open_qty'       => $open['open_qty'],
                'lead_time_days' => $lead[$k] ?? null,
            ] : null;

            $cls = \App\Services\SourcingClassifier::classify(
                isset($po[$k]), count($quotes[$k] ?? []), $openPo['open_qty'] ?? 0, (int) $h['units']
            );
            $counts[$cls['list']]++;

            $items[] = [
                'key'             => $k,
                'name'            => $h['name'],
                'variants'        => $variants,
                'hold_units'      => (int) $h['units'],
                'image_url'       => $imgMap[$photoName] ?? null,
                'photo_item_name' => $photoName,
                'list'            => $cls['list'],
                'shortfall'       => $cls['shortfall'],
                'suppliers'       => array_merge(array_values($po[$k]['suppliers'] ?? []), $quotes[$k] ?? []),
                'open_po'         => $openPo,
            ];
        }
        usort($items, fn ($a, $b) => [$b['hold_units'], $a['name']] <=> [$a['hold_units'], $b['name']]);

        return response()->json(['ok' => true, 'counts' => $counts, 'items' => $items]);
    }

    /**
     * GET /item/stock?start_date&end_date&view_as — stock, BENTA/ARAW, DOI, i-order, category at item value
     * kada base item (logic sa ItemStockService). Marketing ay may parehong numero (walang presyo);
     * item_value_ceo ay ibinabalik LANG sa CEO na view_as=ceo.
     */
    public function stock(Request $request)
    {
        $this->checkAccess();

        $valid = fn ($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)
            && checkdate((int) substr($s, 5, 2), (int) substr($s, 8, 2), (int) substr($s, 0, 4));
        $start = (string) $request->query('start_date', '');
        $end   = (string) $request->query('end_date', '');
        if (!$valid($start)) $start = Carbon::now('Asia/Manila')->startOfMonth()->subMonth()->toDateString();
        if (!$valid($end))   $end   = Carbon::now('Asia/Manila')->toDateString();
        if ($start > $end)   [$start, $end] = [$end, $start];

        $viewAs = strtolower(trim((string) $request->query('view_as', 'ceo')));
        if (!in_array($viewAs, ['ceo', 'marketing'], true)) $viewAs = 'ceo';
        $withCeoValue = $this->getNormalizedRole() === 'CEO' && $viewAs === 'ceo';

        return response()->json(app(\App\Services\ItemStockService::class)->build($start, $end, $withCeoValue));
    }

    // ═════════════════════════════════════════════════════════════════════
    //  SUPPLIER QUOTES — "may supplier na ba, magkano kada supplier" (CEO LANG)
    //  Hiwalay sa PO: item_supplier_quotes (supplier = existing suppliers table, presyo = bago).
    // ═════════════════════════════════════════════════════════════════════

    /** GET /item/quotes — lahat ng quotes (item_key → [...]) + listahan ng suppliers para sa dropdown. CEO lang. */
    public function quotes(Request $request)
    {
        $this->checkAccess();
        if ($this->getNormalizedRole() !== 'CEO') return response()->json(['ok' => true, 'suppliers' => [], 'quotes' => []]);

        $suppliers = []; $map = [];
        try {
            if (Schema::hasTable('suppliers')) {
                $suppliers = \App\Models\Supplier::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all();
            }
            if (Schema::hasTable('item_supplier_quotes')) {
                foreach ($this->quoteRows() as $key => $rows) $map[$key] = $rows;
            }
        } catch (\Throwable $e) { /* wala pang table */ }

        return response()->json(['ok' => true, 'suppliers' => $suppliers, 'quotes' => $map]);
    }

    /** POST /item/quotes — i-save/i-update ang quote ng isang supplier para sa isang item. CEO lang. */
    public function quoteSave(Request $request)
    {
        $this->checkAccess();
        if ($this->getNormalizedRole() !== 'CEO') return response()->json(['ok' => false, 'error' => 'CEO lang'], 403);

        $data = $request->validate([
            'item_name'   => 'required|string|max:255',
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'price'       => 'nullable|numeric|min:0|max:99999999',
            'moq'         => 'nullable|integer|min:0|max:100000000',
            'link'        => 'nullable|string|max:500',
            'note'        => 'nullable|string|max:255',
            // Photo ng produkto ng supplier — parehong rules ng item photo upload.
            'photo'       => 'nullable|image|mimes:jpg,jpeg,png,webp|max:10240',
        ]);
        $key = \App\Models\ItemSupplierQuote::keyFor($data['item_name']);

        // Bagong photo: i-store muna (generated name). WALANG photo = hindi ginagalaw ang dati.
        $newPath = null;
        if ($request->hasFile('photo') && Schema::hasColumn('item_supplier_quotes', 'photo_path')) {
            $newPath = $request->file('photo')->store('supplier-quote-images', 'public');
        }
        $oldPath = null;

        try {
            DB::transaction(function () use ($key, $data, $newPath, &$oldPath) {
                // History: kung nagbago ang presyo / MOQ / link → itala muna ang LUMANG values.
                $existing = \App\Models\ItemSupplierQuote::where('item_key', $key)
                    ->where('supplier_id', (int) $data['supplier_id'])->lockForUpdate()->first();
                if ($existing && $this->quoteChanged($existing, $data)) {
                    $this->writeQuoteHistory($existing, 'update');
                }

                $attrs = [
                    'item_name'  => trim($data['item_name']),
                    'price'      => $data['price'] ?? null,
                    'moq'        => $data['moq'] ?? null,
                    'link'       => isset($data['link']) ? trim((string) $data['link']) : null,
                    'note'       => isset($data['note']) ? trim((string) $data['note']) : null,
                    'updated_by' => Auth::id(),
                ];
                if ($newPath !== null) {
                    $oldPath = $existing?->photo_path;
                    $attrs['photo_path'] = $newPath;
                }
                \App\Models\ItemSupplierQuote::updateOrCreate(
                    ['item_key' => $key, 'supplier_id' => (int) $data['supplier_id']],
                    $attrs
                );
            });
        } catch (\Throwable $e) {
            if ($newPath) { try { Storage::disk('public')->delete($newPath); } catch (\Throwable $ex) {} }
            throw $e;
        }
        // Pagkatapos ng commit lang tanggalin ang napalitang photo.
        if ($oldPath && $oldPath !== $newPath) {
            try { Storage::disk('public')->delete($oldPath); } catch (\Throwable $e) {}
        }

        return response()->json(['ok' => true, 'quotes' => $this->quoteRows($key)[$key] ?? []]);
    }

    /**
     * POST /item/category — itakda (o tanggalin) ang category ng isang item. CEO lang.
     * Walang category_id at walang new_category = "Walang category" (buburahin ang assignment).
     */
    public function categorySave(Request $request)
    {
        $this->checkAccess();
        if ($this->getNormalizedRole() !== 'CEO') return response()->json(['ok' => false, 'error' => 'CEO lang'], 403);
        if (! Schema::hasTable('item_categories') || ! Schema::hasTable('item_category_assignments')) {
            return response()->json(['ok' => false, 'message' => 'item_categories table wala pa — patakbuhin: php artisan migrate --force'], 200);
        }

        $data = $request->validate([
            'item_name'    => 'required|string|max:190',
            'category_id'  => 'nullable|integer|exists:item_categories,id',
            'new_category' => 'nullable|string|max:60',
        ]);

        $key = \App\Support\ItemBaseKey::key($data['item_name']);
        if ($key === '') {
            throw \Illuminate\Validation\ValidationException::withMessages(['item_name' => 'Walang laman ang pangalan ng item.']);
        }
        // Ang lowercase ay pwedeng pahabain ang key (hal. "İ") — dapat kasya sa item_key(190).
        if (mb_strlen($key) > 190) {
            throw \Illuminate\Validation\ValidationException::withMessages(['item_name' => 'Masyadong mahaba ang pangalan ng item.']);
        }
        // Blank new_category (TrimStrings → null) = walang ipinadala. Parehong ipinadala = malabo.
        $newName = trim((string) ($data['new_category'] ?? ''));
        if ($newName !== '' && isset($data['category_id'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['new_category' => 'Pumili ng category O maglagay ng bago, hindi pareho.']);
        }

        $categoryId = DB::transaction(function () use ($data, $key, $newName) {
            $categoryId = isset($data['category_id']) ? (int) $data['category_id'] : null;

            if ($newName !== '') {
                $byName = fn () => \App\Models\ItemCategory::whereRaw('LOWER(name) = ?', [mb_strtolower($newName, 'UTF-8')])->first();
                $existing = $byName();
                if (! $existing) {
                    try {
                        // Savepoint — para hindi masira ang outer transaction (pgsql) kapag unique violation.
                        $existing = DB::transaction(fn () => \App\Models\ItemCategory::create([
                            'name'       => $newName,
                            'sort_order' => ((int) \App\Models\ItemCategory::max('sort_order')) + 1,
                        ]));
                    } catch (\Illuminate\Database\QueryException $e) {
                        // Double submit, o case/accent-insensitive collation (MySQL) na nakatugma sa existing.
                        $existing = $byName() ?? \App\Models\ItemCategory::where('name', $newName)->first();
                        if (! $existing) {
                            throw \Illuminate\Validation\ValidationException::withMessages(['new_category' => 'Hindi ma-save ang bagong category — subukan ulit.']);
                        }
                    }
                }
                $categoryId = $existing->id;
            }

            if ($categoryId === null) {
                \App\Models\ItemCategoryAssignment::where('item_key', $key)->delete();
            } else {
                \App\Models\ItemCategoryAssignment::updateOrCreate(
                    ['item_key' => $key],
                    ['category_id' => $categoryId, 'updated_by' => Auth::id()]
                );
            }

            return $categoryId;
        });

        $categories = \App\Models\ItemCategory::orderBy('sort_order')->orderBy('id')->get(['id', 'name'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all();

        return response()->json(['ok' => true, 'category_id' => $categoryId, 'categories' => $categories]);
    }

    /** POST /item/supply-settings — lead time at safety days ng isang item (by base key). CEO lang. */
    public function supplySettingsSave(Request $request)
    {
        $this->checkAccess();
        if ($this->getNormalizedRole() !== 'CEO') return response()->json(['ok' => false, 'error' => 'CEO lang'], 403);
        if (! Schema::hasTable('supply_item_settings')) {
            return response()->json(['ok' => false, 'message' => 'supply_item_settings table wala pa — patakbuhin: php artisan migrate --force'], 200);
        }

        $data = $request->validate([
            'item_name'      => 'required|string|max:255',
            'lead_time_days' => 'required|integer|min:0|max:255',
            'safety_days'    => 'nullable|integer|min:0|max:255',
        ]);

        $parsed = \App\Support\ItemBaseKey::parse($data['item_name']);
        if ($parsed['key'] === '') {
            throw \Illuminate\Validation\ValidationException::withMessages(['item_name' => 'Walang laman ang pangalan ng item.']);
        }

        DB::transaction(function () use ($data, $parsed) {
            $values = [
                'lead_time_days' => (int) $data['lead_time_days'],
                'updated_at'     => now(),
            ];
            // Palugit: may value = safety_days at palugit_override; blangko = balik sa lifecycle (override NULL, safety_days hindi ginagalaw).
            $palugit = $data['safety_days'] ?? null;
            if ($palugit !== null) $values['safety_days'] = (int) $palugit;
            if (Schema::hasColumn('supply_item_settings', 'palugit_override')) {
                $values['palugit_override'] = $palugit === null ? null : (int) $palugit;
            }
            // Maliit ang table — i-filter sa PHP para parehong key ang gamit (kahit magkaiba ang case/spacing).
            $ids = DB::table('supply_item_settings')->get(['id', 'item_name'])
                ->filter(fn ($r) => \App\Support\ItemBaseKey::key((string) $r->item_name) === $parsed['key'])
                ->pluck('id')->all();

            if (! $ids) {
                try {
                    // Savepoint — double submit / collation match = unique violation → update path na lang.
                    DB::transaction(fn () => DB::table('supply_item_settings')->insert($values + [
                        'item_name'  => $parsed['base'],
                        'created_at' => now(),
                    ]));
                    return;
                } catch (\Illuminate\Database\QueryException $e) {
                    $ids = DB::table('supply_item_settings')->get(['id', 'item_name'])
                        ->filter(fn ($r) => mb_strtolower(trim((string) $r->item_name), 'UTF-8') === mb_strtolower($parsed['base'], 'UTF-8')
                            || \App\Support\ItemBaseKey::key((string) $r->item_name) === $parsed['key'])
                        ->pluck('id')->all();
                    if (! $ids) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['item_name' => 'Hindi ma-save ang settings — subukan ulit.']);
                    }
                }
            }
            DB::table('supply_item_settings')->whereIn('id', $ids)->update($values);
        });

        return response()->json(['ok' => true]);
    }

    /** Nagbago ba ang presyo (2dp), MOQ o link? null ≠ 0; "100" = "100.00". */
    private function quoteChanged(\App\Models\ItemSupplierQuote $q, array $data): bool
    {
        $price = fn ($v) => ($v === null || $v === '') ? null : number_format((float) $v, 2, '.', '');
        $moq   = fn ($v) => ($v === null || $v === '') ? null : (int) $v;
        $link  = fn ($v) => ($v === null || trim((string) $v) === '') ? null : trim((string) $v);

        return $price($q->price) !== $price($data['price'] ?? null)
            || $moq($q->moq)     !== $moq($data['moq'] ?? null)
            || $link($q->link)   !== $link($data['link'] ?? null);
    }

    /** Isang history row ng LUMANG values ng quote (best-effort kung wala pang table). */
    private function writeQuoteHistory(\App\Models\ItemSupplierQuote $q, string $action): void
    {
        if (! Schema::hasTable('item_supplier_quote_history')) return;
        DB::table('item_supplier_quote_history')->insert([
            'quote_id'    => $q->id,
            'item_key'    => $q->item_key,
            'item_name'   => $q->item_name,
            'supplier_id' => $q->supplier_id,
            'price'       => $q->price,
            'moq'         => $q->moq,
            'link'        => $q->link,
            'action'      => $action,
            'quoted_at'   => $q->updated_at,
            'updated_by'  => Auth::id(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    /** POST /item/quotes/delete — tanggalin ang isang quote. CEO lang. */
    public function quoteDelete(Request $request)
    {
        $this->checkAccess();
        if ($this->getNormalizedRole() !== 'CEO') return response()->json(['ok' => false, 'error' => 'CEO lang'], 403);

        $data = $request->validate(['id' => 'required|integer', 'item_name' => 'required|string|max:255']);
        $key  = \App\Models\ItemSupplierQuote::keyFor($data['item_name']);
        $photo = null;
        DB::transaction(function () use ($data, $key, &$photo) {
            $q = \App\Models\ItemSupplierQuote::where('id', (int) $data['id'])->where('item_key', $key)->lockForUpdate()->first();
            if ($q) {
                $photo = $q->photo_path;
                $this->writeQuoteHistory($q, 'delete');
                $q->delete();
            }
        });
        if ($photo) {
            try { Storage::disk('public')->delete($photo); } catch (\Throwable $e) {}
        }

        return response()->json(['ok' => true, 'quotes' => $this->quoteRows($key)[$key] ?? []]);
    }

    /**
     * item_key → [{id, supplier_id, supplier, price, moq, link, note, updated_at, prev_price, prev_date, cheapest}]
     * (isang key lang kung ibinigay). prev_* = "dati ₱X (date)": pinakahuling history row ng
     * parehong item + supplier na may presyo at IBA sa kasalukuyang presyo.
     */
    private function quoteRows(?string $onlyKey = null): array
    {
        $q = \App\Models\ItemSupplierQuote::query()->with('supplier')->orderBy('price');
        if ($onlyKey !== null) $q->where('item_key', $onlyKey);
        $quotes = $q->get();

        $hist = []; // "item_key|supplier_id" → [rows, pinakabago muna]
        try {
            if ($quotes->isNotEmpty() && Schema::hasTable('item_supplier_quote_history')) {
                $h = DB::table('item_supplier_quote_history')->whereNotNull('price')
                    ->whereIn('item_key', $quotes->pluck('item_key')->unique()->all())
                    ->orderByDesc('id')->get(['item_key', 'supplier_id', 'price', 'quoted_at']);
                foreach ($h as $r) $hist[$r->item_key . '|' . $r->supplier_id][] = $r;
            }
        } catch (\Throwable $e) { /* walang history — ok lang */ }

        $map = [];
        foreach ($quotes as $r) {
            $cur  = $r->price !== null ? number_format((float) $r->price, 2, '.', '') : null;
            $prev = null;
            foreach ($hist[$r->item_key . '|' . $r->supplier_id] ?? [] as $h) {
                if (number_format((float) $h->price, 2, '.', '') !== $cur) { $prev = $h; break; }
            }
            $map[$r->item_key][] = [
                'id'          => $r->id,
                'supplier_id' => $r->supplier_id,
                'supplier'    => $r->supplier?->name ?? ('#' . $r->supplier_id),
                'price'       => $r->price !== null ? (float) $r->price : null,
                'moq'         => $r->moq,
                'link'        => $r->link,
                'note'        => $r->note,
                'updated_at'  => optional($r->updated_at)->format('Y-m-d'),
                'prev_price'  => $prev ? (float) $prev->price : null,
                'prev_date'   => $prev && $prev->quoted_at ? substr((string) $prev->quoted_at, 0, 10) : null,
                'photo_url'   => $r->photo_path ? url(Storage::disk('public')->url($r->photo_path)) : null,
            ];
        }

        // Dito sa PHP pinagpapasyahan ang pagkakasunod, hindi sa database: iba-iba ang puwesto ng
        // null at ng magkaparehong presyo sa sqlite / MySQL / PostgreSQL. May presyo (> 0) muna,
        // pinakamura sa itaas; ang walang presyo at ang 0 ay parehong "walang magagamit na presyo"
        // kaya nasa dulo, ayon sa id lang. Tie = mas mababang id muna, para hindi palipat-lipat.
        $fmt = fn ($row) => number_format((float) $row['price'], 2, '.', '');
        foreach ($map as $key => $rows) {
            usort($rows, function ($a, $b) {
                $pa = (float) $a['price'] > 0; $pb = (float) $b['price'] > 0;
                if ($pa !== $pb) return $pa ? -1 : 1;
                return $pa ? [$a['price'], $a['id']] <=> [$b['price'], $b['id']] : $a['id'] <=> $b['id'];
            });
            // cheapest: may saysay lang kapag may maikukumpara — dalawa o higit pang may presyo.
            // Lahat ng kapantay ng pinakamababa ay cheapest (naka-sort na, kaya ang una ang pinakamababa).
            $priced = count(array_filter($rows, fn ($row) => (float) $row['price'] > 0));
            $low    = $priced >= 2 ? $fmt($rows[0]) : null;
            foreach ($rows as $i => $row) {
                $rows[$i]['cheapest'] = $low !== null && (float) $row['price'] > 0 && $fmt($row) === $low;
            }
            $map[$key] = $rows;
        }
        return $map;
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

    /** date_range "YYYY-MM-DD to YYYY-MM-DD" → [startDate, endDate] 'Y-m-d' (para sa ts_date). */
    private function parseRange(string $range): array
    {
        if ($range === '') return [null, null];
        $parts = preg_split('/\s+(?:to|-)\s+/i', $range);
        $s = $parts[0] ?? null;
        $e = $parts[1] ?? $parts[0] ?? null;
        try {
            $startDate = $s ? Carbon::createFromFormat('Y-m-d', $s)->format('Y-m-d') : null;
            $endDate   = $e ? Carbon::createFromFormat('Y-m-d', $e)->format('Y-m-d') : null;
            return [$startDate, $endDate];
        } catch (\Throwable $ex) {
            return [null, null];
        }
    }
}
