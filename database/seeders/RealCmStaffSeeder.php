<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\Lead;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Phase 6.6 — nhân sự thật của chi nhánh Hà Nội + HCM.
 *
 * Cơ cấu:
 * - 2 CM Hà Nội (Giang, Hợi) — assignment tại Cơ sở HN, scope custom = HN.
 * - 3 CM HCM (Trâm, Thư, Lan) — assignment tại Cơ sở HCM.
 * - 1 DM HCM (Ngân) — cao nhất HCM.
 * - 2 Team Leader (Đức @ Team Mr.Hợi, Quỳn @ Team Ms.Ashley), scope team.
 * - 1 Trợ lý kinh doanh (Tự) — assignment tại Công ty, scope custom = toàn công ty.
 * - 17 chuyên viên tư vấn (SHC/HC) — role Sale, scope self, assignment tại team tương ứng.
 *
 * Idempotent: updateOrCreate theo email; assignment chỉ tạo khi user chưa có assignment tương ứng role đó.
 */
class RealCmStaffSeeder extends Seeder
{
    private const PASSWORD = '123456';

    public function run(): void
    {
        $rootCompany = OrgUnit::firstWhere('code', 'company');
        $branchHn = OrgUnit::firstWhere('code', 'branch-hn');
        $branchHcm = OrgUnit::firstWhere('code', 'branch-hcm');

        // 2026-07-16: 2 team Giang + Hợi thuộc Phòng Marketing (dưới Cơ sở HN), tên đầy đủ họ tên.
        $mktHn = OrgUnit::firstWhere('code', 'marketing-hn')
            ?? OrgUnit::createNode(['name' => 'Marketing', 'code' => 'marketing-hn'], $branchHn);
        $teamGiang = OrgUnit::firstWhere('code', 'team-giang')
            ?? OrgUnit::createNode(['name' => 'Team Trần Thị Thu Giang', 'code' => 'team-giang'], $mktHn);
        $teamHoiHn = OrgUnit::firstWhere('code', 'team-hoi-hn')
            ?? OrgUnit::createNode(['name' => 'Team Tạ Văn Hợi', 'code' => 'team-hoi-hn'], $mktHn);
        // 2026-07-16: HCM đồng bộ HN/ĐN — Cơ sở > Marketing > Team Ashley > (Booking + Sale).
        $mktHcm = OrgUnit::firstWhere('code', 'marketing-hcm')
            ?? OrgUnit::createNode(['name' => 'Marketing', 'code' => 'marketing-hcm'], $branchHcm);
        $teamAshley = OrgUnit::firstWhere('code', 'team-ashley')
            ?? OrgUnit::createNode(['name' => 'Team Ms. Ashley', 'code' => 'team-ashley'], $mktHcm);

        // 2026-07-16: gộp CM khu vực về role chung "CM sale" — khu vực do assignment quyết định.
        $roleCmHn = Role::firstWhere('name', 'CM sale');
        $roleCmHcm = Role::firstWhere('name', 'CM sale');
        $roleTl = Role::firstWhere('name', 'Team Leader');
        $roleAssistant = Role::firstWhere('name', 'Trợ lý kinh doanh');
        $roleDmHcm = Role::firstWhere('name', 'DM HCM');
        $roleSale = Role::firstWhere('name', 'Sale');

        // Danh sách chuẩn: [email, name, role, org, data_scope, scope_nodes, job_title]
        $staff = [
            // === HN — 2 CM (Cơ sở), scope custom = branch-hn ===
            // 2026-07-16: CM Giang + CM Hợi nằm trong team riêng, scope team.
            ['ttg@longevity.com.vn', 'Trần Thị Thu Giang', $roleCmHn, $teamGiang, Assignment::SCOPE_TEAM, [], 'Clinic Manager'],
            ['tvh@longevity.com.vn', 'Tạ Văn Hợi', $roleCmHn, $teamHoiHn, Assignment::SCOPE_TEAM, [], 'Clinic Manager'],
            // TL Đức (Team Mr.Hợi)
            ['nhd@longevity.com.vn', 'Nguyễn Hoành Đức', $roleTl, $teamHoiHn, Assignment::SCOPE_TEAM, [], 'Team Leader'],

            // 2026-07-16: SHC/HC sale HN nằm trong Team Sale con của mỗi team CM.
            // === HN — Chuyên viên Team Trần Thị Thu Giang → Team Sale ===
            ['thk@longevity.com.vn', 'Trần Huy Kiên', $roleSale, OrgUnit::firstWhere('code','team-giang-sale'), Assignment::SCOPE_SELF, [], 'HC'],
            ['nhg@longevity.com.vn', 'Nguyễn Hương Giang', $roleSale, OrgUnit::firstWhere('code','team-giang-sale'), Assignment::SCOPE_SELF, [], 'SHC'],
            ['nmp@longevity.com.vn', 'Nguyễn Minh Phương', $roleSale, OrgUnit::firstWhere('code','team-giang-sale'), Assignment::SCOPE_SELF, [], 'HC'],
            ['nta@longevity.com.vn', 'Nguyễn Thị Anh', $roleSale, OrgUnit::firstWhere('code','team-giang-sale'), Assignment::SCOPE_SELF, [], 'HC'],
            ['ntn@longevity.com.vn', 'Nguyễn Thị Nga', $roleSale, OrgUnit::firstWhere('code','team-giang-sale'), Assignment::SCOPE_SELF, [], 'SHC'],
            ['cla@longevity.com.vn', 'Cao Thị Lan Anh', $roleSale, OrgUnit::firstWhere('code','team-giang-sale'), Assignment::SCOPE_SELF, [], 'SHC'],

            // === HN — Chuyên viên Team Tạ Văn Hợi → Team Sale ===
            ['ptt@longevity.com.vn', 'Phạm Thanh Trúc', $roleSale, OrgUnit::firstWhere('code','team-hoi-sale'), Assignment::SCOPE_SELF, [], 'HC'],
            ['ntt@longevity.com.vn', 'Nguyễn Thị Thúy', $roleSale, OrgUnit::firstWhere('code','team-hoi-sale'), Assignment::SCOPE_SELF, [], 'SHC'],
            ['pta@longevity.com.vn', 'Phạm Tú Anh', $roleSale, OrgUnit::firstWhere('code','team-hoi-sale'), Assignment::SCOPE_SELF, [], 'HC'],
            ['ntm@longevity.com.vn', 'Nguyễn Trà My', $roleSale, OrgUnit::firstWhere('code','team-hoi-sale'), Assignment::SCOPE_SELF, [], 'SHC'],
            ['nma@longevity.com.vn', 'Nguyễn Mai Anh', $roleSale, OrgUnit::firstWhere('code','team-hoi-sale'), Assignment::SCOPE_SELF, [], 'HC'],

            // === HCM — 1 DM + 3 CM + 1 TL + 1 Trợ lý ===
            ['tnkn@longevity.com.vn', 'Trần Nguyễn Kim Ngân', $roleDmHcm, $branchHcm, Assignment::SCOPE_CUSTOM, [$branchHcm?->id], 'DM'],
            ['ptkq@longevity.com.vn', 'Phan Trần Khánh Quỳn', $roleTl, $teamAshley, Assignment::SCOPE_TEAM, [], 'Team Leader'],
            // 2026-07-16: 3 CM HCM là đồng CM Team Sale Ashley (scope team).
            ['tbt@longevity.com.vn', 'Trần Thị Bích Trâm', $roleCmHcm, OrgUnit::firstWhere('code','team-ashley-sale'), Assignment::SCOPE_TEAM, [], 'Clinic Manager'],
            ['nmt@longevity.com.vn', 'Nguyễn Thị Minh Thư', $roleCmHcm, OrgUnit::firstWhere('code','team-ashley-sale'), Assignment::SCOPE_TEAM, [], 'Trợ lý kinh doanh Clinic Manager (Assistant CM HCM)'],
            ['hbtl@longevity.com.vn', 'Huỳnh Bùi Thanh Lan', $roleCmHcm, OrgUnit::firstWhere('code','team-ashley-sale'), Assignment::SCOPE_TEAM, [], 'Clinic Manager'],
            // Trợ lý kinh doanh: scope custom toàn công ty — view-only mọi lead theo doc Phase 6.6.
            ['lpt@longevity.com.vn', 'Lê Thị Phương Tự', $roleAssistant, OrgUnit::firstWhere('code','company'), Assignment::SCOPE_CUSTOM, [OrgUnit::firstWhere('code','company')?->id], 'Trợ lý kinh doanh'],

            // === HCM — Chuyên viên Team Ms.Ashley ===
            // 2026-07-16: SHC/HC sale HCM nằm trong Team Sale (con của Team Ashley).
            ['tyn@longevity.com.vn', 'Trương Thị Yến Nhi', $roleSale, OrgUnit::firstWhere('code','team-ashley-sale'), Assignment::SCOPE_SELF, [], 'SHC'],
            ['nhn@longevity.com.vn', 'Nguyễn Thị Hoài Như', $roleSale, OrgUnit::firstWhere('code','team-ashley-sale'), Assignment::SCOPE_SELF, [], 'SHC'],
            ['hmm@longevity.com.vn', 'Huỳnh Thị My My', $roleSale, OrgUnit::firstWhere('code','team-ashley-sale'), Assignment::SCOPE_SELF, [], 'HC'],
            ['ntt2@longevity.com.vn', 'Nguyễn Thị Thanh', $roleSale, OrgUnit::firstWhere('code','team-ashley-sale'), Assignment::SCOPE_SELF, [], 'HC'],
            ['nkc@longevity.com.vn', 'Nguyễn Thị Kim Chi', $roleSale, OrgUnit::firstWhere('code','team-ashley-sale'), Assignment::SCOPE_SELF, [], 'HC'],
            ['lpd@longevity.com.vn', 'Lê Phát Đạt', $roleSale, OrgUnit::firstWhere('code','team-ashley-sale'), Assignment::SCOPE_SELF, [], 'SHC'],
        ];

        foreach ($staff as [$email, $name, $role, $org, $scope, $scopeNodes, $jobTitle]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'job_title' => $jobTitle,
                    'password' => self::PASSWORD,
                    'status' => User::STATUS_ACTIVE,
                ]
            );

