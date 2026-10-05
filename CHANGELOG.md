# Longevity Data Source — Changelog

Format: mỗi lần chốt tạo 1 block `## vX.Y.Z — YYYY-MM-DD` + bullets. Mới nhất ở trên cùng.

## v0.16.3 — 2026-10-05

- **Quick Sheets** (`/settings/quick-sheets`) — Super admin only, chỉnh nhanh 5 entity cùng chỗ, UI Google-Sheets:
  - 5 tab footer: 👤 Nhân sự · 💆 Dịch vụ · 🩺 Dịch vụ lâm sàng · 💬 Tư vấn · 🏢 Cơ sở & Phòng ban.
  - 3 tab service là chung bảng `services` filter theo `service_type` (dich_vu / tham_kham / tu_van). Thêm/sửa/xóa đầy đủ, draft row xanh dương + inline edit.
  - Tab Nhân sự: name/email/phone/job_title/username/status. Thêm user tự sinh password random (admin reset sau).
  - Tab Cơ sở & Phòng ban (OrgUnit): parent dropdown với indent theo depth, auto cập nhật path khi đổi parent, chặn cycle (parent là chính nó hoặc đơn vị con).
  - Search per-tab + pagination 30 (org 50) · Card vào từ `/settings` > Tổ chức.

- **Simple Booking redesign kiểu Google Sheets** (`/simple-booking`):
  - Full-width (cancel padding layout gốc), font Arial, cell `border-collapse` sát nhau, input không viền (focus-only), header xám `#f1f3f4`.
  - Bỏ dropdown "Cơ sở" trên toolbar → tab footer kiểu sheet (📋 Tất cả · CS1: 59NTN · CS2: 207NVT · CS3: 11&15TDN), lọc theo `booking_co_so_slug`, click chuyển scope + auto gán draft.facility_id.
  - Row bg theo trạng thái: 🔴 đỏ nhạt (thiếu KH/SĐT/ngày), 🟡 vàng (thiếu giờ/sale/liệu pháp), 🟢 xanh (đủ).
  - Toolbar footer có legend 3 màu + tổng số dòng.
  - Draft row: "(nháp)" → "(tự động)"; cột Nguồn chuyển từ input tự do → `<select>` lấy 8 nhóm từ `Lead::SOURCE_GROUPS` (MKT/MKTBR/BDM/BOD/SA/BA/WI/HL).
  - Thêm filter khoảng ngày đặt lịch ở toolbar (Từ / Đến) + checkbox **Tất cả (mới → cũ)** để override. Mặc định hôm nay.

## v0.16.1 — 2026-10-05

- **Dashboard + /leads thêm widget "Đặt lịch lại"** — card thứ 4 (màu rose) đếm `booking_status=rescheduled` trong scope user (seesAllReports / isPersonalScopeOnly / visibleTo). Click:
  - Ở dashboard → `/leads?booking=rescheduled` (filter URL mới).
  - Ở `/leads` → toggle `fBookingStatus` tại chỗ, loại trừ với filter 3 phase để tránh combo rỗng.
- Grid stat 3 → 4 card responsive (1/2/4 cột).
- **Fix lead-form — checkbox "Không áp dụng luật thu hồi"** trước nằm trong grid cascade kho; khi chọn `mktMode=auto|manual` grid bị `hidden` → checkbox biến mất. Giờ tách ra ngoài grid, hiện luôn khi mở section "Chia số" bất kể mode nào.
- **Dropdown cơ sở ở navbar — sort theo short_name** (CS1 → CS2 → CS3 → Vận hành). Trước sort theo `position` (toàn 0) → `name` → thứ tự Đà Nẵng/Hà Nội/HCM/Vận hành khó đọc.
- **Lead-list respect scope cơ sở của super admin** — chọn CS1 ở navbar thì `/leads` chỉ hiện lead thuộc subtree CS1 (whereIn `org_unit_id`). User thường không bị double-restrict.

## v0.16.0 — 2026-09-04

