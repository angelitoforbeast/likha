<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PageItemSenderMappingController extends Controller
{
    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from')
            ? \Carbon\Carbon::parse($request->input('date_from'), 'Asia/Manila')->startOfDay()
            : now('Asia/Manila')->subDays(6)->startOfDay(); // last 7 days default

        $dateTo = $request->input('date_to')
            ? \Carbon\Carbon::parse($request->input('date_to'), 'Asia/Manila')->endOfDay()
            : now('Asia/Manila')->endOfDay();

        $search = trim($request->input('search', ''));

        // Query 1: all unique Page+Item combos from macro_output (within date range)
        $combosQuery = DB::table('macro_output')
            ->select('PAGE as page', 'ITEM_NAME as item_name')
            ->whereBetween('ts_date', [$dateFrom, $dateTo])
            ->whereNotNull('PAGE')->where('PAGE', '!=', '')
            ->whereNotNull('ITEM_NAME')->where('ITEM_NAME', '!=', '')
            ->groupBy('PAGE', 'ITEM_NAME');

        $combos = $combosQuery->get();

        // Query 2: all item-level mappings (item_name IS NOT NULL), latest per page+item
        $mappings = DB::table('page_sender_mappings')
            ->whereNotNull('item_name')
            ->select('PAGE', 'item_name', 'SENDER_NAME', 'id')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn($r) => $r->PAGE . '|||' . $r->item_name)
            ->map(fn($group) => $group->first()); // keep latest

        // Merge in PHP
        $rows = $combos->map(function ($r) use ($mappings) {
            $key           = $r->page . '|||' . $r->item_name;
            $mapping       = $mappings->get($key);
            $r->sender_name = $mapping?->SENDER_NAME ?? null;
            $r->mapping_id  = $mapping?->id ?? null;
            return $r;
        });

        // Optional search filter
        if ($search !== '') {
            $s    = mb_strtolower($search);
            $rows = $rows->filter(function ($r) use ($s) {
                return str_contains(mb_strtolower($r->page), $s)
                    || str_contains(mb_strtolower($r->item_name), $s)
                    || str_contains(mb_strtolower((string) $r->sender_name), $s);
            });
        }

        // Sort: unmapped first, then page asc, item_name asc
        $rows = $rows->sortBy(fn($r) => [
            empty($r->sender_name) ? 0 : 1,
            $r->page,
            $r->item_name,
        ])->values();

        $unmappedCount = $rows->filter(fn($r) => empty($r->sender_name))->count();

        $dateFromStr = $dateFrom->toDateString();
        $dateToStr   = $dateTo->toDateString();

        return view('jnt.item-sender-name', compact('rows', 'search', 'unmappedCount', 'dateFromStr', 'dateToStr'));
    }

    public function save(Request $request)
    {
        $page       = trim($request->input('page', ''));
        $itemName   = trim($request->input('item_name', ''));
        $senderName = trim($request->input('sender_name', ''));

        if (!$page || !$itemName || !$senderName) {
            return back()->with('error', 'Page, Item Name, and Shop Name are all required.');
        }

        $existing = DB::table('page_sender_mappings')
            ->where('PAGE', $page)
            ->where('item_name', $itemName)
            ->whereNotNull('item_name')
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            DB::table('page_sender_mappings')
                ->where('id', $existing->id)
                ->update(['SENDER_NAME' => $senderName, 'updated_at' => now()]);
        } else {
            DB::table('page_sender_mappings')->insert([
                'PAGE'       => $page,
                'item_name'  => $itemName,
                'SENDER_NAME'=> $senderName,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return back()->with('success', "Saved: [{$page}] + [{$itemName}] → {$senderName}");
    }

    public function delete($id)
    {
        DB::table('page_sender_mappings')
            ->where('id', $id)
            ->whereNotNull('item_name')
            ->delete();

        return back()->with('success', 'Mapping deleted.');
    }
}
