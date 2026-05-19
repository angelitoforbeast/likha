<?php

namespace App\Http\Controllers;

use App\Models\ItemTypeMapping;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ItemTypeMappingController extends Controller
{
    /**
     * Read-only consolidated view: each item_type and ALL its mapped item_names
     * in one row. Below the table, lists every item_name sa macro_output na
     * walang mapping yet — para makita agad anong items kailangan mappingan.
     */
    public function grouped(Request $request)
    {
        // Group mappings by item_type — GROUP_CONCAT puts all item_names sa one cell.
        $grouped = DB::table('item_type_mappings')
            ->selectRaw("item_type,
                         COUNT(*) as variant_count,
                         GROUP_CONCAT(item_name ORDER BY item_name SEPARATOR ' | ') as item_names")
            ->groupBy('item_type')
            ->orderBy('item_type')
            ->get();

        // Unmapped item_names — distinct sa macro_output, not in item_type_mappings.
        $unmapped = DB::table('macro_output as mo')
            ->whereNotNull('mo.ITEM_NAME')
            ->where('mo.ITEM_NAME', '!=', '')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('item_type_mappings as itm')
                  ->whereColumn('itm.item_name', 'mo.ITEM_NAME');
            })
            ->distinct()
            ->orderBy('mo.ITEM_NAME')
            ->pluck('mo.ITEM_NAME');

        return view('jnt.item-types-grouped', [
            'grouped'  => $grouped,
            'unmapped' => $unmapped,
        ]);
    }

    // ── Option 1: Paste from Sheets ─────────────────────────────────────────
    public function index(Request $request)
    {
        $search = $request->input('search');

        $query = DB::table('item_type_mappings')->orderBy('item_type')->orderBy('item_name');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('item_type', 'LIKE', '%' . $search . '%')
                  ->orWhere('item_name', 'LIKE', '%' . $search . '%');
            });
        }

        $mappings = $query->paginate(100);

        // Build item_name → item_type map for conflict detection in JS live preview
        $existingMap = DB::table('item_type_mappings')
            ->pluck('item_type', 'item_name');

        return view('jnt.item-types', compact('mappings', 'search', 'existingMap'));
    }

    // ── Option 2: Inline Edit Table ─────────────────────────────────────────
    public function inline(Request $request)
    {
        $dateFrom = $request->input('date_from')
            ? Carbon::parse($request->input('date_from'), 'Asia/Manila')->startOfDay()
            : now('Asia/Manila')->subDays(29)->startOfDay();

        $dateTo = $request->input('date_to')
            ? Carbon::parse($request->input('date_to'), 'Asia/Manila')->endOfDay()
            : now('Asia/Manila')->endOfDay();

        $search = trim($request->input('search', ''));

        // All distinct item names from macro_output within date range
        $itemNames = DB::table('macro_output')
            ->select('ITEM_NAME as item_name')
            ->whereBetween('ts_date', [$dateFrom, $dateTo])
            ->whereNotNull('ITEM_NAME')->where('ITEM_NAME', '!=', '')
            ->groupBy('ITEM_NAME')
            ->get();

        // All existing mappings keyed by item_name
        $mappings = DB::table('item_type_mappings')
            ->get()
            ->keyBy('item_name');

        // Merge
        $rows = $itemNames->map(function ($r) use ($mappings) {
            $mapping       = $mappings->get($r->item_name);
            $r->item_type  = $mapping?->item_type ?? null;
            $r->mapping_id = $mapping?->id ?? null;
            return $r;
        });

        // Optional search filter
        if ($search !== '') {
            $s    = mb_strtolower($search);
            $rows = $rows->filter(fn($r) =>
                str_contains(mb_strtolower($r->item_name), $s) ||
                str_contains(mb_strtolower((string) $r->item_type), $s)
            );
        }

        // Unmapped first, then sort by item_name
        $rows = $rows->sortBy(fn($r) => [
            empty($r->item_type) ? 0 : 1,
            $r->item_name,
        ])->values();

        $unmappedCount = $rows->filter(fn($r) => empty($r->item_type))->count();

        $dateFromStr = $dateFrom->toDateString();
        $dateToStr   = $dateTo->toDateString();

        return view('jnt.item-types-inline', compact('rows', 'search', 'unmappedCount', 'dateFromStr', 'dateToStr'));
    }

    // ── Save bulk (Option 1) ─────────────────────────────────────────────────
    public function save(Request $request)
    {
        $rawInput = $request->input('bulk_data');

        if (!$rawInput) {
            return back()->with('error', 'No data pasted.');
        }

        $lines = explode("\n", trim($rawInput));
        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $parts = preg_split("/\t+/", $line);
            if (count($parts) < 2) continue;

            $itemName = trim($parts[0]);
            $itemType = trim($parts[1]);

            if (!$itemName || !$itemType) continue;

            $existing = DB::table('item_type_mappings')->where('item_name', $itemName)->first();

            if ($existing) {
                if ($existing->item_type === $itemType) {
                    $skipped++;
                } else {
                    DB::table('item_type_mappings')
                        ->where('id', $existing->id)
                        ->update(['item_type' => $itemType, 'updated_at' => now()]);
                    $updated++;
                }
            } else {
                DB::table('item_type_mappings')->insert([
                    'item_name'  => $itemName,
                    'item_type'  => $itemType,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $inserted++;
            }
        }

        $parts = [];
        if ($inserted) $parts[] = "{$inserted} inserted";
        if ($updated)  $parts[] = "{$updated} updated";
        if ($skipped)  $parts[] = "{$skipped} skipped (no change)";

        if ($inserted || $updated) {
            return back()->with('success', implode(', ', $parts) . '.');
        }

        return back()->with('error', implode(', ', $parts) ?: 'Nothing saved.');
    }

    // ── Save one (Option 2 inline AJAX) ─────────────────────────────────────
    public function saveOne(Request $request)
    {
        $itemName = trim($request->input('item_name', ''));
        $itemType = trim($request->input('item_type', ''));

        if (!$itemName || !$itemType) {
            return response()->json(['error' => 'item_name and item_type are required.'], 422);
        }

        $existing = DB::table('item_type_mappings')->where('item_name', $itemName)->first();

        if ($existing) {
            DB::table('item_type_mappings')
                ->where('id', $existing->id)
                ->update(['item_type' => $itemType, 'updated_at' => now()]);
        } else {
            DB::table('item_type_mappings')->insert([
                'item_name'  => $itemName,
                'item_type'  => $itemType,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    // ── Delete (shared by both UIs) ──────────────────────────────────────────
    public function delete($id)
    {
        DB::table('item_type_mappings')->where('id', $id)->delete();
        return back()->with('success', 'Mapping deleted.');
    }
}
