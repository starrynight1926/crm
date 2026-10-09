# Tích hợp lara-scrm ↔ lara-sbooking — Luồng hiện tại

> Cập nhật 2026-08-16 (branch `fifteenth` cả 2 repo). Trước đó file này là plan A→D (2026-08-01) đã lỗi thời — nay viết lại theo đúng code hiện chạy.
> Đọc kèm: `scope.md` §8, `ERD.md` (booking_logs, booking_log_consultants), `result.md` §6.21, §6.25, B1–B5.
> 2 repo: `F:/Laragon/www/lara-scrm` + `F:/Laragon/www/lara-sbooking`.

---

## 1. Kiến trúc chung

- 2 hệ, **2 DB riêng**, giao tiếp qua **HTTP JSON + Bearer token**.
- Token dùng chung: `users.api_token` — token của user nào thì hành động ghi log dưới tên user đó.
- URL + token cấu hình qua UI, **không sửa `.env` tay**:
  - SCRM: `Thiết lập → Kết nối Booking` → lưu `sbooking_api_url`, `sbooking_api_token` (encrypted trong `app_settings`, fallback `env('BOOKING_API_URL/TOKEN')`).
  - Sbooking: `Thiết lập → Kết nối SCRM` → lưu `scrm_api_url`, `scrm_api_token` (encrypted), thêm `scrm_callback_hosts` whitelist.
- Master data (BS / phòng / dịch vụ / user / khung giờ): **sbooking là master**, SCRM pull về mirror tables (`sb_bac_si`, `sb_rooms`, `sb_services`, `sb_users`) — KHÔNG đồng bộ 2 chiều.

---

## 2. Luồng scrm → sbooking (`App\Services\SbookingClient`)

### 2.1 Push tạo booking — `POST /api/bookings`
Trigger: user bấm **Ghi nhận booking** ở Phase 4 form lead-form (`⚡lead-form.blade.php`), status = `da_xac_nhan`.

Payload chính (xem `SbookingClient::pushBooking`):
- Khách: `so_dien_thoai`, `ho_ten`, `crm_khach_ma` (= `leads.code`)
- Slot: `co_so_id` (walk parent chain lấy `sbooking_co_so_id`), `phong_id` (`sb_phong_id`), `bac_si_id` (`sb_bac_si_id`), `khung_gio_id` (`sb_khung_gio_id`), `ngay_dat`, `gio_thuc_hien`, `gio_ket_thuc`
- Dịch vụ: `dich_vu_id` — ưu tiên `sb_dich_vu_id` do form lưu, fallback map theo tên
- Loại lịch: `loai_dat_lich` = `tham_kham→phong_kham` hoặc `dich_vu`
- Sale: `sale_id` = `tiep_don_user_id` = CV#1 (map qua `users.sbooking_user_id`)
- Nguồn: `nguon` = `lead.source_group` (mkt/mkt_br/bdm/bod/sa/ba/wi/hl), fallback `SCRM`
- Extra: `so_lieu_trinh`, `so_luong_lo`, `dung_tich_lo`, `ket_hop_medical`, `co_tu_van`, `co_kham_cls`, `ghi_chu`

**Sbooking xử lý** (`BookingApiController::store`):
- Guard capacity **trước khi lưu**:
  - Phòng đầy → `409 room_full`
  - BS + DV + khung giờ đầy → `422 bs_capacity`
- Nếu có `crm_khach_ma` → **luôn** vào `cho_duyet` (kể cả `phong_kham`) để admin sbooking review capacity. Booking tạo trực tiếp bên sbooking thì `phong_kham` vẫn auto-duyệt.
- Trả về `{id, ma_booking, khach_hang_id, trang_thai}` → SCRM lưu `sbooking_booking_id`, `sbooking_booking_ma`, `sync_status=synced`.
- Fail → SCRM `markFailed(sync_error)`, hiển thị lỗi trên form Phase 4.

Nếu `booking_log.note` != null → SCRM tự chain `pushComment` ngay sau đó (để hiện trong tab "Trạng thái lịch hẹn" bên sbooking).

### 2.2 Push sửa booking — `PUT /api/bookings/{id}`
Trigger: user sửa booking bên SCRM (đổi giờ / phòng / BS / DV / note / sale). Method `SbookingClient::pushBookingUpdate`.

Sbooking `BookingApiController::update`:
- Nếu đổi slot → re-check capacity phòng (`409 room_full` nếu conflict).
- Cho phép nhận `trang_thai=huy` + `ly_do_huy` → chuyển thành `ly_do_tu_choi = "Auto-hủy 15': ..."` (dùng chung cột).

