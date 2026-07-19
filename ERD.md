# Lara-SCRM — ERD (thiết kế dữ liệu chi tiết)

> Đi kèm `scope.md`. 2 connection Laravel: `pgsql` (raw) + `mysql` (clean, default).
> Cập nhật 2026-07-15: mở rộng `leads` cho luồng 6 nguồn + bảng mới `recall_policies` / `system_settings` (xem B2, B3).

---

## A. PostgreSQL — tầng hứng (raw zone)

### raw_leads
| Cột | Kiểu | Ghi chú |
|---|---|---|
| id | bigserial PK | |
| source_type | varchar | `excel` / `ads_api` / `webhook` / `manual` |
| source_ref | varchar | tên connection / batch import |
| import_batch_id | bigint FK → import_batches, nullable | |
| payload | **jsonb** | toàn bộ data gốc, field tùy ý |
| status | varchar | `pending` / `processed` / `failed` / `duplicate` |
| error_reason | text nullable | lý do fail để marketing soi lại |
| clean_lead_id | bigint nullable | id lead bên MySQL sau chuẩn hóa (tham chiếu logic, không FK) |
| created_at / processed_at | timestamptz | |

Index: GIN(`payload`), btree(`status`), btree(`source_type`, `created_at`), btree(`(payload->>'phone')`).

### import_batches
| Cột | Kiểu | Ghi chú |
|---|---|---|
| id | bigserial PK | |
| file_name | varchar | |
| uploaded_by | bigint | user id bên MySQL (logic) |
| column_mapping | jsonb | map cột file → field chuẩn |
| total / success / failed / duplicated | int | thống kê batch |
| created_at | timestamptz | |

### ingest_logs
Nhật ký webhook/API call: id, source_type, connection_id (logic), http_status, request jsonb, response jsonb, created_at. Phục vụ debug kết nối.

---

## B. MySQL — tầng chuẩn (clean zone)

### B1. Tổ chức & phân quyền

**users** — id, name, email (unique), password, phone, avatar, status (`active`/`locked`), last_login_at, timestamps.

**personal_access_tokens** (Sanctum) — thêm cột: device_name, ip, user_agent, last_used_at → màn Quản lý phiên + end session từ xa = revoke token.

**org_units** — cây tổ chức sâu tùy ý
| Cột | Ghi chú |
|---|---|
| id, parent_id (self FK, nullable) | |
| name, code | |
| path | materialized path, VD `/1/4/9/` — query subtree bằng `path LIKE '/1/4/%'` |
| depth, position, active | |

**roles** — id, name, description, is_system. Role tự định nghĩa.

**permissions** — id, `key` (unique, VD `lead.view`, `lead.export`, `lead.pull_pool`, `rule.manage`, `contribution.set`...), group.

**permission_role** — role_id + permission_id (pivot).

**assignments** — lõi của mô hình chồng chéo
| Cột | Ghi chú |
|---|---|
| id, user_id FK, role_id FK, org_unit_id FK | 1 user nhiều assignment |
| data_scope | `self` / `team` / `custom` |
| active, valid_from, valid_to | hỗ trợ điều chuyển tạm thời |

**assignment_scope_nodes** — assignment_id + org_unit_id: các node được tích checkbox khi `data_scope = custom` (thấy node + toàn bộ con).

### B2. Lead

