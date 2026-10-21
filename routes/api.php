<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AutomationController;
use App\Http\Controllers\StaffFeedController;
use App\Http\Controllers\BotcakeChatController;

Route::post('/automation/macro-import', [AutomationController::class, 'macroImport']);

// Mga mensahe ng customer mula sa JSON API block ng Botcake (shared key, walang session o CSRF). GET din, kung
// naiwang GET ang block.
Route::match(['get', 'post'], '/botcake/chat', [BotcakeChatController::class, 'receive'])->middleware('throttle:botcake-chat');

// Mga event ng Forbeast Gateway (pinirmahan ng GATEWAY_WEBHOOK_SECRET, walang session o CSRF).
Route::post('/gateway/events', [\App\Http\Controllers\GatewayEventController::class, 'receive']);

// Read-only staff feed para sa assistant ng owner (header X-STAFF-FEED-KEY, sariling key).
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/staff-status', [StaffFeedController::class, 'status']);
    Route::get('/staff-events', [StaffFeedController::class, 'events']);
});

// Default example route (optional)
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
