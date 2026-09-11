# Role Flow Test — hướng dẫn kiểm thử phân quyền + luồng lead

Mục tiêu: verify các role đang seed trong `OrgStaffSeeder` + `Phase66FlowSeeder` hoạt động đúng luồng **tạo → chia số → chuyển → cập nhật → thu hồi** cho lead.

## 0. Chuẩn bị

```bash
# reset DB (chỉ dùng khi cần fresh)
php artisan migrate:fresh --seed

# hoặc chỉ reseed org/role/user
php artisan db:seed --class='Database\Seeders\OrgStaffSeeder'
php artisan db:seed --class='Database\Seeders\Phase66FlowSeeder'
php artisan db:seed --class='Database\Seeders\OrgUnitManagerSeeder'
```

Mật khẩu mặc định: `123456` (admin: `admin@123`).

## 1. Test tự động (script PHP)

Chạy script bootstrap Laravel + gọi engine trực tiếp (không qua HTTP):

```bash
php scripts/role-flow-test.php
```

Script kỳ vọng in `✅ TẤT CẢ PASS (26)`. Nếu có `❌` → dừng, debug ngay case đó.

Test cases:

| # | Actor | Kịch bản | Kỳ vọng |
|---|---|---|---|
| 1 | `page1@` (Team trực page) | Kiểm tra perms: có `lead.create`, KHÔNG có `lead.view/update/distribute` | pass |
| 2 | `page1@` | Tạo lead mới (kho chung) | tạo ok |
| 3 | `cmbk@` (CM booking) | Có `lead.distribute` + `lead.recall` | pass |
| 4 | `cmbk@` | Chia lead trong team-giang-booking cho `book1@` | `owner_id = book1.id` |
| 5 | `book1@` (Team booking) | Chỉ có `lead.update`, KHÔNG có distribute/create | pass |
| 6 | `book1@` | Update `note` lead đang sở hữu | update ok |
| 7 | `cmsale@` (CM sale) | Có `lead.distribute` | pass |
| 8 | `cmsale@` | Chuyển lead từ team-giang-booking → team-hoi-sale | `org_unit_id` mới |
| 9 | `cmsale@` | Chia lead cho sale `thk@` | `owner_id = thk.id` |
| 10 | `thk@` (Sale) | Thấy lead mình sở hữu | `isVisibleTo = true` |
| 11 | `thk@` | KHÔNG thấy lead của sale khác (`nhg@`) — scope SELF | `isVisibleTo = false` |
| 12 | `huyently@` (Observer) | Có `lead.view`, không update/distribute | pass |
| 13 | `huyently@` | Thấy lead toàn công ty (scope company) | `isVisibleTo = true` |
| 14 | `admin@` | Có tất cả quyền lead + ops | pass |
| 15 | `cmbk@` | Recall lead khỏi `book1@` → về kho team | `owner_id = null`, `pool = team` |

## 2. Test tay qua browser

Server:
```bash
php artisan serve
# hoặc dùng preview_start "lara-scrm" trong Claude Code
```

### Luồng đúng — Team trực page tạo lead

1. Đăng nhập `page1@longevity.com.vn` / `123456`.
2. Menu chỉ có: **Dashboard**, **Khách hàng → Thêm khách hàng**.
3. Vào "Thêm khách hàng" → điền form → **Lưu**.
4. Kỳ vọng: redirect về `/leads/create` trống + flash "Đã tạo lead mới." (KHÔNG bị 403 khi sang `leads.show`).
5. Bấm "Hủy" trên form: redirect về `/dashboard` (KHÔNG 403).

### Luồng đúng — Chia số & cập nhật

1. Đăng nhập `cmbk@longevity.com.vn`. Menu: Dashboard, Khách hàng (Danh sách, Chia số), Vận hành (Báo cáo).
2. Vào **Khách hàng → Chia số** → chọn tab "Kho team" → chọn lead → **Chia** cho `book1@`.
3. Đăng xuất, đăng nhập `book1@`. Vào **Khách hàng → Danh sách** → thấy lead vừa nhận → mở → sửa note → **Lưu**.
4. Trở lại `cmbk@` → **Thu hồi** lead khỏi `book1@` → lead về kho team, `owner_id = null`.

