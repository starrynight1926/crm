<?php

namespace App\Http\Controllers;

use App\Models\BookingLog;
use App\Models\Lead;
use App\Models\SbBacSi;
use Illuminate\Http\Request;
use Mpdf\Mpdf;

/**
 * 2026-09-11 rev4 — Render PLCP bằng mPDF (bỏ dompdf).
 * mPDF hỗ trợ gradient CSS, Unicode Việt, @font-face — vẽ khớp PDF gốc hơn.
 */
class LeadPlcpController extends Controller
{
    public function download(Lead $lead, BookingLog $log, Request $request)
    {
        if ((int) $log->lead_id !== (int) $lead->id) {
            abort(404, 'Booking không thuộc lead này.');
        }
        $u = auth()->user();
        $cv1 = $log->consultants()->orderBy('booking_log_consultants.position')->first();
        $isCv1 = $cv1 && (int) $cv1->id === (int) $u->id;
        // 2026-10-16: Admin (user.manage) tải PLCP được dù không phải CV1 — QC/in lại hồ sơ.
        if (! $isCv1 && ! $u->hasPermission('user.manage')) {
            abort(403, 'Chỉ Sale tiếp đón (CV#1) của booking hoặc Admin mới tải được PLCP.');
        }

        $ngay = $log->first_tiep_don_at ?: now();

        // Cơ sở: walk parent chain lên root (VD "Khối chuyên môn" là dept con,
        // giấy tờ cần tên cơ sở vật lý ở root như "Cơ sở Hà Nội: 59 Ngô Thì Nhậm").
        $fac = $log->facility;
        while ($fac && $fac->parent_id) {
            $fac = $fac->parent;
        }

        $data = [
            'ma_kh'    => (string) ($lead->code ?? ''),
            'ho_ten'   => (string) ($lead->name ?? ''),
            'ngay_lap' => $ngay->format('d/m/Y'),
            'co_so'    => (string) ($fac?->name ?? ''),
            'bac_si'   => $this->resolveBacSi($log),
        ];

        $html = view('pdf.plcp', $data)->render();

        $tmp = storage_path('app/mpdf-tmp');
        if (! is_dir($tmp)) mkdir($tmp, 0775, true);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            'default_font' => 'dejavusans',
            'tempDir' => $tmp,
        ]);
        $mpdf->WriteHTML($html);

        $filename = 'PLCP_' . ($lead->code ?: $lead->id) . '.pdf';
        return response($mpdf->Output($filename, 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Bác sĩ phụ trách = bác sĩ được gán khi admin cơ sở duyệt lịch.
     * Ưu tiên `sb_bac_si_id` (bác sĩ Sbooking — nguồn thực tế đang dùng),
     * fallback `doctor_id` (StaffMember cũ) nếu có.
     */
    private function resolveBacSi(BookingLog $log): string
    {
        $raw = '';
        if ($log->sb_bac_si_id) {
            $bs = SbBacSi::find($log->sb_bac_si_id);
            if ($bs) {
                $raw = trim(($bs->chuc_danh ? $bs->chuc_danh . ' ' : '') . $bs->ten);
            }
        }
        if ($raw === '') {
            $raw = (string) ($log->doctor?->name ?? '');
        }
        // Chuẩn hoá "BS. Bác sĩ Nguyễn ..." / "Bác sĩ BS. Nguyễn ..." → "BS. Nguyễn ..."
        // (chuc_danh và ten đôi khi cùng chứa prefix, ghép lại thành dài loằng ngoằng)
        $name = preg_replace('/^(BS\.?\s*)?(Bác\s*sĩ\s*)?(BS\.?\s*)?/iu', '', $raw);
        $name = trim($name);
        return $name === '' ? $raw : 'BS. ' . $name;
    }
}
