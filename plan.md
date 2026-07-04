# Lara-SCRM — Kế hoạch triển khai (9 phase)

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

**Kết quả**: CRM thủ công hoàn chỉnh, phân quyền + mask SĐT chạy thật.

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
