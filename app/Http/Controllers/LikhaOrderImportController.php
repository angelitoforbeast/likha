<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\ImportLikhaFromGoogleSheet;
use App\Models\LikhaOrder;
use App\Models\LikhaOrderSetting;
use App\Models\ImportStatus;

class LikhaOrderImportController extends Controller
{
    public function import(Request $request)
    {
        if ($request->isMethod('get')) {
            // If AJAX request, return JSON status
            if ($request->ajax()) {
                $status = ImportStatus::where('job_name', 'LikhaImport')->latest()->first();
                return response()->json([
                    'is_complete' => $status?->is_complete ?? false,
                ]);
            }

            // Otherwise, return the Blade view
            $setting = LikhaOrderSetting::first();
            $status = ImportStatus::where('job_name', 'LikhaImport')->latest()->first();

            return view('likha_order.import', compact('setting', 'status'));
        }

        // Reset status before dispatching
        ImportStatus::updateOrCreate(
            ['job_name' => 'LikhaImport'],
            ['is_complete' => false]
        );

        ImportLikhaFromGoogleSheet::dispatch();

        return redirect('/likha_order_import');
    }

public function view(Request $request)
{
    if ($request->isMethod('delete')) {
        \App\Models\LikhaOrder::truncate();
        return redirect('/likha_order/view')->with('status', '🗑️ All records deleted.');
    }

    $query = \App\Models\LikhaOrder::query();

    if ($request->filled('search')) {
        $search = $request->input('search');
        $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%$search%")
              ->orWhere('phone_number', 'like', "%$search%")
              ->orWhere('page_name', 'like', "%$search%");
        });
    }

    if ($request->filled('date')) {
        $query->whereDate('date', $request->input('date'));
    }

    if ($request->filled('page_name')) {
        $query->where('page_name', $request->input('page_name'));
    }

    $orders = $query->latest()->paginate(100);
    $pages = \App\Models\LikhaOrder::select('page_name')->distinct()->pluck('page_name');

    return view('likha_order.view', compact('orders', 'pages'));
}
}