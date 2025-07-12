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
use App\Http\Controllers\MesSegregatorController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\MacroGsheetController;
use App\Http\Controllers\OrderTallyController;
use App\Models\Role;

// ✅ Public routes
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::get('/register', [RegisterController::class, 'showRegistrationForm'])->name('register');
Route::post('/register', [RegisterController::class, 'register']);

// ✅ Logout
Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

Route::get('/assign-roles', [RoleAssignmentController::class, 'index']);
Route::post('/assign-roles/{id}', [RoleAssignmentController::class, 'update']);

// ✅ Protected routes
Route::middleware(['auth'])->group(function () {



    Route::get('/orders/tally', [OrderTallyController::class, 'index'])->name('orders.tally');
    Route::get('/orders/tally/{date}', [OrderTallyController::class, 'show'])->name('orders.tally.show');

    // ✅ Likha Order (multi-sheet support)
    Route::get('/likha_order_import/settings', [LikhaOrderSettingController::class, 'settings'])->name('likha.settings');
    Route::post('/likha_order_import/settings', [LikhaOrderSettingController::class, 'store'])->name('likha.settings.store');
    Route::put('/likha_order_import/settings/{id}', [LikhaOrderSettingController::class, 'update'])->name('likha.settings.update');
    Route::delete('/likha_order_import/settings/{id}', [LikhaOrderSettingController::class, 'destroy'])->name('likha.settings.delete');
    Route::match(['get', 'post'], '/likha_order_import', [LikhaOrderImportController::class, 'import'])->name('likha.import');
    Route::match(['get', 'delete'], '/likha_order/view', [LikhaOrderImportController::class, 'view'])->name('likha.view');

    // ✅ Macro GSheet
    Route::get('/macro/gsheet/settings', [MacroGsheetController::class, 'settings'])->name('macro.settings');
    Route::post('/macro/gsheet/settings', [MacroGsheetController::class, 'storeSetting'])->name('macro.settings.store');
    Route::delete('/macro/gsheet/settings/{id}', [MacroGsheetController::class, 'deleteSetting'])->name('macro.settings.delete');
    Route::post('/macro/gsheet/import', [MacroGsheetController::class, 'import'])->name('macro.import');
    Route::get('/macro/gsheet/index', [MacroGsheetController::class, 'index'])->name('macro.index');
    Route::delete('/macro/gsheet/delete-all', [MacroGsheetController::class, 'deleteAll'])->name('macro.deleteAll');
    Route::get('/macro/gsheet/import', function () {
        $settings = \App\Models\MacroGsheetSetting::all();
        return view('macro.gsheet.import', compact('settings'));
    })->name('macro.import.view');

    // ✅ Tasks
    Route::get('/task/index', [TaskController::class, 'index'])->name('task.index');
    Route::get('/task/create', [TaskController::class, 'showCreateForm'])->name('task.create.form');
    Route::post('/task/create', [TaskController::class, 'create'])->name('task.create');
    Route::get('/task/my-tasks', [TaskController::class, 'myTasks'])->name('task.my-tasks');
    Route::post('/task/update-status', [TaskController::class, 'updateStatus'])->name('task.updateStatus');
    Route::post('/task/update-creator-remarks', [TaskController::class, 'updateCreatorRemarks'])->name('task.updateCreatorRemarks');

    // ✅ MES Segregator
    Route::get('/data_encoder/mes-segregator', [MesSegregatorController::class, 'index'])->name('mes.index');
    Route::post('/data_encoder/mes-segregator', [MesSegregatorController::class, 'segregate'])->name('mes.segregate');
    Route::get('/mes-download/{filename}', [MesSegregatorController::class, 'download'])->name('mes.download');

    // ✅ Dashboard
    Route::get('/', fn () => view('dashboard', ['heading' => 'Home']));
    Route::view('/encoded_vs_upload', 'encoded_vs_upload')->name('encoded_vs_upload');

    // ✅ Ads
    Route::get('/ads_manager/ads', [AdsViewController::class, 'index']);
    Route::get('/fb_ads_data', [FacebookAdsController::class, 'fetch'])->name('fb_ads.fetch');

    Route::get('/cpp', function () {
        $user = auth()->user();
        $role = $user->roles->first();
        if (!$role || !in_array($role->name, ['Marketing', 'CEO', 'Marketing - OIC'])) {
            abort(403);
        }
        return app(CPPReportController::class)->index();
    });

    // ✅ Role Management
    Route::get('/roles/index', [\App\Http\Controllers\RoleController::class, 'index']);
    Route::post('/roles/store', [\App\Http\Controllers\RoleController::class, 'store'])->name('roles.store');
    Route::post('/roles/update/{id}', [\App\Http\Controllers\RoleController::class, 'update'])->name('roles.update');

    // ✅ Ads Manager
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

    // ✅ GSheet Import (General)
    Route::get('/import_gsheet/settings', [GsheetSettingController::class, 'edit']);
    Route::post('/import_gsheet/settings', [GsheetSettingController::class, 'update']);
    Route::match(['get', 'post'], '/import_gsheet', [GoogleSheetImportController::class, 'import']);
    Route::get('/from_gsheet', [FromGsheetController::class, 'index']);

    // ✅ JNT Upload
    Route::view('/from_jnt', 'from_jnt');
    Route::post('/from_jnt', [FromJntController::class, 'store']);
    Route::get('/from_jnt_view', [FromJntController::class, 'index']);

    Route::view('/jnt_update', 'jnt_update');
    Route::post('/jnt_update', [FromJntController::class, 'updateOrInsert']);
    Route::get('/jnt_rts', [FromJntController::class, 'rtsView']);
    Route::post('/jnt_rts', [FromJntController::class, 'rtsFiltered']);

    // ✅ Misc
    Route::view('/rts', 'rts');
    Route::get('/phpinfo', fn () => phpinfo());
});
