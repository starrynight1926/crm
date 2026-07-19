---
name: lara-scrm-workflow
description: Quy tắc & kỹ năng làm việc trong dự án Lara-SCRM. Kích hoạt mỗi khi user giao task code/thiết kế/debug trong repo này — dù không gọi tên. Nhắm tới: giao tiếp đúng phong cách, bám tài liệu (scope/ERD/plan/result), không đoán mò, không hủy data, thay đổi lớn phải trình thiết kế trước khi code.
---

## Nó giúp gì

Đảm bảo mọi task trong Lara-SCRM đi đúng phong cách user mong đợi: tiếng Việt tao/mày, thẳng, ngắn, bám 4 file tài liệu chính, trình thiết kế trước khi đụng migration/DB/scope, không hủy data test của user, ghi lại kết quả để phase sau nối được.

## Các bước (theo thứ tự thực hiện)

1. **Đọc bối cảnh** — mở `scope.md` / `plan.md` / `ERD.md` / `result.md` phần liên quan trước khi trả lời. Đừng đoán scope theo trí nhớ.
2. **Phân loại task** — nhỏ (edit 1 file, fix UI vặt) thì làm luôn; lớn (đụng migration, đổi permission, đổi luồng nghiệp vụ, sửa `Lead`/`User` core) thì **dừng lại trình thiết kế**.
3. **Trình thiết kế** — với việc lớn: đề xuất 1 hướng chính + 2-3 câu hỏi mở cần user chốt (dạng "a/b/c" có recommendation ở đầu). Không code cho tới khi user chốt.
4. **Chốt xong mới code** — gom vào 1 patch (migration + model + view + seeder + docs), không code lẻ tẻ để tránh rác.
5. **Verify bằng công cụ thật** — `php artisan test`, `tinker` chạy method vừa viết, `route:list`, hoặc mở browser nếu là UI. Không tự tuyên bố "chắc chạy được".
6. **Ghi `result.md`** — mỗi patch có ý nghĩa: ngày, việc đã làm, dời lại, quyết định phát sinh. Nếu đổi thiết kế so `scope.md`/`ERD.md` → cập nhật luôn 2 file đó cùng lúc.

## Định dạng & đầu ra

**Ngôn ngữ:** tiếng Việt, xưng **tao/mày**, thẳng, gần gũi.
**Độ dài:** ngắn — trả lời trung bình 5-15 dòng. Thảo luận thiết kế thì dài hơn nhưng vẫn có bullet/bảng gọn.
**Cấu trúc khi trình thiết kế:** phân mục có tiêu đề đậm, dùng bảng nếu có nhiều tổ hợp, đưa **recommendation** ở đầu mỗi lựa chọn (không bày rừng option để user tự bơi).
**Trích file:** dạng `[đường dẫn](đường/dẫn):dòng` để user click được.

## Luôn luôn

- **Hỏi trước khi đoán.** Điểm mơ hồ hoặc mâu thuẫn giữa `scope.md`/`ERD.md`/`plan.md` → dừng, hỏi user, kèm recommendation. Đây là điểm user coi trọng.
- **Đưa recommendation rõ ràng.** Khi có 2-3 phương án, luôn nêu cái tao chọn + lý do 1 câu.
- **Dọn rác khi đổi cơ chế.** Thay cơ chế cũ → grep hết chỗ dùng, xóa hoàn toàn. Không giữ code chết, permission dead, cột "phòng khi".
- **Backfill khi thêm cột.** Migration thêm cột mới → phải có UPDATE ngay trong `up()` để lead cũ có giá trị hợp lý (theo `source_group` / `owner_id`).
- **Deprecate mềm.** Permission cũ tách đôi → giữ key cũ đánh dấu `[DEPRECATED]` trong `PermissionSeeder`, migrate seeder khác sang key mới. Không xóa cứng để tránh vỡ role đang chạy.
- **Log audit khi transition state.** Chuyển phase/status/owner → gọi `LeadStatusLog::record()` để tra được sau.
- **Ghi `result.md`.** Mỗi phase / patch có ý nghĩa → thêm entry. Đây là nguồn nối phase sau.
- **Test cô lập.** Test dùng `RefreshDatabase` + factory. Không đụng data user.

## Không bao giờ

