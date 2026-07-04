<?php

/*
|--------------------------------------------------------------------------
| Demo staging — định nghĩa các NGUỒN upload (chỉnh tay thoải mái)
|--------------------------------------------------------------------------
|
| Mỗi nguồn = 1 mẫu file (1 dòng tiêu đề + data). Sinh ra từ file
| "Tổng hợp trường khách hàng". Cấu trúc mỗi nguồn:
|
|   'key'    => định danh nội bộ (không trùng, không dấu)
|   'name'   => tên hiển thị
|   'fields' => danh sách cột theo ĐÚNG THỨ TỰ trong file:
|                 'label'    => tên cột trong file (khớp để map)
|                 'required' => bắt buộc phải có giá trị (mặc định false)
|                 'role'     => (tùy chọn) gán cột này vào trường chung để
|                               lọc/báo cáo. Nhận: name | phone | source | date
|
| Muốn thêm/bớt trường, đổi bắt buộc, đổi nguồn nào là name/phone/source/date
| → sửa thẳng file này rồi upload lại. Không cần migrate.
|
| Validate tối thiểu: cột role=name và role=phone bắt buộc có giá trị,
| phone phải chuẩn hóa được về số VN hợp lệ (0XXXXXXXXX).
*/

return [

    // ── Nguồn 1 — Book lịch (form có email) ──────────────────────────────
    [
        'key'  => 'nguon_1',
        'name' => 'Nguồn 1 — Book lịch (có email)',
        'fields' => [
            ['label' => 'Dấu thời gian'],
            ['label' => 'Địa chỉ email'],
            ['label' => 'Bên Book'],
            ['label' => 'Lịch', 'role' => 'date'],
            ['label' => 'Giờ'],
            ['label' => 'Họ tên KH', 'required' => true, 'role' => 'name'],
            ['label' => 'Ngày tháng năm sinh'],
            ['label' => 'Số điện thoại', 'required' => true, 'role' => 'phone'],
            ['label' => 'Book'],
            ['label' => 'Đây có phải lần đầu khách tới không?'],
            ['label' => 'Chuyên Viên Tư Vấn'],
            ['label' => 'Nguồn', 'role' => 'source'],
            ['label' => 'Bác Sĩ'],
        ],
    ],

    // ── Nguồn 2 — Book lịch (điều dưỡng) ─────────────────────────────────
    [
        'key'  => 'nguon_2',
        'name' => 'Nguồn 2 — Book lịch (điều dưỡng)',
        'fields' => [
            ['label' => 'Dấu thời gian'],
            ['label' => 'BÊN BOOK'],
            ['label' => 'NGÀY ĐẶT LỊCH', 'role' => 'date'],
            ['label' => 'GIỜ'],
            ['label' => 'NGUỒN', 'role' => 'source'],
            ['label' => 'HỌ TÊN KH', 'required' => true, 'role' => 'name'],
            ['label' => 'SỐ ĐIỆN THOẠI', 'required' => true, 'role' => 'phone'],
            ['label' => 'SALE'],
            ['label' => 'LIỆU PHÁP'],
            ['label' => 'SỐ LỌ'],
            ['label' => 'ĐIỀU DƯỠNG'],
            ['label' => 'BÁC SĨ'],
            ['label' => 'KHÁCH TẶNG & GHI CHÚ'],
        ],
    ],

    // ── Nguồn 3 — Book lịch + tình trạng lịch ────────────────────────────
    [
        'key'  => 'nguon_3',
        'name' => 'Nguồn 3 — Book lịch (có tình trạng)',
        'fields' => [
            ['label' => 'Dấu thời gian'],
            ['label' => 'BÊN BOOK'],
            ['label' => 'NGÀY ĐẶT LỊCH', 'role' => 'date'],
            ['label' => 'GIỜ'],
            ['label' => 'NGUỒN', 'role' => 'source'],
            ['label' => 'HỌ TÊN KH', 'required' => true, 'role' => 'name'],
            ['label' => 'SỐ ĐIỆN THOẠI', 'required' => true, 'role' => 'phone'],
            ['label' => 'SALE'],
            ['label' => 'LIỆU PHÁP'],
            ['label' => 'SỐ LỌ'],
            ['label' => 'ĐIỀU DƯỠNG'],
            ['label' => 'BÁC SĨ'],
            ['label' => 'KHÁCH TẶNG & GHI CHÚ'],
            ['label' => 'TÌNH TRẠNG LỊCH'],
        ],
    ],

    // ── Nguồn 4 — Telesale / CRM chi tiết ────────────────────────────────
    [
        'key'  => 'nguon_4',
        'name' => 'Nguồn 4 — Telesale / CRM chi tiết',
        'fields' => [
            ['label' => 'STT'],
            ['label' => 'Ngày', 'role' => 'date'],
            ['label' => 'Tên', 'required' => true, 'role' => 'name'],
            ['label' => 'SĐT', 'required' => true, 'role' => 'phone'],
            ['label' => 'LP'],
            ['label' => 'Insight'],
            ['label' => 'Tên bài ADS'],
            ['label' => 'Link'],
            ['label' => 'Nguồn', 'role' => 'source'],
            ['label' => 'S.I.C'],
            ['label' => 'Cá nhân'],
            ['label' => 'Ngày gọi'],
            ['label' => 'Ghi nhận tình trạng'],
            ['label' => 'Bước tiếp theo'],
            ['label' => 'KHU VỰC'],
            ['label' => 'TỈNH'],
            ['label' => 'Độ tuổi'],
            ['label' => 'Phân loại'],
            ['label' => 'Kết quả'],
            ['label' => 'DEALS'],
            ['label' => 'Note thông tin'],
            ['label' => 'Recall'],
        ],
    ],

    // ── Nguồn 5 — Lead funnel đầy đủ ─────────────────────────────────────
    [
        'key'  => 'nguon_5',
        'name' => 'Nguồn 5 — Lead funnel đầy đủ',
        'fields' => [
            ['label' => 'Ngày', 'role' => 'date'],
            ['label' => 'PAGE'],
            ['label' => 'Tên', 'required' => true, 'role' => 'name'],
            ['label' => 'SĐT', 'required' => true, 'role' => 'phone'],
            ['label' => 'Camp'],
            ['label' => 'Insight'],
            ['label' => 'Link'],
            ['label' => 'Nguồn quảng cáo', 'role' => 'source'],
            ['label' => 'Người nhận LEAD'],
            ['label' => 'CHIA CHO'],
            ['label' => 'Ghi nhận tình trạng lần 1'],
            ['label' => 'Ghi nhận tình trạng lần 2'],
            ['label' => 'NOTE'],
            ['label' => 'KHU VỰC'],
            ['label' => 'PHÂN LOẠI'],
            ['label' => 'KẾT QUẢ'],
        ],
    ],

    // ── Nguồn 6 — Landing page form ──────────────────────────────────────
    [
        'key'  => 'nguon_6',
        'name' => 'Nguồn 6 — Landing page form',
        'fields' => [
            ['label' => 'Ngày', 'role' => 'date'],
            ['label' => 'Họ tên', 'required' => true, 'role' => 'name'],
            ['label' => 'Số điện thoại', 'required' => true, 'role' => 'phone'],
            ['label' => 'Link'],
            ['label' => 'IP'],
            ['label' => 'Nguồn', 'role' => 'source'],
        ],
    ],

];
