<?php

namespace App\Services;

use App\Models\BookingLog;
use App\Models\LeadStatusLog;
use App\Models\SbService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 2026-10-09 — Sale "Hẹn lại" booking đã đặt (CHO_XAC_NHAN hoặc DA_XAC_NHAN):
 *   1. Booking cũ: status → HUY_DOI_LICH (sync_status=canceled, push sbooking hủy slot).
 *   2. Booking mới clone y hệt (replicate) + đổi scheduled_at/_end_at + status=CHO_XAC_NHAN.
 *   3. Consultants pivot clone.
 *   4. Push sang sbooking → tạo booking mới cho_duyet.
 *   5. Lead.booking_status → rescheduled (qua syncLeadBookingStatus hook).
 */
class BookingRescheduler
{
    public function __construct(private SbookingClient $sb) {}

    /**
     * @return array{ok:bool,reason?:string,new_id?:int}
     */
    public function reschedule(BookingLog $old, Carbon $newAt, string $reason, User $actor): array
    {
        if (! in_array($old->status, [BookingLog::STATUS_CHO_XAC_NHAN, BookingLog::STATUS_DA_XAC_NHAN], true)) {
            return ['ok' => false, 'reason' => 'Chỉ hẹn lại được booking "Chờ xác nhận" / "Đã xác nhận".'];
        }
        if ($newAt->isPast()) {
            return ['ok' => false, 'reason' => 'Giờ hẹn mới phải sau hiện tại.'];
        }

        // Compute scheduled_end_at theo thoi_gian_phut của service (fallback 30 phút).
        $phut = 0;
        if ($old->service) {
            $phut = (int) SbService::where('ten', $old->service->name)->value('thoi_gian_phut');
        }
        $newEndAt = $newAt->copy()->addMinutes($phut > 0 ? $phut : 30);

        try {
            return DB::transaction(function () use ($old, $newAt, $newEndAt, $reason, $actor) {
                // 1) Mark old as rescheduled + flag sync cancel.
                $old->update([
                    'status'      => BookingLog::STATUS_HUY_DOI_LICH,
                    'sync_status' => 'canceled',
                    'sync_error'  => 'Sale hẹn lại: ' . $reason,
                ]);
                // Push cancel sang sbooking (silent fail — nếu push lỗi vẫn giữ state local).
                try { $this->sb->pushBookingUpdate($old); } catch (Throwable) {}

                // 2) Clone new BookingLog, reset sync + ngày giờ mới.
                $new = $old->replicate();
                $new->status = BookingLog::STATUS_CHO_XAC_NHAN;
                $new->scheduled_at = $newAt;
                $new->scheduled_end_at = $newEndAt;
                $new->user_id = $actor->id;
                $new->sync_status = null;
                $new->sync_error = null;
                $new->synced_at = null;
                $new->sbooking_booking_id = null;
                $new->sbooking_booking_ma = null;
                $new->note = ($new->note ? $new->note . "\n" : '')
                    . "[Hẹn lại từ booking #{$old->id}] " . $reason;
                $new->save();

                // 3) Clone consultants pivot (giữ position).
                $cvSync = [];
                foreach ($old->consultants as $i => $cv) {
                    $cvSync[$cv->id] = ['position' => $cv->pivot->position ?? ($i + 1)];
                }
                if ($cvSync) $new->consultants()->sync($cvSync);

                // 4) Push booking mới sang sbooking (silent fail — admin sẽ thấy sync_status=pending).
                try { $this->sb->pushBooking($new); } catch (Throwable) {}

                // 5) Audit log.
                LeadStatusLog::record(
                    $old->lead,
                    'reschedule',
                    null,
                    "Hẹn lại booking #{$old->id} → #{$new->id}. Lịch mới: {$newAt->format('d/m/Y H:i')}. Lý do: {$reason}",
                    $actor->id
                );

                // 6) Sync lead.booking_status (hook map theo booking mới nhất).
                BookingLog::syncLeadBookingStatus($old->lead_id);

                return ['ok' => true, 'new_id' => $new->id];
            });
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => 'Lỗi hệ thống khi hẹn lại: ' . $e->getMessage()];
        }
    }
}
