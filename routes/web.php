<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FromJntController;
use App\Http\Controllers\FromGsheetController;
use App\Http\Controllers\GoogleSheetImportController;
use App\Http\Controllers\GsheetSettingController;
use App\Http\Controllers\FromAdsManagerController; // ✅ ADD THIS LINE
use App\Http\Controllers\LikhaOrderImportController;
use App\Http\Controllers\LikhaOrderSettingController;
use App\Http\Controllers\CPPReportController;
use App\Http\Controllers\FacebookAdsController;
use App\Http\Controllers\AdsViewController;

Route::get('/ads_manager/ads', [AdsViewController::class, 'index']);


Route::get('/fb_ads_data', [FacebookAdsController::class, 'fetch'])->name('fb_ads.fetch');

Route::get('/cpp', [CPPReportController::class, 'index']);

Route::match(['get', 'delete'], '/likha_order/view', [LikhaOrderImportController::class, 'view']);
Route::get('/likha_order_import/settings', [LikhaOrderSettingController::class, 'edit']);
Route::post('/likha_order_import/settings', [LikhaOrderSettingController::class, 'update']);



// GET = show import page
// POST = trigger import logic
Route::match(['get', 'post'], '/likha_order_import', [LikhaOrderImportController::class, 'import']);


Route::view('/ads_manager/index', 'ads_manager.index');
Route::post('/ads_manager/index', [FromAdsManagerController::class, 'store']);
Route::get('/ads_manager/view', [FromAdsManagerController::class, 'view']);
Route::get('/ads_manager/view', [FromAdsManagerController::class, 'view'])->name('ads_manager.view');
Route::post('/ads_manager/update_field', [FromAdsManagerController::class, 'updateField']);
Route::post('/ads_manager/delete_row', [FromAdsManagerController::class, 'deleteRow']);



Route::get('/import_gsheet/settings', [GsheetSettingController::class, 'edit']);
Route::post('/import_gsheet/settings', [GsheetSettingController::class, 'update']);

Route::match(['get', 'post'], '/import_gsheet', [GoogleSheetImportController::class, 'import']);

Route::get('/from_gsheet', [FromGsheetController::class, 'index']);


Route::get('/from_jnt', function () {
    return view('from_jnt'); // make sure this view exists
});

Route::post('/from_jnt', [FromJntController::class, 'store']);

Route::get('/from_jnt_view', [FromJntController::class, 'index']);

Route::get('/jnt_update', function () {
    return view('jnt_update'); // yung blade file mo na may xlsx upload
});

Route::post('/jnt_update', [FromJntController::class, 'updateOrInsert']);


Route::get('/', function () {
    return view('home');
});

Route::get('/rts', function () {
    return view('rts');
});

Route::get('/phpinfo', function () {
    phpinfo();
});

Route::get('/jnt_rts', [FromJntController::class, 'rtsView']);
Route::post('/jnt_rts', [FromJntController::class, 'rtsFiltered']);





