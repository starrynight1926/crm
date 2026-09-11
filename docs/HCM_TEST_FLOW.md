# HCM End-to-End Test Flow — Checklist

**Mục tiêu**: test 5 nguồn lead (MKT / BDM / Data lạnh / REF / Walk-in) qua trình duyệt, từ up lead → chia → đặt lịch → sync booking → chăm sóc → close. Test **cả Data Source (:1999) và Booking (:1995)**.

**Prerequisites** (đã seed sẵn):

| Vai trò | Data Source email | Booking username | Pass Data Source | Pass Booking |
|---|---|---|---|---|
| Team trực page (up MKT/BDM/Cold) | test.hcm.trucpage@longevity.com.vn | test.hcm.trucpage | 123456 | <pass-hcm> |
| CM booking (chia kho booking) | test.hcm.cmbooking@longevity.com.vn | test.hcm.cmbooking | 123456 | <pass-hcm> |
| Team booking 1 (đặt lịch) | test.hcm.booking1@longevity.com.vn | test.hcm.booking1 | 123456 | <pass-hcm> |
| Team booking 2 | test.hcm.booking2@longevity.com.vn | test.hcm.booking2 | 123456 | <pass-hcm> |
| CM sale (chia kho sale) | test.hcm.cmsale@longevity.com.vn | test.hcm.cmsale | 123456 | <pass-hcm> |
| Sale 1 (chăm sóc) | test.hcm.sale1@longevity.com.vn | test.hcm.sale1 | 123456 | <pass-hcm> |
| Sale 2 | test.hcm.sale2@longevity.com.vn | test.hcm.sale2 | 123456 | <pass-hcm> |

**Ghi chú common**: Cơ sở HCM slug `207nvt`. Trước mỗi test, mở 2 tab: Data Source (1999) + Booking (1995).

**Ký hiệu**: `[ ]` chưa test — `[x]` OK — `[!]` fail, ghi chú bên phải.

---

## Test 1 — Nguồn Marketing (MKT)

### T1.1 — Up lead
- [ ] Login Data Source bằng `test.hcm.trucpage@longevity.com.vn` / `123456`. → chuyển vào Dashboard.
- [ ] Vào **Khách hàng → Thêm mới**. Dropdown "Nhóm nguồn" hiện đủ 5 option nhưng chỉ **MKT / BDM / Data lạnh** enable (Team trực page chỉ có perm `lead.distribute_booking`).
- [ ] Điền: Tên `Khách MKT Test 1`, SĐT `0900011001`, Ngày thu thập hôm nay, Nhóm nguồn `Marketing`.
- [ ] Hint "Bước tiếp theo: chia về kho team, chờ CM chia cho nhân viên booking" hiện.
- [ ] Bấm **Lưu thông tin**. → Toast "Đã tạo lead mới". Mã KH dạng `KH-XXX-MKT`.
- [ ] Sau khi lưu → phase = `Booking · Chờ CM booking chia`, ở kho team `team-ashley-booking` (kho common công ty nếu chưa map).

### T1.2 — CM booking chia
- [ ] Logout → Login `test.hcm.cmbooking@longevity.com.vn` / `123456`.
- [ ] Vào **Vận hành → Kho Lead → tab Kho Team**. Thấy lead `KH-XXX-MKT` vừa tạo.
- [ ] Bấm ô lead → nút **Chia tay** → chọn nhân viên `Test HCM Booking 1` (`test.hcm.booking1`). → Toast confirm.
- [ ] Verify lead: owner_id = booking1, org = team-ashley-booking, pool_level = personal.

### T1.3 — Team booking đặt lịch
- [ ] Logout → Login `test.hcm.booking1@longevity.com.vn` / `123456`.
- [ ] Vào **Khách hàng → danh sách**. Thấy lead `KH-XXX-MKT` (owner=mình).
- [ ] Bấm tên khách → vào Chi tiết. Nút **Cập nhật thông tin** ẩn (Team booking readonly). Nút **Đặt booking** hiện (blue button).
- [ ] Bấm **Đặt booking → 🏥 Đặt phòng khám**. → Mở tab mới sang Booking `/207nvt/tao-moi?...&khach_ma=KH-XXX-MKT&return_url=...`.

### T1.4 — Bên Booking: đăng nhập + tạo lịch
- [ ] Bên Booking: đang chưa login → redirect login. Đăng nhập `test.hcm.booking1` / `<pass-hcm>` → vào form tạo mới.
- [ ] Form đã prefill Họ tên + SĐT (từ URL). Trường ẩn `khach_ma` đã set (verify qua devtools: `document.querySelector('input[name=khach_ma]').value === 'KH-XXX-MKT'`).
- [ ] Chọn Phòng, Khung giờ, Dịch vụ, Bác sĩ, **Sale phụ trách** (chọn 1 trong 3 CM sale HCM). Bấm **Lưu**.
- [ ] Redirect về Data Source `/leads/{id}/booking-callback?booking_ma=BKG-...&booking_id=...` → tự động về Chi tiết lead.
- [ ] Chi tiết lead: badge **📅 Đã đặt · BKG-YYMMDD-XXXXXX** hiện, phân loại = `Booking`, Lịch sử tương tác có 3 dòng mới (booking_status / note / classification).

