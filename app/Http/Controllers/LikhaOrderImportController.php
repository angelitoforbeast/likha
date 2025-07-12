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
            if ($request->ajax()) {
                $status = ImportStatus::where('job_name', 'LikhaImport')->latest()->first();
                return response()->json([
                    'is_complete' => $status?->is_complete ?? false,
                ]);
            }

            $settings = LikhaOrderSetting::all();
            $status = ImportStatus::where('job_name', 'LikhaImport')->latest()->first();

            return view('likha_order.import', compact('settings', 'status'));
        }

        ImportStatus::updateOrCreate(
            ['job_name' => 'LikhaImport'],
            ['is_complete' => false]
        );

        ImportLikhaFromGoogleSheet::dispatch(); // This now supports multiple sheets
        return redirect('/likha_order_import');
    }

    public function view(Request $request)
    {
        if ($request->isMethod('delete')) {
            LikhaOrder::truncate();
            return redirect('/likha_order/view')->with('status', '🗑️ All records deleted.');
        }

        $query = LikhaOrder::query();

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
        $pages = LikhaOrder::select('page_name')->distinct()->pluck('page_name');

        return view('likha_order.view', compact('orders', 'pages'));
    }
}
