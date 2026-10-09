<?php

namespace Database\Seeders;

use App\Models\Facility;
use App\Models\StaffMember;
use Illuminate\Database\Seeder;

/**
 * Seed 3 cơ sở (Hà Nội / HCM / Đà Nẵng) + dept "Khối chuyên môn"
 * + nhân sự chuyên môn từ file "List nhân sự.xlsx" (đã lọc bỏ điều dưỡng).
 *
 * Idempotent theo (facility_id, name) — chạy lại không nhân đôi.
 * Format name: "Tên\n(Chức vụ)" — UI dropdown render 2 dòng qua CSS whitespace-pre-line.
 */
class RealDoctorsSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            'Hà Nội' => [
                ['Lê Tuyên Hồng Dương', 'Phó Tổng Giám đốc'],
                ['Nguyễn Tiến Dũng', 'Giám đốc chuyên môn - HN'],
                ['Hoàng Trà My', 'Bác sĩ chuyên khoa y học cổ truyền'],
                ['Ngọc Bình', 'Bác sĩ'],
                ['Đỗ Ngọc Việt', 'KTV Xét nghiệm'],
                ['Trương Thị Biên', 'Bác sĩ chuyên khoa Nội (đứng bằng 59 NTN)'],
                ['Trần Văn Quang', 'Phụ trách bộ phận chuyên môn khoa YHCT'],
                ['Nguyễn Thị Lan Anh', 'Tư vấn'],
                ['Nguyễn Thế Thịnh', 'Chuyên gia tư vấn'],
                ['Nguyễn Thị Thu Hồng', 'Khoa chẩn đoán hình ảnh'],
                ['Ngô Thị Ngà', 'Bác sĩ'],
                ['Trịnh Hòa Bình', 'Bác sĩ đọc kết quả X-Quang'],
                ['Đoàn Tuấn Vũ', 'Bác sĩ tim mạch đột quỵ'],
                ['Nguyễn Thị Tú', 'Kỹ thuật viên xét nghiệm'],
                ['Đỗ Đức Đông', 'Kỹ thuật viên X-Quang'],
                ['Nguyễn Thị Hiền', 'Thuê bằng Sản phụ khoa'],
                ['Nguyễn Thu Hằng', 'Bác sĩ da liễu'],
                ['Vũ Điên Biên', 'Bác sĩ tim mạch đột quỵ'],
                ['Nguyễn Văn Hoà', 'KTV Chẩn đoán hình ảnh'],
            ],
            'HCM' => [
                ['Hoàng Văn Đông', 'Bác sĩ'],
                ['Đặng Công Danh', 'Bác sĩ chuyên khoa y học cổ truyền'],
                ['Nguyễn Thành Tân', 'KTV Xét nghiệm'],
                ['Dương Đức Việt', 'Giám đốc chuyên môn - HCM'],
                ['Trần Văn Thủy', 'KTV Xét nghiệm (Đứng tên)'],
                ['Nguyễn Minh Cường', 'Y học cổ truyền (thuê bằng)'],
                ['Ngô Duy Đức', 'Bác sĩ Siêu âm'],
                ['Đàm Thúy Kiều', 'Training manager in beauty'],
                ['Phạm Thị Thùy', 'KTV Da liễu'],
                ['Lê Huy Thư', 'Bác sĩ da liễu'],
                ['Bạch Thị Thu Huyền', 'Bác sĩ chuyên khoa nội'],
            ],
            'Đà Nẵng' => [
                ['Nguyễn Thị Phượng', 'KTV Xét nghiệm'],
                ['Mai Tấn Mẫn', 'Giám đốc chuyên môn'],
            ],
        ];

        $totalStaff = 0;

        foreach ($data as $branchName => $people) {
            $root = Facility::firstOrCreate(
                ['name' => $branchName, 'parent_id' => null],
                ['active' => true]
            );

            $dept = Facility::firstOrCreate(
                ['name' => 'Khối chuyên môn', 'parent_id' => $root->id],
                ['active' => true]
            );

            foreach ($people as [$name, $title]) {
                StaffMember::updateOrCreate(
                    ['facility_id' => $dept->id, 'name' => $name],
                    ['title' => $title, 'role' => 'doctor', 'active' => true]
                );
                $totalStaff++;
            }
        }

        $this->command->info("Seeded {$totalStaff} nhân sự Khối chuyên môn vào 3 cơ sở.");
    }
}
