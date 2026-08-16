# Longevity Data Source — Changelog

Format: mỗi lần chốt tạo 1 block `## vX.Y.Z — YYYY-MM-DD` + bullets. Mới nhất ở trên cùng.

## v0.15.1 — 2026-08-16

- **Integration**: booking push sang sbooking giờ luôn ở trạng thái `cho_duyet` (bên sbooking bỏ auto-duyệt cho `phong_kham` — thống nhất 1 gate duyệt). Cập nhật `plan-integration-sbooking.md`.

## v0.14.0 — 2026-08-13

- Thêm bộ **Changelog / Version** (trang `/changelog` + chip version ở footer).
- Login: gộp 2 nút thành 1 nút "Chuyển sang Booking App" + gạch phân tách; nút Hướng dẫn đưa lên trên.
- Bổ sung LPT (Lê Thị Phương Tự) là HC Team Ashley HCM (chuyển từ Trợ lý kinh doanh).
- Sửa `DefaultPassword` map theo cơ sở qua assignment (không dựa email prefix); ĐN đổi hằng `l23@tdn`.
- Migration reset password toàn bộ user theo cơ sở.

## v0.13.0 — 2026-08-12

- Route `/ai-coop` chat 3 bên (user + 2 Claude API riêng key).
- "Gọi lại sau" về kho cá nhân tele + khoá 1 ngày + auto về kho địa điểm.
- Nút "Tạo bản sao booking" + handler status huỷ → sync canceled + sync_error.
