# Bảng trường dữ liệu — Lara SCRM

_Bản export tự động từ models + enums + custom fields đang active. Dùng để trình quản lý duyệt các mục có chuẩn không._

_Xuất ngày: 2026-08-04_

---

## Tổng quan: Khách hàng (Lead)

* **Các trường quy định gồm:**
   * Mã KH (`code`): text — tự sinh theo nhóm nguồn, VD `KH-002-MKT`
   * Họ tên (`name`): text
   * SĐT (`phone`): text
   * Ngày nhập (`received_date`): date
   * Insight (`insight`): text — mô tả insight khách
   * Link (`link`): text — link nguồn (post/ad)
   * Khu vực (`region`): text — VD "Hà Nội", "TP. Hồ Chí Minh"
   * Phân loại (`classification`): select — `new` / `lead` / `follow` / `net` / `tai_chinh_yeu` / `quan_tam` / `tham_khao` / `tim_hieu` / `goi_lai_sau` / `klld` / `missed` / `booking` / `show` / `close`
   * Trạng thái 1 (`status_1`): text
   * Trạng thái 2 (`status_2`): text
   * Ghi chú (`note`): text
   * Ngày sinh (`birthday`): date
   * Địa chỉ (`address`): text
   * Tiền sử bệnh (`medical_history`): text
   * Nghề nghiệp (`occupation`): text
   * Tên dịch vụ (`service_name`): text
   * Dịch vụ tiềm năng (`potential_service`): text
   * Lần khám đầu tiên? (`is_first_visit`): boolean

* **Nhóm nguồn (`source_group`) — 3 luồng xử lý:**
   * Nhóm 1 (qua Team Booking): `mkt` (Marketing), `mkt_br` (Marketing BR), `bdm` (BDM)
   * Nhóm 2 (lối tắt qua CM Sale): `bod` (BOD - Ban lãnh đạo giới thiệu), `sa` (SA - Sale Appointment), `ba` (BA - Booking Appointment)
   * Nhóm 3 (khách đến trực tiếp): `wi` (Walk-in)

* **Trạng thái Booking (`booking_status`):**
   * `not_booked` — Chưa đặt
   * `booked` — Đã đặt
   * `rescheduled` — Hẹn lại
   * `khach_da_toi` — Khách đã tới ✅
   * `khach_toi_tre` — Khách tới trễ ⏰
   * `khach_huy` — Khách hủy ❌
   * `da_xong` — Đã xong 🎉

* **Kho (`pool_level`):**
   * `common` — Kho chung công ty
   * `team` — Kho team (thuộc PoolUnit — cascade Công ty → Địa điểm → Cơ sở → Phòng KD)
   * `personal` — Đã chia sale phụ trách

* **Phase Customer Flow (`phase`) — 6 bước funnel:**
   * `1` — Tạo mới & Chia số (gộp)
   * `2` — Gọi điện
   * `3` — Booking
   * `4` — Check-in
   * `5` — Sales
   * `6` — Sau bán / Chăm sóc

* **Trường phân quyền / gán:**
   * `owner_id` — Sale phụ trách (người nhận)
   * `receiver_id` — Người nhận trước đó (giữ lịch sử tele)
   * `imported_by` — Người nhập lead
   * `org_unit_id` — Đơn vị tổ chức (legacy)
   * `pool_unit_id` — Node cây Kho số (Phase 6.24 — thay `org_unit_id`)
   * `facility_id`, `doctor_id`, `consultant_1_id`..`consultant_3_id` — legacy Phase 3, hiện chuyển sang `booking_log_consultants` per booking
   * `assigned_at`, `last_care_at`, `overdue_marked_at`, `recall_at`, `is_permanent_assignment` — SLA / thu hồi
   * `booking_ma`, `booked_at` — mã booking sbooking đang gắn
   * `approval_status`, `approval_by`, `approved_at` — duyệt lead

---

## Tổng quan: Trường tuỳ chỉnh (Custom Field)

_Cho phép admin/CM thêm trường riêng cho công ty hoặc từng phòng ban, không cần code._

