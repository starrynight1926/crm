# Mẫu báo cáo Excel — Thông tin khách hàng cơ bản

> Trạng thái: **CHƯA LÀM** — note lại để xử lý sau (2026-07-10).
> Yêu cầu: xuất Excel các trường thông tin khách hàng cơ bản (từ **STT** tới **Tần suất quay lại** thì freeze cột).

## 1. Danh sách trường cần xuất

| # | Trường | Giải thích |
|---|---|---|
| 1 | STT | Số thứ tự khách hàng/bản ghi. |
| 2 | NGÀY GHI NHẬN DOANH THU | Ngày phát sinh doanh số đầu tiên của khách hàng. |
| 3 | HÌNH ẢNH | Link hình ảnh khách hàng. Mỗi lần khách dùng dịch vụ đều lưu ảnh để đánh giá hiệu quả trước/sau. |
| 4 | MÃ KHÁCH | Mã định danh khách hàng trong hệ thống. |
| 5 | TÊN KHÁCH | Họ tên khách hàng. |
| 6 | CƠ SỞ | Chi nhánh/cơ sở khách hàng sử dụng dịch vụ. |
| 7 | TẦN SUẤT QUAY LẠI | Tự động tính, không nhập tay. Mỗi lần phát hiện khách trùng mã thì +1. |
| 8 | NGÀY SINH | Ngày tháng năm sinh của khách hàng. |
| 9 | ĐỊA CHỈ | Địa chỉ liên hệ của khách hàng. |
| 10 | KHAI THÁC TIỀN SỬ | Bệnh lý, dịch vụ đã từng dùng ở đâu, tiền sử liên quan. |
| 11 | NGHỀ NGHIỆP | Nghề nghiệp hiện tại của khách hàng. |
| 12 | PHÂN LOẠI KHÁCH | Nhóm khách hàng (VIP, tiềm năng, mới, cũ,...). |
| 13 | GHI CHÚ | Thông tin bổ sung khác. |
| 14 | NGUỒN | Nguồn khách đến từ đâu (Facebook, Google, giới thiệu, TikTok,...). |
| 15 | BÁC SĨ TƯ VẤN | Bác sĩ thực hiện tư vấn ban đầu. |
| 16 | CHUYÊN VIÊN TƯ VẤN 1 | Chuyên viên tư vấn chính. |
| 17 | CHUYÊN VIÊN TƯ VẤN 2 | Chuyên viên tư vấn hỗ trợ thứ 2. |
| 18 | CHUYÊN VIÊN TƯ VẤN 3 | Chuyên viên tư vấn hỗ trợ thứ 3. |
| 19 | DỊCH VỤ | Tên dịch vụ hoặc tổng gói dịch vụ khách hàng sử dụng. |

**Freeze cột**: từ STT (#1) tới Tần suất quay lại (#7).

## 2. Đối chiếu với schema hiện tại

| # | Trường | Hiện có? | Nguồn / Ghi chú |
|---|---|---|---|
| 1 | STT | ✅ suy ra | Số dòng khi export, không cần lưu |
| 2 | NGÀY GHI NHẬN DOANH THU | ⚠️ chưa có | = `MIN(payments.paid_at)` của khách; cần logic tính |
| 3 | HÌNH ẢNH | ❌ thiếu | Không có chỗ lưu; ảnh trước/sau mỗi lần dùng DV → nên gắn `customer_service_phases` |
| 4 | MÃ KHÁCH | ✅ | `leads.code` (`KH-{id}-...`) |
| 5 | TÊN KHÁCH | ✅ | `leads.name` |
| 6 | CƠ SỞ | ⚠️ mơ hồ | `region`=KHU VỰC, `org_unit`=team giữ lead; "cơ sở khách dùng DV" chưa có field đúng nghĩa |
| 7 | TẦN SUẤT QUAY LẠI | ❌ thiếu (field + logic) | Cần counter tự tăng; **xung đột** với chống trùng hiện tại (gộp theo `phone`) |
| 8 | NGÀY SINH | ❌ thiếu | ứng viên `custom_field` |
| 9 | ĐỊA CHỈ | ❌ thiếu | ứng viên `custom_field` |
| 10 | KHAI THÁC TIỀN SỬ | ❌ thiếu | ứng viên `custom_field` |
| 11 | NGHỀ NGHIỆP | ❌ thiếu | ứng viên `custom_field` |
| 12 | PHÂN LOẠI KHÁCH | ❌ thiếu | **≠** `classification` (đó là phân loại KẾT QUẢ funnel). Cần field mới cho nhóm VIP/tiềm năng |
| 13 | GHI CHÚ | ✅ | `leads.note` |
| 14 | NGUỒN | ⚠️ gần đúng | `ad_source`; "giới thiệu/tự đến" cần map thêm |
| 15 | BÁC SĨ TƯ VẤN | ❌ thiếu | Không có field |
| 16 | CHUYÊN VIÊN TƯ VẤN 1 | ⚠️ | Có thể là `owner_id`? chưa rõ |
| 17 | CHUYÊN VIÊN TƯ VẤN 2 | ❌ thiếu | |
| 18 | CHUYÊN VIÊN TƯ VẤN 3 | ❌ thiếu | |
| 19 | DỊCH VỤ | ✅ suy ra | `customer_services → services` |

## 3. Phân nhóm gap — ĐÃ XỬ LÝ (2026-07-12)

**A. Custom fields mức công ty** (seed bởi `ReportCustomFieldSeeder`):
Ngày sinh, Địa chỉ, Khai thác tiền sử, Nghề nghiệp, Phân loại khách (VIP/Tiềm năng/Mới/Cũ).

**B. Bảng riêng** — 2 bảng mới `facilities` + `staff_members`:
- Cơ sở → FK `leads.facility_id` → bảng `facilities` (cây: Cơ sở > Phòng ban).
- Bác sĩ tư vấn → FK `leads.doctor_id` → bảng `staff_members` (role=doctor).
- Chuyên viên tư vấn 1/2/3 → FK `leads.consultant_1_id / 2 / 3` → `staff_members` (role=consultant).
- Dropdown hiện theo nhóm Cơ sở › Phòng ban, có ô search lọc theo tên.

**C. Logic đếm tần suất quay lại:**
- Trong mục ghi chú (lead_status_logs): 2 checkbox exclusive "Khách tới lần đầu" (`is_first_visit`) và "Khách trở lại" (`is_return`).
- Tần suất = COUNT(is_return = true) của lead.
- Tick "Khách trở lại" bắt buộc nhập mã tiếp đón (reception_code, unique).

**D. Còn lại (chưa làm):**
- Ngày ghi nhận doanh thu — tính từ `MIN(payments.paid_at)`.
- Hình ảnh — đã có `lead_status_logs.images` (ảnh đính kèm ghi chú), cần xác nhận đủ yêu cầu chưa.

## 4. Quyết định đã chốt (2026-07-12)

1. **Nhóm A**: `custom_fields` mức công ty (org_unit_id = null). ✅
2. **Tần suất quay lại**: 1 lead + đếm số ghi chú tick "Khách trở lại". ✅
3. **Bác sĩ/Chuyên viên**: chọn từ bảng `staff_members`, nhóm theo `facilities` (Cơ sở > Phòng ban). Không phải user hệ thống. ✅
