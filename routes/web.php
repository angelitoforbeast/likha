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
use App\Http\Controllers\JntUploadV2Controller;
use App\Http\Controllers\SystemMonitorController;
use App\Http\Controllers\PancakeSubscriptionCheckerController;
use App\Http\Controllers\AdsManagerCampaignsController;
use App\Http\Controllers\AdsInsightsController;
use App\Http\Controllers\GPTAdGeneratorController;
use App\Http\Controllers\JntHoldController;
use App\Http\Controllers\JntHoldDownloadController;
use App\Http\Controllers\ItemCogsController;
use App\Http\Controllers\SummaryOverallController;
use App\Http\Controllers\OwnerPrivateController;
use App\Http\Controllers\JntOndelController;
use App\Http\Controllers\JntRemittanceController;
use App\Http\Controllers\SalesDeclarationController;
use App\Http\Controllers\FeeSettingController;
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
use App\Http\Controllers\MacroOutputPageNameController;
use App\Http\Controllers\JntAccountController;
use App\Http\Controllers\PancakeConversationController;
use App\Http\Controllers\JntStickerController;
use App\Http\Controllers\EncoderPendingRateController;
use App\Http\Controllers\PancakePageIdMappingController;
use App\Http\Controllers\JntStickerSegregatorController;
use App\Http\Controllers\PancakeRetrieve2Controller;
use App\Http\Controllers\JntAddressController;
use App\Http\Controllers\JntShipmentController;
use App\Http\Controllers\JntOrderUiController;
use App\Http\Controllers\JntWaybillController;
use App\Http\Controllers\JntOrderManagementController;
use App\Http\Controllers\PancakeConversationIndexController;
use App\Http\Controllers\JntWaybillPrintController;
use App\Http\Controllers\JntWaybillFilesController;
use App\Http\Controllers\Jnt\SenderAddressController;
use App\Http\Controllers\Jnt\Waybills\SenderNameController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\PhoneWhitelistController;
use App\Http\Controllers\ValidationListController;
// use App\Http\Controllers\AutomationController;
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

// 100 GPT generations per hour per IP — protects OpenAI billing from runaway loops.
// Bumped from 20 → 100 (5x) per user request — limit was hitting normal users.
Route::post('/api/generate-gpt-summary', [GPTAdGeneratorController::class, 'generate'])
    ->middleware('throttle:100,60');
Route::get('/gpt-ad-generator', [GPTAdGeneratorController::class, 'showGeneratorForm']);
Route::get('/gpt-ad-generator/history', [GPTAdGeneratorController::class, 'history'])
    ->name('gpt.history');
Route::get('/gpt-ad-generator/history/{id}', [GPTAdGeneratorController::class, 'historyDetail'])
    ->whereNumber('id')
    ->name('gpt.history.detail');
// Save the base prompt (append-only into gpt_prompts table). Throttled tighter.
Route::post('/gpt-ad-generator/prompt', [GPTAdGeneratorController::class, 'savePrompt'])
    ->middleware('throttle:10,60')
    ->name('gpt.prompt.save');
Route::get('/gpt-ad-generator/prompt-history', [GPTAdGeneratorController::class, 'promptHistory'])
    ->name('gpt.prompt.history');
Route::get('/gpt-ad-generator/prompt-history/{id}', [GPTAdGeneratorController::class, 'promptVersion'])
    ->whereNumber('id')
    ->name('gpt.prompt.version');
Route::post('/gpt-ad-generator/prompt-history/{id}/restore', [GPTAdGeneratorController::class, 'promptRestore'])
    ->whereNumber('id')
    ->middleware('throttle:30,1')
    ->name('gpt.prompt.restore');
// Suggestions are cached 5min, but still throttle to 60/min/IP for safety.
Route::get('/ad-copy-suggestions', [GPTAdGeneratorController::class, 'loadAdCopySuggestions'])
    ->middleware('throttle:60,1')
    ->name('gpt.suggestions');

// ── Video analysis endpoints for the GPT Ad Generator ──────────────────
// Hash check (cheap, frequent) — pre-upload dedup probe.
Route::post('/gpt-ad-generator/check-video-hash', [GPTAdGeneratorController::class, 'checkVideoHash'])
    ->middleware('throttle:120,1')
    ->name('gpt.video.check');
// Heavy endpoint — uploads + runs ffmpeg + OpenAI calls. Tighter throttle.
Route::post('/gpt-ad-generator/analyze-video', [GPTAdGeneratorController::class, 'analyzeVideo'])
    ->middleware('throttle:20,60')
    ->name('gpt.video.analyze');
Route::get('/gpt-ad-generator/video-analysis/{id}', [GPTAdGeneratorController::class, 'getVideoAnalysis'])
    ->whereNumber('id')
    ->name('gpt.video.get');
Route::get('/gpt-ad-generator/video-history', [GPTAdGeneratorController::class, 'videoHistory'])
    ->name('gpt.video.history');

// ✅ Ondel Counter
Route::get('/jnt/ondel', [JntOndelController::class, 'index'])->name('jnt.ondel');
Route::post('/jnt/ondel/process', [JntOndelController::class, 'process'])->name('jnt.ondel.process');


// Route::post('/automation/macro-import', [AutomationController::class, 'macroImport']);







// ✅ Protected routes

// ✅ Daily Checklist
Route::middleware(['web','auth'])->prefix('checklist')->name('checklist.')->group(function () {
    Route::get('/', [\App\Http\Controllers\ChecklistController::class, 'index'])->name('index');
    Route::get('/manage', [\App\Http\Controllers\ChecklistController::class, 'manage'])->name('manage');
    Route::get('/report', [\App\Http\Controllers\ChecklistController::class, 'report'])->name('report');
    Route::post('/{task}/submit', [\App\Http\Controllers\ChecklistController::class, 'submit'])->name('submit');
    Route::delete('/submission/{submission}', [\App\Http\Controllers\ChecklistController::class, 'deleteSubmission'])->name('delete-submission');
    Route::delete('/files/{file}', [\App\Http\Controllers\ChecklistController::class, 'deleteFile'])->name('delete-file');
    Route::post('/tasks', [\App\Http\Controllers\ChecklistController::class, 'storeTask'])->name('store-task');
    Route::patch('/tasks/{task}', [\App\Http\Controllers\ChecklistController::class, 'updateTask'])->name('update-task');
    Route::delete('/tasks/{task}', [\App\Http\Controllers\ChecklistController::class, 'destroyTask'])->name('destroy-task');
    Route::post('/tasks/{task}/duplicate', [\App\Http\Controllers\ChecklistController::class, 'duplicateTask'])->name('duplicate-task');
    Route::post('/tasks/reorder', [\App\Http\Controllers\ChecklistController::class, 'reorderTasks'])->name('reorder');
    Route::post('/tasks/bulk-assign', [\App\Http\Controllers\ChecklistController::class, 'bulkAssign'])->name('bulk-assign');
    Route::post('/tasks/bulk-type', [\App\Http\Controllers\ChecklistController::class, 'bulkType'])->name('bulk-type');
    Route::post('/tasks/bulk-submission-type', [\App\Http\Controllers\ChecklistController::class, 'bulkSubmissionType'])->name('bulk-submission-type');
    Route::post('/submission/{submission}/analyze', [\App\Http\Controllers\ChecklistController::class, 'analyzeSubmission'])->name('analyze');
    Route::get('/submission/{submission}/analysis-logs', [\App\Http\Controllers\ChecklistController::class, 'getAnalysisLogs'])->name('analysis-logs');
    Route::post('/submission/{submission}/approval-check', [\App\Http\Controllers\ChecklistController::class, 'approvalCheck'])->name('approval-check');
    Route::get('/submission/{submission}/approval-logs', [\App\Http\Controllers\ChecklistController::class, 'getApprovalLogs'])->name('approval-logs');
});

