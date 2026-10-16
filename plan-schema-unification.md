# Plan thống nhất schema master data (Option 2)

> Tạo 2026-08-01 sau khi user chốt Option 2 trong Phase C của [plan-integration-sbooking.md](plan-integration-sbooking.md).
> **KHÔNG phải task nhỏ** — tổng effort ~25-40h focused work, spread ra **8-12 session** riêng. Mỗi phase con là 1-2 session, làm xong test kỹ rồi mới sang phase tiếp.
> 2 repo: `F:/Laragon/www/lara-scrm` (chính) + `F:/Laragon/www/lara-sbooking`.
> Đọc trước mỗi phase: `CLAUDE.md`, `scope.md`, `ERD.md`, phase trước đã ghi trong `result.md`.

---

## Master schema đã chốt (2026-08-01)

| Bảng scrm ↔ sbooking | Master | Lý do |
|---|---|---|
| `services` ↔ `dich_vu` | **sbooking** | Schema đơn giản hơn, đủ dùng. Scrm services có nhiều cột không dùng cho booking. |
| `facilities` ↔ `co_so` | **scrm** | Cấu trúc tree (parent-child) tốt hơn flat. Sbooking sẽ đổi thành tree. |
| `staff_members` ↔ `bac_si` | **scrm** | Có `role` phân loại doctor/consultant/KTV — sbooking chỉ có bac_si + ktv riêng lẻ, sẽ gộp lại. |
| `users` | **scrm** | Auth/permission gốc ở scrm. Sbooking users sẽ đọc sync từ scrm. |

**Chiến lược cho mỗi bảng**:
- Bên master GIỮ NGUYÊN schema hiện tại.
- Bên còn lại (slave) **đổi schema** theo master + backfill data.
- Sau khi đồng nhất, thêm cột `remote_id` (`scrm_id` hoặc `sbooking_id`) để tracking, và cơ chế sync 1 chiều master → slave (auto qua observer + manual qua nút).

---

## Thứ tự làm (từ ít rủi ro tới nhiều)

**Phase C1 → C2 → C3 → C4** — không nhảy phase, không gộp.

### Phase C1 — Services / dich_vu 🟡 (master = sbooking)

**Rủi ro**: TRUNG BÌNH. Bên scrm dashboard/report doanh thu dùng `services.code`, `pricing_type`, `package_price` → cần giữ hoặc migrate report.

**Design cần chốt**:
1. Cột riêng của scrm (`code`, `pricing_type`, `package_price`, `service_type`, `parent_id` tree) → **giữ ở scrm-only** (không sync sang sbooking), hay **dời qua sbooking** (sbooking mở rộng schema)?
2. Sbooking hiện có `co_so_id` (dịch vụ theo cơ sở) — scrm muốn shared (cùng dùng chung). Reconcile sao?

**Bước làm**:
1. Grep tất cả nơi dùng cột đặc thù scrm.services → liệt kê để plan migrate.
2. Migration scrm: thêm `sbooking_id` unique nullable.
3. Migration sbooking: mở rộng `dich_vu` (thêm cột nếu scrm cần giữ) + `scrm_id` unique nullable.
4. Command backfill: match theo tên (case-insensitive) → link scrm.id ↔ sbooking.id.
5. Observer bên sbooking (master): khi save `dich_vu` → push sang scrm.
6. Bỏ nếu có: bảng scrm.services ghi mới (chỉ đọc sync). Hoặc giữ dual-write cho backward compat.
7. Update UI scrm: dropdown dịch vụ đọc từ scrm.services (đã sync từ sbooking).
8. Test regression: report doanh thu, dropdown chọn dịch vụ, `lead_treatments`, `customer_services`, `booking_logs`.

**Ghi `result.md` §6.21o**.

---

### Phase C2 — Facilities / co_so 🔴 (master = scrm, sbooking đổi thành tree)

**Rủi ro**: CAO. `co_so.slug` bên sbooking đang dùng làm URL `/{slug}/booking/tao-moi` — thay đổi = vỡ URL + bookmark user.

**Design cần chốt**:
1. Sbooking đổi flat → tree: thêm `parent_id` vào `co_so`. Slug giữ hay bỏ?
   - **Đề xuất giữ slug** ở leaf nodes, root nodes không cần slug.
2. Backfill sbooking: mỗi row co_so hiện tại → tạo parent (VD: "Hà Nội") + child (row hiện tại thành child).
3. Migration `co_so.parent_id` (nullable, self-FK), giữ `slug` unique nullable.

**Bước làm**:
1. Grep sbooking dùng `co_so.slug`, `co_so.dia_chi` → plan giữ nguyên.
2. Migration scrm: thêm `facilities.sbooking_id`.
3. Migration sbooking: thêm `co_so.parent_id` + `co_so.scrm_id`.
4. Command backfill:
   - Bước 1: với mỗi root scrm.facility (ko có parent) → tạo hoặc match co_so bên sbooking.
   - Bước 2: với mỗi child scrm.facility → tạo hoặc match co_so + gán parent_id + slug (nếu chưa có).
