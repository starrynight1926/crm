# Lara-SCRM — Kế hoạch triển khai (8 phase)

> Đi kèm `scope.md` + `ERD.md`. Nguyên tắc: mỗi phase kết thúc đều có thứ chạy được, demo được. Làm xong phase nào ghi vào `result.md`.

## Phase 0 — Scaffold & nền tảng
- [ ] `laravel new`, cấu hình 2 connection `mysql` (default) + `pgsql`
- [ ] Cài Sanctum, Livewire, Reverb; Alpine.js qua CDN
- [ ] Layout Blade chung theo Figma (sidebar, header), seeder tài khoản admin
- [ ] Màn 1: Đăng nhập
- [ ] Màn 2: Quản lý phiên (list token Sanctum theo thiết bị, end session từ xa)

**Kết quả**: login, layout, kill session từ xa.

## Phase 1 — Tổ chức & phân quyền
- [ ] Migrations + models: `org_units` (materialized path), `roles`, `permissions`, `assignments`, `assignment_scope_nodes`
- [ ] Trait/global scope resolve data scope (self/team/custom → subtree) + **unit test kỹ**
- [ ] Màn 3: Quản lý nhân viên & phân quyền
- [ ] Màn 4: Thiết lập vai trò & quyền hạn (RBAC checkbox)
- [ ] Màn 5: Sơ đồ tổ chức + checkbox data scope

**Kết quả**: tạo user, gán nhiều assignment; chạy được case "sale team A kiêm manager team B".

## Phase 2 — Lead CRUD (tầng clean)
- [ ] Migrations: `leads`, `lead_status_logs`, `audit_logs` (+ index như ERD)
- [ ] Màn 7: Danh sách KH (server-side pagination, filter)
- [ ] Màn 8: Thêm mới / cập nhật KH (nguồn lead nhập tay hoạt động)
- [ ] Màn 9: Chi tiết & ghi chú KH, lịch sử chăm sóc từ `lead_status_logs`
- [ ] Che SĐT theo scope (accessor + audit log khi xem số đầy đủ)
- [ ] Chống trùng: unique `leads.phone`

**Kết quả**: CRM thủ công hoàn chỉnh, phân quyền + mask SĐT chạy thật.

## Phase 3 — Pipeline raw → clean + Import
- [ ] Postgres migrations: `raw_leads`, `import_batches`, `ingest_logs` + GIN index
- [ ] Job chuẩn hóa (queue): validate SĐT E.164, check trùng → gộp, ghi `raw_lead_id`
- [ ] Màn 13: Import Excel/CSV (column mapping, thống kê batch)
- [ ] Màn 14 (một nửa): danh sách lead lỗi (`failed`) cho marketing
- [ ] Webhook endpoint từ landing page; bảng `source_connections` (Ads API dời sang Phase 7)

**Kết quả**: đổ file 10–50k dòng, lead sạch tự chảy sang MySQL.

## Phase 4 — Engine chia số ⚠️ (rủi ro cao nhất, test dày)
- [ ] Migrations: `distribution_rules`, `rule_targets`, `rule_counters`, `lead_caps`, `user_lead_settings`, `sla_policies`, `lead_distribution_logs`
- [ ] Engine: matching theo priority → strategy → constraints (trần 3 cấp, bật/tắt nhận số)
- [ ] Strategy: round-robin + weighted trước; `top_revenue` / `top_close_rate` stub, hoàn thiện ở Phase 6 (cần `stats_daily`)
- [ ] Lock `SELECT ... FOR UPDATE` trên `rule_counters`, test race khi lead về dồn dập
- [ ] SLA recall (scheduler) + thu hồi/chia lại thủ công + kéo lead từ kho theo quyền
- [ ] Màn 11: Cấu hình chia số & rule
- [ ] Màn 12: Quản lý kho lead 3 cấp (chung/team/cá nhân)
- [ ] Thông báo realtime qua Reverb khi sale nhận lead

**Kết quả**: lead về tự chảy xuống đúng sale, đúng luật.

## Phase 5 — Dịch vụ, thanh toán, % đóng góp
- [ ] Migrations: `services`, `service_phases`, `customer_services`, `customer_service_phases`, `payments`, `contributions`, `contribution_templates`
- [ ] Màn 15: Quản lý & theo dõi dịch vụ (danh mục + phase, ai làm, note bàn giao)
- [ ] Màn 16: Ghi nhận thu tiền & công nợ (công nợ tính động, không lưu)
- [ ] Màn 10: Popup % đóng góp khi Close (enforce Σ=100, template mặc định)

**Kết quả**: case "A làm 3/10 phase bàn giao B" chạy được, doanh thu thực thu có số.

## Phase 6 — Báo cáo & Dashboard
- [ ] `stats_daily` + job aggregate (1–3 phút cho hôm nay, chốt cứng qua đêm)
- [ ] Màn 6: Dashboard tổng quan (lead hôm nay, funnel tháng, top sale, quá SLA)
- [ ] Màn 17: Funnel theo kỳ, hiệu quả marketing (camp/nguồn/PAGE), hiệu suất sale/team, báo cáo chia số
- [ ] Export Excel theo quyền + audit log
- [ ] Hoàn thiện strategy `top_revenue` / `top_close_rate` của Phase 4

**Kết quả**: đủ 5 bộ báo cáo trong scope.

## Phase 7 — Ads API + hoàn thiện
- [ ] Màn 14 đầy đủ: kết nối Facebook Lead Form / TikTok / Google Ads, sync định kỳ
- [ ] Seed ~200–300k lead giả, test index/pagination/aggregate ở quy mô thật, tune query
- [ ] Partition/prune `audit_logs` theo tháng
- [ ] Polish UI theo Figma, rà soát audit log toàn hệ thống