            if (! $role || ! $org) {
                continue;
            }

            // Nếu user đã có assignment role này (có thể ở org cũ) → migrate về org/scope đúng
            $assignment = Assignment::where('user_id', $user->id)
                ->where('role_id', $role->id)
                ->first();
            if ($assignment) {
                $assignment->update([
                    'org_unit_id' => $org->id,
                    'data_scope' => $scope,
                ]);
            } else {
                $assignment = Assignment::create([
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                    'org_unit_id' => $org->id,
                    'data_scope' => $scope,
                ]);
            }
            $assignment->scopeNodes()->sync(array_filter($scopeNodes));
        }

        // Xóa 2 user demo cũ không dùng nữa (giữ cmdn cho Đà Nẵng chưa có nhân sự thật)
        User::whereIn('email', ['cmhn@longevity.com.vn', 'cmhcm@longevity.com.vn'])->delete();

        // === Sub-teams Booking/Sale dưới mỗi team CM + 1 CM Team (TL) mỗi sub-team ===
        $this->seedSubTeams($teamGiang, $teamHoiHn, $teamAshley, $roleTl);
    }

    private function seedSubTeams(OrgUnit $teamGiang, OrgUnit $teamHoiHn, OrgUnit $teamAshley, ?Role $roleTl): void
    {
        // 2026-07-16: chỉ tạo sub org units, không seed placeholder user cho Giang/Hợi
        // (đã có cmbk@/cmsale@ demo thật vào Team Booking Giang / Team Sale Hợi qua Phase66FlowSeeder).
        $subs = [
            ['team-giang-booking',  'Team Booking',  $teamGiang,   null,                              null],
            ['team-giang-sale',     'Team Sale',     $teamGiang,   null,                              null],
            ['team-hoi-booking',    'Team Booking',  $teamHoiHn,   null,                              null],
            ['team-hoi-sale',       'Team Sale',     $teamHoiHn,   null,                              null],
            ['team-ashley-booking', 'Team Booking',  $teamAshley,  null,                              null],
            ['team-ashley-sale',    'Team Sale',     $teamAshley,  null,                              null],
        ];

        $subUnits = [];
        foreach ($subs as [$code, $name, $parent, $email, $userName]) {
            $unit = OrgUnit::firstWhere('code', $code)
                ?? OrgUnit::createNode(['name' => $name, 'code' => $code], $parent);
            $subUnits[$code] = $unit;

            if ($email === null || ! $roleTl) {
                continue;
            }

            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $userName,
                    'job_title' => 'CM Team',
                    'password' => self::PASSWORD,
                    'status' => User::STATUS_ACTIVE,
                ]
            );

            $assignment = Assignment::where('user_id', $user->id)
                ->where('role_id', $roleTl->id)
                ->first();
            if ($assignment) {
                $assignment->update(['org_unit_id' => $unit->id, 'data_scope' => Assignment::SCOPE_TEAM]);
            } else {
                Assignment::create([
                    'user_id' => $user->id,
                    'role_id' => $roleTl->id,
                    'org_unit_id' => $unit->id,
                    'data_scope' => Assignment::SCOPE_TEAM,
                ]);
            }
        }

        $this->reassignHoiStaff($subUnits['team-hoi-booking'], $subUnits['team-hoi-sale']);
        $this->seedHoiLeads($subUnits['team-hoi-booking'], $subUnits['team-hoi-sale']);
    }

    /** Chuyển các Sale không xung đột role về sub-team Booking/Sale của Team Hợi. */
    private function reassignHoiStaff(OrgUnit $booking, OrgUnit $sale): void
    {
        $roleSale = Role::firstWhere('name', 'Sale');
        if (! $roleSale) {
            return;
        }

        $moves = [
            $sale->id => ['Nguyễn Hương Giang', 'Nguyễn Trà My', 'Cao Thị Lan Anh', 'Nguyễn Thị Nga', 'Nguyễn Thị Thúy'],
            $booking->id => ['Phạm Tú Anh', 'Phạm Thanh Trúc', 'Nguyễn Mai Anh', 'Trần Huy Kiên', 'Nguyễn Thị Anh'],
        ];

        foreach ($moves as $orgId => $names) {
            foreach ($names as $name) {
                $user = User::firstWhere('name', $name);
                if (! $user) {
                    continue;
                }
                Assignment::where('user_id', $user->id)
                    ->where('role_id', $roleSale->id)
                    ->update(['org_unit_id' => $orgId, 'data_scope' => Assignment::SCOPE_SELF]);
            }
        }
    }

    /** 5 lead cho Team Booking Hợi + 5 lead cho Team Sale Hợi, pool_level=team → chỉ team đó thấy. */
    private function seedHoiLeads(OrgUnit $booking, OrgUnit $sale): void
    {
        $admin = User::firstWhere('email', 'admin@longevity.com.vn');

        $bookingLeads = [
            ['Nguyễn Thị Booking 1', '0981000001'],
            ['Trần Văn Booking 2',   '0981000002'],
            ['Lê Thị Booking 3',     '0981000003'],
            ['Phạm Văn Booking 4',   '0981000004'],
            ['Đỗ Thị Booking 5',     '0981000005'],
        ];
        $saleLeads = [
            ['Nguyễn Văn Sale 1',    '0982000001'],
            ['Trần Thị Sale 2',      '0982000002'],
            ['Lê Văn Sale 3',        '0982000003'],
            ['Phạm Thị Sale 4',      '0982000004'],
            ['Đỗ Văn Sale 5',        '0982000005'],
        ];

        foreach ([[$booking, $bookingLeads, 'Team Booking Hợi'], [$sale, $saleLeads, 'Team Sale Hợi']] as [$unit, $rows, $note]) {
            foreach ($rows as [$name, $rawPhone]) {
                $phone = Lead::normalizePhone($rawPhone) ?? $rawPhone;
                Lead::firstOrCreate(
                    ['phone' => $phone],
                    [
                        'name' => $name,
                        'received_date' => now()->toDateString(),
                        'classification' => 'new',
                        'pool_level' => Lead::POOL_TEAM,
                        'source_group' => Lead::SOURCE_MARKETING,
                        'approval_status' => 'none',
                        'receiver_id' => $admin?->id,
                        'org_unit_id' => $unit->id,
                        'note' => 'Demo khách riêng ' . $note,
                    ]
                )->generateCode();
            }
        }
    }
}
