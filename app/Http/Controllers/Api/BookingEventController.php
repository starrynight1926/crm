<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadStatusLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Endpoint nhận push từ lara-sbooking khi:
 *   - status: nhân viên bấm 1 trong 4 nút Khách đã tới / tới trễ / hủy / đã xong.
 *   - comment: bình luận mới trong booking.
 *   - edit: đổi giờ/phòng/dịch vụ… của booking.
 *
 * Auth: Bearer token = user.api_token (chung 2 hệ).
 * Route: POST /api/leads/{code}/booking-event  (khớp cột leads.code)
 */
class BookingEventController extends Controller
{
    public function __invoke(Request $request, string $code)
    {
        $data = $request->validate([
            'type'         => ['required', 'in:status,comment,edit'],
            'booking_ma'   => ['nullable', 'string', 'max:40'],
            'trang_thai_khach' => ['nullable', 'string', 'max:20'],
            'trang_thai'   => ['nullable', 'string', 'max:20'],
            'comment'      => ['nullable', 'string', 'max:2000'],
            'summary'      => ['nullable', 'string', 'max:500'], // mô tả edit (VD "Đổi giờ 09:00 → 10:30")
        ]);

        $lead = Lead::where('code', $code)->firstOrFail();
        $bookingMa = $data['booking_ma'] ?? null;
        $actorId = auth()->id();

        DB::transaction(function () use ($lead, $data, $bookingMa, $actorId) {
            switch ($data['type']) {
                case 'status':
                    // Ưu tiên: Đã xong > Khách hủy > Tới trễ > Đã tới > Booked.
                    $newStatus = match (true) {
                        ($data['trang_thai'] ?? null) === 'da_xong'       => Lead::BOOKING_DA_XONG,
                        ($data['trang_thai_khach'] ?? null) === 'huy'     => Lead::BOOKING_KHACH_HUY,
                        ($data['trang_thai_khach'] ?? null) === 'toi_tre' => Lead::BOOKING_KHACH_TOI_TRE,
                        ($data['trang_thai_khach'] ?? null) === 'da_toi'  => Lead::BOOKING_KHACH_DA_TOI,
                        default                                            => Lead::BOOKING_BOOKED,
                    };
                    $before = $lead->booking_status;
                    $lead->update([
                        'booking_status' => $newStatus,
                        'booking_ma'     => $bookingMa ?: $lead->booking_ma,
                        'last_care_at'   => now(),
                    ]);
                    LeadStatusLog::record($lead, 'booking_status', $before, $newStatus, $actorId);
                    LeadStatusLog::record($lead, 'note', null,
                        'Booking ' . ($bookingMa ?: '?') . ' — ' . (Lead::BOOKING_STATUSES[$newStatus] ?? $newStatus),
                        $actorId);
                    AuditLog::record('booking_status_push', $lead, [
                        'booking_ma' => $bookingMa,
                        'before' => $before,
                        'after' => $newStatus,
                    ]);
                    break;

                case 'comment':
                    $note = 'Bình luận Booking ' . ($bookingMa ?: '?') . ': ' . ($data['comment'] ?? '');
                    LeadStatusLog::record($lead, 'note', null, $note, $actorId);
                    $lead->update(['last_care_at' => now()]);
                    AuditLog::record('booking_comment_push', $lead, ['booking_ma' => $bookingMa]);
                    break;

                case 'edit':
                    $note = 'Booking ' . ($bookingMa ?: '?') . ' đã đổi: ' . ($data['summary'] ?? 'không mô tả');
                    LeadStatusLog::record($lead, 'note', null, $note, $actorId);
                    $lead->update(['last_care_at' => now()]);
                    AuditLog::record('booking_edit_push', $lead, ['booking_ma' => $bookingMa, 'summary' => $data['summary'] ?? null]);
                    break;
            }
        });

        return response()->json(['ok' => true, 'lead_code' => $lead->code]);
    }
}
