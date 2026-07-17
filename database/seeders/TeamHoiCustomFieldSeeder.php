<?php

namespace Database\Seeders;

use App\Models\CustomField;
use App\Models\OrgUnit;
use Illuminate\Database\Seeder;

/**
 * Trường tùy biến của Team Hợi (nhánh Marketing) — dựng từ file
 * "Data team Hợi (tách 1).xlsx". Team Hợi = "Team (Tạ Văn Hợi)" thuộc Marketing.
 *
 * Cột file gốc đã có field chuẩn trong `leads` (Ngày, PAGE, Tên, SĐT, CAMP,
 * Insight, Link, Nguồn, CHIA CHO, tình trạng 1/2, NOTE, KHU VỰC) → không tạo lại.
 * 3 cột không có field chuẩn → trường tùy biến:
 *   - Phân loại (P), Kết quả (Q): select, bắt buộc.
 *   - S.I.C (J): select.
 * import_code để trùng tên cột gốc cho import Excel tự khớp header.
 */
class TeamHoiCustomFieldSeeder extends Seeder
{
    public function run(): void
    {
        // Team Hợi HN nằm sẵn trong OrgStaffSeeder (code team-hoi-hn).
        $teamHoi = OrgUnit::firstWhere('code', 'team-hoi-hn');
        if (! $teamHoi) {
            $this->command?->error('Không tìm thấy Team Hợi (code=team-hoi-hn). Chạy OrgStaffSeeder trước.');
            return;
        }

        $fields = [
            [
                'key' => 'phan_loai',
                'import_code' => 'Phân loại',
                'label' => 'Phân loại',
                'field_type' => 'select',
                'options' => ['Quan tâm', 'Tìm hiểu', 'Không nhu cầu', 'KLLD', 'Tài chính yếu', 'Gọi lại sau', 'Nét', 'Tham khảo', 'Bệnh nặng, sai tệp'],
                'required' => true,
                'position' => 1,
            ],
            [
                'key' => 'ket_qua',
                'import_code' => 'Kết quả',
                'label' => 'Kết quả',
                'field_type' => 'select',
                'options' => ['Missed', 'Follow', 'Booking', 'Show', 'Close'],
                'required' => true,
                'position' => 2,
            ],
            [
                'key' => 'sic',
                'import_code' => 'S.I.C',
                'label' => 'S.I.C',
                'field_type' => 'select',
                'options' => ['Hợi'],
                'required' => false,
                'position' => 3,
            ],
        ];

        foreach ($fields as $f) {
            CustomField::updateOrCreate(
                ['org_unit_id' => $teamHoi->id, 'key' => $f['key']],
                array_merge($f, [
                    'org_unit_id' => $teamHoi->id,
                    'affects_code' => false,
                    'active' => true,
                    'status' => CustomField::STATUS_ACTIVE,
                ])
            );
        }

        $this->seedTemplate($teamHoi->id);

        $this->command?->info("Seeded 3 trường tùy biến + mẫu báo cáo vào Team Hợi (org_unit_id={$teamHoi->id}).");
    }

    /**
     * 2 mẫu báo cáo demo của Team Hợi (khớp file gốc):
     *  1) "Thống kê theo funnel": bảng tổng, 7 Phân loại (bỏ "Không nhu cầu" & "Bệnh nặng, sai tệp") + 5 Kết quả.
     *  2) "Thống kê theo người": bảng theo người phụ trách, cột Nét + Follow/Booking/Show/Close.
     */
    private function seedTemplate(int $orgId): void
    {
        $phan = CustomField::where('org_unit_id', $orgId)->where('key', 'phan_loai')->first();
        $ket = CustomField::where('org_unit_id', $orgId)->where('key', 'ket_qua')->first();
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
