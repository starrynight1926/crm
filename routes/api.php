<?php

use App\Http\Controllers\Api\BookingEventController;
use App\Http\Controllers\Api\UpsAttendanceController;
use App\Http\Middleware\AuthByApiToken;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthByApiToken::class)->group(function () {
    Route::post('/leads/{code}/booking-event', BookingEventController::class);
    Route::post('/ups/busy',     [UpsAttendanceController::class, 'busy']);
    Route::post('/ups/complete', [UpsAttendanceController::class, 'complete']);
});