### 2.3 Push comment — `POST /api/bookings/{id}/comments`
Mỗi comment bên SCRM đi thẳng qua sbooking, prefix `[Hệ thống Data · {user}]`.

### 2.4 Auto-hủy khách trễ 15' — SCRM chủ động
`php artisan bookings:auto-cancel-late` — schedule **every 5 minutes** (`routes/console.php`).

Rule (`AutoCancelLateBookings`):
- Booking `STATUS_DA_XAC_NHAN` + `scheduled_at + 15' ≤ now` + `sync_status ∉ (checkedin, done, canceled)` → hủy.
- SCRM: set `STATUS_HUY_DOI_LICH`, `sync_status=canceled`, `sync_error="Auto-hủy: khách trễ quá 15 phút chưa tới."`, `lead.booking_status = BOOKING_KHACH_HUY`, log status.
- Push sang sbooking bằng `pushBookingUpdate` với `trang_thai=huy` → sbooking mark booking hủy.

---

## 3. Luồng sbooking → scrm (`App\Services\CrmPushService`)

Endpoint SCRM nhận: `POST /api/leads/{code}/booking-event` — controller `Api\BookingEventController`.

Guard: nếu `type` ∈ (`status`, `delete`) và có `sbooking_booking_id` → phải khớp `BookingLog` của lead đó, không thì trả `409`.

### 3.1 Push status (nhân viên tiếp đón bấm nút)
Nút bên sbooking gồm 4 trạng thái: **Khách đã tới / Khách tới trễ / Khách hủy / Đã xong**.

Sbooking gửi `type=status` + `trang_thai_khach` (`da_toi|toi_tre|huy`) hoặc `trang_thai=da_xong`.

SCRM ưu tiên: `da_xong > khách_huy > toi_tre > da_toi > booked`. Cập nhật `lead.booking_status`, log status, `last_care_at=now`.

Cases đặc biệt:
- `da_xong` + `is_first_visit=true` → auto set `is_first_visit=false` (khách quay lại).
- Chuyển `toi_tre → da_toi` → xóa `BookingLateLog` cũ.
- `da_toi` → **UPS auto-chia sale tiếp đón**: pick sale từ list A→B→C→OFF ở cơ sở đó, mark busy, broadcast realtime `BookingStatusSynced`, `UpsSaleAssigned`. **Bỏ qua** nếu chưa chốt UPS hôm nay (`UpsDailyConfirm`).

### 3.2 Push edit / comment / delete / validation_error
- `type=edit` + `summary` (VD "Đổi giờ 09:00 → 10:30") → SCRM ghi note vào `lead_status_logs`.
- `type=comment` + `comment` → SCRM append vào comment stream.
- `type=delete` → SCRM đánh dấu BookingLog hủy.
- `type=validation_error` (409/422 lúc admin sbooking duyệt fail) → SCRM show flash message cho sale biết.

### 3.3 UPS attendance — Sale Tiếp Đón toggle busy/free
Sbooking `CrmPushService::pushUpsAttendance` gọi:
- `POST /api/ups/busy` — sale bấm "Đang tiếp đón" (khách tới) → SCRM mark sale busy trong `daily_attendance`.
- `POST /api/ups/complete` — sale bấm "Hoàn tất" → mark free lại.
- `POST /api/ups/pause` / `/api/ups/resume` — tạm dừng / tiếp tục nhận khách trong ca.

Controller: `Api\UpsAttendanceController`.

### 3.4 Auto-hủy `cho_duyet` quá 10' — sbooking chủ động
`php artisan bookings:auto-cancel-overdue --minutes=10` — schedule every 5', `withoutOverlapping`.
Booking `cho_duyet` nếu quá 10' sau giờ hẹn admin chưa duyệt → tự hủy phía sbooking (không push ngược về SCRM ở version hiện tại).

---

## 4. Sync master data (SCRM pull từ sbooking)

Commands (chạy tay hoặc cron):
- `sync:bacsi-from-sbooking`
- `sync:rooms-from-sbooking`
- `sync:services-from-sbooking`
- `sync:users-from-sbooking`
- `reconcile:bookings-from-sbooking` — recon booking bị lệch trạng thái.

Endpoint bên sbooking (middleware `scrm.token`):
- `GET /api/sync/{dich-vu, users, bac-si, phong, khung-gio}`

