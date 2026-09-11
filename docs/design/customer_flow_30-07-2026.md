# Customer Flow 7 phase — Thiết kế chi tiết (30-07-2026)

> Bối cảnh: sau họp 30-07-2026, thay mô hình 2-phase (`booking`/`sale`) hiện tại bằng mô hình 7-phase lifecycle. UI đổi từ 6 tab dọc thành 7 tab-phase + arrow-breadcrumb (mockup: `docs/mockups/customer_flow_30-07-2026.html`).
>
> File này là design doc **duyệt trước khi code**. Sau khi user OK sẽ đồng bộ vào `scope.md` / `ERD.md` / `plan.md` (Phase 6.21).

---

## 1. Mô hình 7 phase

| # | Tên phase | Ai xử lý (mặc định) | Data cốt lõi | Trạng thái sub |
|---|-----------|---------------------|--------------|----------------|
| 1 | Thêm mới khách hàng | Trực Page / Tele / QL Sale / Sale (tùy nguồn) | name, phone, dob, region, source, insight | – |
| 2 | Chia số | CM cơ sở → CM team | owner_id, org_unit_id, distribute_note | – |
| 3 | Gọi điện | Tele | `call_logs[]` (nhiều lần) | Thành công / Thất bại / Không nghe máy |
| 4 | Booking thăm khám | Sale | `booking_logs[]` (nhiều lần) + doctor + consultant | Đã xác nhận / Chờ xác nhận / Hủy - Đổi lịch |
| 5 | Check-in | Lễ tân | checkin_at, receptionist_id, doctor_id | – |
| 6 | Bán hàng | *(chưa build — placeholder)* | future: `lead_upsells`, `customer_services` | – |
| 7 | Sử dụng DV | *(chưa build — placeholder)* | future: `lead_treatments` | – |

**Ghi chú**: phase 6-7 là placeholder tab hiển thị "Chưa build", không có nút Kết thúc phase.

---

## 2. Mapping `source_group` → `start_phase`

`start_phase` = phase cao nhất được chốt tại thời điểm user tạo lead bấm "Lưu chốt N phase". Lead nhảy sang `start_phase + 1` sau khi lưu.

| Source | Người tạo | start_phase | Phase mở thông khi tạo | Sau lưu, lead vào phase |
|--------|-----------|-------------|------------------------|-------------------------|
| MKT | Trực Page | **1** | 1 | 2 (chờ CM chia) |
| MKT_BR | Sale (tự nhận) | **4** | 1 → 4 | 5 (Check-in) |
| BA | Tele (tự nhận) | **3** | 1 → 3 | 4 (Booking, Sale làm tiếp) |
| SA | QL Sale | **2** | 1 → 2 | 3 (Tele gọi) |
| BDM | QL Sale | **2** | 1 → 2 | 3 |
| BOD | QL Sale | **2** | 1 → 2 | 3 |
| Walk-in (WI) | QL Sale | **2** | 1 → 2 | 3 |

Constant Lead model:
```php
public const START_PHASE_BY_SOURCE = [
    self::SOURCE_MKT    => 1,
    self::SOURCE_MKT_BR => 4,
    self::SOURCE_BA     => 3,
    self::SOURCE_SA     => 2,
    self::SOURCE_BDM    => 2,
    self::SOURCE_BOD    => 2,
    self::SOURCE_WI     => 2,
];
```

---

## 3. Luật mở thông / tuần tự / lùi

- **Mở thông (bulk edit)** — chỉ xảy ra **1 lần duy nhất** khi lead chưa được Lưu lần đầu:
  - Với khách **mới**: mở thông phase `1..start_phase`.
  - Với khách **quay lại** (`is_first_visit = false`): mở thông phase `3..start_phase` (nếu `start_phase ≥ 3`), phase 1-2 giữ nguyên data cũ.
  - User nhập full → bấm **"Lưu — chốt N phase"** → tất cả các phase mở thông được set `LeadPhaseClosure(phase, closed_by=current_user, closed_at=now)`, `leads.phase = start_phase + 1`.
