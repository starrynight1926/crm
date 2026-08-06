<?php

/**
 * Trường CORE của form Lead (⚡lead-form.blade.php) theo 6 phase.
 * Đây là snapshot thủ công dùng cho /admin/catalog tab "Trường thông tin KH".
 * Không dùng ở runtime form — chỉ để tra cứu / kiểm kê.
 *
 * Khi sửa form (thêm/bớt/rename trường) → cập nhật file này để catalog đồng bộ.
 */

return [
    1 => [
        'title' => 'Phase 1 — Tạo mới & Chia số',
        'groups' => [
            'Thông tin khách hàng' => [
                ['field' => 'name',            'label' => 'Họ tên',        'type' => 'text',    'required' => true,  'note' => 'Tên khách hàng (150 ký tự)'],
                ['field' => 'phone',           'label' => 'SĐT',           'type' => 'text',    'required' => true,  'note' => 'Auto-normalize 0XX / +84...'],
                ['field' => 'received_date',   'label' => 'Ngày thu thập', 'type' => 'date',    'required' => true,  'note' => 'Ngày lead vào hệ thống'],
                ['field' => 'sourceGroup',     'label' => 'Nhóm nguồn',    'type' => 'select',  'required' => true,  'options' => 'MKT | MKT_BR | BDM | BOD | SA | BA | WI', 'note' => 'Marketing / Marketing BR / BDM / BOD / Sale hẹn lại / BA / Walk-in'],
                ['field' => 'note',            'label' => 'NOTE',          'type' => 'textarea','required' => false, 'note' => 'Ghi chú thêm'],
            ],
            'Chia số (Phân phối) — nguồn MKT (trực page)' => [
                ['field' => 'mktMode', 'label' => 'Cách chia', 'type' => 'radio', 'required' => true, 'options' => 'auto = Tự động (UPS list) | pool = Chia về kho (theo quyền)', 'note' => '2026-08-05 mới thêm'],
            ],
            'Chia số (Phân phối) — CM/Admin' => [
                ['field' => 'poolCompanyMode', 'label' => 'Kho chung công ty', 'type' => 'checkbox', 'required' => false, 'note' => 'Tick = toàn công ty thấy'],
                ['field' => 'poolBranchId',    'label' => 'Chi nhánh',         'type' => 'select',  'required' => false, 'options' => 'PoolUnit kind=branch'],
                ['field' => 'poolFacilityId',  'label' => 'Địa điểm',          'type' => 'select',  'required' => false, 'options' => 'PoolUnit kind=facility (con của Chi nhánh)'],
                ['field' => 'poolDepartmentId','label' => 'Cơ sở',             'type' => 'select',  'required' => false, 'options' => 'PoolUnit kind=department (con của Địa điểm)'],
                ['field' => 'personId',        'label' => 'Nhân viên phụ trách', 'type' => 'search', 'required' => false, 'note' => 'Autocomplete user'],
                ['field' => 'skipRecall', 'label' => 'Không áp dụng luật thu hồi', 'type' => 'checkbox', 'required' => false, 'note' => 'mặc định áp: 1 ngày cần ghi nhận cuộc gọi, 3 ngày cần đủ phân loại + kết quả + đóng phase 2'],
            ],
        ],
    ],

    2 => [
        'title' => 'Phase 2 — Gọi điện + Trường bổ sung',
        'groups' => [
            'Ghi cuộc gọi' => [
                ['field' => 'newCallStatus', 'label' => 'Trạng thái gọi', 'type' => 'select',  'required' => true,  'options' => 'thanh_cong | khong_nghe | tu_choi | ...'],
                ['field' => 'newCallNote',   'label' => 'Ghi chú',        'type' => 'textarea','required' => false, 'note' => 'Max 1000 ký tự'],
            ],
            'Trường bổ sung (custom fields — dynamic)' => [
                ['field' => 'custom.*', 'label' => 'Theo cấu hình CustomField của phòng ban', 'type' => 'dynamic', 'required' => 'per field', 'note' => 'Xem bảng "Trường tùy biến" bên dưới'],
            ],
            'Trạng thái chăm sóc' => [
                ['field' => 'status_1',       'label' => 'Trạng thái 1',    'type' => 'text',   'required' => false],
                ['field' => 'status_2',       'label' => 'Trạng thái 2',    'type' => 'text',   'required' => false],
                ['field' => 'classification', 'label' => 'Phân loại',       'type' => 'select', 'required' => true, 'options' => 'new / potential / warm / cold / ...'],
                ['field' => 'bookingStatus',  'label' => 'Trạng thái booking', 'type' => 'select', 'required' => true, 'options' => 'not_booked / booked / rescheduled'],
            ],
            'Insight' => [
                ['field' => 'insight', 'label' => 'Insight khách', 'type' => 'textarea', 'required' => false],
                ['field' => 'link',    'label' => 'Link tham khảo', 'type' => 'text',   'required' => false],
                ['field' => 'region',  'label' => 'Khu vực',       'type' => 'text',   'required' => false],
            ],
        ],
    ],

    3 => [
        'title' => 'Phase 3 — Booking thăm khám',
        'groups' => [
            'Danh sách booking (readonly — list history)' => [
                ['field' => '—', 'label' => 'Load qua Lead->bookingLogs()', 'type' => 'list', 'required' => false, 'note' => 'Chờ duyệt lên đầu, rồi order theo scheduled_at desc'],
            ],
            'Thêm booking mới (chỉ Admin/Sale + lead.book_action)' => [
                ['field' => 'newBookingType',        'label' => 'Loại',             'type' => 'select',   'required' => true,  'options' => 'tham_kham | dich_vu'],
                ['field' => 'newBookingStatus',     'label' => 'Trạng thái',       'type' => 'readonly', 'required' => false, 'note' => 'Lock = "cho_xac_nhan"'],
                ['field' => 'newBookingDate',       'label' => 'Ngày hẹn',         'type' => 'date',     'required' => true],
                ['field' => 'newBookingFacilityId', 'label' => 'Cơ sở',            'type' => 'select',   'required' => true,  'options' => 'facilities (cây scrm)'],
                ['field' => 'newBookingRoomId',     'label' => 'Phòng',            'type' => 'select',   'required' => true,  'options' => 'sb_rooms lọc theo cơ sở'],
                ['field' => 'newBookingTime',       'label' => 'Khung giờ',        'type' => 'select',   'required' => true,  'options' => 'Load từ sb_khung_gio theo phòng+ngày'],
                ['field' => 'newBookingSbBacSiId',  'label' => 'Bác sĩ',           'type' => 'select',   'required' => false, 'options' => 'sb_bac_si lọc theo cơ sở'],
                ['field' => 'newBookingServiceId',  'label' => 'Dịch vụ',          'type' => 'select',   'required' => false, 'options' => 'sb_services lọc theo loại (tham_kham/dich_vu)'],
                ['field' => 'newBookingConsultantIds[]', 'label' => 'Chuyên viên tư vấn (auto UPS)', 'type' => 'auto', 'required' => true, 'note' => '2026-08-05: auto lấy từ UPS Sale list bucket A→B→C→OFF. Nút "+ Thêm CV" tăng slot.'],
                ['field' => 'newBookingSoLieuTrinh', 'label' => 'Số liệu trình',   'type' => 'text',     'required' => false, 'note' => 'VD: 1/10'],
                ['field' => 'newBookingSoLuongLo',  'label' => 'Số lượng lọ',      'type' => 'text',     'required' => false, 'note' => 'VD: 3'],
                ['field' => 'newBookingDungTichLo', 'label' => 'Dung tích lọ',    'type' => 'select',   'required' => false, 'options' => '8M | 10M | 16M | 20M | 450M | 1 LT | 2 LT'],
                ['field' => 'newBookingCoTuVan',    'label' => 'Có tư vấn',        'type' => 'checkbox', 'required' => false],
                ['field' => 'newBookingCoKhamCls',  'label' => 'Có thăm khám lâm sàng', 'type' => 'checkbox', 'required' => false],
                ['field' => 'newBookingKetHopMedical', 'label' => 'Kết hợp medical', 'type' => 'checkbox', 'required' => false],
                ['field' => 'newBookingNote',       'label' => 'Ghi chú',          'type' => 'textarea', 'required' => false, 'note' => 'Max 1000 ký tự'],
            ],
        ],
    ],

    4 => [
        'title' => 'Phase 4 — Check-in',
        'groups' => [
            'Info readonly (khách tới)' => [
                ['field' => '—', 'label' => 'Sale/lễ tân bấm nút "Check-in" khi khách tới. Không có form field.', 'type' => 'action', 'required' => false, 'note' => 'Perm cần: phase.close.checkin (Admin cơ sở / Lễ tân)'],
            ],
        ],
    ],

    5 => [
        'title' => 'Phase 5 — Bán hàng',
        'groups' => [
            'Điều trị (treatment_rows)' => [
                ['field' => 'treatmentRows[].performed_at',        'label' => 'Ngày thực hiện',   'type' => 'date',     'required' => false],
                ['field' => 'treatmentRows[].performing_doctor_id','label' => 'Bác sĩ thực hiện', 'type' => 'select',   'required' => false, 'options' => 'staff_members'],
                ['field' => 'treatmentRows[].quality_rating',      'label' => 'Đánh giá chất lượng', 'type' => 'textarea', 'required' => false, 'note' => 'Max 2000 ký tự'],
            ],
            'Upsell (upsell_rows)' => [
                ['field' => 'upsellRows[].service_id',      'label' => 'Dịch vụ upsell',   'type' => 'select', 'required' => true,  'options' => 'services'],
                ['field' => 'upsellRows[].staff_member_id', 'label' => 'Nhân viên bán',    'type' => 'select', 'required' => false, 'options' => 'staff_members'],
                ['field' => 'upsellRows[].amount',          'label' => 'Số tiền',          'type' => 'text',   'required' => true],
            ],
        ],
    ],

    6 => [
        'title' => 'Phase 6 — Sử dụng dịch vụ',
        'groups' => [
            'Chưa build' => [
                ['field' => '—', 'label' => 'Phase này chưa có form fields. Chỉ hiển thị readonly.', 'type' => '—', 'required' => false],
            ],
        ],
    ],
];
