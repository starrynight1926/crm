<?php

use App\Http\Controllers\Api\BookingEventController;
use App\Http\Middleware\AuthByApiToken;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthByApiToken::class)
    ->post('/leads/{code}/booking-event', BookingEventController::class);
