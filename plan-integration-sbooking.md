# Plan tích hợp lara-scrm ↔ lara-sbooking

> Tạo 2026-08-01 sau khi rework Phase 4 booking per-record (result.md §6.21i-j).
> Đọc trước khi bắt đầu mỗi phase: `CLAUDE.md`, `scope.md` §8, `ERD.md` (bảng `booking_logs`, `booking_log_consultants`), `result.md` §6.21i-j.
> 2 repo: `F:/Laragon/www/lara-scrm` (chính) + `F:/Laragon/www/lara-sbooking` (booking).
> Làm **tuần tự** theo thứ tự dưới. Mỗi phase: trình design → user chốt → code → test tay → ghi result.md. **Không nhảy phase.**

---

## Phase A — Fix dropdown Bác sĩ Phase 4 (small, isolated) 🟢

**Mục tiêu**: dropdown chọn Bác sĩ trong khung "Ghi nhận booking" (Phase 4) group theo **Cơ sở > Phòng > Bác sĩ**, tránh chọn nhầm.

**Files đụng**:
- `resources/views/components/leads/⚡lead-form.blade.php` — select `wire:model="newBookingDoctorId"` (~line 1672)
- Có thể tái sử dụng `window.__staffTree` đã có (~line 1560 area)

**Design đã rõ, không cần chốt gì**. Chỉ 1 lựa chọn:
- Nếu `newBookingFacilityId` đã chọn → filter cứng chỉ hiện BS thuộc cơ sở đó, hay chỉ đẩy lên đầu list?
  - **Đề xuất**: filter cứng (đơn giản, khớp real-life vì user chọn cơ sở trước rồi mới chọn BS).

**Steps**:
1. Thay `<select>` phẳng bằng searchable dropdown Alpine (copy pattern từ block cũ đã xóa).
2. Data source: `window.__staffTree` (đã có). Nhóm theo cơ sở > phòng.
3. Filter theo `newBookingFacilityId` nếu có.
4. Test tay: chọn cơ sở HN → BS list chỉ có BS HN, phân theo phòng.

**Rủi ro**: thấp. Chỉ đụng 1 file blade.

**Ghi result.md §6.21k**.

---

## Phase B — UI Thiết lập config sbooking integration 🟡

**Mục tiêu**: Admin nhập `sbooking_url` + `sbooking_token` qua trang UI, không sửa `.env` tay. Cả 2 phía đều có.

**Files đụng**:
- **scrm**: migration mới `create_integration_settings` (hoặc reuse `settings` nếu đã có), route `/admin/integrations/sbooking`, Livewire component, Model `IntegrationSetting`, helper `integration_config($key)` với fallback env.
- **sbooking**: tương tự — nhập `scrm_url`, `scrm_token`.

**Design cần chốt**:
1. **Bảng riêng hay reuse bảng chung** `settings` (kiểu key-value)? Kiểm tra: `grep "Schema::create.*settings" database/migrations/`
2. **Encrypt token trong DB** (Laravel `Crypt`) hay lưu plaintext? Tao đề xuất encrypt.
3. **Fallback**: nếu bản DB chưa có → dùng env cũ (backward compat).
4. **Perm**: gate bằng perm nào? `admin.settings` chưa có — tạo mới hay dùng `phase.rollback` (Admin-only)?
5. Có nút **"Test connection"** trước khi save? Đề xuất: có, gọi endpoint healthcheck `GET /api/health`.

**Steps** (sau khi chốt design):
1. Migration bảng settings (scrm + sbooking).
2. Model + Livewire form + route + view.
3. Helper `sbooking_url()` / `sbooking_token()` với fallback env.
4. Endpoint healthcheck bên sbooking + nút "Test connection" bên scrm.
5. Test: đổi URL/token qua UI, verify integration còn chạy.

**Rủi ro**: trung bình. Nếu Admin nhập sai token → break integration → cần nút Test.

**Ghi result.md §6.21m**.

---

## Phase C — Sync 2 chiều master data 🔴 (task lớn nhất)

**Mục tiêu**: `facilities`, `staff_members / bac_si`, `services / dich_vu`, `users (tele+sale)` đồng bộ giữa 2 hệ. Có nút "🔄 Đồng bộ" ở 2 phía.

**Files đụng**: RẤT NHIỀU — 2 repo, ~10-15 file mỗi phía.

**Design cần chốt (bắt buộc trước khi code)**:

1. **Master-of-truth cho mỗi bảng**:
   - `facilities/co_so`: master = ? (đề xuất: scrm — vì admin cơ sở dễ setup ở scrm)
   - `staff_members/bac_si`: master = ? (đề xuất: scrm)
   - `services/dich_vu`: master = ? (đề xuất: scrm)
   - `users (CV)`: master = scrm (vì user login qua scrm SSO)
   → Chốt: **scrm là master cho cả 4 bảng**? Sbooking chỉ mirror?

2. **Cột `remote_id`** để map ID 2 hệ:
   - Scrm: thêm `sbooking_id` (nullable, unique) vào mỗi bảng.
   - Sbooking: thêm `scrm_id` (nullable, unique) vào mỗi bảng.

