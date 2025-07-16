<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Imports\AdsManagerReportImport;
use Maatwebsite\Excel\Facades\Excel;

class AdsManagerReportController extends Controller
{
    // 👉 ITO ANG IDADAGDAG — para sa import form
    public function showImportForm()
    {
        return view('ads_manager.import-form');
    }

    public function import(Request $request)
{
    $request->validate([
        'excel_file' => 'required|file|mimes:xlsx,xls,csv',
    ]);

    $import = new AdsManagerReportImport;
    Excel::import($import, $request->file('excel_file'));

    $summary = $import->getSummary();

    return back()->with('success', "Import complete: {$summary['inserted']} inserted, {$summary['updated']} updated.");
}
}
