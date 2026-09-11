<?php

namespace App\Http\Controllers;

use App\Models\BookingLog;
use App\Models\Lead;
use Illuminate\Http\Request;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * 2026-09-11 — Render Phiếu tiếp đón khách hàng (PLCP) đã fill sẵn:
 *   - Mã KH  (lead.code)
 *   - Họ tên (lead.name)
 *   - Ngày lập (booking_log.first_tiep_don_at — mốc lần đầu bấm "Đang tiếp đón",
 *              fallback now() nếu chưa bấm)
 *   - Cơ sở  (booking_log.facility.name)
 *
 * Guard: user hiện tại là CV1 của booking log đó.
 * Debug: ?debug=grid → vẽ lưới tọa độ mm 10x10 để căn ô, KHÔNG stamp text.
 *
 * Tọa độ ô mm (x, y) đo từ mép trái/trên trang 1:
 *   PLCP_FIELDS_MM — chỉnh trực tiếp mảng này khi mày căn xong.
 */
class LeadPlcpController extends Controller
{
    /** Toạ độ ước lượng — mày mở ?debug=grid xong chỉnh lại chỗ này. */
    private const PLCP_FIELDS_MM = [
        // key       => [x, y, font_size]
        'ma_kh'      => [45, 45, 11],
        'ho_ten'     => [45, 55, 11],
        'ngay_lap'   => [45, 65, 11],
        'co_so'      => [45, 75, 11],
    ];

    private const TEMPLATE_PATH = 'downloads/plcp-mau.pdf';

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

        $tpl = public_path(self::TEMPLATE_PATH);
        if (! is_file($tpl)) {
            abort(500, 'Thiếu template PLCP tại public/' . self::TEMPLATE_PATH);
        }

        $pdf = new Fpdi('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pageCount = $pdf->setSourceFile($tpl);

        // Font Unicode có sẵn TCPDF (dejavusans hỗ trợ tiếng Việt).
        $font = 'dejavusans';

        $debug = $request->query('debug') === 'grid';

        for ($p = 1; $p <= $pageCount; $p++) {
            $tplId = $pdf->importPage($p);
            $size = $pdf->getTemplateSize($tplId);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tplId, 0, 0, $size['width'], $size['height'], true);

            if ($p === 1) {
                if ($debug) {
                    $this->drawGrid($pdf, $size['width'], $size['height']);
                } else {
                    $this->stampFields($pdf, $font, $lead, $log);
                }
            }
        }

        $filename = 'PLCP_' . ($lead->code ?: $lead->id) . '.pdf';
        return response($pdf->Output($filename, 'S'), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function stampFields(Fpdi $pdf, string $font, Lead $lead, BookingLog $log): void
    {
        $ngay = $log->first_tiep_don_at ?: now();
        $facility = $log->facility?->name ?? '';
        $values = [
            'ma_kh'    => (string) ($lead->code ?? ''),
            'ho_ten'   => (string) ($lead->name ?? ''),
            'ngay_lap' => $ngay->format('d/m/Y'),
            'co_so'    => $facility,
        ];
        $pdf->SetTextColor(0, 0, 0);
        foreach (self::PLCP_FIELDS_MM as $k => [$x, $y, $size]) {
            $pdf->SetFont($font, '', $size);
            $pdf->SetXY($x, $y);
            $pdf->Write(0, $values[$k] ?? '');
        }
    }

    private function drawGrid(Fpdi $pdf, float $w, float $h): void
    {
        $pdf->SetDrawColor(255, 0, 0);
        $pdf->SetTextColor(255, 0, 0);
        $pdf->SetFont('helvetica', '', 5);
        for ($x = 0; $x <= $w; $x += 10) {
            $pdf->Line($x, 0, $x, $h);
            $pdf->SetXY($x + 0.5, 0.5);
            $pdf->Cell(6, 3, (string) $x, 0, 0, 'L');
        }
        for ($y = 0; $y <= $h; $y += 10) {
            $pdf->Line(0, $y, $w, $y);
            $pdf->SetXY(0.5, $y + 0.5);
            $pdf->Cell(6, 3, (string) $y, 0, 0, 'L');
        }
    }
}