### Luồng đúng — Handoff Booking → Sale

1. Đăng nhập `cmsale@`. Vào Chia số → chọn lead trong kho team booking → **Chuyển kho** sang `team-hoi-sale`.
2. Chia lead cho `thk@` (Sale). Verify `owner_id`.
3. Đăng nhập `thk@` → thấy lead mình → update trạng thái/note → Lưu.

### Luồng sai — kỳ vọng bị chặn

| Actor | Hành động | Kỳ vọng |
|---|---|---|
| `page1@` (Team trực page) | Vào URL `/leads` | **403** (thiếu `lead.view`) |
| `page1@` | Vào `/distribution/pools` | **403** |
| `book1@` (Team booking) | Vào `/leads/create` | **403** (thiếu `lead.create`) |
| `book1@` | Vào `/distribution/rules` | **403** (thiếu `rule.manage`) |
| `thk@` (Sale) | Vào chi tiết lead của `nhg@` (sale khác) | **403** (`isVisibleTo` false) |
| `huyently@` (Observer) | Click nút "Chia" trong kho | Button ẨN (canDistribute = false) |
| Bất kỳ user thường | Vào `/ops/rules` | **403** (thiếu `ops.manage`) |

### Filter dropdown "Chia số cho user"

Vào Chia số → dropdown "Chọn sale nhận" **không được** có: Admin, Manager, CM booking, CM sale, Team Leader, DM, Team trực page (họ là distributor/uploader), Observer (chỉ xem).
Chỉ có: **Sale**, **Team booking**, `NV Kinh Doanh`, `NV Marketing`, các HC/SHC.

## 3. Reset DB test data

```bash
# script tự động cleanup lead có phone 099TEST*
php scripts/role-flow-test.php
```

Script luôn `forceDelete` lead có phone `099TEST%` trước khi chạy → chạy lại được nhiều lần.

## 4. Cấu trúc org sau seed

```
Công ty
├── Cơ sở Hà Nội
│   ├── Marketing
│   │   ├── Team Trần Thị Thu Giang (manager: Giang)
│   │   │   ├── Team Trực Page      ← Phạm Trực Page 1
│   │   │   ├── Team Booking        ← CM Booking, Nguyễn Booking 1
│   │   │   └── Team Sale           ← Team Hợi sale members
│   │   └── Team Tạ Văn Hợi (manager: Hợi)
│   │       ├── Team Trực Page
│   │       ├── Team Booking
│   │       └── Team Sale
│   └── BDM
├── Cơ sở HCM (manager: Trần Nguyễn Kim Ngân — DM)
│   └── Marketing → Team Ms. Ashley (managers: Trâm, Lan)
│       ├── Team Booking
│       └── Team Sale (manager: Quỳn)
├── Cơ sở Đà Nẵng
│   └── Marketing (manager: Phấn)
└── Vận hành & Giám sát
    ├── Nhóm Vận Hành (manager: Bảo)
    └── Nhóm Giám Sát (manager: Tú)
```

## 5. Ma trận role x permission

Xem trực tiếp trong DB:

```bash
php artisan tinker --execute="
foreach(\App\Models\Role::orderBy('name')->get() as \$r) {
  echo str_pad(\$r->name,25).' | '.\$r->permissions->pluck('key')->implode(', ').PHP_EOL;
}"
```

Tóm tắt semantic:

| Nhóm | Role | Permission chính |
|---|---|---|
| Toàn bộ | Admin, DM HCM | `*` |
| Chỉ thêm khách | Team trực page | `lead.create` |
| Chia số + cập nhật | CM booking, CM sale, Manager, Team Leader | `lead.distribute*`, `recall`, `update`, `view*` |
| Cập nhật | Sale, Team booking | `lead.update`, `view*` |
| Xem + báo cáo | Observer, Trợ lý kinh doanh | `lead.view`, `report.view*` |