- **Tuần tự** — mọi thao tác sau khi lưu lần đầu. Từng phase có nút **"Kết thúc phase X"**:
  - Điều kiện: `activeTab === leads.phase` (đúng phase đang mở).
  - Kiểm perm `phase.close.<slug>` (xem §5).
- **Lùi phase** — chỉ role có perm `phase.rollback`:
  - Nút "⤺ Lùi về phase X" hiện ở tab của phase đã done + `idx < leads.phase`.
  - Xóa `LeadPhaseClosure` từ `idx` trở đi, set `leads.phase = idx`.
  - Log vào `lead_status_logs` với action `phase_rollback`.
- **Ai được ghi call_log/booking_log**: người đang giữ lead (owner) + QL Sale (`lead.distribute_sale`) + Admin vận hành (`phase.rollback`). Kiểm bằng method `Lead::canLogCall($user)` / `canLogBooking($user)`.
- **Ai được kết thúc phase**: bất kỳ user có perm `phase.close.<slug>` tương ứng, không cần là owner.

---

## 4. Khách quay lại (`is_first_visit`)

- Field mới trên `leads` (bool default `true`). Field "Đến lần đầu" trên UI = `is_first_visit`.
- Khi khách đã Check-in xong (phase 5 chốt) và quay lại lần 2+:
  - User (Tele/Sale/QL) bỏ tick "Đến lần đầu" → `is_first_visit = false`.
  - Hệ thống **KHÔNG tạo lead mới**, dùng lead cũ.
  - `leads.phase` reset về **3** (Gọi điện) để Tele gọi xác nhận lịch mới.
  - Phase 1-2 giữ nguyên data cũ (owner, thông tin cá nhân), read-only nếu xem lại.
  - `call_logs` + `booking_logs` lịch sử cũ được giữ (không xóa), khách quay lại chỉ append record mới.
  - `LeadPhaseClosure` của phase 3-5 lần trước được giữ với timestamp cũ; phase 3 lần 2 tạo closure mới khi Tele chốt.
  - Nếu source cho phép start_phase ≥ 3 (MKT_BR, BA) và user tạo mới lần 2 vẫn nhập call_log → mở thông từ phase 3 → start_phase như luật ở §3.

**Trigger UI**: nút "Khởi động lần thăm khám mới" ở header trang chi tiết (chỉ hiện khi `leads.phase === 5` và closure phase 5 đã có). Bấm → prompt xác nhận → set `is_first_visit=false`, `leads.phase=3`.

---

## 5. Permission mới (5 perm)

| Perm key | Phase | Mặc định gán |
|----------|-------|--------------|
| `phase.close.new` | 1 | Trực Page + Tele + QL Sale + Sale + Admin vận hành |
| `phase.close.distribute` | 2 | CM cơ sở + CM team + Admin vận hành |
| `phase.close.call` | 3 | Tele + QL Sale + Admin vận hành |
| `phase.close.booking` | 4 | Sale + QL Sale + Admin vận hành |
| `phase.close.checkin` | 5 | Lễ tân + Admin vận hành |
| `phase.rollback` *(mới)* | * | Admin vận hành **only** |

Các perm này tách riêng theo yêu cầu user (linh hoạt cấp lẻ về sau). Bulk save 1 lần chốt N phase sẽ check **tất cả** N perm — user không có 1 perm nào thì fail toàn bộ (báo lỗi rõ).

**"Admin vận hành"** — grep chưa thấy role có tên chính xác này. Kế hoạch:
- Nếu có sẵn role `ops.manage` holder → dùng role đó (thường là admin công ty).
- Nếu chưa có → tạo role mới `admin-ops` với label "Admin vận hành", gán 5+1 perm trên + `ops.manage`.
- Xác nhận: chờ user check ở bước review.

---

## 6. Schema changes

