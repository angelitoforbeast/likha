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
use App\Http\Controllers\EverydayTaskController;
use App\Http\Controllers\AdsManagerReportController;
use App\Http\Controllers\AdCopyController;
use App\Http\Controllers\AdCampaignCreativeController;
use App\Http\Controllers\Checker2GsheetController;
use App\Http\Controllers\MacroOutputController;
use App\Http\Controllers\PageSenderMappingController;
use App\Http\Controllers\JntCheckerController;
use App\Http\Controllers\JntUploadController;
use App\Http\Controllers\PancakeSubscriptionCheckerController;
use App\Http\Controllers\AdsManagerCampaignsController;
use App\Http\Controllers\AdsInsightsController;
use App\Http\Controllers\GPTAdGeneratorController;
use App\Http\Controllers\JntHoldController;
use App\Http\Controllers\ItemCogsController;
use App\Http\Controllers\SummaryOverallController;
use App\Http\Controllers\JntOndelController;
use App\Http\Controllers\JntRemittanceController;
use App\Http\Controllers\JntShippedController;
use App\Http\Controllers\JntReturnScannedController;
use App\Http\Controllers\JntReturnReconciliationController;
use App\Http\Controllers\JntReturnInventoryController;
use App\Http\Controllers\Encoder\Checker1SummaryController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PaymentActivityController;
use App\Http\Controllers\AdAccountController;
use App\Http\Controllers\Pancake\RetrieveOrdersController;
use App\Http\Controllers\BotcakePsidGsheetController;
use App\Http\Controllers\JntStatusController;
use App\Http\Controllers\Encoder\Tools\AiController;
use App\Http\Controllers\JntChatblastGsheetController;
use App\Http\Controllers\Security\AllowedIpController;

use App\Models\Role;

// ✅ Public routes

Route::middleware(['web','auth'])->get('/debug/ip', function () {
    return response()->json([
        'ip'  => request()->ip(),
        'ips' => request()->ips(),
        'xff' => request()->header('x-forwarded-for'),
        'xri' => request()->header('x-real-ip'),
    ]);
});

Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::get('/registerlogin', [RegisterController::class, 'showRegistrationForm'])->name('register');
Route::post('/registerlogin', [RegisterController::class, 'register']);

// ✅ Logout
Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

Route::get('/assign-roles', [RoleAssignmentController::class, 'index']);
Route::post('/assign-roles/{id}', [RoleAssignmentController::class, 'update']);

Route::post('/api/generate-gpt-summary', [GPTAdGeneratorController::class, 'generate']);
Route::get('/gpt-ad-generator', [GPTAdGeneratorController::class, 'showGeneratorForm']);
Route::get('/ad-copy-suggestions', [GPTAdGeneratorController::class, 'loadAdCopySuggestions'])->name('gpt.suggestions');

// ✅ Ondel Counter
Route::get('/jnt/ondel', [JntOndelController::class, 'index'])->name('jnt.ondel');
Route::post('/jnt/ondel/process', [JntOndelController::class, 'process'])->name('jnt.ondel.process');

