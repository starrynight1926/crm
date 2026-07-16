# Lara-SCRM — Nhật ký kết quả

> Làm xong phase nào ghi vào đây: ngày hoàn thành, việc đã làm, việc dời lại/chưa xong, ghi chú & quyết định phát sinh. Mẫu bên dưới.

<!--
## Phase X — <tên phase> ✅
- **Ngày hoàn thành**: YYYY-MM-DD
- **Đã làm**:
  - ...
- **Dời lại / chưa xong**:
  - ...
- **Ghi chú & quyết định phát sinh**:
  - ...
-->

## Phase 6.6+ — Dọn duplicate org + gộp CM khu vực về CM sale ✅
- **Ngày**: 2026-07-16
- **Đã làm**:
  - **Gộp 3 role vùng** (`CM Hà Nội / CM Đà Nẵng / CM HCM`) về role chung **`CM sale`**. Khu vực do assignment (phòng ban gắn) quyết định. Migrate 6 assignment sang CM sale, giữ nguyên org + scope. Thêm `lead.distribute_ctv` vào CM sale. Sửa 3 seeder để không tái sinh role vùng khi seed lại.
  - **Dọn nhánh Marketing cũ** (id 6/7/8/9) — data trước Phase 6.6, các team này đã có bản real ở HN (id 20 Team Ms. Giang, id 21 Team Mr. Hợi). Xóa 4 org + 13 user demo `@sweetsica.com` (không tham chiếu lead nào). Xóa thêm id 19 "Team Hợi" rỗng.
  - **Rename** id 10 `Telesales Marketing` → `Marketing` để ngang cấp BDM (id 11) dưới Cơ sở HN, đúng nghiệp vụ user chỉ.
- **Cây org sạch còn** (nhìn theo path): Công ty > (Cơ sở HN > Marketing / BDM / Team Ms. Giang > (Team Booking + Team Sale) / Team Mr. Hợi > (Team Booking + Team Sale)) + (Cơ sở HCM > Team Ms. Ashley > (Team Booking + Team Sale)) + (Vận hành & Giám sát) + (Phòng Kinh doanh > Team Sale A/B) + (Phòng Marketing) + (Phòng Booking > Team trực page + Team booking).
- **Ghi chú**: cụm "Phòng Kinh doanh / Marketing / Booking" ở cuối cây là data seed từ OrgAndRoleSeeder + Phase66FlowSeeder — có thể cần dọn sau khi user chốt cấu trúc chính thức.

## Phase 6.6+ — Seed 4 role luồng 6 nguồn + booking_status + fix bug @php compile ✅
- **Ngày**: 2026-07-16
- **Bối cảnh**: user yêu cầu rà + test 6 luồng nguồn theo bảng: Team trực page (nhóm 1) / CM booking (nhóm 2-3) / Team booking (nhận số) / CM sale (chia sang sale) — hiện DB thiếu 4 role này.
- **Đã làm**:
  - **Migration mới**: `leads.booking_status` (enum `not_booked/booked/rescheduled`, default `not_booked`, có index). Là placeholder cho b_ước handoff booking→sale — Team booking đổi trạng thái khi khách đồng ý, CM sale nhìn theo trạng thái này để chia sang sale (UI nút "Đặt lịch booking" sẽ làm sau).
  - **Model Lead**: 3 constant `BOOKING_*` + map `BOOKING_STATUSES` + cast + fillable.
  - **Seeder mới `Phase66FlowSeeder`** (idempotent):
    - Org units: `Phòng Booking > Team trực page + Team booking` (thêm dưới root Công ty).
    - 4 role: `Team trực page`, `CM booking`, `Team booking`, `CM sale` — permission theo bảng nghiệp vụ.
    - 5 user demo (pw `123456`): `page1@`, `cmbk@`, `book1@`, `book2@`, `cmsale@longevity.com.vn`.
  - **🐛 Bug thật fix nhân dịp này**: form thêm lead `/leads/create` 500 — Blade compiler ở dự án này KHÔNG convert `@php ... @endphp` (cả block form và single-line inline `@php ... @endphp`) thành `<?php ... ?>`; chỉ `@endphp` được convert → biến sinh trong block không tồn tại lúc echo. Đã đổi 5 chỗ trong `⚡lead-form.blade.php` sang raw `<?php ... ?>`. Ngoài ra chuyển tính `svcTreeJson` từ @php trong view vào data return của component (sạch hơn). Chưa rõ vì sao Blade lỗi, có thể do phiên bản Livewire 4 + Laravel 13 override compile; tinker test `compileString` với @php lại chạy đúng — cần đào tiếp sau.
- **Verify**:
  - `Lead::allowedSourceGroupsFor` cho từng user demo trả đúng nhóm nguồn theo permission.
  - Login `page1@` + navigate `/leads/create` → form load 200, dropdown "Nhóm nguồn" hiện: Marketing, Data lạnh, BDM, Bạn giới thiệu, Khách tự đến (không có CTV — đúng).
- **Tài khoản demo mới** (pw `123456`):

  | Email | Role | Org | Thấy source_group |
  |---|---|---|---|
  | page1@longevity.com.vn | Team trực page | Team trực page | marketing, data_cold, bdm, referral, walk_in |
  | cmbk@longevity.com.vn | CM booking | Phòng Booking | marketing, data_cold, bdm, referral, walk_in |
  | book1@longevity.com.vn | Team booking | Team booking | referral, walk_in |
  | book2@longevity.com.vn | Team booking | Team booking | referral, walk_in |
  | cmsale@longevity.com.vn | CM sale | Phòng Kinh doanh | marketing, data_cold, bdm, referral, walk_in |

- **Test tay 6 luồng** (2026-07-16): tạo 6 lead thật (KH-021..026), 1 qua browser (KH-021 do page1 tạo), 5 qua tinker mô phỏng actor. **Kết quả**:

  | Lead | Nhóm | pool | org | owner/recv | approval | Nghiệp vụ đúng? |
  |---|---|---|---|---|---|---|
  | KH-021 | marketing (page1) | common | null | recv=page1 | none | ⚠️ Nên vào **kho Phòng Booking** thay vì kho chung |
  | KH-022 | data_cold (cmbk) | common | null | recv=cmbk | none | ⚠️ Nên vào kho Phòng Booking |
  | KH-023 | bdm (cmbk) | common | null | recv=cmbk | none | ⚠️ Nên vào kho Phòng Booking |
  | KH-024 | ctv (ttg CM HN) | common | null | recv=ttg | none | ⚠️ Nên gán về sale khu vực HN |
  | KH-025 | walk_in (nvkd) | common | null | recv=nvkd | **pending** | ⚠️ Approval OK nhưng nên vào org CM cơ sở |
  | KH-026 | referral (chọn sale nvkd) | **personal** | null | owner=nvkd | none | ✅ Đúng — vào kho cá nhân sale |

- **Gap nghiệp vụ tìm được** (chưa có trong code, cần bàn):
  - **Auto-route theo source_group**: hiện Livewire form chỉ gán `pool_level=common` cho mọi nguồn (trừ nhóm 4 chọn sale nhận). Cần thêm logic khi save:
    - marketing/data_cold/bdm → `org_unit_id = Phòng Booking`, `pool_level=team` (vào kho booking để CM booking chia cho Team booking)
    - ctv → `org_unit_id = Phòng Kinh doanh của khu vực CM đó`, `pool_level=team` (CM khu vực chia tay cho sale)
    - walk_in → `org_unit_id = phòng của CM cơ sở người up`, `pool_level=team` (chờ CM cơ sở duyệt rồi chia)
  - **User chốt (2026-07-16)**: kho booking KHÔNG phải theo chi nhánh mà theo **từng team sale** (VD Team Giang có team booking riêng, Team Hợi có team booking riêng). Cấu trúc org phải là: mỗi team sale gắn 1 team booking con hoặc sibling. Auto-route: khi Marketing/Data lạnh/BDM up lead → lead chảy về team booking của team sale mà người up thuộc về / hoặc do CM booking quyết định target team. Cần thiết kế chi tiết trước khi code.
  - **User chốt (2026-07-16)**: chưa code auto-route — giữ nguyên, xử lý phase sau.
