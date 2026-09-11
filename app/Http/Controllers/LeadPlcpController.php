<?php

namespace App\Http\Controllers;

use App\Models\BookingLog;
use App\Models\Lead;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * 2026-09-11 rev3 — Render PLCP bằng Blade + dompdf.
 *   Trước dùng FPDI overlay lên PDF template (image-based) — không đọc được toạ độ label,
 *   fill sai chỗ. Rewrite thành HTML template chủ động, fill 4 field:
 *     - Mã KH  (lead.code)
 *     - Ngày lập (booking_log.first_tiep_don_at || now)
 *     - Cơ sở (booking_log.facility.name)
 *     - Họ tên (lead.name)
 *   Guard: user hiện tại là CV1 (position=1) của booking log đó.
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
        if (! $cv1 || (int) $cv1->id !== (int) $u->id) {
            abort(403, 'Chỉ Sale tiếp đón (CV#1) của booking mới tải được PLCP.');
        }

        $ngay = $log->first_tiep_don_at ?: now();
        $data = [
            'ma_kh'    => (string) ($lead->code ?? ''),
            'ho_ten'   => (string) ($lead->name ?? ''),
            'ngay_lap' => $ngay->format('d/m/Y'),
            'co_so'    => (string) ($log->facility?->name ?? ''),
        ];

        $pdf = Pdf::loadView('pdf.plcp', $data)
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'defaultFont'      => 'DejaVu Sans',
                'isRemoteEnabled'  => false,
                'isPhpEnabled'     => false,
                'isHtml5ParserEnabled' => true,
            ]);

        $filename = 'PLCP_' . ($lead->code ?: $lead->id) . '.pdf';
        return $pdf->download($filename);
    }
}
