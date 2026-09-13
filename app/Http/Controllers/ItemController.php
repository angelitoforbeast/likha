<?php

namespace App\Http\Controllers;

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

    /** GET /item — the page. */
    public function index(Request $request)
    {
        $this->checkAccess();
        $tz    = new \DateTimeZone('Asia/Manila');
        $today = (new \DateTime('now', $tz))->format('Y-m-d');
        $first = (new \DateTime('now', $tz))->modify('first day of this month')->format('Y-m-d');

        return view('item.index', [
            'defaultStart' => $first,
            'defaultEnd'   => $today,
        ]);
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

    /** POST /item/image — upload/palit ng item photo. */
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
