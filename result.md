# Lara-SCRM — Nhật ký kết quả

> Làm xong phase nào ghi vào đây: ngày hoàn thành, việc đã làm, việc dời lại/chưa xong, ghi chú & quyết định phát sinh. Mẫu bên dưới.

## 2026-08-15 — E2E HTTP push cancel sbooking + fix enum 'huy' ✅

Script `scratchpad/qa_push_cancel.php` full HTTP flow:
1. Setup token match 2 bên (env `BOOKING_API_TOKEN=SCRM_API_TOKEN=qatoken12345`).
2. Seed 1 booking bên sbooking (`trang_thai=da_duyet`).
3. Datasource gọi `Http::withToken()->put('.../api/bookings/{id}', ['trang_thai'=>'huy', 'ly_do_huy'=>'...'])`.
4. Verify sbooking row cập nhật `trang_thai=huy` + `ly_do_tu_choi` chứa "Auto-hủy 15': ...".

### Bug tìm được qua E2E — enum thiếu 'huy'
Middleware auth PASS, controller validate PASS, nhưng UPDATE fail 500: `Data truncated for column 'trang_thai'`. Enum `booking.trang_thai` chỉ có (`cho_duyet`, `da_duyet`, `da_xong`, `tu_choi`) — thiếu `huy`.

Fix: migration `2026_08_15_100000_add_huy_to_booking_trang_thai.php` bên `lara-sbooking` (commit `7c7e811`) — ALTER TABLE thêm `'huy'`. Down migration chuyển `huy→tu_choi` trước rollback.

Sau fix: **200 OK, DB verify PASS** cả 2 field.

### Setup token — chuyển sang env
Trước dùng AppSetting encrypted → phức tạp cross-app (encrypt key khác nhau). Chuyển sang env-based:
- Datasource `.env`: `BOOKING_API_TOKEN=qatoken12345` + `BOOKING_API_URL=http://127.0.0.1:8001/api`
- Sbooking `.env`: `SCRM_API_TOKEN=qatoken12345`
- Prod: user thay bằng token thật + xoá `app_settings.scrm_api_token` để env fallback kick in.

---

## 2026-08-15 — Revert WI UPS + BA note (user clarification) ✅

User bổ sung mid-turn:
- **WI**: khách tự tới, KHÔNG chia leads/gọi điện, chỉ nhập liệu + check-in. Ngược với B4 khai báo `SOURCES_UPS_BASED=[MKT, WI]`.
- **BA**: người nhập data = leader Team Tele (informational — role Team Leader đã có `source.up.ba` trong seeder, không cần đổi).

Fix (commit `74e4535`):
- `⚡lead-form.blade.php` line 1554: bỏ WI khỏi `$upsAutoSources` (revert commit `baf0af2`).
- `Lead::SOURCES_UPS_BASED = [MKT]` (bỏ WI).
- QA expectation WI: `owner=null, pool=common`. Admin cơ sở check-in sau qua UI phase 5.

QA rerun: **21/21 vẫn PASS** với spec đúng.

---

## 2026-08-15 — QA E2E 21 case FULL PASS + WI fix + HL seed + rule 15' cancel ✅

Sau vòng QA đầu (15/21), fix lần lượt 3 gap → **21/21 PASS**.

### Vòng 2 — Fix WI (commit `baf0af2`)
`⚡lead-form.blade.php` line 1554 chỉ auto-UPS cho [MKT, MKT_BR]. Thêm WI vào — reuse `trucPageFacility()` (đã resolve OK cho Admin cơ sở qua `org_pool_map` HN/DN/HCM). WI dùng `pickGreet` (bucket A/B/C tiếp đón), khác MKT dùng `pickMkt`.

### Vòng 3 — Fix HL seeder (commit `ab5dfe5`)
Perm `source.up.hl` chỉ có ở `PermissionSeeder`, không role nào attach. Gán cho 3 role Sale (Sale / Team sale / Team sale ĐN). Trực Page/BO không cần — HL yêu cầu owner=creator là sale trực hotline.

### Vòng 4 — E2E rule 15' auto-cancel (script `scratchpad/qa_cancel15.php`)
Tạo BookingLog scheduled 20' trước, run `bookings:auto-cancel-late`:
- ✅ `BookingLog.status` → `huy_doi_lich`
- ✅ `BookingLog.sync_status` → `canceled`
- ✅ `BookingLog.sync_error` → "Auto-hủy: khách trễ quá 15 phút chưa tới."
- ✅ `Lead.booking_status` → `khach_huy`
- ✅ `LeadStatusLog` note "Auto-hủy booking BKG-QA-93 — khách trễ quá 15 phút."
- ✅ `SbookingClient::pushBookingUpdate` có branch push `trang_thai=huy` khi `sync_status=canceled`

### Kết quả cuối

| Nguồn | HN | DN | HCM |
|-------|:-:|:-:|:-:|
| MKT   | ✅ | ✅ | ✅ |
| WI    | ✅ | ✅ | ✅ |
| BDM   | ✅ | ✅ | ✅ |
| BOD   | ✅ | ✅ | ✅ |
| SA    | ✅ | ✅ | ✅ |
| MKT_BR| ✅ | ✅ | ✅ |
| HL    | ✅ | ✅ | ✅ |

**21/21 PASS** + rule 15' cancel end-to-end (local side) PASS. Push sang sbooking đã verify code path; test HTTP thực sự cần booking sống 2 bên (làm khi có QA thật).

### Sbooking side (Phase 6.25) — tất cả pieces in place ✅
- `SbookingClient::pushBookingUpdate` push `trang_thai=huy + ly_do_huy` khi `sync_status=canceled`.
- `BookingApiController::update` accept `trang_thai=in:huy` + `ly_do_huy` + map → `ly_do_tu_choi` prefix "Auto-hủy 15': ".
- `BookingController::duyet` accept `gio_thuc_hien / gio_ket_thuc / tiep_don_user_id / ghi_chu` (Q5.1).
- `AutoCancelLateBookings` command + scheduled `everyFiveMinutes()` trong `routes/console.php`.

### Bug đã fix trong session

`⚡lead-form.blade.php` line 1537 drift so với B4 refactor (commit `aa072e5`):
- BOD → CM-assigned, không còn "yêu cầu chọn sale nhận".
- HL luôn auto-set owner=creator, bất kể distribute perm.
- MKT_BR → self-owned trước khi rơi vào UPS branch.

Refactor: dùng `Lead::isSelfOwnedSource()` const-driven — không drift lần sau.

---

## 2026-08-15 — QA E2E 21 case (7 nguồn × 3 cơ sở) + fix B4 drift ✅

Script `scratchpad/qa21.php` chạy end-to-end 21 case với DB thật + Livewire dispatch, seed UPS check-in cho 3 facility trước (HN/DN/HCM, mỗi facility 3 sale: 1 bucket MKT + 2 bucket A).

### Kết quả cuối: 15/21 PASS (đúng logic mong đợi)

| Nguồn | HN | DN | HCM | Ghi chú |
|-------|----|----|-----|---------|
| MKT   | ✅ | ✅ | ✅ | UPS round-robin → sale bucket MKT (owner=28/12/39) |
| WI    | ❌ | ❌ | ❌ | **Design gap**: WI khai báo UPS-based (SOURCES_UPS_BASED=[MKT,WI]) nhưng `save()` line 1554 chỉ auto-UPS cho MKT/MKT_BR. Admin cơ sở up WI → fallback pool_common. Cần impl riêng cho Admin cơ sở resolve facility. |
| BDM   | ✅ | ✅ | ✅ | CM-assigned no auto-owner → pool_common chờ CM chia tay |
| BOD   | ✅ | ✅ | ✅ | CM chỉ định personId=sale → owner=27/11/38 pool=personal |
| SA    | ✅ | ✅ | ✅ | Sale bucket MKT self-owned (rule 2026-08-09) |
| MKT_BR| ✅ | ✅ | ✅ | Self-owned owner=creator sau fix B4 drift |
| HL    | ❌ | ❌ | ❌ | **Seeder gap**: chỉ Admin có `source.up.hl` per RolePermissionSyncSeeder. Admin không phải Sale role → `assignableUserIds` reject "Không thể chia cho nhân sự này". Cần seed HL cho Sale/Team Tele. |

### Bug thật fix trong session này (commit `aa072e5`)

`⚡lead-form.blade.php` line 1537 drift so với B4 refactor (2026-08-14) — dùng lại hardcode `[BOD, SA, BA]` cũ:

1. **BOD kẹt** ở nhánh "yêu cầu chọn sale nhận" trong khi B4 đã chuyển BOD → CM-assigned (không tự nhận).
2. **HL không auto-owner** dù self-owned per spec ("ai tạo lead = tele + tiếp đón").
3. **MKT_BR bị UPS-picked** ở line 1554 thay vì self-owned.

Fix: dùng `Lead::isSelfOwnedSource()` const-driven check. HL bypass distribute perm check (luôn auto-set owner=creator).

### Sbooking side (Phase 6.25) — verify tất cả pieces in place ✅
- `SbookingClient::pushBookingUpdate`: push `trang_thai=huy` + `ly_do_huy` khi `sync_status=canceled` ✅
- `BookingApiController::update`: accept `trang_thai=in:huy` + `ly_do_huy` + map → `ly_do_tu_choi` prefix "Auto-hủy 15': " ✅
- Modal duyệt `BookingController::duyet` accept `gio_thuc_hien / gio_ket_thuc / tiep_don_user_id / ghi_chu` (Q5.1) ✅
- `AutoCancelLateBookings` command + scheduled `everyFiveMinutes()` trong `routes/console.php` ✅

### Nợ chuyển sang batch sau
- **WI Admin cơ sở up**: impl UPS auto-assign cho Admin cơ sở (resolve facility từ assignment thay vì `trucPageFacility()`).
- **HL seeder**: quyết định seed `source.up.hl` cho role nào ngoài Admin. Nếu HL là "hotline chung", có thể để chỉ Admin/BO up.
- **QA browser end-to-end auto-cancel 15'**: cần chờ có booking thật + trigger scheduler. Script hôm nay verify config, chưa trigger flow thật.

---

## 2026-08-15 — Phase 6.25: batch sbooking Q5.1-5.3 + rule 15' auto-hủy sync 2 chiều ✅

Nối tiếp batch 2026-08-14. Bên `lara-sbooking`:

- **Q5.1/5.2/5.3** đã có ở commit `ef3080b` (B5c) hôm qua: modal duyệt edit sale/giờ/note cho admin vận hành + admin hệ thống, sale dropdown filter `co_so_id` (route `/api/sales-in-cosolow`), field giờ nhập tự do bỏ capacity validate.
- **Rule 15' sync**: trước hôm nay datasource `AutoCancelLateBookings` chỉ update local + gọi `pushBookingUpdate` — nhưng payload không có `trang_thai` → sbooking không biết đã hủy → data lệch.
  - Datasource commit `805db67`: `SbookingClient::pushBookingUpdate` thêm `trang_thai=huy + ly_do_huy` khi `log.sync_status=canceled`.
  - Sbooking commit `daf8eb2`: `BookingApiController::update` accept optional `trang_thai=huy + ly_do_huy` (whitelist), map `ly_do_huy` → `ly_do_tu_choi` prefix "Auto-hủy 15': ".

Nợ QA browser end-to-end (chờ MySQL dev online): admin sbooking duyệt → verify sync về datasource; scheduler trigger auto-cancel → verify cả 2 bên `khach_huy`/`trang_thai=huy`.

---

## 2026-08-14 — Fifteenth: batch A/B/C + Q1-5 (recall, timeline, sale-status, Hotline, booking-approval) ✅

Nhánh `fifteenth`. Batch theo chốt chat 2026-08-14 với user (khối A + B1-B5 + C1-C2 + Q1-3 + Q5.1-5.3).

### A — Chốt nghiệp vụ
- Hạn thu hồi CHỈ áp dụng nguồn MKT.
- Ownership transfer khi tạo booking cho: MKT, BA, BDM, BOD, WI.
- Trạng thái sale = 2 state (Đang chờ / Đang tiếp đón) + toggle "Không tiếp nhận" riêng.
- Booking duyệt → sync 2 chiều datasource ↔ sbooking (API token có sẵn ở /connections).

### B1 — Recall MKT + past-owner giữ quyền + ownership transfer
- Migration [lead_ownership_history](database/migrations/2026_08_14_100000_create_lead_ownership_history_table.php) + model.
- B1b: Eloquent event auto-ghi history khi `owner_id` đổi.
- B1c: `Lead::canLogCall/canLogBooking/canSee` cho past-owner (query history) → sale cũ vẫn ghi call/booking + thấy lead sau khi bị thu hồi. **KHÔNG xóa data**.
- B1d: State machine recall MKT (`recall_at` + `SOURCES_NO_RECALL`) + hook ownership transfer khi tạo booking cho 5 nguồn.

### B2 — Liên hệ gần nhất (multi-media timeline)
- Bảng `lead_contact_snapshots` + `lead_contact_snapshot_files` (upload nhiều ảnh + note/snapshot).
- Livewire `⚡contact-snapshots`: mỗi lượt tương tác = 1 card (sale + thời gian + ảnh + note). Tránh lẫn ảnh giữa nhiều sale khi cùng chăm 1 lead sau thu hồi.
- Quyền ghi = `canLogCall` (past-owner OK).

### B3 — 2 trạng thái sale + toggle "Không tiếp nhận"
- Auto: `is_busy` → "Đang tiếp đón" | else "Đang chờ" (thông tin, KHÔNG chặn chia — bỏ filter `is_busy` khỏi `UpsDispatcher`).
- Manual: `dung_nhan_lead` → dòng đỏ "· Không nhận lead" bên dưới name (chỉ toggle này mới chặn round-robin).
- Route `POST /me/receive-toggle` + `MeStatusController`.
- Avatar dropdown: badge + nút "Không tiếp nhận / Tiếp tục nhận".

### B4 — Nguồn Hotline (HL) + phân loại UPS-based vs Self-owned
- `Lead::SOURCE_HL='hl'` label "Hotline", code "HL", perm `source.up.hl`.
- `SOURCES_UPS_BASED = [MKT, WI]`, `SOURCES_CM_ASSIGNED = [BDM, BOD]`, `SOURCES_SELF_OWNED = [MKT_BR, SA, BA, HL]`.
- Refactor `c57a6ca`: tách BDM/BOD sang CM-assigned (CM chia tay, không qua UPS) + hint text form + fallback pool_level.
- Hotline: ai tạo lead = tele + tiếp đón (self-owned).

