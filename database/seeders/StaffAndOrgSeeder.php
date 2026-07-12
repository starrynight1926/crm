<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class StaffAndOrgSeeder extends Seeder
{
    public function run(): void
    {
        $this->restructureOrg();
        $this->createViewerRole();
        $this->seedViewerAccounts();
        $this->seedSystemAdmins();
        $this->setJobTitles();

        $this->command?->info('StaffAndOrgSeeder hoàn tất.');
    }

    private function restructureOrg(): void
    {
        $root = OrgUnit::firstWhere('code', 'company');
        if (! $root) {
            $this->command?->error('Không tìm thấy Công ty (code=company).');
            return;
        }

        $hanoi = OrgUnit::firstWhere('code', 'branch-hn');
        if (! $hanoi) {
            $hanoi = OrgUnit::createNode([
                'name' => 'Cơ sở Hà Nội: 59 Ngô Thì Nhậm',
                'code' => 'branch-hn',
            ], $root);
        }

        $hcm = OrgUnit::firstWhere('code', 'branch-hcm');
        if (! $hcm) {
            OrgUnit::createNode([
                'name' => 'Cơ sở HCM: 207 Nguyễn Văn Thụ',
                'code' => 'branch-hcm',
            ], $root);
        }

        $deptCodes = ['mkt', 'telesales-mkt', 'bdm'];
        foreach ($deptCodes as $code) {
            $unit = OrgUnit::firstWhere('code', $code);
            if ($unit && $unit->parent_id !== $hanoi->id) {
                $unit->parent_id = $hanoi->id;
                $unit->depth = $hanoi->depth + 1;
                $unit->path = rtrim($hanoi->path, '/') . '/' . $unit->id . '/';
                $unit->save();

                $this->fixChildPaths($unit);
            }
        }

        $this->command?->info('Cây tổ chức: thêm Cơ sở HN + HCM, chuyển phòng ban vào HN.');
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

    private function createViewerRole(): void
    {
        $viewer = Role::updateOrCreate(
            ['name' => 'Viewer'],
            ['description' => 'Xem toàn bộ, không thêm/sửa/xóa dịch vụ và nhân sự']
        );

        $viewPerms = Permission::whereIn('key', [
            'lead.view',
            'lead.view_phone',
            'lead.export',
            'report.view',
            'report.view_all',
        ])->pluck('id');

        $viewer->permissions()->sync($viewPerms);
    }

    private function seedViewerAccounts(): void
    {
        $viewer = Role::firstWhere('name', 'Viewer');
        $root = OrgUnit::firstWhere('code', 'company');
        if (! $viewer || ! $root) {
            return;
        }

        $accounts = [
            ['email' => 'huyently', 'name' => 'Huyền', 'job_title' => 'Trợ lý kinh doanh'],
            ['email' => 'hangktt', 'name' => 'Hằng', 'job_title' => 'Kế toán trưởng'],
            ['email' => 'lyktdt', 'name' => 'Ly', 'job_title' => 'Kế toán doanh thu'],
            ['email' => 'msan', 'name' => 'An', 'job_title' => 'COO'],
            ['email' => 'mstuyet', 'name' => 'Tuyết', 'job_title' => 'CEO'],
        ];

        foreach ($accounts as $acc) {
            $email = $acc['email'] . '@longevity.com.vn';
            $user = User::firstWhere('email', $email);
            if (! $user) {
                $user = User::create([
                    'name' => $acc['name'],
                    'email' => $email,
                    'job_title' => $acc['job_title'],
                    'password' => '123456',
                    'status' => User::STATUS_ACTIVE,
                ]);
            } else {
                $user->update(['job_title' => $acc['job_title']]);
            }

            if (! Assignment::where('user_id', $user->id)->where('role_id', $viewer->id)->exists()) {
                $assignment = Assignment::create([
                    'user_id' => $user->id,
                    'role_id' => $viewer->id,
                    'org_unit_id' => $root->id,
                    'data_scope' => Assignment::SCOPE_CUSTOM,
                ]);
                $assignment->scopeNodes()->sync([$root->id]);
            }
        }

        $this->command?->info('Seeded ' . count($accounts) . ' tài khoản Viewer.');
    }

    private function seedSystemAdmins(): void
    {
        $admin = Role::firstWhere('name', 'Admin');
        $root = OrgUnit::firstWhere('code', 'company');
        if (! $admin || ! $root) {
            return;
        }

        $accounts = [
            ['email' => 'baoit', 'name' => 'Bảo', 'job_title' => 'IT hệ thống'],
            ['email' => 'tumod', 'name' => 'Tú', 'job_title' => 'Kiểm soát hệ thống PK'],
        ];

        foreach ($accounts as $acc) {
            $email = $acc['email'] . '@longevity.com.vn';
            $user = User::firstWhere('email', $email);
            if (! $user) {
                $user = User::create([
                    'name' => $acc['name'],
                    'email' => $email,
                    'job_title' => $acc['job_title'],
                    'password' => '123456',
                    'status' => User::STATUS_ACTIVE,
                ]);
            } else {
                $user->update(['job_title' => $acc['job_title']]);
            }

            if (! Assignment::where('user_id', $user->id)->where('role_id', $admin->id)->exists()) {
                $assignment = Assignment::create([
                    'user_id' => $user->id,
                    'role_id' => $admin->id,
                    'org_unit_id' => $root->id,
                    'data_scope' => Assignment::SCOPE_CUSTOM,
                ]);
                $assignment->scopeNodes()->sync([$root->id]);
            }
        }

        $this->command?->info('Seeded ' . count($accounts) . ' tài khoản Admin hệ thống.');
    }

    private function setJobTitles(): void
    {
        $titles = [
            'Tạ Văn Hợi' => 'Clinic Manager (CM)',
            'Trần Thị Thu Giang' => 'Clinic Manager (CM)',
            'Nguyễn Hoành Đức' => 'Team Leader (TL)',
        ];

        foreach ($titles as $name => $title) {
            User::where('name', $name)->update(['job_title' => $title]);
        }

        $phan = User::firstWhere('name', 'Lương Thị Kim Phấn');
        if (! $phan) {
            $phan = User::create([
                'name' => 'Lương Thị Kim Phấn',
                'email' => 'ltkp@longevity.com.vn',
                'job_title' => 'Clinic Manager (CM)',
                'password' => '123456',
                'status' => User::STATUS_ACTIVE,
            ]);
        } else {
            $phan->update(['job_title' => 'Clinic Manager (CM)']);
        }

        $this->command?->info('Cập nhật chức danh cho 4 nhân viên.');
    }
}
