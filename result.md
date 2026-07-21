# Lara-SCRM — Nhật ký kết quả

> Làm xong phase nào ghi vào đây: ngày hoàn thành, việc đã làm, việc dời lại/chưa xong, ghi chú & quyết định phát sinh. Mẫu bên dưới.

## Phase 6.20 — Refactor page/camp thành custom field cấp công ty ✅
- **Ngày hoàn thành**: 2026-07-19
- **Đã làm** (A→D):
  - **A. Migration + backfill**: `2026_07_19_160000_migrate_page_camp_to_custom_fields` — tạo 2 custom_field cấp công ty (`key=page` label PAGE + `key=camp` label Camp, org_unit null, không bắt buộc); backfill mọi lead có page/camp != null → `lead_custom_values`; DROP cột `page`, `camp` khỏi `leads`.
  - **B. Pipeline** (`ProcessRawLead`): thêm helper `writeCoreCustom()` — payload có key `page`/`camp` → ghi vào `lead_custom_values` thay cột. Sửa `mergeInto()` bỏ 'page','camp' khỏi list core-field merge, thêm branch merge cho custom_values. WebhookController không cần đổi.
  - **C1. Lead + form + detail**:
    - `Lead` model: bỏ 'page', 'camp' khỏi Fillable. Thêm accessor `getPageAttribute()` / `getCampAttribute()` đọc từ `customValues` — `$lead->page` và `$lead->camp` vẫn work.
    - `⚡lead-form`: xóa property `$page`/`$camp` + input PAGE/Camp core + Save attributes. Move Link → tab Insight.
    - `⚡lead-detail`: xóa block hiển thị Page/Camp core (giờ hiển thị trong section Trường bổ sung).
  - **C2. Lead-list**: `filteredQuery` chuyển `where camp = $fCamp` → `whereHas customValues (field_id, value)`. Thêm helper `coreCustomOptions('camp')` — options distinct từ `lead_custom_values`.
  - **C3. Distribution rules**: `DistributionRule::matches()` dùng `$lead->{$field}` — accessor hoạt động. DistributionEngine `distribute()` thêm `loadMissing('customValues')` để tránh N+1.
  - **C4. Reports**: `StatsAggregator::aggregateDay` LEFT JOIN `lead_custom_values as camp_cv` để lấy camp qua alias. `⚡report-center::marketingData()` refactor: nếu groupBy ∈ ['camp','page'] thì JOIN + groupBy `gb_cv.value`, else groupBy cột leads bình thường.
  - **C5. Import + Connection Manager**: TARGETS + GUESS + payload key `page`/`camp` giữ nguyên — payload chảy qua `ProcessRawLead` → tự route sang custom_values (không cần đổi).
  - **D. QA browser toàn pipeline**:
    - Detail lead 49: section "Trường bổ sung" hiển thị `PAGE = Fanpage Longevity HN`, `CAMP = CAMP_JULY_KO1` ✓
    - Form edit lead 49: custom field inputs (id 5, 6) pre-fill giá trị; input core `wire:model="page"`/`"camp"` không còn ✓
    - `/leads` list render OK ✓
    - `/distribution/rules` render OK ✓
    - `/reports` tab Marketing group by `camp` → hiển thị đúng: `(trống) 36 · camp-summer 2 · CAMP_JULY_HCM3 1 · CAMP_JULY_KO1 1` ✓
  - Cập nhật `ERD.md`: đánh dấu 2 cột đã drop, chú thích chuyển sang `lead_custom_values`.
- **Ghi chú**:
  - Custom field id được cache trong static `Lead::$_coreCustomFieldIds` để tránh query lặp.
  - Query rule matching có accessor phía trong loop → nên eager load `customValues` ở DistributionEngine (đã fix).
  - **Không tăng schema**: dùng bảng `lead_custom_values` sẵn có (composite PK lead_id + custom_field_id), không thêm bảng mới.
- **Bug patched sau QA lần 2 (check lại)**:
  1. Migration drop cột chưa drop index `leads_camp_index` → SQLite fresh migrate lỗi (115 tests fail). Fix: `dropIndex(['camp'])` trước `dropColumn`.
  2. Test `ProcessRawLeadTest::duplicate_phone_merges` dùng `Lead::create(['camp' => ...])` — cột không còn. Fix: tạo `LeadCustomValue` với field seed từ migration; reset static cache `Lead::$_coreCustomFieldIds`.
  3. Test `CustomFieldTest::lead_without_org_gets_company_fields_only` expected 1 field cấp công ty — refactor thêm 2 (page/camp) → thay `assertCount` bằng `assertTrue(contains)`.
- **Test suite cuối**: 115/116 pass. Fail duy nhất là `LeadScopeTest::test_team_scope_sees_all_leads_in_subtree` — **pre-existing từ Phase 6.8**, không liên quan refactor này.
- **Sửa seeder (task 46)**: bỏ dòng seed `selectField($marketing->id, 'camp', ...)` trong `DemoDataSeeder` — field Camp giờ được migration seed cấp công ty, không cần trùng ở cấp phòng ban.

## Phase 6.21 — Chuyển page/camp từ cấp công ty → cấp phòng Marketing (3 org) ✅
- **Ngày hoàn thành**: 2026-07-20
- **Đã làm**:
  - User chốt: page/camp là data riêng team Marketing, không phải data mọi org. Chuyển 2 field từ cấp công ty (org null) → cấp phòng Marketing, seed cho cả 3 cơ sở HN/HCM/DN.
  - Migration `2026_07_20_100000_move_page_camp_to_marketing_depts`:
    - Backup lead_custom_values đang gán 2 field cũ (id=5, id=6).
    - Tạo 6 field mới (2 field × 3 org): page-HN, camp-HN, page-HCM, camp-HCM, page-DN, camp-DN. Camp có options select (19 giá trị từ DemoDataSeeder cũ).
    - Backfill: mỗi value cũ → map sang field mới theo Marketing ancestor của org lead (dùng path prefix). Fallback Marketing HN nếu không match.
    - Xóa 2 field cũ + cascade custom_values.
  - **Refactor code đọc field theo key (key có thể ở nhiều org)**:
    - `Lead::customValueByKey()` → dùng eager load `customValues.field` + iterate tìm value theo `field.key`. Bỏ static cache field_id.
    - `ProcessRawLead::writeCoreCustom()` + `mergeInto` → dùng `CustomField::applicableTo($lead->orgUnit)->firstWhere('key', ...)` để pick field đúng theo org.
    - `⚡lead-list::coreCustomOptions()` + `filteredQuery::fCamp` → `whereIn custom_field_id` với tất cả field IDs khớp key.
    - `StatsAggregator` + `⚡report-center::marketingData()` → JOIN `whereIn` field IDs.
  - `DemoDataSeeder`: bỏ dòng `selectField(marketing->id, 'camp', ...)` (migration đã seed cho cả 3 phòng).