3. **Field mapping**: liệt kê từng bảng, mỗi bên có cột gì, cột nào bên A không có bên B (phải khai để không lỗi khi sync).
   - VD: scrm `staff_members` có `title` — sbooking `bac_si` có tương đương? Cần grep.

4. **Conflict resolution**: master ghi đè slave (đơn giản nhất). Nếu 2 bên đổi cùng lúc → master thắng.

5. **Full backfill 1 lần** hay **incremental** (chỉ sync khi có thay đổi)?
   - Đề xuất: full backfill 1 lần bằng artisan command, sau đó incremental qua endpoint push khi save model.

6. **Trigger sync**:
   - (a) Auto: hook vào model `saved` event → push ngay.
   - (b) Manual: nút "🔄 Đồng bộ tất cả" ở trang admin, chỉ push khi bấm.
   - Đề xuất: (a) + (b) đồng thời — auto để realtime, manual để backfill / recover.

7. **Endpoint schema** (bên sbooking):
   - `POST /api/sync/facilities` — nhận list, upsert
   - `POST /api/sync/staff-members` — nhận list, upsert
   - `POST /api/sync/services`, `POST /api/sync/users`
   - Tương tự bên scrm.

**Steps** (sau khi chốt design):
1. Migration `add_remote_id` cho 4 bảng x 2 repo.
2. Model observer / event listener push khi save.
3. Endpoint upsert bên nhận.
4. Artisan command `php artisan sync:full-master` để backfill.
5. UI nút "🔄 Đồng bộ" ở trang Admin 2 phía.
6. Test: sửa BS bên scrm → verify auto push sang sbooking. Ngược lại KHÔNG được ghi (vì scrm là master, sbooking readonly).

**Rủi ro CAO**:
- Đụng bảng master của cả 2 hệ.
- Field mapping sai → data lệch.
- Loop vô hạn nếu observer trigger observer.
- Sync fail giữa chừng → data không nhất quán.

**Cần test**: unit test cho sync logic + test tay end-to-end.

**Ghi result.md §6.21l**.

---

## Phase D — Push booking scrm → sbooking 🔴 (phụ thuộc B + C)

**Mục tiêu**: bấm "Ghi nhận booking" bên scrm → tạo record thật bên sbooking. Bỏ banner "chỉ ghi log nội bộ".

**Prereq**: Phase B (config) + Phase C (sync master) đã xong.

**Files đụng**:
- **sbooking**: refactor `BookingController@store` (F:/Laragon/www/lara-sbooking/app/Http/Controllers/BookingController.php:733) thành service reusable. Tạo `Api/BookingWriteController@store` với route `POST /api/bookings/{co_so}/create` (middleware `scrm.token`).
- **scrm**: mở rộng form Phase 4 (dropdown phòng + khung giờ), Service `App\Services\SbookingClient`, sửa `addBookingLog()`.
- Migration `add_remote_booking_ma_to_booking_logs`.

**Design cần chốt**:
1. **Dropdown Phòng + Khung giờ** bên scrm: fetch động qua Livewire (mỗi lần đổi cơ sở/BS/DV) hay preload toàn bộ + filter client-side?
   - Đề xuất: fetch động, cache 5 phút.
2. **Response từ sbooking**: trả về đủ để hiển thị (`booking_ma`, `khung_gio_bat_dau/ket_thuc` computed)?
3. **Flow lỗi**: sbooking trả 422 (validate fail) → hiển thị field errors bên scrm form. 5xx → flash generic error.

**Steps** (sau khi chốt):
1. Bên sbooking: extract service, tạo API endpoint + validate + trả JSON.
2. Bên scrm: `SbookingClient` với method `getPhongList(co_so)`, `getKhungGioList(co_so, phong, bs, dv, ngay)`, `pushBooking(payload)`.
3. Migration `booking_logs.remote_booking_ma`.
4. Form Phase 4: thêm 2 dropdown động.
5. `addBookingLog()`: gọi push TRƯỚC, thành công mới `BookingLog::create()`. Fail → flash lỗi, không lưu.
6. Bỏ banner "chỉ ghi log nội bộ" đã thêm §6.21j. Đổi label section lại thành "Tạo booking".
7. Test end-to-end: tạo booking bên scrm → verify xuất hiện đúng bên sbooking.

**Rủi ro CAO**:
- Refactor `store` bên sbooking đụng logic phức tạp (conflict, capacity...) — dễ regression.
- Form scrm phức tạp hơn — nhiều dropdown động.
- Race condition: 2 user cùng book slot đó → 1 fail.

**Ghi result.md §6.21n**.

---

## Ghi chú chung

- Mỗi phase xong → **test tay bằng browser**, không chỉ chạy unit test. Chụp screenshot nếu cần.
- Trước khi start phase, đọc lại phase description + design questions + user đã chốt trong session trước.
- Sau khi xong: cập nhật `result.md` + đánh dấu ✅ phase trong file này.
- Nếu phát sinh design decision mới ở giữa phase → dừng, hỏi user, không tự quyết.

## Trạng thái

- [x] Phase A — BS dropdown ✅ (2026-08-01)
- [x] Phase B — UI settings ✅ (2026-08-01)
- [~] Phase C — Sync master data (đổi hướng: Option 2 → schema unification, xem [plan-schema-unification.md](plan-schema-unification.md))
- [ ] Phase D — Push booking
