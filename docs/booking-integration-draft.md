# Booking Integration — Draft (chưa chốt, để research tiếp)

> Ghi lại 2026-07-20. Chưa code. Chờ user chốt 4 câu cuối trước khi bắt tay.

## Bối cảnh
Hiện tại nút "Mở Booking" trong `⚡lead-detail` chỉ redirect sang `{BOOKING_URL}/handoff?crm_lead_id=X&name=Y&phone=Z` — team booking bên đó nhập lịch tay, **không có callback về CRM**. Cần dựng 2 chiều để CRM biết khách đã đặt lịch gì, giờ nào, bác sĩ nào.

## API contract đề xuất (Booking-side sẽ implement)

### GET `{BOOKING_API_URL}/facilities`
Trả cơ sở + phòng.
```json
{
  "facilities": [
    {"id": 1, "name": "59 NTN", "rooms": [{"id": 10, "name": "P.101"}]}
  ]
}
```

### GET `{BOOKING_API_URL}/services`
Danh mục dịch vụ.
```json
{
  "services": [
    {"id": 5, "name": "Laser combo", "duration_min": 60}
  ]
}
```

### GET `{BOOKING_API_URL}/slots?facility_id=1&service_id=5&date=2026-07-25`
Khung giờ khả dụng cho combo (cơ sở + dịch vụ + ngày).
```json
{
  "date": "2026-07-25",
  "slots": [
    {"start": "09:00", "end": "10:00", "available": true},
    {"start": "10:00", "end": "11:00", "available": false}
  ]
}
```

### POST `{BOOKING_API_URL}/appointments`
CRM gửi form đặt lịch. Booking check availability → OK thì ghi.

Request:
```json
{
  "crm_lead_id": 49,
  "customer": {"name": "Nguyễn Thị Ngọc Ánh", "phone": "0912345678"},
  "facility_id": 1,
  "room_id": 10,
  "service_id": 5,
  "start_at": "2026-07-25T09:00:00"
}
```

Response OK:
```json
{"status": "ok", "appointment_id": "APT-001", "code": "BK-2026-07-25-001"}
```

Response conflict (slot đã bị đặt):
```json
{"status": "conflict", "reason": "Slot đã bị đặt bởi khách khác"}
```

## Trên CRM sẽ code

### 1. Bảng `lead_appointments`
```
id, lead_id (FK cascade),
booking_appointment_id (string, ref bên booking),
facility_id_ext, facility_name_snapshot,
room_id_ext, room_name_snapshot,
service_id_ext, service_name_snapshot,
start_at (datetime), duration_min,
status: booked | cancelled,
created_by (FK users),
timestamps
```
Snapshot tên để CRM hiển thị đúng dù bên booking rename sau này (cần chốt câu 4).

### 2. `App\Services\BookingClient`
Class wrapper cho 4 endpoint trên. Auth qua `BOOKING_API_TOKEN` (config/services.php đã có).

### 3. Livewire modal `book-appointment`
Thay nút "Mở Booking" cũ. Flow:
1. Mở modal → gọi GET /facilities + /services (cache 10 phút).
2. User chọn cơ sở → auto load phòng.
3. Chọn dịch vụ → chọn ngày → gọi GET /slots.
4. Chọn slot khả dụng → Submit.
5. POST /appointments → hiển thị kết quả:
   - OK: lưu row `lead_appointments`, auto set `pipeline_phase=sale/waiting_distribute`, log audit.
   - Conflict: toast lỗi, không lưu.

### 4. Tab Insight hiển thị timeline appointment
Trong `⚡lead-detail` tab Insight (đã có), thêm section "Lịch hẹn" liệt kê `lead_appointments` gần nhất.

## 4 câu cần user chốt trước khi tao code

1. **Auth**: dùng `BOOKING_API_TOKEN` bearer trong header `Authorization: Bearer <token>`? Hay HMAC ký request (an toàn hơn nhưng phức tạp)?
2. **Cache**: facilities/services đổi ít → cache 10 phút OK? (Slots không cache — real-time)
3. **Nút cũ "Mở Booking"** (redirect tab mới): **thay hoàn toàn** bằng modal API, hay **giữ cả 2** (đặt qua API + fallback mở tab khi API xuống)?
4. **Snapshot** trong `lead_appointments`: lưu **full snapshot** (facility/room/service tên) đề phòng booking đổi tên sau này? Hay chỉ ID + call API lấy tên khi hiển thị (nhẹ DB nhưng phụ thuộc booking online)?

## Ghi chú kỹ thuật khác

- Config đã có sẵn: `config('services.booking.url')`, `booking.api_url`, `booking.api_token` (Phase trước).
- Nút "Chuyển sang Sale" hiện tại (`moveToSalePhase()`) → có thể tự trigger sau khi booking OK, khỏi phải bấm tay.
- Nếu booking bên kia có cron cancel appointment → cần webhook về CRM để update `lead_appointments.status`.

## Trạng thái
Chờ user research tiếp + trả lời 4 câu → tao code.
