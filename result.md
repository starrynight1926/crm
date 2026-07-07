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
