<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PancakeConversation;

class PancakeConversationIndexController extends Controller
{
    public function index(Request $request)
    {
        $startDate = $request->string('start_date')->toString(); // YYYY-MM-DD
        $endDate   = $request->string('end_date')->toString();   // YYYY-MM-DD
        $pageId    = trim((string) $request->input('pancake_page_id', ''));
        $search    = trim((string) $request->input('q', ''));
        $perPage   = (int) $request->input('per_page', 50);

        if (!in_array($perPage, [25, 50, 100, 200], true)) $perPage = 50;

        $query = PancakeConversation::query();

        if ($startDate !== '') $query->whereDate('created_at', '>=', $startDate);
        if ($endDate !== '')   $query->whereDate('created_at', '<=', $endDate);

        if ($pageId !== '') {
            $query->where('pancake_page_id', $pageId);
        }

        if ($search !== '') {
            $query->where(function ($qq) use ($search) {
                $qq->where('full_name', 'like', "%{$search}%")
                   ->orWhere('customers_chat', 'like', "%{$search}%");
            });
        }

        $rows = $query
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->appends($request->query());

        $pageIds = PancakeConversation::query()
            ->select('pancake_page_id')
            ->whereNotNull('pancake_page_id')
            ->where('pancake_page_id', '!=', '')
            ->distinct()
            ->orderBy('pancake_page_id')
            ->pluck('pancake_page_id');

        return view('pancake.index', [
            'rows'      => $rows,
            'pageIds'   => $pageIds,
            'startDate' => $startDate,
            'endDate'   => $endDate,
            'pageId'    => $pageId,
            'search'    => $search,
            'perPage'   => $perPage,
        ]);
    }
}
