<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PancakeConversation;
use App\Models\PancakeId;

class PancakeConversationIndexController extends Controller
{
    public function index(Request $request)
    {
        // filters
        $startDate = $request->string('start_date')->toString();          // YYYY-MM-DD
        $endDate   = $request->string('end_date')->toString();            // YYYY-MM-DD

        // ✅ Page Name exact + search (LIKE)
        $pageName  = trim((string) $request->input('pancake_page_name', '')); // exact dropdown
        $pageQ     = trim((string) $request->input('page_q', ''));            // LIKE search

        // ✅ Search (full_name / customers_chat)
        $search    = trim((string) $request->input('q', ''));

        // pagination
        $perPage   = (int) $request->input('per_page', 50);
        if (!in_array($perPage, [25, 50, 100, 200], true)) $perPage = 50;

        // base query
        $query = PancakeConversation::query()
            ->with(['page']); // ✅ eager load page name

        // date filters (by created_at date)
        if ($startDate !== '') $query->whereDate('created_at', '>=', $startDate);
        if ($endDate !== '')   $query->whereDate('created_at', '<=', $endDate);

        // page exact filter
        if ($pageName !== '') {
            $query->whereHas('page', function ($q) use ($pageName) {
                $q->where('pancake_page_name', $pageName);
            });
        }

        // page name search (LIKE)
        if ($pageQ !== '') {
            $query->whereHas('page', function ($q) use ($pageQ) {
                $q->where('pancake_page_name', 'like', "%{$pageQ}%");
            });
        }

        // general search
        if ($search !== '') {
            $query->where(function ($qq) use ($search) {
                $qq->where('full_name', 'like', "%{$search}%")
                   ->orWhere('customers_chat', 'like', "%{$search}%");
            });
        }

        // results
        $rows = $query
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->appends($request->query());

        // dropdown data for pages (from mapping table)
        $pageNames = PancakeId::query()
            ->select('pancake_page_name')
            ->whereNotNull('pancake_page_name')
            ->where('pancake_page_name', '!=', '')
            ->distinct()
            ->orderBy('pancake_page_name')
            ->pluck('pancake_page_name');

        return view('pancake.index', [
            'rows'      => $rows,

            // dropdown list
            'pageNames' => $pageNames,

            // filters back to UI
            'startDate' => $startDate,
            'endDate'   => $endDate,
            'pageName'  => $pageName,
            'pageQ'     => $pageQ,
            'search'    => $search,
            'perPage'   => $perPage,
        ]);
    }
}