### B5 — Booking approval sync 2 chiều + auto-cancel 15' (DATASOURCE SIDE)
- `BookingEventController::trang_thai` callback mở rộng: nhận `scheduled_at / scheduled_end_at / note / cv1_user_id` khi admin sbooking đổi lúc duyệt.
- Auto set `status=da_xac_nhan` khi `trang_thai=da_duyet`.
- Command `bookings:auto-cancel-late` (every 5'): booking `scheduled_at + 15'` chưa được tick "Đã tới" → hủy + `lead.booking_status=khach_huy` + push cancel callback sang sbooking + `LeadStatusLog`.

### C1-C2, Q1-Q3 — đồng ý theo phương án đã đề xuất trước đó, không đổi code.

### Fix bonus — UPS bucket MKT
- `71135dd`: chốt UPS đủ để chia MKT (bỏ điều kiện tick +M riêng). Mọi sale check-in hôm nay ở cơ sở (bucket ≠ OFF, `dung_nhan_lead=false`) đều là ứng viên round-robin MKT.

### QA
- Test suite: 38/39 pass (`Phase66FlowsTest`, `LeadSourceBucketGateTest`, `UpsFlowTest`, `CustomerFlow621Test`). 1 fail `DistributionEngineTest::full_flow_common_to_team` — pre-existing, không liên quan.
- Fix drift: `Phase66FlowsTest::test_admin_thay_du_7_nhom_nguon` → `test_admin_thay_du_8_nhom_nguon` (thêm HL).
- Click-through browser: MySQL dev offline — hoãn, cover qua feature test.

### Nợ chuyển sang SBOOKING (repo `lara-sbooking`) — batch tiếp theo
Theo Q5.1-5.3 chốt:
1. **Q5.1** Modal duyệt: mở edit `sale tiếp đón / giờ bắt đầu / giờ kết thúc / note` cho role `admin vận hành` + `admin hệ thống` (đang bị block).
2. **Q5.2** Dropdown "Sale tiếp đón" override: lọc theo `co_so_id` của booking.
3. **Q5.3** Field giờ start/end: nhập tự do (không validate capacity phòng). Để sau thống kê ca trễ/quá giờ.
4. **Rule 15'**: bên sbooking cũng phải auto-hủy song song hoặc tin cậy callback từ datasource (đã push cancel).
5. Payload duyệt phải gửi full `scheduled_at + scheduled_end_at + note + cv1_user_id` để callback datasource sync đủ.

---

## 2026-08-11 — Twelfth: bỏ required Phân loại/Kết quả, ẩn Ghi nhận tình trạng, move Link → custom field, fix Sale tiếp đón, booking cho_duyet ✅

Nhánh `twelfth`.

### T1 — Bỏ required "Phân loại" + "Kết quả"
- [TeamHoiCustomFieldSeeder.php](database/seeders/TeamHoiCustomFieldSeeder.php): `phan_loai` + `ket_qua` `required=true` → `false`.
- [⚡lead-form.blade.php:1426](resources/views/components/leads/⚡lead-form.blade.php:1426): validation `classification` từ `required` → `nullable`.

### T2 — Ẩn section "Ghi nhận tình trạng" (phase 2)
- [⚡lead-form.blade.php](resources/views/components/leads/⚡lead-form.blade.php): xoá block chứa 3 field (Phân loại core `classification`, Ghi nhận tình trạng 1 `status_1`, Ghi nhận tình trạng 2 `status_2`). Field vẫn còn trong DB (default 'new' / nullable) để không vỡ save/query. **Chú ý**: "Phân loại" ẩn ở đây là core field `classification` — khác custom field `phan_loai` (Trường bổ sung) vẫn giữ nguyên.

### T3 — Move field "Link" từ phase 2 → phase 1 (Trường bổ sung, custom field)
- [⚡lead-form.blade.php](resources/views/components/leads/⚡lead-form.blade.php): xoá input Link ra khỏi section INSIGHT phase 2.
- [TeamHoiCustomFieldSeeder.php](database/seeders/TeamHoiCustomFieldSeeder.php): thêm custom field cấp công ty `link` (type=text, import_code=Link, position=3, không required).
- Cột core `leads.link` giữ nguyên trong DB (backward compat), nhưng không còn UI phase 2 và không import qua target `link` nữa.

### T4 — Sửa mẫu import + docs
- [⚡lead-import.blade.php](resources/views/components/leads/⚡lead-import.blade.php): bỏ target `link` + dòng "Link" trong bảng docs (Link giờ đến qua custom field cột "Trường bổ sung").
- [guide.blade.php](resources/views/guide.blade.php): cập nhật hướng dẫn — Phân loại + Kết quả không còn bắt buộc; rule thu hồi 1 ngày còn `PAGE + Camp`, rule 3 ngày chỉ khuyến khích.

### T5 — Fix "Sale tiếp đón" không hiện tên trong list lead
- [Lead.php:517](app/Models/Lead.php:517): `handlerTrio()` gán `$sale = pipeline_phase===sale ? owner : consultant1`. Trước đó chỉ set khi pipeline=sale → cột "Sale tiếp đón" ở [⚡lead-list.blade.php](resources/views/components/leads/⚡lead-list.blade.php) toàn "—" cho lead đang phase booking dù đã có CV1.

### Bonus — Booking mới tạo phải là "Chờ duyệt" (bên sbooking)
- [BookingApiController.php](app/Http/Controllers/Api/BookingApiController.php) (sbooking): bỏ nhánh auto-duyệt `phong_kham`. Booking do CRM push luôn `trang_thai='cho_duyet'`, `da_duyet=false`. Admin bên booking phải chủ động duyệt.

### QA
- `migrate:fresh --seed` cả 2 bên OK.
- Custom fields cấp công ty: `phan_loai (req=no)`, `ket_qua (req=no)`, `link (req=no)`, `page`, `camp` — verified tinker.

---

## 2026-08-11 — Eleventh (T6b): Kho re-call — import xlsx + chia hàng loạt UPS MKT ✅

Trực Page up danh sách khách cũ (họ tên + sdt), match phone với DB → lưu **id lead + ngày** vào `recall_entries` (không tạo lead mới, không copy dữ liệu). CM/Team Lead/Trực Page bấm "Chia hàng loạt" → pick sale round-robin **UPS bucket MKT hôm nay** (reuse `UpsDispatcher::pickMkt`) → update `Lead.owner_id`, `phase=CALL(2)`, `pipeline_phase=booking`, `source_group=mkt_br` (khách quay lại), ghi log.

### Files
- Migration [2026_08_11_100000_create_recall_entries_table.php](database/migrations/2026_08_11_100000_create_recall_entries_table.php) — bảng `recall_entries` (batch_date, lead_id, imported_by, assigned_to_user_id, assigned_by, assigned_at, facility_pool_unit_id, imported_name, imported_phone). Unique `(batch_date, lead_id)` để 1 lead / 1 ngày.
- [RecallEntry.php](app/Models/RecallEntry.php) — model + relations `lead`/`importer`/`assignee`/`facility`.
- [⚡recall-pool.blade.php](resources/views/components/recall/⚡recall-pool.blade.php) — Livewire component: upload xlsx (parse SpreadsheetReader), match `Lead::normalizePhone`, insert entries. Bảng liệt kê entries theo ngày batch, checkbox chọn hàng loạt, nút "Chia hàng loạt → UPS MKT". Skip list hiển thị dòng bỏ qua (số không hợp lệ / không match DB).
- [recall/pool.blade.php](resources/views/recall/pool.blade.php) — wrapper view.
- Route `GET /recall` (name `recall.pool`, middleware `permission:recall.view`) trong [routes/web.php](routes/web.php).
- Nav item "Kho re-call" thêm vào menu "Khách hàng" (gate `recall.view`) trong [layouts/app.blade.php](resources/views/layouts/app.blade.php).

### Permissions (mới)
- `recall.import` (Trực Page).
- `recall.view` (Trực Page, Admin cơ sở, CM sale, CM Tele, Team Leader).
- `recall.assign` (như view).

Cập nhật [PermissionSeeder.php](database/seeders/PermissionSeeder.php) + [RolePermissionSyncSeeder.php](database/seeders/RolePermissionSyncSeeder.php).

### Không đụng
- Không tạo lead mới trong `leads`, không copy tên/sdt từ file (chỉ lưu snapshot ở `recall_entries.imported_name/phone` để trace).
- Không thêm bucket UPS mới — reuse bucket MKT hiện có (1 sale vừa là tele vừa tiếp đón tùy UPS bucket).
- `AdminScope` được reuse để resolve facility cho super admin chọn branch.

### QA cần chạy
- Login `hn.page01@longevity.com.vn` → menu "Khách hàng" → "Kho re-call". Upload xlsx 2 cột (tên + sdt) — số đã tồn tại phải match, số chưa có phải hiện trong bảng "Bỏ qua".
- Login CM/admin → cùng menu → bấm "Chia hàng loạt" — verify Lead.owner_id đã cập nhật, `entries.assigned_to_user_id` không null.

---

## 2026-08-11 — Eleventh: AdminScope + Trực Page bỏ mkt_br + fix dropdown Sale trống ✅

Nhánh `eleventh`.

### Bỏ `source.up.mkt_br` khỏi Trực Page
- [RolePermissionSyncSeeder.php:139](database/seeders/RolePermissionSyncSeeder.php:139) — Trực Page giờ chỉ up `source.up.mkt`. MKT_BR (khách quay lại do MKT tìm ra) để Sale điền tay qua form Nhập lead (Sale đã có `source.up.mkt_br`).
- Admin cơ sở đã có sẵn `source.up.wi` — nhập tay Walk-in qua form (không auto-UPS).

### AdminScope — super admin chọn "Cơ sở đang xem" tạm
- **[App\Support\AdminScope](app/Support/AdminScope.php)** — helper mới:
  - `isSuperAdmin()` → is_admin hoặc email admin@longevity.
  - `currentBranchId()` / `currentBranchName()` → đọc từ session.
  - `orgUnitIds()` → return `null` (worldwide) khi admin không chọn scope, hoặc array subtree khi đã chọn branch. User thường: return `memberOrgUnitIds()`.
  - `branchOptions()` → OrgUnit `depth=1` (cơ sở/chi nhánh trực thuộc công ty).
- Route `POST /admin-scope` set session `admin_scope_org_unit_id` (validate depth=1 + super admin gate).
- Topmenu [layouts/app.blade.php](resources/views/layouts/app.blade.php) — dropdown "Cơ sở: — Toàn công ty — / HN / HCM / DN / Vận hành" chỉ hiện cho super admin; chọn xong POST → back.
- Widget "Kho số" [⚡dashboard-overview.blade.php](resources/views/components/reports/⚡dashboard-overview.blade.php) — `poolSaleUsers` / `poolLeads` / `poolLeadsCount` / `assignFromPool` scope theo `AdminScope::orgUnitIds()`. Super admin không chọn scope → dropdown "chọn Sale" giờ hiện toàn 31 sale; chọn HN → còn 13 sale HN.

### Bug fix bối cảnh
Trước đó admin@longevity vào dashboard bấm "Chia" ở widget Kho số → dropdown "chọn Sale" trống rỗng vì `poolSaleUsers()` filter `whereHas('assignments','org_unit_id', memberOrgUnitIds())` mà admin không có assignment cấp branch/facility → subtreeIds rỗng → return `collect()`.

### Không đụng
- `Lead::isVisibleTo` core / permission model.
- Các màn khác (`/leads`, `/distribution/pools`) vẫn theo `memberOrgUnitIds()` gốc — có thể mở rộng scope sau nếu cần.

---

## 2026-08-10 — T5 (scrm) Cột `dung_nhan_lead` + Admin cơ sở edit UPS + nút quay lại phase 3 ✅

Nhánh `final-01`. Đi kèm patch bên [lara-sbooking](../lara-sbooking/result.md) cùng ngày.

- **[AdminCoSoSeeder.php](database/seeders/AdminCoSoSeeder.php)** — thêm 4 perms UPS (`ups.view`, `ups.checkin`, `ups.override`, `ups.confirm_daily`) vào role `Admin cơ sở`. `admin.hn/hcm/dn` giờ đồng bộ quyền UPS với tài khoản duyệt bên booking.
- **Migration [2026_08_10_120000_add_dung_nhan_lead_to_daily_attendance.php](database/migrations/2026_08_10_120000_add_dung_nhan_lead_to_daily_attendance.php)** — cột `daily_attendance.dung_nhan_lead` bool + `dung_nhan_lead_since` timestamp + index.
- **[UpsDispatcher.php](app/Services/Ups/UpsDispatcher.php)** — `pickFromBucket()` luôn `->where('dung_nhan_lead', false)` (kể cả wrap-around `includeBusy=true`). Khác với `is_busy`: sale đang tiếp đón vẫn được chia lại khi full, nhưng sale "Dừng nhận lead" bị loại tuyệt đối. Thêm methods `markPause()` + `markResume()`.
- **[UpsAttendanceController.php](app/Http/Controllers/Api/UpsAttendanceController.php)** — 2 endpoint mới `POST /api/ups/pause` + `/api/ups/resume`, refactor `resolveAttendance()` chung cho busy/pause. Error message rõ hơn khi sale chưa check-in UPS.
- **[⚡lead-form.blade.php](resources/views/components/leads/⚡lead-form.blade.php)** — 3 chỗ đổi:
  - Popup "⚡ Check UPS List" hiển thị label `⏸️ Dừng nhận lead` (slate) tách khỏi `🔴 Đang tiếp đón` (amber) + `🟢 Sẵn sàng` (emerald). Áp cho cả 2 popup (Trực Page + Sale-book).
  - `pickMktRoundRobin()` + `predictSaleGreetSequence()` skip `dung_nhan_lead=true`.
  - Phase 4 (Check-in) panel: thêm nút `↩ Tạo booking khác cho khách này` (gate `canRestartBooking`), bấm gọi `markReturning(3)` để sale quay lại phase 3 tạo booking tiếp cho cùng lead.
- **[DailyAttendance.php](app/Models/DailyAttendance.php)** — fillable + casts cho 2 cột mới.

### QA
- Migration chạy sạch: `migrate:fresh --seed` OK, seeder không lỗi.
- Route đăng ký: `POST api/ups/pause`, `POST api/ups/resume` xuất hiện trong `route:list`.
- Chưa QA tay browser — cần login `admin.hn` xem popup Check UPS + login 1 sale test flow "Đang tiếp đón" (đã check-in UPS trước) → popup phải hiện đỏ.

### Ghi chú
- Bug "Sale bấm Đang tiếp đón, popup vẫn Sẵn sàng": root cause đoán là sale chưa check-in UPS scrm hôm đó → `daily_attendance` không có row → `markBusy` return false. Đã thêm log rõ lỗi bên booking (`CrmPushService::pushTiepDon` giờ dump `reason` từ scrm response). Sale phải check-in UPS trước khi bấm — behavior đúng.
- Chưa gắn broadcast event cho `UpsBusyChanged` khi paused/resume (topic khác). Có thể thêm sau nếu cần realtime Reverb.

---

## 2026-08-06 — T16 Widget "Kho số" trên dashboard scrm ✅

Thêm khối "Kho số — chờ chia" vào [⚡dashboard-overview.blade.php](resources/views/components/reports/⚡dashboard-overview.blade.php) để CM chia số tay nhanh, không phải mở `/distribution/pools`.

### Gate & scope
- **`lead.view_pool`** (đã có): thấy khối. Query lead `owner_id IS NULL` + `pool_level IN (common, team)`:
  - Kho công ty (`pool_level=common`) → mọi user có view_pool thấy.
  - Kho team (`pool_level=team`) → chỉ user có org_unit thuộc `memberOrgUnitIds()` thấy (auto scope theo cấp user — Admin/DM thấy company, CM cơ sở thấy branch, CM team thấy team).
- **`lead.pull_pool`** (đã có, đổi label): thấy thêm cột "Chia thẳng" — dropdown Sale trong scope + button "Chia".

### Perm label update
[PermissionSeeder.php:38](database/seeders/PermissionSeeder.php:38): `lead.pull_pool` label đổi từ "Kéo lead từ kho (legacy)" → **"Phân bổ từ kho số — chia thẳng lead trong kho cho 1 Sale/Tele (dashboard widget Kho số)"**. Reseed.

### UI table (10 row mới nhất)
Columns: Mã KH · Họ tên · Ngày sinh · SĐT · Phase · Tele care · Sale care · Người tạo · Ngày tạo · [Chia thẳng] (nếu có perm).

Row click Mã KH → `route('leads.edit', ...)`. Dropdown filter Sale theo pattern `whereHas('assignments.role.name', 'like', '%ale%')` trong `memberOrgUnitIds()` (reuse pattern UPS board).

### Action `assignFromPool($leadId)`
- Check `canPullPool()` + user chọn nhân sự → validate target có assignment trong scope người chia.
- Set `owner_id`, `assigned_at=now()`, `pool_level=personal`, `pipeline_status=in_care`, `phase = max(current, CF_PHASE_CALL)`.
- Log 2 record LeadStatusLog: `owner_id` change + note "Kho số: chia thẳng cho X (làm Tele care)".
- Session flash `pool_ok` / `pool_error` hiển thị inline banner trên khối.

### Verify
- `php artisan view:clear` + `php -l`: no syntax error.
- Tinker render dashboard 4 role:
  - Admin: 44991 bytes (đủ widget + cột chia).
  - CM sale: 32139 bytes.
  - Sale: 32130 bytes (không có pull_pool → widget hiện readonly).
  - Trực Page: 29438 bytes (không có view_pool → widget ẨN — đúng ý).
- `wire:poll.15s` giữ nguyên → widget tự cập nhật mỗi 15s cùng dashboard.

### Chưa làm
- **Feature test action assignFromPool** — chưa viết. QA tay: login CM sale, mở /dashboard, chọn 1 lead trong kho, chia thẳng cho 1 Sale HCM, F5 kiểm tra `owner_id` + `phase` update.
- **Bulk assign** (tick nhiều lead + chia hàng loạt) — chưa làm. Nếu cần task nhỏ, làm sau.
- **Filter theo cấp kho** (tab Cơ sở/Chi nhánh/Công ty) — hiện auto theo scope user. Nếu Admin muốn filter drilldown, add sau.

---

## 2026-08-05 — T15 fix 2 bug schema drift sbooking (Bug #1 + #2) ✅

Fix 2 bug tìm được ở T14 audit.

### Bug #1 — Rollback code `bac_si_user_id` → `bac_si_id`
Sau khi grep FK: `booking.bac_si_id` FK trỏ `bac_si.id` (bảng danh mục, KHÔNG phải users). Code `bac_si_user_id` là drift merge sai — schema mới đúng ngữ nghĩa (khác `ktv_user_id`/`sale_id` trỏ `users.id` vì họ login).

**Sed replace 8 file** (không đụng migration backfill `2026_07_02_000005` vì nó check `Schema::hasColumn('booking', 'bac_si_user_id')` — cột không tồn tại nên guard skip):
- [Booking.php:20](../lara-sbooking/app/Models/Booking.php:20) + xoá comment merge sai
- [BookingFields.php](../lara-sbooking/app/Support/BookingFields.php), [PageController.php](../lara-sbooking/app/Http/Controllers/PageController.php), [SettingsController.php](../lara-sbooking/app/Http/Controllers/SettingsController.php)
- [BookingController.php:761,963](../lara-sbooking/app/Http/Controllers/BookingController.php:761): đổi thêm `Rule::exists('users', 'id')` → `Rule::exists('bac_si', 'id')` (FK trỏ đúng bảng)
- [AuthorizesByPhanQuyen.php](../lara-sbooking/app/Http/Controllers/Concerns/AuthorizesByPhanQuyen.php), [bookings.blade.php](../lara-sbooking/resources/views/longevity/bookings.blade.php), [create.blade.php](../lara-sbooking/resources/views/longevity/create.blade.php)

### Bug #2 — Thêm relation `caKhams` HasMany vào `BacSi`
[BacSi.php](../lara-sbooking/app/Models/BacSi.php): thêm `caKhams(): HasMany` với FK `bac_si_id`. Confirm `ca_kham.bac_si_id` FK trỏ `bac_si.id` — schema đã đúng, chỉ thiếu relation.

BacSi và BacSiTuVan là 2 model song song cho ngữ nghĩa khác nhau (BacSi = danh mục fixed, BacSiTuVan = ca thay đổi). Giữ nguyên.

### Verify
- Rerun `scratchpad/audit_sbooking.php` (dispatch 40+ URL × 4 role): **0 exception** (trước fix có 25 URL 500).
- Log `storage/logs/laravel.log` sạch.
- Grep `bac_si_user_id` trong app/resources: 0 occurrence.

### Chưa làm
- **Feature test tự động** cho các URL đã fix — dựa vào audit script + manual QA browser.
- **KTV role không login web** → không có audit coverage. Nếu sau này KTV có UI login riêng → chạy lại audit script sau khi thêm role.
- **Bug UI-only** (JS console error, layout vỡ, button không click được) — audit script chỉ catch server-side 500. Cần user QA browser thật.

---

## 2026-08-05 — T14 Task A: audit dead links + undefined key all roles (sbooking) 📋

### Cách audit
- Script `scratchpad/audit_sbooking.php`: dispatch qua Laravel HTTP kernel 40+ GET URL × 4 role (Admin hệ thống, Lễ tân, Sale/TVV, Bác sĩ). KTV không login web → bỏ.
- Report code HTTP 500/404/405 + parse exception thật từ `storage/logs/laravel.log`.

### Bug thật tìm được — 2 nhóm chính

**Bug #1 — Column drift `bac_si_user_id` (code) vs `bac_si_id` (DB)** — 🔴 CHẶN NHIỀU URL

DB `booking` table có column `bac_si_id`. Code query `bac_si_user_id` ở **25+ chỗ**:
- Controllers: [PageController.php:41,65,83,92,359,593](../lara-sbooking/app/Http/Controllers/PageController.php:41), [BookingController.php:761,963](../lara-sbooking/app/Http/Controllers/BookingController.php:761), [SettingsController.php:292,307](../lara-sbooking/app/Http/Controllers/SettingsController.php:292)
- Concerns: [AuthorizesByPhanQuyen.php:139,149,169](../lara-sbooking/app/Http/Controllers/Concerns/AuthorizesByPhanQuyen.php:139)
- Model: [Booking.php:20,83](../lara-sbooking/app/Models/Booking.php:20)
- Views: [bookings.blade.php:208](../lara-sbooking/resources/views/longevity/bookings.blade.php:208), [create.blade.php:376,819](../lara-sbooking/resources/views/longevity/create.blade.php:376), [BookingFields.php:42,84](../lara-sbooking/app/Support/BookingFields.php:42)

Comment tại [Booking.php:18](../lara-sbooking/app/Models/Booking.php:18): "2026-08-05 merge: remote đổi bac_si_id → bac_si_user_id (create_bac_si_and_ktv_tables)." → migration đổi tên **không có thật** (đã grep xem tất cả migration, không có ai rename column).

URL 500 (mọi role):
- `/59ntn/bac-si` (doctors page)
- `/59ntn/dat-kham` (tạo lịch tư vấn)
- `/59ntn/lich-tu-van` (manage lịch)
- `/59ntn/danh-sach` (khi filter scope theo user)
- `/59ntn/tim-kiem?q=a` (search)
- `/59ntn/lich-hen/timeline` (khi BS đăng nhập → scope user)
- `/59ntn/dat-kham/ca-kham?bac_si_id=1&ngay=...` (chọn ca khám)

**Bug #2 — Relation `caKhams` missing on `BacSi` model** — 🔴

- [BacSi.php](../lara-sbooking/app/Models/BacSi.php) có `belongsTo(coSo)` + attribute `ten_day_du` — **không có** `caKhams`.
- [BacSiTuVan.php:22](../lara-sbooking/app/Models/BacSiTuVan.php:22) **có** `caKhams()` HasMany.
- [LichHenController.php:63,89,374](../lara-sbooking/app/Http/Controllers/LichHenController.php:63) query `BacSi::with('caKhams')` → crash "Call to undefined relationship".
- Đây là 2 model song song cho "bác sĩ", drift kiến trúc — chưa rõ ý định gộp hay tách.

### Fix scope — cần user quyết trước khi làm

**Bug #1**, chọn 1 trong 2:
| Hướng | Ưu | Nhược |
|---|---|---|
| **(a rec)** Migration `RENAME COLUMN bac_si_id TO bac_si_user_id` + backfill. Giữ code hiện tại. | Code đã sẵn sàng, chỉ 1 migration | Đụng data prod, phải test kỹ; foreign key có ràng buộc |
| (b) Grep-replace mọi `bac_si_user_id` → `bac_si_id`. Rollback code về schema. | Không đụng DB | 25+ ref, cao rủi ro miss, quên form field trong Blade |

**Bug #2**, chọn 1 trong 2:
| Hướng | Ưu | Nhược |
|---|---|---|
| **(a rec)** Thêm `caKhams` HasMany vào `BacSi` (nếu bảng `ca_kham` có FK `bac_si_id`). | Ít file đụng, đúng ngữ nghĩa "bs tư vấn cũng cần ca khám" | Cần check FK column tên gì (bac_si_id hay bac_si_tu_van_id) |
| (b) Đổi `LichHenController` query từ `BacSi` → `BacSiTuVan`. | Không đụng model | LichHen filter theo `nhan_tu_van=true` (thuộc BacSi) → phải re-check logic |

### Các bug NHỎ / no-op
- Không có 404 hardcoded link giả (tao test sai section names ban đầu, đã fix trong script).
- Không có undefined array key nào khác ngoài `dung_gio` đã fix.
- Route `/thong-bao` OK mọi role.
- Route settings tabs (dich-vu, menu, phong, quyen, bac-si, ktv, bao-cao, nguoi-dung, vai-tro, co-so, phong-ban) tất cả OK.

### Chưa làm
- **Fix Bug #1 + Bug #2**: chờ user chốt hướng (a/b) cho mỗi bug.
- **Duyệt tay 4 role qua browser thật**: script chỉ catch server-side 500. UI-only bug (button không hoạt động, JS console error, layout vỡ) chưa cover — user QA thấy bug nào báo tao fix.
- **KTV role**: không login web nên bỏ khỏi audit. Nếu sau này có role KTV login → bổ sung.

---

## 2026-08-05 — T13 sbooking: fix bug `dung_gio` + refactor filter bar UI (Task B) ✅

### Bug bao-cao
- [bao-cao.blade.php:145-150](../lara-sbooking/resources/views/longevity/settings/bao-cao.blade.php:145): block "Khách:" trùng lặp dùng key `$c['booking']['dung_gio']`/`tre`/`huy` **không tồn tại** — controller [SettingsController.php:321](../lara-sbooking/app/Http/Controllers/SettingsController.php:321) trả về `kh_dung_gio`/`kh_muon`/`kh_huy` đã render ở block trên (line 141-143). Xoá block trùng → hết crash `/thiet-lap/bao-cao`.

### Refactor filter bar (Task B - UI vỡ tan nát)
- **File mới**: [components/longevity/filter-bar.blade.php](../lara-sbooking/resources/views/components/longevity/filter-bar.blade.php) + [filter-field.blade.php](../lara-sbooking/resources/views/components/longevity/filter-field.blade.php) — Blade anonymous components chuẩn Material 3.
  - Form GET wrapper, grid responsive 1/2/4 cột (`cols` prop cho 2..6).
  - 3 slot: default (fields), `toolbar` (preset chip Ngày/Tuần/Tháng), `actions` (button row cuối form).
  - Field height cố định `h-10` (40px), border-outline-variant, bg-surface — đồng nhất mọi input.
- **Refactor**:
  - [bookings.blade.php:253-367 cũ](../lara-sbooking/resources/views/longevity/bookings.blade.php) (dùng cho `/danh-sach` + `/duyet-lich`): 115 dòng filter bar rối → 65 dòng gọn. Preset chip tách hàng riêng (không nhét ngang label). Action row (Lọc/Đặt lại/Xuất Excel/Chọn file) căn phân tách rõ ràng.
  - [settings/bao-cao.blade.php:17-72 cũ](../lara-sbooking/resources/views/longevity/settings/bao-cao.blade.php): 56 dòng → 55 dòng, đồng bộ style với bookings.
- **Move permission compute** ($canExportBooking, $canDuyet, $canCheckIn, $canEditBookingRow) ra @php block đầu file (bookings.blade.php line ~193) — trước đây bị nhét bên trong `<form>` filter, kéo theo $canEditBookingRow chỉ scoped trong form.

### Verify
- Tinker render 3 view: `bookings` 40748 bytes, `duyet-lich` 39197 bytes, `bao-cao` 31108 bytes → OK.
- `php -l` blade 4 file mới/sửa: no syntax error.
- Bug đã bắt: HTML comment `<!-- ... <x-longevity.filter-bar> ... -->` bị Blade parse như directive → gây 28 endif missing. Fix bằng đổi sang Blade comment `{{-- --}}`. Ghi note: mọi comment quanh `<x-*` tag phải dùng Blade comment.

### Chưa làm
- **Task A (audit dead link + undefined key all roles)**: dời sang turn kế. Đề xuất approach:
  1. Grep tự động `$var['key']` trong mọi blade sbooking, extract unique keys.
  2. Test render mọi controller method qua tinker với 5 role (admin, admin cơ sở, sale, bác sĩ, KTV) → bắt undefined key/null-method crash.
  3. Duyệt tay 5 role qua browser → bug bash link 404, wording xấu, feature dở.
  4. Ra bảng bug → user duyệt trước khi fix.
- **Áp dụng filter-bar cho các trang khác** (`/thiet-lap/nguoi-dung`, `/thiet-lap/lieu-phap`, ...): task nhỏ, làm cùng lúc với Task A khi duyệt qua.

---

## 2026-08-05 — T12 gom 6 fix: Trực Page + chia thẳng + preview Auto + bỏ check-in form + fix callback token ✅

Gộp 6 task user yêu cầu (buổi chiều 05/08) thành 1 patch.

### 1. Khóa lead-form cho Trực Page
- [HasAccessControl.php](app/Models/Concerns/HasAccessControl.php): thêm `hasRole(string $roleName)` helper — reuse `effectiveAssignments` cache, check theo tên role.
- [⚡lead-form.blade.php:1922](resources/views/components/leads/⚡lead-form.blade.php:1922): thêm biến `$isTrucPage = auth()->user()->hasRole('Trực Page')`.
- [⚡lead-form.blade.php:2545](resources/views/components/leads/⚡lead-form.blade.php:2545): fieldset lồng phase 2 order-1/2/3 + phase 3/4/5 → `:disabled="!!cfLocked[phase] || @js($isTrucPage)"`. Kèm banner amber "Tài khoản Trực Page — chỉ điền Trường bổ sung".
- Custom fields phase 2 (block line 2481) nằm NGOÀI fieldset → Trực Page vẫn điền được. Phase 1 (info khách) cũng ngoài fieldset → Trực Page vẫn nhập lead mới.

### 2 + 6. Preview sale khi bấm "Tự động" + notice xoay vòng + Check UPS button cho Trực Page
- [⚡lead-form.blade.php:1103](resources/views/components/leads/⚡lead-form.blade.php:1103): `previewMktNextSale()` refactor return array `['sale' => User, 'rotated' => bool]`. Nếu `is_busy=false` cạn → dùng wrap-around (tất cả sale) và mark `rotated=true`.
- [⚡lead-form.blade.php:3111](resources/views/components/leads/⚡lead-form.blade.php:3111): mở `@if ($canDistribute)` thành `@if ($canDistribute || (MKT && !$lead?->exists))` để Trực Page cũng thấy Check UPS button + banner preview. Cascade + person section vẫn wrap `@if ($canDistribute)` bên trong (Trực Page ẩn).
- Banner "Chia tự động — dự kiến" đổi màu: xanh khi còn sale rảnh, **vàng + dòng "⚠ List sale đón tiếp đang full, xoay vòng trở lại — {tên sale đầu tiên}"** khi rotated.
- Live update qua `wire:model.live="mktMode"` — user bấm radio Auto là banner + Check UPS hiện ngay, không cần Lưu.

### 3. Bỏ 4 field check-in ở scrm — data đồng bộ từ sbooking
- Xóa 4 property `checkinTime`, `checkinReceptionistId`, `checkinDoctorId`, `checkinNote` ([⚡lead-form.blade.php:169](resources/views/components/leads/⚡lead-form.blade.php:169)).
- `closePhaseNow($idx)` bỏ khối gộp 4 field vào note của closure phase 5 (line 633-643 cũ).
- View phase 5: block form input 4 field → thay bằng banner sky "🔄 Check-in đồng bộ tự động từ Sbooking".
- Khi Admin BO bấm "Đã tới / Tới trễ" bên sbooking → `BookingEventController::status` đã tự close phase 5 + hiện box "✓ Đã check-in lúc ... bởi Admin vận hành (sbooking)" (logic có sẵn).

### 4. Fix bug booking "Đã xong" không sync về scrm — token mismatch
- **Root cause**: sbooking `.env` set `SCRM_API_TOKEN=demodemodemo123` để callback. Scrm `AuthByApiToken` middleware kiểm tra `config('services.booking.api_token')` — cấu hình đọc từ env `BOOKING_API_TOKEN`. Scrm `.env` **chưa có** biến này → config đọc từ `AppSetting::get('booking_api_token')` (AppServiceProvider override). Nhưng `AppSetting` giá trị đã bị set **nhầm** thành URL `http://127.0.0.1:1995/` (không phải token).
- Callback token "demodemodemo123" → không match `AppSetting` (URL) + không match user `api_token` nào → **401 reject** → mọi callback `da_xong` / `da_toi` / `tu_choi` từ sbooking silent fail → scrm không sync.
- **Fix**:
  - Thêm `BOOKING_API_TOKEN=demodemodemo123` vào scrm [.env](.env) (khớp `SCRM_API_TOKEN` sbooking).
  - `AppSetting::set('booking_api_token', 'demodemodemo123')` — reset giá trị đúng.
- Verify: middleware simulate với `Bearer demodemodemo123` → `status=200, actor=1 (Admin)`.

### 5. Perm `lead.assign_direct` + option "Thủ công - Chọn nhân sự"
- Migration [2026_08_05_160000_add_lead_assign_direct_perm.php](database/migrations/2026_08_05_160000_add_lead_assign_direct_perm.php): thêm perm `lead.assign_direct` + attach 5 role (Admin, DM HCM, Manager, CM sale, CM Tele). **Không** tick Admin cơ sở theo yêu cầu.
- [PermissionSeeder.php:41](database/seeders/PermissionSeeder.php:41) + [RolePermissionSyncSeeder.php](database/seeders/RolePermissionSyncSeeder.php): thêm key vào MATRIX source-of-truth.
- [⚡lead-form.blade.php:88](resources/views/components/leads/⚡lead-form.blade.php:88): property `$manualAssignUserId`; mở rộng enum `$mktMode` = `auto | pool | manual`.
- `handleMktDistribution()` case `'manual'`: check perm + check user tồn tại + check target org nằm trong `visibleOrgUnitIds()` của user hiện tại (DM HCM chỉ chia trong HCM) → set `personId = target->id`. **Không set `is_busy=true`** — chia thẳng bỏ qua UPS.
- UI: khi user có perm assign_direct → radio group đổi từ 2 cột thành 3 cột, thêm option "👤 Thủ công - Chọn nhân sự". Chọn Manual → dropdown user list (filter theo `assignments.org_unit_id ∈ visibleOrgUnitIds`).

### Verify
- `php artisan test --filter="RolePermissionMatrix|Distribution|Ups|BookingCallback"` → **61/65 pass**. 4 fail đều **pre-existing** (đã note ở 2026-08-04 T2 + 2026-08-03 Phase 6.22).
- Tinker: 5 role có perm `lead.assign_direct` ✓, Admin cơ sở + Trực Page ✗ (đúng ý).
- `php -l` blade + Blade compile view → no syntax error.
- Middleware test: `AuthByApiToken` với `Bearer demodemodemo123` → 200 OK actor=Admin.

### Chưa làm / dời lại
- **Feature test radio Manual + preview rotated banner**: chưa viết test tự động. Nếu bug sau khi user QA thì viết sau.
- **Option Manual ở màn khác** (VD [⚡lead-pools.blade.php](resources/views/components/leads/⚡lead-pools.blade.php) — CM chia lead từ kho): chưa áp dụng. Task 5 user nói "lúc chia số" — hiện tại chỉ áp dụng radio MKT khi Trực Page tạo lead mới. Nếu user muốn CM cũng có option "Thủ công" khi chia lead từ kho → task riêng.
- **Prod env sync**: `BOOKING_API_TOKEN=demodemodemo123` là token dev/demo. Deploy prod cần đổi token thật + sync sang sbooking `.env`.
- **Config UI cho token**: user hiện phải chỉnh `.env` hoặc `AppSetting` tay. Chưa có màn UI để đổi token qua giao diện (đã có `/settings/booking-connection` nhưng chưa test verify).

### Ghi chú kỹ thuật
- Radio "Thủ công" chỉ hiện khi cả 2 điều kiện: `hasPermission('lead.assign_direct')` **và** đang tạo lead MKT mới (`!$lead?->exists && sourceGroup === MKT`). CM update lead đã tồn tại không thấy — dùng UI cascade + person section như cũ.
- `hasRole()` helper mới dùng `effectiveAssignments` cache → không thêm N+1 query khi check nhiều lần trong 1 request.
- Banner xoay vòng dùng cùng logic `wrap-around` với `UpsDispatcher::pickGreet` (fallback khi tất cả busy) — giữ nhất quán UX.

---

## 2026-08-05 — T11 bán real-time dashboard ✅

### scrm
- [⚡dashboard-overview.blade.php:180](resources/views/components/reports/⚡dashboard-overview.blade.php:180): đổi `wire:poll.60s` → `wire:poll.15s`. Livewire tự re-render mỗi 15s, không cần thêm code.

### sbooking (chưa cài Livewire → dùng fetch+JS thay)
- **`PageController::dashboard`**: khi request `expectsJson()` hoặc `?json=1` → trả `JsonResponse` gồm `counts` + `bookings[]` + `server_time`. Tránh double query bằng cách reuse toàn bộ logic count/list đã có, chỉ đổi output.
- **`dashboard.blade.php`**:
  - Thêm badge "● Live" + đồng hồ `data-server-time` update mỗi 15s.
  - Widget counter đánh `data-count="today|processing|upcoming|done"` để JS update in-place.
  - Bảng list gắn `data-bookings-tbody` để JS render lại rows.
  - Script cuối: `setInterval(refresh, 15000)`, skip khi `document.hidden` (tiết kiệm request khi user chuyển tab).
  - JS render match 100% cấu trúc row của blade (badge status/loại/dịch vụ giống nhau) — user không thấy nhấp nháy khi refresh.

### Verify
- `php -l` PHP + Blade PASS.
- JSON endpoint tinker test: trả `{"counts":{"today":0,"processing":0,"upcoming":0,"done":0},"tab":"today","bookings":[],"server_time":"00:33:17"}` OK.

### Ghi chú
- Cả 2 dashboard giờ tự update mỗi 15s không cần F5. Sale/BO đang thao tác nếu có booking mới hoặc status đổi sẽ thấy trong 15s.
- Chưa dùng websocket vì đụng infra (Reverb + channel auth + JS bridge sbooking↔scrm). Poll 15s đủ dùng cho tần suất booking thực tế; nếu sau này cần instant thì upgrade sang Reverb.

---

## 2026-08-04 — T9 dashboard scrm + T10 dashboard sbooking + fix URL propagation ✅

### T9 — scrm `/dashboard` rewrite
Rewrite [⚡dashboard-overview.blade.php](resources/views/components/reports/⚡dashboard-overview.blade.php) theo yêu cầu user:
- **Bỏ**: funnel counters (Total/Follow/Nét/Booking/Show/Close), Top sale tháng, Lead quá SLA, Doanh thu tháng, widget "hôm nay theo role" cũ, table "Lead mới nhất".
- **Thêm 3 widget mới** (grid 3 cột, click → nav `/leads?phase=X`):
  - 🔵 Lead mới nhập (Phase 1)
  - 🟡 Lead Tele chăm sóc (Phase 2)
  - 🟢 Lead đang booking (Phase 3)
- **Section "Lead hôm nay"** + filter search + Phase dropdown + Nguồn dropdown + bảng 50 row.

### Bug fix: widget click không set filter (⚡lead-list)
- **Trước**: click widget → URL `?phase=1` nhưng `⚡lead-list::$fPhase` vẫn `""` (Livewire không tự đọc query string) → **widget vô tác dụng**.
- **Sau**: [⚡lead-list.blade.php:88-104](resources/views/components/leads/⚡lead-list.blade.php:88) `mount()` đọc `request()->query('phase' | 'source' | 'received=today')` → set property tương ứng.
- Verify: `select[wire:model.live="fPhase"].value === "1"` khi truy cập `/leads?phase=1`.

### T10 — sbooking `/lich-hen` rewrite thành dashboard
- Route `/lich-hen` giờ = `PageController::dashboard()`. Timeline gantt cũ dời sang `/lich-hen/timeline`.
- **4 widget** click-to-filter (query `?tab=today|processing|upcoming|done`):
  - 🔵 Lịch hôm nay (all booking hôm nay)
  - 🟡 Đang xử lý (khách đã tới / tiếp đón)
  - 🟣 Sắp tới (giờ hẹn trong 60 phút, đã duyệt, chưa tới)
  - 🟢 Đã hoàn thành (`trang_thai='da_xong'`)
- **List booking** (theo tab): STT · Mã ĐL · Tên khách · SĐT · Sale chăm sóc · Danh mục (🩺 Thăm khám / 💆 Dịch vụ + tên dịch vụ) · Giờ hẹn · Trạng thái (badge màu theo status + status_khach).
- Row click → nav `/xem-dat-phong/{id}`.
- 2 button top-right: "Xem lịch trình" (timeline cũ) + "Danh sách đầy đủ" (route danh-sach).

### Migration phát sinh
Sbooking DB `lara-sbooking` thiếu migration `2026_08_03_140000_add_trang_thai_tiep_don_to_bookings` (Pending) → chạy `php artisan migrate` để `dashboard()` query cột `trang_thai_tiep_don` không lỗi.

### Verify
- scrm view `dashboard` render 41270 bytes OK (Admin login).
- scrm test browser: 3 widget hiện + section "Lead hôm nay" + filter live-update. Click widget → `/leads?phase=1` → dropdown filter tự chọn "Phase 1 · Tạo mới & Chia số" ✅.
- sbooking view `longevity.dashboard` render 23150 bytes OK.
- Không phá test scrm (baseline 184/207 giữ nguyên).

### Chưa làm / lưu ý
- Bên sbooking chưa test qua browser thật (Laragon vhost timeout khi navigate) — chỉ render server-side + template validate xong syntax. User F5 verify trực tiếp.
- Widget cũ scrm (UPS today / Chờ chia / Chờ duyệt / Được nhận) — **đã bỏ theo yêu cầu**. Nếu cần thêm lại vài widget hữu ích, báo tao thêm.
- Nav "Đặt lịch phòng khám" / "Đặt lịch dịch vụ" bên sbooking topnav vẫn trỏ `/lich-hen` (giờ = dashboard) — nếu user muốn giữ trải nghiệm "vào là thấy lịch gantt", đổi link topnav trỏ `/lich-hen/timeline`.

---

## 2026-08-04 — T5 (audit) + T6 (tái tổ chức menu scrm + sbooking) ✅

### T5 — Audit toàn bộ hệ thống (script scratchpad/audit_full.php)
- **9 sections × 30+ check**: DB Integrity / Permission / UPS / Booking / Lead Flow / Task 3 route / Task 4 filter / UI render (Admin + Sale).
- **Kết quả**: PASS 90%. 3 warn phát hiện:
  1. **A8**: 26 user active không có assignment (bác sĩ/KTV/điều dưỡng seed từ RealDoctorsSeeder) — **behavior đúng**, staff data không cần login. Không fix, chỉ note.
  2. **B2**: Role Admin thiếu 3 perm `lead.consult`, `lead.source_all`, `system.backup` — **BUG THẬT**. FIX: bổ sung vào `RolePermissionSyncSeeder::MATRIX['Admin']`, re-seed. Verify Admin giờ có mọi perm hệ thống.
  3. **G1**: 0 lead gán `facility_id` → filter Cơ sở ở `/reports` ra 0 dòng — data issue của DB `lara-crm` (chưa có demo), không phải code bug.

### T6.2 — Tái tổ chức nav scrm + `/settings` tab hóa

**Vấn đề trước**:
- Nav top 4 group nhưng chồng chéo: "Chia số" ở group Khách hàng + "Rule chia số" ở /settings.
- "Sơ đồ tổ chức" ở /settings + "Tổ chức" (org.users) ở nav top → trùng.
- "Danh mục hệ thống" ở nav top + "Trường tùy biến" ở /settings → nghĩa gần nhau.
- `/settings/index` là grid 15 module phẳng, không nhóm.

**Sửa** ([layouts/app.blade.php](resources/views/layouts/app.blade.php:6-56)):

Nav top mới, **3 khu**:
- **KHU 1 · Vận hành hằng ngày** (flat, cho user thường): `Dashboard` | `Khách hàng` (danh sách + thêm + duyệt) | `Chia số` (UPS hôm nay + Kho lead + UPS check-in) | `Kinh doanh` (dịch vụ + thu tiền) | `Báo cáo`.
- **KHU 2 · Quản trị** (dropdown, gate `ops.manage / rule.manage / connection.manage`): `Quy tắc vận hành` | `Rule chia số` | `Kết nối Booking` | `Kết nối nguồn Ads`.
- **KHU 3 · Thiết lập** (dropdown, gate `user.manage`): `Trang thiết lập` | `Tổ chức & User` | `Danh mục hệ thống`.

Trang `/settings/index` ([settings/index.blade.php](resources/views/settings/index.blade.php)) — tab hóa 4 nhóm:
- **Tổ chức** (4 module): Sơ đồ tổ chức / Người dùng / Vai trò / Bác sĩ & Cơ sở.
- **Danh mục dữ liệu** (4 module): Danh mục hệ thống (T3) / Trường tùy biến / Duyệt trường / Dịch vụ.
- **Hệ thống** (3 module): Thiết lập thông báo / Nhật ký thông báo / Sao lưu & khôi phục.
- **Cá nhân** (2 module): Quản lý phiên / Đổi mật khẩu.

Alpine `x-data="{ tab: firstKey }"` + `x-show` — không đổi URL, mở sẽ luôn ở tab đầu.

**Verify per role**:
- ADMIN: 4 tabs / 13 module / 52586 bytes.
- SALE: 1 tab (Cá nhân) / 2 module / 27119 bytes — đúng two-tier UX (memory `two-tier-ux-operator-vs-admin`).

**Xóa trùng**:
- Kết nối Booking rời `/settings` → chỉ ở nav Quản trị.
- Rule chia số rời `/settings` → chỉ ở nav Quản trị.
- Báo cáo rời `/settings` → chỉ ở nav top.
- Kết nối nguồn Ads rời `/settings` → chỉ ở nav Quản trị.

### T6.3 — Tái tổ chức `/thiet-lap` sbooking

**Vấn đề trước**: grid 9 sections phẳng + 2 mục admin-only rời rạc, không nhóm.

**Sửa** ([resources/views/longevity/settings/index.blade.php](../lara-sbooking/resources/views/longevity/settings/index.blade.php)):

Tab hóa 4 nhóm (Alpine `x-show`), giữ style Material 3 hiện có:
- **Tổ chức** (5): Phòng ban / Vai trò / Cơ sở / Người dùng / Quyền.
- **Danh mục** (3): Liệu pháp / Menu / Phòng chức năng.
- **Báo cáo** (1): Báo cáo.
- **Hệ thống** (2, admin only): Nhật ký thông báo / Kết nối SCRM.

Không đụng controller `SettingsController::SECTIONS` (giữ nguyên logic permission per section). Chỉ đổi cách render lại thành tabs.

### Regression
- scrm: 184/207 pass, y hệt baseline. **0 test regress** do T5+T6.
- sbooking: view render OK (`php artisan view:clear` + `php -l`).
- Fail còn lại tất cả **pre-existing** (đã note ở phase trước).

### Ghi chú
- Menu scrm ưu tiên 1 tính năng chỉ 1 chỗ, gate theo permission → user thường chỉ thấy KHU 1, Admin cơ sở thấy KHU 2, Admin hệ thống thấy đủ 3.
- Sbooking: nếu user muốn đổi tên "Liệu pháp" → "Dịch vụ" hoặc rearrange sections khác, chỉ cần chỉnh `$tabMap` ở `index.blade.php` — không đụng controller.

---

## 2026-08-04 — T4: Filter báo cáo "Chi tiết lead" theo cơ sở + kho số ✅

Bổ sung sau Task 3: user cần xuất lead cắt theo cơ sở/kho số ngay ở `/reports` (không phải mở `/admin/catalog`).

### Sửa `⚡report-center`
- Thêm 2 property Livewire: `?int $fFacilityId` + `?int $fPoolId`.
- `reportLeadQuery()`: khi `tab === 'leads'` → apply 2 filter:
  - `fFacilityId` → `where facility_id = X`.
  - `fPoolId` → resolve pool → `subtreeIds()` → JOIN `org_pool_map` → tập org_ids → subtree các org đó theo `path LIKE` → `whereIn org_unit_id`. Pool chưa map với org nào → `whereRaw('1=0')` (0 lead) để user thấy rõ mapping thiếu.
  - Filter CHỈ apply ở tab `leads` — không đè lên funnel/marketing/performance/distribution để giữ nguyên số report cũ.
- UI filter bar (khi `section=overall && tab=leads`): thêm 2 select "Cơ sở" (list `Facility::orderBy('name')` với parent prefix) + "Kho số" (list `PoolUnit::where('is_active', true)` với indent theo `depth`).
- Nút "Xuất Excel" của tab `leads` tự động dùng cùng `reportLeadQuery()` → filter propagate xuống file xuất mà không cần sửa gì thêm.

### Verify
- `view('reports.index')->render()` → 40127 bytes OK.
- `php artisan test` → 184/207 pass, y hệt baseline. **0 test regress.**

### Ghi chú thiết kế
- Không đụng bảng `stats_daily` — filter chạy live trên `leads`, chậm hơn với data lớn nhưng chính xác + không cần re-aggregate.
- Tab "Danh sách khách hàng" đã có sẵn với tên "Chi tiết lead" — không đổi tên tránh vỡ bookmark user. Nếu user muốn đổi tên, sửa key `leads` trong array `['funnel' => ..., 'leads' => 'Chi tiết lead']` ở line ~936.
- Filter "Kho số" ảnh hưởng qua `org_pool_map` → nếu team con chưa map (VD facility HN CS2 chưa hoạt động) sẽ ra 0 lead. Đây là behavior đúng.

---

## 2026-08-04 — Task 3: /admin/catalog — Danh mục hệ thống 5 tab + Import/Export ✅

### Bối cảnh
User nhận thấy config bắt đầu phân tán mất kiểm soát (nhân sự / dịch vụ / trường info khách / khách hàng / cây tổ chức nằm rải rác nhiều màn). Cần 1 trang gom chung cho Admin hệ thống xem toàn cảnh + import/export dữ liệu thật.

### Thiết kế chốt
- Route mới `/admin/catalog` — chỉ role có `user.manage` (Admin).
- 5 tab all-in-one Livewire (1 URL để tránh import nhầm tab):
  1. **Cơ cấu tổ chức** (`org_units`)
  2. **Nhân sự** (`users` + `assignments`)
  3. **Dịch vụ** (`services`)
  4. **Khách hàng** (`leads` + filter theo Phase 1-6)
  5. **Trường thông tin KH** (`custom_fields`, bypass approve khi Admin nhập)
- Mỗi tab: bảng data + 3 nút: **📄 Tải file mẫu** / **⬇ Xuất Excel** / **⬆ Nhập**.
- Import ghi thẳng vào DB (không qua raw pipeline) — công cụ admin, chấp nhận rủi ro.
- Nhân sự có cột `password` plain trong file mẫu → tự hash bcrypt lúc import; user đã tồn tại thì giữ password cũ nếu cột để trống/hoặc placeholder `(đã hash, không xuất)`.

### File mới
- `app/Http/Controllers/SystemCatalogController.php`: 2 method `export($tab)` + `template($tab)` — cùng logic fill cột (khi withData=true dùng thật, false dùng row mẫu).
- `app/Services/SystemCatalogImporter.php`: 5 method `importOrg / importStaff / importService / importLead / importField`. Trả `{created, updated, skipped, errors[]}` để UI hiện report.
- `resources/views/admin/catalog.blade.php` (wrapper).
- `resources/views/components/admin/⚡system-catalog.blade.php` — Livewire class-less: props `activeTab`, `importFile`, `leadPhaseFilter`, `importReport`; action `setTab`, `runImport`. Guard `hasPermission('user.manage')` cả `mount()` lẫn `runImport()`.

### File sửa
- `routes/web.php`: `Route::prefix('admin/catalog')->middleware('permission:user.manage')` với 3 route (view + export + template).
- `resources/views/layouts/app.blade.php`: thêm nav item "Danh mục hệ thống" (gate `user.manage`).
- `resources/views/components/leads/⚡lead-form.blade.php`: **fix bug Task 2** — `@can('lead.book_action')` và `->can('lead.book_action')` không hoạt động vì repo không dùng Gate, phải đổi thành `hasPermission()` / `@if(...->hasPermission(...))`.

### Cột file mẫu (khớp giữa import và export)
- **org**: `code, name, parent_code, depth, position, active`
- **staff**: `username, name, email, password, job_title, status, role_name, org_unit_code, data_scope`
- **service**: `code, name, service_type, pricing_type, package_price, active`
- **lead**: `name, phone, received_date, source_group, classification, region, note, phase`
- **field**: `key, label, field_type, org_unit_code, required, options, position, active` (options ngăn cách `|`)

### Verify
- `php artisan route:list --path=admin/catalog` → 3 route OK.
- `php artisan tinker --execute="Auth::login(Admin); view('admin.catalog')->render()"` → **46145 bytes** render OK (không exception, gate `user.manage` verify pass).
- `php artisan test` → **184/207 pass** (baseline giống pre-Task 3, verify bằng `git stash -u`). **0 regression từ Task 3.**
- 10 fail + 13 error đều **pre-existing** (Hcm test cần MySQL, ProcessRawLead KH-code format, SbookingClient http msg, RolePermissionMatrix sourceGroup, DistributionEngine notification, UpsDispatcher fallback, ImportComponent, BookingCheckin phase 4/5, SlaRecall sqlite NOT NULL).

### Chưa làm / dời lại
- **Import ORG nested**: hiện `parent_code` phải là node đã tồn tại trong DB (không tự order theo depth). Muốn import cả cây fresh → tự sort file theo depth trước khi import, hoặc chạy import 2 lần (lần 1 tạo root, lần 2 tạo children).
- **Import file mẫu STAFF password**: file mẫu có row `PassPlain@123` — user copy demo sẽ hash và tạo user thật với password đó. Chú ý đổi trước khi import.
- **Feature test import/export**: chưa viết. Nếu user gặp bug khi import thực tế thì viết test sau (mỗi tab 1 case OK + 1 case fail).
- **UI download link**: dùng anchor tag `<a href>` thẳng, không qua Livewire — user click là browser download luôn (không stream qua Livewire vì Livewire không hỗ trợ StreamedResponse tốt).

### Ghi chú kỹ thuật
- Livewire 4 class-less component (`new class extends Component`) — pattern chuẩn của repo, file ở `resources/views/components/**/⚡*.blade.php`.
- Bảng chỉ load 500 record đầu (200 với lead) để UI không đơ; xuất Excel để lấy full data.
- Import upload dùng `WithFileUploads` trait, giới hạn 10MB, chấp nhận `xlsx/xls/csv`.
- Không có Gate registered trong repo → **luôn dùng `hasPermission()` thay vì `->can()`**. Bài học: kiểm tra Provider có `Gate::before` hay `Gate::define` trước khi dùng `@can`/`can()`.

---

## 2026-08-04 — Task 1+2: Hook migrate:fresh cho PG + Booking chỉ Admin/Admin cơ sở tạo ✅

### Task 1 — Hook `db:wipe --database=pgsql` vào `migrate:fresh`

- `AppServiceProvider::boot()` listen `CommandStarting`: khi lệnh = `migrate:fresh` + có connection `pgsql` → prompt user:
  - `0` = bỏ qua pgsql
  - `1` = wipe pgsql (default)
  - Validator chặn nhập ngoài `0/1`.
- No-interaction mode (test/CI) → auto reset để không chặn.
- Try/catch quanh `db:wipe` — PG chết không chặn `fresh` mysql.

### Task 2 — Cắt quyền tạo booking + Sale chỉ xem booking phụ trách

**Thiết kế chốt (2026-08-04):**
- Sbooking từ "phần mềm độc lập" → "phần mềm phụ trợ Data Source". Chỉ Admin cơ sở (BO Lễ Tân) tạo/duyệt booking.
- Sale chỉ ghi chú + xem booking mình phụ trách (là CV pivot).
- Booking "Đã xong" → auto `UpsDispatcher::markFree(cv1)` → Sale trở về bucket UPS cũ (A/B/C/OFF), sẵn sàng nhận số kế tiếp.
- Dùng `da_xong` (đã có) làm status "hoàn thành" — không thêm status mới.
- Phòng BO thêm bên `lara-sbooking` (nằm ngang phòng Kinh Doanh), chứa 3 tk admin_59ntn/23tdn/207nvt vai trò `le_tan` (đã có perm `duyet_booking`).

**Bên scrm (5 file):**
- Migration `2026_08_04_100000_trim_book_action_perm_to_admin_only.php`: detach `lead.book_action` khỏi 12 role, chỉ giữ ở Admin + Admin cơ sở. Verify: `Roles giữ lead.book_action: Admin cơ sở, Admin`.
- `RolePermissionSyncSeeder::MATRIX` (source of truth): bỏ `lead.book_action` khỏi DM HCM, Manager, CM sale, CM Tele, Team Leader, Sale, Team sale, Team sale ĐN, Team Tele.
- `⚡lead-form.blade.php`:
  - Khối "Ghi nhận booking" (nút "+ Tạo booking") wrap `@can('lead.book_action')` — Sale không thấy.
  - Khối "Lịch sử booking" thêm dropdown filter status (Tất cả / Chưa duyệt / Đã duyệt / Đã tới / Tới trễ / Khách hủy / Đã xong).
  - Filter mặc định: nếu user không có `lead.book_action` → chỉ hiện booking mà user là CV (`booking_log_consultants` pivot). Có label "📌 Chỉ hiện booking mà bạn phụ trách".
  - Property mới `public string $bookingHistoryFilter = ''` (Livewire live-model).
- `BookingLog::booted()`: observer `updated` — khi `sync_status` chuyển sang `done` → gọi `app(UpsDispatcher::class)->markFree(cv1->id)`. Sale giữ nguyên bucket, chỉ toggle `is_busy=false`.

**Bên lara-sbooking (2 file):**
- `LongevitySeeder`: thêm `'bo_le_tan' => 'Phòng BO (Lễ Tân)'` vào `$phongBanChuan` (auto seed cho mọi cơ sở lần fresh sau).
- Migration `2026_08_04_100000_seed_bo_le_tan_and_admin_co_so.php` (idempotent): 
  - Insert phòng `bo_le_tan` cho tất cả cơ sở đang có (4 cơ sở).
  - Tạo 3 user: `admin_59ntn` (HN CS1) / `admin_23tdn` (DN) / `admin_207nvt` (HCM CS1), password `<pass-hn>`, vai trò `le_tan` (đã có perm `duyet_booking` + `xem_booking` + `them_booking` + `cap_nhat_trang_thai_khach` + `binh_luan_booking` từ Longevity).

Verify tinker:
```
admin_59ntn  | Admin Cơ sở 59NTN  | cs=59ntn  | pb=bo_le_tan | vt=le_tan
admin_23tdn  | Admin Cơ sở 23TDN  | cs=23tdn  | pb=bo_le_tan | vt=le_tan
admin_207nvt | Admin Cơ sở 207NVT | cs=207nvt | pb=bo_le_tan | vt=le_tan
```

### Test regression
- `php artisan test --filter="RolePermissionMatrix|Distribution|Ups"` → 61/65 pass.
- 4 fail đều **pre-existing** (verify bằng git stash pop cùng test filter):
  - `RolePermissionMatrixTest::Team sale × sa` + `CM sale × sa` — có sẵn trước Task 2.
  - `DistributionEngineTest::test_full_flow_common_to_team_to_user` — pre-existing (đã ghi ở result.md cũ).
  - `UpsDispatcherTest::test_pick_greet_returns_null_when_all_busy` — pre-existing.
- **Không có test nào regress do Task 2.**

### Chưa làm / dời lại
- Feature test cho observer `markFree`: chưa viết, dựa vào regression manual. Task nhỏ, làm sau nếu bug bash phát hiện.
- Task 3 (`/admin/catalog`): làm ở patch sau, không gộp chung để dễ review.

### Ghi chú kỹ thuật
- `RolePermissionSyncSeeder` chạy CUỐI + dùng `sync()` (replace) → chỉ cần sửa MATRIX ở đây, các seeder khác (OrgStaffSeeder, Phase66FlowSeeder, OrgAndRoleSeeder) không phải sửa — sẽ bị Sync đè.
- Sbooking role `le_tan` **đã có sẵn** perm cần thiết → không tạo perm mới bên sbooking.
- Filter booking history query trực tiếp trong Blade `@php` block — vì Livewire component đã có nhiều state, tránh thêm public method mới.
- Observer dùng `wasChanged('sync_status')` → chỉ trigger đúng 1 lần khi status thực sự chuyển sang `done` (không bị double trigger nếu record đã ở `done` rồi).

---

## 2026-08-03 — Phase 6.22: Cây Kho số, Role BO & UPS check-in ✅

### Đã làm

**Data & seed:**
- Migration `2026_08_03_100000_create_pool_units_and_map.php` — bảng `pool_units` (cây kho số đệ quy, kind: company/branch/facility/department) + `org_pool_map` (bảng cầu org↔pool).
- Migration `2026_08_03_100100_create_ups_tables.php` — `ups_config`, `daily_attendance`, `ups_daily_confirm`.
- `PoolUnitSeeder`: cây **Longevity Medical** — 1 company / 3 chi nhánh HN·ĐN·HCM / 5 cơ sở / 4 phòng KD. HN CS2 (190 Hoàng Ngân) và HCM CS2 (137 Nguyễn Chí Thanh) seed với `is_active=false` (chưa hoạt động).
- Map `org_pool_map`: `branch-hn/dn/hcm` → pool tương ứng (1-1).

**Role & tài khoản:**
- 4 permission mới: `ups.view`, `ups.checkin`, `ups.override`, `ups.confirm_daily`.
- Role seed `BO (Lễ Tân)` — 4 perm UPS + `lead.view` + `lead.view_phone` + `lead.distribute_sale`.
- 3 tài khoản BO (`BoRoleSeeder`), scope theo chi nhánh:
  - `bo.hn@longevity.com.vn` (branch-hn) · pass `<pass-hn>`
  - `bo.dn@longevity.com.vn` (branch-dn) · pass `<pass-hn>` (fallback rule, xem `DefaultPassword`)
  - `bo.hcm@longevity.com.vn` (branch-hcm) · pass `<pass-hn>` (fallback rule)
- Admin thêm 4 perm UPS vào `RolePermissionSyncSeeder` (source of truth).

**Business logic:**
- `App\Services\Ups\UpsBucketResolver` — 2 API: `resolve(checkin_at, facility_id)` (đọc DB cutoff) và `resolveWithCutoff(checkin_at, cutoff)` (thuần logic, dùng test).
- Rule tạm (Phase 6.22): ≤ cutoff → `A`; > cutoff → `OFF`; null → null. Cutoff mặc định `08:35:00`.
- `App\Services\Ups\UpsGate` — chặn chia số nếu chi nhánh của user chưa có cơ sở nào chốt UPS hôm nay. Admin (`user.manage`) bypass. Walk ancestors qua materialized path để tìm branch org.

**UI:**
- Route `/ups` (`permission:ups.view`) + nav item "UPS check-in" dưới "Khách hàng".
- Livewire component `⚡ups-board.blade.php`: mỗi chi nhánh 1 section, mỗi cơ sở active 1 card. 5 cột A/B/C/OFF/MKT nhóm "Sale tiếp đón" (4) + "Sale nhận số" (1). Đồng hồ Alpine tick giây góc phải. Nút "Chốt UPS hôm nay" (perm `ups.confirm_daily`).
- Check-in: BO chọn sale từ dropdown → click "+ Check in" → resolver quyết định bucket (A/OFF) theo now vs cutoff. Override: dropdown "↔" trong ô để chuyển bucket, "×" để xóa.
- Guard chia số ở `⚡lead-pools.blade.php`: gọi `upsGuard()` đầu 5 mutation (autoDistribute, confirmAssign, confirmPool, pullLead, bulkAssign, bulkPool) — chưa chốt UPS → 423.
- Banner + button "Check UPS System" trên `distribution/pools.blade.php` và `leads/create.blade.php`. Khi block: pool bị `pointer-events-none opacity-50`.

**Test:**
- `tests/Unit/UpsBucketResolverTest.php` — 5 case: null / trước cutoff / đúng cutoff (=A) / sau cutoff 1s / 8h36 (=OFF).
- `tests/Feature/UpsFlowTest.php` — 6 case: check-in trước cutoff → A; 8h36 → OFF; CM bị gate block khi chưa chốt; sau khi BO chốt daily → gate mở; Admin bypass; route `/ups` đòi `ups.view`.
- **12/12 test UPS pass.**

**Smoke test qua browser** (BO HN):
- `/ups`: chi nhánh HN, 1 cơ sở active, 5 cột, đồng hồ tick, dropdown 17 sale HN, banner cutoff 08:35.
- `/distribution/pools`: banner đỏ "UPS chưa được chốt", button "Check UPS System" phải, kho lead disabled.

### Rủi ro / đã lưu ý
- **Full regression**: 181/202 test pass. 8 failure + 13 error đều **pre-existing** (Hcm tests cần MySQL thật, CustomerFlow621 phase logic, ImportComponent, ProcessRawLead KH- code format, SbookingClient http msg, RolePermissionMatrix sourceGroup, DistributionEngine notification). Không có failure nào ở test đụng file UPS đã sửa (Distribution/Ups/LeadListActions filter → 30/31 pass, cùng 1 failure pre-existing).
- **Mapping org↔pool**: hiện chỉ map ở cấp chi nhánh (branch-hn ↔ pool-branch-hn ...). Mapping sâu (team → phòng KD cụ thể) hoãn đến khi user duyệt riêng.
- **Lead/rule chưa migrate sang pool_unit_id**: giữ nguyên `org_unit_id` cho tương thích. Phase sau (khi user OK mapping) mới cắt đường cũ.
- **Sale list ở màn UPS**: hiện lấy tất cả user có role tên chứa "sale" (LIKE '%ale%'), thuộc subtree org chi nhánh. Nếu user muốn strict theo cơ sở, cần map cấp sâu hơn.
- **DevOps note**: bật `pdo_pgsql` + `pgsql` + `pdo_sqlite` + `sqlite3` cho php-8.5.9 (Laragon); sửa `.claude/launch.json` dùng absolute php path (composer đòi ≥ 8.4.1, Laragon default 8.2.31 fail).

### Chưa làm (dời phase sau)
- Cắt reference `org_unit_id` → `pool_unit_id` ở leads/rules (chờ user duyệt mapping đầy đủ).
- Tier engine B/C/MKT (hiện chỉ A/OFF theo cutoff, cột B/C/MKT chỉ BO override tay).
- Config cutoff per cơ sở qua UI (đã có bảng `ups_config`, nhưng chưa có màn quản lý — dùng default 08:35).
- Danh sách sale "đúng cơ sở" (hiện đang list toàn chi nhánh).


## 2026-08-01 — Phase C1.b rev3: Sync khung giờ từ sbooking ✅

User feedback: hardcode giờ 8:30-12/13:30-18 không dùng được. Logic BS capacity phức tạp (1 BS = 1 tư vấn + 6 khám lâm sàng/giờ, mỗi BS `nhan_tu_van`/`nhan_kham_ls` khác nhau) → không thể replicate scrm-side, phải hỏi sbooking. Chọn hướng B: đọc khung giờ động từ sbooking.

### Đã làm

**Sbooking:**
- Endpoint mới `GET /api/sync/khung-gio?co_so_id=X` — trả distinct `gio_bat_dau` của tất cả `khung_gio` thuộc phòng active của cơ sở, loại slot <30' (data test có nhiều 5' slot).
- Response: `{co_so_id, count, slots:[{gio_bat_dau, label}]}`.
- Test tay: cơ sở id=1 → **17 slot** từ 08:00 → 17:30 (mỗi 30 phút).