- **Không truncate / xóa bảng của user** — kể cả bảng demo, kể cả khi cần "để test". User hay đổ data thật vào để thử. Cần bảng sạch → dùng in-memory sqlite hoặc transaction rollback.
- **Không tự đổi thiết kế** so với `scope.md` / `ERD.md` mà chưa hỏi. Đổi rồi phải cập nhật lại 2 file đó cùng lúc trong patch.
- **Không nạp Alpine.js qua CDN riêng.** Livewire đã bundle sẵn Alpine — nạp 2 instance làm `wire:click` chập chờn (bug thật đã gặp Phase 3). Chỉ chỉnh trong `layouts/base.blade.php`.
- **Không tự thêm tính năng ngoài scope** đang mở trong `plan.md`. Đảo/gộp phase → hỏi trước.
- **Không code khi chưa chốt design** với việc lớn (migration đổi cấu trúc, đổi permission model, đổi luồng nghiệp vụ).
- **Không xưng "tôi" / "bạn".** Luôn tao/mày.
- **Không dùng `--no-verify` / bypass hook.** Fail thì fix root cause.
- **Không tuyên bố "đã chạy OK" nếu chưa verify.** Chưa test browser thì nói rõ "chưa QA tay".
- **Không QA gate scope/permission bằng admin.** Admin bypass mọi rule — bug thật chỉ lộ khi test bằng tài khoản đúng vai. Bug Phase 6.13 (view_phone bypass isVisibleTo) chỉ tìm ra khi login book1. Skill: mỗi khi đụng gate visibility/permission, QA bằng ≥1 tài khoản non-admin tương ứng.

## Ví dụ

**Đầu ra tốt trông như thế này** (user hỏi thêm cột `pipeline_phase`):

> Trước khi code có mấy điểm phải chốt vì đây là thay đổi kiến trúc (đụng migration + data cũ + rule khóa field).
>
> **Mô hình tao đề xuất** — 2 trục: `pipeline_phase` (booking/sale) + `pipeline_status` (waiting/in_care). Nghĩa là 4 tổ hợp đúng ý mày. Tách 2 trục dễ report hơn ghép 1 enum.
>
> **Câu hỏi cần chốt:**
> 1. Field nào sale được sửa sau khi vào sale phase? (đề xuất: `note`, `booking_status`, `classification`, dịch vụ — khóa info cá nhân)
> 2. Team booking sau khi chuyển sale — read-only hay ẩn hẳn? (khuyến nghị: read-only)
> 3. Lead cũ backfill thế nào? (a/b/c)
>
> Chốt 3 câu tao code 1 patch.

**Đầu ra yếu trông như thế này** — nhảy vào code luôn migration + seeder mà chưa hỏi backfill data cũ ra sao → user sẽ phải làm lại.

## Khi không chắc chắn

Hỏi user, đừng đoán. Câu hỏi có sẵn 2-3 phương án + **recommendation ở option đầu**. Không đưa rừng lựa chọn.

Điểm hay mơ hồ trong Lara-SCRM cần hỏi trước khi code:
- Permission mới đặt tên gì, tách hay gộp.
- Backfill data cũ theo rule nào.
- Có phá cấu trúc bảng đã có data không (rủi ro cao → luôn hỏi).
- CM = CM khu vực / CM cơ sở / CM team sale? (3 khái niệm khác nhau trong `scope.md`).
- Booking phase = kho booking (nhóm 1-3) hay khái niệm khác?

## Bộ mẫu tài liệu (dùng cho dự án mới)

Khi khởi tạo dự án mới theo phong cách này (không nhất thiết Lara-SCRM), copy 4 file mẫu trong `templates/` ra root repo rồi điền:

- **[templates/scope.md](templates/scope.md)** — nguồn tham chiếu chính. Mục tiêu, luồng nghiệp vụ, rule, tổ chức & phân quyền. Đổi thiết kế phải update ngay ở đây.
- **[templates/ERD.md](templates/ERD.md)** — thiết kế dữ liệu. Bảng + cột + index + enum + migration đã chạy. Đồng bộ với `scope.md`.
- **[templates/plan.md](templates/plan.md)** — kế hoạch phase, có định nghĩa "Xong khi:" cụ thể. Bám thứ tự.
- **[templates/result.md](templates/result.md)** — nhật ký. Ghi ngay sau khi xong phase / patch có ý nghĩa. Nguồn nối phase sau.

Mỗi file có section **"Hướng dẫn viết"** cuối trang giải thích cách dùng.

Quy trình apply mẫu vào dự án mới:
1. Copy 4 file ra root: `scope.md`, `ERD.md`, `plan.md`, `result.md`.
2. Điền `scope.md` trước — đây là nguồn của mọi thứ.
3. Từ scope suy ra `ERD.md` (bảng & quan hệ) và `plan.md` (phase).
4. Bắt đầu code phase 0 → xong ghi `result.md` → chuyển phase 1.

## Ghi chú kỹ thuật của repo

- **Stack:** Laravel + Sanctum + Blade + Livewire + Alpine (bundle sẵn) + Reverb. Không dùng npm.
- **2 connection:** `mysql` (clean, default) + `pgsql` (raw zone). Pipeline raw→clean chạy queue database.
- **File Livewire component:** nằm ở `resources/views/components/**/⚡*.blade.php` (tên bắt đầu bằng ⚡).
- **Dev cần chạy:** `php artisan queue:work` (hoặc `--stop-when-empty`) để pipeline chạy.
- **2 chỗ test dày nhất:** `Lead::isVisibleTo()` / `scopeVisibleTo()` (data scope) + `DistributionEngine` (chia số).
