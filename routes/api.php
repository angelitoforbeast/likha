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

// Mga event ng Forbeast Gateway (pinirmahan ng webhook secret ng page, o ng GATEWAY_WEBHOOK_SECRET; walang session o CSRF).
Route::post('/gateway/events', [\App\Http\Controllers\GatewayEventController::class, 'receive']);

// Read-only feed ng kusang lipat ng provider ng chatbot, para sa assistant ng owner (header X-CHATBOT-EVENTS-KEY, sariling key).
Route::get('/chatbot/provider-events', [\App\Http\Controllers\ChatbotProviderEventsController::class, 'index'])->middleware('throttle:30,1,chatbot-provider-events');
Route::get('/chatbot/health', [\App\Http\Controllers\ChatbotHealthController::class, 'json'])->middleware('throttle:30,1,chatbot-health');

// Read-only staff feed para sa assistant ng owner (header X-STAFF-FEED-KEY, sariling key).
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/staff-status', [StaffFeedController::class, 'status']);
    Route::get('/staff-events', [StaffFeedController::class, 'events']);
});

// Read-only feed ng Set RTS% laban sa Projected RTS% ng bawat page+item, para sa assistant ng owner (header X-RTS-CHECK-KEY, sariling key).
Route::get('/owner/rts-check', [\App\Http\Controllers\OwnerRtsCheckController::class, 'index'])->middleware('throttle:30,1,owner-rts-check');

// Sulatan ng analyst ng Mira RTS% ng bawat page+item, may dahilan (header X-ANALYST-RTS-KEY, sariling key).
Route::post('/owner/analyst-rts', [\App\Http\Controllers\AnalystRtsController::class, 'store'])->middleware('throttle:30,1,owner-analyst-rts');

// Default example route (optional)
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