* **Các trường quy định gồm:**
   * Key (`key`): text — định danh trong DB
   * Mã import (`import_code`): text — cột trong file Excel khi import
   * Nhãn (`label`): text — tên hiển thị
   * Loại (`field_type`): select — `text` / `number` / `date` / `email` / `select` / `tick` (ô tích có/không) / `code` (mã phân loại nối vào mã KH)
   * Bắt buộc? (`required`): boolean
   * Ảnh hưởng mã KH? (`affects_code`): boolean — nếu tick, giá trị sẽ nối vào code
   * Options (`options`): JSON — danh sách value cho type=select
   * Rules (`rules`): JSON — validation + code_kind (`fixed`/`from_value`) + fixed_value + option_labels
   * Vị trí (`position`): number — thứ tự hiển thị trong form
   * Phạm vi (`org_unit_id`): null = công ty; else = phòng ban cụ thể
   * Trạng thái duyệt (`status`): `active` / `pending` (chờ cấp trên duyệt) / `rejected`
   * `requested_by`, `reviewed_by`, `reviewed_at`, `reject_reason`

* **Custom field hiện đang áp cho toàn công ty (5 trường):**
   * `page` — PAGE (text)
   * `camp` — Camp (text)
   * `phan_loai` — Phân loại (select)
   * `ket_qua` — Kết quả (select)
   * `sic` — S.I.C (select)

---

## Tổng quan: Gọi điện (Call Log)

* **Các trường quy định gồm:**
   * Lead (`lead_id`)
   * Nhân viên gọi (`user_id`)
   * Trạng thái cuộc gọi (`status`): select
     * `thanh_cong` — Thành công
     * `that_bai` — Thất bại
     * `khong_nghe_may` — Không nghe máy
   * Ghi chú (`note`): text
   * Thời điểm gọi (`called_at`): datetime

---

## Tổng quan: Booking (Booking Log)

_Booking bên scrm — mirror với sbooking. Mỗi booking đẩy sang sbooking để chốt lịch._

* **Các trường quy định gồm:**
   * Lead (`lead_id`)
   * Người tạo booking (`user_id`)
   * Loại đặt lịch (`type`): `tham_kham` (Thăm khám) / `dich_vu` (Dịch vụ)
   * Trạng thái duyệt (`status`): 
     * `da_xac_nhan` — Đã xác nhận
     * `cho_xac_nhan` — Chờ xác nhận
     * `huy_doi_lich` — Hủy - Đổi lịch
   * Thời gian bắt đầu (`scheduled_at`): datetime
   * Giờ kết thúc (`scheduled_end_at`): time — khớp `khung_gio` sbooking
   * Cơ sở (`facility_id`): FK — cơ sở scrm (map sang sbooking qua `sbooking_co_so_id`)
   * Phòng sbooking (`sb_phong_id`): int — trỏ tới `sb_rooms.sbooking_id`
   * Bác sĩ sbooking (`sb_bac_si_id`): int — trỏ tới `sb_bac_si.sbooking_id`
   * Dịch vụ sbooking (`sb_dich_vu_id`): int — trỏ tới `sb_services.sbooking_id`
   * Khung giờ sbooking (`sb_khung_gio_id`): int — trỏ tới `khung_gio.id` bên sbooking
   * Dịch vụ scrm (`service_id`): FK legacy — kept for compat
   * Ghi chú (`note`): text
   * **Dịch vụ (4 trường bổ sung):**
     * Số liệu trình (`so_lieu_trinh`): text — VD "3 liệu trình"
     * Số lượng lô (`so_luong_lo`): text
     * Dung tích lô (`dung_tich_lo`): enum sbooking (`8M`, `10M`, `16M`, `20M`, `450M`, `1 LT`, `2 LT`)
     * Kết hợp Medical? (`ket_hop_medical`): boolean
     * Có tư vấn? (`co_tu_van`): boolean
     * Có khám CLS? (`co_kham_cls`): boolean
   * **Đồng bộ sbooking:**
     * `sbooking_booking_id`: int — id bên sbooking
     * `sbooking_booking_ma`: text — mã booking sbooking (VD `BKG-260803-000009`)
     * `sync_status`: `pending` / `synced` / `approved` / `checkedin` / `done` / `rejected` / `canceled` / `deleted` / `failed`
     * `sync_error`: text
     * `synced_at`: datetime

* **Chuyên viên tư vấn (CV) — bảng pivot `booking_log_consultants`:**
   * `booking_log_id`, `user_id`, `position` (1..n — position=1 = CV chính = Sale phụ trách sau khi booking duyệt)

---

## Tổng quan: Bình luận Booking (Booking Log Comment)

_Thread bình luận 2 chiều giữa scrm và sbooking._

* **Các trường quy định gồm:**
   * `booking_log_id`, `lead_id`
   * `source`: `scrm` hoặc `sbooking`
   * `user_id` (scrm) hoặc `sbooking_user_id` (sbooking)
   * `user_name`: text — cache tên hiển thị
   * `content`: text (max 2000)

---

## Tổng quan: Log tới trễ (Booking Late Log)