Model mirror bên SCRM (**read-only**, chỉ để dropdown & mapping):
- `SbBacSi` — bảng `sb_bac_si` (khóa `sbooking_id`)
- `SbRoom` — `sb_rooms`
- `SbService` — `sb_services`
- `SbUser` — `sb_users` (dùng để hiện danh sách sale tiếp đón khi form Phase 4)

Mapping ID lưu ở tài liệu gốc SCRM:
- `facilities.sbooking_co_so_id`
- `users.sbooking_user_id`
- `booking_logs.sb_bac_si_id / sb_phong_id / sb_dich_vu_id / sb_khung_gio_id`

---

## 5. Rule "duyệt lịch" — ai duyệt cái gì

**Từ 2026-08-16: MỌI booking mới đều `cho_duyet`, phải Admin vận hành sbooking bấm duyệt.** Không còn auto-duyệt cho bất kỳ loại nào.

| Nguồn tạo | `loai_dat_lich` | Trạng thái ban đầu | Ai duyệt |
|---|---|---|---|
| SCRM push (có `crm_khach_ma`) | phong_kham | `cho_duyet` | Admin sbooking |
| SCRM push (có `crm_khach_ma`) | dich_vu | `cho_duyet` | Admin sbooking |
| Booking trực tiếp bên sbooking | phong_kham | `cho_duyet` | Admin sbooking |
| Booking trực tiếp bên sbooking | dich_vu | `cho_duyet` | Admin sbooking |

Lý do: quy về 1 luồng vận hành thống nhất — Admin sbooking là gate duy nhất kiểm capacity BS/phòng/khung giờ trước khi lịch chốt. Capacity phòng + BS vẫn được check ngay lúc POST (409/422) để fail sớm cho SCRM show lỗi.

---

## 6. Trạng thái BookingLog (SCRM) ↔ Booking (sbooking)

| SCRM `booking_logs` | Sbooking `booking` | Trigger |
|---|---|---|
| `status=da_xac_nhan, sync_status=synced` | `trang_thai=cho_duyet` hoặc `da_duyet` | SCRM push tạo mới |
| `sync_status=checkedin` | `trang_thai_khach=da_toi` | sbooking push status |
| `lead.booking_status=BOOKING_KHACH_TOI_TRE` | `trang_thai_khach=toi_tre` | sbooking push |
| `sync_status=done` | `trang_thai=da_xong` | sbooking push |
| `sync_status=canceled, status=HUY_DOI_LICH` | `trang_thai=huy` | SCRM auto-cancel 15' (push) hoặc sbooking push khách_huy |
| `sync_status=failed` + `sync_error` | — | Push fail (network / 4xx / 5xx) |

---

## 7. Đã làm xong (checkpoint gần nhất)

- [x] Phase A — BS dropdown group Cơ sở > Phòng (2026-08-01)
- [x] Phase B — UI settings SCRM ↔ sbooking (2026-08-01)
- [x] Phase C (đổi hướng) — Sync master 1 chiều pull + mirror tables thay vì 2 chiều (2026-08-02 → 08-05)
- [x] Phase D — Push booking + edit + comment + validate 409/422 (2026-08-02 → 08-12)
- [x] Phase 6.25.C — UPS auto-chia sale tiếp đón khi `da_toi` (2026-08-03 → 08-04)
- [x] B1–B4 — MKT recall, multi-media timeline, sale 2 chiều, nguồn HL (2026-08-13 → 08-14)
- [x] B5 — Auto-cancel 15' + push `trang_thai=huy` sang sbooking (2026-08-14 → 08-15)
- [x] B5c — Sbooking accept `trang_thai=huy` + modal duyệt lịch edit sale/giờ/note (2026-08-15)
- [x] E2E QA 21/21 PASS (2026-08-15, xem `result.md`)
- [x] Dev tool — impersonate + quick-login panel (2026-08-16, branch `fifteenth`)

---

## 8. Nguyên tắc khi động vào integration

1. **Không đổi payload/route/enum** mà không cập nhật cả 2 phía cùng lúc — 2 repo phải commit song song.
2. **Push fail không rollback local** — luôn ghi `sync_status=failed, sync_error=...` để user thấy và retry, KHÔNG xoá log local.
3. Trước khi merge bất kỳ thay đổi liên quan endpoint API → test tay end-to-end cả 2 chiều (tạo booking, đổi giờ, khách tới, hủy).
4. Config URL/token đọc từ `AppSetting` trước, env sau — đừng hard-code.
