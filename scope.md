# Lara-SCRM — Scope & Thiết kế tổng quan

> Cập nhật: 2026-07-03 — chốt sau trao đổi ban đầu. File này là nguồn tham chiếu chính của dự án.

## 1. Mục tiêu

Phần mềm CRM quản lý data khách hàng (lead) + phân bổ data cho nhân viên sale, hỗ trợ tổ chức nhiều phòng ban với vai trò chồng chéo (một người thuộc team A nhưng giữ vai trò ở team B).

Thiết kế UI tham khảo: Figma "[Longevity] Phần mềm" — 4 màn hình: Dashboard Tổng Quan, Danh Sách Khách Hàng, Thêm Mới/Cập Nhật Khách Hàng, Chi Tiết & Ghi Chú Khách Hàng.

## 2. Quy mô & ràng buộc

- ~200–300k lead. Cần index tử tế, phân trang server-side, báo cáo chạy aggregate riêng.
- Data nhạy cảm (SĐT khách): che SĐT theo quyền, audit log, chống trùng lead.

## 3. Kiến trúc dữ liệu — 2 DB (raw zone / clean zone)

### PostgreSQL — tầng hứng (raw)
- Mọi nguồn lead đổ vào bảng `raw_leads` với cột **JSONB** — schema linh hoạt, camp mới thêm field không cần migrate.
- GIN index trên JSONB.
- Lead lỗi/không chuẩn hóa được → trạng thái `failed` kèm lý do để marketing soi lại.

### MySQL — tầng chuẩn (clean)
- Hệ thống, phòng ban, nhân sự, vai trò, quy tắc chia số, log phân bổ, cấu hình.
- Lead đã chuẩn hóa — mọi nghiệp vụ (chia số, chăm sóc, báo cáo) chạy ở đây.

### Pipeline chuẩn hóa (raw → clean)
- Job đọc raw → validate/chuẩn hóa SĐT → check trùng → ghi sang MySQL kèm `raw_lead_id` để truy vết ngược.
- Chia số chỉ chạy sau khi lead đã sang tầng chuẩn.
- Nghiệp vụ nằm trọn bên MySQL → không có vấn đề join chéo DB.

## 4. Trường dữ liệu lead

Ngày, PAGE, Tên, SĐT, Camp, Insight, Link, Nguồn quảng cáo, Người nhận LEAD, CHIA CHO, Ghi nhận tình trạng lần 1, Ghi nhận tình trạng lần 2, NOTE, KHU VỰC, PHÂN LOẠI KẾT QUẢ.

### 4.1 Mã khách hàng (bổ sung 2026-07-03, theo whiteboard)

- Định dạng: `KH-{số}-{loại}[-{nguồn}]` — VD `KH-00123-MKT-FB`, `KH-00045-C-A`.
- **Số**: tăng dần toàn hệ thống, là định danh duy nhất của khách.
- **Loại data** (5 loại): `MKT` (Data Marketing), `C` (Data lạnh/telesale), `BDM`, `SI` (Tự giới thiệu — sale), `N` (Tự đến).
- **Nguồn** (tùy chọn, nối thêm khi cần tag/tracking): FB, GG, TT... — sinh tự động từ nguồn quảng cáo, sửa được.
- Mã sinh tự động khi lead vào hệ thống.

### 4.2 Trường tùy biến theo phòng ban (bổ sung 2026-07-03)

- Mỗi phòng ban có bộ trường tùy biến riêng (đánh dấu riêng), do **admin của phòng đó** định nghĩa (quyền `field.manage` gán qua assignment tại phòng); admin công ty quản lý được tất cả.
- Trường mức **công ty** (không gắn phòng nào): mọi bộ phận đều thấy; trường bắt buộc mức công ty thì ai cũng phải điền.
- Trường **bắt buộc theo rule từng phòng**: phòng MKT cần 5 trường, phòng khác cần 10 trường đều cấu hình được.
- Bộ trường áp vào lead theo **phòng ban đang giữ lead** (org_unit) + các phòng cha; lead chuyển phòng thì bộ trường đổi theo.
- Kiểu trường: text, số, ngày, select (danh sách chọn).

### 4.3 Backlog sau Phase 8 (chưa làm, cần bàn thêm)

- Quy định sửa theo role từng trường + **quy trình tuần tự** (người A cập nhật xong mới tới người B) — whiteboard note "cần làm rõ hơn".
- Báo cáo tùy chỉnh cho từng phòng ban.
- **Loại data cấu hình được**: 5 loại (MKT/C/BDM/SI/N) đang là hằng số trong code — nếu admin cần tự thêm/sửa loại thì chuyển thành bảng cấu hình.
- **Kho data Ebiz / PMDK** (2 nhánh trong whiteboard): hệ thống ngoài, chưa rõ là gì và có cần đồng bộ không — chờ user mô tả thêm.

**Trạng thái phân loại lead**: Lead, Follow, Nét, Tài chính yếu, Quan tâm, Tham khảo, Tìm hiểu, Gọi lại sau, KLLD, Missed, Booking, Show, Close.