### T1.5 — Check bên booking
- [ ] Bên Booking `/207nvt/xem-dat-phong/{id}` — verify khách hiển thị đúng info, `crm_khach_ma = KH-XXX-MKT` (query DB nếu cần).
- [ ] Đổi trạng thái khách → **Khách đã tới** → confirm popup có kèm câu "sẽ đẩy sang Data Source KH-XXX-MKT" → OK.
- [ ] Flash message xanh: "...Đã đẩy sang Data Source KH-XXX-MKT."
- [ ] Quay Data Source Chi tiết lead → badge chuyển **✅ Khách đã tới**. Log mới ghi "Booking BKG... — Khách đã tới".
- [ ] Test lần lượt: **Khách tới trễ** → badge ⏰. **Đã xong** → badge 🎉 (đây là priority cao nhất).

### T1.6 — Chuyển sang phase Sale
- [ ] Vẫn login booking1, quay Data Source Chi tiết lead. Nút **"Chuyển sang Sale"** hiện (canMoveToSale).
- [ ] Bấm → confirm "Xác nhận: khách đã đồng ý gặp. Chuyển lead sang phase Sale (Chờ chia)?" → OK.
- [ ] Lead phase đổi sang **Sale · Chờ chia**, owner_id = null, org_unit_id = team-ashley-sale.

### T1.7 — CM sale chia
- [ ] Logout → Login `test.hcm.cmsale@longevity.com.vn`. Vào Kho Lead → tab team → thấy lead.
- [ ] Chia cho `Test HCM Sale 1`. Verify owner = sale1.

### T1.8 — Sale chăm sóc
- [ ] Logout → Login `test.hcm.sale1@longevity.com.vn`. Vào Khách hàng → thấy lead.
- [ ] Vào Chi tiết → **Cập nhật thông tin** enable (Sale có perm `lead.update_sale`... wait, role "Sale" không có `update_sale`. Verify: nút bút chì ẨN → Sale bình thường không sửa info khi phase Sale — đúng thiết kế).
- [ ] Đổi Phân loại kết quả: `Booking` → `Show` → `Close`. Mỗi lần đổi có log Lịch sử.
- [ ] Thêm ghi chú với tick "🆕 Lần đầu" — nếu booking_status = da_xong / khach_da_toi / khach_toi_tre thì checkbox disabled.

### T1.9 — Bình luận booking sync
- [ ] Quay Booking → xem-dat-phong → **Thêm bình luận** "Khách hài lòng" → Gửi.
- [ ] Flash "Đã gửi bình luận. Đã đẩy sang Data Source KH-XXX-MKT."
- [ ] Data Source Chi tiết → Lịch sử có ghi chú "Bình luận Booking BKG...: Khách hài lòng".

**Kết quả Test 1**: `[ ]` PASS / `[ ]` FAIL — ghi chú: _____

---

## Test 2 — Nguồn Data lạnh (COLD)

Same flow như Test 1, chỉ đổi bước T1.1: chọn nhóm nguồn `Data lạnh`. Mã KH dạng `KH-XXX-COLD`.

- [ ] T2.1 Up lead COLD (Team trực page)
- [ ] T2.2 CM booking chia cho booking2
- [ ] T2.3 booking2 đặt lịch
- [ ] T2.4 Booking sync trạng thái
- [ ] T2.5 Chuyển sang Sale
- [ ] T2.6 CM sale chia cho sale2
- [ ] T2.7 Sale chăm sóc + close

**Kết quả**: `[ ]` PASS / `[ ]` FAIL — ghi chú: _____

---

## Test 3 — Nguồn BDM

Same flow, chọn `BDM`. Mã KH `KH-XXX-BDM`. Note: BDM khá giống MKT, không có gì đặc biệt trừ mã.

- [ ] T3.1 Up lead BDM
- [ ] T3.2 CM booking chia
- [ ] T3.3 Đặt lịch
- [ ] T3.4 Sync
- [ ] T3.5 Chuyển Sale + chia + close

**Kết quả**: `[ ]` PASS / `[ ]` FAIL — ghi chú: _____

---

## Test 4 — Nguồn Bạn giới thiệu (REF)

**Đặc thù**: sale up trực tiếp, tự nhận, không qua team booking.

### T4.1 — Sale up lead
- [ ] Login `test.hcm.sale1@longevity.com.vn`.
- [ ] Vào Khách hàng → Thêm mới. Dropdown Nhóm nguồn: chỉ **Bạn giới thiệu / Khách tự đến** enable (role Sale không có `distribute_booking`/`distribute_ctv`).
- [ ] Chọn `Bạn giới thiệu`. Hint: "Bước tiếp theo: lead sẽ tự động chia cho bạn (Test HCM Sale 1)".
- [ ] Điền info → Lưu. Verify: owner_id = sale1 auto, phase = Sale · Đang chăm sóc, mã `KH-XXX-REF`.