* **Các trường quy định gồm:**
   * `booking_log_id`, `lead_id`, `sbooking_booking_id`, `sbooking_booking_ma`
   * Giờ hẹn (`expected_at`): datetime
   * Giờ tới thực tế (`arrived_at`): datetime
   * Số phút trễ (`late_minutes`): int
   * Người đánh dấu (`marked_by`): text — VD "Admin vận hành (sbooking)"
   * Ghi chú (`note`): text

---

## Tổng quan: UPS System (Check-in đầu ngày)

### Cấu hình cơ sở (UpsConfig)
* `facility_pool_unit_id` — cơ sở
* `cutoff_time` — mốc cutoff (VD 08:36) — sale check-in sau mốc này auto vào OFF

### Chốt UPS ngày (UpsDailyConfirm)
* `facility_pool_unit_id`, `work_date`, `confirmed_by`, `confirmed_at` — Đã chốt = mở khoá cho Phase 1 chia số

### Điểm danh trong ngày (DailyAttendance)
* `facility_pool_unit_id` — cơ sở check-in
* `user_id` — sale
* `work_date` — ngày làm
* `checkin_at` — giờ check-in
* `list_bucket` — bucket UPS:
   * `A` — BOD / HOTLINE / MKT / AFF / WI / BR (≥20TR, SHOW + TIỀN)
   * `B` — APPT / PNS / VOUCHER (có SHOW / có TIỀN)
   * `C` — B bận (check-in on time)
   * `OFF` (Offlist) — A, B, C bận (>5p trễ). Không phải nghỉ làm — chỉ là không nhận số hôm nay.
   * `MKT` — TM Team (HC) — check-in on time
* `is_off`, `override_by`, `override_at` — BO có quyền override chuyển bucket

---

## Tổng quan: Dịch vụ khách hàng (Customer Service — sau bán)

### CustomerService
* `lead_id`, `service_id`, `agreed_price`, `status`, `started_at`, `completed_at`
* `status`: `active` / `paused` / `completed` / `refunded`

### CustomerServicePhase (theo phase của dịch vụ)
* `customer_service_id`, `service_phase_id`, `status`, `done_by`, `done_at`, `handover_note`

### LeadTreatment (điều trị / thực hiện)
* `lead_id`, `sequence`, `performed_at`, `performing_doctor_id`, `quality_rating`

### LeadUpsell (up-sale)
* `lead_id`, `staff_member_id`, `service_id`, `amount`

### Contribution (chia doanh số)
* `lead_id`, `customer_service_id`, `user_id`, `role_label`, `percent`, `set_by`, `created_at`

---

## Tổng quan: Thanh toán (Payment)

* `lead_id`, `customer_service_id`, `customer_service_phase_id`
* `amount`, `method`, `paid_at`
* `collected_by`, `note`

---

## Tổng quan: Nhân viên & Phân quyền

### User
* `username`, `name`, `email`, `phone`, `job_title`, `avatar`
* `status`, `password`, `last_login_at`
* `report_prefs` (JSON), `api_token` (Sanctum)
* `sbooking_user_id` — map sang users bên sbooking (auto-match theo local-part email)

### Assignment (gán role + đơn vị)
* `user_id`, `role_id`, `org_unit_id`
* `data_scope`: `self` / `team` / `subtree` / `org` / `all`
* `active`, `valid_from`, `valid_to`

### Role / Permission
* Role: `name`, `description`, `is_system`
* Permission: `key`, `label`, `group`, `position`

### OrgUnit (cây phòng ban)
* `parent_id`, `name`, `code`, `path`, `depth`, `position`, `active`

### PoolUnit (cây Kho số — Phase 6.24)
* `parent_id`, `name`, `code`, `kind` (`company` / `branch` / `facility` / `department`), `path`, `depth`, `sort`, `is_active`

### UserLeadSetting (bật/tắt nhận lead)
* `user_id`, `receiving` (boolean), `off_reason`, `off_until`

---

## Tổng quan: Chia số (Distribution)

### DistributionRule
* `name`, `active`, `priority` (nhỏ chạy trước)
* `level`: `pool_to_team` (L1 công ty→team) / `team_to_user` (L2 team→sale)
* `pool_unit_id` — kho áp dụng (từ Phase 6.24, thay `org_unit_id`)
* `conditions` (JSON): filter theo `region`, `camp`, `page`
* `strategy`: `round_robin` / `weighted` / `top_revenue` / `top_close_rate`
* `strategy_config` (JSON)