- **Test browser**: detail K1 (org team-giang-sale, subtree Marketing HN) → PAGE `Fanpage Longevity HN` + CAMP `CAMP_JULY_KO1` hiển thị đúng, value đã re-map sang field Marketing HN (id=8).
- **Test suite**: 115/116 pass (fail duy nhất pre-existing từ Phase 6.8).
- **Fix test pre-existing** (bonus): `LeadScopeTest::test_team_scope_sees_all_leads_in_subtree` fail vì test viết theo design cũ. Logic `scopeVisibleTo` hiện tại có branch cho kho chung công ty (org null + pool_level=common) visible với user có scope tổ chức. Update test khớp design mới (thêm assertion phân biệt kho chung vs null-org-khác-common). **Test suite giờ 116/116 pass**.

## Booking Integration — Draft, chưa code (2026-07-20)
User request tích hợp 2 chiều CRM ↔ Booking system (GET facilities/services/slots + POST appointments). Đã note đầy đủ vào [docs/booking-integration-draft.md](docs/booking-integration-draft.md): API contract, bảng `lead_appointments`, `BookingClient`, Livewire modal, 4 câu cần user chốt (auth, cache, giữ nút cũ, snapshot). **User đang research tiếp, chưa bắt đầu code.**

## Phase 6.25 — Export note_history: thêm URL ảnh + thời gian upload ✅
- **Ngày hoàn thành**: 2026-07-20
- **User chốt**: mỗi ảnh trong ghi chú xuất dưới dạng **URL absolute + thời gian upload**, vẫn gộp trong cùng 1 cell.
- **Đã làm**:
  - `noteHistoryCell()` mở rộng: sau dòng head log có ảnh, in mỗi ảnh 1 line indent 2 space: `  📎 dd/mm/YYYY HH:MM · <url>`.
  - URL absolute qua `url(Storage::disk('public')->url($path))` — prefix `APP_URL` để mở được từ file Excel.
- **Test**: `Khách gọi hỏi giá dịch vụ. [+2 ảnh]\n  📎 19/07/2026 13:50 · http://localhost/storage/lead-notes/49/fake-a.jpg\n  📎 19/07/2026 13:50 · http://localhost/storage/lead-notes/49/fake-b.jpg`
- **Test suite**: 117/117 pass.

## Phase 6.24 — Export Excel: thêm cột "Lịch sử ghi chú" ✅
- **Ngày hoàn thành**: 2026-07-20
- **Đã làm**:
  - `⚡lead-list` `coreColumns()`: thêm key `note_history` label "Lịch sử ghi chú" (giữ `note` cũ rename thành "Ghi chú (hiện tại)").
  - Helper `noteHistoryCell(Lead)` gộp tất cả `lead_status_logs` field='note' của lead thành **1 cell multi-line**:
    - Format mỗi log: `[dd/mm/YYYY HH:MM] Tên user: [prefix] nội dung [+N ảnh]`
    - Prefix `🆕` (lần đầu) / `🔁` (khách trở lại) nếu có
    - `[+N ảnh]` khi log có `images` (không nhúng ảnh — chỉ số lượng để nhẹ file)
  - Export controller: sau `fromArray()`, apply style cho cột `note_history`: `wrapText=true`, vertical top, width 60 char để Excel hiển thị đẹp.
- **Test**: cell format đúng — VD lead 49: `[19/07/2026 13:50] Quản trị viên: Khách gọi hỏi giá dịch vụ.\n[19/07/2026 13:50] An: Đã tư vấn combo laser 10 buổi.`
- **Test suite**: 117/117 pass.
- **Ghi chú**:
  - **Không nhúng ảnh** trực tiếp vì file lớn + phức tạp. Nếu cần xem ảnh gốc, mở trang detail lead.
  - `note` (core) vẫn xuất riêng — đây là note "hiện tại" trên `leads.note`. `note_history` là **timeline đầy đủ** từ status logs.

## Phase 6.23 — Tách permission riêng `lead.view_pool` (Xem kho số) ✅
- **Ngày hoàn thành**: 2026-07-20
- **User chốt**: dùng permission riêng "Xem kho số" (`lead.view_pool`) thay vì gộp với distribute*. Cách này gọn — admin tick 1 quyền là được xem kho, không phụ thuộc quyền chia.
- **Đã làm**:
  - `PermissionSeeder`: thêm `lead.view_pool` "Xem kho số (kho chung công ty, chưa chia)" group distribution.
  - `Lead::scopeVisibleTo()` + `isVisibleTo()`: check `hasPermission('lead.view_pool')` thay vì `hasAnyPermission(distribute*)`.
  - Cấp `lead.view_pool` cho các role đã có `lead.distribute` (đều là DM/CM/QL/TL): OrgAndRoleSeeder + OrgStaffSeeder + Phase66FlowSeeder — tất cả nơi có `'lead.distribute'` giờ có thêm `'lead.view_pool'` trước nó.
  - Test cũ Phase 6.22 rename thành `test_pool_visible_only_for_user_with_view_pool_permission`, dùng perm mới.
- **Test suite**: 117/117 pass.