**leads**
| Cột | Kiểu/Ghi chú |
|---|---|
| id | PK |
| raw_lead_id | bigint nullable — truy vết về Postgres |
| received_date | date (Ngày) |
| page, camp, insight, link, ad_source | varchar |
| name | varchar |
| phone | varchar, **index**, chuẩn hóa E.164; unique để chống trùng |
| region | varchar (KHU VỰC) |
| classification | enum: `new` / `lead` / `follow` / `net` / `tai_chinh_yeu` / `quan_tam` / `tham_khao` / `tim_hieu` / `goi_lai_sau` / `klld` / `missed` / `booking` / `show` / `close` |
| status_1 / status_2 | text — Ghi nhận tình trạng lần 1 / lần 2 |
| note | text |
| pool_level | enum: `common` / `booking` / `ctv` / `approval` / `team` / `personal` — mở rộng 2026-07-15 để phản ánh 6 luồng nguồn |
| source_group | enum: `marketing` / `data_cold` / `bdm` / `referral` / `ctv` / `walk_in` — nhóm nguồn (6.3) |
| approval_status | enum: `none` / `pending` / `approved` / `rejected` — dùng cho luồng "Khách tự đến" |
| approval_by, approved_at | FK users, timestamp — ai duyệt, khi nào |
| overdue_marked_at | timestamp nullable — đánh dấu lead từ chối quá hạn ở kho booking (không auto-delete) |
| recall_at | timestamp nullable — mốc thu hồi (do CM chia đặt); null = chia vĩnh viễn |
| is_permanent_assignment | bool default false — "Chia vĩnh viễn" (admin vẫn thu hồi được) |
| booking_status | enum: `not_booked` / `booked` / `rescheduled` — trạng thái đặt lịch |
| pipeline_phase | enum: `booking` / `sale` — giai đoạn lifecycle (Phase 6.8) |
| pipeline_status | enum: `waiting_distribute` / `in_care` — trạng thái trong giai đoạn (Phase 6.8) |
| consultant_1_id, consultant_2_id, consultant_3_id | FK **users** (Phase 6.9, trước đó là staff_members) — chuyên viên tư vấn = user team sale |
| doctor_id | FK staff_members — bác sĩ tư vấn (không đăng nhập) |
| ~~performing_doctor_id, treatment_1..4, quality_rating~~ | **Đã drop Phase 6.11** — chuyển sang bảng `lead_treatments` (thẻ 1-N) |
| ~~page, camp~~ | **Đã drop Phase 6.20** — chuyển sang `lead_custom_values` (custom field cấp công ty, key `page`/`camp`) |
| owner_id | FK users, nullable (CHIA CHO) |
| receiver_id | FK users, nullable (Người nhận LEAD / thu thập) |
| org_unit_id | FK org_units, nullable — team đang giữ |
| assigned_at, last_care_at | datetime — tính SLA thu hồi |
| timestamps, soft delete | |

Index: (`org_unit_id`,`classification`), (`owner_id`,`classification`), (`received_date`), (`camp`), (`ad_source`), (`pool_level`), (`pipeline_phase`,`pipeline_status`).

Bổ sung 2026-07-03 (mã KH + trường tùy biến, xem scope.md 4.1–4.2):
- **leads** thêm cột: `code` varchar unique (VD `KH-00123-MKT-FB`, sinh sau khi có id), `type_code` varchar(10) (`MKT`/`C`/`BDM`/`SI`/`N`), `source_code` varchar(10) nullable.
- **custom_fields** — id, org_unit_id FK nullable (null = mức công ty), `key` (unique trong org), label, field_type (`text`/`number`/`date`/`select`), options JSON (cho select), required bool, position, active, timestamps. Quyền `field.manage`.
- **lead_custom_values** — lead_id FK + custom_field_id FK (PK kép), value text. Bộ trường áp theo org_unit đang giữ lead + tổ tiên (path) + mức công ty.

**staff_members** (Phase 6.12 — thêm cột `title`): id, `name` (tên riêng), `title` (chức vụ), facility_id FK, role (`doctor`/`consultant`), active, timestamps. `title` nullable — hiển thị "Tên\n(Chức vụ)" qua `displayName()`.

**lead_treatments** (Phase 6.11) — id, lead_id FK (cascade), `sequence` (1,2,3...), `performed_at` date nullable, `performing_doctor_id` FK staff_members nullable, `quality_rating` text nullable, timestamps. Index `(lead_id, sequence)`. Mỗi row = 1 lần liệu trình, có bác sĩ + đánh giá riêng.

**lead_status_logs** — id, lead_id FK, user_id FK, field (`classification`/`status_1`/`status_2`/`note`), old_value, new_value, created_at. Nguồn cho lịch sử chăm sóc + audit.