**Số liệu tổng hợp theo tháng** (hệ thống tự tính, không nhập tay): Total, Lead, Follow, Nét, Booking, Show, Close.

## 5. Nguồn lead (đủ 4)

1. Import Excel/CSV thủ công
2. Tự động từ Ads API (Facebook Lead Form / TikTok / Google Ads)
3. Nhập tay từng lead trên form
4. Webhook từ landing page

## 6. Chia số (phân bổ lead)

- **2 cấp**: rule chia lead về team → rule trong team chia tiếp cho từng sale.
- **Engine rule cấu hình được** (admin tạo/sửa không cần code):
  - Chia lần lượt (round-robin)
  - Chia theo tỉ trọng (VD 5-3-2, 5-12-8)
  - Chia theo doanh thu cao nhất
  - Chia theo tỉ lệ thành công (close rate)
  - Mở rộng thêm rule mới sau này
- **Thu hồi/chia lại lead** — 3 chế độ cấu hình được:
  - Tự động theo SLA (quá X giờ không chăm → thu hồi, chia lại)
  - Thủ công (quản lý rút và chia lại bằng tay)
  - Tắt (lead đã chia là cố định)

### 6.1 Cấu trúc rule (3 phần)
1. **Điều kiện lọc (matching)**: lead nào áp rule — theo khu vực, camp, nguồn quảng cáo, PAGE... Rule có thứ tự ưu tiên, khớp rule đầu tiên thì dừng; không khớp → nằm lại kho chung chờ chia tay.
2. **Đích + thuật toán chia (strategy)**: danh sách team/sale + thuật toán (round-robin, tỉ trọng 5-3-2..., doanh thu, tỉ lệ close).
3. **Ràng buộc nhận số (constraints)**: sale bật/tắt nhận số, nghỉ phép tự loại khỏi vòng chia, trần lead.

### 6.2 Chi tiết đã chốt
- **Cửa sổ tính doanh thu / tỉ lệ close**: mặc định theo ngày; cấu hình được theo tuần, tháng, hoặc khoảng thời gian tùy chọn trên từng rule.
- **3 cấp kho**: kho chung → kho team → kho cá nhân. Lead về là chia ngay, **không phụ thuộc giờ làm việc**.
- **Trần lead**: setup được ở cả 3 cấp — phòng ban, team, cá nhân. Chạm trần thì nhảy đích kế tiếp.
- **Tự kéo lead từ kho**: được, nếu role có quyền (quyền "kéo lead từ kho" trong RBAC).

## 7. Tổ chức & phân quyền

Mô hình 2 lớp tách biệt:

### 7.1 Quyền chức năng (RBAC — role tự định nghĩa)
- Admin tự tạo role, tích checkbox từng quyền: xem/tạo/sửa/xóa lead, import, export, chia số, thu hồi, cấu hình rule chia, quản lý user/team, xem báo cáo...
- Quyền **export** gắn trên role, mặc định tắt. Mọi lần export ghi audit log.

### 7.2 Phạm vi dữ liệu (data scope)
- Cấu hình riêng, độc lập với role, 3 mức: **Chỉ dữ liệu bản thân** / **Chỉ dữ liệu team** / **Chọn phòng ban cụ thể**.
- UI chọn scope: **sơ đồ cây tổ chức + checkbox** — tích node nào thì thấy dữ liệu node đó (và con của nó).

### 7.3 Assignment (user – role – team, nhiều-nhiều)
- 1 user gắn nhiều assignment, mỗi assignment = (role + team + data scope).
- VD case thực tế: sale team A kiêm quản lý team B → 2 assignment: (Sale, team A, scope bản thân) + (Manager, team B, scope team B).

### 7.4 Bảo vệ dữ liệu
- Che SĐT theo scope: ngoài phạm vi được cấp thì SĐT bị mask.
- Audit log: ai xem/sửa/export/chia gì, khi nào.
- Chống trùng: SĐT đã tồn tại thì gộp, không chia mới.

### 7.5 Cây tổ chức
- Cây đệ quy **sâu tùy ý** (không giới hạn cấp) — mở chi nhánh/nhóm mới không phải sửa cấu trúc.
- Data scope tích theo node: thấy node đó và toàn bộ node con.

## 8. Luồng lead (lifecycle)

1. Lead về (4 nguồn) → Postgres raw
2. Pipeline chuẩn hóa → check trùng → vào MySQL, trạng thái `Mới`, nằm trong **kho chung (lead pool)**
3. Engine chia số: rule cấp 1 chia từ kho chung về team → rule cấp 2 chia cho sale → sale nhận thông báo realtime
4. Sale gọi → ghi nhận tình trạng lần 1, lần 2 → gắn phân loại (Follow, Nét, Quan tâm, Tài chính yếu, Tham khảo, Tìm hiểu, Gọi lại sau...)
5. Booking → Show → Close (hoặc rơi vào KLLD / Missed)
6. Quá SLA không chăm → thu hồi, quay lại bước 3 (nếu bật chế độ auto)

## 8.1 Dịch vụ gắn vào khách & theo dõi phase