### 6.1 Thêm cột `leads`
```php
$table->tinyInteger('phase')->default(1)->after('pipeline_status')->comment('1..7 customer flow');
$table->boolean('is_first_visit')->default(true)->after('phase');
$table->index('phase');
```

### 6.2 Bảng mới `lead_phase_closures`
```
id                  bigint PK
lead_id             bigint FK leads (cascade delete)
phase               tinyint 1..7
closed_by           bigint FK users
closed_at           timestamp
note                text nullable
created_at, updated_at
UNIQUE(lead_id, phase)  -- 1 phase 1 khách chỉ chốt 1 lần
INDEX(lead_id, phase)
```

### 6.3 Bảng mới `call_logs`
```
id                  bigint PK
lead_id             bigint FK leads (cascade)
user_id             bigint FK users (ai gọi)
status              varchar(20) 'thanh_cong' | 'that_bai' | 'khong_nghe_may'
note                text nullable
called_at           datetime
timestamps
INDEX(lead_id, called_at desc)
INDEX(user_id)
```

### 6.4 Bảng mới `booking_logs`
```
id                  bigint PK
lead_id             bigint FK leads (cascade)
user_id             bigint FK users (ai đặt)
status              varchar(20) 'da_xac_nhan' | 'cho_xac_nhan' | 'huy_doi_lich'
scheduled_at        datetime nullable
doctor_id           bigint FK staff_members nullable
service_id          bigint FK services nullable
note                text nullable
timestamps
INDEX(lead_id, scheduled_at desc)
INDEX(user_id)
```

### 6.5 Legacy — không xóa
- `leads.pipeline_phase`, `leads.pipeline_status`, `leads.booking_status` **GIỮ** — dùng làm compat cho báo cáo cũ + rule chia số hiện có. Sẽ deprecate ở phase riêng sau (Phase 6.22).
- `leads.status_1`, `leads.status_2` **GIỮ** — mapping vào phase 3 UI (hiển thị đọc, không nhập mới). Data mới ghi vào `call_logs`.
- `lead_treatments`, `lead_upsells` **GIỮ** — hiển thị ở tab Phase 7 / Phase 6 khi build.
- `lead_status_logs` **GIỮ** — extend action enum thêm `phase_close`, `phase_rollback`.

---

## 7. Backfill data cũ (41 lead hiện có)

**Rule mapping** trong migration `up()` sau khi thêm cột `phase`:

```sql
-- Default phase = 3 (chăm sóc) cho đa số lead cũ
UPDATE leads SET phase = 3, is_first_visit = true;

-- Nếu đã book lịch → phase 4
UPDATE leads SET phase = 4 WHERE booking_status IN ('booked', 'rescheduled');

-- Nếu đang chờ chia (chưa có owner) → phase 2
UPDATE leads SET phase = 2 WHERE owner_id IS NULL AND pipeline_status = 'waiting_distribute';

-- Nếu vừa nhập chưa gán ai → phase 1
UPDATE leads SET phase = 1 WHERE owner_id IS NULL AND receiver_id IS NULL AND classification = 'new';
```

**Sinh `lead_phase_closures` giả lập cho lead cũ** để không hiển thị "chưa chốt":
- Với mỗi lead cũ có `phase = N`, sinh closures cho phase `1..N-1` với `closed_by = receiver_id ?? owner_id ?? 1` (fallback admin), `closed_at = leads.created_at`, note = `'[backfill]'`.

**Không backfill `call_logs`/`booking_logs`** — 2 bảng này bắt đầu rỗng, data cũ đọc từ `status_1/2` + `booking_status` (compat read-only).

---

## 8. Mapping 6 tab UI cũ → 7 tab-phase mới

