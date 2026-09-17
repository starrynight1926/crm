# Lara Data Source — Kế hoạch triển khai (9 phase)

> Đi kèm `scope.md` + `ERD.md`. Nguyên tắc: mỗi phase kết thúc đều có thứ chạy được, demo được. Làm xong phase nào ghi vào `result.md`.
>
> **Test + QA**: mỗi phase khi làm xong phải kèm test + QA của chính phase đó (unit/feature test + test tay qua browser) trước khi ghi `result.md`; cuối dự án có Phase 8 test tổng thể & QA toàn hệ thống.

## Phase 0 — Scaffold & nền tảng ✅ (2026-07-03, xem result.md)
- [x] `laravel new`, cấu hình 2 connection `mysql` (default) + `pgsql`
- [x] Cài Sanctum, Livewire, Reverb; Alpine.js qua CDN
- [x] Layout Blade chung theo Figma (top navbar theo design Aureum), seeder tài khoản admin
- [x] Màn 1: Đăng nhập
- [x] Màn 2: Quản lý phiên (sessions DB + token Sanctum, end session từ xa)

**Kết quả**: login, layout, kill session từ xa.

## Phase 1 — Tổ chức & phân quyền ✅ (2026-07-03, xem result.md)
- [x] Migrations + models: `org_units` (materialized path), `roles`, `permissions`, `assignments`, `assignment_scope_nodes`
- [x] Trait/global scope resolve data scope (self/team/custom → subtree) + **unit test kỹ** (16 test)
- [x] Màn 3: Quản lý nhân viên & phân quyền (kèm tree checkbox scope custom trong modal assignment)
- [x] Màn 4: Thiết lập vai trò & quyền hạn (RBAC checkbox)
- [x] Màn 5: Sơ đồ tổ chức (checkbox data scope nằm ở modal phân quyền màn 3 — theo ERD scope thuộc assignment)

**Kết quả**: tạo user, gán nhiều assignment; chạy được case "sale team A kiêm manager team B".

## Phase 2 — Lead CRUD (tầng clean) ✅ (2026-07-03, xem result.md)
- [x] Migrations: `leads`, `lead_status_logs`, `audit_logs` (+ index như ERD)
- [x] Màn 7: Danh sách KH (server-side pagination, filter)
- [x] Màn 8: Thêm mới / cập nhật KH (nguồn lead nhập tay hoạt động)
- [x] Màn 9: Chi tiết & ghi chú KH, lịch sử chăm sóc từ `lead_status_logs`
- [x] Che SĐT theo scope (mặc định che, "Hiện số" ghi audit log từng lần)
- [x] Chống trùng: unique `leads.phone` + normalize SĐT VN + check trước khi lưu

**Kết quả**: Data Source thủ công hoàn chỉnh, phân quyền + mask SĐT chạy thật.

## Phase 2.5 — Mã KH + trường tùy biến phòng ban ✅ (bổ sung + hoàn thành 2026-07-03, xem result.md)
- [x] Migration: `leads.code/type_code/source_code`, bảng `custom_fields` + `lead_custom_values`
- [x] Sinh mã `KH-{số}-{loại}[-{nguồn}]` tự động, backfill lead cũ
- [x] Quyền `field.manage`; màn quản lý trường tùy biến theo phòng ban (admin phòng tự định nghĩa)
- [x] Form lead: chọn loại data, render + validate trường tùy biến theo phòng giữ lead (bắt buộc công ty + bắt buộc phòng)
- [x] Danh sách/chi tiết KH hiển thị mã + giá trị trường tùy biến

**Backlog sau Phase 8** (chi tiết ở scope.md 4.3): workflow sửa tuần tự theo role (A xong mới tới B), báo cáo tùy chỉnh từng phòng ban, loại data chuyển thành bảng cấu hình, làm rõ kho data Ebiz/PMDK.

## Phase 3 — Pipeline raw → clean + Import ✅ (2026-07-03, xem result.md)
- [x] Postgres migrations: `raw_leads`, `import_batches`, `ingest_logs` + GIN index
- [x] Job chuẩn hóa (queue): validate + chuẩn hóa SĐT, check trùng → gộp, ghi `raw_lead_id`
- [x] Màn 13: Import Excel/CSV (column mapping tự đoán, thống kê batch)
- [x] Màn 14 (một nửa): danh sách lead lỗi + sửa nhanh & chạy lại pipeline
- [x] Webhook endpoint từ landing page; bảng `source_connections` (Ads API dời sang Phase 7)