**Scrm:**
- Public property `availableSlots` trong `⚡lead-form`.
- Livewire hook `updatedNewBookingFacilityId(mixed $value)`: khi user chọn cơ sở → walk parent chain lấy `sbooking_co_so_id` → HTTP GET `/sync/khung-gio` → set `availableSlots`. Fail silent (form dùng fallback).
- Dropdown giờ đọc từ `$availableSlots` (server-side inject vào Alpine x-data). Fallback hardcode 8:30-12/13:30-18 nếu chưa chọn cơ sở hoặc API fail.
- Đổi `wire:model="newBookingFacilityId"` → `wire:model.live` để trigger hook.
- Title tooltip select giờ hiện rõ đang dùng "fallback" hay "sbooking (N slot)".

### Chưa xử lý (đúng ý user, để tương lai)

- **Check capacity BS slot cụ thể**: endpoint hiện chỉ trả khung giờ chung của cơ sở, không loại slot đã đầy. Muốn check "BS X đã đủ 1 tư vấn + 6 khám lâm sàng chưa" cần:
  - Endpoint mới `GET /api/sync/check-capacity?bac_si_id=X&ngay=Y&gio=HH:MM&dich_vu_id=Z` gọi lại logic `checkBacSiCapacity()` sẵn có bên sbooking.
  - Điều kiện: BS scrm ↔ BS sbooking đã map (task Phase C3 staff sync).
- **Xử lý duyệt fail bên sbooking**: khi Admin sbooking duyệt fail do conflict (`BookingController::duyet` return early với error), hiện KHÔNG push callback về scrm → badge scrm vẫn `synced` sai. Cần thêm push với `trang_thai='cho_duyet'` + reason khi duyệt fail. Task nhỏ, làm sau.

### Test
- `SbookingClient` + `SyncServicesFromSbooking` 8/8 pass.
- E2E: curl `/api/sync/khung-gio?co_so_id=1` → 17 slot chuẩn. Livewire hook chưa test tự động (test manual F5 form).

### Ghi chú thiết kế
- Endpoint không filter theo ngày. Vì `khung_gio` bên sbooking là template lặp (không per ngày). Ngày nghỉ (`ngay_nghi`) là bảng riêng, có thể extend endpoint sau nếu cần.
- Distinct theo `gio_bat_dau` only — không trả `gio_ket_thuc` vì mỗi phòng có `gio_ket_thuc` riêng cùng `gio_bat_dau`. Scrm chỉ cần start time; Admin sbooking resolve slot đầy đủ khi duyệt.
- Slot ≥ 30 phút: filter cứng qua `TIMESTAMPDIFF(MINUTE, ...) >= 30` để loại rác test 5-10 phút. Nếu cần chỉnh sau thì sửa condition.

---

## 2026-08-01 — Phase C1.b rev2: nguồn = source_group + form ngày/giờ slot + callback từ chối ✅

3 điểm bổ sung sau khi test tay lần 2 (Trần Hoà):

### 1. Nguồn booking bên sbooking = source_group scrm
- Trước: `SbookingClient::pushBooking` gửi `nguon='SCRM'` cứng → sbooking hiện "SCRM" thay vì nguồn thật.
- Sau: `nguon => $lead->source_group ?: 'SCRM'`. Payload push mkt/mkt_br/bdm/bod/sa/ba/wi tương ứng lead.
- Sbooking dropdown `nguon` đã có 7 mã này từ Phase 2026-07-28.

### 2. Form chọn ngày/giờ booking
- Trước: `<input type="datetime-local">` — 1 field lộn xộn, không giới hạn khung giờ làm việc.
- Sau: Alpine.js x-data tách 2 field `<input type="date">` + `<select>` slot giờ.
- **Khung giờ hardcode**: 8:30-12:00 (7 slot) + 13:30-18:00 (9 slot) = 16 slot 30 phút.
- Binding: getter/setter trên `$wire.newBookingScheduledAt` — combine `date + 'T' + time`. Backward compat với logic backend cũ.

