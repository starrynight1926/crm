<?php

namespace App\Http\Controllers;

use App\Models\StaffMember;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffExportController extends Controller
{
    public function export(): StreamedResponse
    {
        abort_unless(auth()->user()->hasPermission('staff.manage'), 403);

        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Nhân sự');
        $sheet->fromArray(['Cơ sở', 'Phòng ban', 'Tên', 'Chức vụ', 'Active'], null, 'A1');

        $row = 2;
        foreach (StaffMember::with('facility.parent')->orderBy('facility_id')->orderBy('name')->get() as $s) {
            $fac = $s->facility;
            $parent = $fac?->parent;
            $sheet->fromArray([
                $parent?->name ?? $fac?->name ?? '',
                $parent ? $fac?->name : '',
                $s->name,
                $s->title ?? '',
                $s->active ? 1 : 0,
            ], null, 'A' . $row++);
        }

        foreach (range('A', 'E') as $c) $sheet->getColumnDimension($c)->setAutoSize(true);
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);

        $filename = 'nhan-su-' . now()->format('Ymd-His') . '.xlsx';

        return response()->streamDownload(function () use ($ss) {
            (new Xlsx($ss))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