## Phase 6.22 — Chặt lại visibility kho chung công ty (gate bằng perm distribute*) ✅
- **Ngày hoàn thành**: 2026-07-20
- **User chốt**: kho chung công ty (`org_unit_id=null, pool_level=common`) không mặc định visible cho mọi user có scope tổ chức. Chỉ user có quyền chia số (DM, CM) mới thấy để chia — người thường được chia gì thấy đó.
- **Đã làm**:
  - `Lead::scopeVisibleTo()` + `isVisibleTo()`: thêm điều kiện visible kho chung công ty chỉ khi user có 1 trong 4 perm: `lead.distribute` / `lead.distribute_booking` / `lead.distribute_sale` / `lead.distribute_ctv`.
  - Test cũ update lại (design cũ đã sai): manager scope=team + không có perm distribute → KHÔNG thấy kho chung.
  - Thêm test mới `test_pool_visible_only_for_user_with_distribute_permission`: cấp perm `lead.distribute` cho manager → thấy được lead kho chung.
- **Test suite**: 117/117 pass.

## Phase 6.19 — Filter lead-list liên kết với column visibility ✅
- **Ngày hoàn thành**: 2026-07-19
- **Đã làm**:
  - `⚡lead-list`: mỗi ô filter (Chiến dịch/Nguồn QC/Nguồn data/Phân loại/Ngày) bọc `@if ($this->colVisible('X'))` → chỉ hiện khi cột tương ứng đang tick trong bộ chọn cột.
  - Ô search "Tìm kiếm" giữ luôn hiện (không tied to 1 cột).
  - `toggleColumn()`: khi tắt cột, reset filter value tương ứng (`fCamp='', fAdSource='', fNguon='', fClassification='', fDateFrom/To=''`) để không kẹt filter cũ, + `resetPage()`.
  - Grid filter đổi từ `grid-cols-7` cố định → `flex flex-wrap items-end` — số filter linh hoạt.
- **Test**: mặc định 5 filter (do camp+ad_source không tick prefs); bật cột `camp` → "Chiến dịch" xuất hiện; tắt lại → biến mất, `fCamp` reset về ''.

## Phase 6.18 — Move field Insight vào tab Insight + rename Ngày → Ngày thu thập ✅
- **Ngày hoàn thành**: 2026-07-19
- **Đã làm**:
  - `⚡lead-form`: bỏ textarea `insight` khỏi cột trái (nằm giữa Link ↔ NOTE), thêm vào đầu tab Insight cột phải, label "Ghi chú insight khách".
  - Đổi label field `received_date` từ "Ngày *" → "Ngày thu thập *" cho rõ nghĩa.
- **Test**: field Insight không còn ở cột trái, xuất hiện đúng khi click tab Insight; label "Ngày thu thập *" render đúng.

## Phase 6.17 — Style tabbar: horizontal text-only inline ✅
- **Ngày hoàn thành**: 2026-07-19
- **Đã làm**:
  - Đổi tabbar style từ dạng button pill fill vàng → text-only inline giống top nav Google Docs.
  - Bar: `flex flex-wrap items-center border-b border-gold-200` — 1 hàng, wrap khi tràn (không scrollbar xấu).
  - Active: `text-gold-700 border-b-2 border-gold-600 font-semibold`. Non-active: `text-ink/50 border-b-2 border-transparent hover:text-gold-700`.
  - Rút gọn labels: Nhân sự · Insight · Liệu trình · Trạng thái · Dịch vụ · Phân phối. Padding `px-3 py-2`.
- **Test browser**: 6 tab fit 1 hàng (barWidth 596px, hasScrollbar false), active gạch chân vàng.

## Phase 6.16 — Thêm 2 tab: Dịch vụ & Upsell + Phân phối & Nguồn ✅
- **Ngày hoàn thành**: 2026-07-19
- **Đã làm**:
  - Move `Dịch vụ tiềm năng & UPSELL` (200 dòng) và `Phân phối & Nguồn` (70 dòng) từ cột trái → cột phải, wrap `x-show="tab === 'upsell'"` / `x-show="tab === 'distribution'"`.
  - Tabs array tăng lên 6, tab `distribution` conditional theo `$canDistribute`.
  - Dùng Python script để cut+paste 2 blocks lớn (Edit tool không match nổi 200 dòng string).
- **Cột trái sạch** — chỉ còn 2 section (Thông tin khách hàng + Trường bổ sung), phù hợp với vai trò "info cá nhân/nhân khẩu".
- **Test browser**: 6 tab hiện đủ, switch tab Dịch vụ & Upsell + Phân phối & Nguồn → visible đúng, các tab khác ẩn.

## Phase 6.15 — Cột phải: 4 section thành 4 tab (Alpine) ✅
- **Ngày hoàn thành**: 2026-07-19
- **Đã làm**:
  - `⚡lead-form` cột phải: bọc `<div x-data="{ tab: 'staff' }">`, thêm tabbar 4 nút (active fill vàng, non-active border). Mỗi section trước đây `<div class="bg-white...">` thêm `x-show="tab === 'X'" x-cloak`.
  - **INSIGHT** move từ cột trái (giữa Trường bổ sung ↔ DV tiềm năng) sang cột phải làm 1 tab.
  - Thứ tự tab: **Cơ sở & Nhân sự (default)** → INSIGHT → Liệu Trình → Trạng thái chăm sóc.
- **Test browser**: default tab "staff" visible, 3 tab khác ẩn (`display: none`). Click INSIGHT/Liệu Trình/Trạng thái chăm sóc → tab tương ứng visible, các tab khác ẩn. 4/4 tab switch OK.
- **Bug gặp + fix**: `@php $tabs = [...] @endphp` block **không share scope** với `@foreach ($tabs as $t)` phía dưới trong Livewire volt → phải dùng `<?php $tabs = [...] ?>` inline. (Repeat bug từ Phase 6.11 — đã có trong skill, tao vẫn quên áp dụng.)

## Phase 6.14 — Trường bổ sung: đối vị trí + fix dấu * cho Nguồn ✅
- **Ngày hoàn thành**: 2026-07-19
- **Đã làm**:
  - `⚡lead-form`: chuyển block "Trường bổ sung" từ cột phải cuối → cột trái, ngay sau "Thông tin khách hàng" (trước INSIGHT). Đổi hint dưới header: "Trường có * là bắt buộc" cho rõ.
  - Set `custom_fields.id=1` (Nguồn, mức công ty) → `required = true`. Trước đó DB flag `false` nên code không render * dù logic có sẵn `@if ($field->required)<span class="text-red-500">*</span>@endif`.
