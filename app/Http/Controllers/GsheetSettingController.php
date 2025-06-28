<?php
namespace App\Http\Controllers;

use App\Models\GsheetSetting;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;


class GsheetSettingController extends Controller
{
    public function edit()
    {
        $setting = GsheetSetting::first();
        return view('import_gsheet_settings', compact('setting'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'sheet_id' => 'required',
            'range' => 'required',
        ]);

        $setting = GsheetSetting::first() ?? new GsheetSetting();
        $setting->sheet_id = $request->sheet_id;
        $setting->range = $request->range;
        $setting->save();

        return redirect('/import_gsheet')->with('status', 'GSheet settings updated!');
    }
}

