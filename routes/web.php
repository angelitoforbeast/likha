<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\FromJntController;
use App\Http\Controllers\FromGsheetController;
use App\Http\Controllers\GoogleSheetImportController;
use App\Http\Controllers\GsheetSettingController;
use App\Http\Controllers\FromAdsManagerController;
use App\Http\Controllers\LikhaOrderImportController;
use App\Http\Controllers\LikhaOrderSettingController;
use App\Http\Controllers\CPPReportController;
use App\Http\Controllers\FacebookAdsController;
use App\Http\Controllers\AdsViewController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\OfflineAdController;
use App\Http\Controllers\AdsManagerController;
use App\Http\Controllers\RoleAssignmentController;
use App\Models\Role;

// ✅ Public routes (accessible to guests)
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::get('/register', [RegisterController::class, 'showRegistrationForm'])->name('register');
Route::post('/register', [RegisterController::class, 'register']);

// ✅ Logout (only for authenticated users)
Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');
Route::get('/assign-roles', [RoleAssignmentController::class, 'index']);
Route::post('/assign-roles/{id}', [RoleAssignmentController::class, 'update']);
// ✅ Protected routes
Route::middleware(['auth'])->group(function () {
    Route::get('/', fn () => view('dashboard', ['heading' => 'Home']));

    Route::view('/encoded_vs_upload', 'encoded_vs_upload')->name('encoded_vs_upload');
    Route::get('/ads_manager/ads', [AdsViewController::class, 'index']);
    Route::get('/fb_ads_data', [FacebookAdsController::class, 'fetch'])->name('fb_ads.fetch');

    //Route::get('/cpp', [CPPReportController::class, 'index']);
Route::get('/cpp', function () {
    $user = auth()->user();
    $role = $user->roles->first();

    if (!$role || !in_array($role->name, ['Marketing', 'CEO'])) {
        abort(403);
    }

    return app(App\Http\Controllers\CPPReportController::class)->index();
})->middleware('auth');



    Route::match(['get', 'delete'], '/likha_order/view', [LikhaOrderImportController::class, 'view']);
    Route::get('/likha_order_import/settings', [LikhaOrderSettingController::class, 'edit']);
    Route::post('/likha_order_import/settings', [LikhaOrderSettingController::class, 'update']);
    Route::match(['get', 'post'], '/likha_order_import', [LikhaOrderImportController::class, 'import']);

    Route::view('/ads_manager/campaign', 'ads_manager.campaign');
    Route::view('/ads_manager/index', 'ads_manager.index');
    Route::post('/ads_manager/index', [FromAdsManagerController::class, 'store']);
    Route::get('/ads_manager/view', [FromAdsManagerController::class, 'view'])->name('ads_manager.view');
    Route::post('/ads_manager/update_field', [FromAdsManagerController::class, 'updateField']);
    Route::post('/ads_manager/delete_row', [FromAdsManagerController::class, 'deleteRow']);
    Route::post('/ads_manager/campaign', [OfflineAdController::class, 'store'])->name('offline_ads.campaign');
    Route::get('/ads_manager/campaign_view', [OfflineAdController::class, 'index'])->name('offline_ads.campaign_view');
    Route::delete('/ads_manager/campaign', [OfflineAdController::class, 'deleteAll'])->name('offline_ads.delete_all');
    Route::get('/ads_manager/ads_manager', [AdsManagerController::class, 'index'])->name('ads_manager.index');
    Route::get('/ads_manager/adsets', [AdsManagerController::class, 'adsets'])->name('ads_manager.adsets');

    Route::get('/import_gsheet/settings', [GsheetSettingController::class, 'edit']);
    Route::post('/import_gsheet/settings', [GsheetSettingController::class, 'update']);
    Route::match(['get', 'post'], '/import_gsheet', [GoogleSheetImportController::class, 'import']);
    Route::get('/from_gsheet', [FromGsheetController::class, 'index']);

    Route::view('/from_jnt', 'from_jnt');
    Route::post('/from_jnt', [FromJntController::class, 'store']);
    Route::get('/from_jnt_view', [FromJntController::class, 'index']);

    Route::view('/jnt_update', 'jnt_update');
    Route::post('/jnt_update', [FromJntController::class, 'updateOrInsert']);

    Route::get('/jnt_rts', [FromJntController::class, 'rtsView']);
    Route::post('/jnt_rts', [FromJntController::class, 'rtsFiltered']);

    Route::view('/rts', 'rts');
    Route::get('/phpinfo', fn () => phpinfo());
});