- **Danh mục Dịch vụ** (dạng sản phẩm): mỗi dịch vụ có **giá** và **các phase** (VD dịch vụ da liễu 10 phase).
- **Cách tính giá — tùy từng dịch vụ chọn 1 trong 2**: giá trọn gói (phase chỉ là mốc tiến độ) hoặc giá theo từng phase (khách trả đến đâu tính đến đó).
- **Thanh toán**: ghi từng lần thu tiền của khách (ngày, số tiền, người thu, gắn vào dịch vụ/phase) → báo cáo doanh thu thực thu + công nợ còn lại.
- Khách hàng được gắn 1 hoặc nhiều dịch vụ. Mỗi dịch vụ của khách theo dõi **tiến độ theo phase**: phase nào xong, **ai làm**, ngày làm, **note bàn giao**.
- Case điển hình: sale A làm xong 3/10 phase dịch vụ da liễu → bàn giao sale B care tiếp → B thấy rõ lịch sử 3 phase trước + note của A, ghi tiếp từ phase 4.
- Lịch sử tham gia lấy từ chính dữ liệu phase này (ghi tường minh, không suy đoán tự động).

## 8.2 Nhiều người tham gia 1 lead & chia % đóng góp

- Một lead/deal qua tay nhiều người: người thu thập (nhập/kéo data), care lần 1, care lần 2, người làm từng phase...
- Khi deal **Close thành công**: **lead team đánh % đóng góp** cho từng người tham gia (tổng 100%), tham khảo lịch sử phase ở mục 8.1.
- Admin đặt được **mẫu % mặc định** (VD thu thập 20% – care1 30% – care2 50%), lead team chỉ sửa khi cần.
- % này dùng để tính doanh thu ghi nhận từng người trong báo cáo hiệu suất / KPI / rule chia theo doanh thu.

## 9. Báo cáo & Dashboard

### Bộ báo cáo
1. **Dashboard tổng quan** (theo Figma): lead hôm nay, funnel tháng hiện tại, top sale, lead chưa chăm/quá SLA.
2. **Funnel theo kỳ**: Total → Lead → Follow → Nét → Booking → Show → Close + tỉ lệ chuyển đổi từng bước; cắt theo tháng/tuần/khoảng tùy chọn.
3. **Hiệu quả marketing**: theo camp / nguồn quảng cáo / PAGE — lead về, tỉ lệ close.
4. **Hiệu suất sale/team**: số nhận, tỉ lệ close, doanh thu, xếp hạng.
5. **Báo cáo chia số**: log phân bổ, thu hồi, tồn kho từng cấp.

### Đã chốt
- **Doanh thu + dịch vụ/sản phẩm**: có danh mục dịch vụ/sản phẩm, deal Close gắn dịch vụ + số tiền → báo cáo được theo dịch vụ; rule chia theo doanh thu dùng số này.
- **Export Excel theo quyền** (quyền export trên role, ghi audit log). Không cần báo cáo tự gửi định kỳ (có thể thêm sau).
- **Độ tươi dashboard**: refresh 1–3 phút (polling/cache), không cần realtime từng giây; số liệu lịch sử tính sẵn (aggregate theo giờ/đêm).

## 10. Danh sách màn hình (đủ 17 màn, đã có design Figma)

Figma: [Longevity] Phần mềm — https://www.figma.com/design/Y4WHZMrVu4TZL8sjft3TCt/

1. Đăng nhập hệ thống
2. Quản lý phiên đăng nhập (end session từ xa)
3. Quản lý nhân viên & Phân quyền
4. Thiết lập vai trò & Quyền hạn (RBAC checkbox)
5. Sơ đồ Tổ chức & Phạm vi Dữ liệu (cây + checkbox data scope)
6. Dashboard Tổng Quan
7. Danh Sách Khách Hàng
8. Thêm Mới / Cập Nhật Khách Hàng
9. Chi Tiết & Ghi Chú Khách Hàng
10. Chi Tiết KH — Popup % Đóng góp khi Close
11. Cấu hình Chia số & Rule lead
12. Quản lý Kho Lead tập trung (kho chung/team/cá nhân)
13. Import dữ liệu khách hàng (Excel/CSV)
14. Quản lý Kết nối & Lead Lỗi (Ads API, webhook, raw lead failed)
15. Quản lý & Theo dõi Dịch vụ (danh mục + phase)
16. Ghi nhận Thu tiền & Công nợ
17. Báo cáo Funnel & Hiệu suất Marketing

## 11. Tech stack

- **Backend**: Laravel + Sanctum (auth token, quản lý session, **end session từ xa** — liệt kê thiết bị đăng nhập, thu hồi token từng thiết bị)
- **Frontend không dùng npm**: Blade + Livewire (composer) + Alpine.js qua CDN
- **Realtime**: Laravel Reverb (websocket, client Echo qua CDN) — thông báo "có lead mới về" tức thì; Livewire polling làm phương án phụ
- **DB**: PostgreSQL (raw) + MySQL (clean) — Laravel multi-connection