5. Observer scrm (master): save facility → push sang sbooking (cả cây).
6. Update sbooking UI list co_so: hiển thị tree.
7. Test: URL cũ `/{slug}/booking/tao-moi` vẫn work; booking flow không vỡ.

**Ghi `result.md` §6.21p**.

---

### Phase C3 — Staff / bac_si + ktv 🔴🔴 (master = scrm, sbooking gộp bac_si+ktv thành 1 bảng)

**Rủi ro**: RẤT CAO. Bên sbooking `bac_si` và `ktv` (nếu có) là 2 bảng riêng, code booking dùng `bac_si_id` và `ktv_user_id` riêng biệt. Gộp lại → refactor toàn bộ booking flow.

**Design cần chốt**:
1. Sbooking hiện có bảng `bac_si` (Phase C khảo sát) và column `ktv_user_id` (trên `bookings`, join `users`). Vậy KTV là user chứ không phải bảng riêng?
   - Nếu vậy: bên sbooking bac_si → đổi tên thành `staff_members` với cột `role` (doctor/ktv/consultant), thay column `ktv_user_id` bằng `staff_member_id` role=ktv.
2. Hoặc: scrm chấp nhận split lại — 2 bảng `doctors` + `staff` để match sbooking (đề xuất KHÔNG vì scrm hiện đã có 1 bảng gộp).

**Bước làm**:
1. Grep sbooking dùng `bac_si_id` và `ktv_user_id` → toàn bộ chỗ cần refactor.
2. Migration sbooking: đổi tên `bac_si` → `staff_members`, thêm cột `role`, backfill role='doctor'.
3. Migration sbooking: thêm bản ghi staff_members role='ktv' từ những user đã có bookings với ktv_user_id → set booking.staff_id_ktv.
4. Migration scrm: thêm `staff_members.sbooking_id`.
5. Refactor sbooking code: `bac_si_id` → `staff_id_bac_si` hoặc `doctor_id`; `ktv_user_id` → `staff_id_ktv`.
6. Observer scrm: push staff sang sbooking.
7. Test regression: booking create/edit form (chọn BS + KTV), conflict check.

**Ghi `result.md` §6.21q**.

---

### Phase C4 — Users 🔴🔴🔴 (master = scrm)

**Rủi ro**: CỰC KỲ CAO. Đụng auth 2 hệ.

**Design cần chốt (nhiều)**:
1. Sbooking users vẫn login độc lập (có password riêng), hay SSO qua scrm?
2. Nếu SSO: refactor auth sbooking = HUGE.
3. Nếu vẫn độc lập: chỉ sync `id / name / email / co_so_id / phong_ban_id`, không sync password. Sbooking user record là mirror readonly.
4. Phong_ban bên sbooking (Sales, KTV, Lễ tân, Admin) — mapping từ role/permission scrm sao?

**Bước làm**: sẽ chi tiết khi tới phase này (còn xa, không viết trước).

**Ghi `result.md` §6.21r**.

---

## Cơ chế sync sau khi phase xong

Sau MỖI phase, thêm:
- Cột `remote_id` (2 phía) đã có → thiết lập observer bên master.
- Trigger auto khi save model master → HTTP push sang slave endpoint `POST /api/sync/{table}`.
- Nút "🔄 Đồng bộ toàn bộ" ở admin (bên master) → chạy artisan command.
- Sync **1 chiều** master → slave. Slave KHÔNG được write các cột chung (schema readonly ở phía slave).

## Ghi chú chung

- **Mỗi phase = 1 branch riêng** (VD: `phase-c1-services`, `phase-c2-facilities`...). Merge sau khi test xong.
- **KHÔNG chạy migration trên prod** cho tới khi test staging đầy đủ (nếu có).
- **Backup DB 2 hệ** trước mỗi migration structural.
- Rollback plan: mỗi migration có `down()` đảo được. Test rollback ở dev trước.
- Data 2 hệ đang là **demo** (theo CLAUDE.md), nên có thể aggressive hơn — nhưng không được truncate bảng user để "test lại".

## Trạng thái

- [x] Phase C1 — services (đảo master: sbooking cho data booking, scrm giữ services có giá) — 2026-08-01, xem result.md.
- [x] Phase C1.b — gộp form booking CRM + auto push sang sbooking — 2026-08-01, xem result.md. Còn C1.c (đổi dropdown Service → SbService) chưa làm.
- [ ] Phase C2 — facilities (master scrm)
- [ ] Phase C3 — staff_members (master scrm)
- [ ] Phase C4 — users (master scrm)