### 3. Callback từ chối từ sbooking → scrm cập nhật `rejected`
- Sbooking `BookingController::tuChoi()` giờ gọi `CrmPushService::pushStatus()` sau save.
- Payload push thêm `ly_do_tu_choi`.
- Scrm `BookingEventController` case status: `tu_choi` → `sync_status='rejected'`, lưu `ly_do_tu_choi` vào `sync_error`.
- UI badge scrm: `❌ Sbooking từ chối — {lý do}` (rose).

### Fix bonus khi debug
- `CRM_URL` bên sbooking `.env` cũ trỏ `127.0.0.1:1999` không tồn tại → sửa `http://lara-scrm.test:81`. Không có config này thì tất cả callback trước đó silent fail.
- Sbooking user `admin@sweetsica.com` chưa có `api_token` → gán khớp với scrm user `hn.cms04@longevity.com.vn`. Cần setup thêm cho các user khác nếu họ cũng bấm duyệt.
- `SbookingClient::pushBooking` giờ walk parent chain: nếu user chọn cơ sở con "Khối chuyên môn" (không map), tự leo lên root (HN=1) lấy `sbooking_co_so_id`.
- Gate `newBookingFacilityId` chuyển thành **required** — không cho gửi thiếu cơ sở. Cùng walk parent chain khi check `sbooking_co_so_id`.

### Chưa làm / dời lại
- **Sync `lich_lam_viec` từ sbooking**: user gợi ý "chưa sync giờ làm việc bên này qua à?" — sbooking đã có bảng `lich_lam_viec` per bác sĩ per ngày. Hiện scrm hardcode 8:30-12/13:30-18. Đúng luồng phải: chọn cơ sở + BS + ngày → gọi API sbooking lấy slot khả dụng → hiện dropdown động. Task riêng, gộp vào Phase C3 (staff) hoặc tạo Phase C1.d.
- **Setup api_token đại trà**: hiện chỉ 2 user scrm có token. Cần script seed/sync để mọi user bấm duyệt bên sbooking đều gọi callback thành công. Nếu không → badge `synced` sẽ không tự đổi thành `approved`.

---

## 2026-08-01 — Phase C1.b rev: Chặn cứng + callback duyệt từ sbooking ✅

Nối tiếp C1.b chiều nay, user QA thấy wording sai + luồng lỏng lẻo. Sửa 4 điểm:

### Đã làm

1. **Wording box lead-form (khối "Ghi nhận booking")**:
   - Trước: "lễ tân sbooking gán phòng + khung giờ khi duyệt".
   - Sau: "Bấm Thêm booking → tạo record bên sbooking ở trạng thái Chờ duyệt. Admin sbooking duyệt → tự cập nhật trạng thái Đã duyệt về đây. Chưa map cơ sở → không cho ghi."

2. **Chặn cứng ghi khi cơ sở chưa map**:
   - Validation `newBookingFacilityId` giờ **required**, không nullable.
   - Sau validate, kiểm tra `facility.sbooking_co_so_id` null → `session()->flash('cf_error', ...)` + return. Không tạo booking_log local nữa.
   - Bỏ điều kiện `status === da_xac_nhan` trước khi push — mọi booking đều push (hợp lý vì đã chặn cứng, cơ sở chắc chắn map).

3. **Sbooking auto callback về scrm khi Admin duyệt**:
   - `BookingController::duyet()` — sau `$booking->save()`, gọi `CrmPushService::pushStatus($booking, auth()->id())`. Nếu fail → warn message.
   - `CrmPushService::pushStatus()` payload thêm `sbooking_booking_id => $booking->id` để scrm match log dễ hơn (thay vì phải parse ma_booking).

4. **Scrm nhận callback duyệt → cập nhật booking_log**:
   - `BookingEventController::__invoke` case `status`: nếu payload có `sbooking_booking_id` → tìm `BookingLog::where('sbooking_booking_id', X)` + update `sync_status` theo `trang_thai` sbooking:
     - `da_duyet` → `approved`
     - `cho_duyet` (bỏ duyệt) → `synced`
   - Ghi `synced_at = now()`.
   - UI badge cập nhật: `approved` (xanh đậm ✅ Sbooking đã duyệt), `synced` (xanh nhạt ⏳ Chờ duyệt).

### Retry
- Bỏ điều kiện `status = da_xac_nhan` trong `retrySbookingSync` — retry được cho mọi log failed.

### Enum ý nghĩa mới của `booking_logs.sync_status`
- `pending`: đang gửi (transient).
- `synced`: đã tạo bên sbooking, chờ Admin duyệt.
- `approved`: Admin sbooking đã duyệt.
- `failed`: gửi lỗi (network / cơ sở chưa map / ...), giữ log local, có nút "🔄 Thử lại".

### Test
- `SbookingClient` 4/4 + `SyncServicesFromSbooking` 4/4 = **8/8 pass** (không phá).
- E2E callback duyệt cần user bấm nút "Duyệt" bên sbooking → theo dõi `booking_logs.sync_status` thay đổi. Cần user bên sbooking có `api_token` khớp với user bên scrm (setup Phase A/B).

### Chưa làm / dời lại
- **Test tự động cho callback approved**: hiện chỉ có test cho `SbookingClient::pushBooking`. Feature test cho `BookingEventController::status` type=da_duyet + update BookingLog chưa viết. Task nhỏ, làm sau nếu bug bash phát hiện.
- **Từ chối (`tu_choi`) và bỏ duyệt (`cho_duyet` lần 2)**: sync_status hiện chỉ handle `da_duyet` + `cho_duyet`. Nếu Admin sbooking bấm "Từ chối" (trang_thai='tu_choi') → hiện match default (giữ nguyên sync_status). Có thể cần thêm giá trị `rejected` sau.

---

## 2026-08-01 — Phase C1.b: Gộp form booking CRM + auto push sang sbooking ✅

Nối tiếp Phase C1 sáng nay. User chốt: form "Ghi nhận booking" trong lead-form phải **tạo lịch thật bên sbooking** khi save (status=`Đã xác nhận`), không còn tách log CRM và nút "Đặt booking" cũ nữa. Design B trong đề xuất trước, 5 điểm chốt: 1a/2a/3a/4-hack/5x.

### Đã làm

**Sbooking:**
- Endpoint mới `POST /api/bookings` ([SyncApiController → BookingApiController::store](../../lara-sbooking/app/Http/Controllers/Api/BookingApiController.php)) — validate payload, upsert `khach_hang` theo `so_dien_thoai`, insert `booking` với `trang_thai='cho_duyet'`, không yêu cầu `phong_id`/`khung_gio_id` (lễ tân sbooking gán sau).
- Route trong group `scrm.token`. E2E test tay bằng curl → tạo được booking id=6 khách_hang_id=4 status=cho_duyet.
- **Lưu ý enum**: `loai_dat_lich` bên sbooking là `phong_kham`/`dich_vu` — không phải `tham_kham`. Client scrm tự map `tham_kham` → `phong_kham` khi push.

**Scrm:**
- Migration `2026_08_01_130000_add_sbooking_sync_to_booking_logs_and_facilities`: `booking_logs` thêm `sbooking_booking_id`, `sync_status` (`pending|synced|failed`), `sync_error`, `synced_at`. `facilities` thêm `sbooking_co_so_id` (map tay, chờ Phase C2 auto sync).
- Service `App\Services\SbookingClient::pushBooking(BookingLog)`: build payload (map service.name → sb_services.sbooking_id best-effort, phone/name/code lead, facility.sbooking_co_so_id, scheduled_at tách ngày+giờ), gọi `Http::withToken()->post($BOOKING_API_URL.'/bookings')`. Không rollback nếu fail — ghi `sync_status=failed` + `sync_error`, giữ log local.
- Trigger trong `⚡lead-form::addBookingLog()`: sau khi tạo booking_log, nếu `status=da_xac_nhan` → gọi `SbookingClient::pushBooking()`. Flash `cf_warn` nếu fail.
- Method mới `retrySbookingSync(int $bookingLogId)` — user bấm nút "🔄 Thử lại" trong badge sync.
- UI:
  - Đổi wording khối "Ghi nhận booking" — bỏ note "chỉ ghi log nội bộ", thay bằng giải thích rõ Data Source auto push khi status=Đã xác nhận.
  - Gỡ nút "Đặt booking" dropdown xanh cũ ở `⚡lead-form` footer + `⚡lead-detail` header (2 chỗ).
  - Badge sync ở mỗi booking_log: `synced` xanh + id + diffForHumans, `failed` đỏ + nút "🔄 Thử lại", `pending` amber.
  - Flash `cf_warn` amber (thêm mới).
  - `⚡booking-connection`: thêm cột "Sbooking co_so_id" (input number) vào bảng mapping cơ sở, save cùng `booking_co_so_slug` cũ.
- Model: `BookingLog` fillable + casts thêm `sbooking_booking_id`/`sync_status`/`sync_error`/`synced_at`. `Facility` fillable thêm `sbooking_co_so_id`.

### Design chốt (5 điểm B)
- **1a**: Không chọn phòng/khung giờ ở form CRM. Sbooking status=`cho_duyet`, lễ tân sbooking gán.
- **2a**: Không push update khi edit. Muốn sửa lịch thật → sang sbooking sửa tay.
- **3a**: Không push cancel. Cần cancel → sang sbooking cancel.
- **4-hack**: Thêm cột `facilities.sbooking_co_so_id` map tay. Phase C2 (facilities) sẽ auto sync sau.
- **5x**: Gỡ hoàn toàn nút "Đặt booking" cũ.

### Test
- `SbookingClientTest` (4 case, 12 assertions): push success + mark synced; fail khi facility chưa map; fail HTTP 500; map service.name → sb_service.sbooking_id. **4/4 pass** (Http::fake, cô lập).
- `SyncServicesFromSbookingTest` (4 case): pre-existing từ C1 sáng, vẫn pass.
- Full regression: 173/188 pass. 3 fail + 12 error đều **pre-existing** (đã ghi 2026-07-31): DistributionEngine notification, ProcessRawLead KH-001-MKT format, Hcm/BookingEvent/SyncFromBooking cần DB `lara_scrm` MySQL, SlaRecall sqlite NOT NULL. **Không có test nào tao gây fail.**
- E2E curl: POST /api/bookings với token → 201 `{id, ma_booking, khach_hang_id, trang_thai}`. Booking thật id=6 tạo bên sbooking.

### Config cần verify khi mày test tay
1. Vào `/settings/booking-connection` map từng cơ sở SCRM → `sbooking_co_so_id` (VD: HN → 1, HCM → 2, ĐN → 3). Bỏ trống = chỉ ghi log local.
2. Form Booking trong lead-form, chọn cơ sở đã map + status=`Đã xác nhận` + Thêm booking → booking sinh bên sbooking (mở URL `/lo23tdn/duyet-lich` để verify).
3. Chưa map → flash amber cảnh báo, badge `failed` đỏ với lý do, có nút "🔄 Thử lại".

### Chưa làm / dời lại
- **C1.c — Đổi dropdown chọn dịch vụ trong lead-form từ `Service` → `SbService`**: `⚡lead-form:2271` vẫn dùng `\App\Models\Service::where(...)`. `SbookingClient` hiện map best-effort theo `service.name → sb_services.ten` (không hoàn toàn chính xác). Muốn đổi thẳng sang `SbService` → đụng FK `booking_logs.service_id → services.id`. Cần chốt design (giữ FK cũ + thêm cột `sb_service_id` mới, hay drop FK + migrate). Task riêng, không block C1.b này.
- **C1.d — Auto push khi edit/cancel** (điểm 2/3 phương án b): giữ nguyên chốt "không push", làm sau nếu user thấy vướng.
- **Endpoint list co_so bên sbooking** — hiện map tay ID bằng số. Sau khi có endpoint `GET /api/sync/co-so` (Phase C2) → dropdown map thay input number.
- **Observer auto-push khi tạo dich_vu/co_so/bac_si bên sbooking**: chưa làm, dùng sync manual.

### Ghi chú kỹ thuật
- `SbookingClient::pushBooking` catch mọi exception → ghi vào `sync_error`. Không throw ra ngoài → save booking_log không rollback.
- `retrySbookingSync` chỉ push khi status=`da_xac_nhan` (không retry log status `cho_xac_nhan`/`huy_doi_lich`).
- Payload `crm_khach_ma` = `leads.code` (VD `KH-001-MKT`) → sbooking lưu vào `booking.crm_khach_ma`, dùng tracking 2 hệ sau này.
- Booking `trang_thai='cho_duyet'` + `da_duyet=false` — như tạo tay trong sbooking. Lễ tân sbooking duyệt qua `/lo23tdn/duyet-lich` như thường.

---

## 2026-08-01 — Phase C1: Sync dịch vụ sbooking → scrm ✅ (core)

Chi tiết plan: [plan-schema-unification.md](plan-schema-unification.md) §Phase C1. Master data 2 hệ chốt design: `services` (scrm, có giá + phase, phục vụ thu tiền + % đóng góp) giữ **nguyên**; data booking (dịch vụ / cơ sở / bác sĩ) master = **sbooking**, scrm mirror lại chỉ để làm dropdown ở phase booking.

### Design chốt sau khi bàn với user
- **Câu 1** (cột giá của scrm): giữ ở scrm-only. Không dời sang sbooking, không gộp bảng.
- **Câu 2** (co_so_id bên sbooking): giữ ở sbooking, mirror qua scrm kèm `sbooking_co_so_id`. Sbooking đang quản 4 cơ sở, sync sang phase booking scrm.
- **Câu 3** (map sb_services ↔ scrm.services): không cần. Sbooking chỉ chọn từ danh mục do sbooking quản, không link tới bảng giá bên scrm. Hai concept độc lập.

### Đã làm
- **scrm migration** `2026_08_01_120000_create_sb_services_table`: bảng `sb_services` (sbooking_id unique, sbooking_co_so_id nullable, ten, thoi_gian_phut, thuoc_nhom, la_dich_vu, active, synced_at). Idempotent theo sbooking_id.
- **scrm model** `App\Models\SbService` — Fillable + casts.
- **scrm command** `App\Console\Commands\SyncServicesFromSbooking` (`php artisan sb:sync-services [--dry-run]`): gọi `GET {BOOKING_API_URL}/sync/dich-vu` với `Http::withToken(BOOKING_API_TOKEN)`, upsert theo sbooking_id, log số tạo mới/cập nhật.
- **sbooking controller** `App\Http\Controllers\Api\SyncApiController::dichVu()` — trả JSON `{count, data:[…]}` toàn bộ `dich_vu`, order theo id.
- **sbooking route** `GET /api/sync/dich-vu` trong group middleware `scrm.token` (dùng lại token đã config Phase A/B).
- **scrm UI**: gộp vào trang **Thiết lập → Kết nối Booking** (`⚡booking-connection`) section mới "Đồng bộ dữ liệu từ Booking (Phase C1)": hiển thị `SbService::count()` + `synced_at` (`diffForHumans`), nút "🔄 Đồng bộ dịch vụ" gọi `Artisan::call('sb:sync-services')` + flash banner OK/Fail. Có `wire:loading` indicator.
- **Test** `SyncServicesFromSbookingTest` (4/4 pass, `Http::fake`): tạo mới, upsert idempotent, fail khi thiếu token, fail HTTP 401.
- **QA checklist**: thêm section "🔄 Phase C1 — Đồng bộ dịch vụ từ Sbooking" trong `docs/qa/qa_checklist.html` (9 case cả positive + negative).

### Config cần verify
- scrm `.env`: `BOOKING_URL=http://localhost:8001` + `BOOKING_API_TOKEN=…` (đã có từ Phase A/B).
- sbooking `.env`: `SCRM_API_TOKEN=…` khớp token trên (đã có).
- Nếu sbooking chạy port khác 8001, sửa `BOOKING_API_URL` trong scrm `.env` hoặc AppSetting `booking_url`.

### Chưa làm / dời lại
- **C1.b — Đổi dropdown chọn dịch vụ ở phase booking sang `sb_services`**: hiện `⚡lead-form.blade.php:2271` đang query `Service::where('active',true)->where('service_type',$newBookingType)`. Đổi sang `SbService` sẽ đụng FK `booking_logs.service_id → services.id` (có data thực). Cần chốt design C1.b trước: (a) thêm cột `booking_logs.sb_service_id` nullable song song, dropdown ghi vào cột mới, giữ FK cũ cho backward compat; hoặc (b) migrate hẳn FK sang `sb_services` (drop FK cũ, backfill service_id ↔ sb_services theo tên). Đề xuất (a) để không mất data booking cũ.
- **C1.c — Observer bên sbooking auto-push khi save dich_vu**: hiện chỉ có sync manual (bấm nút / CLI). Optional, làm sau nếu user thấy phải bấm nhiều.
- **C2 (facilities) + C3 (staff)**: chưa động. Khi làm C2 xong sẽ backfill `sb_services.facility_id` map từ `sbooking_co_so_id`.

### Ghi chú kỹ thuật
- Sync 1 chiều **sbooking → scrm**. Bên scrm KHÔNG được UI sửa `sb_services` (readonly mirror). Muốn thêm/sửa dịch vụ mới → làm bên sbooking, sync lại.
- Idempotent: chạy `sb:sync-services` bao nhiêu lần cũng cho cùng kết quả. Row đã tồn tại → update; row mới → create. Chưa xử lý soft-delete (nếu bên sbooking xóa dich_vu, bên scrm vẫn giữ). Nếu cần → thêm bước diff sau này.
- Test dùng `Http::fake()` — không đụng sbooking server. Chạy được cả khi sbooking offline.

---

## 2026-07-31 — Rebrand tele + filter Phase + assert booking-first + dimension phase cho báo cáo ✅

Nhánh `fifth`. Chốt bằng flow ảnh mới (7 bước, 7 nguồn — file `public/images/flow.jpg`), fix xong 4 mảng:

### Rebrand permission
- `lead.distribute_booking` → `lead.distribute_tele` (team booking cũ đã rename thành "tele" ở `2026_07_30_160000`; giờ permission cũng theo). Sed toàn repo 18 chỗ (`app/`, `database/seeders/`, blade components, test comment). Migration `2026_07_31_100000_rename_perm_distribute_booking_to_tele.php` UPDATE `permissions.key + label` in-place (giữ id + liên kết `permission_role`).
- Label mới: "Chia số ở kho Tele (CM team tele)"; ops-rules panel đổi nhãn "Chia số kho Tele (phase)".

### Dashboard + gate nút Thêm mới
- Thêm nút **Thêm mới khách hàng** (gold-600 solid) trong header `⚡dashboard-overview` bên cạnh nút Xem báo cáo. Gate `@can('lead.create')`.
- Verify rule chặn nguồn: form `⚡lead-form` đã dùng `Lead::allowedSourceGroupsFor()` map `SOURCE_PERMISSIONS` → Sale/Tele bấm nút vào form chỉ chọn được nguồn thuộc quyền (Sale = MKT_BR/SA, Tele = BA), không thấy MKT trong dropdown → OK, không cần sửa gì thêm.

### Filter Phase ở list Leads
- Thêm `public string $fPhase` + dropdown "Trạng thái xử lý" trong `⚡lead-list`. 5 options:
  - `waiting_tele` — Chờ chia — Tele (gate `lead.distribute_tele`)
  - `waiting_sale` — Chờ chia — Sale (gate `lead.distribute_sale`)
  - `in_care` — Đang care (pipeline_status=in_care, booking chưa book)
  - `booked` — Đã đặt booking
  - `checkin` — Đã check-in (khach_da_toi/khach_toi_tre/da_xong)
- Sale/Tele thường **không** thấy 2 options waiting_* (không có perm distribute_tele/sale). Query mapping vào `filteredQuery()` bằng match, đã kết hợp với `visibleTo($user)` scope.

### Assert cứng: booking_status=booked mới cho handoff Sale
- `Lead::moveToSaleWaiting()` throw `DomainException` nếu `booking_status !== BOOKING_BOOKED`. Áp cho **mọi caller**.
- Caller ở `⚡lead-detail::moveToSalePhase()` bắt exception, flash `cf_error` thay vì 500.
- Nguồn direct-sale (BOD/SA/BA/WI) vẫn giữ luồng `initialPipelineFor` cũ (owner=sale ngay khi tạo) — chỉ chặn bước handoff booking→sale (phương án 2 mày chốt).

### Dimension Phase cho báo cáo Funnel + Hiệu suất sale
- `stats_daily` + cột `pipeline_phase` (nullable, sau ad_source); update unique index bao thêm cột này. Migration `2026_07_31_100100_add_pipeline_phase_to_stats_daily.php`.
- `StatsAggregator::aggregateDay` group thêm chiều `leads.pipeline_phase` ở cả funnel + revenue; `key()` thêm phase. Data cũ backfill=null → cần chạy lại `stats:aggregate` cho khoảng ngày muốn số liệu chuẩn.
- `⚡report-center` thêm `public string $fPhase`; `scopedStats` where theo phase khi != ''. UI: dropdown Phase (Tất cả / Booking (Tele) / Sale) chỉ hiện ở section=overall + tab funnel/performance.

### Test + kết quả
- Test mới `MoveToSaleWaitingTest` — 2/2 pass:
  - throws khi `booking_status = not_booked`
  - passes khi = `booked` (phase → sale, status → waiting_distribute, owner → null)
- Regression suite (`CustomerFlow621|DistributionEngine|LeadScope|LeadListActions|AccessControl`): 56/57 pass. 1 fail `DistributionEngineTest::test_full_flow_common_to_team_to_user` là assertion notification, không liên quan.
- `Lead*` filter suite: 26/28. 1 fail có sẵn (`ProcessRawLeadTest` KH-001 vs KH-001-MKT), 1 error (BookingEventEndpointTest chạy mysql thay sqlite) — cả hai đã có trước lần này.

### Chạy migrate + seed + aggregate (2026-07-31 chiều)
- `php artisan migrate` — 2 migration mới OK: `2026_07_31_100000_rename_perm_distribute_booking_to_tele` (79.81ms), `2026_07_31_100100_add_pipeline_phase_to_stats_daily` (2s). Migration lần đầu lỗi vì tao `->after('ad_source')` — cột đã bị drop bởi `2026_07_20_140000`. Fix: đổi `->after('camp')`.
- `php artisan db:seed --class=PermissionSeeder` — updateOrCreate không lỗi.
- `db:seed --class=DemoDataSeeder` — thêm 2 nhóm lead mới (Phase 4 Booked + Phase 5 Checkin), mỗi nhóm 2 lead. Idempotent qua `firstOrCreate` theo phone `0917500001-2`, `0917600001-2`.
- `stats:aggregate --from=2026-07-01 --to=2026-07-31`: re-aggregate 31 ngày → 17 dòng `pipeline_phase=sale`, 3 dòng `pipeline_phase=booking`, không còn NULL. Filter Phase ở báo cáo giờ có số thật.

### Full test suite
- 145 test / 130 pass. 3 fail + 12 error đều pre-existing (verify bằng stash pop): `DistributionEngineTest` notification, `ProcessRawLeadTest` KH-001-MKT format, Hcm/BookingEvent/SyncFromBooking không có DB `lara_scrm`, `SlaRecallTest` sqlite NOT NULL. Không liên quan changes lần này.
- `MoveToSaleWaitingTest` (mới) 2/2 pass.

### QA từng role (script tinker, đọc thẳng DB thực)

