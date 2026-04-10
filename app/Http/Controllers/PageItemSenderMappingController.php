<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PageItemSenderMappingController extends Controller
{
    public function index(Request $request)
    {
        $since  = now()->subDays(90)->startOfDay();
        $search = trim($request->input('search', ''));

        $query = DB::table('macro_output as m')
            ->select([
                'm.PAGE as page',
                'm.ITEM_NAME as item_name',
                DB::raw("(
                    SELECT psm.SENDER_NAME
                    FROM page_sender_mappings psm
                    WHERE psm.PAGE = m.PAGE
                      AND psm.item_name = m.ITEM_NAME
                      AND psm.item_name IS NOT NULL
                    ORDER BY psm.id DESC
                    LIMIT 1
                ) as sender_name"),
                DB::raw("(
                    SELECT psm.id
                    FROM page_sender_mappings psm
                    WHERE psm.PAGE = m.PAGE
                      AND psm.item_name = m.ITEM_NAME
                      AND psm.item_name IS NOT NULL
                    ORDER BY psm.id DESC
                    LIMIT 1
                ) as mapping_id"),
            ])
            ->where('m.ts_date', '>=', $since)
            ->whereNotNull('m.PAGE')->where('m.PAGE', '!=', '')
            ->whereNotNull('m.ITEM_NAME')->where('m.ITEM_NAME', '!=', '')
            ->groupBy('m.PAGE', 'm.ITEM_NAME');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('m.PAGE', 'LIKE', "%{$search}%")
                  ->orWhere('m.ITEM_NAME', 'LIKE', "%{$search}%")
                  ->orWhere('m.SENDER_NAME', 'LIKE', "%{$search}%");
            });
        }

        // Fetch then sort in PHP: unmapped first, then by page/item
        $rows = $query->get()->sortBy(function ($r) {
            return [empty($r->sender_name) ? 0 : 1, $r->page, $r->item_name];
        })->values();

        $unmappedCount = $rows->filter(fn($r) => empty($r->sender_name))->count();

        return view('jnt.item-sender-name', compact('rows', 'search', 'unmappedCount'));
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