- **Chưa làm / lưu ý**:
  - Nghiệp vụ mismatch nhỏ: theo bảng, Team trực page CHỈ nên up Marketing (nhóm 1), CM booking mới up nhóm 2-3. Hiện `SOURCE_PERMISSIONS` gán chung `lead.distribute_team` cho 3 nhóm → cả 2 role thấy đủ 3. Nếu user muốn strict thì phải tách permission (VD `lead.source.marketing/data_cold/bdm`). Chưa đổi, hỏi user trước.
  - Nút "Đặt lịch booking" + logic handoff booking→sale dựa trên `booking_status` chưa làm — cần thiết kế UI sau.

## Phase 6.6 — Nhân sự đầy đủ HN + HCM (26 user) ✅ (mở rộng)
- **Ngày**: 2026-07-16
- **Đã làm**:
  - **3 team con** dưới chi nhánh: `Team Ms. Giang` (team-giang) + `Team Mr. Hợi` (team-hoi-hn) — thuộc HN; `Team Ms. Ashley` (team-ashley) — thuộc HCM. Tạo qua `OrgUnit::createNode` idempotent.
  - **`RealCmStaffSeeder` viết lại** hỗ trợ:
    - **Migrate assignment**: nếu user đã có assignment role đó ở org cũ, `update` sang org đúng thay vì tạo mới → TL Đức + Quỳn tự migrate từ chi nhánh về team con.
    - Set `job_title` cho từng người (Clinic Manager / DM / Team Leader / SHC / HC / Trợ lý kinh doanh).
  - **26 user thật** (mật khẩu `123456`):
    - **HN Team Ms. Giang** (7): CM Giang (ttg) + 6 chuyên viên (thk/nhg/nmp/nta/ntn/cla)
    - **HN Team Mr. Hợi** (7): CM Hợi (tvh) — assignment ở branch-hn (scope custom = HN) + TL Đức (nhd, scope=team) + 5 chuyên viên (ptt/ntt/pta/ntm/nma)
    - **HCM Team Ms. Ashley** (12): DM Ngân (tnkn) + TL Quỳn (ptkq) + 3 CM (tbt/nmt/hbtl) + 6 chuyên viên (tyn/nhn/hmm/ntt2/nkc/lpd)
    - **Công ty** (1): Trợ lý Tự (lpt) — scope custom = toàn công ty
  - **Xử lý conflict email**: Nguyễn Thị Thúy (HN) → `ntt@`, Nguyễn Thị Thanh (HCM) → `ntt2@` (initials trùng nên đánh số).
  - **Xóa 2 demo cũ** (`cmhn@`, `cmhcm@`), giữ `cmdn@longevity.com.vn` cho Đà Nẵng.
- **Test suite**: **115/116 pass** (không đổi).
- **Ghi chú**:
  - CM Tạ Văn Hợi + CM Trần Thị Thu Giang assignment tại **chi nhánh** với scope custom = HN (thấy toàn HN). Muốn CM chỉ thấy team của mình thì gán lại tại team con — chưa cần thiết vì user vẫn chưa yêu cầu phân biệt.
  - Convention email: initials + bỏ "Thị" khi tên 4 âm tiết, conflict → suffix số. Nếu mày muốn đổi convention khác thì bảo, tao đổi tất trong seeder + DB.

## Phase 6.6 — Nhân sự thật HN + HCM (9 user) ✅
- **Ngày**: 2026-07-16
- **Bối cảnh**: user cung cấp danh sách nhân sự thật 2 chi nhánh (HN + HCM), yêu cầu thay 2 demo user (`cmhn@`, `cmhcm@`) bằng CM/DM/TL/Trợ lý thật.
- **3 role mới trong OrgAndRoleSeeder**:
  - `Team Leader`: quyền như CM nhưng scope team — permissions: `lead.view/view_phone/create/update/distribute/distribute_team/recall/approve_source` + `report.view`.
  - `Trợ lý kinh doanh`: view-only — chỉ `lead.view` + `report.view`. Scope custom = toàn công ty.
  - `DM HCM`: cao nhất khu vực HCM — 20 permission (full CM + user.manage + rule.manage + report.view_all + field.approve...).
- **Seeder mới `RealCmStaffSeeder`** (idempotent): tạo 9 user thật + gán role + scope tương ứng. Xóa 2 demo `cmhn@` / `cmhcm@`. Đăng ký vào `DatabaseSeeder`.
- **9 tài khoản CM/DM/TL/Trợ lý** (password `123456`):

  | Chi nhánh | Email | Họ tên | Role |
  |---|---|---|---|
  | HN | ttg@longevity.com.vn | Trần Thị Thu Giang | CM Hà Nội |
  | HN | tvh@longevity.com.vn | Tạ Văn Hợi | CM Hà Nội |
  | HN | nhd@longevity.com.vn | Nguyễn Hoành Đức | Team Leader |
  | HCM | tnkn@longevity.com.vn | Trần Nguyễn Kim Ngân | DM HCM |
  | HCM | ptkq@longevity.com.vn | Phan Trần Khánh Quỳn | Team Leader |
  | HCM | tbt@longevity.com.vn | Trần Thị Bích Trâm | CM HCM |
  | HCM | nmt@longevity.com.vn | Nguyễn Thị Minh Thư | CM HCM |
  | HCM | hbtl@longevity.com.vn | Huỳnh Bùi Thanh Lan | CM HCM |
  | Công ty | lpt@longevity.com.vn | Lê Thị Phương Tự | Trợ lý kinh doanh |

- **Đà Nẵng giữ demo** `cmdn@longevity.com.vn` (chưa có nhân sự thật).
- **Test suite**: **115/116 pass** (không đổi).
- **Chưa làm** (theo yêu cầu user): các chuyên viên tư vấn (SHC/HC, 17 người) — user tự thêm qua UI Quản lý nhân viên.

## Phase 6.6 — Dọn seeder + system_settings + CM demo users ✅
- **Ngày**: 2026-07-16
- **Đã làm**:
  - **Chuẩn hóa email** — đổi toàn bộ `@sweetsica.com` → `@longevity.com.vn` (khớp với các seeder khác đã dùng đuôi này). 3 seeder + 3 record trong DB.
  - **Role `Manager`**: gán 9 permission: `lead.view`, `lead.create`, `lead.update`, `lead.view_phone`, `lead.distribute`, `lead.distribute_team`, `lead.approve_source`, `lead.recall`, `report.view` (đủ để CM team vận hành Phase 6.6).
  - **Role `Sale`**: gán `lead.view`, `lead.create`, `lead.update`, `report.view` (đủ để sale nhìn + tạo lead trong scope).
  - **`system_settings` mặc định**: `default_recall_after_days=7`, `default_escalate_after_days=3`, `default_allow_permanent=1`. `RecallPolicyResolver` không còn trả null cho số ngày.
  - **3 CM user demo** (password `123456`, scope self ở node gốc "Công ty"): `cmhn@longevity.com.vn` / `cmdn@longevity.com.vn` / `cmhcm@longevity.com.vn`.
- **Verify DB**: 6 user + 5 role có permission đúng + 3 system_settings có giá trị.
- **Test suite**: **115/116 pass** (không đổi so với lần trước — chỉ 1 legacy fail đã biết).
- **Tài khoản demo hiện tại**:
  - Admin: `admin@longevity.com.vn` / `admin@123` (mật khẩu cũ giữ nguyên khi update email).
  - Sale: `nvkd@longevity.com.vn`, `nvmkt@longevity.com.vn` / `123456`.
  - CM khu vực: `cmhn@`, `cmdn@`, `cmhcm@longevity.com.vn` / `123456`.