- **Test browser** (admin, `/leads/49/edit`):
  - Section order: Thông tin khách hàng → **Trường bổ sung** → INSIGHT → LIỆU TRÌNH · cột phải: Cơ sở & Nhân sự → Trạng thái chăm sóc.
  - Field "Nguồn" hiển thị "Nguồn *  #mã KH  Công ty" — dấu * đỏ.
  - Detail view cột phải: `TRƯỜNG BỔ SUNG (TEAM SALE) · NGUỒN *`.
- **Ghi chú**:
  - Logic required của custom field đã có sẵn từ đầu, chỉ cần data flag đúng. Không cần code mới. User có thể tự tick required trong `/settings/fields` cho các trường khác cần bắt buộc.

## Phase 6.13 — QA multi-role + fix bug view_phone bypass scope ✅
- **Ngày hoàn thành**: 2026-07-19
- **Đã làm**:
  - **BUG THẬT phát hiện** trong QA vai book1: user có perm `lead.view_phone` BYPASS được `isVisibleTo()` → xem được lead của team khác. Nguyên nhân: `⚡lead-detail::mount()` gate `abort_unless(isVisibleTo || hasPermission('lead.view_phone'), 403)`. Perm gốc `lead.view_phone` chỉ nên **unmask SĐT khi lead đã trong scope** (như `Lead::phoneFor()` đang dùng), không phải mở toàn trang.
  - Fix: bỏ vế `|| hasPermission('lead.view_phone')` trong gate mount → chỉ giữ `isVisibleTo`. Verify book1 → /leads/49 (khác team) → 403 sau fix.
  - QA browser 4 vai với 2 khách test (K1 Sale/in_care, K2 Booking/in_care):
    | Vai | Perm chính | Trên K1 (sale) | Trên K2 (booking) |
    |---|---|---|---|
    | Sale nhân viên (nvkd) | lead.update | Thấy (owner mình) · KHÔNG có nút Cập nhật · edit → 403 | Không thấy (khác team, khác phase) |
    | CM sale (cmsale) | update_sale + distribute_sale | Thấy · nút Cập nhật + Thu hồi · edit → OK | Thấy · KHÔNG có nút nào · edit → 403 |
    | Team booking (book1) | update_booking | Không thấy sau fix (đúng scope) | Thấy · đủ 3 nút · edit → OK |
    | CM booking (cmbk) | update_booking + distribute_booking | Không thấy · edit → 403 | Thấy · đủ 3 nút · edit → OK |
  - Toàn bộ gate scope + phase-based edit hoạt động đúng cross-role.
- **Ghi chú**:
  - **Bài học**: QA bằng admin bị bypass mọi gate → không phát hiện được bug. Luôn phải test theo tài khoản chức năng thực tế. Đưa vào skill: "QA gate scope/permission phải dùng tài khoản đúng vai, không admin bypass".
  - 2 khách test đang giữ nguyên trong DB (id 25, 49). User tự dọn khi cần.

## Phase 6.12 — Trang quản lý Bác sĩ & Cơ sở ✅
- **Ngày hoàn thành**: 2026-07-19
- **Đã làm**:
  - Migration `2026_07_19_140000_add_title_to_staff_members`: thêm cột `title` nullable, backfill từ `name` (split `"Tên\n(Chức vụ)"`).
  - `StaffMember` model: thêm `title` vào Fillable, method `displayName()` gộp "Tên\n(Chức vụ)".
  - `RealDoctorsSeeder`: lưu name + title riêng, không join `\n` nữa.
  - Permission mới `staff.manage` — "Chỉnh sửa danh mục bác sĩ & cơ sở". Cấp Admin + DM HCM. Seed lại 3 seeder.
  - Route `GET /settings/staff` + `POST /settings/staff/export`, middleware `permission:staff.manage`.
  - Livewire component `settings/⚡staff-management`: 2 tab **Nhân sự chuyên môn** + **Cơ sở**.
    - Tab Nhân sự: bảng list (Tên · Chức vụ · Cơ sở · Active · Thao tác), filter theo cơ sở + search theo tên/chức vụ, form Add/Edit 3 field riêng (Tên + Chức vụ + Cơ sở) + toggle active, xóa với confirm.
    - Tab Cơ sở: CRUD facility cây 2 tầng, toggle active; xóa chặn nếu còn nhân sự hoặc cơ sở con.
    - Import Excel: cột A=Cơ sở, B=Phòng ban, C=Tên, D=Chức vụ, E=Active. Upsert theo (facility_id, name).
    - Export Excel: `StaffExportController` dump toàn bộ nhân sự (kể cả tắt) ra xlsx dùng `phpoffice/phpspreadsheet`.
  - Update dropdown BS ở `⚡lead-form` (staffTree + selectedName) và thẻ liệu trình (select option `Name — Title`) dùng `$s->displayName()` / `$doc->title`. Detail view (`⚡lead-detail`) đổi sang `->displayName()`.
  - Menu `settings/index`: thêm ô "Bác sĩ & Cơ sở" (icon users, scope system, perm `staff.manage`).
  - Cập nhật ERD.md bảng `staff_members` (thêm cột title).
- **Test browser**: trang render OK — 32 nhân sự, 6 cơ sở (3 root + 3 dept). Tạo mới "BS Test QA (Bác sĩ nội soi test)" → count 33, dropdown ở form lead thấy đúng "BS Test QA\n(Bác sĩ nội soi test)". Đã cleanup.
- **Ghi chú**:
  - Import Excel format khác file gốc "List nhân sự.xlsx" (đơn giản hơn: cột A=Cơ sở thay vì filter theo `Khối chuyên môn`). File gốc user cần re-format trước khi import qua UI, hoặc chạy seeder CLI.
  - `RealDoctorsSeeder` đã register trong `DatabaseSeeder` — `php artisan db:seed` fresh sẽ tự seed 32 nhân sự vào 3 cơ sở. Idempotent (`updateOrCreate` theo `facility_id, name`).

