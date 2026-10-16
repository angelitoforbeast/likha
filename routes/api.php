<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AutomationController;
use App\Http\Controllers\StaffFeedController;

Route::post('/automation/macro-import', [AutomationController::class, 'macroImport']);

// Read-only staff feed para sa assistant ng owner (header X-STAFF-FEED-KEY, sariling key).
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/staff-status', [StaffFeedController::class, 'status']);
    Route::get('/staff-events', [StaffFeedController::class, 'events']);
});

// Default example route (optional)
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
