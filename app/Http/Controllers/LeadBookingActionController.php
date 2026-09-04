<?php

namespace App\Http\Controllers;

use App\Models\BookingLog;
use App\Models\Lead;
use App\Services\SbookingClient;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 6.26.b (2026-09-04) — Sale tiếp đón thao tác 3 hành động bên SCRM,
 * push sang sbooking qua SbookingClient. Mỗi endpoint guard:
 *   - BookingLog thuộc lead trong URL.
 *   - User hiện tại là CV1 (position=1) của log đó.
 *   - Log đã sync sbooking (có sbooking_booking_id).
 *
 * Không nhận log_id từ query bên ngoài — resolve từ latest BookingLog của lead.
 */
class LeadBookingActionController extends Controller
{
    public function trangThaiKhach(Lead $lead, BookingLog $log, Request $request, SbookingClient $client)
    {
        [$err, $data] = $this->guard($lead, $log, $request, [
            'trang_thai_khach' => ['nullable', Rule::in(['da_toi', 'toi_tre', 'huy'])],
        ]);
        if ($err) return back()->with('error', $err);

        $u = auth()->user();
        $res = $client->pushTrangThaiKhach($log, $data['trang_thai_khach'] ?? null, $u->id, $u->name ?? '');
        $msg = $res['ok']
            ? 'Đã cập nhật trạng thái khách bên sbooking' . ($res['trang_thai_khach'] ? ' (' . $res['trang_thai_khach'] . ').' : ' (đã bỏ chọn).')
            : 'Không cập nhật được: ' . $res['reason'];
        return back()->with($res['ok'] ? 'success' : 'error', $msg);
    }

    public function tiepDon(Lead $lead, BookingLog $log, Request $request, SbookingClient $client)
    {
        [$err, $data] = $this->guard($lead, $log, $request, [
            'trang_thai_tiep_don' => ['required', Rule::in(['dang_tiep_don', 'hoan_tat'])],
        ]);
        if ($err) return back()->with('error', $err);

        $u = auth()->user();
        $res = $client->pushTrangThaiTiepDon($log, $data['trang_thai_tiep_don'], $u->id, $u->name ?? '');
        $label = $data['trang_thai_tiep_don'] === 'dang_tiep_don' ? 'Bắt đầu tiếp đón' : 'Hoàn tất tiếp đón';
        return back()->with($res['ok'] ? 'success' : 'error',
            $res['ok'] ? $label . ' — đã đồng bộ sbooking.' : 'Không cập nhật được: ' . $res['reason']);
    }

    public function comment(Lead $lead, BookingLog $log, Request $request, SbookingClient $client)
    {
        [$err, $data] = $this->guard($lead, $log, $request, [
            'noi_dung' => ['required', 'string', 'max:2000'],
        ]);
        if ($err) return back()->with('error', $err);

        $u = auth()->user();
        $ok = $client->pushComment($log, $data['noi_dung'], $u->id, $u->name ?? '');
        return back()->with($ok ? 'success' : 'error',
            $ok ? 'Đã gửi bình luận sang sbooking.' : 'Không gửi được bình luận (kiểm tra kết nối).');
    }

    /**
     * Trả [error_string|null, validated_data]. Guard 3 bước: lead↔log, CV1, sync sbooking.
     */
    private function guard(Lead $lead, BookingLog $log, Request $request, array $rules): array
    {
        if ((int) $log->lead_id !== (int) $lead->id) {
            return ['Booking không thuộc lead này.', []];
        }
        if (! $log->sbooking_booking_id) {
            return ['Booking chưa sync sbooking.', []];
        }
        $u = auth()->user();
        $cv1 = $log->consultants()->orderBy('booking_log_consultants.position')->first();
        if (! $cv1 || (int) $cv1->id !== (int) $u->id) {
            return ['Chỉ Sale tiếp đón (CV#1) của booking mới thao tác được.', []];
        }
        $data = $request->validate($rules);
        return [null, $data];
    }
}
