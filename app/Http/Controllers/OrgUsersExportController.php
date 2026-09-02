<?php

namespace App\Http\Controllers;

use App\Models\OrgUnit;
use App\Models\User;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Xuất danh sách nhân viên theo bộ lọc hiện tại của /org/users.
 * Query params:
 *   - org_unit (id): nếu có → chỉ user có assignment trong subtree của node đó.
 *   - role (id): lọc theo vai trò.
 *   - q: search theo name/email.
 */
class OrgUsersExportController extends Controller
{
    public function export(Request $request): StreamedResponse
    {
        abort_unless(auth()->user()->hasPermission('user.manage'), 403);

        $orgUnitId = $request->integer('org_unit') ?: null;
        $roleId    = $request->integer('role') ?: null;
        $q         = trim((string) $request->input('q', ''));

        $subtreeIds = [];
        $orgLabel   = 'Tất cả';
        if ($orgUnitId) {
            $node = OrgUnit::find($orgUnitId);
            if ($node) {
                $subtreeIds = $node->subtreeIds();
                $orgLabel   = $node->name;
            }
        }

        $users = User::query()
            ->with(['assignments.role', 'assignments.orgUnit'])
            ->when($q !== '', fn ($qq) => $qq->where(fn ($w) => $w
                ->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")))
            ->when(! empty($subtreeIds), fn ($qq) => $qq->whereHas('assignments',
                fn ($a) => $a->whereIn('org_unit_id', $subtreeIds)))
            ->when($roleId, fn ($qq) => $qq->whereHas('assignments',
                fn ($a) => $a->where('role_id', $roleId)))
            ->orderBy('name')
            ->get();

        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Nhân viên');
        $sheet->fromArray(
            ['Họ tên', 'Email', 'SĐT', 'Chức danh', 'Trạng thái', 'Vai trò @ Đơn vị'],
            null, 'A1'
        );

        $row = 2;
        foreach ($users as $u) {
            $roles = $u->assignments
                ->filter(fn ($a) => ! empty($subtreeIds) ? in_array($a->org_unit_id, $subtreeIds, true) : true)
                ->map(fn ($a) => ($a->role?->name ?? '—') . ' @ ' . ($a->orgUnit?->name ?? '—'))
                ->implode('; ');

            $sheet->fromArray([
                $u->name,
                $u->email,
                $u->phone ?? '',
                $u->job_title ?? '',
                $u->isLocked() ? 'Khoá' : 'Hoạt động',
                $roles,
            ], null, 'A' . $row++);
        }

        foreach (range('A', 'F') as $c) $sheet->getColumnDimension($c)->setAutoSize(true);
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);

        $slug = \Illuminate\Support\Str::slug($orgLabel) ?: 'all';
        $filename = "nhan-vien-{$slug}-" . now()->format('Ymd-His') . '.xlsx';

        return response()->streamDownload(function () use ($ss) {
            (new Xlsx($ss))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
