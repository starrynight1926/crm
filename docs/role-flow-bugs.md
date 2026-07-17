# Role Flow — Bugs & Gaps phát hiện khi QA browser

> Ngày test: 2026-07-17. Actor: manual QA qua browser trên `php artisan serve` port 8000.
> Script auto `scripts/role-flow-test.php` PASS 26/26 (engine level). Bugs dưới đây là gap giữa engine và UI.

## 🐛 BUG 1 — Team trực page (`page1@`) không thấy nhóm nguồn Marketing/Data lạnh/BDM

**Route**: `/leads/create`
**Actor**: `page1@longevity.com.vn` (role: Team trực page)
**Kỳ vọng** (theo `result.md` Phase 6.6+): dropdown "Nhóm nguồn" hiện `Marketing, Data lạnh, BDM, Bạn giới thiệu, Khách tự đến`.
**Thực tế**: chỉ hiện `Bạn giới thiệu` + `Khách tự đến`. **Thiếu 3 nhóm chính**.

**Ý nghĩa nghiệp vụ**: Team trực page bản chất để up **Marketing** (nhóm 1) — nếu không chọn được thì luồng nghiệp vụ gãy hoàn toàn. Toàn bộ lead do page1 tạo sẽ bị ép vào referral/walk_in — sai bản chất.

**Nghi ngờ nguyên nhân**: `Lead::allowedSourceGroupsFor()` lọc theo `SOURCE_PERMISSIONS` — có thể role `Team trực page` bị mất permission `lead.distribute_team` (hoặc perm mới đã tách nhưng chưa gán). Cần grep:
```bash
php artisan tinker --execute="
\$u = App\Models\User::where('email','page1@longevity.com.vn')->first();
dd(App\Models\Lead::allowedSourceGroupsFor(\$u));
dd(\$u->roles->flatMap->permissions->pluck('key')->unique()->values());
"
```

**File nghi vấn**:
- `app/Models/Lead.php` (map `SOURCE_PERMISSIONS`)
- `database/seeders/Phase66FlowSeeder.php` (gán permission cho role Team trực page)

---

## 🐛 BUG 2 — CM booking (`cmbk@`) không có nút Thu hồi trên UI chi tiết lead

**Route**: `/leads/{id}` (chi tiết KH)
**Actor**: `cmbk@longevity.com.vn` (role: CM booking, có perm `lead.recall`)
**Kỳ vọng**: khi vào chi tiết lead đã chia cho sale khác, hiện nút "Thu hồi" để đưa lead về pool team.
**Thực tế**: trang chi tiết chỉ có form ghi chú — không có action recall, không có action distribute, không có action transfer.

**Ý nghĩa**: cmbk phải quay ra danh sách/kho lead để thao tác → luồng thao tác dài hơn cần thiết. Script auto pass vì gọi engine trực tiếp `manualRecall`, nhưng UI chưa expose.

**Cần verify**: nút Thu hồi có được UI expose ở đâu khác không (kho cá nhân filter theo user? popup từ danh sách?). Nếu có thì đây là gap trải nghiệm; nếu không thì **thiếu UI**.

**File nghi vấn**:
- `resources/views/livewire/lead-detail.blade.php` (hoặc component chi tiết lead)
- `app/Livewire/LeadPools.php` (có expose recall nhưng chỉ trên kho)

---

## ⚠️ BUG 3 — CM sale demo (`cmsale@`) chỉ có subtree 1 node

**Không phải bug engine, là bug config seed.**

Verify: `cmsale@` được gán `org_unit_id=11` (Team Sale = Team Hợi Sale, leaf node), scope=team → subtree chỉ 1 node. Dropdown "chọn kho" hiện đúng logic: `Kho chung` + node chính (11).

Nếu ý user: cmsale demo là **CM sale toàn công ty** để chia lead giữa các team sale → phải đổi assignment: `org=1 (Công ty)` hoặc `scope=custom` gồm nhiều nodes.

