<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google_Client;
use Google_Service_Sheets;
use Google_Service_Sheets_ValueRange;
use Google_Service_Sheets_BatchUpdateValuesRequest;
use App\Jobs\ImportLikhaFromGoogleSheet;

use App\Models\LikhaOrder;
use App\Models\LikhaOrderSetting; // or LikhaOrderSetting if you're using a separate table

class LikhaOrderImportController extends Controller
{


public function import(Request $request)
{
    if ($request->isMethod('get')) {
        $setting = LikhaOrderSetting::first();
        return view('likha_order.import', compact('setting'));
    }

    ImportLikhaFromGoogleSheet::dispatch();

    return redirect('/likha_order_import')->with('status', '⏳ Import started in background. Please refresh later to see results.');
}

public function view(Request $request)
{
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