| Tab cũ | Field cốt lõi | Chuyển đi đâu |
|--------|---------------|---------------|
| **Trạng thái** | classification, status_1/2, booking_status, doctor + consultant, "Đến lần đầu" | classification → header lifecycle bar (giữ nguyên vị trí trên header). status_1/2 → Phase 3 (readonly, compat). booking_status → Phase 4 (đồng bộ từ booking_logs mới nhất). doctor + consultant → Phase 4. "Đến lần đầu" → Phase 1 (checkbox) + trigger reset ở header. |
| **Bác sĩ tư vấn** | doctor_id, consultant_1/2/3_id | Phase 4 |
| **Liệu trình** | lead_treatments[] | Phase 7 (placeholder "chưa build" — giữ bảng data, chưa render form) |
| **Tiềm năng** | lead_upsells[] | Phase 6 (placeholder "chưa build" — giữ bảng data) |
| **Insight** | leads.insight | Phase 1 |
| **Phân phối & Nguồn** (gộp trong Trạng thái cũ) | source_group, org_unit_id, owner_id | Phase 2 |

**Lưu ý cho migration UI**: Livewire component `⚡lead-form.blade.php` hiện dùng `x-data="{ tab: 'status' }"` với 5 keys (`status`, `staff`, `treatment`, `upsell`, `insight`). Chuyển sang `x-data="{ tab: 1 }"` với 7 keys `1..7`. Blade wrap `x-show="tab === N"` cho từng section.

---

## 9. UX chi tiết mỗi phase

Xem mockup [docs/mockups/customer_flow_30-07-2026.html](../mockups/customer_flow_30-07-2026.html). Điểm cần code:

1. **Header lifecycle bar**: đổi từ label 2-phase (`Sale · Chờ chia`) sang label 7-phase (`Phase 3 · Gọi điện`). Component `pipelineLabel()` viết lại theo `phase` + fallback `pipeline_phase`.
2. **Arrow-breadcrumb**: component mới `resources/views/components/leads/⚡customer-flow-bar.blade.php`, nhận `$lead` + `$activePhase`, render 7 nút mũi tên với state (done/current/open/pending/skipped/notbuilt).
3. **Tab-bar 7 phase** thay `Tabbar horizontal text-only`.
4. **Nút action bar** (footer form): 3 trạng thái mutually exclusive:
   - Bulk mode: `Lưu — chốt N phase (từ X đến Y)` → gọi `saveBulk()`.
   - Tuần tự mode: `Kết thúc phase X — <label>` → `closePhase($idx)`.
   - Admin lùi: `⤺ Lùi phase về "<label>"` → `rollbackTo($idx)`.

---

## 10. Rủi ro migration + rollback plan

| Rủi ro | Mức | Mitigation |
|--------|-----|------------|
| Backfill `leads.phase` sai → 41 lead cũ hiển thị phase lạ | Cao | Backup DB trước khi migrate. Rule backfill ở §7 giữ conservative (default phase 3). Có script rollback đưa `phase` về NULL. |
| Perm `phase.close.*` chưa gán → user không chốt được phase nào | Cao | Seeder idempotent, gán ngay ở migration. Sau migrate check `roles.name = 'Admin vận hành'` tồn tại, nếu không tạo mới. |
| Livewire form rewrite phá vỡ luồng đang chạy (booking, tư vấn) | Cao | Giữ nguyên tất cả wire method cũ (`save`, `updated*`, distribute logic). Chỉ đổi wrapping tab. Regression test 117 test cũ trước khi merge. |
| Team booking đang dùng `pipeline_phase = booking` để lọc kho → đổi model phase làm vỡ query kho | Trung | Không đụng `pipeline_phase` — giữ song song. Query kho tiếp tục dùng field cũ. Field mới `phase` chỉ dùng cho UI + phase closure logic. |
| Enum `booking_status` (`not_booked`/`booked`/`rescheduled`) vs mới (`da_xac_nhan`/`cho_xac_nhan`/`huy_doi_lich`) — 2 hệ song song | Trung | Booking logs mới độc lập với `booking_status`. Sync 1 chiều: khi thêm booking_log có status `da_xac_nhan` → set `booking_status = booked` (giữ compat cho code cũ). |
| Migration hủy cột nhầm | Thấp | KHÔNG có `dropColumn` nào trong migration lần này — chỉ thêm. |

