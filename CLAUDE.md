# Lara-SCRM — Quy tắc làm việc

## Tài liệu tham chiếu
- `scope.md` — scope & thiết kế tổng quan (nguồn tham chiếu chính)
- `ERD.md` — thiết kế dữ liệu chi tiết (2 DB: PostgreSQL raw + MySQL clean)
- `plan.md` — kế hoạch 8 phase, làm theo thứ tự
- `result.md` — nhật ký: làm xong phase nào PHẢI ghi vào đây (ngày, việc đã làm, việc dời lại, ghi chú)

## Quy tắc bắt buộc
1. **Không đoán mò scope.** Chỉ làm những gì đã ghi trong `scope.md` / `ERD.md` / `plan.md`. Không tự thêm tính năng ngoài scope.
2. **Cái nào không rõ, hỏi lại ngay.** Gặp điểm mơ hồ, mâu thuẫn giữa các file, hoặc thiếu thông tin để quyết định → dừng và hỏi user trước, không tự quyết.
3. **Bám sát file kế hoạch.** Làm đúng phase đang mở trong `plan.md`, đúng thứ tự. Muốn đảo thứ tự hoặc gộp/tách phase → hỏi trước.
4. **Thấy rủi ro phải báo trước, không làm "mù".** Trước khi thực hiện thao tác rủi ro (xóa/sửa dữ liệu, migration đổi cấu trúc bảng đã có data, đổi thiết kế so với ERD, thao tác không đảo ngược được...) → nêu rõ rủi ro và chờ user xác nhận.

## Quy trình mỗi phase
1. Đọc lại phase tương ứng trong `plan.md` trước khi bắt đầu.
2. Làm xong → chạy test/kiểm tra, báo kết quả trung thực (fail thì nói fail).
3. Ghi kết quả vào `result.md` rồi mới chuyển phase tiếp theo.

## Ghi chú kỹ thuật
- Stack: Laravel + Sanctum, Blade + Livewire + Alpine.js (CDN, không npm), Laravel Reverb.
- 2 connection: `mysql` (clean, default) + `pgsql` (raw).
- 2 chỗ phải test dày nhất: data scope resolve (Phase 1) và engine chia số (Phase 4).
