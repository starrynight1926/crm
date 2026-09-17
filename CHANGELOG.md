# Longevity Data Source — Changelog

Format: mỗi lần chốt tạo 1 block `## vX.Y.Z — YYYY-MM-DD` + bullets. Mới nhất ở trên cùng.

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
