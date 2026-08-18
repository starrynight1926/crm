<?php

use App\Http\Controllers\Api\BookingEventController;
use App\Http\Controllers\Api\UpsAttendanceController;
use App\Http\Middleware\AuthByApiToken;
use Illuminate\Support\Facades\Route;

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
