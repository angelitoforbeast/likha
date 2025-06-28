<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\LikhaOrderSetting;

class LikhaOrderSettingController extends Controller
{
    public function edit()
    {
        $setting = LikhaOrderSetting::first();
        return view('likha_order.import_settings', compact('setting'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'sheet_id' => 'required',
            'range' => 'required',
        ]);

        $setting = LikhaOrderSetting::first() ?? new LikhaOrderSetting();
        $setting->sheet_id = $request->sheet_id;
        $setting->range = $request->range;
        $setting->save();

        return redirect('/likha_order_import/settings')->with('status', '✅ Settings saved successfully!');
    }
}
