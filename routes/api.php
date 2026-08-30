<?php

use App\Http\Controllers\Api\BookingEventController;
use App\Http\Controllers\Api\UpsAttendanceController;
use App\Http\Middleware\AuthByApiToken;
use Illuminate\Support\Facades\Route;

// ═══════════════════════════════════════════════════════════════════
// API v1 — CRUD chuẩn (bearer token qua AuthByApiToken, throttle 60/min/token).
// Phase A (2026-08-30): users + facilities.
// ═══════════════════════════════════════════════════════════════════
Route::prefix('v1')->middleware([AuthByApiToken::class, 'throttle:api-v1'])->group(function () {
    Route::apiResource('users', \App\Http\Controllers\Api\V1\UserController::class);
    Route::apiResource('facilities', \App\Http\Controllers\Api\V1\FacilityController::class);

    // Phase B: org units + user assignments.
    Route::get('org-units/tree', [\App\Http\Controllers\Api\V1\OrgUnitController::class, 'tree']);
    Route::apiResource('org-units', \App\Http\Controllers\Api\V1\OrgUnitController::class)
        ->parameters(['org-units' => 'org_unit']);
    Route::post('org-units/{org_unit}/move', [\App\Http\Controllers\Api\V1\OrgUnitController::class, 'move']);

    Route::get   ('users/{user}/assignments',                  [\App\Http\Controllers\Api\V1\AssignmentController::class, 'index']);
    Route::post  ('users/{user}/assignments',                  [\App\Http\Controllers\Api\V1\AssignmentController::class, 'store']);
    Route::patch ('users/{user}/assignments/{assignment}',     [\App\Http\Controllers\Api\V1\AssignmentController::class, 'update']);
    Route::delete('users/{user}/assignments/{assignment}',     [\App\Http\Controllers\Api\V1\AssignmentController::class, 'destroy']);
});

Route::middleware(AuthByApiToken::class)->group(function () {
    Route::post('/leads/{code}/booking-event', BookingEventController::class);
    // 2026-08-18: Sale tiếp đón bấm "Đã xong" bên sbooking → close phase 5 + sync classification.
    Route::post('/booking-event/checkin-done', [BookingEventController::class, 'checkinDone']);
    // 2026-08-18: sbooking mở modal duyệt → lấy danh sách sale check-in UPS hôm nay.
    Route::get('/ups/sales-today', [UpsAttendanceController::class, 'salesToday']);
    Route::post('/ups/busy',     [UpsAttendanceController::class, 'busy']);
    Route::post('/ups/complete', [UpsAttendanceController::class, 'complete']);
    Route::post('/ups/pause',    [UpsAttendanceController::class, 'pause']);
    Route::post('/ups/resume',   [UpsAttendanceController::class, 'resume']);
});