### T4.2 — Sale đặt booking cho khách REF
- [ ] Chi tiết lead: nút **Đặt booking** hiện (Sale có perm `book_action` qua override `isDirectSaleSource + isOwnedBy`).
- [ ] Nếu chưa có facility → check helper `resolvedBookingSlug()` fallback: owner của lead thuộc `branch-hcm` → slug = `207nvt`. Nút enable.
- [ ] Bấm Đặt booking → mở booking.

### T4.3 — Booking + sync
- [ ] Login booking bằng `test.hcm.sale1` (nếu chưa) → tạo lịch → callback về Data Source.
- [ ] Verify tương tự T1.4.

### T4.4 — Chăm sóc
- [ ] Trở lại Data Source, sale1 update phân loại → close.
- [ ] Sale1 có sửa được info cá nhân không (SĐT/tên)? Test qua nút bút chì. **Yes** vì override `isDirectSaleSource && isOwnedBy` cho `canEditPersonalInfo`.

**Kết quả**: `[ ]` PASS / `[ ]` FAIL — ghi chú: _____

---

## Test 5 — Nguồn Khách tự đến (Walk-in)

**Đặc thù**: sale up nhưng lead vào kho team, chờ CM sale chia.

### T5.1 — Sale up lead Walk-in
- [ ] Login `test.hcm.sale2@longevity.com.vn`.
- [ ] Thêm mới → chọn `Khách tự đến`. Hint: "Bước tiếp theo: chia về kho team, chờ CM team sale chia."
- [ ] Điền info → Lưu. Verify: owner_id = null, org_unit_id = team-ashley-sale, phase = Sale · Chờ chia, mã `KH-XXX-WI`.

### T5.2 — CM sale chia
- [ ] Logout → Login CM sale. Vào Kho Lead → chia cho `Test HCM Sale 2`.

### T5.3 — Sale chăm sóc + booking
- [ ] Login sale2 → chi tiết lead. Owner=sale2. Nút bút chì "Cập nhật thông tin" hiện (walk_in + owner override).
- [ ] Nút Đặt booking hiện.
- [ ] Đặt lịch → sync tương tự Test 4.

**Kết quả**: `[ ]` PASS / `[ ]` FAIL — ghi chú: _____

---

## Test 6 — Cross-cutting: đồng bộ nút "Đồng bộ Booking"

Dành cho case booking tạo trực tiếp bên booking side (KHÔNG qua nút Đặt booking Data Source → thiếu `crm_khach_ma`). Bấm nút Đồng bộ Booking bên Data Source để sync manual.

- [ ] Với 1 trong 5 lead đã tạo, xóa `booking_ma` + đổi `booking_status='not_booked'` trực tiếp DB (giả lập chưa sync).
- [ ] Vào Chi tiết → bấm **Đồng bộ Booking**.
- [ ] Nếu có booking match theo SĐT bên Booking → update booking_status + booking_ma + classification=booking. Toast xanh: "Đã đồng bộ booking BKG-... từ Booking".
- [ ] Nếu không có → Toast: "Bên Booking chưa có lịch nào cho SĐT này."

**Kết quả**: `[ ]` PASS / `[ ]` FAIL — ghi chú: _____

---

## Test 7 — Perm gates (nhanh)

- [ ] Sale1 (không phải owner) mở URL trực tiếp `/leads/{id}` của lead khác owner → 403 hoặc không thấy trong danh sách.
- [ ] Team booking1 vào Cập nhật lead phase Booking → tất cả input disabled + banner "Chế độ chỉ đọc".
- [ ] Sale1 mở form Cập nhật lead phase Sale (không phải nguồn REF/WI + không phải owner) → nút Lưu ẩn.
- [ ] Test dropdown "Nhóm nguồn" cho từng role: Team trực page thấy MKT/COLD/BDM. CM booking thấy MKT/COLD/BDM (có `distribute_booking`). CM sale thấy đủ 5 (có `distribute_sale + distribute_ctv`). Sale chỉ thấy REF/WI.

**Kết quả**: `[ ]` PASS / `[ ]` FAIL — ghi chú: _____

---

## Tổng kết

- Test 1 (MKT): `[ ]`
- Test 2 (COLD): `[ ]`
- Test 3 (BDM): `[ ]`
- Test 4 (REF): `[ ]`
- Test 5 (Walk-in): `[ ]`
- Test 6 (Sync manual): `[ ]`
- Test 7 (Perm gates): `[ ]`

**Bug tổng hợp** (điền vào khi tick FAIL):
1. ...
2. ...

**Ghi chú kỹ thuật** (issue lặp giữa các nguồn):
- ...
