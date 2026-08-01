<?php

namespace App\Services;

use App\Models\BookingLog;
use App\Models\Facility;
use App\Models\SbService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Phase C1.b (2026-08-01) — push booking từ scrm sang sbooking.
 *
 * Chỉ push khi:
 *  - Có BOOKING_API_TOKEN.
 *  - BookingLog::facility có `sbooking_co_so_id` (map tay tạm).
 *  - BookingLog::status === da_xac_nhan.
 *
 * Không rollback khi fail — log error vào booking_logs.sync_error, giữ log local.
 */
class SbookingClient
{
    /**
     * Push 1 booking_log sang sbooking. Ghi kết quả vào chính bản ghi.
     * Trả về true nếu OK, false nếu fail (đã ghi sync_error).
     */
    public function pushBooking(BookingLog $log): bool
    {
        $token = config('services.booking.api_token');
        $baseUrl = rtrim(config('services.booking.api_url') ?: '', '/');

        if (! $token || ! $baseUrl) {
            $this->markFailed($log, 'Chưa cấu hình BOOKING_API_URL hoặc BOOKING_API_TOKEN.');
            return false;
        }

        $lead = $log->lead;
        if (! $lead) {
            $this->markFailed($log, 'Booking log không gắn lead.');
            return false;
        }

        $facility = $log->facility_id ? Facility::find($log->facility_id) : null;
        if (! $facility) {
            $this->markFailed($log, 'Booking log không gắn cơ sở.');
            return false;
        }
        // Walk parent chain — node con (VD "Khối chuyên môn") không map, dùng của root.
        $sbookingCoSoId = null;
        $cur = $facility;
        while ($cur) {
            if ($cur->sbooking_co_so_id) { $sbookingCoSoId = (int) $cur->sbooking_co_so_id; break; }
            $cur = $cur->parent_id ? Facility::find($cur->parent_id) : null;
        }
        if (! $sbookingCoSoId) {
            $this->markFailed($log, 'Cơ sở "' . $facility->name . '" (hoặc cha) chưa được map sbooking_co_so_id (vào Thiết lập → Kết nối Booking để map).');
            return false;
        }

        // service_id hiện đang trỏ scrm.services. Map sang sb_services theo tên (best-effort).
        $sbookingDichVuId = null;
        if ($log->service_id) {
            $svc = $log->service; // scrm.services
            if ($svc) {
                $match = SbService::where('ten', $svc->name)->where('active', true)->first();
                $sbookingDichVuId = $match?->sbooking_id;
            }
        }

        $payload = [
            'so_dien_thoai' => $lead->phone,
            'ho_ten'        => $lead->name ?: 'Khách CRM',
            'co_so_id'      => $sbookingCoSoId,
            'ngay_dat'      => $log->scheduled_at?->format('Y-m-d') ?? now()->format('Y-m-d'),
            'gio_thuc_hien' => $log->scheduled_at?->format('H:i:s'),
            'dich_vu_id'    => $sbookingDichVuId,
            // Map scrm booking_logs.type (tham_kham/dich_vu) → sbooking enum (phong_kham/dich_vu).
            'loai_dat_lich' => $log->type === 'dich_vu' ? 'dich_vu' : 'phong_kham',
            // Phase C1.b rev 2026-08-01: nguon = source_group scrm (mkt/mkt_br/bdm/bod/sa/ba/wi), fallback 'SCRM'.
            'nguon'         => $lead->source_group ?: 'SCRM',
            'crm_khach_ma'  => $lead->code,
            'ghi_chu'       => $log->note,
            // Phase C1.b rev9 2026-08-02: 4 field bổ sung.
            'so_lieu_trinh'   => $log->so_lieu_trinh,
            'so_luong_lo'     => $log->so_luong_lo,
            'dung_tich_lo'    => $log->dung_tich_lo,
            'ket_hop_medical' => (bool) $log->ket_hop_medical,
        ];

        try {
            $response = Http::withToken($token)->timeout(15)->acceptJson()
                ->post($baseUrl . '/bookings', $payload);
        } catch (Throwable $e) {
            $this->markFailed($log, 'HTTP fail: ' . $e->getMessage());
            return false;
        }

        if (! $response->successful()) {
            $this->markFailed($log, 'HTTP ' . $response->status() . ': ' . substr($response->body(), 0, 500));
            return false;
        }

        $body = $response->json();
        if (! isset($body['id'])) {
            $this->markFailed($log, 'Response thiếu id: ' . substr($response->body(), 0, 300));
            return false;
        }

        $log->update([
            'sbooking_booking_id' => (int) $body['id'],
            'sbooking_booking_ma' => $body['ma_booking'] ?? null,
            'sync_status' => 'synced',
            'sync_error' => null,
            'synced_at' => now(),
        ]);

        return true;
    }

    private function markFailed(BookingLog $log, string $reason): void
    {
        $log->update([
            'sync_status' => 'failed',
            'sync_error' => $reason,
            'synced_at' => now(),
        ]);
    }
}
