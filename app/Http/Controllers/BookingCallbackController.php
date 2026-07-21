<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingCallbackController extends Controller
{
    /**
     * GET /leads/{lead}/booking-callback?booking_ma=...&booking_id=...
     * Nhận redirect từ lara-sbooking sau khi đặt lịch thành công.
     * Cập nhật lead: booking_status='booked', lưu booking_ma + booked_at.
     */
    public function __invoke(Lead $lead, Request $request)
    {
        abort_unless($lead->isVisibleTo(auth()->user()), 403);

        $data = $request->validate([
            'booking_ma' => ['required', 'string', 'max:40'],
            'booking_id' => ['nullable', 'integer'],
        ]);

        DB::transaction(function () use ($lead, $data) {
            $before = $lead->booking_status;
            $lead->fill([
                'booking_status' => Lead::BOOKING_BOOKED,
                'booking_ma'     => $data['booking_ma'],
                'booked_at'      => now(),
            ])->save();

            AuditLog::record('booking_created', $lead, [
                'booking_ma'     => $data['booking_ma'],
                'booking_id'     => $data['booking_id'] ?? null,
                'booking_status_before' => $before,
            ]);
        });

        return redirect()->route('leads.show', $lead)
            ->with('status', 'Đã đặt booking ' . $data['booking_ma'] . ' cho khách ' . $lead->name . '.');
    }
}