## Phase 6.6.c + d — Modal recall/permanent + màn duyệt + màn Quy tắc VH + test 6 luồng ✅
- **Ngày**: 2026-07-16
- **Đã làm**:
  - **6.6.c1 — Modal chia số**: `⚡lead-pools.blade.php` thêm 2 property `$assignRecallMode` (default/custom/permanent) + `$assignRecallDays`. UI modal assign hiện dropdown 3 option **chỉ khi user có `lead.recall`**. Sau `manualAssign`: nếu permanent (và policy cho phép) → `is_permanent_assignment=true`; custom → `recall_at = now + N ngày`; default → dùng `recall_after_days` từ `RecallPolicyResolver::for($org)`.
  - **6.6.c2 — Màn duyệt "Khách tự đến"** (`/leads/approvals`, permission `lead.approve_source`):
    - Livewire component `⚡lead-approvals.blade.php`: bảng lead `source_group=walk_in` + `approval_status=pending` (lọc theo scope). Actions: **Duyệt** (set approved + log `ACTION_APPROVE`) / **Từ chối** kèm lý do (bắt buộc nhập, log `ACTION_REJECT` với `reason`).
    - Nav item "Duyệt lead" theo permission `lead.approve_source`.
  - **6.6.c3 — Màn "Quy tắc vận hành"** (`/ops/rules`, permission `ops.manage`):
    - Livewire component `⚡ops-rules.blade.php` — 3 tab:
      1. **Phân bổ (giám sát)**: bảng 4 permission (`distribute_team`/`distribute_ctv`/`approve_source`/`recall`) kèm danh sách user + role đang có.
      2. **Thời gian recall/escalate**: bảng cây org, mỗi node có cột "Hiệu lực (resolved)" hiển thị giá trị đang áp + nguồn (`org:N` hoặc `system`) — tường minh xem cấp nào đang override. Sửa/xóa cấu hình per node.
      3. **Overdue booking**: top 100 lead có `overdue_marked_at`.
    - Nav item "Quy tắc VH" theo permission `ops.manage`.
  - **6.6.d — Feature test 6 luồng** (`Phase66FlowsTest`, 6 test):
    - Admin thấy đủ 6 nhóm nguồn.
    - NV thường (không permission) chỉ thấy 2 nhóm (referral + walk_in).
    - CM khu vực (có `lead.distribute_ctv`) thấy thêm nhóm CTV.
    - Route `/leads/approvals` chặn user thiếu `lead.approve_source` (403), admin OK (200).
    - Route `/ops/rules` chặn user thiếu `ops.manage`, admin OK.
    - Duyệt lead walk_in qua Livewire → `approval_status=approved` + log `ACTION_APPROVE`.
- **Kết quả test suite**: **115/116 pass** (Phase 6.6 tổng cộng thêm 19 test mới, tất cả pass).
- **⚠️ Vẫn còn 1 test legacy fail** — `LeadScopeTest::test_team_scope_sees_all_leads_in_subtree` (đã verify từ 6.6.a: không phải regression, có thể xóa/sửa test sau).
- **Ghi chú**:
  - Modal chia số: giá trị "default" đọc từ `RecallPolicyResolver::for($lead->orgUnit)` — nếu chưa cấu hình node nào thì lead vẫn assign OK, chỉ không có `recall_at` (== không tự thu hồi).
  - "Chia vĩnh viễn" bị ẩn nếu policy áp dụng có `allow_permanent_assignment=false` — nhưng UI hiện đang hiện luôn 3 option; validation ở backend chặn set permanent khi policy cấm. **Nice-to-have sau**: ẩn option "permanent" nếu policy cấm (cần fetch policy khi mở modal — hiện đang lười).
  - Màn Quy tắc VH có "Nguồn: system" khi chưa ai set — vẫn hoạt động bình thường (dùng default null → không auto-thu hồi).
- **Kết thúc Phase 6.6** ✅. Toàn bộ luồng 6 nguồn + recall/escalate + màn ops đã có backend + UI + test.

## Phase 6.6.b — Form lead + jobs vòng đời ✅
- **Ngày**: 2026-07-16
- **Đã làm**:
  - `Lead` model: thêm 6 constant `SOURCE_*` + map `SOURCE_GROUPS` (nhãn) + `SOURCE_PERMISSIONS` (map nhóm → permission cần có). Thêm 4 constant `APPROVAL_*`. Casts cho các cột mới. Helper `Lead::allowedSourceGroupsFor(User)` lọc theo permission người dùng.
  - Form `⚡lead-form.blade.php`: property `$sourceGroup`, dropdown "Nhóm nguồn" cạnh "Ngày" (hint xanh khi chọn Referral/Walk-in), validate required + in-list theo `allowedSourceGroupsFor`, referral bắt buộc có personId, walk_in tự set `approval_status = pending`.
  - `LeadDistributionLog`: thêm cột `reason` vào fillable + 4 constant action mới (`ESCALATE`/`APPROVE`/`REJECT`/`MARK_OVERDUE`).
  - 3 command mới + schedule (`routes/console.php`):
    - `leads:process-recalls` (hourly): thu hồi lead có `recall_at <= now` về pool team, bỏ qua `is_permanent_assignment = true`.
    - `leads:process-escalates` (daily 02:00): quét pool team, so với `RecallPolicyResolver::escalate_after_days`, quá hạn → chuyển `org_unit_id` lên `parent_id` + log escalate. Skip node gốc.
    - `leads:mark-overdue-booking --days=7` (daily 02:15): lead nhóm marketing/data_cold/bdm ở kho common quá 7 ngày → set `overdue_marked_at` + log (không xóa).
  - `Phase66JobsTest` — **5 test**: recall hết hạn / bỏ qua chia vĩnh viễn / escalate lên cha khi quá hạn / bỏ qua khi chưa quá / mark-overdue chỉ nhóm 1-2-3.
- **Kết quả test suite**: **109/110 pass** (thêm 5 test mới của Phase 6.6.b, tất cả pass).
- **⚠️ Vẫn còn 1 test legacy fail** — `LeadScopeTest::test_team_scope_sees_all_leads_in_subtree` (đã verify với git stash ở 6.6.a): không phải regression.
- **Ghi chú**:
  - Nhóm nguồn CTV trong dropdown chỉ hiện với user có `lead.distribute_ctv` (mặc định là 3 role `CM Hà Nội/Đà Nẵng/HCM`). Admin có mọi quyền nên thấy đủ 6 nhóm.
  - Nhóm 1-3 (Marketing/Data lạnh/BDM) yêu cầu `lead.distribute_team` — chưa gán role nào ngoài admin, cần assign khi có team booking thực tế.
- **Chưa làm** (đẩy sang 6.6.c/d):
  - Màn duyệt lead "Khách tự đến" (approval_status = pending) cho CM cơ sở.
  - Form chia lead thêm ô "Thu hồi sau XX ngày / Chia vĩnh viễn" (khi role có `lead.recall`).
  - Màn "Quy tắc vận hành" (permission `ops.manage`) — 3 tab.

## Phase 6.6.a — Data & permission (nền) ✅
- **Ngày hoàn thành**: 2026-07-16
- **Đã làm**:
  - Migration `2026_07_15_100000_phase_6_6_lead_source_group_and_recall_policies.php`:
    - `leads` thêm 7 cột: `source_group`, `approval_status`, `approval_by`, `approved_at`, `overdue_marked_at`, `recall_at`, `is_permanent_assignment` + 3 index.
    - Tạo bảng `recall_policies` (unique per org_unit) + `system_settings` (key-value).
    - `lead_distribution_logs` thêm cột `reason`.
  - `PermissionSeeder`: thêm 4 permission mới — `lead.distribute_team`, `lead.distribute_ctv`, `lead.approve_source`, `ops.manage`. Giữ `lead.pull_pool` (user muốn giữ, đổi mô tả "legacy").
  - `OrgAndRoleSeeder`: seed 3 role hệ thống `CM Hà Nội` / `CM Đà Nẵng` / `CM HCM`, gán `lead.distribute_ctv`.
  - `RecallPolicyResolver` (app/Services): resolve theo path materialized, **ancestor gần root nhất thắng** (cha override con). Fallback null → system_settings → mặc định `allow_permanent = true`.
  - `DemoDataSeeder`: reset 5 lead demo với 5 `source_group` khác nhau (marketing/data_cold/bdm/referral/walk_in), lead walk_in để `approval_status = pending` minh họa luồng duyệt.
  - Test `RecallPolicyResolverTest`: **8 test** cover cascade (system default / team riêng / phòng override team / ancestor cao nhất thắng / sibling không leak / null fallback / root không policy / cây sâu 4 cấp).
- **Kết quả test suite**: **104/105 pass** (thêm 8 test mới của tao, tất cả pass).
- **⚠️ 1 test cũ vẫn fail — KHÔNG do Phase 6.6**: `LeadScopeTest::test_team_scope_sees_all_leads_in_subtree`. Verify bằng `git stash + test` → fail cả khi rollback toàn bộ thay đổi Phase 6.6. Nguyên nhân: assertion cũ (`assertNotContains($noOrg->id, $visible)`) mâu thuẫn với logic hiện tại của `Lead::scopeVisibleTo` (line 176) — user có scope org thấy được kho chung. Test này đã broken từ trước, cần bàn với user: (a) sửa test cho khớp logic mới hoặc (b) đảo logic scope.
- **Ghi chú**:
  - Ancestor resolve dùng path materialized `/1/4/9/` — cực nhanh, không đệ quy.
  - `system_settings` để trống — sẽ fill giá trị mặc định ở phase 6.6.c (màn ops) qua UI của admin. Nếu chưa fill: `allow_permanent = true`, các số ngày = null (nghĩa là "không có mặc định" → CM phải nhập tay ở form chia).
