<?php

namespace App\Http\Controllers;

use App\Models\DailyAttendance;
use App\Models\PoolUnit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * 2026-08-26: Import/Export DailyAttendance cho trang /ups-today.
 * Chỉ email admin@longevity.com.vn được truy cập (guard trong mỗi action).
 */
class UpsAttendanceImportExportController extends Controller
{
    private const ADMIN_EMAIL = 'admin@longevity.com.vn';

    /** Guard: chỉ 1 email cụ thể được vào. */
    private function guard(): void
    {
        abort_unless(auth()->user()?->email === self::ADMIN_EMAIL, 403);
    }

    /**
     * GET /ups-today/export?from=YYYY-MM-DD&to=YYYY-MM-DD
     * Xuất DailyAttendance theo date range → xlsx.
     */
    public function export(Request $request)
    {
        $this->guard();

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to'   => ['required', 'date', 'after_or_equal:from'],
        ]);

        $rows = DailyAttendance::with(['facility', 'user'])
            ->whereBetween('work_date', [$data['from'], $data['to']])
            ->orderBy('work_date')
            ->orderBy('facility_pool_unit_id')
            ->orderBy('checkin_at')
            ->get();

        $ss = new Spreadsheet();
        $sh = $ss->getActiveSheet();
        $sh->setTitle('UPS attendance');

        $headers = ['work_date', 'facility_code', 'facility_name', 'sale_email', 'sale_name', 'bucket', 'is_mkt', 'is_off', 'checkin_at'];
        $sh->fromArray($headers, null, 'A1');

        $r = 2;
        foreach ($rows as $a) {
            $sh->fromArray([
                $a->work_date?->format('Y-m-d'),
                $a->facility?->code,
                $a->facility?->name,
                $a->user?->email,
                $a->user?->name,
                $a->list_bucket ?: '',
                $a->is_mkt ? 'Y' : 'N',
                $a->is_off ? 'Y' : 'N',
                $a->checkin_at?->format('H:i:s'),
            ], null, "A{$r}");
            $r++;
        }

        foreach (range('A', 'I') as $col) $sh->getColumnDimension($col)->setAutoSize(true);

        $filename = 'ups_attendance_' . $data['from'] . '_' . $data['to'] . '.xlsx';
        return response()->streamDownload(function () use ($ss) {
            (new Xlsx($ss))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * POST /ups-today/import
     * Replace toàn bộ DailyAttendance hôm nay bằng data từ file xlsx.
     * Cột đọc: facility_code, sale_email, bucket, is_mkt.
     */
    public function import(Request $request)
    {
        $this->guard();

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls'],
        ]);

        $path = $request->file('file')->getRealPath();
        $sheet = IOFactory::load($path)->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);
        if (empty($rows)) {
            return redirect()->route('ups.today')->with('ups_msg', 'File rỗng, không import.');
        }

        // Header ở dòng 1 → map cột theo tên (không cứng thứ tự).
        $header = array_map(fn ($v) => strtolower(trim((string) $v)), array_shift($rows));
        $colIdx = array_flip($header);
        foreach (['facility_code', 'sale_email', 'bucket', 'is_mkt'] as $need) {
            if (! isset($colIdx[$need])) {
                return back()->with('ups_msg', "Thiếu cột bắt buộc: {$need}.");
            }
        }

        $today = now()->toDateString();
        $now = now();

        $facilityByCode = PoolUnit::where('kind', 'facility')->pluck('id', 'code');
        $userByEmail = User::pluck('id', 'email');

        $insertRows = [];
        $skipped = 0;
        foreach ($rows as $row) {
            $facCode = trim((string) ($row[$colIdx['facility_code']] ?? ''));
            $email   = trim((string) ($row[$colIdx['sale_email']] ?? ''));
            $bucket  = strtoupper(trim((string) ($row[$colIdx['bucket']] ?? '')));
            $isMkt   = strtoupper(trim((string) ($row[$colIdx['is_mkt']] ?? 'N'))) === 'Y';

            if ($facCode === '' && $email === '') continue; // dòng trống
            $facId = $facilityByCode[$facCode] ?? null;
            $userId = $userByEmail[$email] ?? null;
            if (! $facId || ! $userId) { $skipped++; continue; }
            if ($bucket !== '' && ! in_array($bucket, DailyAttendance::BUCKETS, true)) { $skipped++; continue; }

            $insertRows[] = [
                'facility_pool_unit_id' => $facId,
                'user_id' => $userId,
                'work_date' => $today,
                'checkin_at' => $now,
                'list_bucket' => $bucket ?: null,
                'is_off' => $bucket === 'OFF',
                'is_mkt' => $isMkt,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($today, $insertRows) {
            DailyAttendance::whereDate('work_date', $today)->delete();
            foreach (array_chunk($insertRows, 200) as $chunk) {
                DailyAttendance::insert($chunk);
            }
        });

        $msg = 'Đã import ' . count($insertRows) . ' dòng cho ngày ' . $today . '.';
        if ($skipped) $msg .= " Bỏ qua {$skipped} dòng thiếu ref / bucket sai.";
        return redirect()->route('ups.today')->with('ups_msg', $msg);
    }
}
