<?php

namespace App\Services;

use App\Models\CustomField;
use App\Models\Facility;
use App\Models\Lead;
use App\Models\OrgUnit;
use App\Models\Permission;
use App\Models\PoolUnit;
use App\Models\Role;
use App\Models\SbBacSi;
use App\Models\SbRoom;
use App\Models\SbService;
use App\Models\SbUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * 2026-08-05 — Export catalog toàn bộ hệ thống ra 1 file xlsx multi-sheet.
 * Sheet order: tổ chức → nhân sự → vai trò → cơ sở → mirror sbooking (bác sĩ/KTV/phòng/dịch vụ) → trường tùy biến → kho lead.
 * Data thật, dùng để duyệt bước cuối trước on air.
 */
class CatalogExporter
{
    public function build(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        // 2026-08-05: sort cây liền mạch, Chi nhánh HN → HCM → ĐN → ops (chuẩn convention).
        //   Dùng path suffix để giữ subtree đúng thứ tự cha-con.
        $this->addSheet($spreadsheet, 'Cơ cấu tổ chức', ['Cấp', 'Tên', 'Code', 'Path', 'Active'],
            $this->sortOrgTree(OrgUnit::orderBy('path')->get())
                ->map(fn ($o) => [
                    $o->depth,
                    str_repeat('    ', $o->depth) . ($o->depth === 0 ? '' : '└─ ') . $o->name,
                    $o->code,
                    $o->path,
                    $o->active ? '✅' : '⛔',
                ])->all());

        $this->addSheet($spreadsheet, 'Nhân sự', ['Username', 'Tên', 'Email', 'Status', 'Job title', 'Assignments'],
            User::with('assignments.role', 'assignments.orgUnit')->orderBy('username')->get()
                ->map(fn ($u) => [
                    $u->username, $u->name, $u->email, $u->status, $u->job_title,
                    $u->assignments->map(fn ($a) => ($a->role?->name ?? '?') . ' @ ' . ($a->orgUnit?->name ?? '?'))->implode('; '),
                ])->all());

        $this->addSheet($spreadsheet, 'Vai trò & Quyền', ['Vai trò', 'Permission keys'],
            Role::with('permissions')->orderBy('name')->get()
                ->map(fn ($r) => [$r->name, $r->permissions->pluck('key')->sort()->implode(', ')])->all());

        // Cơ sở: cây (Facility có parent_id). Duyệt DFS từ root để liền mạch.
        $facilityRows = [];
        $walk = function ($nodes, int $depth) use (&$walk, &$facilityRows) {
            foreach ($nodes as $f) {
                $facilityRows[] = [
                    $depth,
                    str_repeat('    ', $depth) . ($depth === 0 ? '' : '└─ ') . $f->name,
                    $f->id,
                    $f->booking_co_so_slug,
                    $f->sbooking_co_so_id,
                    $f->active ? '✅' : '⛔',
                ];
                $walk($f->children, $depth + 1);
            }
        };
        $walk(Facility::with('children.children.children')->whereNull('parent_id')->orderBy('name')->get(), 0);
        $this->addSheet($spreadsheet, 'Cơ sở', ['Cấp', 'Tên', 'ID', 'Slug booking', 'Sbooking co_so_id', 'Active'], $facilityRows);

        // Mirror sbooking — nguồn thật để duyệt.
        $this->addSheet($spreadsheet, 'Bác sĩ (sbooking)', ['Sbooking ID', 'Tên', 'Chức danh', 'Cơ sở ID', 'Active'],
            SbBacSi::orderBy('ten')->get()
                ->map(fn ($b) => [$b->sbooking_id, $b->ten, $b->chuc_danh, $b->sbooking_co_so_id, $b->active ? '✅' : '⛔'])->all());

        $this->addSheet($spreadsheet, 'KTV - Điều dưỡng (sbooking)', ['Sbooking ID', 'Tên', 'Username', 'Vai trò', 'Cơ sở ID'],
            SbUser::whereIn('sbooking_vai_tro_ma', ['ktv', 'dieu_duong'])->orderBy('ten')->get()
                ->map(fn ($u) => [$u->sbooking_id, $u->ten, $u->username, $u->sbooking_vai_tro_ten, $u->sbooking_co_so_id])->all());

        $this->addSheet($spreadsheet, 'Phòng khám (sbooking)', ['Sbooking ID', 'Tên', 'Loại', 'Kiểu phòng', 'Slot tối đa', 'Phút/khách', 'Trạng thái', 'Cơ sở ID'],
            SbRoom::orderBy('ten')->get()
                ->map(fn ($r) => [$r->sbooking_id, $r->ten, $r->loai, $r->kieu_phong, $r->so_slot_toi_da, $r->phut_moi_khach, $r->trang_thai, $r->sbooking_co_so_id])->all());

        // 2026-10-09: load map DV → phòng + DV → bác sĩ 1 lần, append vào sheet thăm khám + dịch vụ.
        $phongMap = DB::table('sb_dich_vu_phong')
            ->join('sb_rooms', 'sb_rooms.sbooking_id', '=', 'sb_dich_vu_phong.sbooking_phong_id')
            ->select('sb_dich_vu_phong.sbooking_dich_vu_id as dv_id', 'sb_rooms.ten as phong_ten')
            ->orderBy('sb_rooms.ten')->get()->groupBy('dv_id')
            ->map(fn ($g) => $g->pluck('phong_ten')->unique()->implode(', '));
        $bacSiMap = Schema::hasTable('sb_dich_vu_bac_si')
            ? DB::table('sb_dich_vu_bac_si')
                ->join('sb_bac_si', 'sb_bac_si.sbooking_id', '=', 'sb_dich_vu_bac_si.sbooking_bac_si_id')
                ->select('sb_dich_vu_bac_si.sbooking_dich_vu_id as dv_id', 'sb_bac_si.ten as bs_ten')
                ->orderBy('sb_bac_si.ten')->get()->groupBy('dv_id')
                ->map(fn ($g) => $g->pluck('bs_ten')->unique()->implode(', '))
            : collect();

        $buildSvcRow = function ($g) use ($phongMap, $bacSiMap) {
            $dvId = (int) $g->first()->sbooking_id;
            return [
                $g->first()->ten,
                $g->first()->thoi_gian_phut,
                number_format((float) $g->first()->gia, 0, ',', '.'),
                $g->pluck('sbooking_co_so_id')->unique()->sort()->implode(', '),
                $phongMap[$dvId] ?? '—',
                $bacSiMap[$dvId] ?? '—',
                $g->first()->active ? '✅' : '⛔',
            ];
        };
        $svcHeader = ['Tên', 'Thời gian (phút)', 'Giá', 'Cơ sở áp dụng', 'Phòng thực hiện', 'Bác sĩ thực hiện', 'Active'];

        $thamKham = SbService::where('la_dich_vu', false)->orderBy('ten')->get()->groupBy('ten')
            ->map($buildSvcRow)->values()->all();
        $this->addSheet($spreadsheet, 'Thăm khám', $svcHeader, $thamKham);

        $dichVu = SbService::where('la_dich_vu', true)->orderBy('ten')->get()->groupBy('ten')
            ->map($buildSvcRow)->values()->all();
        $this->addSheet($spreadsheet, 'Dịch vụ', $svcHeader, $dichVu);

        // 2026-10-09: Nguồn — bảng cố định từ Lead::SOURCE_GROUPS.
        $nguonRows = [];
        foreach (Lead::SOURCE_GROUPS as $key => $label) {
            $flow = match (true) {
                in_array($key, Lead::SOURCES_UPS_BASED, true) => 'UPS-based',
                in_array($key, Lead::SOURCES_CM_ASSIGNED, true) => 'CM-assigned',
                in_array($key, Lead::SOURCES_SELF_OWNED, true) => 'Self-owned',
                default => 'Direct',
            };
            $nguonRows[] = [
                Lead::SOURCE_GROUP_CODES[$key] ?? strtoupper($key),
                $key,
                $label,
                Lead::SOURCE_PERMISSIONS[$key] ?? '—',
                $flow,
                Lead::isRecallableSource($key) ? '✅' : '❌',
            ];
        }
        $this->addSheet($spreadsheet, 'Nguồn', ['Mã', 'Key', 'Tên hiển thị', 'Permission', 'Luồng xử lý', 'Recall tự động'], $nguonRows);

        $this->addSheet($spreadsheet, 'Trường tùy biến', ['Key', 'Label', 'Loại', 'Options', 'Phòng ban', 'Bắt buộc', 'Active'],
            CustomField::with('orgUnit')->orderBy('org_unit_id')->orderBy('position')->get()
                ->map(function ($f) {
                    $opts = '—';
                    if (! empty($f->options)) {
                        $lines = [];
                        foreach ($f->options as $opt) {
                            $lbl = $f->optionLabel($opt);
                            $lines[] = ($lbl !== '' && $lbl !== $opt) ? "$opt = $lbl" : $opt;
                        }
                        $opts = implode(' | ', $lines);
                    }
                    return [$f->key, $f->label, $f->field_type, $opts, $f->orgUnit?->name ?? '(công ty)', $f->required ? '⚠' : '', $f->active ? '✅' : '⛔'];
                })->all());

        // Trường form 6 phase từ config (snapshot cứng).
        $phaseRows = [];
        foreach (config('lead_form_fields', []) as $pIdx => $phase) {
            foreach ($phase['groups'] as $groupName => $fields) {
                foreach ($fields as $f) {
                    $phaseRows[] = [
                        $pIdx,
                        $phase['title'],
                        $groupName,
                        $f['field'] ?? '',
                        $f['label'] ?? '',
                        $f['type'] ?? '',
                        is_bool($f['required'] ?? null) ? ($f['required'] ? '⚠' : '') : ($f['required'] ?? ''),
                        $f['options'] ?? '',
                        $f['note'] ?? '',
                    ];
                }
            }
        }
        $this->addSheet($spreadsheet, 'Trường form 6 phase', ['Phase', 'Phase title', 'Nhóm', 'Field', 'Nhãn', 'Type', 'Bắt buộc', 'Options', 'Ghi chú'], $phaseRows);

        // Kho lead: cây liền mạch, Chi nhánh HN → HCM → ĐN theo convention.
        $this->addSheet($spreadsheet, 'Kho lead (PoolUnit)', ['Cấp', 'Kind', 'Tên', 'Code', 'Path', 'Active'],
            $this->sortOrgTree(PoolUnit::orderBy('path')->get(), 'pool-')
                ->map(fn ($p) => [
                    $p->depth,
                    $p->kind,
                    str_repeat('    ', $p->depth) . ($p->depth === 0 ? '' : '└─ ') . $p->name,
                    $p->code,
                    $p->path,
                    $p->is_active ? '✅' : '⛔',
                ])->all());

        $spreadsheet->setActiveSheetIndex(0);
        return $spreadsheet;
    }

