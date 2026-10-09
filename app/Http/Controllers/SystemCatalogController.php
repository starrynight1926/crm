<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\CustomField;
use App\Models\Lead;
use App\Models\OrgUnit;
use App\Models\PoolUnit;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 2026-08-04 (Task 3): Danh mục hệ thống — 5 tab (org/staff/service/lead/field).
 * Chỉ Admin hệ thống (perm `user.manage`) mới vào được.
 *
 * Route index render Livewire component. Controller chỉ xử lý 2 dạng file:
 *   - export/{tab}  — xuất data thật hiện tại
 *   - template/{tab} — tải file mẫu để user điền + upload lại
 */
class SystemCatalogController extends Controller
{
    public function export(string $tab): StreamedResponse
    {
        abort_unless(in_array($tab, ['org', 'staff', 'service', 'lead', 'field'], true), 404);

        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();

        match ($tab) {
            'org' => $this->fillOrg($sheet, withData: true),
            'staff' => $this->fillStaff($sheet, withData: true),
            'service' => $this->fillService($sheet, withData: true),
            'lead' => $this->fillLead($sheet, withData: true),
            'field' => $this->fillField($sheet, withData: true),
        };

        $filename = "catalog-{$tab}-" . now()->format('Ymd-His') . '.xlsx';
        return $this->stream($ss, $filename);
    }

    public function template(string $tab): StreamedResponse
    {
        abort_unless(in_array($tab, ['org', 'staff', 'service', 'lead', 'field'], true), 404);

        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();

        match ($tab) {
            'org' => $this->fillOrg($sheet, withData: false),
            'staff' => $this->fillStaff($sheet, withData: false),
            'service' => $this->fillService($sheet, withData: false),
            'lead' => $this->fillLead($sheet, withData: false),
            'field' => $this->fillField($sheet, withData: false),
        };

        return $this->stream($ss, "mau-catalog-{$tab}.xlsx");
    }