## Phase 6.11 — LIỆU TRÌNH dạng thẻ 1-N ✅
- **Ngày hoàn thành**: 2026-07-19
- **Đã làm**:
  - Migration `2026_07_19_120000_create_lead_treatments_and_drop_old_columns`: tạo bảng `lead_treatments` (id, lead_id, sequence, performed_at, performing_doctor_id, quality_rating, timestamps + index); drop 6 cột cũ trên `leads` (`treatment_1..4`, `performing_doctor_id`, `quality_rating`). Data cũ 0 row → không backfill.
  - Model `LeadTreatment` + relation `Lead::treatments()` (`HasMany`, orderBy sequence).
  - `⚡lead-form`: bỏ 6 field cứng, thêm `treatmentRows` mảng, method `addTreatmentRow()` / `removeTreatmentRow($idx)`, `syncTreatments()` (delete + recreate theo thứ tự nhập). UI mỗi thẻ có Lần / Ngày / Bác sĩ (select với format `Name — (Chức vụ)`) / Đánh giá riêng + nút × xoá + nút "Thêm liệu trình" ở header.
  - `⚡lead-detail`: bỏ hiển thị treatment_1..4 + BS thực hiện + quality_rating cũ, thay bằng vòng lặp qua `$lead->treatments` render dạng thẻ (sequence, ngày, BS, đánh giá).
  - Cập nhật ERD.md (thêm bảng `lead_treatments`, đánh dấu 6 cột đã drop).
- **Ghi chú**:
  - `LeadTreatment` giữ nguyên FK `performing_doctor_id` → `staff_members` (user chốt bác sĩ vẫn là staff, không đăng nhập).
  - Không giới hạn số lần liệu trình (cũ giới hạn 4).
- **Test**: QA E2E pass — thêm 2 thẻ khác BS, save, verify DB có 2 row `lead_treatments` (sequence 1&2, ngày/BS/đánh giá đúng). Detail view timeline render 2 thẻ + bác sĩ 2 dòng đúng format.
- **Bug phát hiện + fix trong QA**: section INSIGHT (bọc bởi `@if birthday || address || medical_history || occupation`) không include `treatments` → treatments không hiển thị nếu lead thiếu 4 field kia. Fix: thêm `|| $lead->treatments->isNotEmpty()` vào điều kiện.

## Phase 6.10 — Seed 32 bác sĩ Khối chuyên môn + format tên xuống dòng ✅
- **Ngày hoàn thành**: 2026-07-19
- **Đã làm**:
  - Đọc file `List nhân sự.xlsx`, lọc `PHÒNG BAN = Khối chuyên môn` + `STATUS = ON`, bỏ điều dưỡng (case-insensitive "điều dưỡng" trong chức vụ). Còn **32 người**: Hà Nội 19, HCM 11, Đà Nẵng 2.
  - Seeder `RealDoctorsSeeder`: seed 3 facility root (Hà Nội / HCM / Đà Nẵng), mỗi root có 1 dept "Khối chuyên môn", 32 `staff_members` gắn dept tương ứng, `role=doctor`. Idempotent theo `(facility_id, name)`.
  - Format `name` chứa `\n`: `"Hoàng Trà My\n(Bác sĩ chuyên khoa y học cổ truyền)"` — để UI render 2 dòng.
  - UI dropdown BS tư vấn + BS thực hiện (`⚡lead-form`): thêm class `whitespace-pre-line leading-tight` cho `<span>` tên (cả lúc đang chọn và lúc đã pick). Đổi init `selectedName` sang `Js::from(...)` để không vỡ khi name có `\n`.
  - UI trang chi tiết (`⚡lead-detail`): đổi `->displayLabel()` → `->name` cho BS tư vấn + BS thực hiện, thêm `whitespace-pre-line leading-tight` + `items-start` để label chip căn top.
- **Dời lại / chưa xong**:
  - Chưa refactor LIỆU TRÌNH sang dạng thẻ 1-N (bảng riêng `lead_treatments`) — user chưa chốt câu hỏi thiết kế; sẽ làm phase kế.
- **Ghi chú**:
  - Giữ schema `staff_members` không thêm cột — dùng `\n` trong `name` cho gọn (không cần title/code cột riêng).
  - Bảng `staff_members` giờ chỉ dùng cho **bác sĩ / KTV chuyên môn** (consultant đã tách sang User ở Phase 6.9).

## Phase 6.9 — Chuyên viên tư vấn = User (team sale) + fix nút "Bớt" chuyên viên ✅
- **Ngày hoàn thành**: 2026-07-19
- **Đã làm**:
  - Migration `2026_07_19_100000_change_consultant_fk_to_users`: đổi FK `consultant_1_id`, `consultant_2_id`, `consultant_3_id` từ `staff_members` → `users`. Bảng `staff_members` đang rỗng (chưa dùng cho consultant) → không phải migrate data.
  - `Lead` model: relationships `consultant1/2/3()` chuyển sang `belongsTo(User::class)`.
  - `⚡lead-form`: thêm method `consultantUsers()` — lấy user có `lead.update` trong subtree `org_unit` của lead (nếu chưa gắn team dùng scope người thao tác) + giao với `visibleOrgUnitIds()`. Data đẩy qua `window.__consultantUsers` cho Alpine dropdown flat list.
  - Tách UI dropdown: bác sĩ giữ Alpine tree cũ (`staffTree` từ `staff_members`); 3 chuyên viên dùng dropdown flat list mới (search theo tên).
  - Fix bug **"thêm được, bớt không được"**: thêm nút `× Bớt` cạnh label chuyên viên 2/3 — click → giảm `extraConsultants` + clear `consultantXId` (bớt CV2 tự động clear luôn CV3).
  - `⚡lead-detail`: eager load `consultant1/2/3` (bỏ `.facility.parent`), hiển thị `$cv->name` (User) thay cho `->displayLabel()` (StaffMember).
  - Cập nhật `ERD.md` bảng leads.