// Allow any authenticated user to view the Allowed IPs page (no IP check)
Route::middleware(['web','auth'])->group(function () {
    Route::get('/allowed-ips', [AllowedIpController::class, 'index'])->name('allowed_ips.index');
    Route::post('/allowed-ips', [AllowedIpController::class, 'store'])->name('allowed_ips.store');
    Route::put('/allowed-ips/{allowedIp}', [AllowedIpController::class, 'update'])->name('allowed_ips.update');
    Route::delete('/allowed-ips/{allowedIp}', [AllowedIpController::class, 'destroy'])->name('allowed_ips.destroy');
});

Route::middleware(['web','auth','allowed_ip'])->group(function () {



 // ✅ Allowed IPs (CEO only) - same style as /cpp
Route::get('/allowed-ips', function () {
    // Allow any authenticated user to access the page; controller will
    // still restrict which rows are visible (CEO sees all, others see their own).
    return app(AllowedIpController::class)->index();
})->name('allowed_ips.index');

Route::post('/allowed-ips', function () {
    $role = auth()->user()->employeeProfile?->role;
    $createAllowed = ['CEO', 'Data Encoder - OIC', 'Marketing - OIC'];
    if (!$role || !in_array($role, $createAllowed)) abort(403);

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



Route::get('/pancake/retrieve2', [PancakeRetrieve2Controller::class, 'index']);
Route::get('/pancake/retrieve2/export', [PancakeRetrieve2Controller::class, 'export'])
    ->name('pancake.retrieve2.export');

Route::get('/pancake/retrieve-orders', [RetrieveOrdersController::class, 'index'])
    ->name('pancake.retrieve-orders.index');

Route::post('/pancake/retrieve-orders/check', [RetrieveOrdersController::class, 'check'])
    ->name('pancake.retrieve-orders.check');
Route::get('/pancake/conversations', [PancakeConversationController::class, 'index'])
        ->name('pancake.conversations');

    Route::post('/pancake/conversations/upload', [PancakeConversationController::class, 'store'])
        ->name('pancake.conversations.store');

    Route::get('/pancake/conversations/status', [PancakeConversationController::class, 'status'])
        ->name('pancake.conversations.status');



    Route::get('/pancake/page-id-mapping', [PancakePageIdMappingController::class, 'index'])
    ->name('pancake.page_id_mapping.index');

Route::post('/pancake/page-id-mapping', [PancakePageIdMappingController::class, 'store'])
    ->name('pancake.page_id_mapping.store');

Route::put('/pancake/page-id-mapping/{id}', [PancakePageIdMappingController::class, 'update'])
    ->name('pancake.page_id_mapping.update');

Route::delete('/pancake/page-id-mapping/{id}', [PancakePageIdMappingController::class, 'destroy'])
    ->name('pancake.page_id_mapping.destroy');

    Route::get('/pancake/index', [PancakeConversationIndexController::class, 'index'])
    ->name('pancake.index');



Route::get('/ads_manager/ad_account', [AdAccountController::class, 'index'])
    ->name('ad_accounts.index');

Route::get('/ads_manager/ad_account/{ad_account_id}', [AdAccountController::class, 'index'])
    ->name('ad_accounts.edit');

Route::post('/ads_manager/ad_account', [AdAccountController::class, 'store'])
    ->name('ad_accounts.store');

Route::delete('/ads_manager/ad_account/{ad_account_id}', [AdAccountController::class, 'destroy'])
    ->name('ad_accounts.destroy');

Route::get('/ads_manager/payment/upload', [PaymentActivityController::class, 'create'])
    ->name('ads_payment.upload');

Route::post('/ads_manager/payment/upload', [PaymentActivityController::class, 'store'])
    ->name('ads_payment.upload.store');

// View-only (simple table of saved rows)
Route::get('/ads_manager/payment/records', [PaymentActivityController::class, 'records'])
    ->name('ads_payment.records.index');

    Route::delete('/ads_manager/payment/records', [PaymentActivityController::class, 'destroyAll'])
    ->name('ads_payment.records.delete_all');
Route::post('/ads_manager/payment/records/update-remarks', [PaymentActivityController::class, 'updateRemarks'])
    ->name('ads_payment.records.update_remarks');

Route::post('/ads_manager/payment/records/destroy', [PaymentActivityController::class, 'destroy'])
    ->name('ads_payment.records.destroy');





Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::post('/profile', [ProfileController::class, 'update'])->name('profile.update');

Route::get('/jnt/remittance', [JntRemittanceController::class, 'index'])
    ->name('jnt.remittance');

// JNT Config hub — consolidates all JNT config screens into one nav entry.
Route::get('/jnt/config', [\App\Http\Controllers\JntConfigHubController::class, 'index'])->name('jnt.config');

// JNT Accounts & Page Mapping
Route::get('/jnt/accounts', [JntAccountController::class, 'index'])->name('jnt.accounts.index');
Route::post('/jnt/accounts', [JntAccountController::class, 'store'])->name('jnt.accounts.store');
Route::post('/jnt/settings', [JntAccountController::class, 'saveSettings'])->name('jnt.settings.save');
Route::get('/jnt/accounts/mapping', [JntAccountController::class, 'mapping'])->name('jnt.accounts.mapping');
Route::post('/jnt/accounts/mapping', [JntAccountController::class, 'saveMapping'])->name('jnt.accounts.mapping.save');
Route::put('/jnt/accounts/{account}', [JntAccountController::class, 'update'])->name('jnt.accounts.update');
Route::delete('/jnt/accounts/{account}', [JntAccountController::class, 'destroy'])->name('jnt.accounts.destroy');

// Fee Settings CRUD
Route::get('/jnt/fee-settings', [FeeSettingController::class, 'index'])->name('fee-settings.index');
Route::post('/jnt/fee-settings', [FeeSettingController::class, 'store'])->name('fee-settings.store');
Route::put('/jnt/fee-settings/{fee_setting}', [FeeSettingController::class, 'update'])->name('fee-settings.update');
Route::delete('/jnt/fee-settings/{fee_setting}', [FeeSettingController::class, 'destroy'])->name('fee-settings.destroy');
Route::get('/jnt/shipped', [\App\Http\Controllers\JntShippedController::class, 'index'])->name('jnt.shipped');

// Sales Declaration
Route::get('/jnt/sales-declaration', [SalesDeclarationController::class, 'index'])->name('jnt.sales-declaration');
Route::post('/jnt/sales-declaration/generate', [SalesDeclarationController::class, 'generate'])->name('jnt.sales-declaration.generate');
Route::post('/jnt/sales-declaration/filter', [SalesDeclarationController::class, 'filterOptions'])->name('jnt.sales-declaration.filter');
Route::get('/jnt/sales-declaration/export', [SalesDeclarationController::class, 'export'])->name('jnt.sales-declaration.export');
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
Route::post('/jnt/sender-name/settings', [PageSenderMappingController::class, 'saveSetting']);
Route::post('/jnt/sender-name/delete/{id}', [\App\Http\Controllers\PageSenderMappingController::class, 'delete']);

Route::get('/jnt/item-sender-name', [\App\Http\Controllers\PageItemSenderMappingController::class, 'index']);
Route::post('/jnt/item-sender-name/save', [\App\Http\Controllers\PageItemSenderMappingController::class, 'save']);
Route::post('/jnt/item-sender-name/delete/{id}', [\App\Http\Controllers\PageItemSenderMappingController::class, 'delete']);

Route::get('/jnt/item-types',              [\App\Http\Controllers\ItemTypeMappingController::class, 'index']);
Route::get('/jnt/item-types/grouped',      [\App\Http\Controllers\ItemTypeMappingController::class, 'grouped'])->name('jnt.item-types.grouped');
Route::get('/jnt/item-types/inline',       [\App\Http\Controllers\ItemTypeMappingController::class, 'inline']);
Route::post('/jnt/item-types/save',        [\App\Http\Controllers\ItemTypeMappingController::class, 'save']);
Route::post('/jnt/item-types/save-one',    [\App\Http\Controllers\ItemTypeMappingController::class, 'saveOne']);
Route::post('/jnt/item-types/delete/{id}', [\App\Http\Controllers\ItemTypeMappingController::class, 'delete']);
Route::get('/jnt/checker', [JntCheckerController::class, 'index'])->name('jnt.checker');
Route::post('/jnt/checker/upload', [JntCheckerController::class, 'upload'])->name('jnt.checker.upload');
Route::get('/jnt/checker/upload', fn () => redirect()->route('jnt.checker'));
Route::post('/jnt/checker/update', [\App\Http\Controllers\JntCheckerController::class, 'update'])
    ->name('jnt.checker.update');
Route::get('/jnt/waybills/sender-name', [SenderNameController::class, 'index'])
    ->name('jnt.waybills.sender_name');



Route::get('/jnt/stickers', [JntStickerController::class, 'index'])->name('jnt.stickers');

// AJAX: get DB waybills for date
Route::post('/jnt/stickers/db', [JntStickerController::class, 'dbWaybills'])->name('jnt.stickers.db');

// Commit flow (chunked uploads)
Route::post('/jnt/stickers/commit/init', [JntStickerController::class, 'commitInit'])->name('jnt.stickers.commit.init');
Route::post('/jnt/stickers/commit/upload', [JntStickerController::class, 'commitUploadChunk'])->name('jnt.stickers.commit.upload');
Route::post('/jnt/stickers/commit/finalize', [JntStickerController::class, 'commitFinalize'])->name('jnt.stickers.commit.finalize');
// ✅ COMMIT status + download (for auto-poll + downloadable outputs)
Route::get('/jnt/stickers/commit/status', [JntStickerController::class, 'commitStatus'])
    ->name('jnt.stickers.commit.status');

Route::get('/jnt/stickers/commit/download', [JntStickerController::class, 'commitDownload'])
    ->name('jnt.stickers.commit.download');
   



   Route::prefix('jnt')->group(function () {

    Route::get('/segregate-stickers', [JntStickerSegregatorController::class, 'show'])
        ->name('jnt.stickers.form');

    Route::post('/segregate-stickers', [JntStickerSegregatorController::class, 'submit'])
        ->name('jnt.stickers.submit');

    Route::get('/segregate-stickers/{runId}', [JntStickerSegregatorController::class, 'status'])
        ->where('runId', '[A-Za-z0-9\-_]+')
        ->name('jnt.stickers.status');

    Route::get('/segregate-stickers/{runId}/download/{file}', [JntStickerSegregatorController::class, 'download'])
        ->where([
            'runId' => '[A-Za-z0-9\-_]+',
            'file' => '[A-Za-z0-9\-\_\.]+',
        ])
        ->name('jnt.stickers.download');

    Route::get('/segregate-stickers/{runId}/download-zip', [JntStickerSegregatorController::class, 'downloadZip'])
        ->where('runId', '[A-Za-z0-9\-_]+')
        ->name('jnt.stickers.downloadZip');
});

Route::prefix('jnt/shipments')->group(function () {
    Route::get('/', [JntShipmentController::class, 'index']);
    Route::post('/create/{macroOutputId}', [JntShipmentController::class, 'createOne']);
    Route::post('/bulk-create', [JntShipmentController::class, 'bulkCreate']);
    Route::post('/track/{shipmentId}', [JntShipmentController::class, 'track']);
    Route::post('/cancel/{shipmentId}', [JntShipmentController::class, 'cancel']);
});
Route::get('jnt/orders', [\App\Http\Controllers\JntOrderUiController::class, 'index']);
Route::post('jnt/orders/batch', [\App\Http\Controllers\JntOrderUiController::class, 'createBatch']);

Route::get('jnt/orders/batch/{runId}', [\App\Http\Controllers\JntOrderUiController::class, 'showRun']);
Route::get('jnt/orders/batch/{runId}/status', [\App\Http\Controllers\JntOrderUiController::class, 'status']);
Route::post('jnt/orders/batch/{runId}/stop', [\App\Http\Controllers\JntOrderUiController::class, 'stop']);
Route::post('jnt/orders/batch/{runId}/retry-failed', [\App\Http\Controllers\JntOrderUiController::class, 'retryFailed'])->name('jnt.orders.retry-failed');
Route::post('jnt/orders/retry-by-date-page', [\App\Http\Controllers\JntOrderUiController::class, 'retryByDatePage'])->name('jnt.orders.retry-by-date-page');
Route::get('jnt/orders/debug/{shipmentId}', [\App\Http\Controllers\JntOrderUiController::class, 'debug'])
    ->whereNumber('shipmentId');
// ✅ Print one waybill (opens PDF)
Route::post('jnt/orders/print-one', [\App\Http\Controllers\JntOrderUiController::class, 'printOne'])
    ->name('jnt.orders.print_one');

// ✅ Print all waybills in a run (downloads ZIP)
Route::post('jnt/orders/batch/{runId}/print-zip', [\App\Http\Controllers\JntOrderUiController::class, 'printRunZip'])
    ->name('jnt.orders.print_run_zip');

Route::get('/jnt/waybills', [\App\Http\Controllers\JntWaybillController::class, 'index']);
Route::post('/jnt/waybills/query-one', [\App\Http\Controllers\JntWaybillController::class, 'queryOne']);
Route::get('/jnt/order-management', [JntOrderManagementController::class, 'index']);
Route::post('/jnt/order-management/query', [JntOrderManagementController::class, 'query']);

Route::get('/jnt/waybills/print', [JntWaybillPrintController::class, 'index']);
Route::post('/jnt/waybills/print-one', [JntWaybillPrintController::class, 'printOne']);


Route::post('/jnt/waybills/print-bulk', [JntWaybillPrintController::class, 'printBulk']);
Route::get('/jnt/waybills/print-bulk/run/{runId}', [JntWaybillPrintController::class, 'runPage']);
Route::get('/jnt/waybills/print-bulk/status/{runId}', [JntWaybillPrintController::class, 'statusRun']);
Route::get('/jnt/waybills/print-bulk/download/{runId}', [JntWaybillPrintController::class, 'download']);
Route::post('/jnt/waybills/print-bulk/cancel/{runId}', [JntWaybillPrintController::class, 'cancel']);


Route::get('/jnt/waybills/files', [JntWaybillFilesController::class, 'index'])
    ->name('jnt.waybills.files');

Route::get('/jnt/waybills/files/download/{runId}/{filename}', [JntWaybillFilesController::class, 'download'])
    ->whereNumber('runId')
    ->where('filename', '.*')
    ->name('jnt.waybills.files.download');

Route::delete('/jnt/waybills/files/{runId}/{filename}', [JntWaybillFilesController::class, 'destroy'])
    ->whereNumber('runId')
    ->where('filename', '.*')
    ->name('jnt.waybills.files.destroy');

Route::post('/jnt/waybills/files/destroy-old', [JntWaybillFilesController::class, 'destroyOld'])
    ->name('jnt.waybills.files.destroyOld');



Route::get('jnt/waybills/sender-address', [SenderAddressController::class, 'index'])
    ->name('jnt.sender_address.index');

Route::post('jnt/waybills/sender-address', [SenderAddressController::class, 'store'])
    ->name('jnt.sender_address.store');

Route::put('jnt/waybills/sender-address/{senderAddress}', [SenderAddressController::class, 'update'])
    ->name('jnt.sender_address.update');

Route::delete('jnt/waybills/sender-address/{senderAddress}', [SenderAddressController::class, 'destroy'])
    ->name('jnt.sender_address.destroy');


Route::get('/item/cogs', [ItemCogsController::class, 'index'])->name('item.cogs.index');
Route::get('/item/cogs/grid', [ItemCogsController::class, 'grid'])->name('item.cogs.grid');      // JSON grid for month
Route::post('/item/cogs/update', [ItemCogsController::class, 'update'])->name('item.cogs.update'); // edit one cell
Route::post('/item/cogs/delete', [ItemCogsController::class, 'delete'])->name('item.cogs.delete'); // delete one explicit cogs row → falls back to inheritance
Route::post('/item/cogs/clean-redundant', [ItemCogsController::class, 'cleanRedundant'])->name('item.cogs.clean-redundant'); // bulk-delete redundant anchors (same value as prior inheritance) sa visible month

Route::get('/owner/private', [OwnerPrivateController::class, 'index'])->name('owner.private');
Route::get('/owner/private/data', [OwnerPrivateController::class, 'data'])->name('owner.private.data');
Route::get('/owner/private/item-summary', [OwnerPrivateController::class, 'itemSummary'])->name('owner.private.item-summary');
Route::post('/owner/private/item-setting', [OwnerPrivateController::class, 'saveItemSetting'])->name('owner.private.item-setting.save');
Route::post('/owner/private/item-setting/delete', [OwnerPrivateController::class, 'deleteItemSetting'])->name('owner.private.item-setting.delete');
Route::post('/owner/private/refresh-primary-items', [OwnerPrivateController::class, 'refreshPrimaryItems'])->name('owner.private.refresh-primary-items');
Route::get('/owner/private/page-range-breakdown', [OwnerPrivateController::class, 'pageRangeBreakdown'])->name('owner.private.page-range-breakdown');
Route::get('/owner/private/breakdown', [OwnerPrivateController::class, 'breakdownPage'])->name('owner.private.breakdown');
Route::get('/owner/private/edit-logs', [OwnerPrivateController::class, 'editLogs'])->name('owner.private.edit-logs');

// Per-(page, date) Action note — CEO + Marketing-OIC + Marketing.
Route::post('/owner/private/action', [OwnerPrivateController::class, 'saveAction'])->name('owner.private.action.save');
Route::get('/owner/private/action/logs', [OwnerPrivateController::class, 'actionLogs'])->name('owner.private.action.logs');

// /owner/private snapshots — CEO-only frozen captures of the rendered state.
Route::get('/owner/private/snapshots',         [\App\Http\Controllers\OwnerPrivateSnapshotsController::class, 'index'])
    ->name('owner.private.snapshots.index');
Route::post('/owner/private/snapshots',        [\App\Http\Controllers\OwnerPrivateSnapshotsController::class, 'save'])
    ->name('owner.private.snapshots.save');
Route::get('/owner/private/snapshots/{id}',    [\App\Http\Controllers\OwnerPrivateSnapshotsController::class, 'show'])
    ->whereNumber('id')->name('owner.private.snapshots.show');
Route::delete('/owner/private/snapshots/{id}', [\App\Http\Controllers\OwnerPrivateSnapshotsController::class, 'destroy'])
    ->whereNumber('id')->name('owner.private.snapshots.destroy');
Route::get('/owner/private/daily', [OwnerPrivateController::class, 'daily'])->name('owner.private.daily');
Route::get('/owner/private/daily/data', [OwnerPrivateController::class, 'dailyData'])->name('owner.private.daily.data');

// CEO-only Users credentials oversight — view-only list + password edit
Route::get('/owner/users', [\App\Http\Controllers\OwnerUsersController::class, 'index'])
    ->name('owner.users');
Route::post('/owner/users/{id}/password', [\App\Http\Controllers\OwnerUsersController::class, 'updatePassword'])
    ->whereNumber('id')->name('owner.users.password');

Route::get('/summary/overall', [SummaryOverallController::class, 'index'])->name('summary.overall');
Route::get('/summary/overall/data', [SummaryOverallController::class, 'data'])->name('summary.overall.data');
Route::get('/summary/overall/daily', [SummaryOverallController::class, 'daily'])->name('summary.overall.daily');

Route::get('/encoder/checker_1/summary', [Checker1SummaryController::class, 'index'])
     ->name('encoder.checker1.summary');
Route::get('/encoder/checker_1/idle-summary', [App\Http\Controllers\Encoder\Checker1IdleSummaryController::class, 'index'])
     ->name('encoder.checker1.idle-summary');
Route::get('/encoder/checker_1/settings', [App\Http\Controllers\Encoder\Checker1SettingsController::class, 'index'])
     ->name('encoder.checker1.settings');
Route::post('/encoder/checker_1/settings', [App\Http\Controllers\Encoder\Checker1SettingsController::class, 'update'])
     ->name('encoder.checker1.settings.update');
Route::get('/encoder/summary', [App\Http\Controllers\MacroOutputController::class, 'summary'])->name('macro_output.summary');
    Route::get('/encoder/checker_1', [MacroOutputController::class, 'index'])->name('macro_output.index');
Route::post('/encoder/checker_1/update', [MacroOutputController::class, 'bulkUpdate'])->name('macro_output.bulk_update');
Route::post('/encoder/checker_1/update-field', [MacroOutputController::class, 'updateField'])->name('macro_output.update_field');
Route::post('/macro_output/validate', [MacroOutputController::class, 'validateAddresses'])->name('macro_output.validate');

// ✅ AI Checker — CHECKER_11_1 macro port (PHP). Drives the /encoder/checker_1 toolbar button.
// Batch is frontend-driven (JS loops calling run-row sequentially) — no background job.
Route::get ('/encoder/checker_1/ai-checker/count',  [\App\Http\Controllers\MacroCheckerController::class, 'count'])
    ->name('macro_checker.count');
Route::post('/encoder/checker_1/ai-checker/start',  [\App\Http\Controllers\MacroCheckerController::class, 'start'])
    ->name('macro_checker.start');
Route::post('/encoder/checker_1/ai-checker/run-row/{id}', [\App\Http\Controllers\MacroCheckerController::class, 'runRow'])
    ->whereNumber('id')
    ->name('macro_checker.run_row');
Route::get('/encoder/checker_1/ai-checker/logs', [\App\Http\Controllers\MacroCheckerController::class, 'logs'])
    ->name('macro_checker.logs');
Route::get('/macro_output/download', [MacroOutputController::class, 'download'])->name('macro_output.download');
Route::post('/macro_output/validate-items', [MacroOutputController::class, 'validateItems']);
Route::post('/macro_output/validate1', [MacroOutputController::class, 'validateCheckerToFix'])
  ->name('macro_output.validate1');
  Route::get('/macro_output/validated-summary', [MacroOutputController::class, 'validatedSummary'])
    ->name('macro_output.validated_summary');


Route::get('/encoder/page-name', [MacroOutputPageNameController::class, 'index'])
    ->name('encoder.page-name');
    Route::get('/encoder/pending-rate', [EncoderPendingRateController::class, 'index'])->name('encoder.pending-rate');
    Route::post('/macro_output/pancake-more', [MacroOutputController::class, 'pancakeMore'])
    ->name('macro_output.pancake_more');
    Route::get('jnt/address', [JntAddressController::class, 'index'])->name('jnt.address');
Route::get('jnt/address/search', [JntAddressController::class, 'search'])->name('jnt.address.search');



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

    // Snapshot capture — hit when user clicks the Copy Table button sa /cpp.
    // Saves the current /cpp matrix (today only) sa cpp_snapshots, bucket
    // auto-derived from PH-local click time.
    Route::post('/ads_manager/cpp/snapshot', [\App\Http\Controllers\CPPController::class, 'saveSnapshot'])
        ->name('ads_manager.cpp.snapshot');

    // Timeline view — grid of buckets × dates with click-to-detail modal.
    Route::get('/ads_manager/cpp/timeline',        [\App\Http\Controllers\CPPController::class, 'timeline'])
        ->name('ads_manager.cpp.timeline');
    Route::get('/ads_manager/cpp/timeline/data',   [\App\Http\Controllers\CPPController::class, 'timelineData'])
        ->name('ads_manager.cpp.timeline.data');
    Route::get('/ads_manager/cpp/timeline/detail', [\App\Http\Controllers\CPPController::class, 'timelineDetail'])
        ->name('ads_manager.cpp.timeline.detail');
    Route::get(
    '/ads_manager/pancake-subscription-checker',
    [PancakeSubscriptionCheckerController::class, 'index']
)->name('ads_manager.pancake_subscription_checker');

Route::get('/ads_manager/campaigns', [AdsManagerCampaignsController::class, 'index'])
     ->name('ads_manager.campaigns');

     Route::get('/ads_manager/campaigns/data', [AdsManagerCampaignsController::class, 'data'])
        ->name('ads_manager.campaigns.data');

     // Batched data endpoint — fetches multiple pages' campaigns in ONE request.
     // Used by /owner/private "Expand all" para mawala yung N parallel HTTP calls.
     // Frontend falls back to per-page data() endpoint kung mag-fail.
     Route::post('/ads_manager/campaigns/batch-data', [AdsManagerCampaignsController::class, 'batchData'])
        ->name('ads_manager.campaigns.batch-data');

     // Creative preview popup (FB post embed + welcome msg / quick replies preview).
     // Used by /owner/private expanded-campaigns panel.
     Route::get('/ads_manager/creative-preview', [AdsManagerCampaignsController::class, 'creativePreview'])
        ->name('ads_manager.creative-preview');

     // Read-only catalog viewer (ad_catalog table). Mini Ads Manager-style tree
     // (Campaign → Ad Set → Ad) lazy-loaded on expand. Data from ad_catalog only.
     // Auto-maintained sa uploads.
     Route::get('/ads_manager/catalog',          [\App\Http\Controllers\AdsManagerCatalogController::class, 'index'])
        ->name('ads_manager.catalog');
     Route::get('/ads_manager/catalog/adsets',   [\App\Http\Controllers\AdsManagerCatalogController::class, 'adsets'])
        ->name('ads_manager.catalog.adsets');
     Route::get('/ads_manager/catalog/ads',      [\App\Http\Controllers\AdsManagerCatalogController::class, 'ads'])
        ->name('ads_manager.catalog.ads');

     // History — daily change log derived from spend transitions.
     Route::get('/ads_manager/campaigns/history',      [AdsManagerCampaignsController::class, 'history'])
        ->name('ads_manager.campaigns.history');
     Route::get('/ads_manager/campaigns/history/data', [AdsManagerCampaignsController::class, 'historyData'])
        ->name('ads_manager.campaigns.history.data');

     // Manual campaign ownership tagging — used by history page dropdown.
     Route::get ('/ads_manager/campaigns/assignments',         [\App\Http\Controllers\CampaignAssignmentController::class, 'list'])
        ->name('ads_manager.campaigns.assignments.list');
     Route::post('/ads_manager/campaigns/assignments',         [\App\Http\Controllers\CampaignAssignmentController::class, 'save'])
        ->name('ads_manager.campaigns.assignments.save');
     Route::get ('/ads_manager/campaigns/assignments/history', [\App\Http\Controllers\CampaignAssignmentController::class, 'history'])
        ->name('ads_manager.campaigns.assignments.history');
     // Full audit log page (with filters + pagination)
     Route::get ('/ads_manager/campaigns/assignments/log', [\App\Http\Controllers\CampaignAssignmentController::class, 'logPage'])
        ->name('ads_manager.campaigns.assignments.log');
     Route::get ('/ads_manager/employees',                     [\App\Http\Controllers\CampaignAssignmentController::class, 'employees'])
        ->name('ads_manager.employees');

     // TEMP diagnostic — verify earliest day / starts in DB. Remove after debug.
     Route::get('/ads_manager/campaigns/_diag', [AdsManagerCampaignsController::class, 'diag'])
        ->name('ads_manager.campaigns.diag');

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

Route::get('/likha_order_import', [LikhaOrderImportController::class, 'index']);
Route::post('/likha_order_import/start', [LikhaOrderImportController::class, 'start']);
Route::get('/likha_order_import/status', [LikhaOrderImportController::class, 'status']);

Route::get('/likha_order_import/settings', [LikhaOrderSettingController::class, 'settings']);
Route::post('/likha_order_import/settings', [LikhaOrderSettingController::class, 'store']);
Route::put('/likha_order_import/settings/{id}', [LikhaOrderSettingController::class, 'update']);
Route::delete('/likha_order_import/settings/{id}', [LikhaOrderSettingController::class, 'destroy']);
Route::post('/likha_order_import/settings/{id}/archive', [LikhaOrderSettingController::class, 'archive'])
     ->name('likha_order.settings.archive');
Route::post('/likha_order_import/settings/{id}/unarchive', [LikhaOrderSettingController::class, 'unarchive'])
     ->name('likha_order.settings.unarchive');

// Existing view page mo (keep)
Route::match(['get','delete'], '/likha_order/view', [LikhaOrderImportController::class, 'view']);


// ── Conversation Tracker import ──────────────────────────────────────
Route::get ('/conversation/tracker',        [\App\Http\Controllers\ConversationTrackerImportController::class, 'index'])
    ->name('conversation.tracker');
Route::post('/conversation/tracker/start',  [\App\Http\Controllers\ConversationTrackerImportController::class, 'start'])
    ->name('conversation.tracker.start');
Route::get ('/conversation/tracker/status', [\App\Http\Controllers\ConversationTrackerImportController::class, 'status'])
    ->name('conversation.tracker.status');
Route::match(['get','delete'], '/conversation/tracker/view', [\App\Http\Controllers\ConversationTrackerImportController::class, 'view'])
    ->name('conversation.tracker.view');
Route::delete('/conversation/tracker/view/{id}', [\App\Http\Controllers\ConversationTrackerImportController::class, 'destroyRow'])
    ->whereNumber('id')
    ->name('conversation.tracker.row.delete');
Route::get('/conversation/tracker/stats', [\App\Http\Controllers\ConversationTrackerImportController::class, 'stats'])
    ->name('conversation.tracker.stats');
Route::get('/conversation/tracker/compare', [\App\Http\Controllers\ConversationTrackerImportController::class, 'compare'])
    ->name('conversation.tracker.compare');

// Flow Templates editor (per-page bubble library)
Route::get ('/conversation/tracker/flows', [\App\Http\Controllers\ConversationTrackerImportController::class, 'flowsIndex'])
    ->name('conversation.tracker.flows');
Route::post('/conversation/tracker/flows/save', [\App\Http\Controllers\ConversationTrackerImportController::class, 'flowsSave'])
    ->name('conversation.tracker.flows.save');
Route::post('/conversation/tracker/flows/upload', [\App\Http\Controllers\ConversationTrackerImportController::class, 'flowsUpload'])
    ->middleware('throttle:30,1')
    ->name('conversation.tracker.flows.upload');

Route::get   ('/conversation/tracker/settings', [\App\Http\Controllers\ConversationTrackerSettingController::class, 'settings'])
    ->name('conversation.tracker.settings');
Route::post  ('/conversation/tracker/settings', [\App\Http\Controllers\ConversationTrackerSettingController::class, 'store']);
Route::put   ('/conversation/tracker/settings/{id}', [\App\Http\Controllers\ConversationTrackerSettingController::class, 'update']);
Route::delete('/conversation/tracker/settings/{id}', [\App\Http\Controllers\ConversationTrackerSettingController::class, 'destroy']);
Route::get   ('/conversation/tracker/settings/fetch-sheets', [\App\Http\Controllers\ConversationTrackerSettingController::class, 'fetchSheetTabs'])
    ->name('conversation.tracker.fetch-sheets');


    // ✅ Cancel Detector (Phase 1: gsheet upload only; Phase 2 will add AI classification)
Route::get ('/conversation/cancel-detector',        [\App\Http\Controllers\CancelDetectorImportController::class, 'index'])
    ->name('conversation.cancel-detector');
Route::post('/conversation/cancel-detector/start',  [\App\Http\Controllers\CancelDetectorImportController::class, 'start'])
    ->name('conversation.cancel-detector.start');
Route::get ('/conversation/cancel-detector/status', [\App\Http\Controllers\CancelDetectorImportController::class, 'status'])
    ->name('conversation.cancel-detector.status');
Route::match(['get','delete'], '/conversation/cancel-detector/view', [\App\Http\Controllers\CancelDetectorImportController::class, 'view'])
    ->name('conversation.cancel-detector.view');
Route::delete('/conversation/cancel-detector/view/{id}', [\App\Http\Controllers\CancelDetectorImportController::class, 'destroyRow'])
    ->name('conversation.cancel-detector.row.delete');

Route::get   ('/conversation/cancel-detector/settings', [\App\Http\Controllers\CancelDetectorSettingController::class, 'settings'])
    ->name('conversation.cancel-detector.settings');
Route::post  ('/conversation/cancel-detector/settings', [\App\Http\Controllers\CancelDetectorSettingController::class, 'store']);
Route::put   ('/conversation/cancel-detector/settings/{id}', [\App\Http\Controllers\CancelDetectorSettingController::class, 'update']);
Route::delete('/conversation/cancel-detector/settings/{id}', [\App\Http\Controllers\CancelDetectorSettingController::class, 'destroy']);
Route::post  ('/conversation/cancel-detector/settings/{id}/toggle-archive', [\App\Http\Controllers\CancelDetectorSettingController::class, 'toggleArchive'])
    ->name('conversation.cancel-detector.settings.toggle-archive');
Route::get   ('/conversation/cancel-detector/settings/fetch-sheets', [\App\Http\Controllers\CancelDetectorSettingController::class, 'fetchSheetTabs'])
    ->name('conversation.cancel-detector.fetch-sheets');


    // ✅ Macro GSheet

Route::put('/macro/settings/{id}', [MacroGsheetController::class, 'update'])->name('macro.settings.update');
Route::get('/macro/gsheet/settings', [MacroGsheetController::class, 'settings'])->name('macro.settings');
Route::post('/macro/gsheet/settings', [MacroGsheetController::class, 'storeSetting'])->name('macro.settings.store');
Route::delete('/macro/gsheet/settings/{id}', [MacroGsheetController::class, 'deleteSetting'])->name('macro.settings.delete');
Route::post('/macro/gsheet/settings/{id}/archive', [MacroGsheetController::class, 'archive'])->name('macro.settings.archive');
Route::post('/macro/gsheet/settings/{id}/unarchive', [MacroGsheetController::class, 'unarchive'])->name('macro.settings.unarchive');
// ✅ IMPORT UI (GET)
Route::get('/macro/gsheet/import', [MacroGsheetController::class, 'showImport'])
    ->name('macro.import.view');
// ✅ START IMPORT (POST)  <-- ITO ANG KULANG MO
Route::post('/macro/gsheet/import', [MacroGsheetController::class, 'import'])
    ->name('macro.import');
// ✅ STATUS POLL (GET)
Route::get('/macro/gsheet/import/status', [MacroGsheetController::class, 'status'])
    ->name('macro.import.status');
Route::get('/macro/gsheet/index', [MacroGsheetController::class, 'index'])->name('macro.index');
Route::delete('/macro/gsheet/delete-all', [MacroGsheetController::class, 'deleteAll'])->name('macro.deleteAll');


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
    // JSON endpoint para sa client-side refresh ng history table sa bottom ng /jnt_upload.
    Route::get('/jnt_upload/history', [JntUploadController::class, 'history'])->name('jnt.upload.history');

    // /jnt_upload_v2 — multi-file uploader with precheck + history (parallel sa v1)
    Route::get   ('/jnt_upload_v2',                       [JntUploadV2Controller::class, 'index'])         ->name('jnt.upload.v2.index');
    Route::post  ('/jnt_upload_v2/precheck',              [JntUploadV2Controller::class, 'precheck'])      ->name('jnt.upload.v2.precheck');
    Route::post  ('/jnt_upload_v2/start',                 [JntUploadV2Controller::class, 'start'])         ->name('jnt.upload.v2.start');
    Route::get   ('/jnt_upload_v2/status/{runId}',        [JntUploadV2Controller::class, 'status'])        ->whereNumber('runId')->name('jnt.upload.v2.status');
    Route::get   ('/jnt_upload_v2/history',               [JntUploadV2Controller::class, 'history'])       ->name('jnt.upload.v2.history');
    Route::get   ('/jnt_upload_v2/history/{runId}',       [JntUploadV2Controller::class, 'historyDetail']) ->whereNumber('runId')->name('jnt.upload.v2.history.detail');
    Route::get   ('/jnt_upload_v2/data',                  [JntUploadV2Controller::class, 'dataIndex'])     ->name('jnt.upload.v2.data');
    Route::get   ('/jnt_upload_v2/data/query',            [JntUploadV2Controller::class, 'dataQuery'])     ->name('jnt.upload.v2.data.query');
    Route::get   ('/jnt_upload_v2/coverage',              [JntUploadV2Controller::class, 'coverageIndex']) ->name('jnt.upload.v2.coverage');
    Route::get   ('/jnt_upload_v2/queue-status',          [JntUploadV2Controller::class, 'queueStatus'])   ->name('jnt.upload.v2.queue.status');
    Route::post  ('/jnt_upload_v2/cancel/{runId}',        [JntUploadV2Controller::class, 'cancelRun'])     ->whereNumber('runId')->name('jnt.upload.v2.cancel');
    Route::post  ('/jnt_upload_v2/clear-queue',           [JntUploadV2Controller::class, 'clearQueue'])    ->name('jnt.upload.v2.clear.queue');

    // /system-monitor — CEO-only system + DB + storage health dashboard
    Route::get   ('/system-monitor',         [SystemMonitorController::class, 'index']) ->name('system.monitor');
    Route::get   ('/system-monitor/data',    [SystemMonitorController::class, 'data'])  ->name('system.monitor.data');

    // Consolidate/merge controls per-run (manual button + pause/resume/cancel)
    Route::post  ('/jnt_upload_v2/run/{runId}/start-merge',    [JntUploadV2Controller::class, 'startMerge'])         ->whereNumber('runId')->name('jnt.upload.v2.merge.start');
    Route::post  ('/jnt_upload_v2/run/{runId}/pause',          [JntUploadV2Controller::class, 'pauseRun'])           ->whereNumber('runId')->name('jnt.upload.v2.merge.pause');
    Route::post  ('/jnt_upload_v2/run/{runId}/resume',         [JntUploadV2Controller::class, 'resumeRun'])          ->whereNumber('runId')->name('jnt.upload.v2.merge.resume');
    Route::post  ('/jnt_upload_v2/run/{runId}/request-cancel', [JntUploadV2Controller::class, 'requestCancelMerge']) ->whereNumber('runId')->name('jnt.upload.v2.merge.cancel');

    // /jnt_upload_v2/pipeline — manual per-phase control with 3-table dashboard.
    // Run-less (processes whole table) — useful para sa debugging / fallback recovery.
    Route::get   ('/jnt_upload_v2/pipeline',                    [\App\Http\Controllers\JntV2PipelineController::class, 'index'])      ->name('jnt.upload.v2.pipeline');
    Route::get   ('/jnt_upload_v2/pipeline/data/staging',       [\App\Http\Controllers\JntV2PipelineController::class, 'dataStaging']);
    Route::get   ('/jnt_upload_v2/pipeline/data/winners',       [\App\Http\Controllers\JntV2PipelineController::class, 'dataWinners']);
    Route::get   ('/jnt_upload_v2/pipeline/data/final',         [\App\Http\Controllers\JntV2PipelineController::class, 'dataFinal']);
    Route::post  ('/jnt_upload_v2/pipeline/run-phase1',         [\App\Http\Controllers\JntV2PipelineController::class, 'runPhase1']);
    Route::post  ('/jnt_upload_v2/pipeline/run-phase2',         [\App\Http\Controllers\JntV2PipelineController::class, 'runPhase2']);
    Route::post  ('/jnt_upload_v2/pipeline/clear/{table}',      [\App\Http\Controllers\JntV2PipelineController::class, 'clearTable']);
    Route::get   ('/jnt_upload_v2/pipeline/progress',           [\App\Http\Controllers\JntV2PipelineController::class, 'progress']);

    // /queue-manager — system-wide queue dashboard (CEO + MOIC)
    Route::get   ('/queue-manager',                       [\App\Http\Controllers\QueueManagerController::class, 'index'])           ->name('queue.manager.index');
    Route::get   ('/queue-manager/data',                  [\App\Http\Controllers\QueueManagerController::class, 'data'])            ->name('queue.manager.data');
    Route::post  ('/queue-manager/restart-workers',       [\App\Http\Controllers\QueueManagerController::class, 'restartWorkers']) ->name('queue.manager.restart');
    Route::post  ('/queue-manager/clear-pending',         [\App\Http\Controllers\QueueManagerController::class, 'clearPending'])    ->name('queue.manager.clear.pending');
    Route::post  ('/queue-manager/clear-failed',          [\App\Http\Controllers\QueueManagerController::class, 'clearFailed'])     ->name('queue.manager.clear.failed');
    Route::post  ('/queue-manager/nuclear-reset',         [\App\Http\Controllers\QueueManagerController::class, 'nuclearReset'])    ->name('queue.manager.nuclear');
    Route::view('/jnt_update', 'jnt_update');
    Route::post('/jnt_update', [FromJntController::class, 'updateOrInsert']);
    Route::get('/jnt_rts', [FromJntController::class, 'rtsView']);
    Route::get('/jnt/hold', [JntHoldController::class, 'index'])->name('jnt.hold');
    Route::get('/jnt/hold/download', [JntHoldDownloadController::class, 'index'])->name('jnt.hold.download');
    Route::get('/jnt/hold/export', [JntHoldDownloadController::class, 'export'])->name('jnt.hold.export');
    // Orders-per-item (per date) — LAHAT ng orders mula macro_output, base item name,
    // order count (di units). Hiwalay sa JNT (galing sa macro_output).
    Route::get('/macro/orders', [\App\Http\Controllers\MacroOrdersController::class, 'index'])->name('macro.orders');

    // Daily HOLD snapshots (units per item) — for /owner/private history + manual test.
    Route::get ('/jnt/hold-snapshots',     [\App\Http\Controllers\ItemHoldSnapshotController::class, 'index'])->name('jnt.hold-snapshots');
    Route::post('/jnt/hold-snapshots/run', [\App\Http\Controllers\ItemHoldSnapshotController::class, 'runNow'])->name('jnt.hold-snapshots.run');
    Route::get ('/jnt/hold-snapshots/schedule', [\App\Http\Controllers\ItemHoldSnapshotController::class, 'scheduleEdit'])->name('jnt.hold-snapshots.schedule');
    Route::post('/jnt/hold-snapshots/schedule', [\App\Http\Controllers\ItemHoldSnapshotController::class, 'scheduleUpdate'])->name('jnt.hold-snapshots.schedule.update');

    // ── Supply Finance (CEO + Marketing-OIC) — suppliers, orders (PO) na may
    //    lifecycle ordered→delivered→counted(=stock-in), partial payments + resibo.
    Route::get   ('/finance/supply',                       [\App\Http\Controllers\SupplyFinanceController::class, 'index'])        ->name('finance.supply.index');
    Route::get   ('/finance/supply/{supplier}',            [\App\Http\Controllers\SupplyFinanceController::class, 'show'])         ->whereNumber('supplier')->name('finance.supply.show');
    // suppliers
    Route::post  ('/finance/supply/suppliers',             [\App\Http\Controllers\SupplyFinanceController::class, 'storeSupplier'])->name('finance.supply.suppliers.store');
    Route::put   ('/finance/supply/suppliers/{supplier}',  [\App\Http\Controllers\SupplyFinanceController::class, 'updateSupplier'])->whereNumber('supplier')->name('finance.supply.suppliers.update');
    // orders (PO)
    Route::post  ('/finance/supply/orders',                [\App\Http\Controllers\SupplyFinanceController::class, 'storeOrder'])   ->name('finance.supply.orders.store');
    Route::put   ('/finance/supply/orders/{order}',        [\App\Http\Controllers\SupplyFinanceController::class, 'updateOrder'])  ->whereNumber('order')->name('finance.supply.orders.update');
    Route::post  ('/finance/supply/orders/{order}/deliver',[\App\Http\Controllers\SupplyFinanceController::class, 'markDelivered'])->whereNumber('order')->name('finance.supply.orders.deliver');
    Route::post  ('/finance/supply/orders/{order}/count',  [\App\Http\Controllers\SupplyFinanceController::class, 'saveCount'])     ->whereNumber('order')->name('finance.supply.orders.count');
    Route::delete('/finance/supply/orders/{order}',        [\App\Http\Controllers\SupplyFinanceController::class, 'deleteOrder'])   ->whereNumber('order')->name('finance.supply.orders.delete');
    // ── Playbook (Problem & Solution) — CEO + Marketing-OIC + Marketing ──
    Route::get   ('/playbook',                   [\App\Http\Controllers\PlaybookController::class, 'index'])  ->name('playbook.index');
    Route::get   ('/playbook/create',            [\App\Http\Controllers\PlaybookController::class, 'create']) ->name('playbook.create');
    Route::post  ('/playbook',                   [\App\Http\Controllers\PlaybookController::class, 'store'])  ->name('playbook.store');
    Route::get   ('/playbook/{problem}',         [\App\Http\Controllers\PlaybookController::class, 'show'])   ->whereNumber('problem')->name('playbook.show');
    Route::get   ('/playbook/{problem}/edit',    [\App\Http\Controllers\PlaybookController::class, 'edit'])   ->whereNumber('problem')->name('playbook.edit');
    Route::put   ('/playbook/{problem}',         [\App\Http\Controllers\PlaybookController::class, 'update']) ->whereNumber('problem')->name('playbook.update');
    Route::delete('/playbook/{problem}',         [\App\Http\Controllers\PlaybookController::class, 'destroy'])->whereNumber('problem')->name('playbook.destroy');
    Route::post  ('/playbook/{problem}/checklist',  [\App\Http\Controllers\PlaybookController::class, 'updateChecklist'])->whereNumber('problem')->name('playbook.checklist');
    Route::post  ('/playbook/{problem}/recurrence', [\App\Http\Controllers\PlaybookController::class, 'addRecurrence'])  ->whereNumber('problem')->name('playbook.recurrence');

    // payments
    Route::post  ('/finance/supply/payments',              [\App\Http\Controllers\SupplyFinanceController::class, 'storePayment'])  ->name('finance.supply.payments.store');
    Route::put   ('/finance/supply/payments/{payment}',     [\App\Http\Controllers\SupplyFinanceController::class, 'updatePayment']) ->whereNumber('payment')->name('finance.supply.payments.update');
    Route::delete('/finance/supply/payments/{payment}',    [\App\Http\Controllers\SupplyFinanceController::class, 'deletePayment']) ->whereNumber('payment')->name('finance.supply.payments.delete');
    Route::get('/jnt/supply', [\App\Http\Controllers\JntSupplyController::class, 'index'])->name('jnt.supply');
    Route::get('/jnt/supply/config', [\App\Http\Controllers\JntSupplyController::class, 'config'])->name('jnt.supply.config');
    Route::post('/jnt/supply/settings', [\App\Http\Controllers\JntSupplyController::class, 'saveSettings'])->name('jnt.supply.settings');
    Route::post('/jnt/supply/class-thresholds', [\App\Http\Controllers\JntSupplyController::class, 'saveClassThresholds'])->name('jnt.supply.class-thresholds');
    Route::post('/jnt/supply/setting-kv', [\App\Http\Controllers\JntSupplyController::class, 'saveSupplySetting'])->name('jnt.supply.setting-kv');
    Route::post('/jnt/supply/class-rules',         [\App\Http\Controllers\JntSupplyController::class, 'createClassRule'])->name('jnt.supply.rules.create');
    Route::put ('/jnt/supply/class-rules/{id}',    [\App\Http\Controllers\JntSupplyController::class, 'updateClassRule'])->name('jnt.supply.rules.update');
    Route::delete('/jnt/supply/class-rules/{id}',  [\App\Http\Controllers\JntSupplyController::class, 'deleteClassRule'])->name('jnt.supply.rules.delete');
    Route::post('/jnt/supply/class-rules/reorder', [\App\Http\Controllers\JntSupplyController::class, 'reorderClassRules'])->name('jnt.supply.rules.reorder');

    // Excluded multi-item pages (CEO only) — affects Proj Profit% only
    Route::get   ('/jnt/supply/excluded-pages',      [\App\Http\Controllers\JntSupplyController::class, 'excludedPagesIndex' ])->name('jnt.supply.excluded.index');
    Route::post  ('/jnt/supply/excluded-pages',      [\App\Http\Controllers\JntSupplyController::class, 'storeExcludedPage'  ])->name('jnt.supply.excluded.store');
    Route::delete('/jnt/supply/excluded-pages/{id}', [\App\Http\Controllers\JntSupplyController::class, 'destroyExcludedPage'])->name('jnt.supply.excluded.destroy');

    // Global DataTables state (column order / sort / filters) — shared across
    // all accounts & devices via app_settings KV.
    Route::get   ('/jnt/supply/table-state', [\App\Http\Controllers\JntSupplyController::class, 'getTableState'  ])->name('jnt.supply.table-state.get');
    Route::post  ('/jnt/supply/table-state', [\App\Http\Controllers\JntSupplyController::class, 'saveTableState' ])->name('jnt.supply.table-state.save');
    Route::delete('/jnt/supply/table-state', [\App\Http\Controllers\JntSupplyController::class, 'resetTableState'])->name('jnt.supply.table-state.reset');

    // Days-Last-Order color rules (CEO only) — JSON list of {op,value,color,label,bold}.
    Route::post  ('/jnt/supply/dlo-color-rules', [\App\Http\Controllers\JntSupplyController::class, 'saveDloColorRules'])->name('jnt.supply.dlo-color-rules.save');

    // ── macro_output filtered export (CEO only) ──────────────────────────
    Route::get ('/macro/export',           [\App\Http\Controllers\MacroExportController::class, 'index'])   ->name('macro.export');
    Route::post('/macro/export/count',     [\App\Http\Controllers\MacroExportController::class, 'count'])   ->name('macro.export.count');
    Route::post('/macro/export/download',  [\App\Http\Controllers\MacroExportController::class, 'download'])->name('macro.export.download');

    // ── Column settings (CEO only) — manages visibility/order para sa
    //    /owner/private at /ads_manager/campaigns tables. One page, two
    //    sections; saved globally to app_settings KV.
    // Standalone Profit Calculator (CEO only) — quick what-if tool, no DB.
    Route::get('/profit-calculator', function () {
        $roleRaw  = \Illuminate\Support\Facades\Auth::user()?->employeeProfile?->role ?? '';
        $roleNorm = preg_replace('/\s+/u', ' ', trim((string) $roleRaw));
        $isCEO    = preg_match('/^ceo$/iu', $roleNorm) === 1;
        if (!$isCEO) abort(404);
        return view('profit_calculator');
    })->name('profit-calculator');

    Route::get ('/owner/nav-settings',  [\App\Http\Controllers\NavSettingsController::class, 'index'])->name('owner.nav-settings');
    Route::post('/owner/nav-settings',  [\App\Http\Controllers\NavSettingsController::class, 'save'])->name('owner.nav-settings.save');
    Route::post('/owner/nav-settings/reset', [\App\Http\Controllers\NavSettingsController::class, 'reset'])->name('owner.nav-settings.reset');
    Route::post('/owner/nav-settings/add-route', [\App\Http\Controllers\NavSettingsController::class, 'addRoute'])->name('owner.nav-settings.add-route');
    Route::post('/nav/view-as',         [\App\Http\Controllers\NavSettingsController::class, 'setViewAs'])->name('nav.view-as');
    Route::get ('/owner/column-settings',                [\App\Http\Controllers\OwnerColumnSettingsController::class, 'index'])           ->name('owner.column-settings');
    Route::get ('/owner/column-settings/owner-private',  [\App\Http\Controllers\OwnerColumnSettingsController::class, 'sectionOwnerPrivate'])->name('owner.column-settings.owner-private');
    Route::get ('/owner/column-settings/campaigns',      [\App\Http\Controllers\OwnerColumnSettingsController::class, 'sectionCampaigns'])    ->name('owner.column-settings.campaigns');
    Route::get ('/owner/column-settings/daily-summary',  [\App\Http\Controllers\OwnerColumnSettingsController::class, 'sectionDailySummary'])->name('owner.column-settings.daily-summary');
    Route::get ('/owner/column-settings/breakdown',      [\App\Http\Controllers\OwnerColumnSettingsController::class, 'sectionBreakdown'])  ->name('owner.column-settings.breakdown');
    Route::post('/owner/column-settings/save',           [\App\Http\Controllers\OwnerColumnSettingsController::class, 'save' ])           ->name('owner.column-settings.save');
    Route::post('/owner/column-settings/breakeven-pct',  [\App\Http\Controllers\OwnerColumnSettingsController::class, 'saveBreakevenPct'])->name('owner.column-settings.breakeven-pct');
    Route::post('/owner/column-settings/col-format',     [\App\Http\Controllers\OwnerColumnSettingsController::class, 'saveColFormat'])   ->name('owner.column-settings.col-format');
    Route::post('/owner/column-settings/{table}/save-as-default',  [\App\Http\Controllers\OwnerColumnSettingsController::class, 'saveAsDefault'])  ->name('owner.column-settings.save-as-default');
    Route::post('/owner/column-settings/{table}/reset-to-default', [\App\Http\Controllers\OwnerColumnSettingsController::class, 'resetToDefault']) ->name('owner.column-settings.reset-to-default');


    // ✅ Validation Lists (CEO, Marketing, Marketing OIC)
    Route::get('/validation-lists', [ValidationListController::class, 'index'])->name('validation-lists.index');

    // Phone Whitelist API
    Route::get('/validation-lists/phone/data', [ValidationListController::class, 'phoneData'])->name('validation-lists.phone.data');
    Route::post('/validation-lists/phone', [ValidationListController::class, 'phoneStore'])->name('validation-lists.phone.store');
    Route::delete('/validation-lists/phone/{phone}', [ValidationListController::class, 'phoneDestroy'])->name('validation-lists.phone.destroy');

    // FB Name Blacklist API
    Route::get('/validation-lists/fbname/data', [ValidationListController::class, 'fbnameData'])->name('validation-lists.fbname.data');
    Route::post('/validation-lists/fbname', [ValidationListController::class, 'fbnameStore'])->name('validation-lists.fbname.store');
    Route::delete('/validation-lists/fbname/{fbname}', [ValidationListController::class, 'fbnameDestroy'])->name('validation-lists.fbname.destroy');

    // Keyword Blacklist API
    Route::get('/validation-lists/keyword/data', [ValidationListController::class, 'keywordData'])->name('validation-lists.keyword.data');
    Route::post('/validation-lists/keyword', [ValidationListController::class, 'keywordStore'])->name('validation-lists.keyword.store');
    Route::delete('/validation-lists/keyword/{keyword}', [ValidationListController::class, 'keywordDestroy'])->name('validation-lists.keyword.destroy');

    // Redirect old phone-whitelist URL
    Route::get('/phone-whitelist', fn() => redirect()->route('validation-lists.index'));

    // ✅ Finance (CEO only)
    Route::get('/finance', [FinanceController::class, 'index'])->name('finance.index');
    Route::get('/finance/dashboard', [FinanceController::class, 'dashboardData'])->name('finance.dashboard');
    Route::get('/finance/transactions', [FinanceController::class, 'transactionsData'])->name('finance.transactions');
    Route::post('/finance/transactions', [FinanceController::class, 'storeTransaction'])->name('finance.transactions.store');
    Route::put('/finance/transactions/{transaction}', [FinanceController::class, 'updateTransaction'])->name('finance.transactions.update');
    Route::delete('/finance/transactions/{transaction}', [FinanceController::class, 'destroyTransaction'])->name('finance.transactions.destroy');
    Route::get('/finance/categories', [FinanceController::class, 'categoriesData'])->name('finance.categories');
    Route::post('/finance/categories', [FinanceController::class, 'storeCategory'])->name('finance.categories.store');
    Route::delete('/finance/categories/{category}', [FinanceController::class, 'destroyCategory'])->name('finance.categories.destroy');

    // ✅ Misc
    Route::view('/rts', 'rts');
    Route::get('/phpinfo', fn () => phpinfo());
});