### RuleTarget (đích chia của mỗi rule)
* `rule_id`, `target_type` (`org_unit` / `pool_unit` / `user`), `target_id`, `weight`, `position`

### RuleCounter (đếm round-robin/weighted)
* `rule_id`, `target_id`, `period_key`, `delivered_count`

### SlaPolicy
* `org_unit_id` (null = default)
* `mode`: `auto` (tự thu hồi theo SLA) / `manual` / `off`
* `recall_after_hours`, `recall_to` (`team` / `common`)

### LeadCap (giới hạn số lead / ngày)
* `scope_type`, `scope_id`, `daily_cap`, `active`

### LeadDistributionLog (nhật ký mọi lần chia/thu hồi)
* `lead_id`, `action`
* `from_pool_level`, `to_pool_level`
* `from_owner_id`, `to_owner_id`
* `org_unit_id`, `rule_id`, `actor_id`, `reason`, `created_at`

---

## Tổng quan: Mirror sbooking (đọc-only)

_Các bảng cache dữ liệu master từ sbooking để scrm chọn khi tạo booking, tránh phụ thuộc online._

* **SbBacSi**: `sbooking_id`, `sbooking_co_so_id`, `ten`, `chuc_danh`, `active`, `xuat_hien_moi_co_so`, `nhan_tu_van`, `phut_tu_van`, `nhan_kham_ls`, `phut_kham_ls`, `gio_bat_dau`, `gio_ket_thuc`
* **SbRoom**: `sbooking_id`, `sbooking_co_so_id`, `ten`, `so_slot_toi_da`, `kieu_phong` (`phong_kham` / `phong_dich_vu`)
* **SbService**: `sbooking_id`, `sbooking_co_so_id`, `ten`, `thoi_gian_phut`, `thuoc_nhom` (`tu_van` / `kham_ls` / …), `la_dich_vu`, `active`
* **SbUser**: `sbooking_id`, `ten`, `chuc_danh`, `username`, `email`, `sbooking_co_so_id`, `sbooking_phong_ban_id`, `sbooking_vai_tro_id/ma/ten`

---

## Tổng quan: Ingest & Import (nguồn dữ liệu)

### RawLead (2 DB: pgsql)
* `source_type`, `source_ref`, `import_batch_id`
* `payload` (JSON), `status`, `error_reason`
* `clean_lead_id`, `created_at`, `processed_at`

### ImportBatch (pgsql)
* `file_name`, `uploaded_by`, `column_mapping`, `total`, `success`, `failed`, `duplicated`

### ImportTemplate
* `name`, `config`, `created_by`

### SourceConnection (webhook / API bên thứ 3)
* `type`, `name`, `credentials` (encrypted), `webhook_token`, `field_mapping`, `active`, `last_synced_at`

### IngestLog (pgsql — nhật ký request/response)
* `source_type`, `connection_id`, `http_status`, `request`, `response`, `created_at`

---

## Tổng quan: Log & Audit

### AuditLog
* `user_id`, `action`, `entity_type`, `entity_id`, `meta` (JSON), `ip`, `created_at`

### LeadStatusLog (đổi trạng thái, hàng đầu tiên nhật ký lead)
* `lead_id`, `user_id`, `field`, `old_value`, `new_value`
* `images` (JSON), `is_return`, `is_first_visit`, `reception_code`

### LeadPhaseClosure (đóng phase Customer Flow)
* `lead_id`, `phase` (1..6), `closed_by`, `closed_at`, `note`

---

## Tổng quan: Thông báo (Notification)

### NotificationPref (config theo role)
* `role_id`, `event_key`, `scope` (`off` / `own` / `team` / `all`)

### ReportTemplate
* `org_unit_id`, `name`, `config` (JSON), `created_by`

---

## Danh mục hỗ trợ

* **Facility** (cơ sở scrm): `name`, `parent_id`, `active`, `booking_co_so_slug`, `sbooking_co_so_id`
* **Service** (dịch vụ scrm legacy): `parent_id`, `name`, `code`, `pricing_type` (`package`/`hourly`/…), `package_price`, `price_usd`, `active`, `notes`
* **ServicePhase**: `service_id`, `position`, `name`, `phase_price`
* **StaffMember** (BS/KTV/lễ tân — legacy): `name`, `title`, `facility_id`, `role` (`doctor`/`nurse`/`receptionist`), `active`
* **ContributionTemplate** (mẫu chia doanh số): `name`, `items` (JSON), `is_default`

---

_Nếu thấy trường nào **không cần** hoặc **thiếu**, ghi chú trực tiếp file này rồi báo dev để chỉnh._
