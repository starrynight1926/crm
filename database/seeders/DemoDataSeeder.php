<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\CustomField;
use App\Models\Lead;
use App\Models\OrgUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Dữ liệu mẫu: nhân sự 2 phòng, khách kho chung, và quy tắc trường (mã phân loại
 * + phân loại nguồn) cho phòng Kinh doanh / Marketing. Idempotent qua updateOrCreate.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $sales = OrgUnit::firstWhere('code', 'sales');
        // "marketing" theo phòng ban thực tế (marketing-hcm, marketing-dn, ...). Fallback về code=marketing để tương thích DB cũ.
        $marketingOrgs = OrgUnit::where('code', 'like', 'marketing%')->get();
        if ($marketingOrgs->isEmpty()) {
            $marketingOrgs = OrgUnit::where('code', 'marketing')->get();
        }
        $marketing = $marketingOrgs->first(); // vẫn giữ 1 biến cho các đoạn cũ dùng $marketing
        $saleRole = Role::firstWhere('name', 'Sale');

        // Role Sale cần quyền cơ bản để thao tác lead (chỉ gán nếu chưa có quyền nào)
        if ($saleRole && $saleRole->permissions()->count() === 0) {
            $saleRole->permissions()->sync(
                Permission::whereIn('key', ['lead.view', 'lead.view_phone', 'lead.create', 'lead.update', 'payment.record'])->pluck('id')
            );
        }

        // ── Nhân sự ──────────────────────────────────────────────
        $this->staff('nvkd@longevity.com.vn', 'NV Kinh Doanh', $saleRole, $sales);
        $this->staff('nvmkt@longevity.com.vn', 'NV Marketing', $saleRole, $marketing);

        // 2026-07-16: xóa demo cmhn/cmdn/cmhcm — có nhân sự thật (ttg/ltkp/tbt).
        \App\Models\User::whereIn('email', [
            'cmhn@longevity.com.vn', 'cmdn@longevity.com.vn', 'cmhcm@longevity.com.vn',
        ])->delete();

        // ── Khách hàng demo (5 nguồn khác nhau, minh họa 6 luồng Phase 6.6) ──
        $receiver = User::firstWhere('email', 'admin@longevity.com.vn');
        // [sourceGroup, poolLevel, ghi chú]
        $demo = [
            ['marketing', 'common', 'Marketing → kho chung'],
            ['data_cold', 'common', 'Data lạnh → kho chung'],
            ['bdm', 'common', 'BDM → kho chung'],
            ['referral', 'common', 'Bạn giới thiệu — người up tự chia sale'],
            ['walk_in', 'common', 'Khách tự đến — chờ CM cơ sở duyệt'],
        ];
        foreach ($demo as $i => [$sg, $pool, $note]) {
            $n = $i + 1;
            $phone = Lead::normalizePhone('091558800' . $n) ?? '091558800' . $n;
            Lead::firstOrCreate(
                ['phone' => $phone],
                [
                    'name' => 'Khách test' . $n,
                    'received_date' => now()->toDateString(),
                    'classification' => 'new',
                    'pool_level' => $pool,
                    'source_group' => $sg,
                    'approval_status' => $sg === 'walk_in' ? 'pending' : 'none',
                    'receiver_id' => $receiver?->id,
                    'org_unit_id' => null,
                    'note' => $note,
                ]
            )->generateCode();
        }

        // ── Seed lead demo cho 3 kho (2026-07-16) — phục vụ demo luồng CM/Booking/Sale ──
        $this->seedFlowLeads($receiver);

        // ── Quy tắc trường ───────────────────────────────────────
        // "Nguồn" cấp công ty ĐÃ GỠ: trùng lặp với enum source_group của lead
        // (Marketing→MKT, BDM→BDM…), source_group giờ tự nối mã vào KH-{id}-{SRC}.
        CustomField::whereNull('org_unit_id')->where('key', 'phan_loai')->get()->each->delete();

        // Dọn field mã cố định theo phòng (KD/MKT) — cũ, giữ lại thì mã KH bị nối lặp.
        CustomField::whereIn('org_unit_id', array_filter([$sales?->id, $marketing?->id]))
            ->where('key', 'ma_phan_loai')
            ->get()->each->delete();
        // "Phân loại" cũ ở phòng KD cũng bỏ
        if ($sales) {
            CustomField::where('org_unit_id', $sales->id)->where('key', 'phan_loai')->get()->each->delete();
        }

        // Cấp phòng Marketing: các trường theo MẪU PHÒNG MARKETING (không nối mã).
        // Có nhiều phòng Marketing (marketing-hcm, marketing-dn, ...), seed cho mỗi phòng.
        foreach ($marketingOrgs as $mkt) {
            $this->textField($mkt->id, 'page', 'Page', 1);
            // Nếu tồn tại field nguon_qc dạng text (do seed trước) → xoá để chuyển sang select
            CustomField::where('org_unit_id', $mkt->id)->where('key', 'nguon_qc')
                ->where('field_type', 'text')->get()->each->delete();
            $this->selectField($mkt->id, 'nguon_qc', 'Nguồn QC',
                $this->flat(['Facebook', 'Google', 'TikTok', 'Zalo', 'Quét Inbox', 'Khác']), 2);
            $this->selectField($mkt->id, 'khu_vuc', 'Khu vực',
                $this->flat(['TP.HCM', 'TỈNH', 'Hà Nội']), 3);

            // Bỏ field cũ (thay cho select "Nguồn quảng cáo" + cột ad_source đã drop)
            CustomField::where('org_unit_id', $mkt->id)
                ->whereIn('key', ['nguon_quang_cao'])
                ->get()->each->delete();

            // 2026-07-21: gộp 12 tick funnel thành 2 select "Phân loại" + "Kết quả"
            // (giống Team Hợi). Dọn sạch 12 tick cũ + values nếu còn.
            $oldTickKeys = [
                'tick_follow','tick_net','tick_tai_chinh_yeu','tick_quan_tam','tick_tham_khao',
                'tick_tim_hieu','tick_goi_lai_sau','tick_klld','tick_missed',
                'tick_booking','tick_show','tick_close',
            ];
            $oldTickFieldIds = CustomField::where('org_unit_id', $mkt->id)
                ->whereIn('key', $oldTickKeys)->pluck('id')->all();
            if ($oldTickFieldIds) {
                DB::table('lead_custom_values')->whereIn('custom_field_id', $oldTickFieldIds)->delete();
                CustomField::whereIn('id', $oldTickFieldIds)->delete();
            }

            $this->selectField($mkt->id, 'phan_loai', 'Phân loại', $this->flat([
                'Follow', 'Nét', 'Tài chính yếu', 'Quan tâm', 'Tham khảo',
                'Tìm hiểu', 'Gọi lại sau', 'KLLD', 'Missed',
            ]), 4);
            $this->selectField($mkt->id, 'ket_qua', 'Kết quả', $this->flat([
                'Booking', 'Show', 'Close',
            ]), 5);

            // Field seed cũ đã bỏ
            CustomField::where('org_unit_id', $mkt->id)
                ->whereIn('key', ['phan_loai_cs'])
                ->get()->each->delete();
        }

        // Chuyển "Page" từ custom field cấp công ty → cấp phòng Marketing (khái niệm Page
        // vốn chỉ của Marketing). Chia đều value theo lead.org_unit_id thuộc phòng nào.
        $oldPage = CustomField::whereNull('org_unit_id')->where('key', 'page')->first();
        if ($oldPage && $marketingOrgs->isNotEmpty()) {
            foreach ($marketingOrgs as $mkt) {
                $newPage = CustomField::where('org_unit_id', $mkt->id)->where('key', 'page')->first();
                if (! $newPage) continue;
                // Lead thuộc org này (hoặc con của nó) — con của Marketing chính là team booking/sale...
                $orgIds = OrgUnit::where('id', $mkt->id)->orWhere('parent_id', $mkt->id)->pluck('id')->all();
                DB::table('lead_custom_values')
                    ->where('custom_field_id', $oldPage->id)
                    ->whereIn('lead_id', function ($q) use ($orgIds) {
                        $q->select('id')->from('leads')->whereIn('org_unit_id', $orgIds);
                    })
                    ->whereNotIn('lead_id', function ($q) use ($newPage) {
                        // MySQL không cho tham chiếu trực tiếp bảng đang UPDATE trong subquery
                        // → bọc thêm 1 lớp SELECT để MySQL materialize thành bảng dẫn xuất.
                        $q->select('lead_id')->fromSub(function ($sub) use ($newPage) {
                            $sub->select('lead_id')->from('lead_custom_values')
                                ->where('custom_field_id', $newPage->id);
                        }, 'lcv_existing');
                    })
                    ->update(['custom_field_id' => $newPage->id]);
            }
            // Xoá field page cấp công ty (cascade các value còn lại của lead không thuộc marketing).
            $oldPage->delete();
        }
    }

    /**
     * Seed 15 lead demo phân bổ đúng 3 kho:
     * - 5 lead kho chung toàn cty (nhiều nguồn, chưa chia)
     * - 3 lead kho cá nhân sale (chăm khách)
     * - 3 lead kho team booking (chờ gọi)
     * - 4 lead kho team CM (chờ CM chia sang sale)
     * Idempotent qua Lead::firstOrCreate theo phone.
     */
    private function seedFlowLeads(?User $admin): void
    {
        $mk = function (array $attrs) use ($admin) {
            $phone = Lead::normalizePhone($attrs['phone']) ?? $attrs['phone'];
            $lead = Lead::firstOrCreate(['phone' => $phone], array_merge([
                'received_date' => now()->toDateString(),
                'classification' => 'new',
                'source_group' => 'marketing',
                'pool_level' => 'common',
                'receiver_id' => $admin?->id,
                'approval_status' => 'none',
            ], $attrs, ['phone' => $phone]));
            $lead->generateCode();
        };

        $userByEmail = fn (string $email) => User::firstWhere('email', $email);
        $orgId = fn (string $code) => OrgUnit::firstWhere('code', $code)?->id;

        // 1) 5 lead kho chung — 5 nguồn khác nhau
        foreach ([
            ['0917100001', 'Đỗ Minh Đạt',    'marketing', 'none'],
            ['0917100002', 'Trần Ngọc Hoa',  'marketing', 'none'],
            ['0917100003', 'Lê Văn Sơn',     'data_cold', 'none'],
            ['0917100004', 'Phạm Thị Yến',   'bdm',       'none'],
            ['0917100005', 'Nguyễn Tấn Vũ',  'walk_in',   'pending'],
        ] as [$p, $n, $sg, $ap]) {
            $mk(['phone' => $p, 'name' => $n, 'source_group' => $sg, 'pool_level' => 'common', 'org_unit_id' => null, 'approval_status' => $ap]);
        }

        // 2) 3 lead kho cá nhân — sale đang chăm (funnel Follow/Booking/Show)
        $sales = [
            ['0917200001', 'Ngô Thị Hà',    'follow',  $userByEmail('tyn@longevity.com.vn')],   // Yến Nhi (HCM)
            ['0917200002', 'Vũ Anh Tuấn',   'booking', $userByEmail('nmp@longevity.com.vn')],   // Minh Phương (Giang)
            ['0917200003', 'Bùi Kim Chi',   'show',    $userByEmail('nhg@longevity.com.vn')],   // Hương Giang (Hợi)
        ];
        foreach ($sales as [$p, $n, $cls, $owner]) {
            $orgOfOwner = $owner?->assignments()->first()?->org_unit_id;
            $mk(['phone' => $p, 'name' => $n, 'classification' => $cls, 'pool_level' => 'personal',
                 'owner_id' => $owner?->id, 'receiver_id' => $owner?->id, 'org_unit_id' => $orgOfOwner]);
        }

        // 3) 3 lead kho team booking — chờ Team booking gọi
        foreach ([
            ['0917300001', 'Hoàng Thị Nga',   'marketing', $orgId('team-giang-booking')],
            ['0917300002', 'Trần Văn Dũng',   'data_cold', $orgId('team-hoi-booking')],
            ['0917300003', 'Nguyễn Thị Mai',  'bdm',       $orgId('team-ashley-booking')],
        ] as [$p, $n, $sg, $org]) {
            $mk(['phone' => $p, 'name' => $n, 'source_group' => $sg, 'pool_level' => 'team', 'org_unit_id' => $org]);
        }

        // 4) 4 lead kho team CM — chờ CM chia sang sale (classification đã có phân hạng)
        foreach ([
            ['0917400001', 'Lê Thị Linh',    'follow',  'marketing', $orgId('team-giang')],
            ['0917400002', 'Phạm Văn Nam',   'net',     'marketing', $orgId('team-giang')],
            ['0917400003', 'Trần Thị Hoa',   'follow',  'data_cold', $orgId('team-hoi-hn')],
            ['0917400004', 'Đỗ Ngọc Bích',   'booking', 'bdm',       $orgId('team-hoi-hn')],
        ] as [$p, $n, $cls, $sg, $org]) {
            $mk(['phone' => $p, 'name' => $n, 'source_group' => $sg, 'classification' => $cls,
                 'pool_level' => 'team', 'org_unit_id' => $org]);
        }
    }

    /** Danh sách giá trị mà Giải thích = chính Giá trị (map value => value). */
    private function flat(array $values): array
    {
        return array_combine($values, $values);
    }

    /** Trường text tự do — không nối mã. */
    private function textField(?int $orgId, string $key, string $label, int $position): void
    {
        CustomField::updateOrCreate(
            ['org_unit_id' => $orgId, 'key' => $key],
            [
                'label' => $label,
                'field_type' => 'text',
                'options' => null,
                'rules' => null,
                'affects_code' => false,
                'required' => false,
                'position' => $position,
                'status' => CustomField::STATUS_ACTIVE,
                'active' => true,
            ]
        );
    }

    /** Trường "Ô tích" (có/không) — không có option, không nối mã. */
    private function tickField(?int $orgId, string $key, string $label, int $position): void
    {
        CustomField::updateOrCreate(
            ['org_unit_id' => $orgId, 'key' => $key],
            [
                'label' => $label,
                'field_type' => 'tick',
                'options' => null,
                'rules' => null,
                'affects_code' => false,
                'required' => false,
                'position' => $position,
                'status' => CustomField::STATUS_ACTIVE,
                'active' => true,
            ]
        );
    }

    private function staff(string $email, string $name, ?Role $role, ?OrgUnit $org, string $scope = Assignment::SCOPE_SELF): void
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => '123456', 'status' => User::STATUS_ACTIVE]
        );

        if ($role && $org && ! Assignment::where('user_id', $user->id)->exists()) {
            Assignment::create([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'org_unit_id' => $org->id,
                'data_scope' => $scope,
            ]);
        }
    }

    /**
     * Trường select: Giá trị (options) + Giải thích (option_labels). $affectsCode = true
     * thì Giá trị được nối vào sau mã KH khi lead dùng trường này.
     */
    private function selectField(?int $orgId, string $key, string $label, array $valueLabels, int $position, bool $affectsCode = false): void
    {
        CustomField::updateOrCreate(
            ['org_unit_id' => $orgId, 'key' => $key],
            [
                'label' => $label,
                'field_type' => 'select',
                'options' => array_keys($valueLabels),
                'rules' => ['option_labels' => $valueLabels],
                'affects_code' => $affectsCode,
                'required' => false,
                'position' => $position,
                'status' => CustomField::STATUS_ACTIVE,
                'active' => true,
            ]
        );
    }
}