**lead_distribution_logs** — id, lead_id FK, action (`distribute`/`recall`/`escalate`/`manual_assign`/`approve`/`reject`), from_pool_level, to_pool_level, from_owner_id, to_owner_id, org_unit_id, rule_id nullable, actor_id nullable (null = hệ thống), reason text nullable (dùng cho reject/escalate), created_at.

> Ghi chú: action `pull` deprecated 2026-07-15 (bỏ cơ chế NV tự lấy lead). Migration mới không xóa dữ liệu lịch sử, chỉ không sinh mới.

### B3. Chia số

**distribution_rules**
| Cột | Ghi chú |
|---|---|
| id, name, active, priority | khớp rule đầu tiên theo priority |
| level | `pool_to_team` (cấp 1) / `team_to_user` (cấp 2) |
| org_unit_id | nullable — rule cấp 2 thuộc team nào |
| conditions | JSON: `{region:[], camp:[], ad_source:[], page:[]}` |
| strategy | `round_robin` / `weighted` / `top_revenue` / `top_close_rate` |
| strategy_config | JSON: weights, metric_window (`day`/`week`/`month`/`custom`), custom_range |

**rule_targets** — id, rule_id FK, target_type (`org_unit`/`user`), target_id, weight (tỉ trọng 5-3-2...), position. 

**rule_counters** — rule_id, target_id, period_key, delivered_count — trạng thái con trỏ round-robin/weighted, reset theo chu kỳ.

**lead_caps** — id, scope_type (`org_unit`/`user`), scope_id, daily_cap, active. Trần 3 cấp (phòng ban/team dùng org_unit).

**user_lead_settings** — user_id PK, receiving (bool bật/tắt nhận số), off_reason, off_until.

**sla_policies** — id, org_unit_id nullable (null = mặc định toàn cty), mode (`auto`/`manual`/`off`), recall_after_hours, recall_to (`common`/`team`).

> **Deprecated 2026-07-15**: bảng này giữ cho SLA "quá X giờ không chăm → thu hồi" (nghiệp vụ SLA chăm sóc). Cơ chế **recall theo mốc CM đặt lúc chia + escalate 2 tầng** dùng bảng `recall_policies` (mới) — 2 khái niệm khác nhau, không gộp.

**recall_policies** (mới, 2026-07-15) — cấu hình recall + escalate theo cấp phòng ban/team, override từ trên xuống

| Cột | Ghi chú |
|---|---|
| id | PK |
| org_unit_id | FK org_units, unique. Cấu hình gắn với node (phòng ban hoặc team) |
| recall_after_days | int nullable — mặc định "Thu hồi sau XX ngày" khi CM không nhập tay ở form chia |
| escalate_after_days | int nullable — quá X ngày ở pool team CM → escalate lên kho CM cấp cha |
| allow_permanent_assignment | bool default true — bật/tắt lựa chọn "Chia vĩnh viễn" trên form chia của cấp này |
| set_by | FK users, updated_at | ai chỉnh, khi nào |

**Quy tắc resolve** (từ trên xuống, cấp cha ghi đè cấp con):
1. Tìm node cha gần nhất có `recall_policies` (theo path). Nếu có → dùng cấu hình đó (cấp con bị bắt buộc theo).
2. Không có ở tổ tiên → dùng cấu hình của chính node đó (nếu có).
3. Không có nữa → dùng mặc định hệ thống (config file / bảng `system_settings`).

Viết thành `RecallPolicyResolver::for($orgUnit)` trả về `(recall_after_days, escalate_after_days, allow_permanent)`.

**system_settings** (mới nếu chưa có) — key-value cấu hình chung: `default_recall_after_days`, `default_escalate_after_days`, `default_allow_permanent`, `sys_admin_can_bypass_permanent` (mặc định true).

### B4. Dịch vụ & tiền

**services** — id, name, code, pricing_type (`package`/`per_phase`), package_price nullable, active, timestamps.

