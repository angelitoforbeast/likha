<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\ImportLikhaFromGoogleSheet;
use App\Models\LikhaOrder;
use App\Models\LikhaOrderSetting;
use Illuminate\Support\Facades\DB;
use App\Services\GoogleSheetService;
use App\Jobs\ClearLikhaOrders;

class LikhaOrderImportController extends Controller
{   

    

public function clearAll() {
    DB::table('macro_output')->truncate();
    dispatch(new ClearLikhaOrders());
    return back()->with('success', 'Data cleared successfully!');
}


    public function import(Request $request)
{
    if ($request->isMethod('get')) {
        $settings = LikhaOrderSetting::all();

        if ($request->ajax()) {
            return response()->json([
                'is_complete'     => cache()->has('likha_import_result'),
                'import_message'  => cache()->get('likha_import_result'),
            ]);
        }

        return view('likha_order.import', compact('settings'));
    }

    // POST method: trigger import
    ImportLikhaFromGoogleSheet::dispatch();
    session()->flash('import_status', '⏳ Importing rows... Please wait.');

    if (cache()->has('likha_import_result')) {
        session()->flash('import_message', cache()->pull('likha_import_result'));
    }

    return redirect()->back();
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