    /**
     * 2026-08-05 — Sort collection cây theo priority Chi nhánh HN → HCM → ĐN → ops.
     * Duyệt DFS từ root, subtree giữ nguyên thứ tự path bên trong.
     */
    private function sortOrgTree(\Illuminate\Support\Collection $all, string $codePrefix = ''): \Illuminate\Support\Collection
    {
        $priority = [
            $codePrefix . 'branch-hn'   => 1, 'branch-hn' => 1,
            $codePrefix . 'branch-hcm'  => 2, 'branch-hcm' => 2,
            $codePrefix . 'branch-dn'   => 3, 'branch-dn' => 3,
            $codePrefix . 'ops-monitor' => 9, 'ops-monitor' => 9,
        ];

        // groupBy key null → cast '' để tránh deprecation "Using null as an array offset".
        $byParent = $all->groupBy(fn ($n) => $n->parent_id === null ? '' : (string) $n->parent_id);
        $ordered = collect();
        $walk = function ($parentId) use (&$walk, $byParent, $priority, &$ordered) {
            $key = $parentId === null ? '' : (string) $parentId;
            $children = ($byParent[$key] ?? collect())
                ->sortBy(fn ($n) => ($priority[$n->code] ?? 50) . '-' . $n->name)
                ->values();
            foreach ($children as $c) {
                $ordered->push($c);
                $walk($c->id);
            }
        };
        $walk(null);
        return $ordered;
    }

    private function addSheet(Spreadsheet $ss, string $title, array $headers, array $rows): void
    {
        $sheet = $ss->createSheet();
        // Excel giới hạn tên sheet 31 ký tự.
        $sheet->setTitle(mb_substr($title, 0, 31));

        foreach ($headers as $i => $h) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($col . '1', $h);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle('A1:' . $lastCol . '1')->getFont()->setBold(true);
        $sheet->getStyle('A1:' . $lastCol . '1')->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFEEE9D6');
        $sheet->freezePane('A2');

        foreach ($rows as $r => $row) {
            foreach ($row as $c => $val) {
                $col = Coordinate::stringFromColumnIndex($c + 1);
                $sheet->setCellValue($col . ($r + 2), $val);
            }
        }
    }

    public function stream(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $ss = $this->build();
        $filename = 'catalog-' . now()->format('Ymd-His') . '.xlsx';
        return response()->streamDownload(function () use ($ss) {
            (new Xlsx($ss))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