// ✅ Protected routes
Route::middleware(['web','auth','allowed_ip'])->group(function () {



 // ✅ Allowed IPs (CEO only) - same style as /cpp
Route::get('/allowed-ips', function () {
    $role = auth()->user()->employeeProfile?->role;
    if (!$role || !in_array($role, ['CEO'])) abort(403);

    return app(AllowedIpController::class)->index();
})->name('allowed_ips.index');

Route::post('/allowed-ips', function () {
    $role = auth()->user()->employeeProfile?->role;
    if (!$role || !in_array($role, ['CEO'])) abort(403);

    return app(AllowedIpController::class)->store(request());
})->name('allowed_ips.store');

Route::put('/allowed-ips/{allowedIp}', function (\App\Models\AllowedIp $allowedIp) {
    $role = auth()->user()->employeeProfile?->role;
    if (!$role || !in_array($role, ['CEO'])) abort(403);

    return app(AllowedIpController::class)->update(request(), $allowedIp);
})->name('allowed_ips.update');

Route::delete('/allowed-ips/{allowedIp}', function (\App\Models\AllowedIp $allowedIp) {
    $role = auth()->user()->employeeProfile?->role;
    if (!$role || !in_array($role, ['CEO'])) abort(403);

    return app(AllowedIpController::class)->destroy($allowedIp);
})->name('allowed_ips.destroy');

   




Route::get('/encoder/tools/ai', [AiController::class, 'index'])->name('encoder.tools.ai');
Route::post('/encoder/tools/ai/run', [AiController::class, 'run'])->name('encoder.tools.ai.run');
Route::get('/encoder/tools/ai/health', [AiController::class, 'health'])->middleware('auth');


Route::prefix('botcake/psid')->group(function () {

    // ===== SETTINGS =====
    Route::get('/settings', [BotcakePsidGsheetController::class, 'settings'])
        ->name('botcake.psid.settings');

    Route::post('/settings', [BotcakePsidGsheetController::class, 'storeSetting'])
        ->name('botcake.psid.settings.store');

    Route::post('/settings/{id}', [BotcakePsidGsheetController::class, 'update'])
        ->name('botcake.psid.settings.update');

    // NOTE: If you want to keep DELETE method, keep this.
    // Make sure your form uses method spoofing: @method('DELETE')
    Route::delete('/settings/{id}', [BotcakePsidGsheetController::class, 'deleteSetting'])
        ->name('botcake.psid.settings.delete');
Route::delete('/abc', [BotcakePsidGsheetController::class, 'deleteSetting'])
        ->name('botcake.psid.settings.delete');

    // ===== IMPORT UI =====
    Route::get('/import', [BotcakePsidGsheetController::class, 'showImport'])
        ->name('botcake.psid.import');

    // POST /botcake/psid/import (start job)
    Route::post('/import', [BotcakePsidGsheetController::class, 'import'])
        ->name('botcake.psid.import.run');

    // ✅ ADD THIS: Live status polling endpoint (UI will call this)
    Route::get('/import/status/{runId}', [BotcakePsidGsheetController::class, 'status'])
        ->name('botcake.psid.import.status');
});




Route::get('/pancake/retrieve-orders', [RetrieveOrdersController::class, 'index'])
    ->name('pancake.retrieve-orders.index');

Route::post('/pancake/retrieve-orders/check', [RetrieveOrdersController::class, 'check'])
    ->name('pancake.retrieve-orders.check');


Route::get('/ads_manager/ad_account', [AdAccountController::class, 'index'])
    ->name('ad_accounts.index');

Route::get('/ads_manager/ad_account/{ad_account_id}', [AdAccountController::class, 'index'])
    ->name('ad_accounts.edit');

Route::post('/ads_manager/ad_account', [AdAccountController::class, 'store'])
    ->name('ad_accounts.store');

Route::delete('/ads_manager/ad_account/{ad_account_id}', [AdAccountController::class, 'destroy'])
    ->name('ad_accounts.destroy');

Route::get('/ads_manager/payment/upload', [PaymentActivityController::class, 'create'])
    ->name('ads_payment.upload.form');

Route::post('/ads_manager/payment/upload', [PaymentActivityController::class, 'store'])
    ->name('ads_payment.upload.store');

// View-only (simple table of saved rows)
Route::get('/ads_manager/payment/records', [PaymentActivityController::class, 'records'])
    ->name('ads_payment.records.index');

    Route::delete('/ads_manager/payment/records', [PaymentActivityController::class, 'destroyAll'])
    ->name('ads_payment.records.delete_all');

Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::post('/profile', [ProfileController::class, 'update'])->name('profile.update');

Route::get('/jnt/remittance', [JntRemittanceController::class, 'index'])
    ->name('jnt.remittance');
Route::get('/jnt/shipped', [\App\Http\Controllers\JntShippedController::class, 'index'])->name('jnt.shipped');
Route::prefix('jnt/return')->group(function () {
    Route::get('scanned', [JntReturnScannedController::class, 'index'])->name('jnt.return.scanned');
    Route::post('scanned/upload', [JntReturnScannedController::class, 'upload'])->name('jnt.return.scanned.upload');
    Route::delete('scanned/{id}', [JntReturnScannedController::class, 'destroy'])->name('jnt.return.scanned.delete');
});
Route::get('/jnt/return/reconciliation', [JntReturnReconciliationController::class, 'index'])
    ->name('jnt.return.reconciliation');

Route::get('/jnt/return/inventory', [JntReturnInventoryController::class, 'index'])
        ->name('jnt.return.inventory');

    
   Route::get('/jnt/sender-name', [PageSenderMappingController::class, 'index']);
Route::post('/jnt/sender-name', [PageSenderMappingController::class, 'save']);
Route::post('/jnt/sender-name/delete/{id}', [\App\Http\Controllers\PageSenderMappingController::class, 'delete']);
Route::get('/jnt/checker', [JntCheckerController::class, 'index'])->name('jnt.checker');
Route::post('/jnt/checker/upload', [JntCheckerController::class, 'upload'])->name('jnt.checker.upload');
Route::get('/jnt/checker/upload', fn () => redirect()->route('jnt.checker'));
Route::post('/jnt/checker/update', [\App\Http\Controllers\JntCheckerController::class, 'update'])
    ->name('jnt.checker.update');

Route::get('/item/cogs', [ItemCogsController::class, 'index'])->name('item.cogs.index');
Route::get('/item/cogs/grid', [ItemCogsController::class, 'grid'])->name('item.cogs.grid');      // JSON grid for month
Route::post('/item/cogs/update', [ItemCogsController::class, 'update'])->name('item.cogs.update'); // edit one cell

Route::get('/summary/overall', [SummaryOverallController::class, 'index'])->name('summary.overall');
Route::get('/summary/overall/data', [SummaryOverallController::class, 'data'])->name('summary.overall.data');
Route::get('/summary/overall/daily', [SummaryOverallController::class, 'daily'])->name('summary.overall.daily');

Route::get('/encoder/checker_1/summary', [Checker1SummaryController::class, 'index'])
     ->name('encoder.checker1.summary');
Route::get('/encoder/summary', [App\Http\Controllers\MacroOutputController::class, 'summary'])->name('macro_output.summary');
    Route::get('/encoder/checker_1', [MacroOutputController::class, 'index'])->name('macro_output.index');
Route::post('/encoder/checker_1/update', [MacroOutputController::class, 'bulkUpdate'])->name('macro_output.bulk_update');
Route::post('/encoder/checker_1/update-field', [MacroOutputController::class, 'updateField'])->name('macro_output.update_field');
Route::post('/macro_output/validate', [MacroOutputController::class, 'validateAddresses'])->name('macro_output.validate');
Route::get('/macro_output/download', [MacroOutputController::class, 'download'])->name('macro_output.download');
Route::post('/macro_output/validate-items', [MacroOutputController::class, 'validateItems']);


// Checker 2 GSheet Settings Routes
Route::get('checker_2/gsheet/settings', [Checker2GsheetController::class, 'index'])->name('checker2.settings.index');
    Route::post('checker_2/gsheet/settings', [Checker2GsheetController::class, 'store'])->name('checker2.settings.store');
    Route::put('checker_2/gsheet/settings/{id}', [Checker2GsheetController::class, 'update'])->name('checker2.settings.update');
    Route::delete('checker_2/gsheet/settings/{id}', [Checker2GsheetController::class, 'destroy'])->name('checker2.settings.delete');
    Route::get('/checker_2/gsheet/import', [Checker2GsheetController::class, 'showImportPage']);
Route::post('/checker_2/gsheet/import', [Checker2GsheetController::class, 'import']);


Route::get('/ads-manager/import-form', [AdsManagerReportController::class, 'showImportForm'])->name('ads-manager.import-form');
Route::post('/ads-manager/import', [AdsManagerReportController::class, 'import'])->name('ads-manager.import');

Route::get('/ads-manager/edit-messaging-template', [AdCampaignCreativeController::class, 'editMessagingTemplate'])->name('ads_manager_creatives.edit');
Route::post('/ads-manager/edit-messaging-template', [AdCampaignCreativeController::class, 'bulkUpdate'])->name('ads_manager_creatives.bulk_update');
Route::put('/ads-manager/edit-messaging-template/{id}', [AdCampaignCreativeController::class, 'update'])->name('ads_manager_creatives.update');

// Ads Manager Reports - Import (simple status)
Route::get('/ads_manager/report', [\App\Http\Controllers\AdsManagerReportController::class, 'index'])
    ->name('ads_manager.report');

Route::post('/ads_manager/report', [\App\Http\Controllers\AdsManagerReportController::class, 'store'])
    ->name('ads_manager.report.store');

// JSON status endpoint for polling (no full reload)
Route::get('/ads_manager/report/status', [\App\Http\Controllers\AdsManagerReportController::class, 'status'])
    ->name('ads_manager.report.status');

    Route::get('/ads_manager/cpp', [\App\Http\Controllers\CPPController::class, 'index'])->name('ads_manager.cpp');
    Route::get('/ads_manager/cpp/data', [\App\Http\Controllers\CPPController::class, 'data'])
    ->name('ads_manager.cpp.data');
    Route::get(
    '/ads_manager/pancake-subscription-checker',
    [PancakeSubscriptionCheckerController::class, 'index']
)->name('ads_manager.pancake_subscription_checker');

Route::get('/ads_manager/campaigns', [AdsManagerCampaignsController::class, 'index'])
     ->name('ads_manager.campaigns');

     Route::get('/ads_manager/campaigns/data', [AdsManagerCampaignsController::class, 'data'])
        ->name('ads_manager.campaigns.data');

    Route::get('/ads_manager/insights', [AdsInsightsController::class, 'index'])->name('ads.insights.index');
    Route::post('/ads_manager/insights/analyze',
    [\App\Http\Controllers\AdsInsightsController::class, 'analyze']
)->name('ads.insights.analyze')->middleware('throttle:20,1'); // 20 req / minute
    Route::get('/ads_manager/insights/preview', [AdsInsightsController::class, 'preview'])->name('ads.insights.preview');

    
    Route::get('/task/my-everyday-task', [EverydayTaskController::class, 'index'])->name('everyday-tasks.index');
    Route::post('/task/my-everyday-task', [EverydayTaskController::class, 'store'])->name('everyday-tasks.store');
    Route::put('/task/my-everyday-task/{id}', [EverydayTaskController::class, 'update'])->name('everyday-tasks.update');
    Route::delete('/task/my-everyday-task/{id}', [EverydayTaskController::class, 'destroy'])->name('everyday-tasks.destroy');
    Route::get('/task/create-everyday-task', [EverydayTaskController::class, 'showCreateForm'])->name('everyday-task.create-form');
Route::post('/task/create-everyday-task', [EverydayTaskController::class, 'store'])->name('everyday-task.store');

    Route::get('/orders/tally', [OrderTallyController::class, 'index'])->name('orders.tally');
    Route::get('/orders/tally/{date}', [OrderTallyController::class, 'show'])->name('orders.tally.show');

    // ✅ Likha Order (multi-sheet support)
    Route::post('/likha_order_import/delete', [LikhaOrderImportController::class, 'clearAll']);
    Route::get('/likha_order_import/settings', [LikhaOrderSettingController::class, 'settings'])->name('likha.settings');
    Route::post('/likha_order_import/settings', [LikhaOrderSettingController::class, 'store'])->name('likha.settings.store');
    Route::put('/likha_order_import/settings/{id}', [LikhaOrderSettingController::class, 'update'])->name('likha.settings.update');
    Route::delete('/likha_order_import/settings/{id}', [LikhaOrderSettingController::class, 'destroy'])->name('likha.settings.delete');
    Route::match(['get', 'post'], '/likha_order_import', [LikhaOrderImportController::class, 'import'])->name('likha.import');
    Route::match(['get', 'delete'], '/likha_order/view', [LikhaOrderImportController::class, 'view'])->name('likha.view');

    // ✅ Macro GSheet
    Route::put('/macro/settings/{id}', [MacroGsheetController::class, 'update'])->name('macro.settings.update');
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
    Route::get('/task/create', function () {
    $role = auth()->user()->employeeProfile?->role;
    if (!$role || !in_array($role, ['CEO', 'Marketing - OIC'])) abort(403);
    return app(\App\Http\Controllers\TaskController::class)->showCreateForm();
})->name('task.create.form');
Route::post('/task/create', [TaskController::class, 'create'])->name('task.create');
    Route::get('/task/my-tasks', [TaskController::class, 'myTasks'])->name('task.my-tasks');
    Route::post('/task/update-status', [TaskController::class, 'updateStatus'])->name('task.updateStatus');
    Route::post('/task/update-creator-remarks', [TaskController::class, 'updateCreatorRemarks'])->name('task.updateCreatorRemarks');
    Route::get('/task/team-tasks', function () {
    $role = auth()->user()->employeeProfile?->role;
    if (!$role || !in_array($role, ['CEO', 'Marketing - OIC'])) abort(403);
    return app(\App\Http\Controllers\TaskController::class)->teamTasks(request());
})->name('task.team-tasks');


Route::post('/task/update-team-task', [TaskController::class, 'updateTeamTask'])->name('task.updateTeamTask');


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
    $role = auth()->user()->employeeProfile?->role;
    if (!$role || !in_array($role, ['Marketing', 'CEO', 'Marketing - OIC'])) abort(403);
    return app(\App\Http\Controllers\CPPReportController::class)->index();
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
    Route::get('/jnt/status-summary', [FromJntController::class, 'statusSummary'])
    ->name('jnt.status-summary');
    Route::get('/jnt/status-summary/details', [FromJntController::class, 'statusSummaryDetails'])
    ->name('jnt.status-summary.details');
    Route::get('/jnt/status-summary/rts-details', [FromJntController::class, 'statusSummaryRtsDetails'])
    ->name('jnt.status-summary.rts-details');

    Route::get('/jnt/dashboard', [FromJntController::class, 'index'])
    ->name('jnt.dashboard');
    Route::get('/jnt/status', [JntStatusController::class, 'index'])->name('jnt.status');

Route::get('/jnt/chatblast/gsheet/settings', [JntChatblastGsheetController::class, 'settings'])
    ->name('jnt.chatblast.gsheet.settings');

Route::post('/jnt/chatblast/gsheet/settings', [JntChatblastGsheetController::class, 'store'])
    ->name('jnt.chatblast.gsheet.settings.store');

Route::delete('/jnt/chatblast/gsheet/settings/{id}', [JntChatblastGsheetController::class, 'destroy'])
    ->name('jnt.chatblast.gsheet.settings.delete');

// ✅ EXPORT endpoint (button from /jnt/status)
Route::post('/jnt/status/export-to-gsheet', [JntStatusController::class, 'exportToGsheet'])
    ->name('jnt.status.export_to_gsheet');


    Route::get('/jnt_upload', [JntUploadController::class, 'index'])->name('jnt.upload.index');
    Route::post('/jnt_upload', [JntUploadController::class, 'store'])->name('jnt.upload.store');
    Route::get('/jnt_upload/status/{uploadLog}', [JntUploadController::class, 'status'])->name('jnt.upload.status');
    Route::view('/jnt_update', 'jnt_update');
    Route::post('/jnt_update', [FromJntController::class, 'updateOrInsert']);
    Route::get('/jnt_rts', [FromJntController::class, 'rtsView']);
    Route::post('/jnt_rts', [FromJntController::class, 'rtsFiltered']);
    Route::get('/jnt/hold', [JntHoldController::class, 'index'])->name('jnt.hold');


    // ✅ Misc
    Route::view('/rts', 'rts');
    Route::get('/phpinfo', fn () => phpinfo());
});