**Rollback command**:
```
php artisan migrate:rollback --step=1   # xoay ngược migration 2026_07_30_100000
```

---

## 11. Break-down commit code (theo task list)

| Commit | Nội dung | File chính |
|--------|----------|------------|
| C1 | Migration cột `leads.phase` + `is_first_visit` + backfill | `database/migrations/2026_07_30_100000_phase_6_21_customer_flow.php` |
| C2 | Migration bảng `lead_phase_closures` + `call_logs` + `booking_logs` | cùng migration ở C1 (1 file) |
| C3 | Seeder 5+1 perm + gán role Admin vận hành | `PermissionSeeder.php`, tạo file mới nếu cần role seeder |
| C4 | Model: `Lead` phase logic + `CallLog` + `BookingLog` + `LeadPhaseClosure` | `app/Models/Lead.php` (thêm ~150 dòng), 3 file model mới |
| C5 | Livewire + Blade: rewrite tab section thành 7 tab-phase + arrow-breadcrumb component + action bar | `⚡lead-form.blade.php` (thay dòng 984-1573), `⚡customer-flow-bar.blade.php` mới |
| C6 | Data backfill: sinh `lead_phase_closures` giả lập cho lead cũ | cùng migration C1, trong `up()` |
| D1 | Feature test | `tests/Feature/CustomerFlowTest.php` |

Tổng: 1 migration file (thao tác gom vào 1), 3 model mới, 1 blade component mới, 1 component seeder update, 1 test file, thay đổi lớn trên 1 blade + 1 model existing.

---

## 12. Test cases cần cover (D1)

1. `test_source_maps_to_correct_start_phase` — 7 nguồn map đúng start_phase.
2. `test_bulk_save_closes_all_phases_from_1_to_start` — Sale MKT_BR bấm Lưu → 4 closure sinh ra + `leads.phase = 5`.
3. `test_close_phase_sequentially` — MKT lead phase 1 done → Trực Page bấm Kết thúc → phase = 2.
4. `test_close_phase_requires_permission` — user không có `phase.close.call` → bấm phase 3 fail 403.
5. `test_rollback_only_admin_ops` — role thường bấm lùi → 403; Admin vận hành → OK, closures xóa từ idx.
6. `test_returning_customer_resets_phase_to_3` — bỏ tick `is_first_visit` → `leads.phase = 3`, lịch sử cũ giữ.
7. `test_call_log_permission` — owner + QL Sale + Admin ghi được; Sale khác không được.
8. `test_booking_log_syncs_booking_status` — thêm booking_log `da_xac_nhan` → `leads.booking_status = 'booked'`.
9. `test_backfill_migration_preserves_existing_leads` — chạy migration, 41 lead có phase ≠ null, không mất data.
10. Regression: 117 test cũ pass hết.

---

## 13. Câu hỏi/quyết định còn treo (cần user confirm 1 lần khi review doc)

- [ ] **Perm `phase.rollback`** — tao mới sinh (không nhắc trong họp). User OK gộp Admin vận hành có riêng perm này không?
- [ ] **Role "Admin vận hành"** — tạo mới với slug `admin-ops` OK? Hay gắn vào role hiện có (`Admin` toàn bộ)?
- [ ] **Rule reset phase 3 khi khách quay lại** — nút "Khởi động lần thăm khám mới" đặt ở đâu (header trang chi tiết vs. panel riêng)? Tao đề xuất header.
- [ ] **status_1/status_2 legacy** — giữ readonly ở phase 3 (compat) hay ẩn hẳn?
- [ ] **Không backfill call_logs từ status_1/2** — OK không mất "lịch sử gọi cũ" (thực chất chỉ 2 field text, không phải nhiều record)?

---

**Sau khi user duyệt** → chuyển sang B1/B2/B3 (update scope.md + ERD.md + plan.md) → chuyển C1..C6 → D1 → D2 báo cáo.