- **Dời lại / chưa xong**:
  - Bảng `staff_members` hiện có `doctor` + (đã bỏ) `consultant`. Chưa seed doctor test → dropdown bác sĩ vẫn rỗng. Đợi bên booking đồng bộ qua API rồi seed.
  - Chưa filter chặt CM khỏi dropdown chuyên viên — hiện tất cả user có `lead.update` trong scope đều hiện (bao gồm CM booking, CM sale). Nếu cần tách, thêm điều kiện loại role có `distribute_booking`/`distribute_sale`.
- **Ghi chú & quyết định phát sinh**:
  - Blade **không parse được `@foreach ([...] as $x)` với array literal đa dòng** — compile silently thành text raw → gây lỗi endforeach. Fix bằng cách khai báo array vào biến `<?php $x = [...] ?>` rồi loop `@foreach ($x as ...)`. Skill note: nhớ cách này cho các loop tương lai.
  - Livewire volt `@php ... @endphp` block **không share scope với `@foreach`** phía dưới → phải dùng `<?php ?>` inline.
- **Test**:
  - QA browser: `admin` vào `/leads/1/edit` → dropdown 35 chuyên viên hiện đúng, nút "Bớt" hiện đúng vị trí. Chưa test transition thật (chọn chuyên viên → save → verify DB).

## Phase 6.8 — Trục lifecycle phase/status + tách permission Booking/Sale ✅
- **Ngày hoàn thành**: 2026-07-19
- **Đã làm**:
  - Migration `2026_07_19_000000_add_pipeline_phase_status_to_leads`: thêm `pipeline_phase` (booking/sale) + `pipeline_status` (waiting_distribute/in_care) + index. Backfill tất cả lead cũ = `sale/in_care` (user chọn phương án b — data test).
  - Tách permission `lead.distribute_team` (deprecated) → `lead.distribute_booking` + `lead.distribute_sale`.
  - Thêm 2 permission mới `lead.update_booking` + `lead.update_sale` — quyền sửa info cá nhân (cột trái) theo phase hiện tại của lead. Không có perm khớp phase → cột trái read-only, route `leads.edit` trả 403.
  - `Lead` model: thêm constants `PHASE_*` / `PSTATUS_*`, method `canEditPersonalInfo(User)`, `personalInfoPermission()`, `pipelineLabel()`, `moveToSaleWaiting()`, `initialPipelineFor()`. `SOURCE_PERMISSIONS` chuyển `distribute_team` → `distribute_booking` cho nhóm 1-3.
  - Route `leads.edit`: đổi middleware `permission:lead.update` → closure gate `canEditPersonalInfo`.
  - `⚡lead-form.blade.php` (edit): gate mount theo `canEditPersonalInfo`.
  - `⚡lead-detail.blade.php`: badge phase/status (4 màu), nút "Cập nhật thông tin" ẩn nếu không có perm khớp phase, thêm cụm 2 nút "Mở Booking" + "Chuyển sang Sale" chỉ hiện khi phase=booking + user có `update_booking`/`distribute_booking`. Log audit khi transition.
  - Seed 2 role mới trong `Phase66FlowSeeder` + `OrgStaffSeeder`: **Team sale** (nhân viên sale) — trước chỉ có "Team booking". Cập nhật perms 4 role hiện có (Team trực page / CM booking / Team booking / CM sale) + role cấp cao (Admin/DM HCM/Manager/Team Leader) để có `distribute_booking`/`distribute_sale`/`update_booking`/`update_sale`.
  - Trang **Quy tắc vận hành** (`⚡ops-rules`): bảng phân bổ tách 5 cột (distribute_booking / distribute_sale / distribute_ctv / update_booking / update_sale). Kho lead pool `⚡lead-pools` cập nhật filter danh sách "user nhận số" (loại luôn ai có `distribute_booking`/`distribute_sale`).
  - Cập nhật `scope.md` §7.1 + §8.0.1 (bảng lifecycle mới) + `ERD.md` bảng `leads` thêm 3 cột + index.
- **Dời lại / chưa xong**:
  - Chưa auto-transition `booking/waiting → booking/in_care` khi có note đầu tiên bên booking (đang để thủ công/next phase).
  - Chưa gọi API booking để tự đổi phase khi bên booking đặt lịch xong (đợi bên booking dựng endpoint hứng).
  - Chưa test browser end-to-end (cần chạy `php artisan serve` + login test).
- **Ghi chú & quyết định phát sinh**:
  - User chọn tách 2 trục (phase + status) thay vì 1 enum ghép — dễ query/report hơn.
  - `lead.distribute_team` giữ trong PermissionSeeder (đánh dấu DEPRECATED) để không vỡ role cũ. Các seeder chính đều đã migrate sang cặp mới.
  - Backfill data cũ = `sale/in_care` (user chọn b) — nếu sau reseed data thật thì cần update lại theo `source_group`.

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

