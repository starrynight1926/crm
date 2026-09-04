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

        // 2026-08-03 fix — ưu tiên sb_dich_vu_id direct (do frontend lưu); fallback map theo tên.
        $sbookingDichVuId = $log->sb_dich_vu_id ? (int) $log->sb_dich_vu_id : null;
        if (! $sbookingDichVuId && $log->service_id) {
            $svc = $log->service;
            if ($svc) {
                $sbookingDichVuId = SbService::where('ten', $svc->name)->where('active', true)->value('sbooking_id');
            }
        }

        // Phase 6.25.C fix (2026-08-03) — resolve CV#1 để push sale_id + tiep_don_user_id.
        // CV1 = tư vấn viên chính do CM/Admin gán ở phase 3 Booking scrm.
        // Sbooking dùng sale_id (booking.sale_id) và tiep_don_user_id (nút "Đang tiếp đón").
        $sbookingSaleId = null;
        $cv1 = $log->consultants()->orderBy('booking_log_consultants.position')->first();
        if ($cv1 && $cv1->sbooking_user_id) {
            $sbookingSaleId = (int) $cv1->sbooking_user_id;
        }

        // 2026-08-18 — Tele phụ trách phase 2 = lead.owner sau khi CM chia (nhóm MKT/BDM/BOD/Walk-in).
        // Với SA/BA/MKT_BR: owner = creator, snapshot cũng OK (banner sbooking check nguon để phân biệt).
        $owner = $lead->owner_id ? \App\Models\User::find($lead->owner_id) : null;

        // 2026-08-19 — nguoi_tao_id = creator thực sự của lead (imported_by), không phải CV1.
        // Trước đây push CV1.sbooking_user_id — với self-owned (SA/BA/MKT_BR) CV1=creator nên trùng,
        // nhưng nếu creator không có sbooking_user_id map → null → banner "Người nhập lead: —" trống,
        // modal Duyệt không lock được dropdown sale tiếp đón.
        $creator = $lead->imported_by ? \App\Models\User::find($lead->imported_by) : null;
        $creatorSbookingId = $creator?->sbooking_user_id ? (int) $creator->sbooking_user_id : null;

        $payload = [
            'so_dien_thoai' => $lead->phone,
            'ho_ten'        => $lead->name ?: 'Khách CRM',
            'co_so_id'      => $sbookingCoSoId,
            'ngay_dat'      => $log->scheduled_at?->format('Y-m-d') ?? now()->format('Y-m-d'),
            'gio_thuc_hien' => $log->scheduled_at?->format('H:i:s'),
            'gio_ket_thuc'  => $log->scheduled_end_at?->format('H:i:s'),
            'dich_vu_id'    => $sbookingDichVuId,
            // Phase C1.d 2026-08-02: gửi thêm phòng + BS đã chọn ở form scrm.
            'phong_id'      => $log->sb_phong_id,
            'bac_si_id'     => $log->sb_bac_si_id,
            'ho_tro_id'     => $log->sb_ho_tro_id,
            // 2026-08-03 fix bug #2: gửi khung_gio_id để sbooking chốt slot khớp form scrm.
            'khung_gio_id'  => $log->sb_khung_gio_id,
            // 2026-08-19 Phase B: map 3 loại — kham_ls/tu_van/dich_vu.
            //   Legacy 'tham_kham' (booking_logs cũ trước Phase B) → 'kham_ls'.
            'loai_dat_lich' => match ($log->type) {
                'dich_vu' => 'dich_vu',
                'tu_van'  => 'tu_van',
                default   => 'kham_ls', // 'kham_ls' hoặc 'tham_kham' legacy
            },
            // Phase C1.b rev 2026-08-01: nguon = source_group scrm (mkt/mkt_br/bdm/bod/sa/ba/wi), fallback 'SCRM'.
            'nguon'         => $lead->source_group ?: 'SCRM',
            'crm_khach_ma'  => $lead->code,
            'ghi_chu'       => $log->note,
            // Phase C1.b rev9 2026-08-02: 4 field bổ sung.
            'so_lieu_trinh'   => $log->so_lieu_trinh,
            'so_luong_lo'     => $log->so_luong_lo,
            'dung_tich_lo'    => $log->dung_tich_lo,
            'ket_hop_medical' => (bool) $log->ket_hop_medical,
            'co_tu_van'       => (bool) $log->co_tu_van,
            'co_kham_cls'     => (bool) $log->co_kham_cls,
            // Phase 6.25.C fix — gán CV1 làm sale + tiep_don_user để bên sbooking hiện nút "Đang tiếp đón".
            'sale_id'            => $sbookingSaleId,
            'tiep_don_user_id'   => $sbookingSaleId,
            // 2026-08-18: nguoi_tao_id = sale gốc (creator) — sbooking modal Duyệt lock dropdown khi source
            // ∈ SA/BA/MKT_BR (check theo `nguon` field). Trước để trống → admin không thấy creator → không lock.
            // 2026-08-19 fix: dùng imported_by (creator thực) thay CV1 để MKT_BR/SA/BA luôn có creator id
            //   dù CV1 mapping hụt hoặc lead được reassign consultant.
            'nguoi_tao_id'       => $creatorSbookingId ?: $sbookingSaleId,
            // 2026-08-18: Tele phụ trách phase 2 SCRM — sbooking modal Duyệt hiện info "Tele: X" cho nguồn MKT/BDM/BOD/Walk-in.
            'tele_owner_id'      => $owner?->id,
            'tele_owner_name'    => $owner?->name,
        ];

        try {
            $response = Http::withToken($token)->connectTimeout(3)->timeout(30)->acceptJson()
                ->post($baseUrl . '/bookings', $payload);
        } catch (Throwable $e) {
            $this->markFailed($log, 'HTTP fail: ' . $e->getMessage());
            return false;
        }

        if (! $response->successful()) {
            // Parse JSON message nếu có (VD sbooking trả 409 room_full với {message: "..."})
            $body = $response->json();
            $reason = is_array($body) && ! empty($body['message'])
                ? $body['message']
                : 'HTTP ' . $response->status() . ': ' . substr($response->body(), 0, 500);
            $this->markFailed($log, $reason);
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

        // Phase C1.f 2026-08-02: nếu có note ban đầu → auto tạo 1 bình luận bên sbooking
        // để nội dung hiện luôn trong "Trạng thái lịch hẹn" (danh sách binh_luan).
        if (! empty($log->note)) {
            $userName = $log->user?->name ?? 'Data Source';
            $this->pushComment($log->fresh(), $log->note, $log->user_id, $userName);
        }

        return true;
    }

    /**
     * Phase C1.e (2026-08-02) — push edit sang sbooking khi user sửa booking bên scrm.
     * Chỉ chạy cho booking đã sync (có sbooking_booking_id). Silent fail nếu lỗi — sync_error ghi log.
     * Payload gồm: note, sale_id (CV#1 map qua users.sbooking_user_id), slot fields, extra fields.
     */
    public function pushBookingUpdate(BookingLog $log): bool
    {
        if (! $log->sbooking_booking_id) return false;

        $token = config('services.booking.api_token');
        $baseUrl = rtrim(config('services.booking.api_url') ?: '', '/');
        if (! $token || ! $baseUrl) {
            $this->markFailed($log, 'Chưa cấu hình BOOKING_API_URL/TOKEN.');
            return false;
        }

        // Resolve CV#1 → sbooking sale_id qua users.sbooking_user_id.
        $saleId = null;
        $cv1 = $log->consultants()->orderBy('booking_log_consultants.position')->first();
        if ($cv1 && $cv1->sbooking_user_id) {
            $saleId = (int) $cv1->sbooking_user_id;
        }

        // Resolve service.name → sb_services.sbooking_id (giống pushBooking).
        $sbookingDichVuId = null;
        if ($log->service_id && $log->service) {
            $sbookingDichVuId = SbService::where('ten', $log->service->name)->where('active', true)->value('sbooking_id');
        }

        // 2026-08-18 — sync Tele phụ trách phase 2 khi CM đổi owner (lead re-assign).
        $lead = $log->lead;
        $owner = $lead && $lead->owner_id ? \App\Models\User::find($lead->owner_id) : null;

        $payload = [
            'ghi_chu'         => $log->note,
            'sale_id'         => $saleId,
            'tele_owner_id'   => $owner?->id,
            'tele_owner_name' => $owner?->name,
            'ngay_dat'        => $log->scheduled_at?->format('Y-m-d'),
            'gio_thuc_hien'   => $log->scheduled_at?->format('H:i:s'),
            'dich_vu_id'      => $sbookingDichVuId,
            'bac_si_id'       => $log->sb_bac_si_id,
            'phong_id'        => $log->sb_phong_id,
            'khung_gio_id'    => $log->sb_khung_gio_id,
            'gio_ket_thuc'    => $log->scheduled_end_at?->format('H:i:s'),
            'so_lieu_trinh'   => $log->so_lieu_trinh,
            'so_luong_lo'     => $log->so_luong_lo,
            'dung_tich_lo'    => $log->dung_tich_lo,
            'ket_hop_medical' => (bool) $log->ket_hop_medical,
            'co_tu_van'       => (bool) $log->co_tu_van,
            'co_kham_cls'     => (bool) $log->co_kham_cls,
        ];

        // B5/2026-08-15: khi datasource auto-hủy (trễ 15'), báo trạng thái sang sbooking.
        if ($log->sync_status === 'canceled') {
            $payload['trang_thai'] = 'huy';
            $payload['ly_do_huy'] = $log->sync_error ?: 'Khách trễ quá 15 phút chưa tới.';
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withToken($token)->connectTimeout(3)->timeout(30)->acceptJson()
                ->put($baseUrl . '/bookings/' . $log->sbooking_booking_id, $payload);
        } catch (Throwable $e) {
            $this->markFailed($log, 'PUT fail: ' . $e->getMessage());
            return false;
        }

        if (! $response->successful()) {
            $body = $response->json();
            $reason = is_array($body) && ! empty($body['message'])
                ? $body['message']
                : 'HTTP ' . $response->status() . ': ' . substr($response->body(), 0, 500);
            $this->markFailed($log, $reason);
            return false;
        }

        $log->update(['sync_error' => null, 'synced_at' => now()]);
        return true;
    }

    /**
     * Phase C1.f (2026-08-02) — push comment sang sbooking (POST /bookings/{id}/comments).
     * Chỉ chạy cho booking đã sync. Silent fail — ghi sync_error, không throw.
     */
    public function pushComment(BookingLog $log, string $content, ?int $scrmUserId = null, ?string $scrmUserName = null): bool
    {
        if (! $log->sbooking_booking_id) return false;
        $token = config('services.booking.api_token');
        $baseUrl = rtrim(config('services.booking.api_url') ?: '', '/');
        if (! $token || ! $baseUrl) return false;

        $sbookingUserId = null;
        if ($scrmUserId) {
            $sbookingUserId = \App\Models\User::where('id', $scrmUserId)->value('sbooking_user_id');
        }

        try {
            $r = \Illuminate\Support\Facades\Http::withToken($token)->connectTimeout(3)->timeout(20)->acceptJson()
                ->post($baseUrl . '/bookings/' . $log->sbooking_booking_id . '/comments', [
                    'noi_dung' => $content,
                    'sbooking_user_id' => $sbookingUserId,
                    'scrm_user_name' => $scrmUserName,
                ]);
            return $r->successful();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Phase 6.26.a (2026-09-04) — SCRM sale tiếp đón đánh trạng thái khách bên data source.
     * Push POST /api/bookings/{id}/trang-thai-khach — sbooking guard sbooking_user_id vs tiep_don_user_id.
     * Trả ['ok'=>bool, 'reason'=>string, 'trang_thai_khach'=>?string].
     */
    public function pushTrangThaiKhach(BookingLog $log, ?string $trangThaiKhach, int $scrmUserId, string $scrmUserName): array
    {
        if (! $log->sbooking_booking_id) {
            return ['ok' => false, 'reason' => 'Booking chưa sync sbooking.', 'trang_thai_khach' => null];
        }
        $token = config('services.booking.api_token');
        $baseUrl = rtrim(config('services.booking.api_url') ?: '', '/');
        if (! $token || ! $baseUrl) {
            return ['ok' => false, 'reason' => 'Chưa cấu hình sbooking API.', 'trang_thai_khach' => null];
        }
        $sbookingUserId = \App\Models\User::where('id', $scrmUserId)->value('sbooking_user_id');
        if (! $sbookingUserId) {
            return ['ok' => false, 'reason' => 'User SCRM chưa map sbooking_user_id.', 'trang_thai_khach' => null];
        }
        try {
            $r = Http::withToken($token)->connectTimeout(3)->timeout(20)->acceptJson()
                ->post($baseUrl . '/bookings/' . $log->sbooking_booking_id . '/trang-thai-khach', [
                    'trang_thai_khach' => $trangThaiKhach,
                    'sbooking_user_id' => (int) $sbookingUserId,
                    'scrm_user_name'   => $scrmUserName,
                ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => 'HTTP fail: ' . $e->getMessage(), 'trang_thai_khach' => null];
        }
        if (! $r->successful()) {
            $body = $r->json();
            $reason = is_array($body) && ! empty($body['error']) ? $body['error']
                : (is_array($body) && ! empty($body['message']) ? $body['message'] : 'HTTP ' . $r->status());
            return ['ok' => false, 'reason' => $reason, 'trang_thai_khach' => null];
        }
        $body = $r->json();
        return ['ok' => true, 'reason' => '', 'trang_thai_khach' => $body['trang_thai_khach'] ?? null];
    }

    /**
     * Phase 6.26.a (2026-09-04) — SCRM sale bấm "Đang tiếp đón / Hoàn tất" bên data source.
     * Push POST /api/bookings/{id}/trang-thai-tiep-don.
     */
    public function pushTrangThaiTiepDon(BookingLog $log, string $trangThaiTiepDon, int $scrmUserId, string $scrmUserName): array
    {
        if (! $log->sbooking_booking_id) {
            return ['ok' => false, 'reason' => 'Booking chưa sync sbooking.'];
        }
        $token = config('services.booking.api_token');
        $baseUrl = rtrim(config('services.booking.api_url') ?: '', '/');
        if (! $token || ! $baseUrl) {
            return ['ok' => false, 'reason' => 'Chưa cấu hình sbooking API.'];
        }
        $sbookingUserId = \App\Models\User::where('id', $scrmUserId)->value('sbooking_user_id');
        if (! $sbookingUserId) {
            return ['ok' => false, 'reason' => 'User SCRM chưa map sbooking_user_id.'];
        }
        try {
            $r = Http::withToken($token)->connectTimeout(3)->timeout(20)->acceptJson()
                ->post($baseUrl . '/bookings/' . $log->sbooking_booking_id . '/trang-thai-tiep-don', [
                    'trang_thai_tiep_don' => $trangThaiTiepDon,
                    'sbooking_user_id'    => (int) $sbookingUserId,
                    'scrm_user_name'      => $scrmUserName,
                ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => 'HTTP fail: ' . $e->getMessage()];
        }
        if (! $r->successful()) {
            $body = $r->json();
            $reason = is_array($body) && ! empty($body['error']) ? $body['error']
                : (is_array($body) && ! empty($body['message']) ? $body['message'] : 'HTTP ' . $r->status());
            return ['ok' => false, 'reason' => $reason];
        }
        return ['ok' => true, 'reason' => ''];
    }

    /**
     * Phase 6.26.c (2026-09-04) — SCRM sync trạng thái busy sang sbooking users.dung_nhan_lead.
     * Silent fail (return bool) — không throw để không phá flow toggle bên SCRM khi sbooking sự cố.
     */
    public function syncBusyStatus(int $scrmUserId, bool $isPaused): bool
    {
        $sbookingUserId = \App\Models\User::where('id', $scrmUserId)->value('sbooking_user_id');
        if (! $sbookingUserId) return false;

        $token = config('services.booking.api_token');
        $baseUrl = rtrim(config('services.booking.api_url') ?: '', '/');
        // baseUrl là …/api → PATCH …/api/v1/users/{id}/toggle-busy chưa có prefix v1; tự nối.
        $v1Base = preg_replace('#/api$#', '/api/v1', $baseUrl);
        if (! $token || ! $v1Base) return false;

        try {
            $r = Http::withToken($token)->connectTimeout(3)->timeout(15)->acceptJson()
                ->post($v1Base . '/users/' . (int) $sbookingUserId . '/toggle-busy', [
                    'dung_nhan_lead' => $isPaused,
                ]);
            return $r->successful();
        } catch (Throwable $e) {
            return false;
        }
    }

    private function markFailed(BookingLog $log, string $reason): void
    {
        $log->update([
            'sync_status' => 'failed',
            'sync_error' => $reason,
            'synced_at' => now(),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Đợt C.3.d (2026-08-25) — Combo helpers cho DV 41 (Xông + YHPĐ)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Preflight 1 phòng qua sbooking (dry-run). Trả về ['ok'=>bool, 'reason'=>string].
     * Payload gồm: co_so_id, ngay_dat, gio_thuc_hien, gio_ket_thuc, dich_vu_id, phong_id.
     */
    public function preflightRoom(array $payload): array
    {
        $token = config('services.booking.api_token');
        $baseUrl = rtrim(config('services.booking.api_url') ?: '', '/');
        if (! $token || ! $baseUrl) return ['ok' => false, 'reason' => 'Chưa cấu hình sbooking API.'];
        try {
            $resp = Http::withToken($token)->connectTimeout(3)->timeout(20)->acceptJson()->post($baseUrl . '/bookings/preflight', $payload);
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => 'Preflight HTTP fail: ' . $e->getMessage()];
        }
        if (! $resp->successful()) {
            $body = $resp->json();
            $reason = is_array($body) && ! empty($body['message']) ? $body['message'] : 'Preflight HTTP ' . $resp->status();
            return ['ok' => false, 'reason' => $reason];
        }
        return ['ok' => true, 'reason' => ''];
    }

    /**
     * Push 1 booking raw sang sbooking. Trả ['ok'=>bool, 'id'=>?int, 'ma'=>?string, 'reason'=>string].
     * KHÔNG ghi vào BookingLog — caller tự lưu.
     */
    public function pushRawBooking(array $payload): array
    {
        $token = config('services.booking.api_token');
        $baseUrl = rtrim(config('services.booking.api_url') ?: '', '/');
        if (! $token || ! $baseUrl) return ['ok' => false, 'id' => null, 'ma' => null, 'reason' => 'Chưa cấu hình sbooking API.'];
        try {
            $resp = Http::withToken($token)->connectTimeout(3)->timeout(30)->acceptJson()->post($baseUrl . '/bookings', $payload);
        } catch (Throwable $e) {
            return ['ok' => false, 'id' => null, 'ma' => null, 'reason' => 'HTTP fail: ' . $e->getMessage()];
        }
        if (! $resp->successful()) {
            $body = $resp->json();
            $reason = is_array($body) && ! empty($body['message']) ? $body['message'] : 'HTTP ' . $resp->status() . ': ' . substr($resp->body(), 0, 400);
            return ['ok' => false, 'id' => null, 'ma' => null, 'reason' => $reason];
        }
        $body = $resp->json();
        if (! isset($body['id'])) {
            return ['ok' => false, 'id' => null, 'ma' => null, 'reason' => 'Response thiếu id.'];
        }
        return ['ok' => true, 'id' => (int) $body['id'], 'ma' => $body['ma_booking'] ?? null, 'reason' => ''];
    }

    /**
     * Xoá 1 booking (rollback combo). Chỉ hoạt động cho booking cho_duyet.
     */
    public function deleteBooking(int $bookingId): bool
    {
        $token = config('services.booking.api_token');
        $baseUrl = rtrim(config('services.booking.api_url') ?: '', '/');
        if (! $token || ! $baseUrl) return false;
        try {
            $resp = Http::withToken($token)->connectTimeout(3)->timeout(20)->acceptJson()->delete($baseUrl . '/bookings/' . $bookingId);
            return $resp->successful();
        } catch (Throwable $e) {
            return false;
        }
    }
}
