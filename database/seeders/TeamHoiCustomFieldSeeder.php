<?php

namespace Database\Seeders;

use App\Models\CustomField;
use App\Models\OrgUnit;
use Illuminate\Database\Seeder;

/**
 * Trường tùy biến — dựng từ file "Data team Hợi (tách 1).xlsx".
 *
 * 2026-08-10: Refactor scope
 *   - Phân loại + Kết quả: đưa lên CẤP CÔNG TY (org_unit_id=null) — dùng chung mọi team.
 *   - S.I.C: giữ ở Team Hợi (chỉ team này dùng).
 *
 * Cột file gốc đã có field chuẩn trong `leads` (Ngày, PAGE, Tên, SĐT, CAMP,
 * Insight, Link, Nguồn, CHIA CHO, tình trạng 1/2, NOTE, KHU VỰC) → không tạo lại.
 */
class TeamHoiCustomFieldSeeder extends Seeder
{
    public function run(): void
    {
        // ---- Cấp công ty: Phân loại + Kết quả ----
        $companyFields = [
            [
                'key' => 'phan_loai',
                'import_code' => 'Phân loại',
                'label' => 'Phân loại',
                'field_type' => 'select',
                'options' => ['Quan tâm', 'Tìm hiểu', 'Không nhu cầu', 'KLLD', 'Tài chính yếu', 'Gọi lại sau', 'Nét', 'Tham khảo', 'Bệnh nặng, sai tệp'],
                'required' => false,
                'position' => 10,
            ],
            [
                'key' => 'ket_qua',
                'import_code' => 'Kết quả',
                'label' => 'Kết quả',
                'field_type' => 'select',
                'options' => ['Missed', 'Follow', 'Booking', 'Show', 'Close'],
                'required' => false,
                'position' => 11,
            ],
        ];
        foreach ($companyFields as $f) {
            CustomField::updateOrCreate(
                ['org_unit_id' => null, 'key' => $f['key']],
                array_merge($f, [
                    'org_unit_id' => null,
                    'affects_code' => false,
                    'active' => true,
                    'status' => CustomField::STATUS_ACTIVE,
                ])
            );
        }

        // ---- Team Hợi HN: chỉ giữ S.I.C ----
        $teamHoi = OrgUnit::firstWhere('code', 'team-hoi-hn');
        if (! $teamHoi) {
            $this->command?->warn('Không tìm thấy Team Hợi (code=team-hoi-hn) — bỏ qua S.I.C.');
            return;
        }

        // Xoá phan_loai + ket_qua cũ ở Team Hợi (đã chuyển lên company).
        CustomField::where('org_unit_id', $teamHoi->id)
            ->whereIn('key', ['phan_loai', 'ket_qua'])
            ->get()->each->delete();

        CustomField::updateOrCreate(
            ['org_unit_id' => $teamHoi->id, 'key' => 'sic'],
            [
                'org_unit_id' => $teamHoi->id,
                'import_code' => 'S.I.C',
                'label' => 'S.I.C',
                'field_type' => 'select',
                'options' => ['Hợi'],
                'required' => false,
                'position' => 3,
                'affects_code' => false,
                'active' => true,
                'status' => CustomField::STATUS_ACTIVE,
            ]
        );

        $this->seedTemplate($teamHoi->id);

        $this->command?->info("Seeded Phân loại + Kết quả ở cấp công ty, S.I.C ở Team Hợi (org_unit_id={$teamHoi->id}).");
    }

    /**
     * 2 mẫu báo cáo demo của Team Hợi (khớp file gốc):
     *  1) "Thống kê theo funnel": bảng tổng, 7 Phân loại (bỏ "Không nhu cầu" & "Bệnh nặng, sai tệp") + 5 Kết quả.
     *  2) "Thống kê theo người": bảng theo người phụ trách, cột Nét + Follow/Booking/Show/Close.
     */
    private function seedTemplate(int $orgId): void
    {
        // 2026-08-10: phan_loai + ket_qua giờ ở cấp công ty (org_unit_id=null).
        $phan = CustomField::whereNull('org_unit_id')->where('key', 'phan_loai')->first();
        $ket = CustomField::whereNull('org_unit_id')->where('key', 'ket_qua')->first();
        if (! $phan || ! $ket) {
            return;
        }

        // Dọn mẫu demo tên cũ (đã tách thành 2 mẫu bên dưới).
        \App\Models\ReportTemplate::where('org_unit_id', $orgId)->where('name', 'Funnel team Hợi')->delete();

        \App\Models\ReportTemplate::updateOrCreate(
            ['org_unit_id' => $orgId, 'name' => 'Thống kê theo funnel'],
            ['config' => [
                'columns' => [
                    ['field_id' => $phan->id, 'type' => 'select', 'options' => [
                        'Quan tâm', 'Tìm hiểu', 'KLLD', 'Tài chính yếu', 'Gọi lại sau', 'Nét', 'Tham khảo',
                    ]],
                    ['field_id' => $ket->id, 'type' => 'select', 'options' => [
                        'Missed', 'Follow', 'Booking', 'Show', 'Close',
                    ]],
                ],
                'views' => ['totals' => true, 'by_owner' => false],
            ]]
        );

        \App\Models\ReportTemplate::updateOrCreate(
            ['org_unit_id' => $orgId, 'name' => 'Thống kê theo người'],
            ['config' => [
                'columns' => [
                    ['field_id' => $phan->id, 'type' => 'select', 'options' => ['Nét']],
                    ['field_id' => $ket->id, 'type' => 'select', 'options' => ['Follow', 'Booking', 'Show', 'Close']],
                ],
                'views' => ['totals' => false, 'by_owner' => true],
            ]]
        );
    }
}