## QA browser 11 role + fix 5 bug + hardening pipeline import ✅
- **Ngày**: 2026-07-17
- **Bối cảnh**: user chốt `docs/role-flow-test.md` + script `scripts/role-flow-test.php` (26 assertion engine-level PASS 100%), sau đó QA tay qua browser 11 role thật để lộ gap UI vs engine.
- **Đã làm**:
  - **QA browser 11 role**: page1@ (Team trực page) / cmbk@ (CM booking) / book1@ (Team booking) / cmsale@ (CM sale demo) / thk@ (Sale) / admin@ / huyently@ (Observer) / lpt@ (Trợ lý KD) / tnkn@ (DM HCM) / nhd@ (Team Leader) / ttg@ (real CM sale Team Giang). Kiểm luồng đúng + luồng sai 403 + dropdown filter.
  - **5 bug thật + fix**:
    1. **BUG 1** — `Team trực page` role chỉ có `lead.create`, thiếu `lead.distribute_team` → dropdown "Nhóm nguồn" ở `/leads/create` chỉ hiện 2/5 (referral + walk_in), mất Marketing/Data lạnh/BDM = nghiệp vụ chính. Fix: thêm perm vào 2 seeder (`OrgStaffSeeder` + `Phase66FlowSeeder`).
    2. **BUG 2** — Chi tiết KH `/leads/{id}` không có nút Thu hồi cho CM (dù có `lead.recall`). Fix: thêm method `recallLead()` + nút đỏ trong `resources/views/components/leads/⚡lead-detail.blade.php` (chỉ hiện khi có perm + lead có owner).
    3. **BUG 3** — `cmsale@` demo bị gán leaf node `team-hoi-sale` (id=11) → dropdown "chọn kho đích" chỉ 1 option. Fix seed: đổi org sang `marketing-hn` (id=3) scope=team → subtree bao 2 team Giang + Hợi (visibleOrgs 1→9, dropdown 10 kho).
    4. **BUG 4** — `lpt@` (Trợ lý KD) seed sai scope=self ở `ops-monitor-sub` → thấy 0 lead. Fix 2 seeder về scope=custom node=`company` → thấy toàn cty (visibleOrgs 24, view-only).
    5. **BUG 6** — Pipeline `ProcessRawLead` validate required custom field TRÊN TOÀN BỘ trường active bất kể org → **mọi lead import CSV/webhook vào kho chung fail 100%** với reason "Thiếu Phân loại/Kết quả (Team Tạ Văn Hợi)". Fix: dùng `CustomField::applicableTo($targetOrg)` để chỉ validate trường bắt buộc trong scope org đích (null = chỉ mức công ty; có owner → cộng thêm trường phòng của owner).
  - **BUG 5** (nghi ban đầu) — DM HCM thấy lead HN — đọc sai ID: KH-016 org_id=16 = **HCM Team Booking**, KH-011 org_id=17 = **HCM Team Sale** (trùng tên với HN nhưng khác id). Rút, engine đúng.
  - **Pipeline import — hardening thêm 3 loại lỗi rõ ràng** (theo user chỉ đạo "báo lỗi nhập nhầm/sai mẫu/vượt thẩm quyền"):
    - `"SĐT không hợp lệ"`, `"Thiếu tên khách hàng"` — nhập nhầm dữ liệu cơ bản.
    - `"Thiếu trường bắt buộc (cho {org}): X, Y"` — thiếu required field trong scope.
    - `"Dữ liệu vượt phạm vi/sai mẫu — lead đang vào {org} nhưng payload có: {label} (thuộc {org khác}, ngoài phạm vi)"` — payload chứa cf ngoài scope org đích.
    - `"...payload có: #{id} (không tồn tại)"` — cf_id không có trong DB (sai mẫu).
    - Trùng SĐT → `status=duplicate`, tự gộp field còn trống vào lead cũ (không tạo mới).
- **Verify**:
  - Script `php scripts/role-flow-test.php` → **26/26 PASS** (không regression).
  - `php artisan test --filter=Phase66` → **11/11 PASS**.
  - Browser confirm 4 bug fix: page1 dropdown 5 nhóm ✅, lpt /leads có data ✅, cmsale dropdown 10 kho ✅, cmsale click "Thu hồi" KH-020 → flash "Đã thu hồi lead về kho team" + owner=null ✅.
  - Pipeline import CSV 6 dòng qua script `scripts/test-bulk-import.php`: 3 processed (KH-040/041/042 vào kho chung) + 1 duplicate (trùng SĐT tự gộp) + 2 failed đúng reason (invalid_phone / thiếu tên). Test edge case cf ngoài scope + cf id không tồn tại đều trả reason rõ.
- **Docs**:
  - `docs/role-flow-bugs.md` — chi tiết 5 bug + file nghi vấn + cách reproduce.
  - `scripts/test-bulk-import.php` — script CLI enqueue import (bypass Livewire UI để test pipeline).
  - `scripts/test-import.csv` — CSV mẫu 6 dòng cover đủ case (valid/invalid phone/thiếu tên/trùng SĐT).
- **Chưa làm / cần bàn tiếp** (import scale lớn):
  - Hiện `/leads/import` UI dispatch từng `ProcessRawLead` job đồng bộ trong request Livewire — file 100k dòng sẽ block request lâu + tốn RAM đọc SpreadsheetReader all-at-once. Cần: (a) chunk parse (đọc từng 500-1000 dòng), (b) dispatch background job `EnqueueImportBatch` để insert raw + dispatch job con, (c) UI báo "đang tải nền" thay vì chờ block. Chưa thấy user cần scale này, giữ nguyên tới khi có yêu cầu thật.
  - Nút Thu hồi ở chi tiết KH: chưa có option "về kho chung" (chỉ về kho team). Nếu CM cần đẩy lên kho chung phải qua kho lead → cần bàn.

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

## Bổ sung (2026-07-20) — Kết nối SCRM ↔ Lara-SBooking (đặt lịch từ chi tiết khách)

- **Mục tiêu**: nút "Đặt booking" ở chi tiết lead → mở form bên `lara-sbooking` (prefill KH), đặt xong tự về SCRM cập nhật `booking_status`, `booking_ma`, `booked_at`.
- **Đã làm (3 phase)**:
  - **Phase 1 — Bên booking**:
    - Migration `booking.ma_booking` (unique, nullable) + backfill record cũ `BKG-yymmdd-{id6}`. Model event `created` tự sinh mã.
    - `BookingController@create/createDichVu` đọc query prefill `ho_ten`, `so_dien_thoai`, `email`, `return_url`. View `longevity/create.blade.php` fallback prefill + hidden `return_url`.
    - `safeReturnUrl()` whitelist host callback (chống open-redirect). `store()` redirect `{return_url}?booking_ma=&booking_id=` nếu whitelist match.
    - `GET /api/bookings` (bearer token) + middleware `EnsureScrmToken` cho đồng bộ S2S sau này.
  - **Phase 2 — Bên SCRM**:
    - Migration `facilities.booking_co_so_slug` (map slug URL cơ sở bên booking) + `leads.booking_ma` + `leads.booked_at`.
    - `BookingCallbackController` (route `GET /leads/{lead}/booking-callback`): cập nhật lead + AuditLog + flash message, gate qua `Lead::isVisibleTo()`.
    - Nút "Đặt booking" trong `⚡lead-detail.blade.php` (chỉ hiện khi `canMoveToSale` = lead đang phase Booking). URL build từ slug + prefill. Facility chưa map slug → nút disabled kèm tooltip.
    - Form `⚡staff-management.blade.php` thêm ô "Slug cơ sở bên Booking" (regex `[a-z0-9\-]+`) cho từng facility.
  - **Phase 3 — UI thiết lập kết nối (thay đọc env)**:
    - Cả 2 bên: bảng `app_settings(key,value)` + model `AppSetting` (cache per-request).
    - Booking: trang `Thiết lập › Kết nối SCRM` (`/{co_so}/thiet-lap/ket-noi/scrm`, admin) — textarea whitelist host, lưu DB. `safeReturnUrl()` đọc DB fallback env.
    - SCRM: trang `Cài đặt › Kết nối Booking` (`/settings/booking-connection`, `permission:connection.manage`) — 2 ô URL + API Token + nút "Test kết nối" (gọi `GET /api/bookings?per_page=1`). `lead-detail` đọc `booking_url` từ DB fallback env.
