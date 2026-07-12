<?php

namespace Database\Seeders;

use App\Models\CustomField;
use App\Models\Facility;
use App\Models\StaffMember;
use Illuminate\Database\Seeder;

class ReportCustomFieldSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCustomFields();
        $this->seedFacilitiesAndStaff();
    }

    private function seedCustomFields(): void
    {
        $fields = [
            [
                'key' => 'ngay_sinh',
                'label' => 'Ngày sinh',
                'field_type' => 'date',
                'required' => false,
                'position' => 100,
            ],
            [
                'key' => 'dia_chi',
                'label' => 'Địa chỉ',
                'field_type' => 'text',
                'required' => false,
                'position' => 101,
            ],
            [
                'key' => 'tien_su',
                'label' => 'Khai thác tiền sử',
                'field_type' => 'text',
                'required' => false,
                'position' => 102,
            ],
            [
                'key' => 'nghe_nghiep',
                'label' => 'Nghề nghiệp',
                'field_type' => 'text',
                'required' => false,
                'position' => 103,
            ],
            [
                'key' => 'phan_loai_kh',
                'label' => 'Phân loại khách',
                'field_type' => 'select',
                'options' => ['VIP', 'Tiềm năng', 'Mới', 'Cũ'],
                'required' => false,
                'position' => 104,
            ],
        ];

        foreach ($fields as $f) {
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

        $this->command?->info('Seeded 5 custom fields mức công ty cho báo cáo.');
    }

    private function seedFacilitiesAndStaff(): void
    {
        $data = [
            'Cơ sở 1 — Quận 1' => [
                'departments' => ['Phòng Da liễu', 'Phòng Thẩm mỹ'],
                'staff' => [
                    ['name' => 'BS. Nguyễn Văn A', 'dept' => 'Phòng Da liễu', 'role' => 'doctor'],
                    ['name' => 'BS. Trần Thị B', 'dept' => 'Phòng Thẩm mỹ', 'role' => 'doctor'],
                    ['name' => 'CV. Lê Văn C', 'dept' => 'Phòng Da liễu', 'role' => 'consultant'],
                    ['name' => 'CV. Phạm Thị D', 'dept' => 'Phòng Thẩm mỹ', 'role' => 'consultant'],
                ],
            ],
            'Cơ sở 2 — Quận 7' => [
                'departments' => ['Phòng Nha khoa', 'Phòng Da liễu'],
                'staff' => [
                    ['name' => 'BS. Hoàng Văn E', 'dept' => 'Phòng Nha khoa', 'role' => 'doctor'],
                    ['name' => 'BS. Vũ Thị F', 'dept' => 'Phòng Da liễu', 'role' => 'doctor'],
                    ['name' => 'CV. Đặng Văn G', 'dept' => 'Phòng Nha khoa', 'role' => 'consultant'],
                ],
            ],
        ];

        foreach ($data as $facilityName => $info) {
            $facility = Facility::updateOrCreate(
                ['name' => $facilityName, 'parent_id' => null],
                ['active' => true]
            );

            $deptMap = [];
            foreach ($info['departments'] as $deptName) {
                $dept = Facility::updateOrCreate(
                    ['name' => $deptName, 'parent_id' => $facility->id],
                    ['active' => true]
                );
                $deptMap[$deptName] = $dept;
            }

            foreach ($info['staff'] as $s) {
                StaffMember::updateOrCreate(
                    ['name' => $s['name'], 'facility_id' => $deptMap[$s['dept']]->id],
                    ['role' => $s['role'], 'active' => true]
                );
            }
        }

        $this->command?->info('Seeded 2 cơ sở demo + phòng ban + nhân sự.');
    }
}
