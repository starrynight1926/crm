<?php

namespace App\Http\Controllers;

use App\Models\BookingLog;
use App\Models\Lead;
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

        $html = view('pdf.plcp', $data)->render();

        $tmp = storage_path('app/mpdf-tmp');
        if (! is_dir($tmp)) mkdir($tmp, 0775, true);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 18,
            'margin_right' => 15,
            'margin_top' => 18,
            'margin_bottom' => 15,
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
}