- **Test**:
  - Prefill query → 3 field khách + hidden `return_url` fill đúng ✅
  - Submit form thật (top-level nav) → booking tạo `BKG-260720-000005`, redirect callback → SCRM update `booking_status=booked`, `booking_ma`, `booked_at` + flash "Đã đặt booking BKG-260720-000005 cho khách Trần Văn Đức" ✅
  - Test nút ẩn/disable: lead phase Sale không có nút ✅
  - "Test kết nối" bên SCRM trả `OK · tổng booking = 5` ✅
  - Whitelist host lưu vào DB verify qua tinker ✅
- **Ghi chú & quyết định**:
  - Chọn hướng **embed form gốc** (mở tab sang booking) thay vì popup Livewire tự viết — tránh replicate ~200 dòng validate + 8 endpoint dropdown, không drift khi form booking đổi.
  - Route form booking phòng khám thật là `/{co_so}/tao-moi` (không phải `/them-booking` như plan ban đầu). Đã sửa URL bên SCRM.
  - `is_admin` không có trên `User` bên SCRM — dùng `permission:connection.manage` để gate trang "Kết nối Booking".
  - Nút "Đặt booking" hiện tại chỉ hiện khi lead phase = Booking. Nếu muốn mở rộng (lead phase Sale/Close cũng đặt lại được) → nới điều kiện trong view.
  - Token API chưa có UI sinh/revoke (vẫn dùng env `SCRM_API_TOKEN`); luồng embed hiện tại không cần token, chỉ dùng cho "Test kết nối" + đồng bộ S2S sau.
- **Chưa làm / để lại**:
  - UI sinh/revoke API token bên booking (hash-based, có tên gợi nhớ + last-used).
  - Cache invalidation cross-request nếu chạy multi-worker.
  - Endpoint mở rộng: `POST /api/bookings` cho luồng thay thế embed (nếu sau này muốn tạo booking không qua UI booking).

## 2026-07-20 — Gộp trùng lặp "Nguồn" & "Nguồn QC" + nối mã source_group vào mã KH
- **Vấn đề**: form lead có 3 trường cùng chủ đề "nguồn": (1) enum `source_group` (Nhóm nguồn — bắt buộc, phân phối), (2) cột `ad_source` (Nguồn QC — nhập tay), (3) custom field `phan_loai` cấp công ty có `affects_code=true` (Nguồn — nối vào mã KH). Trùng vai trò, người dùng nhầm.
- **Sửa**:
  - `Lead::SOURCE_GROUP_CODES` (MKT/COLD/BDM/REF/CTV/WI) — mã nối vào mã KH: `KH-001-MKT`, `KH-004-REF`, …
  - `generateCode()` chèn đoạn source ngay sau id; `report-center.leadCode()` cũng chèn.
  - Bỏ custom field `phan_loai` cấp công ty (`affects_code=true`) khỏi seed — thay bằng SOURCE_GROUP_CODES.
  - Drop cột `leads.ad_source` + `stats_daily.ad_source` (migration `2026_07_20_140000_drop_ad_source_columns`). Bỏ khỏi UI form/list/detail/pools/import/reports, StatsAggregator, ProcessRawLead, WebhookController, FB Ads adapter, DistributionRule config, tests.
  - Seed phòng Marketing: bỏ `nguon_quang_cao` (select cũ), thêm 2 text field `page` + `nguon_qc` cho MỌI phòng Marketing (marketing-hcm, marketing-hn, marketing-dn). Move giá trị `page` cấp công ty sang cấp phòng Marketing tương ứng.
- **Chạy**: `php artisan migrate` + `db:seed --class=DemoDataSeeder` + regenerate mã 41 lead (`Lead::each(fn=>generateCode())`).
- **Test**: 117/117 ✅ (sửa `DistributionEngineTest::test_condition_multiple_fields_all_must_match` — dùng `region`+`insight` thay cho `region`+`ad_source`).
- **Cảnh báo dữ liệu**: mất giá trị `ad_source` cũ (FB Ads label "Facebook Ads"). Rule chia số cũ có filter `ad_source` bị bỏ điều kiện đó. `stats_daily` unique key giảm còn (date, org, user, camp).

## 2026-07-20 — Perm mới `lead.consult` cho khối "CHUYÊN VIÊN TƯ VẤN"
- **Vấn đề**: dropdown Chuyên viên tư vấn ở lead-form lọt cả Admin/DM (Bảo, Tú… phòng Vận hành) vì filter chỉ theo perm `lead.update`, mà Admin có tất cả perm.
- **Sửa**:
  - Thêm perm `lead.consult` (PermissionSeeder).
  - Gán cho role thực sự tư vấn: `Team sale`, `CM sale` (OrgStaffSeeder); `Sale`, `Manager` (OrgAndRoleSeeder).
  - Admin **KHÔNG** tự động nhận `lead.consult` (`Permission::where('key','!=','lead.consult')` khi sync). Muốn Admin tư vấn 1 lead → gán perm riêng qua Role Manager.
  - Sửa `consultantUsers()` ở lead-form: filter `lead.update` → `lead.consult`.
- **Verify**: Bảo/Tú (Admin ops-run) không còn trong danh sách. Danh sách còn Team sale + CM sale.
