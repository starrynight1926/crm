<?php

namespace App\Console\Commands;

use App\Models\BookingLog;
use App\Models\Lead;
use App\Models\LeadStatusLog;
use App\Services\SbookingClient;
use Illuminate\Console\Command;

/**
 * B5b (2026-08-14) — Auto-hủy booking khi khách trễ quá 15 phút.
 *
 * Rule: booking scheduled_at + 15 phút < now VÀ khách chưa được báo "đã tới"
 *       (sync_status != 'checkedin', 'done', 'canceled') → mark canceled.
 *
 * Ví dụ: khung 8:00-8:30, 8:16 khách chưa tới → hủy.
 *
 * Chạy every 5 phút (đủ dense cho window 15').
 */
class AutoCancelLateBookings extends Command
{
    protected $signature = 'bookings:auto-cancel-late';

    protected $description = 'Hủy booking khi khách trễ quá 15 phút mà chưa được báo tới.';

    public function handle(SbookingClient $sb): int
    {
        $deadline = now()->subMinutes(15);
        $cancelled = 0;

        BookingLog::query()
            ->where('status', BookingLog::STATUS_DA_XAC_NHAN)
            ->whereNotIn('sync_status', ['checkedin', 'done', 'canceled'])
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', $deadline)
            ->chunkById(100, function ($logs) use (&$cancelled, $sb) {
                foreach ($logs as $bl) {
                    $bl->update([
                        'status' => BookingLog::STATUS_HUY_DOI_LICH,
                        'sync_status' => 'canceled',
                        'sync_error' => 'Auto-hủy: khách trễ quá 15 phút chưa tới.',
                        'synced_at' => now(),
                    ]);

                    Lead::where('id', $bl->lead_id)->update([
                        'booking_status' => Lead::BOOKING_KHACH_HUY,
                    ]);

                    LeadStatusLog::record(
                        Lead::find($bl->lead_id),
                        'note', null,
                        'Auto-hủy booking ' . ($bl->sbooking_booking_ma ?: '#'.$bl->id) . ' — khách trễ quá 15 phút.',
                        null
                    );

                    // Push canceled status sang sbooking để đồng bộ 2 chiều.
                    if ($bl->sbooking_booking_id) {
                        try { $sb->pushBookingUpdate($bl); } catch (\Throwable $e) { /* log silently */ }
                    }

                    $cancelled++;
                }
            });

        $this->info("Auto-hủy {$cancelled} booking trễ quá 15 phút.");
        return self::SUCCESS;
    }
}