- **Chưa làm (chuyển sang 6.6.b/c/d)**: form chia lead với ô "Thu hồi sau XX ngày", 6 luồng nghiệp vụ, màn Quy tắc vận hành, job scheduler recall/escalate/mark-overdue.

## Chốt thiết kế Phase 6.6 — Luồng vận hành 6 nguồn + recall/escalate 🔷 (design only, chưa code)
- **Ngày**: 2026-07-15
- **Bối cảnh**: user đưa sơ đồ luồng 6 nhóm nguồn (bảng + flowchart). Tao review, đặt 4-5 câu hỏi bóc tách, cuối cùng chốt design đầy đủ trước khi động code (đúng CLAUDE.md).
- **Chốt (chi tiết ở scope.md 6.3 + 7.6, ERD.md B2-B3, plan.md Phase 6.6)**:
  - **6 nhóm nguồn**: Marketing / Data lạnh / BDM / Bạn giới thiệu / CTV / Khách tự đến — mỗi nhóm có `source_group` riêng, quyết định luồng đi (kho booking / kho CM cơ sở / thẳng vào sale).
  - **Permission mới**: `lead.distribute_team`, `lead.distribute_ctv`, `lead.recall`, `lead.approve_source`, `ops.manage`. Deprecate `lead.pull_pool` (không xóa để không gãy dữ liệu).
  - **Role hệ thống mới**: `CM Hà Nội` / `CM Đà Nẵng` / `CM HCM` — user tự thêm tỉnh sau.
  - **Recall 2 tầng**: CM chia đặt mốc "Thu hồi sau XX ngày" hoặc "Chia vĩnh viễn" (admin bypass được). Hết hạn → về pool team CM. Quá `escalate_after_days` → lên kho CM cấp cha.
  - **Cấu hình thời gian**: bảng mới `recall_policies` per org_unit. **Phòng cha override toàn bộ team con** (user chọn cách A, chặt chẽ theo luồng quản lý).
  - **Bỏ hoàn toàn NV tự kéo lead** khỏi kho phòng: chỉ user có `lead.distribute_team` mới thấy kho team.
  - **Trang mới "Quy tắc vận hành"** (permission `ops.manage`) 3 tab: giám sát phân bổ / cấu hình thời gian / danh sách overdue booking.
- **Q&A gạch đầu dòng đã chốt với user** (giữ để tránh quên context):
  - Nhánh "Không đồng ý" ở kho booking → ở lại kho booking, đánh dấu overdue, không auto-delete.
  - Cứng tên user "HN Giang / ĐN Linda / HCM Jenny" → **bỏ**, chuyển thành role + permission.
  - Nhóm 4 (Bạn giới thiệu): người up **tự chọn sale**, không duyệt (mọi cấp).
  - Nhóm 6 (Khách tự đến): CM cơ sở duyệt; nhân viên nào cũng up được (kể cả CTV).
  - Thời gian escalate: **tách riêng** với thời gian hoàn số (2 tham số khác nhau).
  - Thu hồi số → về pool team → CM team duyệt → quá hạn escalate lên CM khu vực.
- **Breaking changes**:
  - Lead cũ (~130 lead demo hiện có) không có `source_group` — plan backfill: mặc định `marketing` cho lead từ import/webhook, `referral` cho lead nhập tay không nguồn ads.
  - `sla_policies` (Phase 4) giữ nguyên — khác khái niệm với `recall_policies` (SLA = chăm sóc quá X giờ; recall_policies = thời gian sở hữu do CM đặt).
  - UI Màn 12 (kho lead) mất nút "Kéo về tôi" → cần cập nhật hướng dẫn user.
- **Trạng thái**: chỉ update tài liệu, chưa code. Task list ở `plan.md` Phase 6.6 chia làm 4 nhóm (data/permission → nghiệp vụ → màn ops → test). Tao đề xuất bắt đầu từ **6.6.a (data & permission)** vì các phần sau phụ thuộc vào migration + permission mới.

## Import chính rule-based (template + mặc định + trường tùy biến) ✅ (bổ sung, xen Phase 7)
- **Ngày**: 2026-07-06
- **Bối cảnh**: sau khi làm khu demo rule-based, user chốt nâng **màn import chính** lên tương tự (thay vì 2 luồng song song). Scope: template dùng chung toàn công ty + giá trị mặc định + map cả trường tùy biến; giữ nguyên pipeline `raw_leads` → `ProcessRawLead` (async, dedup, sinh mã, chia số).
- **Đã làm**:
  - Bảng `import_templates` (MySQL) + model `ImportTemplate` — tên + config `[{target, header, default}]`, dùng chung. `target` = field lead chuẩn hoặc `cf_<id>`.
  - Màn import (`⚡lead-import`): chọn/áp/lưu/xóa **template**; cột **"Mặc định"** cho từng trường (điền khi ô trống); danh sách target giờ gồm **trường tùy biến đang áp** (cf_) — auto-đoán theo nhãn; bỏ dòng Tên+SĐT đều trống.
  - `ProcessRawLead`: đọc payload `cf_<id>` → ghi `LeadCustomValue` (lưu mọi cf hợp lệ, không lọc org vì org chỉ quyết định lúc hiển thị) → `generateCode()` (nối mã từ classification mức công ty). Dedup/sinh mã/chia số giữ nguyên.
- **Test**: **88/88 pass** (thêm test pipeline ghi custom value + nối mã `KH-{id}-2026`). Màn `/leads/import` render 200 với UI template.
- **Ghi chú**: default value áp lúc import (trong component), không đụng job. Trường tùy biến của phòng vẫn map/ghi được dù lead mới vào kho chung (org null) — khi lead chuyển phòng sẽ có sẵn dữ liệu. Demo cũ giữ nguyên làm sân tập; gỡ sau nếu cần.

## Trường tùy biến đa cấp + Duyệt + Mã phân loại 🔶 (bổ sung, xen Phase 7 — đang làm)
- **Ngày**: 2026-07-05
- **Bối cảnh**: user yêu cầu mở rộng trường tùy biến (Phase 2.5) thành hệ thống đa cấp có duyệt + mã phân loại nối vào mã KH. Làm theo 5 lớp.
- **Chốt thiết kế với user trước khi làm**:
  - Mã KH: **cố định chỉ `KH-{id}`** (zero-pad ≥3 số, id lớn dài tự nhiên). Mọi đoạn sau do **classification field** cấu hình sinh, theo cây công ty→phòng→nhóm. VD `KH-001-2026-MKT-FB`.
  - **Xóa hẳn `type_code`/`source_code` cứng** (user: "cái gì thừa xóa đi, toàn demo"). Vai trò chuyển sang classification field.
  - Định danh core = `leads.id` (bigint), không UUID (phân mảnh index ở quy mô 300k). `code` chỉ là mã hiển thị, đổi format an toàn (không FK nào bám `code`).
  - Trường bắt buộc **cấp công ty**: áp ngay, không duyệt. **Cấp phòng/nhóm**: chờ cấp trên (`field.approve` ở node cha) duyệt mới áp. Trường pending **ẩn** với người đề xuất tới khi duyệt.
  - Toggle báo cáo tắt = chỉ trường hệ thống + mức công ty.