**service_phases** — id, service_id FK, position, name, phase_price nullable (dùng khi per_phase).

**customer_services** — dịch vụ gắn vào khách
| Cột | Ghi chú |
|---|---|
| id, lead_id FK, service_id FK | |
| agreed_price | giá chốt thực tế (override giá niêm yết) |
| status | `active` / `completed` / `cancelled` |
| started_at, completed_at | |

**customer_service_phases** — tiến độ từng phase
| Cột | Ghi chú |
|---|---|
| id, customer_service_id FK, service_phase_id FK | |
| status | `pending` / `done` / `skipped` |
| done_by | FK users — **ai làm phase này** |
| done_at | |
| handover_note | text — note bàn giao (sale A xong 3/10 → B đọc) |

**payments** — sổ thu tiền
| Cột | Ghi chú |
|---|---|
| id, lead_id FK, customer_service_id FK nullable, customer_service_phase_id FK nullable | |
| amount, method (`cash`/`transfer`/`card`), paid_at | |
| collected_by | FK users |
| note, timestamps | |

Công nợ = agreed_price − Σ payments (tính, không lưu).

### B5. % đóng góp khi Close

**contribution_templates** — id, name, items JSON `[{role_label, percent}]`, is_default.

**contributions** — id, lead_id FK, customer_service_id FK nullable, user_id FK, role_label (`collector`/`care_1`/`care_2`/`phase_worker`/...), percent decimal(5,2), set_by FK users (lead team), created_at. App enforce Σ = 100 mỗi deal.

### B6. Kết nối nguồn

**source_connections** — id, type (`facebook_ads`/`tiktok_ads`/`google_ads`/`webhook`), name, credentials (encrypted JSON), webhook_token, field_mapping JSON, active, last_synced_at.

### B7. Báo cáo & hệ thống

**stats_daily** — bảng aggregate tính sẵn (job chạy mỗi 1–3 phút cho hôm nay, chốt cứng qua đêm):
date, org_unit_id, user_id nullable, camp nullable, ad_source nullable, + các counter: total, lead, follow, net, booking, show, close, revenue_collected. Unique key theo tổ hợp chiều. Báo cáo tháng = Σ ngày.

**audit_logs** — id, user_id, action (`view_phone`/`export`/`update`/`distribute`/`recall`/`login`...), entity_type, entity_id, meta JSON, ip, created_at. Partition/prune theo tháng.

**notifications** (Laravel chuẩn) — thông báo chia số realtime.

---

## C. Quan hệ chính (tóm tắt)

```
org_units (cây) ─< assignments >─ users, roles ─< permission_role >─ permissions
assignments ─< assignment_scope_nodes >─ org_units
leads >─ org_units, users(owner/receiver); leads ─< lead_status_logs, lead_distribution_logs
distribution_rules ─< rule_targets, rule_counters
leads ─< customer_services >─ services ─< service_phases
customer_services ─< customer_service_phases (done_by → users)
leads ─< payments, contributions
raw_leads (PG) ←logic→ leads.raw_lead_id (MySQL)
```

## D. Ghi chú triển khai

- **Data scope resolve**: từ assignments của user → tập org_unit path prefix → mọi query lead thêm `WHERE org_unit_id IN (subtree)` hoặc `owner_id = user` tùy scope. Viết thành global scope/trait chung.
- **Che SĐT**: accessor trên model Lead — ngoài scope trả `090***4567`; mọi lần xem số đầy đủ ghi audit_logs.
- **Chống trùng**: unique index `leads.phone`; pipeline gặp trùng → đánh dấu raw `duplicate` + gộp thông tin mới vào lead cũ (log lại).
- **Engine chia số**: queue job sau khi lead vào clean; lock theo rule (`SELECT ... FOR UPDATE` trên rule_counters) tránh race khi lead về dồn dập.
- **Số liệu doanh thu cho rule top_revenue / top_close_rate**: đọc từ stats_daily theo metric_window của rule.