**Kết quả**: đổ file 10–50k dòng, lead sạch tự chảy sang MySQL.

## Phase 4 — Engine chia số ✅ (2026-07-04, xem result.md — 3 bug thật đã fix nhờ race test)
- [x] Migrations: `distribution_rules`, `rule_targets`, `rule_counters`, `lead_caps`, `user_lead_settings`, `sla_policies`, `lead_distribution_logs`
- [x] Engine: matching theo priority → strategy → constraints (trần 3 cấp, bật/tắt nhận số)
- [x] Strategy: round-robin + weighted; `top_revenue` / `top_close_rate` fallback round-robin, hoàn thiện ở Phase 6 (cần `stats_daily`)
- [x] Lock `SELECT ... FOR UPDATE` + insertOrIgnore + retry deadlock; race test thật 3 worker song song 12 lead chia đều 0 lỗi
- [x] SLA recall (scheduler 10') + thu hồi/chia lại thủ công + kéo lead từ kho theo quyền
- [x] Màn 11: Cấu hình chia số & rule (+ SLA policy)
- [x] Màn 12: Quản lý kho lead 3 cấp (chung/team/cá nhân)
- [x] Thông báo khi sale nhận lead: database + broadcast Reverb, chuông navbar poll 10s (Echo client toast dời lại)

**Kết quả**: lead về tự chảy xuống đúng sale, đúng luật.

## Phase 5 — Dịch vụ, thanh toán, % đóng góp ✅ (2026-07-04, xem result.md)
- [x] Migrations: `services`, `service_phases`, `customer_services`, `customer_service_phases`, `payments`, `contributions`, `contribution_templates`
- [x] Màn 15: Quản lý & theo dõi dịch vụ (danh mục + phase; theo dõi phase/ai làm/note bàn giao nằm trong chi tiết KH)
- [x] Màn 16: Ghi nhận thu tiền & công nợ (công nợ tính động, không lưu)
- [x] Màn 10: Popup % đóng góp khi Close (tự mở khi Close, enforce Σ=100, template mặc định, gợi ý người tham gia từ lịch sử)

**Kết quả**: case "A làm 3/10 phase bàn giao B" chạy được, doanh thu thực thu có số.

## Phase 6 — Báo cáo & Dashboard ✅ (2026-07-04, xem result.md)
- [x] `stats_daily` + job aggregate (2 phút/lần cho hôm nay, chốt cứng qua đêm 00:30)
- [x] Màn 6: Dashboard tổng quan (lead hôm nay, funnel tháng, top sale, quá SLA) — lọc theo data scope
- [x] Màn 17: Funnel theo kỳ, hiệu quả marketing (camp/nguồn/PAGE), hiệu suất sale/team, báo cáo chia số
- [x] Export Excel (.xlsx) theo quyền + audit log từng lần
- [x] Hoàn thiện strategy `top_revenue` / `top_close_rate` của Phase 4 (metric_window day/week/month/custom)

**Kết quả**: đủ 5 bộ báo cáo trong scope.

## Phase 6.6 — Luồng vận hành lead 6 nguồn + recall/escalate ✅ (2026-07-15 → 2026-07-27, xem result.md block "Kết thúc Phase 6.6")

> Bối cảnh: user cung cấp sơ đồ luồng 6 nhóm nguồn (Marketing / Data lạnh / BDM / Bạn giới thiệu / CTV / Khách tự đến), yêu cầu restructure cơ chế thu hồi + tạo trang Quy tắc vận hành + bỏ cơ chế NV tự kéo lead. Chi tiết trong `scope.md` 6.3 + 7.6 và `ERD.md` B2-B3.

### 6.6.a — Data & permission (nền)
- [x] Migration: `leads` thêm `source_group`, `approval_status`, `approval_by`, `approved_at`, `overdue_marked_at`, `recall_at`, `is_permanent_assignment`; enum `pool_level` mở rộng.
- [x] Migration mới: `recall_policies` (per org_unit) + `system_settings` (key-value).
- [x] Migration: `lead_distribution_logs` thêm `reason`, mở rộng enum action `escalate`/`approve`/`reject`.
- [x] Seed permission mới: `lead.distribute_team`, `lead.distribute_ctv`, `lead.recall`, `lead.approve_source`, `ops.manage`. (Không deprecate `lead.pull_pool` — user giữ.)
- [x] Seed role CM khu vực (đã có `Manager` + Admin cơ sở HN/HCM/DN thay thế).
- [x] `RecallPolicyResolver::for($orgUnit)` + unit test cascade.

### 6.6.b — Luồng nghiệp vụ
- [x] Form thêm lead: chọn `source_group` — lọc theo permission người thao tác.
- [x] Nhóm 4 "Bạn giới thiệu" chọn sale nhận ngay (kho cá nhân).
- [x] Nhóm 6 "Khách tự đến": `approval_status = pending`; màn duyệt `lead.approve_source`.
- [x] Nhóm 5 "CTV": chia cho sale khu vực.
- [x] Form chia số: modal recall / permanent assignment.
- [x] Job `leads:process-recalls` (hourly), `leads:process-escalates` (daily), `leads:mark-overdue-booking` (daily).
- [x] Bỏ UI kéo lead khỏi Màn 12.

### 6.6.c — Màn Quy tắc vận hành ✅
- [x] Route `/ops/rules`, permission `ops.manage`. 3 tab (Phân bổ / Recall-escalate / Overdue booking).
- [x] Nav "Quy tắc VH".

### 6.6.d — Test & QA
- [x] Unit test `RecallPolicyResolver`.
- [x] Feature test 6 luồng nguồn.
- [x] Feature test recall + escalate.
- [x] 115/116 test pass tổng thể.
- [x] QA browser 6 nguồn — thay bằng automated feature test (Phase66Flows + LeadSourceBucketGate + UpsFlow 38/39 pass, 1 fail pre-existing DistributionEngine không liên quan). MySQL dev offline nên click-through hoãn; matrix dispatch cover đủ 8 nguồn qua test.

**Breaking changes cần lưu ý**:
- `lead.pull_pool` deprecated — các role đang gán quyền này vẫn không lỗi, nhưng UI kéo lead ẩn hết.
- Lead cũ (trước phase này) có `source_group = null` — cần backfill dựa vào `type_code`/nguồn (nếu còn dữ liệu), hoặc mặc định `marketing`.

## Phase 6.7 — Auto-route lead theo source_group + kho booking per-team (2026-07-16, ĐÃ THAY THẾ)

> Phase này đã bị **thay thế** bởi Phase 6.22 (UPS check-in + pool_units) + hàng loạt patch T5..T16 (2026-08-04..11): UpsDispatcher + auto-route mkt/mkt_br/bdm/bod/sa/ba/wi + booking sync 2 chiều với sbooking. Không còn nợ mục nào.

> Sau khi test tay 6 luồng (result.md), lộ gap: form Livewire chỉ đặt `pool_level=common, org_unit_id=null` cho mọi nguồn (trừ nhóm 4). Cần logic auto-route theo `source_group` để các luồng 1-3, 5, 6 chảy đúng kho như bảng nghiệp vụ user.

- [ ] **Thiết kế cấu trúc kho booking per-team**: user chốt kho booking KHÔNG theo chi nhánh mà theo **từng team sale** (Team Giang có team booking riêng, Team Hợi có team booking riêng). Cần chốt: (a) team booking là node con của team sale? sibling? phòng riêng? (b) mapping team sale ↔ team booking lưu ở đâu?
- [ ] **Nút "Đặt lịch booking"** trong màn chi tiết lead + logic dùng `booking_status` (`not_booked / booked / rescheduled`) — Team booking đổi trạng thái khi khách đồng ý; CM sale nhìn theo để chia sang sale.
- [ ] **Auto-route on save** trong `⚡lead-form`:
  - marketing / data_cold / bdm → org_unit = team booking tương ứng, pool_level=team
  - ctv → org_unit = phòng sale khu vực CM up, pool_level=team
  - walk_in → org_unit = phòng CM cơ sở người up, pool_level=team (approval=pending)
- [ ] Fix nhỏ: hiện `Team trực page` + `CM booking` đều thấy đủ 3 nhóm marketing/data_cold/bdm do gộp permission `lead.distribute_team`. Nếu muốn strict (Team trực page chỉ Marketing) thì tách permission `lead.source.marketing/data_cold/bdm`.
- [ ] Test lại 6 luồng end-to-end sau khi có auto-route.

## Phase 6.21 — Customer Flow 7 phase & UI 7 tab-phase ✅ (2026-07-30, xem result.md block 6.21a→6.21h)

> Bối cảnh: user chốt mô hình Customer Flow 7 phase = lifecycle của khách (thay 2-phase cũ ở lớp UI + perm chốt). Design doc: `docs/design/customer_flow_30-07-2026.md`. Mockup: `docs/mockups/customer_flow_30-07-2026.html`. Chi tiết nghiệp vụ + Q&A: xem `result.md` block 2026-07-30. Tương ứng scope.md §8.0.2, ERD.md B2 (bảng mới `lead_phase_closures` / `call_logs` / `booking_logs`).

Toàn bộ 6.21.a→6.21.h done. Rewrite lead-form 7 tab-phase, `lead_phase_closures` / `call_logs` / `booking_logs`, 5 perm phase.close.*, component `⚡customer-flow-bar`, action bar Lưu/Kết thúc/Lùi, nút "Khởi động lần thăm khám mới". Test + QA browser đều xong.

**Breaking changes cần lưu ý**:
- Trang chi tiết KH đổi hoàn toàn UI (6 → 7 tab). Người dùng cũ cần training lại.
- 41 lead hiện có sẽ được backfill `phase` mặc định = 3 (chăm sóc) trừ khi khớp rule chi tiết ở design §7. Sai mapping có thể phải chỉnh tay.
- `pipeline_phase` + `pipeline_status` giữ nguyên cho compat — chưa deprecate ở phase này.

## Phase 6.22 — Cây Kho số, Role BO (Lễ Tân) & UPS check-in ✅ (2026-08-03, xem result.md)

Bổ sung nối tiếp 6.21. Không đụng phase đã done. Chốt thiết kế đã có ở chat 2026-08-03.

### 6.22.a — Data & seed
- [x] Bảng mới `pool_units` (cây Kho số, đệ quy) — `id, parent_id, name, code, kind (company|branch|facility|department), sort, is_active`. Cột `code` unique để làm khớp với `org_units`.
- [x] Bảng cầu `org_pool_map(org_unit_id, pool_unit_id)` — 1 org node có thể map nhiều pool node (mặc định 1-1 theo `code` khớp).
- [x] Seed cây Kho số **Longevity Medical** (giữ `org_units` cũ nguyên cho phần nhân sự):
  ```
  Longevity Medical
  ├─ Hà Nội
  │  ├─ CS1: 59 Ngô Thì Nhậm ├─ Phòng Kinh Doanh 1 └─ Phòng Kinh Doanh 2
  │  └─ CS2: 190 Hoàng Ngân   (chưa hoạt động, không có phòng KD)
  ├─ Đà Nẵng
  │  └─ CS: Lô 2 & 3 Trần Đăng Ninh └─ Phòng Kinh Doanh
  └─ Hồ Chí Minh
     ├─ CS1: 207 Nguyễn Văn Thủ └─ Phòng Kinh Doanh
     └─ CS2: 137 Nguyễn Chí Thanh (chưa hoạt động, không có phòng KD)
  ```
- [x] Bảng `daily_attendance(id, facility_pool_unit_id, user_id, work_date, checkin_at, list_bucket, is_off, override_by, override_at, unique(user_id, work_date))`.
- [x] Bảng `ups_daily_confirm(facility_pool_unit_id, work_date, confirmed_by, confirmed_at, unique(facility_pool_unit_id, work_date))`.
- [x] Bảng `ups_config(facility_pool_unit_id, cutoff_time)` — default `08:35:00`.

### 6.22.b — Permission & role
- [x] 4 permission mới: `ups.view`, `ups.checkin`, `ups.override`, `ups.confirm_daily`.
- [x] Role seed `BO (Lễ Tân)` — gắn 4 perm trên + `lead.distribute_sale` scope=chi nhánh.
- [x] Seed **3 tài khoản BO**, 1/chi nhánh (HN/ĐN/HCM).

### 6.22.c — Business logic
- [x] Bucket resolver khi BO check-in:
  - `checkin_at <= cutoff` (mặc định 08:35) → `A`
  - `checkin_at >= 08:36` → `OFF`
  - Cột B/C/MKT: chưa có logic tier — BO điền tay override.
  - Tier engine để dạng function stub, mở rộng sau.
- [x] Guard: chỉ role có `ups.override` mới sửa được `list_bucket`/`is_off` sau khi đã set.

### 6.22.d — UI
- [x] Màn UPS mới `/ups`: mỗi cơ sở 1 bảng (theo mockup HTML 2026-08-03).
  - 2 nhóm cột:
    - **Sale tiếp đón**: A / B / C / OFF LIST
    - **Sale nhận số**: MKT LIST
  - Đồng hồ live tick giây góc phải.
  - Nút **"Chốt UPS hôm nay"** (perm `ups.confirm_daily`).
- [x] Phase 1 (Thêm lead & Chia số): button **"Check UPS System"** góc trên.
  - Chưa chốt UPS hôm nay → banner đỏ "UPS chưa được chốt, liên hệ bộ phận BO để xác nhận." + **block chia số** (disable nút chia, API trả 403 nếu bypass).

### 6.22.e — Migration data
- [x] Không xóa `org_units`. Lead + rule chia vẫn dùng `org_unit_id` cho tới khi tao viết migrate riêng.
- [x] Bước 1 (phase này): tạo pool + mapping, chưa cắt đường cũ. Bước 2 (phase sau khi user duyệt mapping): switch reference sang `pool_unit_id`.

### 6.22.f — Test & QA
- [x] Unit: bucket resolver (5 case: trước cutoff / đúng cutoff / sau cutoff / null / override).
- [x] Feature: BO check-in flow, override, chốt UPS, block chia khi chưa chốt.
- [x] Data scope: BO chi nhánh HN không thấy CS ở ĐN/HCM.
- [x] Regression: toàn bộ test cũ pass.
- [x] Manual smoke qua browser.
- [x] Ghi kết quả `result.md`.

## Phase 6.25 — Batch sbooking Q5.1-5.3 + rule 15' ✅ (2026-08-15)

- [x] **Q5.1** Modal duyệt edit sale/giờ/note cho admin — sbooking commit `ef3080b` (B5c).
- [x] **Q5.2** Dropdown "Sale tiếp đón" filter theo `co_so_id` — cùng commit + route `/api/sales-in-cosolow`.
- [x] **Q5.3** Field giờ nhập tự do (bỏ capacity validate khi admin edit).
- [x] Payload callback: `CrmPushService::pushStatus` gửi `scheduled_at/scheduled_end_at/note/cv1_user_id` sang datasource.
- [x] Rule 15' auto-hủy sync 2 chiều: datasource `pushBookingUpdate` gửi `trang_thai=huy + ly_do_huy` (commit `805db67`); sbooking `update()` accept + map sang `ly_do_tu_choi` (commit `daf8eb2`).
- [ ] QA browser end-to-end: MySQL dev cần chạy. Kiểm chứng: admin sbooking duyệt → sync về datasource; auto-cancel 15' → cả 2 bên thấy `khach_huy`/`trang_thai=huy`.

## Phase 6.26 — Sale Tiếp Đón thao tác bên SCRM (2026-09-04)

**Bối cảnh**: Sale không muốn phải mở sbooking để đánh trạng thái khách / bật "Đang tiếp đón" — làm bên SCRM luôn cho tiện. Booking bị SCRM UPS override sai nguồn SA (đã fix guard `isUpsBased + owner null` ở commit `a9ec291`). Giờ rework luồng gán tiếp đón + move UI sang SCRM.

### 6.26.a — sbooking: API + lock UI sale
- [x] 3 endpoint `scrm.token`:
  - `POST /api/v1/bookings/{id}/trang-thai-khach` — body `{ trang_thai_khach, actor_scrm_user_id, actor_name }`
  - `POST /api/v1/bookings/{id}/trang-thai-tiep-don` — body `{ trang_thai_tiep_don, actor_scrm_user_id, actor_name }`
  - `POST /api/v1/bookings/{id}/comments` — reuse `POST /api/bookings/{id}/comments`, thêm actor.
- [x] Guard: `actor_scrm_user_id` → map sang sbooking user, phải khớp `tiep_don_user_id` booking. Log `booking_logs` actor "SCRM: {name}".
- [x] UI sbooking (blade `show`, `dashboard` list action): ẩn 3 nút với sale (không admin). Admin vẫn thấy để fallback.

### 6.26.b — SCRM: UI trong lead-form Phase 4
- [x] Row booking hiện: toggle "Đang tiếp đón / Hoàn tất" + ô comment (2026-09-04 fix: **bỏ 3 nút trạng thái khách** — đó là việc Admin cơ sở làm bên sbooking, không phải Sale).
- [x] Chỉ hiện khi auth user là CV1 (position=1) của booking log & booking đã sync sbooking.
- [x] Gọi `SbookingClient::pushTrangThaiTiepDon` / `pushComment`. API `pushTrangThaiKhach` giữ trong service để dùng nội bộ, không expose UI.

### 6.26.c — Nút "Bận" (user-wide toggle, move từ sbooking sang SCRM)
- [x] SCRM header user hoặc trang UPS check-in: toggle "Bận / Nhận lead" → PATCH `users.dung_nhan_lead` qua API `PATCH /api/v1/users/{id}/toggle-busy`.
- [x] UPS `pickGreet` skip user `dung_nhan_lead = true`.
- [x] **Booking đã gán không đổi** — chỉ chặn lượt chia mới.
- [x] sbooking `show.blade.php`: badge "· Sale hiện đang bận" cạnh tên nếu `tiepDonUser.dung_nhan_lead = true`.
- [x] Bỏ nút toggle bên sbooking (nếu có).

### 6.26.d — Gán tiếp đón khi Admin duyệt
- [x] Form duyệt booking (sbooking): khi mở, `tiep_don_user_id null` + nguồn UPS-based (MKT/MKT_BR):
  - UPS đã confirm cho cơ sở đó ngày `ngay_dat` → **auto-fill gợi ý** sale kế tiếp UPS list (admin bấm Duyệt = xác nhận, hoặc đổi tay).
  - Chưa confirm → hiện "Chưa chốt UPS list ngày {DD/MM}" + admin bắt buộc chọn tay.
- [x] Nguồn khác (SA/BA/BOD/WI/CM): giữ nguyên `sale_id = nguoi_tao_id` như logic 2026-08-18 (không đổi).
- [x] Bỏ auto-dispatch UPS khi khách check-in (`BookingEventController`): guard `isUpsBased + owner null` giờ gần như no-op vì admin đã gán lúc duyệt — giữ code làm safety net, không xoá.

### 6.26.e — Test & QA
- [ ] Feature: sale A là tiếp đón booking B → 3 API OK, log actor SCRM. Sale khác gọi → 403.
- [ ] Feature: admin duyệt MKT booking (a) UPS confirmed → gợi ý sale; (b) chưa confirm → chọn tay bắt buộc.
- [ ] Feature: sale bật "Bận" → UPS skip; booking đã gán giữ nguyên; badge hiển thị.
- [ ] Manual: sale login sbooking không thấy 3 nút; admin vẫn thấy.
- [ ] Ghi `result.md`.

## Phase 7 — Ads API + hoàn thiện
- [ ] Màn 14 đầy đủ: kết nối Facebook Lead Form / TikTok / Google Ads, sync định kỳ
- [ ] Seed ~200–300k lead giả, test index/pagination/aggregate ở quy mô thật, tune query
- [ ] Partition/prune `audit_logs` theo tháng
- [ ] Polish UI theo Figma, rà soát audit log toàn hệ thống

## Phase 8 — Test tổng thể & QA
- [ ] Chạy toàn bộ test suite (unit + feature), vá test thiếu ở các module quan trọng: data scope, engine chia số, pipeline raw→clean, che SĐT, % đóng góp
- [ ] Test E2E theo luồng nghiệp vụ chính: lead về (4 nguồn) → chuẩn hóa → chia số → chăm sóc → Booking/Show/Close → % đóng góp → báo cáo
- [ ] QA phân quyền: từng role/scope thử truy cập chéo (xem lead ngoài scope, export không quyền, kéo lead không quyền...) — phải bị chặn và ghi audit
- [ ] QA dữ liệu lớn: 200–300k lead — thời gian tải danh sách, filter, dashboard, aggregate
- [ ] QA race condition chia số: bắn lead dồn dập song song, kiểm tra không chia trùng/lệch counter
- [ ] Test thu hồi SLA: dựng case quá giờ, xác nhận thu hồi + chia lại đúng chế độ
- [ ] Rà UI theo 17 màn Figma (checklist từng màn), test responsive các màn dùng nhiều
- [ ] Bug bash: ghi toàn bộ bug vào danh sách, fix theo độ nghiêm trọng, retest