- **Phase 6.26 — Sale Tiếp Đón thao tác bên SCRM** (không phải sbooking):
  - Lead-form Phase 4 thêm khối "🎯 Bạn là Sale tiếp đón" (chỉ hiện khi user = CV#1 & booking đã sync sbooking): 2 nút `▶ Đang tiếp đón` / `✓ Hoàn tất` + ô comment nhanh, push realtime sang sbooking qua 3 API mới (`/api/bookings/{id}/trang-thai-tiep-don`, `/comments`).
  - Trạng thái khách (Đã tới / Tới trễ / Hủy) VẪN do Admin cơ sở đánh bên sbooking — sale không thao tác 3 nút này bên nào cả.
  - Toggle "Bận / Nhận lead" ở SCRM header (`MeStatusController::toggleReceive`) tự sync sang sbooking `users.dung_nhan_lead` — sbooking hiện badge `· Sale hiện đang bận` cạnh tên tiếp đón để Admin thấy khi khách check-in.
- **Perm mới `lead.view_team_pool`** — tách gate kho team khỏi kho công ty (`lead.view_pool`). Trước sale HC không có `view_pool` vẫn thấy lead BOD trong pool team vì `visiblePoolIds` mapping từ member org không gate perm. Migration grant cho 9 role management (Admin, Admin cơ sở, CM sale, CM Tele, CM booking, DM HCM, Manager, Observer, Team Leader).
- **UPS override khi check-in guard đúng nguồn** — `BookingEventController::pickGreet` chỉ chạy khi `Lead::isUpsBased($source) && empty($lead->owner_id)`. Trước đây cứ `da_toi` là override owner_id → Bích Trâm (UPS) ghi đè Hoài Như (SA) ở booking gốc nguồn SA.
- **UPS `pickMkt` chuyển sang priority A→B→C→OFF** (giống pickGreet) — trước round-robin cross-bucket theo checkin_at asc → C-sales checkin sớm nhận lead trước A-sales. User confirm intent "hết A mới B, hết B mới C".
- **`sb:sync-bac-si` mark inactive BS bị xoá bên sbooking** — trước chỉ upsert, zombie BS active vẫn xuất hiện dropdown → SCRM booking form gửi id không tồn tại → sbooking preflight 422 "The selected bac si id is invalid".
- Lead-list `⚡lead-list.blade.php`: bôi màu dòng theo `booking_status` (green = đã tới, amber = tới trễ, red = hủy, purple = đã xong) — sale trực quan hoá trạng thái khách ngay ở danh sách (match palette sbooking dashboard).
- `SbookingClient::pushComment` log rõ HTTP status + body khi fail (tránh silent-fail như bug token mismatch local).

## v0.15.1 — 2026-08-16

- **Integration**: booking push sang sbooking giờ luôn ở trạng thái `cho_duyet` (bên sbooking bỏ auto-duyệt cho `phong_kham` — thống nhất 1 gate duyệt). Cập nhật `plan-integration-sbooking.md`.

## v0.14.0 — 2026-08-13

- Thêm bộ **Changelog / Version** (trang `/changelog` + chip version ở footer).
- Login: gộp 2 nút thành 1 nút "Chuyển sang Booking App" + gạch phân tách; nút Hướng dẫn đưa lên trên.
- Bổ sung LPT (Lê Thị Phương Tự) là HC Team Ashley HCM (chuyển từ Trợ lý kinh doanh).
- Sửa `DefaultPassword` map theo cơ sở qua assignment (không dựa email prefix); ĐN đổi hằng `<pass-dn>`.
- Migration reset password toàn bộ user theo cơ sở.

## v0.13.0 — 2026-08-12

- Route `/ai-coop` chat 3 bên (user + 2 Claude API riêng key).
- "Gọi lại sau" về kho cá nhân tele + khoá 1 ngày + auto về kho địa điểm.
- Nút "Tạo bản sao booking" + handler status huỷ → sync canceled + sync_error.
