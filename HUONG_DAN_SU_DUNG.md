# Hướng dẫn sử dụng — Lara SCRM

Tài liệu hướng dẫn cho người dùng cuối theo từng vai trò. Đăng nhập tại **http://lara-datasource.test:81** (dev) hoặc URL nội bộ.

_Cập nhật: 2026-08-04_

---

## Mục lục

1. [Chung — mọi vai trò](#chung--mọi-vai-trò)
2. [Trực Page](#trực-page-up-lead-mkt)
3. [BO Lễ Tân](#bo-lễ-tân)
4. [Team Sale (Sale nhân viên)](#team-sale-sale-nhân-viên)
5. [Team Tele / Team Booking](#team-tele--team-booking)
6. [CM Sale](#cm-sale)
7. [CM Booking / CM Tele](#cm-booking--cm-tele)
8. [DM & Admin](#dm--admin)

---

## Chung — mọi vai trò

### Đăng nhập
- Vào **http://lara-datasource.test:81/login**
- Nhập email công ty + mật khẩu (BO cấp cho bạn khi tạo tài khoản).

### Trang chủ
- Sau khi đăng nhập bạn thấy **Dashboard** với 5 ô hôm nay:
  - **UPS hôm nay** — bao nhiêu cơ sở đã chốt UPS (chỉ BO/CM/Admin thấy)
  - **Khách mới hôm nay** — tất cả lead nhận trong ngày
  - **Khách bạn được nhận (7 ngày)** — Sale thấy
  - **Chờ duyệt** — CM/Admin có quyền duyệt thấy
  - **Chờ chia** — CM/Team Booking thấy
- Bấm vào ô nào sẽ nhảy sang trang chi tiết.

### Thông báo
- Chuông trên góc phải nháy khi có: lead mới, booking đổi trạng thái, tin nhắn.
- Bấm chuông xem chi tiết, bấm 1 mục để nhảy đến lead.

### Đổi mật khẩu
- Bấm avatar góc phải → **Đổi mật khẩu**.

---

## Trực Page (up lead MKT)

### Công việc chính
Bạn nhận data khách marketing từ nhiều nguồn (post, ads, page…) và up lên hệ thống để phòng Booking gọi.

### Cách up 1 lead
1. Bấm **+ Thêm mới lead** trên góc phải.
2. Điền:
   - **Họ tên**, **SĐT**, **Ngày nhập** (tự điền ngày hôm nay)
   - **Nhóm nguồn**: chọn `Marketing`, `Marketing BR`, hoặc `BDM`
   - **Link nguồn** (link post/ad), **Insight** (mô tả khách nói gì)
3. Ở mục **Chia số**:
   - **Không** tick "Kho chung công ty"
   - Chọn cascade: Địa điểm → Cơ sở → (không cần chọn Phòng ban)
   - Hệ thống **tự động chia** lead cho 1 sale trong MKT List UPS của cơ sở đó (không cần chọn tay).
   - Bạn thấy flash xanh: _"MKT List: Đã chia lead cho [tên sale]."_
4. Bấm **Lưu**.

### Nếu bị chặn "UPS chưa chốt hôm nay"
- Nghĩa là BO/Lễ Tân của cơ sở đó chưa chốt UPS đầu ngày.
- Bạn không thể up lead cho cơ sở này. Liên hệ BO cơ sở chốt UPS trước.

### Xem lại lead đã up
- Bấm **Khách hàng > Danh sách** → filter theo "Người nhập" = bạn.
- Bạn có thể sửa thông tin những lead do CHÍNH BẠN up (không phải lead của người khác).

---

## BO (Lễ Tân)

### Công việc chính
Quản UPS check-in đầu ngày. **Sale không thể nhận lead cho tới khi bạn chốt UPS**.

### Quy trình đầu ngày (thứ tự bắt buộc)
1. Vào menu **UPS SYSTEM > Check-in (BO)**.
2. Với mỗi sale đến clinic:
   - Chọn tên sale từ dropdown "1. Chọn nhân viên…"
   - Chọn **Vị trí**:
     - **Tiếp đón**: sale sẽ vào bảng A/B/C/OFF (theo giờ + doanh thu hôm trước)
     - **Nhận số (MKT)**: sale nhận lead MKT trực tiếp
   - Chọn **Tier**:
     - **Tự động**: đến trước cutoff = A, sau cutoff = OFF
     - Hoặc chọn tay A/B/C/OFF
   - Bấm **+ Check in**.
3. Khi đủ nhân sự, bấm nút vàng **Chốt UPS hôm nay** ở cơ sở → UPS được khoá + mở khoá chia số cho cả ngày.

### Nếu cần sửa
- Sau khi check-in, có thể dùng dropdown "↔" cạnh mỗi sale để chuyển bucket (A ↔ B ↔ C ↔ OFF).
- Bấm ✕ đỏ để xoá check-in sai.
- **Sau khi đã chốt UPS**: các nút sửa bị khoá. Muốn sửa: bấm **Hủy chốt UPS** (đỏ) → chỉnh → chốt lại.

### Ý nghĩa các bucket
- **A LIST**: Nhận khách nguồn cao cấp (BOD, Hotline, MKT, AFF, WI, BR). Sale có doanh thu >20 triệu hôm trước.
- **B LIST**: Nhận khách APPT/PNS/VOUCHER. Sale có 2 show hoặc có giao dịch thu tiền hôm trước.
- **C LIST**: Backup khi A và B bận.
- **OFF LIST**: Không nhận số hôm nay. **Không phải nghỉ làm** — sale vẫn làm việc bình thường, chỉ không nhận lead mới.
- **MKT LIST**: TM team HC — nhận leads MKT theo thứ tự.

### Khi khách đến clinic
- Sale bấm **Đang tiếp đón** bên hệ thống Booking → tự động chuyển sang bận, khách tiếp theo sẽ chuyển cho sale khác trong danh sách.
- Sale bấm **Hoàn tất** → quay về sẵn sàng nhận số tiếp.

---

## Team Sale (Sale nhân viên)

### Công việc chính
Nhận lead từ hệ thống UPS → gọi khách → book lịch → khách đến clinic → tư vấn → chốt.

### Đầu ngày
1. Đến clinic, **BO check-in** cho bạn vào UPS.
2. Bạn không cần thao tác gì với UPS — BO làm hộ.

### Khi có lead mới được chia cho bạn
1. Toast **🔔 Lead mới** nháy góc phải → bấm để mở lead.
2. Xem 5 cột thông tin ở form khách: **PAGE, Camp, Phân loại, Kết quả, S.I.C**.
3. Bấm **Gọi điện** → chọn trạng thái cuộc gọi (Thành công / Thất bại / Không nghe máy) → ghi note.

### Quy tắc **BẮT BUỘC** cập nhật (Quy tắc PKD Update)
- **Trong 1 ngày** phải cập nhật 3 cột đầu: **PAGE, Camp, Phân loại**. Nếu không → hệ thống **tự động thu hồi** lead về kho team, bạn mất lead đó.
- **Trong 3 ngày** phải cập nhật đủ **5 cột** (thêm Kết quả, S.I.C). Nếu không → thu hồi.
- Áp dụng khi CM/Admin đã tick "Áp dụng luật thu hồi tự động" khi chia lead cho bạn.

### Đặt booking
1. Ở phase Booking, bấm **+ Thêm booking**.
2. Chọn: **Loại** (Thăm khám / Dịch vụ) → **Cơ sở** → **Phòng** → **Dịch vụ** → **Bác sĩ** → **Khung giờ**.
   - Dropdown BS chỉ hiện BS phù hợp với dịch vụ đã chọn (BS không nhận tư vấn sẽ bị ẩn khi bạn chọn dịch vụ tư vấn).
   - Dropdown dịch vụ đã dedupe — mỗi dịch vụ chỉ hiện 1 lần.
3. Chọn CV tư vấn (có thể chọn nhiều — người đầu tiên = Sale phụ trách khi booking được duyệt).
4. Bấm **Lưu**.
5. Booking được đẩy sang **hệ thống Booking (lara-sbooking)** để lễ tân duyệt.

### Khi khách tới clinic
- Lễ tân/Admin sbooking bấm **Khách đã tới** → nếu UPS đã chốt, hệ thống **tự động** gán bạn (theo Sale Tiếp Đón A→B→C→OFF) hoặc CV bạn đã chỉ định.
- Bạn vào hệ thống Booking bấm **Đang tiếp đón** → hệ thống đánh dấu bạn bận.
- Tư vấn xong bấm **Hoàn tất** → bạn quay về danh sách sẵn sàng nhận khách tiếp.

### Xem lead của bạn
- Menu **Khách hàng > Kho khách** → tab **Cá nhân** → thấy tất cả lead của bạn.
- Nếu bạn KHÔNG cập nhật đủ trong 3 ngày → lead tự động về tab **Team** → mất quyền chăm.

### Xin chuyển giao lead cho sale khác
- Không tự chuyển được. Nhờ CM/TL.

---

## Team Tele / Team Booking

### Công việc chính
Gọi lead nguồn MKT/BDM sau khi trực page up. Xác định khách có tiềm năng → book lịch → chuyển sang phòng Sale.

### Nhận lead
- Lead nhóm 1 (MKT/MKT BR/BDM) sau khi trực page up sẽ nằm trong **Kho Booking**.
- CM Booking chia cho bạn qua UPS List.
- Bạn thấy lead ở **Khách hàng > Kho khách > tab Cá nhân**.

### Gọi & cập nhật
- Bấm **Gọi điện** → ghi trạng thái + note.
- Sửa được thông tin cá nhân khách (khác Sale — Sale ở phase sau không sửa được).

### Đặt booking
- Giống Team Sale (xem trên).

### Chuyển sang Sale
- Khi booking được duyệt và **khách đã tới**, hệ thống tự chuyển sang phòng Sale.

---

## CM Sale

### Công việc chính
- Chia lead từ kho team xuống Sale.
- Duyệt các thay đổi quan trọng (đổi source, phase rollback…).
- Xem báo cáo hoạt động team.

### Chia lead thủ công
1. Menu **Khách hàng > Kho khách > tab Team**.
2. Filter theo cascade: Địa điểm → Cơ sở → Phòng ban.
3. Chọn 1 hoặc nhiều lead (tick checkbox).
4. Bấm **Chia thủ công hàng loạt** → chọn sale → OK.
5. Hoặc: **Chia tự động** (theo rule chia đã cấu hình).
6. Có thể tick **"Áp dụng luật thu hồi tự động"** khi chia → sau 1 ngày sale không update 3 cột đầu → tự thu hồi.

### Duyệt lead
- Menu **Khách hàng > Duyệt lead** → xem danh sách chờ duyệt → OK / Từ chối.

### Rút lead về kho team
- Menu **Khách hàng > Kho khách > tab Cá nhân** → tìm lead → bấm **Thu hồi** (chỉ khi user có quyền `lead.recall`).

### Cấu hình quy tắc chia
- Menu **Chia số > Rule** (nếu có quyền).
- Tạo rule cascade: chọn Địa điểm/Cơ sở/Phòng ban → chọn chiến lược (round-robin / weighted).

---

## CM Booking / CM Tele

### Công việc chính
- Duyệt lead nhóm 1 (MKT/MKT BR/BDM) sau khi trực page up.
- Chia lead trong kho Booking cho Team Tele.
- Theo dõi tiến độ gọi.

### Duyệt lead MKT
- Menu **Khách hàng > Duyệt lead** → tab Nguồn.
- Duyệt OK → lead vào kho Booking để chia tiếp.

### Chia thủ công / auto
- Giống CM Sale nhưng phạm vi là Team Booking/Tele.

### Xem báo cáo phòng
- Menu **Báo cáo** → chọn khoảng thời gian → xem funnel + top nhân viên.

---

## DM & Admin

### Công việc chính
- Cấu hình toàn hệ thống.
- Xem báo cáo toàn khu vực / toàn công ty.
- Quản nhân sự, phân quyền, cây phòng ban, cây Kho số.

### Cài đặt hệ thống
- Menu **Cài đặt** (chỉ Admin/DM thấy).
- Tab con:
  - **Nhân viên** — thêm/sửa user + gán role.
  - **Vai trò & Quyền** — chỉnh permission per role.
  - **Cây phòng ban** — sửa OrgUnit.
  - **Cây Kho số** — sửa PoolUnit (Địa điểm/Cơ sở/Phòng ban).
  - **Trường tùy chỉnh** — thêm/sửa các cột custom cho lead (page, camp…).
  - **Dịch vụ & Nhân sự BS** — chỉnh danh mục.
  - **Kết nối Booking** — map cơ sở scrm ↔ sbooking + sync services/rooms/bacsi/users.
  - **Rule chia số** — cấu hình cách chia auto.
  - **SLA Policy** — cấu hình thời gian thu hồi.

### Kết nối 2 hệ (sbooking)
- Vào **Cài đặt > Kết nối Booking**.
- Bấm **Sync Users** — kéo users bên sbooking về + tự động map sang users scrm theo email prefix. Sau khi map, sale bên scrm sẽ được nhận trạng thái "Đang tiếp đón" từ sbooking.
- Bấm **Sync Services** / **Sync Phòng** / **Sync Bác sĩ** — kéo master data để form booking có dropdown chuẩn.

### Nếu booking bị lệch dữ liệu với sbooking
- Mở terminal chạy: `php artisan sb:reconcile-bookings --dry-run` để xem có bao nhiêu booking lệch.
- Bấm apply thật: `php artisan sb:reconcile-bookings`.

### Xuất danh sách khách hàng
- Menu **Khách hàng > Danh sách** → bấm **⬇ Export**.
- Mặc định **CHỈ tick core columns** (trong 6 phase Customer Flow).
- Nếu cần xuất thêm cột tùy chỉnh: tick manual.
- OK → file CSV mở bằng Excel (tiếng Việt hiển thị đúng).

---

## Sự cố thường gặp

| Triệu chứng | Nguyên nhân + cách xử lý |
|---|---|
| Không up được lead MKT — báo "chưa chốt UPS" | BO cơ sở chưa chốt UPS. Nhờ BO chốt trước. |
| Booking đã duyệt bên sbooking nhưng scrm chưa hiện | Tự động refresh sau ~5 giây (real-time). Nếu quá 30s vẫn không hiện: F5 trang. Vấn đề mạng WebSocket. |
| Sale bấm "Đang tiếp đón" bên sbooking mà không có hiệu ứng | Sale chưa check-in UPS hôm nay. BO check-in cho sale trước. |
| Booking không duyệt được — không có thông báo lỗi | Refresh trang. Nếu vẫn không có message, kiểm tra BS đã chọn có phù hợp với dịch vụ (BS tư vấn ≠ BS khám LS). |
| Dịch vụ trong dropdown lặp x3-x4 lần | Bản sửa 2026-08-04 đã fix. Nếu vẫn thấy: F5 hard hoặc báo dev. |
| Bị đá xuống trang login liên tục | Session hết hạn. Đăng nhập lại. |
| Không thấy nút Export | Bạn không có quyền `lead.export`. Nhờ Admin cấp. |
| CV được gán nhưng không thấy nút chỉnh sửa | Đúng — CV chỉ được viết bình luận và ghi phản hồi khách, không được sửa thông tin. Nhờ CM/Admin nếu cần sửa. |

---

## Hỗ trợ
Bug hoặc câu hỏi → báo Admin hệ thống hoặc dev team.
