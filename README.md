# Lara-SCRM (Data Source)

CRM cho hệ thống Longevity Medical — quản lý lead từ nhiều nguồn (Marketing/BDM/BOD/SA/BA/Walk-in), phân phối theo UPS List, đồng bộ 2 chiều với `lara-sbooking`.

Xem chi tiết thiết kế: [`scope.md`](scope.md) · ERD: [`ERD.md`](ERD.md) · Kế hoạch phase: [`plan.md`](plan.md) · Nhật ký: [`result.md`](result.md) · Danh sách trường: [`fields-spec.md`](fields-spec.md) / [`fields-spec.xlsx`](fields-spec.xlsx)

## Stack

- Laravel 12 + Sanctum (API token)
- Blade + Livewire 3 + Alpine.js (không npm — Livewire bundle sẵn Alpine, không load CDN riêng)
- Laravel Reverb (WebSocket real-time)
- 2 DB: `mysql` (clean, default) + `pgsql` (raw ingest / import batch)
- Queue: database (dev cần `php artisan queue:work`)

## Cài đặt lần đầu (dev)

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Sửa `.env`:

```env
DB_DATABASE=lara_datasource
PG_DATABASE=lara_datasource_raw
BOOKING_API_URL=http://lara-sbooking.test:81/api
BOOKING_API_TOKEN=<shared secret giữa 2 hệ>
SCRM_API_TOKEN=<cùng chuỗi trên — dùng cho callback 2 chiều>
REVERB_APP_KEY=... REVERB_APP_SECRET=... REVERB_APP_ID=...
```

```bash
php artisan migrate:fresh --seed   # tạo DB + seed role/permission/PoolUnit/StaffMember/CustomField
php artisan sb:sync-services       # kéo services từ sbooking
php artisan sb:sync-rooms
php artisan sb:sync-bac-si
php artisan sb:sync-users          # kéo users sbooking + auto-map users.sbooking_user_id
```

## Chạy dev

```bash
php artisan serve --port=81        # hoặc dùng Laragon virtual host lara-datasource.test:81
php artisan queue:work             # xử lý raw → clean pipeline
php artisan reverb:start           # WebSocket cho real-time toast + refresh Livewire
```

## Đăng nhập nhanh

Password mặc định theo prefix email (xem `App\Support\DefaultPassword`):

| Email prefix | Mật khẩu | Cơ sở |
|---|---|---|
| `admin@...` | `59ntn` | Superadmin |
| `hn.*` / `admin.hn` | `59@ntn` | HN (59 Ngô Thì Nhậm) |
| `hcm.*` / `admin.hcm` | `207@nvt` | HCM (207 Nguyễn Văn Thủ) |
| `dn.*` / `admin.dn` | `23@tdn` | ĐN (Lô 2+3 Trần Đăng Ninh) |
| `vh.*` | `59ntn` | Vận hành |

## Sync 2 chiều với sbooking

### scrm → sbooking (push)

- Tạo booking mới ở scrm → auto `POST /api/bookings` sang sbooking (gồm `sale_id`, `tiep_don_user_id`, `khung_gio_id`, `dich_vu_id`, `phong_id`, `bac_si_id`).
- Sửa booking → `PUT /api/bookings/{id}`.
- Comment → `POST /api/bookings/{id}/comments`.

Xem [`app/Services/SbookingClient.php`](app/Services/SbookingClient.php).

### sbooking → scrm (callback)

Sbooking gọi `POST /api/leads/{code}/booking-event` với `type = status | comment | edit | delete`. Auth Bearer = `SCRM_API_TOKEN` (env).

Sự kiện quan trọng:
- Khách check-in (`da_toi`) → auto `pickGreet` sale từ Sale Tiếp Đón (A→B→C→OFF) + `markBusy`.
- Sale bấm "Đang tiếp đón" bên sbooking → `POST /api/ups/busy` → `markBusy`.
- Sale bấm "Hoàn tất" → `POST /api/ups/complete` → `markFree`.

Xem [`app/Http/Controllers/Api/BookingEventController.php`](app/Http/Controllers/Api/BookingEventController.php), [`app/Http/Controllers/Api/UpsAttendanceController.php`](app/Http/Controllers/Api/UpsAttendanceController.php).

### Reconcile drift

Backfill dữ liệu cũ bị lệch:

```bash
php artisan sb:reconcile-bookings --dry-run   # xem trước
php artisan sb:reconcile-bookings              # apply
php artisan sb:reconcile-bookings --since=2026-08-01
```

## UPS System (Ưu tiên phân số)

- **Config**: mỗi cơ sở 1 `UpsConfig.cutoff_time` (VD 08:36). Sale check-in sau mốc → auto vào `OFF`.
- **Chốt UPS ngày**: BO/CM bấm "Chốt UPS hôm nay" → mở khoá Phase 1 chia số. Trước khi chốt, mọi thao tác chia lead nguồn MKT bị chặn.
- **Bucket**: `A` / `B` / `C` / `OFF` (Offlist — không nhận số hôm nay, ≠ nghỉ làm) / `MKT` (TM Team).
- **Auto-chia**:
   - Phase 1 (MKT): trực page up lead nguồn MKT → `UpsDispatcher::pickMkt` → auto assign sale từ MKT List.
   - Phase 4 (Sale Tiếp Đón): callback `da_toi` từ sbooking → `pickGreet` chọn từ A→B→C→OFF.

## Cấu trúc phase (Customer Flow)

1. Tạo mới & Chia số · 2. Gọi điện · 3. Booking · 4. Check-in · 5. Sales · 6. Sau bán/Chăm sóc

## Commands có sẵn

```bash
php artisan sb:sync-services
php artisan sb:sync-rooms
php artisan sb:sync-bac-si
php artisan sb:sync-users               # kèm auto-map sbooking_user_id
php artisan sb:reconcile-bookings       # backfill drift
```

## Quy ước code

- **Không nạp Alpine.js qua CDN riêng** — Livewire đã bundle sẵn Alpine; nạp 2 instance làm `wire:click` chập chờn (fix Phase 3, xem `layouts/base.blade.php`).
- Component Livewire dùng syntax mới `#[On('event')]` (Livewire 3).
- Broadcast event: implement `ShouldBroadcast`; client listen qua `window.EchoClient.channel('...').listen('.App\\Events\\...')`.
- Không tự cài `phpspreadsheet` / `laravel-excel` — dùng script Python (openpyxl) qua skill khi cần xuất Excel offline.

## Tài liệu tham chiếu

- [scope.md](scope.md) — thiết kế tổng quan
- [ERD.md](ERD.md) — 2 DB chi tiết
- [plan.md](plan.md) — 8 phase, làm theo thứ tự
- [result.md](result.md) — nhật ký từng phase
- [fields-spec.md](fields-spec.md) / [fields-spec.xlsx](fields-spec.xlsx) — bảng trường dữ liệu (trình quản lý duyệt)
- [QA_CHECKLIST.md](QA_CHECKLIST.md) — checklist test tay trước release
- [plan-integration-sbooking.md](plan-integration-sbooking.md) — thiết kế 2 hệ scrm ↔ sbooking
- [CLAUDE.md](CLAUDE.md) — hướng dẫn AI collaboration