    private function stream(Spreadsheet $ss, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($ss) {
            (new Xlsx($ss))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // ============================================================
    // TAB 1: ORG UNITS (cây org_units — kho số pool_units xuất riêng cột `kind`)
    // ============================================================
    private function fillOrg($sheet, bool $withData): void
    {
        $sheet->setTitle('Cơ cấu tổ chức');
        $sheet->fromArray(['code', 'name', 'parent_code', 'depth', 'position', 'active'], null, 'A1');
        $sheet->fromArray([
            ['company', 'Công ty', '', 0, 0, 1],
            ['branch-hn', 'Chi nhánh Hà Nội', 'company', 1, 0, 1],
        ], null, 'A2');

        if ($withData) {
            $sheet->fromArray([], null, 'A2:F999');
            $row = 2;
            foreach (OrgUnit::orderBy('depth')->orderBy('position')->get() as $o) {
                $parent = $o->parent_id ? OrgUnit::find($o->parent_id) : null;
                $sheet->fromArray([$o->code, $o->name, $parent?->code ?? '', $o->depth, $o->position, (int) $o->active], null, 'A' . $row++);
            }
        }

        $this->autoSize($sheet, ['A', 'B', 'C', 'D', 'E', 'F']);
    }

    // ============================================================
    // TAB 2: STAFF (users + 1 assignment mỗi row)
    // ============================================================
    private function fillStaff($sheet, bool $withData): void
    {
        $sheet->setTitle('Nhân sự');
        $sheet->fromArray(['username', 'name', 'email', 'password', 'job_title', 'status', 'role_name', 'org_unit_code', 'data_scope'], null, 'A1');

        if ($withData) {
            $row = 2;
            foreach (User::with('assignments.role', 'assignments.orgUnit')->orderBy('email')->get() as $u) {
                $a = $u->assignments->first();
                $sheet->fromArray([
                    $u->username,
                    $u->name,
                    $u->email,
                    '(đã hash, không xuất)',
                    $u->job_title,
                    $u->status,
                    $a?->role?->name ?? '',
                    $a?->orgUnit?->code ?? '',
                    $a?->data_scope ?? '',
                ], null, 'A' . $row++);
            }
        } else {
            $sheet->fromArray([
                ['nv.demo01', 'Nguyễn Văn A', 'nv.demo01@longevity.com.vn', 'PassPlain@123', 'Sale', 'active', 'Sale', 'team-giang-sale', 'team'],
            ], null, 'A2');
        }

        $this->autoSize($sheet, range('A', 'I'));
    }

    // ============================================================
    // TAB 3: SERVICE
    // ============================================================
    private function fillService($sheet, bool $withData): void
    {
        $sheet->setTitle('Dịch vụ');
        $sheet->fromArray(['code', 'name', 'service_type', 'pricing_type', 'package_price', 'active'], null, 'A1');

        if ($withData) {
            $row = 2;
            foreach (Service::orderBy('name')->get() as $s) {
                $sheet->fromArray([
                    $s->code, $s->name, $s->service_type ?? '',
                    $s->pricing_type, (float) $s->package_price, (int) $s->active,
                ], null, 'A' . $row++);
            }
        } else {
            $sheet->fromArray([
                ['DA-01', 'Điều trị da', 'dich_vu', 'package', 5000000, 1],
                ['KH-01', 'Khám tổng quát', 'tham_kham', 'package', 500000, 1],
            ], null, 'A2');
        }

        $this->autoSize($sheet, range('A', 'F'));
    }

    // ============================================================
    // TAB 4: LEAD (chỉ core cột, ghi trực tiếp không qua raw pipeline)
    // ============================================================
    private function fillLead($sheet, bool $withData): void
    {
        $sheet->setTitle('Khách hàng');
        $sheet->fromArray([
            'name', 'phone', 'received_date', 'source_group', 'classification',
            'region', 'note', 'phase',
        ], null, 'A1');

        if ($withData) {
            $row = 2;
            $q = Lead::orderBy('id');
            foreach ($q->cursor() as $l) {
                $sheet->fromArray([
                    $l->name, $l->phone, $l->received_date?->format('Y-m-d'),
                    $l->source_group, $l->classification, $l->region, $l->note, (int) $l->phase,
                ], null, 'A' . $row++);
            }
        } else {
            $sheet->fromArray([
                ['Trần Thị B', '0912345678', now()->format('Y-m-d'), 'mkt', 'new', 'Hà Nội', '', 1],
            ], null, 'A2');
        }

        $this->autoSize($sheet, range('A', 'H'));
    }

    // ============================================================
    // TAB 5: CUSTOM FIELDS
    // ============================================================
    private function fillField($sheet, bool $withData): void
    {
        $sheet->setTitle('Trường thông tin KH');
        $sheet->fromArray(['key', 'label', 'field_type', 'org_unit_code', 'required', 'options', 'position', 'active'], null, 'A1');

        if ($withData) {
            $row = 2;
            foreach (CustomField::with('orgUnit')->orderBy('position')->get() as $f) {
                $sheet->fromArray([
                    $f->key, $f->label, $f->field_type,
                    $f->orgUnit?->code ?? '',
                    (int) $f->required,
                    is_array($f->options) ? implode('|', $f->options) : (string) $f->options,
                    $f->position, (int) $f->active,
                ], null, 'A' . $row++);
            }
        } else {
            $sheet->fromArray([
                ['dia_chi_full', 'Địa chỉ đầy đủ', 'text', '', 0, '', 1, 1],
                ['nguon_tv', 'Nguồn tư vấn', 'select', 'team-giang-sale', 1, 'A|B|C', 2, 1],
            ], null, 'A2');
        }

        $this->autoSize($sheet, range('A', 'H'));
    }

    private function autoSize($sheet, array $cols): void
    {
        foreach ($cols as $c) $sheet->getColumnDimension($c)->setAutoSize(true);
        $sheet->getStyle('A1:' . end($cols) . '1')->getFont()->setBold(true);
    }
}
