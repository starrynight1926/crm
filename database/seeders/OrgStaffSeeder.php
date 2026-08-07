<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeder gộp: cây tổ chức + vai trò + chức danh + nhân sự + assignment.
 * Reproduce đúng state đang chạy trên DB — upsert theo code/name/email nên chạy lại an toàn.
 */
class OrgStaffSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedOrgTree();
        $this->seedRoles();
        $this->seedUsers();
        $this->seedAssignments();

        $this->command?->info('OrgStaffSeeder: org + role + user + assignment đã đồng bộ.');
    }

    // ---------------------------------------------------------------------
    // 1) Cây tổ chức
    // ---------------------------------------------------------------------
    private function seedOrgTree(): void
    {
        // 2026-08-05 restructure: Marketing (chỉ Team Nhập Lead) tách khỏi Kinh Doanh (PKD1/PKD2).
        // ĐN đồng dạng cấu trúc (đặc biệt về vai trò sale xuyên suốt).
        $tree = [
            ['code' => 'company', 'name' => 'Công ty', 'children' => [
                ['code' => 'branch-hn', 'name' => 'Cơ sở Hà Nội: 59 Ngô Thì Nhậm', 'children' => [
                    ['code' => 'marketing-hn', 'name' => 'Marketing', 'children' => [
                        ['code' => 'team-nhap-lead', 'name' => 'Team Nhập Lead'],
                    ]],
                    ['code' => 'kinh-doanh-hn', 'name' => 'Kinh Doanh', 'children' => [
                        ['code' => 'team-giang', 'name' => 'Phòng Kinh Doanh 1', 'children' => [
                            ['code' => 'team-giang-booking', 'name' => 'Team Booking'],
                            ['code' => 'team-giang-sale', 'name' => 'Team Sale'],
                        ]],
                        ['code' => 'team-hoi-hn', 'name' => 'Phòng Kinh Doanh 2', 'children' => [
                            ['code' => 'team-hoi-booking', 'name' => 'Team Booking'],
                            ['code' => 'team-hoi-sale', 'name' => 'Team Sale'],
                        ]],
                    ]],
                ]],
                ['code' => 'branch-hcm', 'name' => 'Cơ sở HCM: 207 Nguyễn Văn Thụ', 'children' => [
                    ['code' => 'marketing-hcm', 'name' => 'Marketing', 'children' => [
                        ['code' => 'team-nhap-lead-hcm', 'name' => 'Team Nhập Lead'],
                    ]],
                    ['code' => 'kinh-doanh-hcm', 'name' => 'Kinh Doanh', 'children' => [
                        ['code' => 'team-ashley', 'name' => 'Phòng Kinh Doanh 1', 'children' => [
                            ['code' => 'team-ashley-booking', 'name' => 'Team Booking'],
                            ['code' => 'team-ashley-sale', 'name' => 'Team Sale'],
                        ]],
                    ]],
                ]],
                ['code' => 'branch-dn', 'name' => 'Cơ sở Đà Nẵng', 'children' => [
                    ['code' => 'marketing-dn', 'name' => 'Marketing', 'children' => [
                        ['code' => 'team-nhap-lead-dn', 'name' => 'Team Nhập Lead'],
                    ]],
                    ['code' => 'kinh-doanh-dn', 'name' => 'Kinh Doanh', 'children' => [
                        ['code' => 'team-dn', 'name' => 'Phòng Kinh Doanh 1', 'children' => [
                            ['code' => 'team-dn-booking', 'name' => 'Team Booking'],
                            ['code' => 'team-dn-sale', 'name' => 'Team Sale'],
                        ]],
                    ]],
                ]],
                ['code' => 'ops-monitor', 'name' => 'Vận hành & Giám sát', 'children' => [
                    ['code' => 'ops-run', 'name' => 'Nhóm Vận Hành'],
                    ['code' => 'ops-monitor-sub', 'name' => 'Nhóm Giám Sát'],
                ]],
            ]],
        ];

        foreach ($tree as $node) {
            $this->upsertOrgNode($node, null);
        }
    }

    private function upsertOrgNode(array $def, ?OrgUnit $parent): void
    {
        $node = OrgUnit::firstWhere('code', $def['code']);
        if (! $node) {
            $node = OrgUnit::createNode(
                ['code' => $def['code'], 'name' => $def['name']],
                $parent
            );
        } else {
            $expectedParentId = $parent?->id;
            $needsMove = $node->parent_id !== $expectedParentId;
            if ($node->name !== $def['name'] || $needsMove) {
                $node->name = $def['name'];
                if ($needsMove) {
                    $node->parent_id = $expectedParentId;
                    $node->depth = $parent ? $parent->depth + 1 : 0;
                    $node->path = ($parent ? rtrim($parent->path, '/') : '') . '/' . $node->id . '/';
                }
                $node->save();
                if ($needsMove) {
                    $this->fixChildPaths($node);
                }
            }
        }

        foreach ($def['children'] ?? [] as $child) {
            $this->upsertOrgNode($child, $node);
        }
    }

    private function fixChildPaths(OrgUnit $parent): void
    {
        foreach ($parent->children as $child) {
            $child->depth = $parent->depth + 1;
            $child->path = rtrim($parent->path, '/') . '/' . $child->id . '/';
            $child->save();
            $this->fixChildPaths($child);
        }
    }

    // ---------------------------------------------------------------------
    // 2) Roles + permission
    // ---------------------------------------------------------------------
    private function seedRoles(): void
    {
        $roles = [
            'Admin' => [
                'desc' => 'Toàn quyền hệ thống',
                'perms' => ['connection.manage','contribution.set','field.approve','field.manage','lead.approve_source','lead.book_action','lead.create','lead.delete','lead.view_pool','lead.distribute','lead.distribute_tele','lead.distribute_sale','lead.distribute_to_team','lead.distribute_to_sale','lead.export','lead.import','lead.pull_pool','lead.read_booking','lead.recall','lead.update','lead.update_booking','lead.update_sale','lead.view','lead.view_phone','ops.manage','org.manage','payment.record','report.view','report.view_all','role.manage','rule.manage','service.manage','staff.manage','user.manage'],
            ],
            'Manager' => [
                'desc' => 'Quản lý team & chia số',
                'perms' => ['lead.approve_source','lead.book_action','lead.create','lead.view_pool','lead.distribute','lead.distribute_tele','lead.distribute_sale','lead.distribute_to_team','lead.distribute_to_sale','lead.read_booking','lead.recall','lead.update','lead.update_booking','lead.update_sale','lead.view','lead.view_phone','report.view'],
            ],
            'Sale' => [
                'desc' => 'Khai thác & chăm sóc khách hàng',
                'perms' => ['lead.create','lead.update','lead.consult','lead.view','report.view','payment.record'],
            ],
            'Observer' => [
                'desc' => 'Xem toàn bộ, không thêm/sửa/xóa dịch vụ và nhân sự',
                'perms' => ['lead.export','lead.view','lead.view_phone','lead.view_pool','report.view','report.view_all'],
            ],
            'Team Leader' => [
                'desc' => 'Trưởng nhóm — quyền như CM nhưng scope team, chia số cấp team',
                'perms' => ['lead.approve_source','lead.book_action','lead.create','lead.view_pool','lead.distribute','lead.distribute_tele','lead.distribute_sale','lead.distribute_to_sale','lead.read_booking','lead.recall','lead.update','lead.view','lead.view_phone','payment.record','report.view'],
            ],
            'Trợ lý kinh doanh' => [
                'desc' => 'Xem data cấp công ty, không thêm/sửa',
                'perms' => ['lead.view','report.view'],
            ],
            'DM HCM' => [
                'desc' => 'Directional Manager HCM — cao nhất khu vực HCM',
                'perms' => ['contribution.set','field.approve','field.manage','lead.approve_source','lead.book_action','lead.create','lead.delete','lead.view_pool','lead.distribute','lead.distribute_tele','lead.distribute_sale','lead.distribute_to_team','lead.distribute_to_sale','lead.export','lead.import','lead.read_booking','lead.recall','lead.update','lead.update_booking','lead.update_sale','lead.view','lead.view_phone','payment.record','report.view','report.view_all','rule.manage','service.manage','user.manage'],
            ],
            'Trực Page' => [
                'desc' => 'Team nhập lead marketing — up lead nguồn MKT / MKT BR / BDM. Xem lại được data đã up (imported_by), chia lại từ kho chung xuống team/sale cho CHÍNH lead mình up (scope theo imported_by).',
                'perms' => ['lead.create','lead.import','lead.view'],
            ],
            'CM booking' => [
                'desc' => 'CM Phòng Booking — up nguồn nhóm 1 (MKT / MKT BR / BDM), chia lead trong kho booking cho team booking, thu deposit booking (nếu cơ sở áp dụng)',
                'perms' => ['lead.book_action','lead.create','lead.view_pool','lead.distribute','lead.distribute_tele','lead.distribute_to_sale','lead.read_booking','lead.recall','lead.update','lead.update_booking','lead.view','lead.view_phone','payment.record','report.view'],
            ],
            'Team Tele' => [
                'desc' => 'Team booking — sửa được info khách khi phase=booking, bấm nút Đặt booking. Chuyển sang phase Sale sau khi có data booking (không ghi note dịch vụ).',
                'perms' => ['lead.book_action','lead.create','lead.read_booking','lead.update','lead.update_booking','lead.view','lead.view_phone'],
            ],
            'CM sale' => [
                'desc' => 'CM Phòng Kinh doanh — chia lead đã đồng ý sang sale + sửa info cá nhân khi ở phase Sale + duyệt/ghi thu tiền hộ team sale',
                'perms' => ['lead.book_action','lead.create','lead.distribute','lead.distribute_sale','lead.distribute_to_sale','lead.recall','lead.update','lead.update_sale','lead.consult','lead.view','lead.view_phone','payment.record','report.view','source.up.admin'],
            ],
            'Team sale' => [
                'desc' => 'Sale nhân viên — chăm sóc khách, ghi chú, phân loại, gắn dịch vụ, thu tiền khách trực tiếp cho lead mình phụ trách',
                'perms' => ['lead.update','lead.consult','lead.view','lead.view_phone','payment.record','report.view'],
            ],
            // Đà Nẵng — team làm xuyên suốt tele+book+sale, giữ job_title HC/Tele
            // nhưng có union perms Team sale + Team booking + book_action.
            'Team sale ĐN' => [
                'desc' => 'Team sale Đà Nẵng — làm xuyên suốt tele + booking + sale (union quyền). Tự up lead nguồn SA (Sale Appointment) — không chờ CM chia.',
                'perms' => [
                    'lead.view','lead.view_phone','lead.create','lead.update','lead.consult','report.view',
                    'lead.read_booking','lead.update_booking','lead.book_action',
                    'lead.update_sale',
                    'payment.record',
                    // 2026-08-07: Team Linda ĐN tự up lead SA để tele + booking cho bản thân.
                    'source.up.sa',
                ],
            ],
        ];

        foreach ($roles as $name => $def) {
            $role = Role::updateOrCreate(['name' => $name], ['description' => $def['desc']]);
            $permIds = Permission::whereIn('key', $def['perms'])->pluck('id');
            $role->permissions()->sync($permIds);
        }
    }

    // ---------------------------------------------------------------------
    // 3) Users (nhân sự + chức danh)
    // ---------------------------------------------------------------------
    /** Danh sách user seed — dùng cho cả seedUsers() và seedAssignments() (fallback match qua name). */
    private function userDefs(): array
    {
        return [
            // Hệ thống / demo
            ['email' => 'admin@longevity.com.vn',  'name' => 'Quản trị viên',       'job_title' => null],
            // 2026-08-05: BỎ nvkd + nvmkt — Marketing chỉ dùng "Tài khoản Trực Page" up lead,
            // Kinh doanh dùng các tài khoản CM/Sale/CM sale thật (Giang/Hợi/Ashley/CM sale…).

            // Observer (BOD / trợ lý / kế toán)
            ['email' => 'huyently@longevity.com.vn', 'name' => 'Huyền', 'job_title' => 'Trợ lý kinh doanh'],
            ['email' => 'hangktt@longevity.com.vn',  'name' => 'Hằng',  'job_title' => 'Kế toán trưởng'],
            ['email' => 'lyktdt@longevity.com.vn',   'name' => 'Ly',    'job_title' => 'Kế toán doanh thu'],
            ['email' => 'msan@longevity.com.vn',     'name' => 'An',    'job_title' => 'COO'],
            ['email' => 'mstuyet@longevity.com.vn',  'name' => 'Tuyết', 'job_title' => 'CEO'],

            // Admin hệ thống
            ['email' => 'baoit@longevity.com.vn', 'name' => 'Bảo', 'job_title' => 'IT hệ thống'],
            ['email' => 'tumod@longevity.com.vn', 'name' => 'Tú',  'job_title' => 'Kiểm soát hệ thống PK'],

            // Marketing Đà Nẵng — CM (Kim Phấn) + TL (Bông) + 3 HC + 4 Tele, gán chung marketing-dn
            ['email' => 'ltkp@longevity.com.vn', 'name' => 'Lương Thị Kim Phấn',      'job_title' => 'CM Marketing Đà Nẵng'],
            ['email' => 'ntb@longevity.com.vn',  'name' => 'Nguyễn Thị Bông',         'job_title' => 'Team Leader Marketing Đà Nẵng'],
            ['email' => 'ntan@longevity.com.vn', 'name' => 'Nguyễn Thị Ánh Nhung',    'job_title' => 'HC'],
            ['email' => 'lthu@longevity.com.vn', 'name' => 'Lê Thị Hoàng Uyên',       'job_title' => 'HC'],
            ['email' => 'ltkhi@longevity.com.vn','name' => 'Lương Thị Kim Hiếu',      'job_title' => 'HC'],
            ['email' => 'stk@longevity.com.vn',  'name' => 'Sử Trung Kiên',           'job_title' => 'Tele'],
            ['email' => 'lttv@longevity.com.vn', 'name' => 'Lương Thị Tường Vy',      'job_title' => 'Tele'],
            ['email' => 'tnah@longevity.com.vn', 'name' => 'Trần Ngọc An Hoà',        'job_title' => 'Tele'],
            ['email' => 'ntmh@longevity.com.vn', 'name' => 'Nguyễn Thị Mỹ Hạnh',      'job_title' => 'Tele'],

            // CM / TL / DM thật
            ['email' => 'ttg@longevity.com.vn',  'name' => 'Trần Thị Thu Giang',   'job_title' => 'Clinic Manager'],
            ['email' => 'tvh@longevity.com.vn',  'name' => 'Tạ Văn Hợi',           'job_title' => 'Clinic Manager'],
            ['email' => 'nhd@longevity.com.vn',  'name' => 'Nguyễn Hoành Đức',     'job_title' => 'Team Leader'],
            ['email' => 'tnkn@longevity.com.vn', 'name' => 'Trần Nguyễn Kim Ngân', 'job_title' => 'DM'],
            ['email' => 'ptkq@longevity.com.vn', 'name' => 'Phan Trần Khánh Quỳn', 'job_title' => 'Team Leader'],
            ['email' => 'tbt@longevity.com.vn',  'name' => 'Trần Thị Bích Trâm',   'job_title' => 'Clinic Manager'],
            ['email' => 'nmt@longevity.com.vn',  'name' => 'Nguyễn Thị Minh Thư',  'job_title' => 'Trợ lý kinh doanh Clinic Manager (Assistant CM HCM)'],
            ['email' => 'hbtl@longevity.com.vn', 'name' => 'Huỳnh Bùi Thanh Lan',  'job_title' => 'Clinic Manager'],
            ['email' => 'lpt@longevity.com.vn',  'name' => 'Lê Thị Phương Tự',     'job_title' => 'Trợ lý kinh doanh'],

            // Team Hợi HN (SHC = Senior HC, HC = Health Consultant)
            ['email' => 'thk@longevity.com.vn', 'name' => 'Trần Huy Kiên',         'job_title' => 'HC'],
            ['email' => 'nhg@longevity.com.vn', 'name' => 'Nguyễn Hương Giang',    'job_title' => 'SHC'],
            ['email' => 'nmp@longevity.com.vn', 'name' => 'Nguyễn Minh Phương',    'job_title' => 'HC'],
            ['email' => 'nta@longevity.com.vn', 'name' => 'Nguyễn Thị Anh',        'job_title' => 'HC'],
            ['email' => 'ntn@longevity.com.vn', 'name' => 'Nguyễn Thị Nga',        'job_title' => 'SHC'],
            ['email' => 'cla@longevity.com.vn', 'name' => 'Cao Thị Lan Anh',       'job_title' => 'SHC'],
            ['email' => 'ptt@longevity.com.vn', 'name' => 'Phạm Thanh Trúc',       'job_title' => 'HC'],
            ['email' => 'ntt@longevity.com.vn', 'name' => 'Nguyễn Thị Thúy',       'job_title' => 'SHC'],
            ['email' => 'pta@longevity.com.vn', 'name' => 'Phạm Tú Anh',           'job_title' => 'HC'],
            ['email' => 'ntm@longevity.com.vn', 'name' => 'Nguyễn Trà My',         'job_title' => 'SHC'],
            ['email' => 'nma@longevity.com.vn', 'name' => 'Nguyễn Mai Anh',        'job_title' => 'HC'],

            // Team Ashley HCM (sale)
            ['email' => 'tyn@longevity.com.vn',  'name' => 'Trương Thị Yến Nhi',  'job_title' => 'SHC'],
            ['email' => 'nhn@longevity.com.vn',  'name' => 'Nguyễn Thị Hoài Như', 'job_title' => 'SHC'],
            ['email' => 'hmm@longevity.com.vn',  'name' => 'Huỳnh Thị My My',     'job_title' => 'HC'],
            ['email' => 'ntt2@longevity.com.vn', 'name' => 'Nguyễn Thị Thanh',    'job_title' => 'HC'],
            ['email' => 'nkc@longevity.com.vn',  'name' => 'Nguyễn Thị Kim Chi',  'job_title' => 'HC'],
            ['email' => 'lpd@longevity.com.vn',  'name' => 'Lê Phát Đạt',         'job_title' => 'SHC'],

            // Nhân sự luồng 7 nguồn — không có job_title
            // Tài khoản Nhập Lead: mỗi cơ sở 1 account dùng chung cho team nhập lead.
            ['email' => 'hn.trucpage@longevity.com.vn',  'name' => 'Tài khoản Nhập Lead cơ sở HN',  'job_title' => null],
            ['email' => 'hcm.trucpage@longevity.com.vn', 'name' => 'Tài khoản Nhập Lead cơ sở HCM', 'job_title' => null],
            ['email' => 'dn.trucpage@longevity.com.vn',  'name' => 'Tài khoản Nhập Lead cơ sở ĐN',  'job_title' => null],
            // 2026-08-05: tài khoản test đặt tên "Tài khoản + chức năng" cho rõ.
            // Bỏ cmbktg (CM Booking Team Giang) + cmsale (CM Sale) — không cần trong luồng test cơ bản.
            // 2026-08-08: BỎ book1/book2 — trùng với hn.book01/hn.book02 (sau rename). Dùng 2 tk kia là đủ.
        ];
    }

    private function seedUsers(): void
    {
        $users = $this->userDefs();

        // Cleanup: user demo cũ "Phạm Nhập Lead 1" — xoá assignment + user nếu còn.
        $legacyPage = User::firstWhere('email', 'page1@longevity.com.vn');
        if ($legacyPage) {
            Assignment::where('user_id', $legacyPage->id)->delete();
            $legacyPage->delete();
        }

        foreach ($users as $u) {
            // Match theo email cũ; nếu không thấy (đã bị rename sang format vị trí), match qua tên.
            $existing = User::firstWhere('email', $u['email'])
                ?? User::firstWhere('name', $u['name']);
            if (! $existing) {
                User::create([
                    'name'      => $u['name'],
                    'email'     => $u['email'],
                    'job_title' => $u['job_title'],
                    'password'  => \App\Support\DefaultPassword::forEmail($u['email']),
                    'status'    => User::STATUS_ACTIVE,
                ]);
            } else {
                // Không đụng password của user đã có.
                $existing->update([
                    'name'      => $u['name'],
                    'job_title' => $u['job_title'],
                    'status'    => User::STATUS_ACTIVE,
                ]);
            }
        }
    }

    // ---------------------------------------------------------------------
    // 4) Assignments (user × role × org_unit + data_scope)
    // ---------------------------------------------------------------------
    private function seedAssignments(): void
    {
        // [email, role_name, org_code, scope, scope_node_codes[]]
        $assignments = [
            // Admin gốc
            ['admin@longevity.com.vn', 'Admin', 'ops-monitor', Assignment::SCOPE_CUSTOM, ['company']],

            // Observer nhóm giám sát — scope toàn công ty
            ['huyently@longevity.com.vn', 'Observer', 'ops-monitor-sub', Assignment::SCOPE_CUSTOM, ['company']],
            ['hangktt@longevity.com.vn',  'Observer', 'ops-monitor-sub', Assignment::SCOPE_CUSTOM, ['company']],
            ['lyktdt@longevity.com.vn',   'Observer', 'ops-monitor-sub', Assignment::SCOPE_CUSTOM, ['company']],
            ['msan@longevity.com.vn',     'Observer', 'ops-monitor-sub', Assignment::SCOPE_CUSTOM, ['company']],
            ['mstuyet@longevity.com.vn',  'Observer', 'ops-monitor-sub', Assignment::SCOPE_CUSTOM, ['company']],

            // Admin IT/QC nhóm vận hành
            ['baoit@longevity.com.vn', 'Admin', 'ops-run', Assignment::SCOPE_CUSTOM, ['company']],
            ['tumod@longevity.com.vn', 'Admin', 'ops-run', Assignment::SCOPE_CUSTOM, ['company']],

            // Sale demo

            // CM / TL / DM thật
            ['ttg@longevity.com.vn',  'CM sale',     'team-giang',    Assignment::SCOPE_TEAM,   []],
            ['tvh@longevity.com.vn',  'CM sale',     'team-hoi-hn',   Assignment::SCOPE_TEAM,   []],
            ['nhd@longevity.com.vn',  'Team Leader', 'team-hoi-hn',   Assignment::SCOPE_TEAM,   []],
            ['tnkn@longevity.com.vn', 'DM HCM',      'branch-hcm',    Assignment::SCOPE_CUSTOM, ['branch-hcm']],
            ['ptkq@longevity.com.vn', 'Team Leader', 'team-ashley',   Assignment::SCOPE_TEAM,   []],
            ['tbt@longevity.com.vn',  'CM sale',     'team-ashley-sale', Assignment::SCOPE_TEAM, []],
            ['hbtl@longevity.com.vn', 'CM sale',     'team-ashley-sale', Assignment::SCOPE_TEAM, []],
            ['nmt@longevity.com.vn',  'CM sale',     'team-ashley-sale', Assignment::SCOPE_TEAM, []],
            ['lpt@longevity.com.vn',  'Trợ lý kinh doanh', 'company', Assignment::SCOPE_CUSTOM, ['company']],
            // 2026-08-07: Team Linda ĐN — thuộc Kinh Doanh ĐN (team-dn), KHÔNG ở Marketing.
            //   Linda (Kim Phấn) = CM có cả 2 quyền chia số (distribute_tele + distribute_sale) qua role 'CM sale' mới.
            //   Bông = TL của cùng team này. 7 HC/Tele còn lại ở team-dn-sale (subtree, Bông vẫn thấy).
            ['ltkp@longevity.com.vn', 'CM sale',      'team-dn',       Assignment::SCOPE_TEAM,   []],
            ['ntb@longevity.com.vn',  'Team Leader',  'team-dn',       Assignment::SCOPE_TEAM,   []],
            // 2026-08-08: Đặc thù ĐN — Bông (TL) kiêm role CM sale để up được nguồn BDM/BOD/WI
            //   (perm source.up.admin). Các TL khác (HN/HCM) không có quyền này.
            ['ntb@longevity.com.vn',  'CM sale',      'team-dn',       Assignment::SCOPE_TEAM,   []],

            // 3 HC + 4 Tele đều gộp về team-dn-sale, role "Team sale ĐN" — union perms tele+book+sale.
            ['ntan@longevity.com.vn', 'Team sale ĐN', 'team-dn-sale',  Assignment::SCOPE_SELF, []],
            ['lthu@longevity.com.vn', 'Team sale ĐN', 'team-dn-sale',  Assignment::SCOPE_SELF, []],
            ['ltkhi@longevity.com.vn','Team sale ĐN', 'team-dn-sale',  Assignment::SCOPE_SELF, []],
            ['stk@longevity.com.vn',  'Team sale ĐN', 'team-dn-sale',  Assignment::SCOPE_SELF, []],
            ['lttv@longevity.com.vn', 'Team sale ĐN', 'team-dn-sale',  Assignment::SCOPE_SELF, []],
            ['tnah@longevity.com.vn', 'Team sale ĐN', 'team-dn-sale',  Assignment::SCOPE_SELF, []],
            ['ntmh@longevity.com.vn', 'Team sale ĐN', 'team-dn-sale',  Assignment::SCOPE_SELF, []],

            // 2026-08-08: Chuẩn hoá theo sbooking phong_ban_id:
            //   Phòng 29 = Team Giang → 6 sale (Kiên, Hương Giang, Phương, Anh, Nga, Lan Anh)
            //   Phòng 30 = Team Hợi  → 5 sale (Trúc, Thúy, Tú Anh, Trà My, Mai Anh)
            //   Sbooking không phân sub-team booking/sale → tất cả vào team-*-sale.
            //   Tk chung hn.book01/hn.book02 vẫn ở team-*-booking (role Team Tele).
            // Sale Team Giang HN (Phòng 29)
            ['thk@longevity.com.vn', 'Sale', 'team-giang-sale',  Assignment::SCOPE_SELF, []],
            ['nhg@longevity.com.vn', 'Sale', 'team-giang-sale',  Assignment::SCOPE_SELF, []],
            ['nmp@longevity.com.vn', 'Sale', 'team-giang-sale',  Assignment::SCOPE_SELF, []],
            ['nta@longevity.com.vn', 'Sale', 'team-giang-sale',  Assignment::SCOPE_SELF, []],
            ['ntn@longevity.com.vn', 'Sale', 'team-giang-sale',  Assignment::SCOPE_SELF, []],
            ['cla@longevity.com.vn', 'Sale', 'team-giang-sale',  Assignment::SCOPE_SELF, []],
            // Sale Team Hợi HN (Phòng 30)
            ['ptt@longevity.com.vn', 'Sale', 'team-hoi-sale',    Assignment::SCOPE_SELF, []],
            ['ntt@longevity.com.vn', 'Sale', 'team-hoi-sale',    Assignment::SCOPE_SELF, []],
            ['pta@longevity.com.vn', 'Sale', 'team-hoi-sale',    Assignment::SCOPE_SELF, []],
            ['ntm@longevity.com.vn', 'Sale', 'team-hoi-sale',    Assignment::SCOPE_SELF, []],
            ['nma@longevity.com.vn', 'Sale', 'team-hoi-sale',    Assignment::SCOPE_SELF, []],

            // Sale Team Ashley HCM
            ['tyn@longevity.com.vn',  'Sale', 'team-ashley-sale', Assignment::SCOPE_SELF, []],
            ['nhn@longevity.com.vn',  'Sale', 'team-ashley-sale', Assignment::SCOPE_SELF, []],
            ['hmm@longevity.com.vn',  'Sale', 'team-ashley-sale', Assignment::SCOPE_SELF, []],
            ['ntt2@longevity.com.vn', 'Sale', 'team-ashley-sale', Assignment::SCOPE_SELF, []],
            ['nkc@longevity.com.vn',  'Sale', 'team-ashley-sale', Assignment::SCOPE_SELF, []],
            ['lpd@longevity.com.vn',  'Sale', 'team-ashley-sale', Assignment::SCOPE_SELF, []],

            // Luồng 7 nguồn
            ['hn.trucpage@longevity.com.vn',  'Trực Page', 'team-nhap-lead',     Assignment::SCOPE_SELF, []],
            ['hcm.trucpage@longevity.com.vn', 'Trực Page', 'team-nhap-lead-hcm', Assignment::SCOPE_SELF, []],
            ['dn.trucpage@longevity.com.vn',  'Trực Page', 'team-nhap-lead-dn',  Assignment::SCOPE_SELF, []],
            // 2026-08-08: BỎ book1/book2 assignments — đã dọn user.
        ];

        // Cleanup: nhân sự Đà Nẵng lần trước từng được gán role Team sale/Team booking @ marketing-dn.
        // Giờ chuyển sang role "Team sale ĐN" @ team-dn-sale — xoá các assignment cũ để tránh union.
        $dnEmails = ['ntan@longevity.com.vn','lthu@longevity.com.vn','ltkhi@longevity.com.vn',
                     'stk@longevity.com.vn','lttv@longevity.com.vn','tnah@longevity.com.vn','ntmh@longevity.com.vn'];
        // Fallback qua name nếu email đã bị rename.
        $dnNames = ['Nguyễn Thị Ánh Nhung','Lê Thị Hoàng Uyên','Lương Thị Kim Hiếu',
                    'Sử Trung Kiên','Lương Thị Tường Vy','Trần Ngọc An Hoà','Nguyễn Thị Mỹ Hạnh'];
        $dnUserIds = User::whereIn('email', $dnEmails)->orWhereIn('name', $dnNames)->pluck('id');
        $marketingDnId = OrgUnit::where('code', 'marketing-dn')->value('id');
        if ($dnUserIds->isNotEmpty() && $marketingDnId) {
            Assignment::whereIn('user_id', $dnUserIds)
                ->where('org_unit_id', $marketingDnId)
                ->delete();
        }

        // 2026-08-07: Kim Phấn (Linda) + Bông trước ở marketing-dn (CM sale + CM booking / TL).
        // Move sang team-dn (Team Kinh Doanh của Bông) → xoá assignment cũ ở marketing-dn.
        $leadDnUserIds = User::whereIn('email', ['ltkp@longevity.com.vn', 'ntb@longevity.com.vn'])
            ->orWhereIn('name', ['Lương Thị Kim Phấn', 'Nguyễn Thị Bông'])
            ->pluck('id');
        if ($leadDnUserIds->isNotEmpty() && $marketingDnId) {
            Assignment::whereIn('user_id', $leadDnUserIds)
                ->where('org_unit_id', $marketingDnId)
                ->delete();
        }

        // Map email cũ → tên (fallback nếu email đã bị rename sang format vị trí).
        $emailToName = collect($this->userDefs())->pluck('name', 'email')->all();

        foreach ($assignments as [$email, $roleName, $orgCode, $scope, $scopeCodes]) {
            $user = User::firstWhere('email', $email)
                ?? (isset($emailToName[$email]) ? User::firstWhere('name', $emailToName[$email]) : null);
            $role = Role::firstWhere('name', $roleName);
            $org  = OrgUnit::firstWhere('code', $orgCode);
            if (! $user || ! $role || ! $org) {
                $this->command?->warn("Bỏ qua assignment: $email / $roleName / $orgCode (thiếu ref)");
                continue;
            }

            $assignment = Assignment::firstOrNew([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'org_unit_id' => $org->id,
            ]);
            $assignment->data_scope = $scope;
            $assignment->save();

            if ($scope === Assignment::SCOPE_CUSTOM) {
                $scopeIds = OrgUnit::whereIn('code', $scopeCodes)->pluck('id')->all();
                $assignment->scopeNodes()->sync($scopeIds);
            } else {
                $assignment->scopeNodes()->sync([]);
            }
        }
    }
}