- **Đã làm (Lớp 1–4, verify OK)**:
  - **L1 data+engine**: migration mở rộng `custom_fields` (`rules` json, `affects_code`, `status`/`requested_by`/`reviewed_by`/`reviewed_at`/`reject_reason`; trường cũ backfill `active`); drop `type_code`/`source_code` khỏi `leads` + `default_type_code` khỏi `source_connections`; dọn 14 file. `CustomField` 6 kiểu (text/number/date/**email**/select/**code**), `applicableTo()` lọc `status=active` + sắp theo cây (sort key gộp), `codeSegmentsFor()`. `Lead::generateCode()` viết lại. Verify: `KH-002-2026-MKT-FB`, đổi giá trị→mã đổi, pending bị loại.
  - **L2 field manager + duyệt**: component field-manager nâng cấp (kiểu mới + ràng buộc min/max/maxlength/options/mã cố định-chọn-nhập + cờ nối mã; bắt buộc cấp dưới→pending). Component **duyệt** mới (`field.approve`, node cha duyệt/từ chối kèm lý do). Màn **"Thiết lập"** trong dropdown user, chia tab (Trường tùy biến / Duyệt trường). Quyền `field.approve` đã seed.
  - **L3 lead-form**: render + validate email/code + ràng buộc số(min/max)/text(maxlength); mã cố định tự động (bỏ khỏi input); gỡ "Loại data" cứng; `generateCode()` gọi **sau** khi lưu custom values.
  - **L4 báo cáo**: thêm tab **"Chi tiết lead"** + toggle **"Hiện đầy đủ trường tùy biến"** (tắt = chỉ trường mức công ty), áp cả bảng web lẫn Export Excel.
- **Test**: **87/87 pass** (thêm 2 test duyệt: pending/rejected không áp; 4 test sinh mã đa cấp thay 4 test type_code cũ). Blade compile sạch; `/settings` + `/reports` (tab mới) render 200 với admin.
- **Còn lại**: rà QA tay đầy đủ luồng duyệt trên UI thật (tạo field cấp nhóm bằng tài khoản trưởng nhóm → trưởng phòng duyệt); cập nhật `ERD.md` chi tiết bảng custom_fields mới.
- **Ghi chú môi trường**: máy thiếu `pdo_sqlite`+`pdo_pgsql` → đã bật trong `php.ini`. Laragon MySQL hay tắt → start `mysqld --defaults-file=...\my.ini` (datadir `F:/Laragon/data/mysql-8`).

## QA Mobile toàn hệ thống ✅ (xen giữa Phase 7)
- **Ngày hoàn thành**: 2026-07-04
- **Bối cảnh**: user yêu cầu tối ưu mobile, không chấp nhận vỡ chữ / font sai. Duyệt toàn bộ 18+ màn ở viewport 375px.
- **🐛 Lỗi thật tìm được & đã sửa**:
  1. **CHẶN: không có menu trên mobile** — nav dùng `hidden md:flex` mà thiếu hamburger → điện thoại không vào được màn nào ngoài dropdown avatar. Thêm nút hamburger + drawer menu (Alpine) liệt kê đủ mục theo quyền; gom `navItems` dùng chung desktop/mobile.
  2. **Bảng tràn cả trang** ở 7 chỗ thiếu wrapper `overflow-x-auto` (quản lý nhân viên, trường tùy biến, cấu hình rule, sổ thu tiền + công nợ, danh mục dịch vụ, báo cáo marketing + hiệu suất, lịch sử import) → cả body cuộn ngang. Bọc từng bảng trong `overflow-x-auto` + `min-w-[...]` để chỉ bảng cuộn.
  3. **Thanh tab khu Tổ chức vỡ chữ** — 4 tab bóp thành 3-4 dòng/tab trên mobile. Đổi sang cuộn ngang 1 dòng (`overflow-x-auto` + `whitespace-nowrap`).
  4. **Checkbox màu xanh mặc định trình duyệt** thay vì vàng đồng — lệch theme toàn hệ thống. Thêm `accent-color: #8B5E14` global cho mọi checkbox/radio.
  5. Giảm padding navbar + main trên mobile (`px-6`→`px-4`), logo + user info thu gọn responsive.
- **Kết quả QA**:
  - Quét tràn ngang bằng script (bounding-box) trên **18 màn**: login, dashboard, danh sách/chi tiết/thêm/import/lead-lỗi KH, 4 tab tổ chức, chia số (rule + kho), dịch vụ, thu tiền, báo cáo, kết nối nguồn, quản lý phiên → **tất cả scrollWidth = 375px, 0 phần tử tràn**.
  - Font Be Vietnam Pro đúng trên mọi màn (kiểm bằng screenshot).
  - Checkbox vàng đồng, tab cuộn 1 dòng — verify bằng screenshot màn vai trò.
  - Regression desktop: nav hiện đủ 8 mục, bảng bình thường, không hỏng bản rộng.
  - 88/88 test vẫn pass (chỉ sửa view, không đụng logic).
- **Ghi chú**: bảng dữ liệu dày (list KH, báo cáo) trên mobile dùng chiến lược cuộn ngang nội bộ (`min-w` + `overflow-x-auto`) — chuẩn cho bảng nhiều cột; không ép xuống card layout để giữ nhất quán desktop/mobile.

## Phase 6 — Báo cáo & Dashboard ✅
- **Ngày hoàn thành**: 2026-07-04
- **Đã làm**:
  - Bảng `stats_daily` (ERD B7): dims date/org/user/camp/ad_source + counters funnel + revenue_collected; unique theo tổ hợp chiều.
  - `StatsAggregator` idempotent (xóa ngày rồi ghi lại): funnel từ leads (received_date × classification hiện tại), revenue từ payments (paid_at × người thu). Command `stats:aggregate --from --to`; schedule 2 phút/lần cho hôm nay (độ tươi 1–3 phút theo scope) + chốt cứng hôm qua lúc 00:30. Backfill 31 ngày.
  - **Hoàn thiện `top_revenue` / `top_close_rate`** (nợ từ Phase 4): engine đọc metric từ stats_daily theo `metric_window` của rule (day/week/month/custom), chọn đích metric cao nhất còn đủ điều kiện (vẫn né người tắt nhận số/chạm trần), hòa thì theo position.
  - Màn 6 — Dashboard: 6 stat cards funnel tháng, lead về hôm nay, doanh thu thực thu tháng, top 5 sale, lead chưa chăm/quá SLA (theo policy), bảng lead mới nhất; poll 60 giây; **toàn bộ số liệu lọc theo data scope** của người xem.
  - Màn 17 — Báo cáo: 4 tab cắt theo kỳ tùy chọn — Funnel (bar + tỉ lệ chuyển đổi từng bước), Hiệu quả marketing (cắt theo camp/nguồn/PAGE), Hiệu suất sale (nhận/booking/close/close rate/doanh thu, xếp hạng), Chia số & tồn kho (log 4 loại hành động + tồn 3 cấp kho).
  - **Export Excel** (.xlsx qua phpspreadsheet) theo quyền `lead.export`, **mỗi lần export ghi audit log** kèm loại báo cáo + khoảng ngày.
  - Nav "Báo cáo" theo quyền `report.view`. 7 test mới. Tổng suite: **88/88 pass**.
- **Kết quả QA thật**:
  - Dashboard hiện số thật: 40 lead tháng, 36 lead hôm nay, 1 close, doanh thu 3tr — khớp dữ liệu các phase trước.
  - Báo cáo hiệu suất xếp hạng đúng (admin 3tr doanh thu, Trần Văn Sale 19 lead nhận/1 close/5.3%).
  - Bấm Xuất Excel → audit log ghi `{report: performance, from, to}` đúng.
  - User sale (không có report.view) → `/reports` 403, dashboard vẫn xem được trong phạm vi mình.
  - Không lỗi console, 88/88 test pass.
- **Ghi chú & quyết định**:
  - Funnel đếm theo **classification hiện tại** của lead nhận trong ngày (snapshot) — lead đổi trạng thái thì số quá khứ cập nhật theo; muốn funnel "tại thời điểm" thì cần đếm theo event log, để Phase 8 bàn nếu cần.
  - Tab marketing cắt theo PAGE query trực tiếp bảng leads (stats_daily không có chiều page — thêm chiều nếu dữ liệu lớn làm chậm).
  - Lead kho chung (chưa có org) không tính vào dashboard/báo cáo của manager team (đúng logic scope); admin root thấy đủ.

## Phase 5 — Dịch vụ, thanh toán, % đóng góp ✅
- **Ngày hoàn thành**: 2026-07-04
- **Đã làm**:
  - Migrations ERD B4-B5: `services` (2 kiểu giá: trọn gói / theo phase), `service_phases`, `customer_services` (giá chốt override niêm yết), `customer_service_phases` (ai làm, ngày làm, note bàn giao), `payments`, `contributions`, `contribution_templates`.
  - `CustomerService`: `outstanding()` = giá chốt − Σ đã thu (tính động, không lưu, không âm); `initPhases()` sinh tiến độ; xong hết phase → tự chuyển completed.
  - **`ContributionService`**: `suggestParticipants()` gợi ý người tham gia từ dữ liệu tường minh (người nhận, người giữ, người chăm qua status log, người làm phase — không suy đoán); `save()` enforce Σ=100 + không trùng người, lưu lại là ghi đè bảng cũ.
  - Màn 15 — Danh mục dịch vụ: CRUD + phases (chặn bớt phase khi đã có khách dùng), tự sinh code; CRUD mẫu % đóng góp (validate Σ=100, 1 mẫu mặc định duy nhất).
  - Chi tiết KH — khối "Dịch vụ & Tiến độ": gắn dịch vụ (giá chốt tự điền từ niêm yết, sửa được), tick hoàn thành phase kèm **note bàn giao**, hoàn tác, thu tiền theo dịch vụ, hiện đã thu/công nợ + lịch sử thu.
  - Màn 10 — Popup % đóng góp: **tự mở khi đổi phân loại sang Close** (quyền `contribution.set`), gợi ý người tham gia + áp % theo mẫu mặc định, tổng hiện đỏ/xanh theo 100, mở lại sửa được.
  - Màn 16 — Thu tiền & Công nợ: 3 số tổng (thu hôm nay/tháng, tổng công nợ), tab sổ thu tiền (lọc ngày) + tab công nợ.
  - Nav thêm "Dịch vụ" (service.manage) + "Thu tiền" (payment.record). 10 test mới. Tổng suite: **81/81 pass**.
- **Kết quả QA thật (case điển hình scope.md 8.1-8.2, làm trên UI)**:
  - Tạo dịch vụ "Liệu trình da liễu" 10 phase giá theo phase (niêm yết 10tr) + mẫu % mặc định 20-30-50.
  - Gắn vào khách với giá chốt 9tr (override) → 10 phase pending sinh sẵn.
  - Hoàn thành phase 1 kèm note bàn giao "Da nhạy cảm..." → lưu đúng ai làm/lúc nào/note (người care tiếp đọc được).
  - Thu 3tr tiền mặt → công nợ tự tính 6tr.
  - Đổi phân loại sang **Close → popup % tự mở**, gợi ý đúng 2 người tham gia + % theo mẫu; bấm lưu khi tổng 70% → chặn "Tổng % phải đúng 100 (hiện tại: 70)"; sửa đủ 100 → lưu OK (50/50, ghi kèm set_by).
  - Màn 16 hiện đúng: thu hôm nay 3tr, công nợ 6tr, sổ có dòng thu.
- **Ghi chú**:
  - Bug migration nhỏ trong lúc làm: tên index tự sinh của `customer_service_phases` vượt 64 ký tự MySQL → đặt tên tay. 
  - Thu tiền đang gắn mức dịch vụ (đủ cho báo cáo doanh thu); gắn từng phase (`customer_service_phase_id`) đã có cột, UI chọn phase cụ thể để sau nếu cần.

## Phase 4 — Engine chia số ✅
- **Ngày hoàn thành**: 2026-07-04
- **Đã làm**:
  - Migrations ERD B3: `distribution_rules`, `rule_targets`, `rule_counters` (unique theo rule+target+ngày), `lead_caps`, `user_lead_settings`, `sla_policies`, `lead_distribution_logs` + bảng `notifications`.
  - **`DistributionEngine`**: chia 2 cấp (kho chung → team → sale), rule khớp theo priority (khớp đầu tiên dừng), điều kiện lọc khu vực/camp/nguồn/page; strategy round-robin + tỉ trọng (chọn đích có delivered/weight nhỏ nhất — `top_revenue`/`top_close_rate` tạm fallback round-robin, hoàn thiện Phase 6); constraints: bật/tắt nhận số (nghỉ phép có hạn tự bật lại), **trần lead 3 cấp** (check cả trần phòng cha theo path, chạm trần nhảy đích kế tiếp, kẹt hết thì lead nằm lại kho); counter reset theo ngày.
  - Chống race: `insertOrIgnore` counter + `SELECT ... FOR UPDATE` trong transaction (attempts=5) + job idempotent retry khi deadlock.
  - SLA recall: command `leads:recall-overdue` (schedule 10 phút/lần) — quá X giờ không chăm → thu hồi về team/kho chung → chia lại ngay; policy riêng từng org đè mặc định; mode manual/off không đụng.
  - Thao tác thủ công trên engine: thu hồi (`lead.recall`), chia tay (`lead.distribute`), kéo lead từ kho (`lead.pull_pool`) — đều ghi `lead_distribution_logs` kèm actor.
  - Màn 11 — Cấu hình rule: 2 bảng rule theo cấp, modal đủ điều kiện lọc/strategy/targets + tỉ trọng, bật/tắt/xóa, cấu hình SLA mặc định toàn cty.
  - Màn 12 — Kho lead 3 cấp: tab chung/team/cá nhân (đếm số), chia tự động/chia tay/kéo về tôi/thu hồi/chuyển người theo quyền, kho chung hiện SĐT mask với người ngoài scope.
  - Notification `LeadAssigned` (database + broadcast Reverb) + chuông navbar (poll 10s, badge unread, mở là đánh dấu đã đọc).
  - 20 test mới (engine 13 + SLA/manual 7). Tổng suite: **71/71 pass**.
- **Kết quả QA thật (race test trên MySQL)**:
  - Bắn 12 lead dồn dập qua webhook → **3 queue worker chạy song song** → chia đều tuyệt đối 6-6 giữa 2 sale, counter khớp từng số, **0 failed job**, notification đủ.
  - UI: chia tự động 1 lead kho chung → flash "Đã chia ... cho Trần Văn Sale"; thu hồi ở kho cá nhân → lead về kho team + log recall; badge chuông của sale hiện đúng số unread.
- **🐛 3 bug thật tìm được nhờ race test + QA** (đã fix hết):
  1. `firstOrCreate` rule_counters bị race giữa 2 worker → duplicate key → job chết, lead kẹt kho chung. Fix: `insertOrIgnore` atomic.
  2. Deadlock MySQL khi nhiều worker lock counters → fix: transaction retry (attempts=5) + job idempotent ($tries=3, chạy lại thì chia tiếp lead dở dang thay vì bỏ qua).
  3. Method `pull()` trong Livewire component đụng tên `Livewire\Component::pull()` có sẵn → 500. Đổi `pullLead()`.
- **Dời lại / chưa xong**:
  - Echo JS client (toast realtime trên browser) chưa gắn — chuông đang chạy polling 10s (scope cho phép polling làm phương án phụ). Reverb server + broadcast phía server đã chạy OK; gắn Echo CDN khi làm toast UI.
  - Trần lead + bật/tắt nhận số mới có engine + data, chưa có UI quản lý riêng (cấu hình qua tinker/DB) — sẽ gắn vào màn nhân viên hoặc màn rule sau.
- **Ghi chú vận hành**: dev cần chạy song song `php artisan queue:work` + `php artisan reverb:start` (không bật Reverb thì broadcast job fail — không ảnh hưởng chia lead nhưng rác failed_jobs).

## Phase 3 — Pipeline raw → clean + Import ✅
- **Ngày hoàn thành**: 2026-07-03
- **Đã làm**:
  - Postgres (connection `pgsql`): `raw_leads` (JSONB + GIN index + expression index theo phone), `import_batches`, `ingest_logs`. Khi test tự chuyển sang sqlite in-memory (`DB_RAW_DRIVER=sqlite` trong phpunit.xml) — test không đụng Postgres thật.
  - MySQL: `source_connections` (type, credentials mã hóa, webhook_token, field_mapping, default_type_code).
  - **Job `ProcessRawLead`** (queue database): validate tên/SĐT → chuẩn hóa → check trùng (trùng thì **gộp field còn trống vào lead cũ + log**, không tạo mới) → tạo lead sạch vào kho chung kèm `raw_lead_id`, sinh mã KH, parse ngày đa định dạng (d/m/Y, Y-m-d...).
  - Màn 13 — Import: upload CSV/XLSX (phpspreadsheet), tự đoán column mapping theo tên cột, preview 5 dòng, lịch sử batch tự refresh 5s với thống kê thành công/trùng/lỗi/đang chờ.
  - Màn 14 (nửa lead lỗi) — danh sách raw failed kèm lý do + payload, **sửa nhanh tên/SĐT rồi chạy lại pipeline**, hoặc loại bỏ.
  - Webhook `POST /webhook/lead/{token}` (miễn CSRF, xác thực token connection, hỗ trợ field_mapping) + ghi `ingest_logs` mọi call kể cả token sai.
  - 12 test mới (job pipeline 8 + webhook 4). Tổng suite: **51/51 pass**.
- **Kết quả QA thật**:
  - Webhook bắn curl thật: 202 → queue xử lý → lead `KH-00033-MKT-FB` (SĐT +84 chuẩn hóa, FB tự suy); token sai → 401 + có log.
  - Import CSV 6 dòng qua browser: 3 thành công (`KH-00034-MKT-FB`, `KH-00035-C-GG`, `KH-00036-BDM-TT` — đúng loại/nguồn từng dòng), 1 trùng tự gộp vào lead webhook, 2 lỗi đúng lý do ("SĐT không hợp lệ", "Thiếu tên").
  - Màn lead lỗi: sửa SĐT/tên → chạy lại → cả 2 dòng lỗi thành lead sạch, danh sách lỗi về 0.
- **🐛 Bug thật tìm được khi QA**: nạp Alpine.js CDN riêng trong khi Livewire đã bundle Alpine → 2 instance chạy song song, `wire:click` chập chờn (lúc ăn lúc không). Đã gỡ Alpine CDN khỏi `layouts/base.blade.php`, ghi cảnh báo vào CLAUDE.md. Sau fix mọi nút hoạt động ổn định.
- **Ghi chú**:
  - Dev cần chạy queue worker cho pipeline: `php artisan queue:work` (QA dùng `--stop-when-empty` từng đợt).
  - Webhook connection mẫu "Landing page chính" đã seed kèm token (xem bảng `source_connections`).
  - Ads API (Facebook/TikTok/Google) dời Phase 7 như kế hoạch.

## Phase 2.5 — Mã KH + trường tùy biến phòng ban ✅ (bổ sung theo whiteboard)
- **Ngày hoàn thành**: 2026-07-03
- **Đã chốt với user trước khi làm** (4 câu hỏi):
  - Mã KH = số tăng dần toàn hệ thống + hậu tố loại/nguồn: `KH-00123-MKT-FB`.
  - Admin của phòng tự định nghĩa trường (quyền `field.manage` gán qua assignment tại phòng); mức công ty cần assignment ở node gốc.
  - Bộ trường áp theo **phòng ban đang giữ lead** (+ thừa hưởng từ phòng cha + trường mức công ty).
  - Workflow sửa tuần tự A→B: ghi backlog sau Phase 8 (scope.md 4.3).
- **Đã làm**:
  - `leads` thêm `code` (unique) / `type_code` (MKT, C, BDM, SI, N) / `source_code` (FB, GG, TT... tự suy từ nguồn quảng cáo); `generateCode()` idempotent, đổi loại thì mã đổi theo; backfill 31 lead cũ.
  - Bảng `custom_fields` (org null = mức công ty; kiểu text/số/ngày/select; bắt buộc; ngưng dùng) + `lead_custom_values`.
  - Màn mới "Trường tùy biến" (tab thứ 4 khu Tổ chức, quyền `field.manage`): chọn phạm vi công ty/phòng, CRUD field, chặn xóa field đã có dữ liệu (chỉ cho ngưng dùng).
  - Form lead: chọn Loại data, khối "Trường bổ sung" render động theo phòng của owner (đổi owner là đổi bộ trường), validate bắt buộc + kiểu số + giá trị select.
  - Danh sách KH: cột Mã KH, search theo mã; Chi tiết KH: mã dưới tên + khối trường bổ sung.
  - Cập nhật `scope.md` (mục 4.1–4.3) + `ERD.md`; 8 test mới (format mã, đổi loại, map nguồn, kế thừa field theo cây, không leak sang phòng ngang hàng, inactive, lead kho chung).
- **Kết quả test + QA**:
  - Test suite 39/39 pass, không lỗi console.
  - QA browser: tạo field "Mã giới thiệu" (công ty, bắt buộc) + "Nhu cầu dịch vụ" (select, Phòng Kinh doanh) → form lead chia cho sale Team A hiện đúng cả 2 (Team A thừa hưởng từ Kinh doanh); bỏ trống trường bắt buộc bị chặn; lưu thành công → mã `KH-00032-MKT-FB` (MKT chọn tay, FB tự suy từ Facebook Ads), chi tiết hiện đủ giá trị.
- **Ghi chú**:
  - 5 loại data đang là hằng số trong code (`Lead::TYPE_CODES`) — muốn thêm/sửa loại cần sửa code. Nếu cần admin tự quản lý loại data thì nói, tao chuyển thành bảng cấu hình.
  - Diagram còn 2 nhánh "Kho data Ebiz / PMDK" (kho ngoài) — chưa rõ là hệ thống gì, cần mày mô tả thêm trước khi đưa vào scope.

## Phase 2 — Lead CRUD (tầng clean) ✅
- **Ngày hoàn thành**: 2026-07-03
- **Đã làm**:
  - Migrations theo ERD B2: `leads` (đủ trường + 6 index + unique phone + soft delete), `lead_status_logs`, `audit_logs` (index theo user/entity/action).
  - Model `Lead`: 14 phân loại (new + 13 trạng thái scope), `scopeVisibleTo()` (org_unit trong phạm vi OR owner/receiver là mình; không assignment → không thấy gì), `phoneFor()`/`maskPhone()` (090***4567), `normalizePhone()` chuẩn hóa SĐT VN (+84/84/9 số → 0XXXXXXXXX).
  - `LeadStatusLog::record()` ghi lịch sử chăm sóc; `AuditLog::record()` ghi create/update/view_phone kèm IP.
  - Màn 7 — Danh sách KH: filter ngày/camp/nguồn/phân loại + search tên/SĐT (search SĐT tự normalize), pagination 15/trang, badge màu theo funnel, SĐT hiển thị qua `phoneFor()`.
  - Màn 8 — Thêm/Sửa KH: layout 3 khối như Figma, validate + normalize SĐT, chống trùng (báo lỗi + link mở lead hiện có nếu trong scope), tạo mới → receiver = người nhập, chọn owner → pool personal + org theo assignment của owner, không chọn → kho chung.
  - Màn 9 — Chi tiết KH: SĐT mặc định che, nút "Hiện số" ghi audit từng lần xem; thêm ghi chú; đổi phân loại ngay trên trang (cập nhật `last_care_at`); timeline lịch sử từ `lead_status_logs`.
  - Routes gắn middleware `permission:lead.view/create/update`; nav "Khách hàng" bật theo quyền.
  - 11 test mới (LeadScopeTest): scope self/team/không assignment/chồng chéo, mask trong/ngoài scope + quyền view_phone, normalize 8 case, unique index chống trùng.
- **Kết quả test + QA**:
  - Test suite 30/30 pass. Không lỗi console.
  - QA browser (admin): tạo lead SĐT `+84 930 000 014` → bắt trùng đúng (normalize khớp lead cũ), đổi SĐT mới → tạo OK, redirect chi tiết; bấm "Hiện số" → audit_logs có `view_phone`; thêm note + đổi phân loại → timeline + `lead_status_logs` đủ.
  - QA scope: seed 30 lead — user sale (self@TeamA + team@TeamB) thấy đúng 17 (10 team B + 7 của mình), không thấy kho chung; UI danh sách hiển thị đúng "tổng số 17".
- **Ghi chú & quyết định phát sinh**:
  - SĐT trong scope vẫn che mặc định ở màn chi tiết, bấm "Hiện số" mới hiện + ghi audit — theo ERD "mọi lần xem số đầy đủ ghi audit_logs". Ngoài scope thì mask cứng (sẽ gặp thực tế ở màn kho lead Phase 4).
  - Chuẩn hóa SĐT dạng VN 10 số `0XXXXXXXXX` thay vì E.164 `+84...` — dễ đọc, dễ search, khớp dữ liệu nhập tay thực tế. Nếu sau này cần đa quốc gia thì thêm cột country_code.
  - Dropdown "Lead chia cho" chỉ hiện user thuộc phạm vi của người thao tác.

## Phase 1 — Tổ chức & phân quyền ✅
- **Ngày hoàn thành**: 2026-07-03
- **Đã làm**:
  - Migrations + models theo ERD B1: `org_units` (materialized path `/1/2/3/`, sâu tùy ý), `roles`, `permissions` (19 quyền, 6 nhóm), `permission_role`, `assignments` (user+role+org_unit+data_scope+valid_from/to), `assignment_scope_nodes`.
  - **Trait `HasAccessControl`** trên User — lõi phân quyền toàn hệ thống: `hasPermission()` (union quyền mọi assignment còn hiệu lực), `visibleOrgUnitIds()` (bung subtree theo path prefix cho scope team/custom), `hasSelfScope()`. Cache theo request.
  - **16 unit test** cho access control: assignment inactive/hết hạn/tương lai, union quyền, subtree các scope, case chồng chéo "sale team A kiêm manager team B", cây sâu 4 cấp, path prefix không leak. 
  - Middleware `permission:key` chặn route theo quyền (403).
  - Màn 3 — Quản lý nhân viên: bảng + search/filter, CRUD user, khóa/mở khóa (chặn tự khóa mình), modal phân quyền gán nhiều assignment, tree checkbox chọn node khi scope custom.
  - Màn 4 — Thiết lập vai trò: danh sách role + checkbox quyền theo nhóm, chọn tất cả/theo nhóm, chặn xóa role hệ thống & role đang được gán.
  - Màn 5 — Sơ đồ tổ chức: cây đệ quy, thêm node gốc/con (tự sinh code), đổi tên, ngưng hoạt động, xóa (chặn khi còn con/còn nhân sự), đếm nhân sự mỗi node.
  - Seeder: 19 permissions, 3 role (Admin hệ thống full quyền, Manager, Sale), cây tổ chức mẫu, assignment admin (custom scope = cả cây).
- **Kết quả test + QA**:
  - Test suite 19/19 pass. Không lỗi console.
  - QA browser: tạo user mới, gán 2 assignment chồng chéo (Sale@TeamA self + Manager@TeamB team) qua UI → tinker xác nhận `visibleOrgUnitIds = [Team B]`, `hasSelfScope = true`.
  - Tick full nhóm quyền lead cho role Sale qua UI → user sale nhận `lead.view` ngay.
  - Login bằng user sale: `/dashboard` 200, `/org/users` + `/org/roles` đều 403 đúng.
  - Thêm node cấp 3 "Nhóm Telesale 1" qua UI → path `/1/2/3/6/`, depth 3 đúng.
- **Ghi chú & quyết định phát sinh**:
  - Design Figma màn 5 vẽ checkbox scope ngay trên sơ đồ cây; nhưng theo ERD scope thuộc **assignment** (1 người nhiều scope khác nhau) → checkbox cây đặt trong modal phân quyền của màn 3, màn 5 thuần quản lý cấu trúc cây. Đã ghi chú dẫn hướng trên màn 5.
  - Màn 5 hiển thị cây eager-load tối đa 6 cấp; cấu trúc dữ liệu không giới hạn cấp (query subtree bằng path, không đệ quy).
  - Tài khoản test: `sale.a@lara-scrm.local` / `sale@12345` (Sale@TeamA self + Manager@TeamB team).

## Phase 0 — Scaffold & nền tảng ✅
- **Ngày hoàn thành**: 2026-07-03
- **Đã làm**:
  - Scaffold Laravel 12 (PHP 8.4), cấu hình 2 connection: `mysql` (clean, default — DB `lara_scrm`) + `pgsql` (raw — DB `lara_scrm_raw`, env riêng `DB_RAW_*`). Đã test cả 2 connection thông.
  - Cài Sanctum 4.3, Livewire 4.3, Reverb 1.10; Alpine.js + Tailwind qua CDN (đúng ràng buộc không npm).
  - Migration mở rộng `personal_access_tokens` (device_name, ip, user_agent) + `users` (phone, avatar, status, last_login_at) theo ERD.
  - Layout Blade chung theo Figma "Longevity CRM" (theme vàng đồng, top navbar): `layouts/base` + `layouts/app` + `layouts/guest`.
  - Màn 1 — Đăng nhập: bám design Figma, validate, chặn tài khoản `locked`, ghi `last_login_at`, remember me, toggle hiện mật khẩu.
  - Màn 2 — Quản lý phiên (Livewire): phiên hiện tại + thiết bị khác (parse OS/browser từ user agent), kết thúc từng phiên, đăng xuất tất cả thiết bị khác; khu riêng cho token API Sanctum (thu hồi token).
  - Seeder admin: `admin@lara-scrm.local` / `admin@123`.
  - Đã test thật qua browser: login OK, sai mật khẩu báo lỗi OK, tạo phiên thứ 2 (giả lập Windows/Edge) → kết thúc phiên → thiết bị đó bị đẩy về /login ngay lập tức. Không có lỗi console.
- **Dời lại / chưa xong**:
  - "Quên mật khẩu" mới là placeholder (chưa có trong scope, chờ chốt).
  - Echo/Reverb mới cài đặt server-side, chưa nối client — sẽ nối ở Phase 4 (thông báo lead mới).
- **Ghi chú & quyết định phát sinh**:
  - DB dùng qua **DBngin** (không phải MySQL của MAMP): MySQL 8.0.33 port 3306, PostgreSQL 17.0 port 5432 (user `postgres`, không mật khẩu). Postgres đang được start thủ công bằng `pg_ctl` — nếu restart máy thì bật lại instance Postgres trong DBngin.
  - Màn quản lý phiên hiển thị **2 nguồn**: phiên web từ bảng `sessions` (SESSION_DRIVER=database — xóa row là đá văng ngay) + token Sanctum cho API/thiết bị ngoài (đúng ERD). 
  - Chạy dev: `php artisan serve --port=8000` (có sẵn `.claude/launch.json`).

## Bổ sung (2026-07-07) — Trường select có nhãn Hiển thị + mã KH + báo cáo
- **Đã làm**:
  - Trường "Danh sách chọn" giờ nhập theo cặp **Giá trị + Hiển thị** (form từng dòng, có nút xóa) thay cho textarea; nhãn lưu ở `rules.option_labels` (map value→label, tương thích ngược). Thêm ô tick **"Nối Giá trị vào mã KH"** cho select (`affects_code`). Form lead + báo cáo hiển thị nhãn, lưu giá trị.
  - `CustomField::codeSegmentsFor($lead, $onlyRequired)` + helper `optionLabel()`.
  - Trang **settings/fields**: thêm tab **"Quy tắc đã tạo"** — tổng quan mỗi cấp tổ chức là 1 bộ trường (chip label·kiểu, #mã, *bắt buộc).
  - **Seed** (`DemoDataSeeder`): user `nvkd@sweetsica.com` (Phòng Kinh doanh) + `nvmkt@sweetsica.com` (Phòng Marketing), mật khẩu `123456`, role Sale (scope self); 5 khách `Khách test1..5` (0915588001..005) vào **kho chung**; quy tắc trường: KD = Mã phân loại(KD cố định) + Phân loại(C/BDM/BDM_BIDV/BDM_BIDV_GT nối mã), MKT = Mã phân loại(MKT) + Phân loại(FB/GG/TT/Zalo). Role Sale được cấp quyền cơ bản nếu chưa có.
  - Trang **reports** tab Chi tiết lead: 3 nút **Hiển thị full mã / mã bắt buộc / đơn giản** (đổi cách dựng cột Mã KH), cột **Họ tên · Nguồn · Người thu thập · Người phụ trách · Ngày thu thập**, và **bộ tick chọn cột** trường tùy biến. Lựa chọn (code_mode + lead_fields) **lưu theo user** ở `users.report_prefs` (json, migration mới). Export Excel khớp cột & kiểu mã.
- **Quyết định**:
  - Mã KH giữ chuẩn hóa cũ (bỏ gạch dưới): `KH-007-KD-BDMBIDV`.
  - Cấp công ty trong "quy tắc trường" (Tên/SĐT/Ngày/Người thu thập) là **field core** của lead → không seed thành custom_field để tránh trùng input.
- **Test**: blade compile OK cả 3 view; `php artisan test` (CustomField/Lead...) 15 passed; test tay generateCode: full=`KH-007-KD-BDMBIDV`, required=`KH-007`, simple=`KH-007`, optionLabel=`Nguồn BDM BIDV`.
- **Chưa làm / lưu ý**: chưa test tay qua browser (server 8000 do user giữ) — cần QA tay lại UI select/report.
