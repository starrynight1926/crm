<?php

namespace App\Jobs;

use App\Models\BookingLog;
use App\Services\SbookingClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 2026-08-28: Push booking sang sbooking bất đồng bộ để tránh Apache Windows deadlock
 * (SCRM Livewire request giữ worker + Http::post sbooking cùng Apache → treo).
 * Chạy queue worker: `php artisan queue:work --tries=3`
 */
class PushBookingLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(public int $bookingLogId) {}

    public function handle(SbookingClient $sb): void
    {
        $log = BookingLog::find($this->bookingLogId);
        if (! $log || $log->sbooking_booking_id) return;  // đã sync — skip
        $sb->pushBooking($log);
    }
}
