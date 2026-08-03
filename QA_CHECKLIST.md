# QA Checklist — Lara-SCRM

_Test tay trước mỗi release / mỗi lần merge phase lớn. Đánh dấu ✅ khi PASS, ❌ khi FAIL, ghi chú bug ở cuối._

_Cập nhật: 2026-08-04 (Phase 6.25.C — sync 2 chiều sbooking + UPS System)._

---

## 1. Authentication & Phân quyền

- [ ] Login được với tài khoản `admin@…` mật khẩu `59ntn`
- [ ] Login user `hn.sale04@longevity.com.vn` mật khẩu `59@ntn`
- [ ] Sale không có `lead.distribute_sale` mở lead đã có owner khác → không thấy nút chia/chuyển kho
- [ ] User chỉ là CV (gán vào `booking_log_consultants`), không có role cao → mở lead thấy banner amber, mọi form khách readonly, chỉ mở phần bình luận

## 2. Tạo mới & Chia số (Phase 1)

- [ ] Lead nguồn MKT → chọn kho Cơ sở/Phòng KD → tự động `pickMkt` từ MKT List UPS + flash "Đã chia lead cho {sale}"
- [ ] Chưa chốt UPS hôm nay → chọn cơ sở → thấy cảnh báo đỏ "UPS chưa được chốt hôm nay"
- [ ] Nguồn MKT + kho công ty/chi nhánh → validate error "bắt buộc chọn Cơ sở hoặc Phòng KD"
- [ ] Cascade Kho: Công ty (readonly Longevity) → Địa điểm → Cơ sở → Phòng ban — dừng bất kỳ cấp nào từ Địa điểm

## 3. Cấu hình Chia số (rule)

- [ ] Vào `/distribution/rules` → tạo rule L2 (team_to_user) → chọn cascade 4 select → save không lỗi
- [ ] Rule L2 cascade: chỉ chọn Địa điểm → save OK (kho về địa điểm)
- [ ] Rule L2 cascade: đủ 4 cấp → poolUnitId = Phòng ban id

## 4. Booking (Phase 3)

- [ ] Form booking: chọn Cơ sở → BS dropdown filter theo `sbooking_co_so_id`
- [ ] Chọn dịch vụ loại tư vấn → BS dropdown chỉ hiện BS có `nhan_tu_van=true`
- [ ] Chọn dịch vụ loại khám LS → BS dropdown chỉ hiện BS có `nhan_kham_ls=true`
- [ ] Dropdown dịch vụ KHÔNG bị lặp x3-x4 (đã dedupe + filter theo cơ sở)
- [ ] Chọn slot khung giờ (VD 09:00-09:30) → save booking → `sb_khung_gio_id` + `scheduled_end_at` được lưu
- [ ] Push sang sbooking → mở sbooking `/xem-dat-phong/{id}` → thấy đủ `sale_id`, `khung_gio_id`, `dich_vu_id`, `phong_id`
- [ ] Booking có CV1 gán → sang sbooking thấy nút "Đang tiếp đón" hiển thị cho sale đó

## 5. Callback từ sbooking (real-time)

- [ ] Bên sbooking Admin bấm Duyệt booking → thấy toast xanh "Đã duyệt" + flash xanh
- [ ] Nếu BS không đủ capability (VD BS không nhận tư vấn cho dịch vụ tư vấn) → sbooking hiện **flash đỏ error** giải thích rõ (KHÔNG silent)
- [ ] Sau khi Admin duyệt → mở scrm `/leads/{id}/edit` → **auto refresh Livewire** trong <5s (thấy toast xanh "Cập nhật từ sbooking đã đồng bộ"), không cần F5
- [ ] Admin đánh dấu khách `da_toi` bên sbooking → scrm auto `pickGreet` sale từ Sale Tiếp Đón, gán `owner_id`

## 6. UPS System

- [ ] Vào `/ups/list` (BO) → thấy bảng theo Chi nhánh > Cơ sở
- [ ] Check-in sale: chọn sale → chọn vị trí (Tiếp đón / Nhận số) → chọn tier → bấm Check in → thêm vào bảng
- [ ] Bấm "Chốt UPS hôm nay" cơ sở → flash "Đã chốt" + badge xanh
- [ ] Sau khi chốt → mở tab khác lead-form → nút Check UPS System **không** còn màu đỏ animate-pulse
- [ ] Bấm nút "⚡ Check UPS System" trên lead-form → popup hiện danh sách sale check-in hôm nay + tình trạng (Đang tiếp đón / Đang chờ / Offlist)
- [ ] Sale bấm "Đang tiếp đón" bên sbooking → sale bên scrm UPS chuyển sang trạng thái busy (`is_busy=true`)
- [ ] Sale bấm "Hoàn tất" → sale rảnh lại, có thể chia số tiếp

## 7. Reconcile drift

- [ ] Chạy `php artisan sb:reconcile-bookings --dry-run` → in stats (seen, matched, backfilled, status_changed, cv_synced)
- [ ] Chạy `php artisan sb:reconcile-bookings` → booking cũ có `sb_khung_gio_id`, `sb_dich_vu_id`, `scheduled_end_at` được backfill (nếu sbooking có data)
- [ ] `sync_status` cập nhật khớp trang_thai sbooking (`cho_duyet` → `synced`, `da_duyet` → `approved`, `da_toi` → `checkedin`, `da_xong` → `done`)

## 8. Auto-map users.sbooking_user_id

- [ ] Chạy `php artisan sb:sync-users` → in "Auto-map users↔sbooking: N mapped"
- [ ] `SELECT id, name, email, sbooking_user_id FROM users WHERE email LIKE 'hn.sale04%'` → có `sbooking_user_id` ≠ NULL

## 9. Permission CV-only (bug #5)

- [ ] Login user chỉ là CV của 1 booking → mở lead → **KHÔNG** thấy nút "Thêm booking / Đổi phase / Chia số / Đóng phase"
- [ ] Try đổi `name` → không đổi được (input readonly hoặc submit trả 403)
- [ ] Comment vẫn ghi được → save OK

## 10. Cascade UI

- [ ] `⚡rule-config.blade.php`: 4 select cascade (Công ty readonly + Địa điểm + Cơ sở + Phòng ban)
- [ ] `⚡lead-form.blade.php` "Chia số": checkbox "Kho chung công ty" + 4 select cascade — check "Kho chung" → cascade tự khóa, poolTarget='company'
- [ ] `⚡lead-pools.blade.php` filter Team tab: 3 select cascade Địa điểm → Cơ sở → Phòng ban
- [ ] `⚡lead-pools.blade.php` bulk/inline chuyển kho: `<optgroup>` group theo Địa điểm

## 11. Real-time (Reverb)

- [ ] `php artisan reverb:start` chạy → mở 2 tab lead cùng 1 lead → thao tác 1 tab → tab kia nhận toast "Cập nhật từ sbooking"
- [ ] Notification "Lead mới" toast → click → nhảy đúng lead

---

## Bug tracker (điền khi test)

| # | Ngày | Người test | Bug | Trạng thái |
|---|---|---|---|---|
|   |   |   |   |   |