| Role | Nút Thêm KH | Nguồn được up | Options filter Phase | Total leads thấy | waiting_tele | waiting_sale | in_care | booked | checkin |
|---|---|---|---|---|---|---|---|---|---|
| **Trực Page** (`hn.page01`) | HIỆN | mkt | waiting_tele \| in_care \| booked \| checkin | 0 | 0 | 0 | 0 | 0 | 0 |
| **CM Tele** (`dn.cms01`) | HIỆN | mkt_br,sa,ba | waiting_tele \| waiting_sale \| in_care \| booked \| checkin | 16 | 1 | 0 | 15 | 0 | 0 |
| **Team Tele** (`hn.book01`) | ẨN | ba | in_care \| booked \| checkin | 6 | 0 | 0 | 5 | 1 | 0 |
| **CM sale** (`dn.cms01` — trùng user #11) | HIỆN | mkt_br,sa,ba | waiting_tele \| waiting_sale \| in_care \| booked \| checkin | 16 | 1 | 0 | 15 | 0 | 0 |
| **Team sale ĐN** (`dn.sale01`) | HIỆN | mkt_br,sa | in_care \| booked \| checkin | 0 | 0 | 0 | 0 | 0 | 0 |
| **Observer** (`vh.obs01`) | ẨN | (trống) | in_care \| booked \| checkin | 26 | 1 | 1 | 22 | 2 | 0 |

Kết luận:
- **Rule chặn nguồn theo role**: Trực Page chỉ `mkt`; Team Tele chỉ `ba`; Team sale ĐN chỉ `mkt_br,sa`; Observer không up được — đúng thiết kế.
- **Ẩn P2 với role không có perm distribute_***: Team Tele + Team sale ĐN + Observer không thấy `waiting_tele/waiting_sale` — đúng.
- **Đảo lại: Trực Page HIỆN option `waiting_tele`** vì role được gán `lead.distribute_tele` (tồn dư từ OrgStaffSeeder). Cần user chốt: Trực Page có phải là vị trí kiêm chia số kho Tele không? Nếu không → gỡ perm này khỏi role Trực Page (1 dòng ở `OrgStaffSeeder.php:157`). Tao **không tự sửa** vì chưa rõ intent.

### Tạo 3 tài khoản Admin cơ sở (2026-07-31 chiều)
Migration `2026_07_31_110000_seed_admin_co_so_role_and_users.php`:
- Role mới **"Admin cơ sở"** — union quyền up mọi nguồn (`source.up.*` + `lead.source_all`) + chia số giống CM (`view_pool`, `distribute`, `distribute_tele`, `distribute_sale`, `distribute_to_team/sale`, `recall`, `pull_pool`) + basic lead + phase.close.1..5 + payment/report. **KHÔNG** có org/user/role/service/rule.manage.
- 3 user:

| Username | Email | Branch | Password | Lead visible |
|---|---|---|---|---|
| admin.hn | admin.hn@longevity.com.vn | branch-hn | `<pass-hn>` | 40 |
| admin.hcm | admin.hcm@longevity.com.vn | branch-hcm | `<pass-hn>` | 18 |
| admin.dn | admin.dn@longevity.com.vn | branch-dn | `<pass-hn>` | 16 |

- Scope `SCOPE_TEAM` (subtree branch). Verify: cả 3 up được đủ 7 nguồn (mkt/mkt_br/bdm/bod/sa/ba/wi), thấy đủ 5 options filter Phase (waiting_tele/waiting_sale/in_care/booked/checkin).
- `RenameUsersToPositionFormatSeeder` không đụng vì role "Admin cơ sở" không có trong ROLE_MAP → username giữ nguyên `admin.hn/hcm/dn`.

### Align perm 3 role theo flow mới (2026-07-31 chiều)
Migration `2026_07_31_120000_align_role_perms_with_flow.php`:

| Role | Trước | Sau |
|---|---|---|
| **Trực Page** | có `lead.distribute_tele + distribute_to_team + distribute_to_sale` | **gỡ** 3 perm này (Trực Page chỉ up, CM/Admin chia) |
| **Team Tele** | không có `lead.create` | **thêm** `lead.create` (Tele tự up BA — ưu tiên 1) |
| **CM sale** | không có `book_action`, không up được WI | **thêm** `lead.book_action` + `source.up.admin` (CM sale kiêm sale trực tiếp cho SA/BDM/BOD/WI, cần tự đặt lịch) |

Verify sau fix:

| Role | Nguồn up | Thêm KH | book_action | filter Phase |
|---|---|---|---|---|
| Trực Page | mkt | HIỆN | KHÔNG | in_care \| booked \| checkin |
| Team Tele | ba | HIỆN | CÓ | in_care \| booked \| checkin |
| CM sale | mkt_br, bdm, bod, sa, ba, wi | HIỆN | CÓ | waiting_tele \| waiting_sale \| in_care \| booked \| checkin |

Đồng bộ `OrgStaffSeeder.php` 3 dòng perms tương ứng để re-seed lần sau vẫn đúng.

Câu 2 (SA up bởi Sale + Admin) — verify lại: đã đúng, không cần fix. SA hiện map `source.up.sale`; Sale + CM sale + Admin cơ sở + Admin/DM đều có perm này.

### Checklist E2E tổng hợp (2026-07-31 chiều — sau align perm)
Script tinker `qa_matrix.php` — 7 role × 4 màn HTTP × matrix nguồn × assert booking-first.
**Kết quả: 38/38 PASS** (3 case 403 là expected — permission chặn đúng ở route level).

| Role | /dashboard | /leads | /leads/create | /reports | Nguồn up (7) | Filter Phase (5) | Assert booking-first |
|---|---|---|---|---|---|---|---|
| Admin cơ sở HN | 200 ✅ | 200 ✅ | 200 ✅ | 200 ✅ | 7/7 | 5/5 | throw ✅ · booked→sale ✅ |
| Trực Page | 200 ✅ | 200 ✅ | 200 ✅ | 403 🔒 | mkt (1/7) | 3/5 | N/A |
| CM Tele | 200 ✅ | 200 ✅ | 200 ✅ | 200 ✅ | mkt_br,bdm,bod,sa,ba,wi (6/7) | 5/5 | throw ✅ · booked→sale ✅ |
| Team Tele | 200 ✅ | 200 ✅ | 200 ✅ | 403 🔒 | ba (1/7) | 3/5 | throw ✅ · booked→sale ✅ |
| CM sale | 200 ✅ | 200 ✅ | 200 ✅ | 200 ✅ | mkt_br,bdm,bod,sa,ba,wi (6/7) | 5/5 | throw ✅ · booked→sale ✅ |
| Team sale ĐN | 200 ✅ | 200 ✅ | 200 ✅ | 200 ✅ | mkt_br,sa (2/7) | 3/5 | throw ✅ · booked→sale ✅ |
| Observer | 200 ✅ | 200 ✅ | 403 🔒 | 200 ✅ | 0/7 | 3/5 | N/A |

**3 case 403 (expected):**
- Trực Page → /reports: không có `report.view` ✓
- Team Tele → /reports: không có `report.view` ✓
- Observer → /leads/create: không có `lead.create` ✓

**Assert booking-first (5 role có lead.update, 10 case)**: 10/10 pass — throw khi `booking_status=not_booked`, handoff thành công khi `=booked`.

### Livewire matrix test (RolePermissionMatrixTest) — 35/35 PASS
Feature test data-driven `#[DataProvider('matrixProvider')]` phủ **5 role × 7 nguồn = 35 case**. Với mỗi case: mount `Livewire::test('leads.lead-form')` actingAs user, set sourceGroup, call save, assert:
- Role được phép up nguồn → **assertHasNoErrors('sourceGroup')**.
- Role không được phép → **assertHasErrors('sourceGroup')** + `assertDatabaseMissing('leads', ...)`.

Bảng expected (đồng bộ với `Lead::SOURCE_PERMISSIONS` + role perms hiện tại):

|  | mkt | mkt_br | sa | ba | bdm | bod | wi |
|---|---|---|---|---|---|---|---|
| Trực Page | ✓ | – | – | – | – | – | – |
| Team Tele | – | – | – | ✓ | – | – | – |
| Team sale | – | ✓ | ✓ | – | – | – | – |
| CM sale | – | ✓ | ✓ | – | ✓ | ✓ | ✓ |
| Admin cơ sở (source_all) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

Regression full: 99/100 pass. 1 fail pre-existing (DistributionEngineTest notification, không liên quan).

### Password mặc định theo cơ sở (2026-07-31 cuối ngày)
User đề xuất: password dùng địa chỉ cơ sở cho dễ nhớ, mỗi cơ sở 1 pass.

| Prefix email | Password | Địa chỉ / nguồn |
|---|---|---|
| `hn.*` + `admin.hn` | `<pass-hn>` | 59 Ngô Thì Nhậm (HN) |
| `hcm.*` + `admin.hcm` | `<pass-hcm>` | 207 Nguyễn Văn Thụ (HCM) |
| `dn.*` + `admin.dn` | `23@tdn` | Lô 2+3 Trần Đăng Ninh (ĐN) |
| `vh.*` + `admin` | `59ntn` | Vận hành (không có @) |

**Thực hiện:**
- Tạo helper `App\Support\DefaultPassword` — `::forEmail($email)` resolve theo prefix; `::HN/HCM/DN/VH` const cho seeder biết cứng branch.
- Migration `2026_07_31_130000_reset_default_passwords_per_facility.php` — UPDATE toàn bộ user hiện có (chunk 200) theo pattern email, bỏ qua `bs./ktv./dd.` sync từ sbooking. Chạy 11s.
- Sửa các seeder + migration khác dùng helper: `OrgStaffSeeder`, `DemoDataSeeder`, `HcmTestFlowSeeder`, `HnDnTestFlowSeeder`, `TeamHoiStaffSeeder`, `Phase66FlowSeeder`, `RealCmStaffSeeder` (bỏ const `PASSWORD`), migration Admin cơ sở (`2026_07_31_110000`). Re-seed sau không lệch.
- `SyncCrmAccountsSeeder` giữ nguyên (đang đồng bộ với sbooking, không thuộc scope CRM).
- Update 2 HTML docs (qa_checklist + huong_dan_van_hanh) — hiển thị bảng password theo cơ sở.

**Verify** (10 case tinker): 10/10 pass — mọi user check password khớp cơ sở.

### Chưa làm / dời lại
- Guide page (`resources/views/guide.blade.php`): mới thay ảnh `flow.jpg`; text 4 role (CM/Booking/Sale/Observer) + 3 section phụ (Thu hồi/Đặt booking/Đà Nẵng) chưa viết lại theo vị trí mới (Trực Page/Tele/QL Sale/Sale + 7 nguồn). Chờ mày chốt scope trước khi rewrite.
- Nếu Trực Page không được kiêm chia số → gỡ `lead.distribute_tele` ở `OrgStaffSeeder.php:157` + migration UPDATE detach cho role hiện tại. Chờ mày chốt.

---

## 2026-07-29 — QA bug bash: import/permission/phase/payment/booking ✅

Bug bash 1 ngày qua flow trực page → booking → sale → thu tiền. Bắt được 12 bug, fix hết, đã push lên `fourth`.

### Sửa middleware / permission
- `EnsurePermission::handle(string $permission)` chỉ nhận key đầu → Laravel tách `permission:a,b` thành 2 arg → key thứ 2 bị bỏ qua. Route `/reports` khai `permission:report.view,report.view_all` ngầm chỉ check `report.view` bao lâu nay. Fix: đổi thành `string ...$permissions`. Thêm feature test 6 case (`EnsurePermissionMiddlewareTest`). Commit `bebd84c`.
- `/leads/*` group cũ gate `permission:lead.view` khóa cả nút Import ra ngoài scope trực page. Đổi thành `permission:lead.view,lead.import`; menu "Danh sách khách hàng" dùng `hasAnyPermission(['lead.view','lead.import'])`. Commit `bebd84c`.

### Người nhập lead (imported_by) — thấy vĩnh viễn data mình up
- Trước: trực page import xong lead vào kho chung → engine chia sale → mất scope xem → tưởng mất data.
- Thêm cột `leads.imported_by` (FK users, nullable, index). Migration + backfill cross pgsql/mysql qua `raw_leads.clean_lead_id` ∪ `import_batches.uploaded_by`.
- `ProcessRawLead` set `imported_by = batch.uploaded_by`.
- `form.createLead` (nhập tay) cũng set `imported_by = auth->id` cho user tạo lead giữ scope xem.
- `Lead::scopeVisibleTo` + `isVisibleTo`: thêm nhánh `imported_by = user`. SĐT unmask tự động qua `canViewFullPhone`.
- Seed role "Team nhập lead" bổ sung `lead.import`, `lead.view`, `lead.distribute_to_team`, `lead.distribute_to_sale`; `canDistributeLead` gate scope theo `imported_by` (chỉ chia được lead chính mình up, lead nguồn khác vẫn chờ CM).
- Lead detail: block **Người phụ trách** (`Lead::handlerTrio()`) hiển thị 3 slot Nhập / Booking / Sale theo phase; nguồn direct-sale hiển thị "Không qua booking"; kho chung + chưa chia → cả 3 slot đều `—` (tránh show booking từ `receiver_id` residual).
- Commit `850088b`, `f2c34dc`, `d611c08`, `a51ade5`.

### Luồng phase Booking → Sale + badge
- `ProcessRawLead` không set `source_group`/`pipeline_phase` → lead marketing default DB `phase=sale`, sai luồng. Fix: default source_group=MKT + gọi `Lead::initialPipelineFor()`.
- `DistributionEngine::manualAssign` giữ `pipeline_status=WAITING` sau khi đã có owner → badge "Chờ CM chia" giả. Fix: chuyển WAITING → IN_CARE trong cùng update.
- `Lead::moveToSaleWaiting()` chỉ đổi phase, không reset owner/pool → CM sale không thấy lead trong pool để chia tiếp. Fix: đưa về POOL_COMMON, owner=null, receiver_id=user booking cũ (giữ lịch sử), org_unit_id=null, assigned_at=null.
- `Lead::pipelineLabel()`: chỉ badge "Kho chung · Chưa chia" cho lead mới (receiver_id null). Lead đã qua booking rồi về common → giữ label phase-based ("Sale · Chờ CM sale chia").
- Commit `a51ade5`, `2b6f53b`.

### Thu tiền
- Trước: chỉ Admin + 2 IT demo + DM HCM có `payment.record`. Sale (owner khách) không thu được → data doanh thu lệch báo cáo.
- Cấp `payment.record` cho: Sale, Team sale, Team sale ĐN, CM sale (duyệt/ghi hộ), CM booking (thu deposit nếu áp dụng), Team Leader. Giữ nguyên: Team booking / Trực page / Observer / Trợ lý KD (không thu).
- E2E verify: sale01 gắn dịch vụ 10M + thu 3M → CS.totalPaid=3M, Payment record đủ.
- Commit `fe36022`.

### UX / i18n / cleanup
- Nút Import trong màn Lead list gate theo `lead.import`.
- Sheet "DS Sale" trong file mẫu Excel: liệt kê sale active + phòng/team + email, bôi vàng tên trùng, sheet "Hướng dẫn" nhấn ưu tiên điền email khi có nhiều người cùng tên. Cột CHIA CHO nhận cả tên & email; `resolveOwner` khớp email exact (case-insensitive) trước, fallback logic tên cũ.
- `updatedSelectedOrgTemplate` hook: đổi team sau upload sẽ tự re-init mapping + auto-guess.
- Map cột lọc field theo team đã chọn (`CustomField::applicableTo($org)`) — không còn hiện field của team khác.
- Preview có dòng đỏ cảnh báo cột không map sẽ bị bỏ.
- Redirect sau import về chính trang Import (thấy Lịch sử batch), thay vì /leads/index trống.
- `symlink public/storage` đã tạo (`storage:link`) — trước thiếu → ảnh note upload sẽ 404.
- Publish `lang/vi/validation.php` + `APP_LOCALE=vi` — validation message trước trộn tiếng Anh/Việt.
- Block "Thêm Ghi chú" ẩn khi user không có `lead.update`.
- Menu Cài đặt gate `hasAnyPermission($adminPerms)`.
- `/distribution/pools`: nút chia + checkbox chọn gate per-lead theo `canDistributeLead` để tránh 403 khi bấm nhầm.
- Commit `adabccd`, `4c49289`, `1d87d8b`, `72b5447`, `90e4427`, `3e81387`, `646b6b6`, `ebae219`.

### Test end-to-end đã cover
- **Import Excel**: trực page HN import 3 lead → phase=booking + kho chung + imported_by=user. Chia 2/3 cho hn.book01 → booking sửa info khách → bấm Chuyển sang Sale → CM sale thấy trong pool → chia cho hn.sale01 → sale ghi note. Trio đầy đủ 3 người.
- **Nhập tay**: sale/CM booking/CM sale/trực page mỗi role tạo tay 1 lead → verify phase/pool/owner/source_group/imported_by đúng theo `initialPipelineFor`. Duplicate SĐT bị chặn 2 mức (banner + field error). Validation 3 field required chặn đúng. Custom field required theo team gate qua `validateCustomFields()`.
- **Chia + reassign + thu hồi**: manualAssign đổi owner, log manual_assign đầy đủ; recall thủ công đưa lead về POOL_TEAM; SLA `leads:process-recalls` auto-recall theo `recall_at` + conditions (no_activity/no_booking/no_progress).
- **Booking sync**: callback GET → set booked+booking_ma+classification=booking. Webhook POST đủ 3 event `status` (booked→khach_da_toi→da_xong), `comment`, `edit` — đều ghi note + LeadStatusLog + AuditLog + notification.
- **Role scan** 9 role (page01/book01/cmb01/sale01/cms01/tl01/dm-hcm/observer/tlkd01/admin) qua 13 route: gate 200/403 khớp perm, không có 500.
- **Export**: admin export 42 lead → AuditLog `action=export`. Sale/CM/booking không có `lead.export` → 403.
- **Backup**: config export ra JSON (meta+tables, 24 table), self-import idempotent (0 add/update/delete, 0 errors). Full backup ZIP 95KB chứa data khách/DV/thanh toán/logs.

### Ghi chú & rủi ro
- `pipeline_phase` cho lead import default MKT → nếu công ty thực tế có nguồn khác cho luồng import (VD BOD import batch) cần thêm dropdown "Nhóm nguồn" ở màn Import hoặc set qua payload `source_group`.
- `lead.export` chỉ Observer + Admin có — sale/CM chưa có (chốt sau nếu cần cho báo cáo tay).
- Bug UX nhỏ: nút "Đặt booking" ẩn hoàn toàn khi lead chưa gắn facility → user không biết thiếu gì. Cân nhắc show disabled + tooltip "Chưa gắn cơ sở".
- Flash message chia số hiển thị "Đã chia {tên khách} cho {sale}" — khi tên khách bắt đầu bằng "CM..." đọc lên nghe funky ("chia tay"). Không phải bug, chỉ trùng ngữ.

## 2026-07-28 — Chuyển sang mô hình 7 nguồn khách + rename Team Trực Page ✅
- **Cũ (6 nguồn)**: marketing / data_cold / bdm / referral / ctv / walk_in.
- **Mới (7 nguồn)** — chia 3 luồng:
  - Nhóm 1 (qua Team Booking): **MKT / MKT BR / BDM**
  - Nhóm 2 (lối tắt qua CM Sale): **BOD / SA / BA**
  - Nhóm 3 (walk-in): **WI**
- **Đã làm**:
  - `Lead` model: đổi 7 constants + SOURCE_GROUPS + SOURCE_GROUP_CODES + SOURCE_PERMISSIONS; `isDirectSaleSource()` cover BOD/SA/BA/WI; `initialPipelineFor()` route Nhóm 1 → PHASE_BOOKING, còn lại → PHASE_SALE.
  - Migration `2026_07_28_120000_...`: xóa lead cũ data_cold/referral/ctv, rename marketing→mkt / walk_in→wi, đổi comment cột, rename org_units + role "Team trực page" → "Team nhập lead", xóa perm `lead.distribute_ctv`.
  - Đổi tên **Team Trực Page → Team Nhập Lead** ở toàn bộ hệ thống: OrgStaffSeeder (code + name), Phase66FlowSeeder, RenameUsersToPositionFormatSeeder, guide.blade, ⚡lead-form, ⚡lead-pools, ⚡ops-rules, routes/web.
  - Cập nhật `MarkOverdueBookingLeads`, `RecallIdleBookingLeads` (comment), DemoDataSeeder (seed 7 nguồn mới ở kho chung + team pool), RealCmStaffSeeder, PermissionSeeder (bỏ `lead.distribute_ctv`), OrgAndRoleSeeder.
  - Cập nhật tests: rename mkt/wi trong H1MktFlowTest, T1MktFlowTest, T5WalkInFlowTest, D1FullFlowTest, Phase66FlowsTest, Phase66JobsTest, T7PermGatesTest; XÓA T2ColdFlowTest, T4ReferralFlowTest, H4ReferralFlowTest (nguồn không còn); sửa 2 test Feature dùng `'referral'` → `'bod'`.
  - **lara-sbooking**: cập nhật dropdown `nguon` ở 2 view create (booking + lịch hẹn) sang 7 mã mới. `nguon` vẫn là string tự do (chưa enum-hóa).
- **Kiểm tra sau migration**:
  - `Lead::selectRaw('source_group, count(*)')->groupBy(...)` → `{bdm:6, mkt:21, wi:5, mkt_br:3, bod:1, ba:1}` (seed đã bơm nguồn mới, dữ liệu cũ đã rename).
  - OrgUnit codes: `team-nhap-lead`, `team-nhap-lead-hcm`, `team-nhap-lead-dn` (name = "Team Nhập Lead").
  - Role: "Team nhập lead" ✓
- **Ghi chú & rủi ro**:
  - Data cũ `data_cold`, `referral`, `ctv` đã **xóa hẳn** (theo yêu cầu user "cái cũ xóa đi"). Nếu cần khôi phục → restore từ backup.
  - Sbooking `nguon` vẫn free-text — data cũ ("Fanpage", "Hotline", …) không bị đụng, chỉ dropdown mới cho phép chọn 7 mã.
  - Tests browser đã update nhưng **chưa chạy** (cần Dusk env) — Phase 8 QA sẽ verify.
  - BDM subtype (BDM_BIDV, …) chưa có trường tùy biến sẵn — cần user tự add ở Trường tùy biến cấp Team Nhập Lead khi cần.
- **Rà soát vòng 2 (cùng ngày)**:
  - DemoDataSeeder chuyển `firstOrCreate` → `updateOrCreate` cho khối demo 7 nguồn, đảm bảo re-seed đè source_group đúng mô hình mới. DB verify sau seed: `mkt(21) mkt_br(3) bdm(6) bod(1) sa(1) ba(1) wi(4)` — đủ 7 nguồn.
  - PermissionSeeder: label `lead.approve_source` đổi "Khách tự đến" → "Walk-in (WI)".
  - Trang Duyệt (⚡lead-approvals + settings/approvals): tiêu đề + mô tả đổi "Duyệt Khách tự đến" → "Duyệt Walk-in (WI)".
  - Lara-sbooking seeders (LichThang6, LichTuVanThang6, LichDatMau): danh sách `$nguons` cập nhật thành 7 mã mới.
  - Grep final `SOURCE_MARKETING|SOURCE_CTV|Team trực page|team-truc-page|Cộng tác viên|Bạn giới thiệu|Data lạnh|distribute_ctv` ở scrm ngoài migration đã tạo: **sạch**.
  - Grep sbooking `Fanpage|Hotline|Website|Khách quen|Giới thiệu|Trực tiếp` trong views/app: **sạch**.
  - Verify runtime: `SOURCE_GROUPS` / `SOURCE_GROUP_CODES` / `SOURCE_PERMISSIONS` khớp 7 nguồn; `isDirectSaleSource()` trả true cho BOD/SA/BA/WI, false cho MKT; `initialPipelineFor()` route đúng phase.

## 2026-07-28 — Pass 2: hoàn thiện seed + hiển thị 7 nguồn cả 2 app ✅
- **SCRM**: đổi `DemoDataSeeder` từ `firstOrCreate` → `updateOrCreate` cho 7 lead demo → seed re-run áp đúng nguồn mới cho phone conflict cũ. Kết quả DB: đủ 7 nguồn `{mkt:21, mkt_br:3, bdm:6, bod:1, sa:1, ba:1, wi:4}`.
- **SBOOKING**:
  - `PageController::bookings` + `LichHenController::list` — dropdown filter `nguons` prepend 7 mã master + union distinct DB (giữ tương thích data cũ "Fanpage"/"Hotline").
  - 3 seeder (`LichThang6Seeder`, `LichTuVanThang6Seeder`, `LichDatMauSeeder`): đổi field `nguon` từ sentinel `seed-t6`/`seed-tv-t6`/`seed` → random 1 trong 7 mã thật. Marker cleanup chuyển sang `ghi_chu LIKE '[seed-…]%'`, giữ fallback query cũ để dọn data legacy.
  - Sau re-seed: 1199 booking + 1608 lich_hen phân bố đều 7 nguồn (145–245 record/nguồn).
- **Sweep cuối**: 0 ref cũ trong scrm/app|resources|database|routes|scripts|tests và sbooking/seeders|resources. Kịch bản seed idempotent 2 chiều (data legacy + data mới).

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

## 2026-07-22 — Phase 6.20: Tách quyền booking (read/write/book_action) + phân phối theo cấp pool
- **Vấn đề**:
  1. Team booking hiện có `lead.update_booking` → sửa được info khách; yêu cầu chuyển sang **chỉ đọc** ở màn Cập nhật, chỉ được bấm nút Đặt booking.
  2. Nút "Đặt booking" (mở lara-sbooking) chỉ có ở màn Chi tiết; user booking phổ thông cần thấy cả ở màn Cập nhật.
  3. Chia số hiện chỉ 1 perm `lead.distribute` — không phân biệt được ai chia kho công ty→team (CM cơ sở) vs ai chia kho team→sale (CM team). Muốn tách để một số cơ sở chưa có CM team, cấp cả 2 perm cho CM cơ sở.
- **Sửa**:
  - PermissionSeeder + migration `2026_07_22_120000_split_booking_and_distribute_perms`: thêm 4 perm mới `lead.read_booking`, `lead.book_action`, `lead.distribute_to_team`, `lead.distribute_to_sale`.
  - Migrate role: Team booking bỏ `lead.update_booking`, thêm `read_booking` + `book_action`. CM booking / CM sale / TL / Manager / DM HCM / Admin auto-attach các perm mới tương ứng (idempotent, `syncWithoutDetaching`).
  - `Lead.php`: thêm `canOpenEditForm()` (mở Cập nhật readonly) + `canBookAction()`.
  - `lead-form.blade.php`: tính `$canWrite` / `$isReadonly` / `$canBookAction`, wrap toàn form trong `<fieldset :disabled>`, ẩn nút "Lưu" khi readonly, hiện banner xanh "chế độ chỉ đọc", thêm nút Đặt booking ở header + footer (chỉ khi phase Booking + có `book_action`), gate `save()` server-side bằng `canEditPersonalInfo()`.
  - `lead-detail.blade.php`: `$canEditPersonalInfo` gate dùng `canOpenEditForm()` (Team booking vào được form readonly); `$canMoveToSale` gate dùng `canBookAction()`.
  - `lead-pools.blade.php`: helper `canDistributeLead($lead)` — kho company cần `distribute_to_team` (hoặc `lead.distribute` compat); kho team cần `distribute_to_sale` (hoặc compat). Áp cho tất cả actions (auto/start/confirm assign/pool, bulkAssign, bulkPool). `canDistribute` UI đổi sang `hasAnyPermission([distribute, distribute_to_team, distribute_to_sale])`.
  - `ops-rules.blade.php`: thêm 4 dòng hiển thị ai có `read_booking` / `book_action` / `distribute_to_team` / `distribute_to_sale`.
- **Chạy**: `php artisan migrate` + `php artisan view:clear`.
- **Verify**:
  - Team booking user `book1@longevity.com.vn`: `update_booking=N | read_booking=Y | book_action=Y` ✓
  - Migration idempotent (updateOrCreate perms, syncWithoutDetaching cho existing roles) — không mất perm khác đã cấp tay.
- **Chưa làm (dời)**:
  - Chưa cập nhật `scope.md` / `plan.md` — sẽ đồng bộ ở lần commit doc.
  - Chưa viết test tự động cho readonly form + book_action gate — cần bổ sung ở QA phase.
  - Q4 gate ở `lead-pools` giữ `lead.distribute` là fallback compat; nếu về sau muốn strict thì phải rà lại các role vẫn còn `lead.distribute` và quyết định giữ/gỡ.

## 2026-07-30 — Q&A thiết kế Customer Flow (7 phase) + tab Chi tiết KH

**Bối cảnh**: họp thống nhất mô hình Customer Flow 7 bước = 7 trạng thái lifecycle của lead/khách. Cần cập nhật `scope.md` + `ERD.md` trước khi code.

### Chốt được
1. **Customer Flow = 7 phase, gắn vào khách hàng** (là trạng thái lifecycle của lead):
   1. Thêm mới khách hàng
   2. Chia số
   3. Gọi điện → có sub-status: Thành công / Thất bại / Không nghe máy
   4. Booking thăm khám → có sub-status: Đã xác nhận / Chờ xác nhận / Hủy - Đổi lịch
   5. Check-in
   6. Bán hàng *(chưa build — bước 5 tạm thời là bước cuối)*
   7. Sử dụng dịch vụ *(chưa build)*

2. **Điểm bắt đầu theo nguồn** (bắt buộc, dựa ma trận chia số 7 nguồn):
   - `MKT` → bắt đầu phase 1 (Trực Page nhận), chạy full 4 bậc Trực Page → Tele → QL Sale → Sale.
   - `MKT BR` → nhảy thẳng Sale (bỏ Trực Page/Tele/QL Sale).
   - `SA`, `BDM`, `BOD`, `Walk IN` → QL Sale nhận trực tiếp.
   - `BA` → Tele nhận trực tiếp.
   - Các bước bị skip ẩn hẳn trên UI (không hiển thị "bỏ qua"). Trường thông tin đóng/mở linh hoạt theo phase hiện tại.

3. **Chuyển phase = tuần tự**, không nhảy cóc. Điều kiện chuyển: bấm nút **"Kết thúc phase X"** ở cuối mỗi phase.

4. **Lùi phase**: có, nhưng **CHỈ role "Admin vận hành"** được lùi. User thường không thấy nút này.

5. **Phân quyền chốt phase — TÁCH RIÊNG từng bước** để linh hoạt sau này:
   - `phase.close.new` (chốt phase Thêm mới)
   - `phase.close.distribute` (chốt phase Chia số)
   - `phase.close.call` (chốt phase Gọi điện)
   - `phase.close.booking` (chốt phase Booking)
   - `phase.close.checkin` (chốt phase Check-in)
   - (bán hàng / dịch vụ để sau)
   - Mặc định: gán hết cho role "Admin vận hành". Sau có thể cấp lẻ cho các role khác.

6. **Lịch sử gọi điện / booking**: mỗi lần gọi / mỗi lần booking → **tạo 1 record riêng** (không đè). Có bảng `call_logs` + `booking_logs`. Lý do: Tele gọi 3-5 lần/khách là bình thường, cần log để QA.

7. **Tab Chi tiết KH — đổi thứ tự + tách thẻ Phân phối**:
   `Phân phối | Trạng thái | Tư vấn | Liệu trình | Tiềm năng | Insight`
   ("Phân phối nguồn" đang nằm trong Trạng thái → tách ra tab riêng đứng đầu.)

8. **Thanh Customer Flow trên UI**: đặt dưới thanh trạng thái ("Sale - Chờ CM chia"), style **arrow-breadcrumb** giống AMIS CRM (bước xong xanh + tick, bước hiện tại highlight, bước chưa tới xám). Bước bị skip theo nguồn ẩn hoàn toàn.

### Chưa chốt — chờ user (đang hỏi)
- Mapping cụ thể: mỗi nguồn khởi tạo lead ở **phase index** nào? (VD MKT BR khởi tạo ở phase 3 "Gọi điện" hay nhảy thẳng phase 4?)
- Quyền "cập nhật trạng thái Gọi điện / Booking" (tạo record call_log/booking_log): chỉ người đang giữ lead, hay cả QL Sale / Admin vận hành?
- 1 khách có thể có nhiều "lifecycle" (quay lại sau bán hàng) hay 1 khách = 1 phase duy nhất tại 1 thời điểm?

## 2026-07-30 — Phase 6.21: Customer Flow 7 phase (lifecycle + tab UI)
- **Bối cảnh**: sau họp thiết kế cùng ngày (xem block Q&A ở trên), triển khai mô hình lifecycle 7 phase = trạng thái khách hàng, thay 2-phase cũ ở lớp UI + phân quyền chốt phase. Field cũ (`pipeline_phase`, `pipeline_status`, `booking_status`) **giữ song song** làm compat. Design doc: `docs/design/customer_flow_30-07-2026.md`. Mockup UI: `docs/mockups/customer_flow_30-07-2026.html`.
- **Data model**:
  - Migration `2026_07_30_100000_phase_6_21_customer_flow.php`: thêm `leads.phase` (tinyint 1..7, default 1, index) + `leads.is_first_visit` (bool default true).
  - Bảng mới `lead_phase_closures` (unique lead_id+phase) — mỗi phase chốt = 1 record.
  - Bảng mới `call_logs` (mỗi cuộc gọi Tele = 1 record, status thanh_cong/that_bai/khong_nghe_may).
  - Bảng mới `booking_logs` (mỗi lần đặt = 1 record, status da_xac_nhan/cho_xac_nhan/huy_doi_lich; sync 1 chiều về `leads.booking_status` khi status=da_xac_nhan).
  - Backfill 37 lead cũ: 15 phase 2 (chưa chia), 22 phase 3 (đang chăm). Sinh 59 closure giả lập cho các phase trước phase hiện tại (note='[backfill]').
- **Permission (6 perm mới)**:
  - `phase.close.new / distribute / call / booking / checkin` — tách riêng để linh hoạt cấp lẻ (theo yêu cầu user).
  - `phase.rollback` — Admin vận hành only.
  - Gán vào role hiện có: Admin (full 6), DM HCM/Manager (5 close), Team Leader (4), Sale (new+call+booking), CM sale (distribute+booking), CM booking (distribute+call), Team sale (new+call+booking), Team booking (call). Migration `2026_07_30_100001_seed_phase_621_permissions.php`.
- **Model**:
  - `Lead` thêm constants `CF_PHASE_*`, `CF_PHASE_LABELS`, `CF_PHASE_CLOSE_PERM`, `CF_ROLLBACK_PERM`, `CF_START_PHASE_BY_SOURCE` = [MKT=1, MKT_BR=4, BA=3, SA/BDM/BOD/WI=2].
  - Methods: `startPhase()`, `openFrom()`, `isBulkOpen()`, `phaseState($idx)`, `isPhaseEditable($idx)`, `canLogCall($user)`, `canLogBooking($user)`, `bulkSave($user)`, `closePhase($idx, $user)`, `rollbackTo($idx, $user)`, `markReturning($user)`, `customerFlowLabel()`.
  - Relations: `phaseClosures()`, `callLogs()`, `bookingLogs()`.
  - `$attributes = ['phase' => 1, 'is_first_visit' => true]` + casts.
  - 3 model mới: `LeadPhaseClosure`, `CallLog` (+ STATUSES const), `BookingLog` (+ STATUSES + `syncLeadBookingStatus`).
- **UI**:
  - Thay vì rewrite `⚡lead-form.blade.php` (1600 dòng, risky), tạo **panel mới độc lập** `resources/views/components/leads/⚡customer-flow-panel.blade.php` (Volt-style component). Panel gồm arrow-breadcrumb 7 phase (style AMIS CRM) + tab-bar 7 tab-phase + form nhập call_log/booking_log + action bar 3 nút (Lưu chốt N phase / Kết thúc phase / Lùi phase Admin) + nút "Khởi động lần thăm khám mới" cho khách quay lại.
  - Include panel vào `resources/views/leads/show.blade.php` — hiển thị TRÊN `lead-detail` cũ (không phá vỡ lead-form/lead-detail hiện có).
- **Test**: `tests/Feature/CustomerFlow621Test.php` — 10 test, 10 pass. Bao gồm: mapping source→start_phase, bulk save chốt N phase, close tuần tự, close perm required, rollback chỉ Admin, returning customer reset phase 3, call_log perm, booking sync booking_status, migration NOT NULL, bulk save fail thiếu perm.
- **Regression**: `php artisan test` — 128/143 pass. 3 failure + 12 error là **pre-existing** (verified bằng stash trước khi apply changes): DistributionEngine notification test, ProcessRawLead mã KH format (do commit `143aaad` refactor), Hcm booking test setup, SlaRecall SQLite constraint. Không phá vỡ suite hiện có.

### 5 quyết định default (user check lại)
Trong design doc §13 có 5 câu treo. Tao chọn default sau (thay đổi được sau nếu user muốn):

1. **`phase.rollback` perm** → tạo mới, gán Admin only (đúng ý user "chỉ Admin vận hành lùi phase").
2. **Role "Admin vận hành"** → chưa tạo role riêng, gán 6 perm vào role **`Admin`** hiện có (holder `ops.manage`). Nếu user muốn role riêng, chạy 1 seeder tạo `admin-ops`.
3. **Nút "Khởi động lần thăm khám mới"** → đặt trong panel Customer Flow ở header, hiện khi `phase = 5` + đã có closure phase 5.
4. **status_1/status_2 legacy** → giữ readonly ở Phase 3 (compat, không nhập mới; data mới ghi vào `call_logs`).
5. **Không backfill call_logs từ status_1/2** → data cũ chỉ 2 field text, để trắng logs bảng mới.

### Cách test manual
1. Chạy queue nếu cần: `php artisan queue:work --stop-when-empty`.
2. Login `admin@longevity.com.vn` / `<pass-hn>` → mở `/leads/1` (hoặc bất kỳ lead nào).
3. Panel Customer Flow hiện TRÊN card chi tiết cũ:
   - Arrow-breadcrumb 7 phase (bấm phase để chuyển tab).
   - 7 tab-phase với form nhập tương ứng.
   - Nút "Kết thúc phase X" ở footer (tuần tự) hoặc "Lưu chốt N phase" (nếu lead mới chưa từng Lưu).
   - Nút "⤺ Lùi phase" hiện với Admin vận hành khi ở tab phase đã done.
4. Test cases:
   - Tạo lead mới nguồn `MKT` → phase 1 mở, Lưu → phase 2.
   - Tạo lead mới nguồn `MKT_BR` → mở thông phase 1-4, Lưu 1 phát chốt cả cụm → phase 5.
   - Tạo lead nguồn `BA` → mở thông phase 1-3.
   - Lead ở phase 3: thêm cuộc gọi (status + note) → list cập nhật.
   - Lead ở phase 4: thêm booking → `leads.booking_status` sync theo status log mới nhất.
   - Lead phase 5 đã chốt: bấm "Khởi động lần thăm khám mới" → reset về phase 3, giữ lịch sử.

### Chưa làm (dời sang commit sau)
- Chưa rewrite `⚡lead-form.blade.php` cũ (form Cập nhật vẫn dùng 5 tab cũ). Panel Customer Flow chỉ hiển thị ở trang chi tiết, không ở form edit. Nếu user muốn form edit cũng dùng 7 tab-phase → cần Phase 6.22.
- Chưa build Phase 6 (Bán hàng) + Phase 7 (Sử dụng DV) — hiển thị placeholder "chưa build" ở panel. Sẽ tích hợp lại với module `lead_upsells` + `lead_treatments` hiện có ở phase sau.
- Chưa gán tay perm cho Admin role tại migration (bug: pass integer IDs vào map cần string keys). Đã fix migration + gán tay qua tinker cho DB hiện có; migration mới sẽ chạy đúng ở fresh DB.

## 2026-07-30 (cont'd) — Phase 6.21b: Rewrite lead-form.blade.php sang 7 tab-phase
- **Bối cảnh**: user yêu cầu form Tạo mới + Cập nhật lead cũng dùng UI 7 tab-phase (không chỉ trang chi tiết). Plan A trong 3 hướng đã đề xuất.
- **Thay đổi `⚡lead-form.blade.php`** (1603 → 1875 dòng):
  - Thêm state Livewire: `activePhase` (int 1..7), `isFirstVisit` (bool), `newCallStatus/Note`, `newBookingStatus/ScheduledAt/DoctorId/ServiceId/Note`.
  - Thêm methods: `addCallLog`, `addBookingLog`, `bulkSavePhases`, `closePhaseNow($idx)`, `rollbackToPhase($idx)`, `markReturning`, `selectPhaseTab($idx)`. Tất cả gate qua Lead::canLogCall/Booking + Lead::CF_ROLLBACK_PERM.
  - Import `BookingLog`, `CallLog`, `LeadPhaseClosure` vào top.
  - Mount(): default `activePhase = openFrom` (bulk mode) hoặc `phase` hiện tại; với form Tạo mới → `activePhase = 1`.
  - Header UI: thêm section **Arrow-breadcrumb 7 phase** (style AMIS CRM) TRÊN tabbar. Hiển thị state của từng phase (done/current/open/pending/skipped/notbuilt) với clip-path mũi tên. Legend + alert cf_ok/cf_error inline.
  - Tabbar: đổi từ 5 tab (`status/staff/treatment/upsell/insight` với `x-data="{tab:'status'}"`) sang **7 tab-phase** (`1..7` với `x-data="{phase: @entangle('activePhase').live}"`). Wire `selectPhaseTab` cho click.
  - Đổi `x-show="tab === 'X'"` sang `x-show="phase === N"` cho 5 tab cũ:
    - staff → phase 4 (Booking — bác sĩ + chuyên viên tư vấn)
    - insight → phase 1 (Thêm mới)
    - treatment → phase 7 (Sử dụng DV — liệu trình)
    - status → phase 3 || phase 2 (Gọi điện + Chia số — status_1/2 + classification + booking_status + panel Phân phối)
    - upsell → phase 6 (Bán hàng)
  - Thêm 3 section mới:
    - **Phase 3 Call logs**: list `lead.callLogs` + form add (status dropdown + note + auto called_at=now).
    - **Phase 4 Booking logs**: list `lead.bookingLogs` + form add (status + scheduled_at + doctor + service + note). Sync booking_status khi status=da_xac_nhan.
    - **Phase 5 Check-in placeholder**: hiển thị trạng thái closure phase 5 nếu có, hoặc hint "Lễ tân bấm Kết thúc phase 5".
  - **Nút markReturning**: hiện dưới Phase 5 khi lead đã chốt phase 5 + is_first_visit=true.
  - Footer button bar: giữ nút "Lưu thông tin khách hàng" cũ; **thêm 3 nút Customer Flow** mutually exclusive:
    - `Lưu chốt N phase (X→Y)` — khi bulk mode + activeTab ∈ [openFrom, startPhase].
    - `Kết thúc phase N` — khi tuần tự + activeTab === current phase + phase ≤ 5.
    - `⤺ Lùi phase N (Admin)` — khi user có `phase.rollback` + activeTab < current + có closure.
- **Verify**:
  - Feature test `CustomerFlow621Test.php`: 10/10 ✅ giữ nguyên.
  - Regression `php artisan test`: 128/143 pass — baseline giữ, không có test mới bị vỡ.
  - Dry-render qua Livewire::mount: form Create + form Edit đều render OK (138KB + 171KB HTML). Grep verify chứa: "Customer Flow — 7 phase", 7 label phase, "Lịch sử cuộc gọi", "Lịch sử booking", "selectPhaseTab".
- **Chưa test browser end-to-end** (login qua Livewire XHR khó automation trong session này) — user cần vào trực tiếp `/leads/create` và `/leads/1/edit` để verify UI hoạt động thực tế.

### Cách test manual (updated cho Phase 6.21b)
1. Login admin: `admin@longevity.com.vn` / `<pass-hn>`.
2. Vào `/leads/create` — form Tạo mới:
   - Thấy arrow-breadcrumb 7 phase (phase 1 highlight vì start_phase default 1 khi chưa chọn nguồn).
   - Tabbar 7 tab dọc — click chuyển tab.
   - Chọn nguồn `MKT_BR` → tab phase 1-4 mở, phase 5+ pending.
   - Nhập full info khách → bấm "Lưu thông tin khách hàng" (tạo lead) → sau đó bấm "Lưu chốt 4 phase (1→4)" ở footer để đóng cả cụm.
3. Vào `/leads/1/edit` — form Cập nhật:
   - Arrow-breadcrumb hiện phase hiện tại + closure history.
   - Ở tab phase 3: thấy form "Ghi cuộc gọi" — thêm log → list cập nhật.
   - Ở tab phase 4: thấy form "Ghi booking" — thêm log → booking_status sync theo.
   - Bấm "Kết thúc phase X" ở footer → phase tăng.
   - Admin ops thấy nút "⤺ Lùi phase" ở tab đã done.

## 2026-07-30 (cont'd 2) — Phase 6.21c → 6.21h: hoàn thiện UI + phân quyền + booking split
Sau block "Phase 6.21b" (rewrite lead-form 7 tab-phase), user yêu cầu nhiều chỉnh sửa UI + logic. Danh sách gom lại:

### 6.21c — Layout ngang (style AMIS)
- Bỏ layout 2 cột (grid-cols-2), chuyển thành 1 cột dọc: Header khách + Arrow-breadcrumb + Tabbar + Content phase + Footer.
- Info khách hàng ngang 4 cột: Tên | SĐT | Ngày | Nguồn.

### 6.21d — Tab-driven content (Info + Custom fields vào Phase 1)
- Wrap 2 card đầu (Info khách + Trường bổ sung) trong `x-show="phase === 1"`.
- Bọc tất cả các phase content trong 1 x-data outer duy nhất.

### 6.21e — Sizing lớn hơn (giống mockup)
- Arrow buttons `min-w-[150px] px-5 py-3`, text-sm font-semibold.
- Tabbar text-base, các tab cùng màu vàng (text-gold-700), tab active có gạch chân `border-b-2 border-gold-700 font-bold`.
- Header khách hàng thêm 3 dòng: Người nhập lead / Người phụ trách tele / Người phụ trách tư vấn (compute từ imported_by + closer + owner).

### 6.21f — Phân quyền up nguồn theo role
- **Rename**: `Team booking` → `Team Tele`; `CM booking` → `CM Tele`; `Team nhập lead` → `Trực Page` (rename cả role và org_units). Migrations: `2026_07_30_150000` (roles) + `2026_07_30_160000` (org_units).
- **4 perm mới**: `source.up.{trucpage,sale,tele,admin}`:
  - MKT → `source.up.trucpage` (Trực Page)
  - MKT_BR, SA → `source.up.sale` (Sale, Team sale, CM sale, TL, Manager, DM HCM, Admin)
  - BA → `source.up.tele` (Team Tele, CM Tele, DM HCM, Admin)
  - BDM, BOD, WI → `source.up.admin` (Admin, DM HCM)
- Cấp `lead.update_booking` cho Team Tele + `lead.update_sale` + `lead.book_action` cho Sale/Team sale (migration `2026_07_30_170000`) — Tele/Sale sửa được info lead ở phase họ giữ.
- Fix gate `mount()` LeadForm: dùng `canOpenEditForm` thay `canEditPersonalInfo` để Tele readonly-view thay vì 403.
- Fix validate `save()` UPDATE mode: whitelist sourceGroup cũ nếu ngoài perm user (Tele update lead MKT do người khác up).
- Update `Lead::SOURCE_PERMISSIONS` mapping. Cập nhật `Phase66FlowsTest` (Sale không có source.up.* → 0 nguồn).

### 6.21g — Lock phase đã chốt + logic phaseState + call/booking form
- Compute `$phaseLocked[1..7]` trong `with()`: phase 6, 7 luôn lock; phase 1-5 lock nếu có closure + user không có `phase.rollback`.
- Bọc content 2-7 trong `<fieldset :disabled="cfLocked[phase]">` với banner "Phase X đã chốt — chỉ đọc" reactive theo tab.
- Fix `phaseState`: phase current của lead cũng `open` (xanh dương) dù `idx > startPhase`.
- Redirect sau create/update → `/leads/{id}/edit` (form 7 phase) thay vì `/leads/{id}` (chi tiết cổ).
- Click row list → mở `/edit` nếu có quyền.
- Move panel "Phân phối & Nguồn" từ Phase 3 → Phase 2 (Chia số). Filter dropdown user theo `poolTarget` subtree.
- Filter dropdown "Chia số" chỉ đến depth ≤ 3 (Team owner, không xuống sub-team). Sort HN→DN→HCM. Prefix theo depth (🏢 Công ty / 📍 Chi nhánh / 🏬 Địa điểm / 👥 Team).
- Fix `consultantUsers`: nếu viewer là owner → bỏ intersect với `visibleOrgIds` (Tele hẹp scope vẫn thấy Sale trong Team subtree). Lùi root subtree lên depth 3 khi lead ở sub-team depth 4.
- Header khách hàng: badge "Đang nhập phase X→Y (cần điền)" cho bulk mode + 3 dòng người xử lý.
- Legend 6 trạng thái: Đã chốt / Cần điền thông tin / Chưa tới / Skip / Chưa build (bỏ "Đang xử lý" amber, gộp về xanh dương). State current color đổi amber → blue.
- Phase 3 sắp xếp lại: Lịch sử cuộc gọi (order-1) → Trạng thái chăm sóc (order-2) → Insight (order-3) qua CSS `order-*` + `flex flex-col`.
- Move "Trạng thái đặt lịch" từ Phase 3 → Phase 4 (dropdown readonly badge, "🔒 tự sync").
- Banner tổng errors ở đầu form: "⚠️ Không thể lưu — sửa các lỗi sau: ..." — user không mất lỗi khi ở tab khác.

### 6.21h — Split Thăm khám vs Dịch vụ
- Migration `2026_07_30_180000`: `services.service_type` enum(`tham_kham`/`dich_vu`) + index, `booking_logs.type` varchar nullable + index.
- Seed 9 mục Thăm khám + 40 mục Dịch vụ (idempotent, theo screenshot user).
- `BookingLog` Fillable: thêm `type`.
- `LeadForm.addBookingLog()` validate `newBookingType required in tham_kham,dich_vu`.
- Method mới `syncBookingsFromExternal()` — placeholder chờ tích hợp API lara-sbooking.
- UI Phase 4 form Thêm booking: 5 cột (Loại* / Trạng thái (readonly badge Chờ xác nhận 🔒) / Ngày giờ / Bác sĩ / Dịch vụ). Dropdown Dịch vụ disable + filter theo Loại đang chọn. Nút "🔄 Đồng bộ từ bên booking" ở đầu section.
- List booking hiển thị 2 badge: Loại (🩺 Thăm khám sky / 💆 Dịch vụ fuchsia) + Trạng thái.

### Test summary (2026-07-30 15:34)
- Feature test `CustomerFlow621Test`: **10/10 pass** ✓
- Full suite: **128/143 pass**. 3 failure + 12 error đều **pre-existing** (verify qua stash). Baseline giữ.
- Migrations chạy sạch qua 8 file mới (100000 → 180000).

### Sẵn sàng manual test
Tài khoản test suggest:
- Admin: `admin@longevity.com.vn` / `<pass-hn>` (full quyền, thấy nút Lùi phase).
- Trực Page HN: `hn.page01@longevity.com.vn` (chỉ MKT).
- Tele HN: `hn.book03@longevity.com.vn` (chỉ BA, sửa info lead khi phase 3).
- Sale HN: `hn.tsg.sale01@longevity.com.vn` (MKT_BR + SA, sửa info phase 4).

Vào `/leads/create` tạo mới với các nguồn khác nhau. Vào `/leads/{id}/edit` để test UI 7 phase, chuyển tab, thêm call log, thêm booking (chọn Loại), Kết thúc phase / Lùi phase (Admin).

### 6.21i — Phase 4 rework: booking per-record (2026-08-01)
- **Migration mới**: `2026_08_01_100000` thêm `booking_logs.facility_id` (nullable FK), `2026_08_01_100001` tạo pivot `booking_log_consultants(booking_log_id, user_id, position)` — cho phép mỗi booking có N chuyên viên tư vấn.
- **BookingLog model**: thêm `facility()` belongsTo + `consultants()` belongsToMany ordered by pivot position. Fillable thêm `facility_id`.
- **Rework UI Phase 4** (`⚡lead-form.blade.php`): swap 2 khung.
  - Khung "Bác sĩ tư vấn" (cũ, dropdowns cấp lead) → thay hoàn toàn thành **"Lịch sử booking"** = list record booking (Chờ duyệt lên đầu, rồi scheduled_at desc). Mỗi row hiển thị: người book, datetime, cơ sở, BS, DV, CV[], badge trạng thái.
  - Khung "Lịch sử booking" (cũ, form tạo booking) → đổi thành **"Tạo booking"**, form mở rộng: Loại | Trạng thái (lock) | Datetime | **Cơ sở** | **Bác sĩ** | **Dịch vụ** | **CV multi-select** (nút "+ Thêm CV" / "✕ bỏ"). Giữ ô "Trạng thái tổng thể" + nút "Đồng bộ".
- **Backend Livewire**: thêm properties `newBookingFacilityId`, `newBookingConsultantIds[]`; method `addBookingConsultantSlot()` / `removeBookingConsultantSlot()`; `addBookingLog()` giờ ghi facility_id + attach pivot CV theo position; nếu booking = `da_xac_nhan` + có CV1 + lead chưa có owner → auto call `assignToSale(cv1, 1)` để handoff Sale phụ trách.
- **Bỏ dropdowns cấp lead khỏi UI**: Cơ sở / Bác sĩ / CV1/2/3 / Dịch vụ chính không còn ở Phase 4. **Cột DB `leads.facility_id, doctor_id, consultant_1/2/3_id, service_name` GIỮ NGUYÊN** (backward compat, có thể drop ở lần dọn dẹp sau).
- **lead-detail sidebar**: đọc Cơ sở/BS/CV/DV từ `latestBooking = lead->bookingLogs()->orderByDesc(scheduled_at)->first()` thay vì cột lead. Label CV không còn cứng "CVTV1..3", giờ đánh số theo position của pivot.
- **Rủi ro / cần verify tay**:
  - Handoff Sale: trước đây trigger khi pick CV1 dropdown. Giờ trigger tự động khi tạo booking `da_xac_nhan` + có CV. Test cả case tạo booking `cho_xac_nhan` trước → sau đó update sang `da_xac_nhan`: hiện logic chỉ handoff ở tạo mới, nếu user đổi status booking cũ sang duyệt thì chưa auto handoff (dời sang cải tiến sau).
  - `service_name` không còn ghi mới từ Phase 4 → các dashboard/report group by `leads.service_name` sẽ chỉ có data lịch sử, không có data mới. Cần kiểm tra khi build phase 8.

### 6.21j — Booking push scrm→sbooking: HOÃN, giữ flow cũ (2026-08-01)
- Investigated: form "Tạo booking" mới không thể push sang lara-sbooking vì `BookingController@store` bên sbooking đòi `phong_id` + `khung_gio_id` (REQUIRED) + validate KTV/BS/phòng conflict real-time. Scrm form chỉ có `datetime` + `facility_id`, thiếu phòng và khung giờ → push sẽ fail validate.
- 3 lựa chọn cân nhắc: (1) giữ nút "Đặt booking" cũ redirect + prefill, form scrm chỉ ghi local để tracking; (2) mở rộng form scrm duplicate y hệt form sbooking (~700 dòng logic + fetch dynamic phòng/khung giờ); (3) sbooking build API "quick-book" bypass hết conflict check (data lệch).
- **Chốt (1)**: form "Tạo booking" đổi tên thành **"Ghi nhận booking"** (log nội bộ). Thêm banner xanh cảnh báo "chỉ ghi log nội bộ — muốn tạo lịch thật bấm 'Đặt booking'". Nút "Đặt booking" (redirect sbooking + callback) đã có sẵn ở footer form, giữ nguyên.
- Lý do: form sbooking là feature hoàn chỉnh với business logic phòng/khung giờ/conflict — replicate hoặc bypass = phá vỡ integrity. Sau này nếu cần tự động push, phải build lại form scrm hoặc thay đổi contract 2 phía (task riêng, ~1-2 session).

### 6.21k — Phase A integration: fix dropdown BS Phase 4 (2026-08-01, nhánh sixth)
- Thay `<select>` phẳng của `newBookingDoctorId` bằng searchable dropdown Alpine group **Cơ sở > Phòng > Bác sĩ**, dùng `window.__staffTree` sẵn có.
- Filter cứng theo `newBookingFacilityId`: nếu user chọn cơ sở trước, dropdown BS chỉ hiện BS thuộc cơ sở đó (không hiện BS cơ sở khác gây nhầm).
- Search box theo tên BS, escape để đóng, click ngoài để đóng.
- Chỉ đụng 1 file `⚡lead-form.blade.php` ~line 2196.
- Đánh dấu ✅ Phase A trong `plan-integration-sbooking.md`.

### 6.21m — Phase B integration: UI config token (bỏ .env) (2026-08-01, nhánh sixth)
- **Bên scrm**: đã có sẵn trang `/settings/booking-connection` (Phase 6) — dùng `AppSetting::get/set('booking_url'|'booking_api_token')` + Facility slug + nút Test connection. **Không phải làm gì mới**.
- **Bên sbooking**: mở rộng trang `Thiết lập > Kết nối SCRM` (đã có phần whitelist hosts) → thêm ô nhập **`scrm_api_token`**:
  - Controller `ScrmConnectionController` giờ nhận thêm field, encrypt bằng `Crypt::encryptString` trước khi lưu vào `AppSetting('scrm_api_token')`. Bỏ trống = giữ nguyên.
  - Route mới POST `/thiet-lap/ket-noi/scrm/xoa-token` để xoá token khỏi DB (fallback về env).
  - View: hiển thị masked token (`••••••••1234`) khi đã set; báo trạng thái đang dùng DB / env / chưa có.
  - Middleware `EnsureScrmToken`: đọc `AppSetting::get('scrm_api_token')` với decrypt trước, fallback `config('services.scrm.api_token')` cũ — 100% backward compat.
- **Skip**: nút "Test connection" bên sbooking (scrm chưa có `/api/health`, không đáng build chỉ cho test này); encrypt `booking_api_token` bên scrm (improvement, không blocking).
- **Đánh dấu ✅ Phase B** trong `plan-integration-sbooking.md`.

### 6.21n — Phase C detour: chọn Option 2 schema unification (2026-08-01, nhánh sixth)
- Investigated: schema 4 bảng master (facilities/staff/services/users) 2 phía LỆCH nhiều (tree vs flat, role phân loại vs không, cột riêng bên A không có bên B). Option 3 (map ID tay) đơn giản nhất nhưng user chọn **Option 2** — thống nhất schema 2 hệ.
- Chốt master schema:
  - `services` ↔ `dich_vu`: master **sbooking**.
  - `facilities` ↔ `co_so`: master **scrm** (tree).
  - `staff_members` ↔ `bac_si+ktv`: master **scrm** (1 bảng có role).
  - `users`: master **scrm**.
- Tạo `plan-schema-unification.md` với 4 phase con (C1→C4), effort tổng ~25-40h, spread nhiều session. Mỗi phase = 1 branch riêng, test kỹ mới sang phase tiếp.
- **Không code hôm nay** — chờ user duyệt plan + chốt design cho C1 (services) rồi mới bắt đầu session tiếp.
- Cross-ref: đánh dấu [~] Phase C trong `plan-integration-sbooking.md` → chuyển sang `plan-schema-unification.md`.

### Fix 2026-08-05 — Trực page up lead nguồn MKT báo "phải chọn cơ sở" (nhánh sixth)
- **Bug**: commit `sixth` thêm lời gọi `$this->resolveMktFacility()` trong `save()` (⚡lead-form) để trực page khỏi phải chọn kho cấp Cơ sở, NHƯNG quên viết body method → nguồn MKT + không chọn sale tay = vỡ (trước đó bắt chọn kho "phải chọn cơ sở").
- **Fix**: viết `resolveMktFacility(): ?PoolUnit` ngay sau `targetOrgUnit()`:
  1. User có chọn kho (`poolTarget='org:<poolId>'`, VD BO/CM chia tay) → đi lên PoolUnit tới `kind='facility'` (giữ flow cũ).
  2. Ngược lại (trực page scope=self) → gom org của user + mọi cấp cha (tách từ `orgUnit->path`), tra `org_pool_map` lấy PoolUnit `kind='facility'` active. Đúng 1 cơ sở → auto-resolve; 0 hoặc >1 → null → báo lỗi rõ (Admin kiểm tra phân quyền).
- **Verify** (tinker): `hn.page01`→CS1 59NTN, `hcm.page01`→CS1 207NVT, `dn.page01`→Lô 2&3 TĐN — mỗi account ra đúng 1 cơ sở.
- **Test**: UpsDispatcher + Phase66Flows 9/10 pass. 1 fail (`pick_greet_returns_null_when_all_busy`) pre-existing (verify qua stash), không liên quan.
- **Chưa QA tay browser** — cần login `hn.page01` up 1 lead MKT thật để chắc (nhớ chốt UPS/MKT List hôm nay, không thì kẹt ở gate "Chưa có Sale trong MKT List").

### Fix 2026-08-05 (b) — Quyền trực page: Chia toàn Chi nhánh / toàn Công ty (nhánh sixth)
- **Yêu cầu user**: trong `/org/roles` nhóm "Chia số & Kho lead" thêm 2 ô tick; không tick cả 2 → trực page mặc định chỉ chia cấp cơ sở của mình. Chỉ admin hệ thống tick. Giữ cây Pool (đã tới cấp cơ sở), không thêm cấp Org.
- **Patch**:
  - `PermissionSeeder` nhóm `distribution`: thêm `lead.distribute_branch` + `lead.distribute_company` (hiện tự động ở role-manager vì render data-driven theo group).
  - `⚡lead-form`: property `mktFacilityId`; method `mktAllowedFacilities()` (company perm → mọi cơ sở; branch perm → cơ sở trong chi nhánh của user qua pool branch path; mặc định → cơ sở map trực tiếp từ `org_pool_map`); `resolveMktFacility($allowed)` chốt 1 cơ sở (1→auto, >1→ưu tiên `mktFacilityId`, fallback cascade `poolTarget`, validate ∈ allowed).
  - `save()` block MKT: allowed rỗng → lỗi "liên hệ Admin"; >1 chưa chọn → lỗi "chọn Cơ sở tiếp nhận".
  - `with()` expose `mktFacilities`; UI thêm dropdown "Cơ sở tiếp nhận" ở khối Nhóm nguồn, chỉ hiện khi nguồn MKT + allowed>1 + chưa chọn person.
- **Verify** (tinker, `hn.page01`): DEFAULT=1 (59NTN), BRANCH=1 (HN chỉ 1 cơ sở active — 190HN đang tắt), COMPANY=3 (59NTN, Lô2+3 TĐN, 207NVT). Bật cơ sở HN thứ 2 → BRANCH thành 2.
- **Test**: Phase66Flows + CustomerFlow621 = 15/15 pass. Blade `view:cache` OK.
- **CHƯA QA tay browser** (site cần per-action approval, tool bị chặn). Cần: login admin cấp `lead.distribute_company` cho role Trực Page → login `hn.page01` vào `/leads/create` nguồn MKT thấy dropdown 3 cơ sở; không cấp → không thấy dropdown, auto về cơ sở của họ.

## 2026-08-05 — Session lớn: 5-tab kho, UPS auto-CV, import xlsx, đồng bộ user 2 hệ (nhánh sixth)

### Scrm (lara-datasource)
**Trực page + Sale flow**
- Fix `resolveMktFacility()` (thiếu body ở commit sixth) — trực page nguồn MKT auto-resolve cơ sở qua `org_pool_map` (ancestors).
- Thêm 2 perm `lead.distribute_branch` / `lead.distribute_company` (nhóm distribution) → nới scope trực page.
- Radio "Cách chia" (Tự động / Chia về kho) ở panel "Chia số (Phân phối)" — hiện cho MKT + không lead exists, cả trực page + CM đều thấy. Auto = pickMkt UPS. Pool = cấp kho theo perm (default cơ sở, branch = Chi nhánh, company = Kho công ty).
- Trực page up MKT → giữ phase=1 (không auto-next). Redirect tất cả user tạo lead xong → /edit lead vừa tạo.
- Sale role: thêm perm `lead.update_booking` + `lead.read_booking` + `lead.book_action` (Sale nhận lead MKT qua UPS bucket cần sửa info + đặt booking).
- Route `/leads/{lead}/edit` gate `canOpenEditForm` (thay `canEditPersonalInfo`) — owner mở form được dù không có perm sửa info.
- `canOpenEditForm` mở rộng: owner luôn qua (dù không có `read_booking`).
- `Lead::mount()` default tab = 2 (Call) cho owner nếu phase<2.
- Banner "readonly" mới cho owner: "Info khoá do Phase 1 chốt, ghi call/booking ở tab tương ứng" (xanh lá, thay banner xanh dương cũ).
- Nút "Lưu thông tin khách hàng" (footer + header): đổi gate `!$isReadonly` → `$canWrite` (chặt hơn) — Sale owner không thấy nút → không bấm ăn 403.
- `save()` skip validate "Không thể chia" nếu `personId === owner_id` (owner giữ lead của họ, không phải chia số).
- Fix ô "Lần đầu/Trở lại": auto set `is_first_visit=false` khi sbooking push booking `trang_thai=da_xong` (BookingEventController). Bỏ 2 button `markReturning` UI.

**UPS + auto CV**
- `branchesForUser()` UPS board: gom TOÀN BỘ ancestors từ assignment path → tra `org_pool_map` → đi lên tới `kind=branch`. Trước chỉ check direct assignment.org_unit_id → trực page (assign team-nhap-lead) bị miss.
- Auto CV Phase 4 booking: bỏ dropdown chọn tay `newBookingConsultantIds[]` → hiện readonly "⚡ Sale từ UPS list" (round-robin A→B→C→OFF). `pickGreet` chạy N lần trong `addBookingLog()`. UPS rỗng → block tạo booking.
- `previewNextGreets()` không update state → hiện preview trong UI.
- Bỏ nút "🔄 Đồng bộ từ bên booking" (Reverb đã push tự động), thay bằng nút "⚡ Check UPS List" (popup Alpine, poll wire:poll.5s).

**Kho Lead — 5 tab**
- `/leads/pools` (`⚡lead-pools`): tabs `company/branch/facility/department/personal` thay 3 tab cũ. `TAB_KINDS` const map pool_level + pool_kind. Filter cascade Chi nhánh → Địa điểm → Cơ sở khi tab='department'.
- Rename label toàn UI theo 5-cấp mới: branch="Chi nhánh", facility="Địa điểm", department="Cơ sở". Sale cá nhân (không có perm distribute) chỉ thấy tab "Kho cá nhân".

**Import xlsx**
- Cột mới "Phương thức chia" (TARGETS + GUESS). Sheet 2 "Danh mục kho" tự gen (cây liền mạch: Auto, Kho công ty, Kho Chi nhánh HN, Kho địa điểm 59NTN, Kho cơ sở PKD 1...). Data validation dropdown Excel bắt chọn từ sheet 2.
- `LeadImport::distributionOptions()` map tên → target (auto | pool:<id>).
- `ProcessRawLead` áp `_distribution_target` khi nguồn MKT: auto → pickMkt UPS cơ sở uploader; pool:<id> → set pool_unit_id.
- Strict gate: kho ngoài phạm vi quyền uploader → **HỦY UPLOAD** row (forceDelete lead + fail_ raw). Không note pass. Rule: distribute_company → mọi kho; distribute_branch → subtree Chi nhánh; default → subtree Địa điểm user.

**Catalog**
- `/admin/catalog` tab Dịch vụ: đọc `sb_services` (mirror sbooking) thay bảng `services` legacy. Distinct theo tên, hiện Loại (🩺/💆), Cơ sở áp dụng, Thời gian, Giá.
- `/admin/catalog` tab Trường thông tin KH: thêm section "📋 Trường form Lead theo phase" (đọc `config/lead_form_fields.php` — snapshot cứng của form 6 phase).
- Fix 44 mục sb_services phân loại `la_dich_vu` (9 Thăm khám + 41 Dịch vụ, khớp 2 ảnh user gửi).

**Phase 4 Check-in**
- Thêm block "📋 Thông tin booking đến lịch" đầu Phase 4: hiện Loại/Ngày giờ/Cơ sở/Phòng/BS/DV/CV[]/Liệu trình từ BookingLog mới nhất.
- Chặn nút "Kết thúc phase 4" — chỉ Admin/Lễ tân (phase.close.checkin) hoặc phase.rollback thấy. Sale/trực page không thao tác.

**Perm + rename**
- Bỏ phòng BDM khỏi org tree (0 assignment, 0 lead — an toàn).
- Rename `book1/book2` → "Tài khoản Booking 1/2" (đồng nhất).
- `SyncCrmAccountsSeeder`: dọn 26 user booking-only (ktv_*/dd_*/ddt_*/bsi/adminvh) — không dùng ở scrm.
- Cast `BookingLog.scheduled_end_at = datetime` (fix format() on string ở view Phase 4).
- Dashboard `/dashboard` bảng lead: thêm cột "Trạng thái booking" + click row → luôn /edit.

**Đồng bộ user 2 hệ (root cause CV mapping lệch)**
- Root cause: `RenameUsersToPositionFormatSeeder` (scrm) auto-number theo user.id, KHÔNG khớp mapping cứng bên sbooking (`SyncUsernamesFromCrmSeeder`) → cùng người ở 2 hệ có username khác.
- Fix: copy mapping cứng NAME → USERNAME (25 nhân sự) vào scrm seeder. PASS 1 apply mapping cứng, PASS 2 auto-number cho user không có trong mapping (fallback test data).
- Sbooking mapping: rename "Nguyễn/Trần Booking 1/2" → "Tài khoản Booking 1/2" (đồng bộ scrm).
- Sync hiện tại (data cũ): remap tay 25 user theo NAME. Booking 10 re-push: sale_id=34 (Nguyễn Mai Anh) — đúng CV#1.

### Sbooking (lara-sbooking)
- Xóa migration duplicate `2026_07_02_000003_add_trang_thai_khach_and_phan_hoi_notes` (conflict với 07_05 `add_trang_thai_khach_and_binh_luan`). Xóa `BookingPhanHoi.php`, view `_phan_hoi_section.blade.php`, method `themPhanHoi/xoaPhanHoi`, route them-phan-hoi/xoa-phan-hoi.
- Swap 2 include `_phan_hoi_section` → `partials/trang-thai-lich-hen` (đã dùng `binhLuans`).
- Fix `SearchController::showBooking`: load `binhLuans.nguoiDung` (không phải `user` — relation name lệch), truyền canTrangThai/canBinhLuan/isAdmin cho partial.
- Admin bypass mọi hasPerm trong SearchController.
- `SettingsController` thêm ô nhập "URL SCRM" (`scrm_url` AppSetting). `CrmPushService::crmUrl()` + `callbackToken()` đọc AppSetting trước (fallback env) — trước sbooking dùng default `127.0.0.1:1999` sai host → push callback đi vào void.
- Fix `pushBookingUpdate` format `gio_ket_thuc?->format('H:i:s')` (Carbon serialize sang ISO string → sbooking TIME reject).
- Dashboard `/lich-hen`: thêm widget "Lịch chờ duyệt" (5-widget grid), filter tab='approval'.
- Card "Kết nối SCRM" trở lại `/thiet-lap` (khi tab-hóa bị mất).
- Chạy migration `2026_07_03_000004_create_bac_si_and_ktv_tables` → table `ktv` có (fix `/thiet-lap/nguoi-dung` 500).

### Verify
- Tests: Phase66FlowsTest + CustomerFlow621Test = 15/15 pass qua các patch.
- Tinker verify: 25 user remap OK, `hn.page01` UPS thấy Chi nhánh HN, MKT auto resolve 1 cơ sở, distributionOptions gen cây liền mạch.
- Chưa QA tay browser (site cần per-action approval).

## 2026-08-07 — Recall rules v2 + fix Trực Page + form 3-card

### Bối cảnh
User đọc kỹ "Quy tắc PKD Update.docx" và chỉ ra job `RecallByColumnUpdates` map sai:
- Cột 1,2,3 trong doc = **Ngày gọi + Ghi nhận tình trạng + Bước tiếp theo** (hành động sale).
- Cột 4,5 = **Phân loại + Kết quả**.
- Job cũ hiểu nhầm là MKT tracking (page/camp/phan_loai) → thu hồi sai đối tượng.

### Fix
1. **Migration `2026_08_07_100000_recall_rules_v2`**:
   - Đưa 3 CustomField `phan_loai`, `ket_qua`, `sic` từ org=8 (1 team) về `org_unit_id=NULL` (Công ty toàn bộ). Trước đây gần như không ai thấy.
   - Rename `leads.recall_by_columns` → `leads.skip_recall`. Flip semantic: mặc định false = ÁP thu hồi cho mọi lead. Tick ô = exempt.
2. **Job `RecallByColumnUpdates` viết lại**:
   - Day 1 (≥24h): thiếu call_log có note ≠ '' → thu hồi.
   - Day 3 (≥72h): thiếu bất kỳ 1 trong 4 đk: có call_log với note, CustomField `phan_loai` filled, CustomField `ket_qua` filled, PhaseClosure phase=2 đã đóng.
   - Filter `where skip_recall = false`. Bỏ reset flag (không cần loop-guard vì recall xong `pool_level` chuyển POOL_TEAM, tự loại khỏi query).
3. **Form `⚡lead-form.blade.php`**:
   - Đổi property `recallByColumns` → `skipRecall`, checkbox text "Không áp dụng luật thu hồi" (mặc định = áp).
   - Mô tả rõ 2 mốc thời gian + điều kiện.
4. **Dọn**: cập nhật `Lead.php` fillable, `config/lead_form_fields.php`, `routes/console.php` comment, `qa-checklist.blade.php`.

### Fix song song (task rời trong cùng session)
- **Radio "Chia thủ công"**: perm `lead.assign_direct` đã có sẵn nhưng migration pending → chạy `migrate` + `RolePermissionSyncSeeder` sync cho 5 role (Admin, DM HCM, Manager, CM sale, CM Tele).
- **Form 3-card**: đổi `$__hideCascade` để chỉ hiện cascade khi mode=pool. Thêm placeholder "Vui lòng chọn nguồn khách" khi sourceGroup rỗng.
- **Trực Page không click được "Chia về kho"**: fieldset `:disabled="isTrucPage"` bao luôn phase 1 → fix để chỉ khóa phase 2+ (`phase !== 1`).
- **Bug pool scope check**: `poolTarget` mang pool_unit_id nhưng check bằng `visibleOrgUnitIds()` (org_unit_id) → luôn miss. Skip check khi `mktPoolTarget()` đã set (đã tôn trọng perm).

### Sbooking
- **Xóa/cập nhật token SCRM không được**: form clear-token bị nested trong form update (HTML invalid) → tách form ra ngoài, dùng attribute `form=` HTML5.

### Verify
- `php artisan leads:recall-by-columns --dry-run` → Day1=0, Day3=0 (không lỗi).
- Test Trực Page pool: hn.page01 → CS1: 59 Ngô Thì Nhậm, `mktPoolTarget` OK.
- Chưa QA browser đầy đủ.

## 2026-08-07 (chiều) — Reorg Ghi nhận booking + Sbooking 3-col create form

### SCRM (lara-scrm)
- **⚡lead-form.blade.php**: Move panel "Ghi nhận booking" (Phase 3, `lead.book_action`) LÊN TRÊN panel "Lịch sử booking" (theo yêu cầu — thao tác chính lên trên, tracking xuống dưới).
- Ghi chú kỹ thuật: dùng Python script để move block (~300 lines) vì awk streaming không capture kịp thứ tự. Đã verify order mới: Phân bổ CV → Ghi nhận booking → Lịch sử booking.

### Sbooking (lara-sbooking)
- **Migration `2026_08_07_120000_simplify_booking_quantity_columns`** trên table `booking` (singular, NOT `bookings`):
  - Drop columns `so_luong_lo`, `dung_tich_lo`.
  - Rename `so_lieu_trinh` (varchar) → `so_luong` (unsigned int nullable, ≥ 1). Data cũ = 0 records, không cần backfill.
- **create.blade.php** — 3-col layout theo yêu cầu PKD:
  - Col 1 (order-2): Phòng + KTV + Bác sĩ (Bác sĩ dùng cùng order-2 để CSS grid stack cùng cột).
  - Col 2 (order-3): Dịch vụ + Số lượng (input number min=1).
  - Col 3 (order-4): Ngày + Khung giờ + Giờ TH/KT.
  - Customer info full-width (order-1, col-span-3) ở trên; Quy tắc đặt lịch collapsible details.
  - **Địa điểm dropdown** ở top System Info: đổi cơ sở → JS redirect sang `/{slug}/tao-moi` (chống nhầm cơ sở khi tele HN book cho ĐN). Disabled khi edit.
  - **Ẩn Section "Khách tặng"**: hidden input `khach_tang=khong` default.
  - **Ẩn Section "Hành chính"** (Sale/Menu/Nguồn/Ghi chú): sale_id nullable → controller fallback `auth()->id()`. Menu_ids + ghi_chu preserved qua hidden inputs khi edit.
  - **Bỏ toggle "Kết hợp Medical"** khỏi UI, giữ hidden input để không mất data.
- **BookingController**:
  - `sale_id` validation → `nullable` (bỏ required).
  - Store/update: `'sale_id' => $data['sale_id'] ?? auth()->id()`.
  - `so_luong` validation `nullable|integer|min:1` + Vietnamese message.
  - `formData()` trả về thêm `allCoSos` cho Địa điểm dropdown.
- **Dọn refs**: `Booking.php` fillable, `BookingFields.php` (label + suaSubFields), `BookingExport.php` + `BaoCaoExport.php` (headers + map), `BookingImport.php` (fallback so_lieu_trinh → so_luong), `Api\BookingApiController.php` (2 validate + insert), `CrmPushService.php` (payload), `LichThang6Seeder.php`, `tests/BookingTestSetup.php`.
- **View list + show**: bảng `bookings.blade.php` ẩn 3 cột "Số liệu trình / Số lượng lọ / Dung tích lọ" thành 1 cột "Số lượng"; `show.blade.php` đổi label + field.

### Fix rời trong ngày
- **Xóa/cập nhật token SCRM không được** (lara-sbooking `scrm-connection.blade.php`): nested form (HTML invalid) → tách form clear-token ra ngoài, dùng attribute `form=` HTML5.

### Verify
- Tinker render `create` + `createDichVu` OK, all expected fields present, removed fields absent.
- Tinker store `phong_kham` + `dich_vu`: booking lưu đúng, `so_luong=3`, `sale_id` fallback auth id.
- Edit render OK, giá trị `so_luong` preserved.
- SCRM tests: RecallPolicyResolverTest 8/8 + Phase66FlowsTest 10/10 pass.
- Sbooking suite: 108 test fail vì `BacSi::phongs()` undefined — issue **pre-existing** (không phải do patch này, không có model BacSi::phongs() relation trong code). Chưa fix.

## 2026-08-18 — Booking mai/kia: Admin chọn tay Sale tiếp đón + banner nguồn/tele phụ trách

### Bối cảnh
- Modal Duyệt sbooking hiện chỉ hiện banner nguồn/creator cho SA/BA/MKT_BR. Với MKT/BDM/BOD/Walk-in không có info → admin duyệt mù.
- `/api/sales-in-cosolow` gọi `SCRM /api/ups/sales-today` — chỉ trả sale UPS **hôm nay**. Booking mai/kia vẫn lấy list hôm nay → sai người (sale mai nghỉ vẫn hiện, sale mai đi làm chưa check-in thì mất).
- Tele phụ trách phase 2 SCRM (`lead.owner_id` sau CM chia) chưa được snapshot sang booking → admin không biết ai đang care.

### Sbooking (lara-sbooking)
- **Migration `2026_08_18_160000_add_tele_owner_snapshot_to_booking`**: thêm `bookings.tele_owner_id` (unsigned bigint, no FK — user thuộc SCRM) + `tele_owner_name` (150). Snapshot lúc push, không phụ thuộc SCRM online.
- **`Booking.php` fillable**: thêm 2 field mới.
- **`BookingApiController::store` + `update`**: nhận + lưu `tele_owner_id/name` từ payload SCRM.
- **`routes/web.php` `/api/sales-in-cosolow`**: nhận `?ngay_dat=YYYY-MM-DD`. Nếu `> today` → bỏ qua UPS, trả `User::where('co_so_id',X)->whereRaw('LOWER(chuc_danh) LIKE %sale%')`. Nếu = today → giữ nguyên (UPS list).
- **`_approve_modal.blade.php`**: banner hiện cho MỌI source (amber cho SELF_OWNED SA/BA/MKT_BR, blue cho còn lại). Hiện 2 dòng: Người tạo + Tele phụ trách. `openApprove` nhận thêm `opts.ngay_dat` + `opts.tele_owner_name`, truyền `ngay_dat` vào `loadSales`.
- **`bookings.blade.php` + `show.blade.php`**: `openApprove(...)` truyền thêm `tele_owner_name` + `ngay_dat`.

### SCRM (lara-scrm)
- **`SbookingClient::pushBooking`** payload thêm `tele_owner_id = lead.owner->id` + `tele_owner_name = lead.owner->name`. Resolve `$owner = User::find($lead->owner_id)` trước khi build payload.
- **`SbookingClient::pushBookingUpdate`**: cũng gửi 2 field (khi CM đổi owner sau tạo). Resolve `$lead = $log->lead; $owner = ...`.

### Verify
- `php artisan migrate` OK. `Schema::getColumnListing('booking')` có 2 cột mới.
- Fillable smoke: `new Booking([tele_owner_id/name])->tele_owner_id` = 999. OK.
- `php -l` cả 5 file sửa: no syntax errors.
- Route `api.sales-in-cosolow` still registered.
- Chưa QA browser E2E tay (cần start MAMP + login) — user QA giúp: tạo lead MKT có owner phase 2 = tele X, book ngày mai → mở modal duyệt bên sbooking → verify banner "MKT · Người tạo: Y · Tele phụ trách: X" + dropdown Sale tiếp đón trả full sale cơ sở (không phụ thuộc UPS).

### Không đụng
- `/api/ups/sales-today` SCRM giữ nguyên — sbooking đã tự chuyển sang list all khi `ngay_dat > today`, không gọi endpoint này.
- Rule auto-chia UPS khi khách `da_toi` — giữ nguyên (chưa chốt UPS → không chia, lead về pool team).
- SCRM CM view chia tele phase 2 — đã có sẵn ở `⚡lead-form.blade.php:3813-3840` (radio Auto/Manual + `manualAssignUserId`).
- Không mark busy khi Admin duyệt (đúng ý: busy chỉ khi sale tự tick quá tải).

## 2026-08-24 — Session hotfix prod: password admin, migration Kim Phấn, support bubble, thêm BS + phòng HCM

### 1. Reset password admin về DefaultPassword — CRM
- **Bug**: user `admin` (id=1) bị trôi password sang `'password'` từ seed đời đầu. `OrgStaffSeeder` cố tình KHÔNG đụng password user đã có → chạy lại seed không fix được.
- **Fix ngay**: `\App\Models\User::find(1)->password='59ntn'->save()` — verify OK.
- **Fix seed**: `SyncCrmAccountsSeeder.php` bổ sung "Phần 1b" — sau backfill username, reset password 4 admin (admin, admin.hn/hcm/dn) về `DefaultPassword::forEmail($email)`. Idempotent, safe chạy nhiều lần.
- Commit `84f0af3` — pushed.

### 2. Migration Kim Phấn (sbooking) idempotent hoá
- **Bug prod**: migration `2026_08_22_100000_create_dn_users_and_fix_kim_phan` chạy trên sweetsica prod → `UPDATE users SET email='dn.cms01@...' WHERE id=19` đâm unique. Thực tế Kim Phấn đã tồn tại ở sb#47 với đúng email/username/co_so=3.
- **Fix**: pre-check `dupExists` (row khác id=19 đang giữ username/email 'dn.cms01') → skip step fix id=19 + log. Row 19 (bản cũ sai co_so=1) để dev xử tay sau khi soi bookings.
- Commit `69cb670` — pushed. Cần user chạy `git pull && php artisan migrate` trên prod.

### 3. Support bubble tự bung — sbooking
- **Bug**: partial `_support_bubble.blade.php` dùng Alpine (`x-data`/`x-show`/`x-cloak`). Nhưng topnav + các page booking (dashboard/bookings/create/timeline/show) không nạp Alpine → `x-show="open"` không ẩn được modal → overlay full màn hình hiện đè mọi trang có `@include('partials.topnav')`.
- **Fix**: viết lại bằng vanilla JS + class `hidden`, `onclick` toggle, CSS `#supportBubbleModal:not(.hidden){display:flex!important}`. Bỏ hoàn toàn Alpine.
- Commit `71034ec` — pushed.

### 4. Thêm BS Đặng Công Danh (y học cổ truyền) — HCM 207 NVT
- **Bug**: dropdown "Bác sĩ" rỗng khi tạo booking Dịch vụ + HCM. Trên prod BS chưa có (seed cũ chưa chạy).
- **Fix seed**: `LongevitySeeder.php:390` — đổi `chuc_danh='BS.'` → `'Bác sĩ chuyên khoa y học cổ truyền'`.
- **Migration mới**: `2026_08_24_100000_add_dang_cong_danh_hcm.php` — idempotent qua `(co_so_id, ten)`, insert nếu chưa có, update nếu đã có.
- Commit `f274504` — pushed. Chạy local OK (cập nhật BS#10).

### 5. Fix Phòng YHCT HCM: phong_kham → phong_dich_vu
- **Root cause**: sau khi thêm BS, dropdown vẫn kẹt vì **phòng** rỗng. Cả 3 phòng HCM (Tư vấn / siêu âm / YHCT) đều bị đánh `kieu_phong=phong_kham` (seed `seedPhong` không truyền `kieu` → default). SCRM bucket "Dịch vụ" filter `kieu_phong=phong_dich_vu` → không có phòng match.
- So sánh: 59NTN seed có `'kieu' => 'phong_dich_vu'` rõ ràng cho YHCT/Metaboost/Thủ thuật.
- **Fix seed** + **migration** `2026_08_24_110000_fix_hcm_yhct_room_to_dich_vu.php`: đổi `Phòng YHCT` (207 NVT) → `phong_dich_vu`, 2 slot × 30'. Cột đúng là `phut_moi_khach` (không phải `phut_moi_slot` — commit fix cột `5a17253`).
- Commits `27cc923` + `5a17253` — pushed. Chạy local OK, đã `sb:sync-rooms` cập nhật mirror SCRM.

### Việc dời lại / cần user xử
- **Prod sbooking**: `git pull && php artisan migrate` để chạy 3 migration (Kim Phấn idempotent + BS Đặng Công Danh + fix phòng YHCT).
- **Prod SCRM (data.sweetsica.com)**: chạy sync sau khi sbooking prod migrate:
  ```
  php artisan sb:sync-bac-si && php artisan sb:sync-rooms && php artisan sb:sync-services
  ```
- **Row sb#19 (Kim Phấn bản cũ)**: cần soi bookings gắn `bac_si_id=19` → nếu có, đổi về id=47 (bản đúng) rồi mới xoá row 19. Chưa làm.

### Phát hiện cần thiết kế (chưa code — user gửi sheet dịch vụ HN)
User share sheet map "Dịch vụ HN → Phòng thực hiện". Điểm cần bàn trước khi làm:
1. **Phòng HN seed không khớp sheet**. Seed 59NTN: `Metaboost 1/2/3 T4`, `YHCT 1/2/3 T4`, `Thủ thuật T3`. Sheet dùng phòng **gộp**: `Phòng YHCT`, `Phòng Thủ thuật`, `Phòng Xông`, `Phòng truyền`, `Phòng lấy mẫu`, `Phòng X Quang`, `Phòng khám Nội`, `Phòng VISIA/da`. → Chốt: gộp lại theo sheet hay giữ chi tiết theo tầng?
2. **3 ràng buộc đặc biệt schema KHÔNG support:**
   - **DeepOxy Xông** (id 40): 2 khách/giờ + cùng giới hoặc vợ chồng → cần pairing constraint theo giới tính.
   - **DeepOxy Tổng hợp** (id 41): 1 booking = lock 2 phòng → cần multi-room booking.
   - **Y học Phương Đông** (id 39): thời lượng 30/45/60 linh hoạt → hiện `phut_moi_khach` cố định trên phòng.
3. **Nhiều dịch vụ share 1 phòng** (5 loại tiêm khớp → Phòng Thủ thuật). Cần bảng `dich_vu_phong` (many-to-many) hoặc tag thay vì 1-1.
4. **Data gaps**: STC Japan (id 42) không map phòng; các dòng cuối sheet (Khám Da Visia, Thực hiện lâm sàng lấy máu/siêu âm/Xquang) chưa có trong DB.
5. **Deactivate**: id 1, 3 (Thăm khám cũ + Thực hiện lâm sàng cũ) và 29-33 (Gene2/TruAge) — sheet gạch → cần `active=false`.

Đề xuất thứ tự: **1 → 5 → 4 → 3** (đồng bộ danh mục trước) → mới bàn schema cho **2** (nghiệp vụ đặc biệt).

## 2026-08-24 (tiếp) — Branch `sixteenth`: Phan Trần Khánh Quỳnh làm TL HN PKD1

### Fix
- **`OrgStaffSeeder.php`**:
  - L243: fix typo tên `Phan Trần Khánh Quỳn` → `Phan Trần Khánh Quỳnh`.
  - L343: đổi assignment ptkq `team-ashley` (HCM PKD1) → `team-giang` (HN PKD1), giữ role `Team Leader` + `SCOPE_TEAM`.
  - Thêm block cleanup trước loop assignments: xoá `Assignment` cũ của ptkq @ team-ashley (bắt chước pattern Linda/Bông). Idempotent, match qua email + fallback name (cả 'Quỳn' cũ + 'Quỳnh' mới) để chạy nhiều lần OK.
- **`public/images/flow_full.PNG`**: user dán đè ảnh sơ đồ mới (30KB). Code `guide.blade.php:155,165` đã trỏ sẵn — không cần sửa view.

### Verify
- `php artisan db:seed --class=OrgStaffSeeder --force` OK.
- Tinker: `ptkq` → name = `Phan Trần Khánh Quỳnh`, assignments = `Team Leader @ team-giang` (chỉ 1 dòng, không còn team-ashley).
- Chưa QA browser login ptkq (task nhẹ — chỉ đổi assignment seed).

### Commit
- `b0e3316` push lên branch `sixteenth`.

### Side note
- HCM `team-ashley` giờ không có Team Leader nào (trước là ptkq). Nếu cần TL cho HCM PKD1 → phải thêm người mới, chưa làm.

## 2026-08-24 (tiếp) — Sheet DV/Phòng HN + HCM 207 (Đợt A, không đụng schema)

### Bối cảnh
User gửi sheet Excel "Dịch vụ HN → Phòng thực hiện" (25 dòng) + chốt HCM 207 NVT gồm 6 phòng: Khám / Nội / Siêu Âm / Xét nghiệm / YHCT / Cơ sở điều dưỡng.

Chốt qua Q&A:
- **Metaboost giữ nguyên tên** (không rename thành "Thủ thuật"), YHCT 1/2/3 + Nội 1/2 giữ chi tiết (nhiều phòng song song).
- **Phòng Da + Phòng VISIA tách 2** (Da = bác sĩ khám, VISIA = máy quét).
- **HCM 207 xoá Phòng Tư vấn** (id 13, 0 booking), đủ 6 phòng theo sheet.
- Đợt A KHÔNG đụng schema. 3 ràng buộc đặc biệt (DV 40 pairing giới, DV 41 lock-2-phòng, DV 39 thời lượng linh hoạt 30/45/60) → Đợt B sau.

### Sbooking migration `2026_08_24_150000_update_hn_hcm_services_and_rooms_per_sheet.php`

**HN (co_so=1):**
- Deactivate 7 DV: id 1, 3, 29-33 (set `active=0`, không xoá vì id 1,3 có 1 booking cũ).
- Set `thoi_gian_phut` 18 DV theo sheet:
  - id 2/7/8/9/43=30', id 4=25', id 5=15', id 6=10', id 34=60', id 35-38=10' mỗi, id 39=60' (default; 30/45/60 flexible để Đợt B), id 40=15', id 41=90', id 42=15', id 44=15'.
- Insert 4 DV khám lâm sàng mới (id 177-180): Khám Da (Visia) 15', Thực hiện lâm sàng (lấy máu) 5', (siêu âm) 25', (Xquang) 15'.
- Insert 6 phòng thiếu (id 18-23): Phòng X Quang, Lấy mẫu, Da, VISIA, Xông (slot=2, phong_dich_vu), Truyền (phong_dich_vu). Giữ nguyên 12 phòng cũ.

**HCM 207 (co_so=2):**
- Xoá `phong` id 13 (Phòng Tư vấn).
- Fix `phong` id 15 (YHCT) `phong_kham` → `phong_dich_vu`, slot=2, phut=60 (đồng bộ HN YHCT).
- Insert 4 phòng (id 24-27): Phòng khám, Phòng Nội, Phòng Xét nghiệm, Phòng Cơ sở điều dưỡng.

**Idempotent:** check `exists()` trước khi insert, `where` khớp cụ thể trước khi update. Chạy lại không lỗi.

### SCRM sync mirror
- `php artisan sb:sync-services` → 180 rows (4 mới HN + 176 update).
- `php artisan sb:sync-rooms` → 25 rows (10 mới + 15 update).
- **Bug sync**: command KHÔNG xoá row đã bị delete bên nguồn. Row cũ "Phòng Tư vấn HCM" (sbooking_id=13) còn ở `sb_rooms` → dọn tay bằng `DB::table('sb_rooms')->where('sbooking_id',13)->delete()`. Cần sửa command sync sau (add cleanup step).

### Verify
- Tinker dump sbooking: 44 DV HN đúng phân loại/thời lượng, 4 DV mới có ID 177-180, 18 phòng HN, 6 phòng HCM 207.
- Tinker dump SCRM mirror: 41 active + 7 inactive HN, 18 sb_rooms HN, 6 sb_rooms HCM 207.
- Chưa QA browser (login admin bị lỗi password local — data đã verify qua tinker đủ tin cậy).

### Commit
- Sbooking `c168aae` push branch `sixteenth`.
- SCRM `<next>` push branch `sixteenth`.

### Đợt B1 — YHPĐ tách 3 DV + HCM DV mapping

**Migration `2026_08_24_160000_yhpd_split_and_hcm_dv_mapping.php`:**
- **YHPĐ tách 3** (thay vì flexible field — theo user "cách này đơn giản hơn"):
  - Deactivate id 39 (HN) + id 83 (HCM) — "Y học Phương Đông" gộp cũ.
  - Insert 6 DV mới (2 cơ sở × 3 variant): "Y học Phương Đông 30'/45'/60'".
  - HN: id 181-183. HCM: id 184-186.
- **HCM 207 (co_so=2)**:
  - Insert Phòng X Quang (id 28) — `phong_kham`, slot=1, phut=15.
  - Insert 3 DV "Thực hiện lâm sàng (lấy máu/siêu âm/Xquang)" (id 187-189).
  - Deactivate 15 DV không có phòng phù hợp: id 45, 47 (thăm khám lâm sàng cũ), 53 (Khám Da liễu — HCM chưa có Phòng da), 73-77 (Gene/TruAge), 79-82 (BJR/HA/PRP — HCM không có Phòng Thủ thuật), 84-85 (DeepOxy), 88 (Recells).
  - Set `thoi_gian_phut`: id 78 EAQ = 60', id 86 STC = 15'.
  - id 86 STC Japan giữ active nhưng không map phòng (DV làm ở nước ngoài — UI booking cần skip chọn phòng, để đợt UI sau).

**Mapping DV → Phòng HCM đã suy luận** (chưa lưu cứng vào DB vì chưa có bảng many-to-many):
| Phòng HCM | DV active |
|---|---|
| Phòng khám | Thăm khám tim mạch (46), Khám Sản (52) |
| Phòng Nội | Khám Nội (51) |
| Phòng Siêu Âm | Siêu âm (48), Thực hiện lâm sàng (siêu âm) (188) |
| Phòng Xét nghiệm | Lấy máu (50), Thực hiện lâm sàng (lấy máu) (187) |
| Phòng X Quang | Chụp XQuang (49), Thực hiện lâm sàng (Xquang) (189) |
| Phòng YHCT | EAQ (78), YHPĐ 30/45/60 (184-186) |
| Phòng Cơ sở điều dưỡng | NK (87) — Truyền miễn dịch (user confirm) |

**Sync SCRM lần 2**:
- `sb:sync-services` → 189 DV (9 mới + 180 update).
- `sb:sync-rooms` → 26 phòng (1 mới + 25 update).

**Verify sau đợt B1**:
- HN: 43 active + 8 inactive = 51 DV (44 gốc + 4 Đợt A + 3 YHPĐ Đợt B1).
- HCM: 34 active + 16 inactive = 50 DV (44 gốc + 3 YHPĐ + 3 Thực hiện lâm sàng).
- HCM 7 phòng (6 sheet + Phòng X Quang mới).

### Đợt B2 (2026-08-25) — Fix HCM tiêm khớp

**Migration `2026_08_25_090000_reactivate_hcm_injection_services.php`:**
User bổ sung mapping: tiêm khớp/dịch nhờn/PRP/Recells thực tế làm ở **Phòng Nội** HCM ("Thủ thuật nội"), không phải Phòng Thủ thuật như HN. Đợt B1 deactivate nhầm 5 DV → reactivate:
- id 79 BJR (Tiêm gối) — 10'
- id 80 HA 1%/khớp (Tiêm dịch nhờn) — 10'
- id 81 HA 2%/khớp (Tiêm khớp gối) — 10'
- id 82 PRP/khớp — 10'
- id 88 Recells (Tiêm) — 15'

**Mapping HCM cập nhật**: Phòng Nội giờ nhận 2 nhóm — Khám Nội (id 51) + 5 DV tiêm khớp trên. 1 phòng nhiều DV, sale chọn thủ công.

**Sync + verify**: HCM active 34 → 39 DV (5 reactivate), sb_services mirror sync OK.

### Đợt C.1 (2026-08-25) — Bảng `dich_vu_phong` many-to-many

**Migration `2026_08_25_100000_create_dich_vu_phong_mapping.php`** (sbooking):
- Tạo bảng pivot `dich_vu_phong` (id, dich_vu_id, phong_id, timestamps, unique dv+phong, 2 index).
- Không FK cứng (idempotent, tránh vướng data lệch).
- Seed **66 mappings** (47 HN + 19 HCM):

**HN — nhóm chính:**
- Khám tim mạch (id 2) → Ngoại + Nội 1 + Nội 2 (id 1, 3, 4) — chốt Q&A.
- Siêu âm/XQuang/Lấy máu/Khám Nội/Da/VISIA + 3 "Thực hiện lâm sàng" mới → phòng tương ứng.
- EAQ + YHPĐ 30/45/60 → YHCT 1/2/3.
- BJR/HA 1%/HA 2%/PRP/Recells → **cả 4 phòng Thủ thuật** (T3 + Metaboost 1/2/3 T4).
- DeepOxy Xông (40) → Phòng Xông. NK Truyền (43) → Phòng truyền.

**HCM — nhóm chính:**
- Khám tim mạch (46) → Phòng khám + Phòng Nội.
- BJR/HA/PRP/Recells → Phòng Nội (Thủ thuật nội).
- EAQ + YHPĐ 30/45/60 → Phòng YHCT.
- NK Truyền (87) → Phòng Cơ sở điều dưỡng.

**Skip mapping**: Khám Sản (chưa triển khai), STC Japan (làm ở nước ngoài), DV 41 DeepOxy Tổng hợp (combo — Đợt C.2), DV đã inactive.

### Đợt C.2 (2026-08-25) — Mirror + Wire UI filter phòng theo DV

**Sbooking:**
- `SyncApiController::dichVuPhong()` + route `GET /api/sync/dich-vu-phong`.

**SCRM:**
- Migration `2026_08_25_110000_create_sb_dich_vu_phong_table.php`: bảng mirror với unique (sbooking_dich_vu_id, sbooking_phong_id) + 2 index.
- Command `sb:sync-dich-vu-phong`: full-replace (DELETE + insert) vì pivot không có id nghiệp vụ. Note: KHÔNG dùng TRUNCATE trong transaction (MySQL implicit commit → "no active transaction" error).
- Sync test: `Nhận 66 mappings. Xong. Tổng mirror: 66`.
- `⚡lead-form.blade.php:670-728`: refactor `updatedNewBookingFacilityId` + `updatedNewBookingType` → helper `reloadAvailableRooms()`. Thêm filter `whereIn('sbooking_id', mappedPhongIds)` khi có DV được chọn.
- `updatedNewBookingServiceId`: gọi `reloadAvailableRooms()` + reset `newBookingRoomId` nếu phòng đang chọn không còn hợp lệ.
- Fallback: DV không có mapping (STC, gói khám, tư vấn, đọc kết quả gene) → full list phòng bucket.

**Verify tinker:**
- HCM Khám Nội (51) → 1 phòng: Phòng Nội (25) ✅
- HN BJR (35) → 4 phòng: Thủ thuật T3 + Metaboost 1/2/3 ✅
- HN STC (42) → mapped rỗng → fallback full list ✅

### Đợt C.3.a (2026-08-25) — Sync cleanup row đã delete bên nguồn

- `SyncServicesFromSbooking`: sau upsert, `SbService::whereNotIn('sbooking_id', $receivedIds)->delete()`.
- `SyncRoomsFromSbooking`: cleanup scope theo co_so — `SbRoom::where('sbooking_co_so_id', $coSoId)->whereNotIn('sbooking_id', $receivedIds ?: [0])->delete()`. Guard `[0]` tránh delete-all khi API trả rỗng bất thường. Skip khi `--dry-run`.
- Verify: sync lại services + rooms → `xoá: 0` (mirror đã đồng bộ, không có row thừa).

### Đợt C.3.b (2026-08-25) — STC Japan no-room + UI skip chọn phòng

**Schema:**
- Sbooking migration `2026_08_25_120000_add_khong_can_phong_to_dich_vu.php`: thêm cột `dich_vu.khong_can_phong` (boolean, default false). Set true cho id 42 (HN) + 86 (HCM) — STC Japan làm ở nước ngoài.
- Sbooking `SyncApiController::dichVu()` select thêm field `khong_can_phong`.
- SCRM migration `2026_08_25_120000_add_khong_can_phong_to_sb_services.php`: mirror field.
- SCRM `SbService`: thêm fillable + cast boolean.
- SCRM `SyncServicesFromSbooking`: đọc field từ payload.

**UI SCRM `⚡lead-form.blade.php`:**
- Helper mới `isNewBookingServiceNoRoom()`: query SbService flag.
- `updatedNewBookingServiceId`: nếu DV no-room → clear `newBookingRoomId`, `availableRooms`, `availableSlots`, `roomStatus`; skip `loadSlotsAndStatus`.
- Validation: `newBookingRoomId` chuyển `required` → `nullable`. Add manual check: nếu KHÔNG phải no-room mà room null → `addError` + return.
- Render dropdown: `@if ($this->isNewBookingServiceNoRoom())` → hiện box thông báo "🌐 Dịch vụ này không cần phòng (làm ở nước ngoài)." thay cho select.

**Verify:** sync SCRM → SbService id 42 + 86 có `khong_can_phong=1`.

**Còn nợ (khi user push booking STC thực):** sbooking API preflight/store có thể fail khi phong_id=null (Api BookingApiController.php:119, 171 đã nullable → OK), nhưng `scheduledEndAt` tính từ phòng `phut_moi_khach` — với STC no-room cần fallback dùng `dich_vu.thoi_gian_phut` (STC = 15'). Cần verify end-to-end khi user test tay.

### Đợt C.3.c (2026-08-25) — Chốt từ user

**1. DV 40 pairing giới** — user **BỎ**, chỉ cần ghi chú tay khi book. Không schema, không code.

**2. Fix slot HN + HCM** — Migration `2026_08_25_130000_fix_hn_phong_slot_capacity.php` (sbooking):
User chốt semantics: **slot = max khách/giờ** (tránh dồn khách). Formula: `so_slot_toi_da=1` (1 giường) + `phut_moi_khach = 60/N`.
- HN id 1-5 (Ngoại, Chuyên gia, Nội 1/2, Siêu âm): slot 12 → 1, phut=30 (25 cho Siêu âm).
- HN Phòng lấy mẫu: slot 2 → 1 (giữ phut=10 → 6 khách/giờ).
- HCM Phòng siêu âm (id 14): slot 24 → 1, phut=25.
- HCM Phòng Xét nghiệm: slot 2 → 1.
- **Chưa apply local** vì MySQL tắt cuối session. User pull + `php artisan migrate` bên sbooking mai.

### Đợt C.3.d (2026-08-25) — DV 41 combo Xông + YHPĐ (auto-create 2 booking + rollback)

**Chốt design:**
- Combo max time = 15 + 30/45/60 (không phải 90). Sale chọn YHPĐ variant.
- Preflight cả 2 phòng, chỉ push khi cả 2 free (auto-pick YHCT 10 → 11 → 12).
- Nếu push Booking 2 fail sau khi Booking 1 OK → auto-rollback (DELETE Booking 1).
- 1 BookingLog SCRM cho combo (sb_dich_vu_id=41 marker), note chi tiết 2 sbooking IDs.

**Sbooking side:**
- `DELETE /api/bookings/{booking}` (destroy) — chỉ hoạt động với trang_thai=cho_duyet. Dùng cho rollback.

**SCRM side — `SbookingClient`:**
- `preflightRoom(payload)` — dry-run 1 phòng qua sbooking API.
- `pushRawBooking(payload)` — push booking không qua BookingLog (dùng cho combo sub-bookings).
- `deleteBooking(id)` — rollback booking mồ côi.

**SCRM lead-form `⚡lead-form.blade.php`:**
- Property mới `newBookingComboYhpdVariantId` (nullable, 181/182/183).
- Helper `isNewBookingCombo41()` — detect DV 41 selected.
- UI: khi DV 41 → hide dropdown phòng, hiện box hint + dropdown YHPĐ variant 30/45/60.
- `updatedNewBookingServiceId`: DV 41 → clear room; DV khác → clear combo variant.
- `addBookingLog`: validate combo variant nếu DV 41; branch pushCombo41() BEFORE normal push flow.
- `pushCombo41()` helper (~90 lines): preflight Xông + loop preflight YHCT → push 2 → nếu fail 2 → rollback 1 → trả về ok/error + 2 sbooking IDs cho BookingLog.

**Verify tinker end-to-end:**
- Setup: lead 1 SA source, HN facility, DV 41 + YHPĐ 45' (182), 2026-09-06 09:00.
- Kết quả: BookingLog SCRM id=3 tạo OK, sb_dich_vu_id=41, sb_phong_id=22, sbooking_booking_id=5 (main Xông).
- Sbooking bookings verify: `id=5` DV=40 phòng=22 09:00→09:15; `id=6` DV=182 phòng=10 (YHCT 1) 09:15→10:00 ✅.
- Note SCRM lưu đầy đủ 2 sbooking ma (BKG-260825-000005 + BKG-260825-000006) + info giờ + phòng.
- DELETE API test: `curl DELETE /api/bookings/4` → `{"deleted":true,"id":4}` (đã cleanup test STC booking).

**Ghi chú kỹ thuật:**
- Column `booking_logs.scheduled_end_at` là type `TIME` (không phải DATETIME) — MySQL strip date phần, Carbon cast display date=today. Không ảnh hưởng push sbooking (bookings sbooking có đúng date). Cast quirk sẵn có, không phải bug combo.

**3. DV 41 DeepOxy Tổng hợp — approach A (auto-create 2 booking)** — ĐÃ CODE (Đợt C.3.d trên):
- Flow: sale chọn DV 41 → UI hiện thêm dropdown "YHPĐ đi kèm (30/45/60)" → sale chọn ngày+giờ+BS → submit → SCRM tự tạo 2 booking liền kề:
  - Booking 1: DV 40 (Xông) 15' @ Phòng Xông, giờ start = giờ user chọn.
  - Booking 2: DV YHPĐ variant (181/182/183) @ Phòng YHCT, giờ start = giờ Xông + 15'.
- Chỉ HN có DV 41 active (HCM DV 85 đã inactive).
- Cần handle: partial failure (Booking 1 OK, Booking 2 fail) — rollback Booking 1 hay giữ + báo user? Cần chốt.
- Cần handle: BookingLog SCRM lưu 1 log hay 2 log? Nếu 2 → dashboard sẽ hiện 2 booking cho 1 lead — có thể confusing.
- Task lớn: sửa lead-form submit logic, thêm UI YHPĐ selector, sync 2 booking sang sbooking, handle rollback. **Session sau bàn thiết kế cụ thể trước khi code.**

### Còn nợ verify tay (mai user)
- Pull sbooking + `php artisan migrate` để chạy migration slot fix.
- Login SCRM → tạo lead HN phase 3 → chọn DV STC (id 42) → confirm dropdown phòng ẩn + hiện box "🌐 DV không cần phòng".
- Chọn DV có mapping (BJR 35, Khám Nội 51...) → confirm dropdown chỉ hiện phòng đã map.
- Push booking STC end-to-end → verify sbooking API accept `phong_id=null` + `scheduledEndAt` calc đúng.

### Commit chuỗi (branch `sixteenth`)
- Sbooking: A (`c168aae`) → B1 (`71b7c91`) → B2 (`be1c8c6`) → C.1 (`9b11ea4`) → C.2 (`c5f4e1d`) → C.3.b (`1de73d5`) → C.3.c slot fix (`<next>`).
- SCRM: A (`c5a2450`) → B1 (`1278465`) → B2 (`fe74215`) → C.1 (`356ca85`) → C.2 (`a3387b4`) → C.3 (`6c5dbf1`) → C.3.c result (`<next>`).

### Commit chuỗi (branch `sixteenth`)
- Sbooking: `c168aae` (A), `71b7c91` (B1), `be1c8c6` (B2), `9b11ea4` (C.1), `c5f4e1d` (C.2), `<next>` (C.3.b migration+API).
- SCRM: `c5a2450` (A), `1278465` (B1), `fe74215` (B2), `356ca85` (C.1), `a3387b4` (C.2), `<next>` (C.3.a + C.3.b).
4. **DV 40 DeepOxy Xông** — pairing giới (2 khách/giờ).
5. **STC Japan** — UI booking skip chọn phòng.
6. **Fix sync command SCRM**: cleanup row đã delete bên nguồn.
7. **HN phòng id 1-5 `slot=12`** — confirm số slot thật.

### Commit chuỗi (branch `sixteenth`)
- Sbooking: `c168aae` (A), `71b7c91` (B1), `be1c8c6` (B2), `9b11ea4` (C.1).
- SCRM: `c5a2450` (A), `1278465` (B1), `fe74215` (B2), `<next>` (C.1).

### Commit
- Sbooking `<next>` migration đợt B1 → push `sixteenth`.
- SCRM `<next>` result.md → push `sixteenth`.

## 2026-08-30 — Test online e2e + API v1 design 🟡

### Đã làm hôm nay
- **Test runner online**: refactor `py-test-booking/scenarios/_common.py` — tinker chạy local hoặc SSH qua env (`SCRM_SSH` + `SCRM_REMOTE_DIR` trong `test.env.local`, gitignored). Auth ưu tiên `sshpass -e` (password env), fallback key + BatchMode. Multiplex ControlMaster để tránh "too many auths".
- **Fix ensure_bucket_for_source**: NOT NULL `facility_pool_unit_id` (resolve qua `DistributionEngine::resolvePoolUnitIdFromUser` via Reflection) + `checkin_at` default `now()` (view `⚡lead-form.blade.php:3423` crash khi checkin_at null — bug tồn tại).
- **SCRM UI fix**: tách lỗi `newBooking*` khỏi banner đầu form → hiển thị dưới nút "+ Tạo booking" (khớp ngữ cảnh, tránh hiểu nhầm "phòng còn chỗ" mà báo lỗi trên).
- **SBooking perf**: `LichNotification implements ShouldQueue` — response `/api/bookings` từ 30-120s xuống <1s (worker DB queue đã chạy trên host).
- **Seed mở rộng**:
  - `LichLamViecMauSeeder`: 3 cơ sở × 3 tháng (thay vì HN × tháng hiện tại), idempotent.
  - `LongevitySeeder`: thêm 3 phòng dịch vụ DN (Thủ thuật/Metaboost/YHCT), mirror pattern HN.
  - Staff seeders: chuyển Phan Trần Khánh Quỳnh (ptkq) HCM → HN (Team Leader `hn.tl02`), khớp SCRM `OrgStaffSeeder.team-quynh`.
- **Fix data production** (qua tinker SSH):
  - Reset stale `users.sbooking_user_id` NULL → chạy `sb:sync-users` → auto-map 41 users (trước 0).
  - Tạo 3 BS DN + gán phòng khám (mirror HN structure).
  - Update `ptkq.co_so_id` sang HN (id=1) trên SBooking prod.
  - Backfill `checkin_at` cho 2 DailyAttendance null.

### Bug đã ghi nhận, chưa fix (nợ)
- **SCRM lead-form.blade.php:3423 + 4306**: `optional($x)?->setTimezone(...)->format(...)` crash khi `$x` null. Đúng cú pháp phải là `$x?->setTimezone('Asia/Ho_Chi_Minh')?->format('H:i')` (bỏ `optional()`). Đang tránh trigger bằng cách seed `checkin_at`.
- **SCRM DN dich_vu booking**: form dropdown rỗng dù có phòng + BS + DichVu — cần thêm mapping `sb_services ↔ DichVu` hoặc gán KTV cho phòng dịch vụ. Tạm chỉ rotate `kham_ls` + `tu_van` cho DN scenario (`_TYPES_DN` trong `_common.py`).
- **HnDnTestFlowSeeder fail** trên host: `CoSo::firstOrFail(slug=59ntn)` — có thể LongevitySeeder có lỗi giữa chừng nhưng không log. Cần kiểm tra.
- **SBooking `sb:sync-users` conflict 1 user**: 1 SCRM user có nhiều SBooking match — chưa biết cái nào. Bỏ qua vì không phải user test hiện tại.

### Design API v1 (đã chốt scope, chưa code)

**Mục tiêu**: bộ API CRUD 2 chiều SCRM ↔ SBooking để (a) fix nhanh khi sync lỗi, (b) nhúng sang hệ khác, (c) support test scripts.

**Chung**:
- Base `/api/v1/`, auth `Authorization: Bearer <BOOKING_API_TOKEN>` (dùng lại token đang có).
- Response chuẩn `{data, meta}`, validation FormRequest → 422 field errors.
- Filter/paginate `?filter[...]=&per_page=&sort=`.
- Write op → AuditLog (SCRM có sẵn), SBooking cần thêm `api_audit_logs`.

**Scope SCRM `/api/v1/*`**:
- Users: CRUD + assignments.
- OrgUnits: CRUD + move node.
- Facilities: CRUD.
- Leads + BookingLogs: CRUD + export (JSON/CSV/XLSX theo day/week/month/year).

**Scope SBooking `/api/v1/*`**:
- Users: CRUD + `POST /users/{id}/move` (đổi co_so_id nhanh).
- PhongBan + Phong: CRUD + attach/detach BS (pivot).
- BacSi: CRUD.
- CoSo, DichVu: CRUD.
- LichLamViec: list + tạo tháng + duyệt + bulk add/remove chi tiết.
- Bookings: list/export/CRUD nâng cao (song song `/api/bookings` cũ, không breaking).

**Chia phase triển khai**:
- **Phase A**: users + phong_ban + co_so + bac_si (CRUD cơ bản, cả 2 hệ).
- **Phase B**: org_units (SCRM) + phong + dich_vu (SB) + assignments.
- **Phase C**: LichLamViec + Bookings (list/export/CRUD nâng cao).

**Còn chờ user quyết trước khi code**:
1. Rate limit (đề xuất 60 req/min/token, tắt qua env khi bulk).
2. Client SDK gói sẵn (PHP `ApiClient` hoặc Python `sb_api.py`)?
3. Export format ưu tiên JSON hay XLSX?
4. Bắt đầu Phase A ngay hay xem example payload trước?

### Trạng thái nhánh
- Cả 2 repo (lara-scrm + lara-sbooking) đã checkout `seventeenth` (mới, ứng "17 tiếng anh").
- Commit chưa có gì trên `seventeenth` — bắt đầu từ đây khi user chốt scope API.

### Commit hôm nay (branch `sixteenth`)
- Sbooking: `5edd6d2` (phong DV DN), `4627f85` (LichNotification queue), `49431e4` (ptkq HN).
- SCRM: `2178717` (tách lỗi booking khỏi banner đầu).
- py-test-booking: `cbe6b83` (tinker SSH + local env config).

## 2026-08-31 — API v1 Phase A+B+C hoàn tất, gom Settings admin về 1 mối 🟢

### API v1 (branch `seventeenth`)

**Phase A** — CRUD cơ bản:
- SBooking: users(+move), phong_ban, co_so, bac_si(+attach/detach phong).
- SCRM: users (delete=lock), facilities (chặn xoá nếu còn children).
- Base infra: `BaseV1Controller` (filter/sort/paginate/ok helpers), throttle 60 req/min/token
  (env `API_V1_RATE_LIMIT=0` tắt cho bulk), User-Agent header trong SDK để bypass Cloudflare rule 1010.

**Phase B** — cây tổ chức + gán vai trò:
- SCRM: org_units (CRUD + tree + move), user assignments (nested `/users/{u}/assignments`).
- SBooking: phong (+attach/detach BS), dich_vu.

**Phase C** — lịch + booking:
- SBooking: lich_lam_viec (CRUD + chi tiet bulk add), bookings (CRUD + export theo day/week/month/year).
- SCRM: leads (CRUD + export), booking_logs (CRUD + force push + export).

### Python SDK — `py-test-booking/scenarios/sb_api.py`
Lazy singleton `sb` + `scrm`, auto load `test.env.local`. Signature nhất quán:
`.list(filter, q, sort, page, per_page)`, `.get(id)`, `.create(data)`, `.update(id, data)`, `.delete(id)`.
Đặc biệt: `sb.users.move`, `scrm.org_units.tree/move`, `scrm.booking_logs.push`, các `.export(from_, to, group, filter)`.

### Consolidation Settings
- SCRM `/settings/index.blade.php`: thêm tab **"Cài đặt Booking"** — chỉ super-admin (`username=admin`) thấy.
  6 deep-link mở tab mới sang `booking.sweetsica.com/longevity/thiet-lap*` (icon external ↗).
  Base URL từ `config('services.booking.url')`, không hardcode.

### Fix runtime hôm nay
- **SDK UA**: `python-urllib` bị Cloudflare 1010 → set `User-Agent: sb-api-sdk/1.0`.
- **Test runner**: bump `assert_booking_log_created` deadline 20s → 300s + retry `pushBooking` qua tinker khi stuck 'pending' + log progress mỗi 10s (tránh runner nhìn `(idle)`).
- **Data prod**: rename Phan Trần Khánh Quỳnh (`ptkq`) chuyển co_so HCM→HN (SBooking user id=74).
- **Sync users**: reset stale `users.sbooking_user_id` NULL → `sb:sync-users` auto-map 41 users (trước 0).

### Còn nợ / chưa fix
- SCRM lead-form.blade.php line 3423 + 4306: `optional($x)?->setTimezone(...)->format(...)` crash null — đang tránh bằng seed `checkin_at`. Fix đúng: `$x?->setTimezone(...)?->format(...)` bỏ `optional()`.
- SCRM DN dich_vu booking: form dropdown rỗng — chưa map `sb_services ↔ DichVu` / gán KTV phòng dịch vụ. Tạm rotate `kham_ls + tu_van` cho DN (không `dich_vu`).
- HnDnTestFlowSeeder fail `CoSo::firstOrFail(59ntn)` trên host — LongevitySeeder có thể lỗi giữa chừng nhưng không log.
- 1 conflict trong `sb:sync-users` (SCRM user có nhiều SB match) — không phải test user, bỏ qua.

### Commit hôm nay (branch `seventeenth`)
- SBooking: `0ab2fa0` (A) → `e45b6d0` (B) → `36f2e90` (C).
- SCRM: `6bbba8f` (A) → `46a97d9` (settings tab Booking) → `41f6856` (B) → `e82b696` (C).
- py-test-booking: `c498760` (A) → `1859b53` (UA fix) → `65ebc84` (B) → `882a9c5` (C).

### Phase D (còn chờ user quyết)
Chưa scope. Có thể là:
- Audit log cho API v1 write ops (bảng `api_audit_logs`).
- Export XLSX (thêm Maatwebsite/Excel).
- Frontend admin UI dùng SDK JS thay vì Livewire.

## 2026-09-02 — Avatar → "Lịch sử hoạt động" 🟢

Nguồn: gom `lead_status_logs` (user_id) + `lead_distribution_logs` (actor_id), UNION ALL, order desc, paginate 50/trang. Không double-write, tận dụng index sẵn `(user_id, created_at)`.

- Route: `GET /me/activity` (`me.activity`) — self mặc định; admin có `user.manage` truyền `?user_id=` xem user khác + filter `from/to`.
- Controller: `app/Http/Controllers/MyActivityController.php` — formatter riêng cho status (tạo/sửa/chuyển field) và dist (distribute/recall/pull/manual/…).
- View: `resources/views/me/activity.blade.php` — nhóm theo ngày (Hôm nay/Hôm qua/dd-mm-yyyy), mỗi dòng `HH:mm — <text>` click sang lead.
- Menu: thêm "Lịch sử hoạt động" trong avatar dropdown (`layouts/app.blade.php`, trước "Đổi mật khẩu").
- QA: tinker render OK; test dữ liệu thực trả `Thêm lead mới KH-033-BOD vvq (Nhập tay bởi …)` — đúng mẫu user.