Test với `ttg@` (real CM sale, org=Team Giang id=4, scope=team) → dropdown hiện đúng 4 kho trong subtree Team Giang → engine OK.

**File cần review**: `database/seeders/Phase66FlowSeeder.php` — org gán cho user `cmsale@`.

---

## 🐛 BUG 4 — Trợ lý kinh doanh (`lpt@`) scope sai, thấy 0 lead

**Route**: `/dashboard`, `/leads`
**Actor**: `lpt@longevity.com.vn` (role: Trợ lý kinh doanh)
**Kỳ vọng** (theo `result.md` Phase 6.6): scope custom = TOÀN CÔNG TY view-only, thấy đủ 25+ lead.
**Thực tế**: Dashboard "Lead hôm nay: 0", `/leads` "Không có khách hàng nào".

**Nguyên nhân xác định qua DB**:
```
assignment: role=Trợ lý kinh doanh, org_unit_id=24 (Nhóm Giám Sát), data_scope=self
```
Đáng ra: `data_scope=custom` + scope_node = 1 (Công ty root).

**File cần fix**: `database/seeders/OrgStaffSeeder.php` (hoặc `RealCmStaffSeeder.php`) — phần gán scope cho user "Lê Thị Phương Tự".

---

## ✅ BUG 5 — DM HCM scope — **KHÔNG PHẢI BUG**

Ban đầu tao nghĩ tnkn (DM HCM) thấy lead HN — thực tế KH-016 org_id=16 (**HCM** Team Ashley Team Booking) và KH-011 org_id=17 (**HCM** Team Sale). Trùng tên với HN nhưng khác ID. Engine `scopeVisibleTo` hoạt động đúng.

---

## 🐛 BUG 6 — Pipeline import bắt trường tùy biến cấp phòng cho lead kho chung

**Route**: pipeline `ProcessRawLead` job (import CSV, webhook)
**Bối cảnh**: import 6 lead vào kho chung (org_unit_id=null, pool=common) qua CSV.
**Kỳ vọng**: chỉ áp trường bắt buộc **cấp công ty**; trường phòng/nhóm chỉ áp khi lead thuộc phòng đó.
**Thực tế**: 4/6 lead fail với reason `Thiếu trường bắt buộc: Phân loại (#Phân loại), Kết quả (#Kết quả)` — 2 trường này là custom field cấp `Team Tạ Văn Hợi`. Lead ở kho chung không thuộc team này mà vẫn bị ép.

**Verify**:
```
raw#1,2,3,6: FAIL "Thiếu trường bắt buộc: Phân loại (#Phân loại), Kết quả (#Kết quả)"
raw#4: FAIL "SĐT không hợp lệ" (đúng)
raw#5: FAIL "Thiếu tên khách hàng" (đúng)
```

**Ý nghĩa**: **mọi lead import CSV/webhook đều fail** trừ khi có đúng 2 trường custom của Team Hợi — vô lý.

**File nghi vấn**: `app/Jobs/ProcessRawLead.php` phần validate custom field required. Cần chỉ apply CustomField cho org tương ứng của lead (nếu org=null thì chỉ mức công ty).

- **Recall UI trên kho cá nhân của CM**: kho cá nhân của cmbk chỉ hiển thị lead do CHÍNH cmbk sở hữu, không cho filter theo team member để recall. Nếu vậy CM booking phải "giả sale" mới thấy được lead đã chia — không đúng.

## ✅ Đã pass (không có bug)

- **book1@** — Update note, phân loại kết quả OK. `/leads/create` 403. `/distribution/rules` 403.
- **thk@** — Vào lead của mình (KH-020) OK. Vào lead của nhg (sale khác, id 13) → **403 đúng**. Sale có `lead.create` trong seeder → nút "+ Tạo mới Lead" hợp lệ.

---

## Cách tái hiện toàn bộ

```bash
php artisan migrate:fresh --seed
php artisan serve
# → login page1@longevity.com.vn / 123456
# → /leads/create → mở dropdown "Nhóm nguồn" → thấy chỉ 2 option
```
