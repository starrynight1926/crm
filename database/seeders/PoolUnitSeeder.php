<?php

namespace Database\Seeders;

use App\Models\OrgUnit;
use App\Models\PoolUnit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PoolUnitSeeder extends Seeder
{
    public function run(): void
    {
        $company = PoolUnit::firstWhere('code', 'pool-longevity')
            ?? PoolUnit::createNode([
                'name' => 'Longevity Medical',
                'code' => 'pool-longevity',
                'kind' => 'company',
            ]);

        $tree = [
            ['code' => 'pool-branch-hn', 'name' => 'Hà Nội', 'kind' => 'branch', 'children' => [
                ['code' => 'pool-cs-hn-1', 'name' => 'CS1: 59 Ngô Thì Nhậm', 'kind' => 'facility', 'children' => [
                    ['code' => 'pool-cs-hn-1-kd1', 'name' => 'Phòng Kinh Doanh 1', 'kind' => 'department'],
                    ['code' => 'pool-cs-hn-1-kd2', 'name' => 'Phòng Kinh Doanh 2', 'kind' => 'department'],
                ]],
                ['code' => 'pool-cs-hn-2', 'name' => 'CS2: 190 Hoàng Ngân', 'kind' => 'facility', 'is_active' => false],
            ]],
            ['code' => 'pool-branch-dn', 'name' => 'Đà Nẵng', 'kind' => 'branch', 'children' => [
                ['code' => 'pool-cs-dn-1', 'name' => 'CS: Lô 2 & 3 Trần Đăng Ninh', 'kind' => 'facility', 'children' => [
                    ['code' => 'pool-cs-dn-1-kd', 'name' => 'Phòng Kinh Doanh', 'kind' => 'department'],
                ]],
            ]],
            ['code' => 'pool-branch-hcm', 'name' => 'Hồ Chí Minh', 'kind' => 'branch', 'children' => [
                ['code' => 'pool-cs-hcm-1', 'name' => 'CS1: 207 Nguyễn Văn Thủ', 'kind' => 'facility', 'children' => [
                    ['code' => 'pool-cs-hcm-1-kd', 'name' => 'Phòng Kinh Doanh', 'kind' => 'department'],
                ]],
                ['code' => 'pool-cs-hcm-2', 'name' => '137 Nguyễn Chí Thanh', 'kind' => 'facility', 'is_active' => false],
            ]],
        ];

        $this->seedChildren($tree, $company);

        $this->mapOrgToPool();
    }

    private function seedChildren(array $nodes, PoolUnit $parent): void
    {
        foreach ($nodes as $sort => $data) {
            $children = $data['children'] ?? [];
            unset($data['children']);
            $isActive = $data['is_active'] ?? true;
            unset($data['is_active']);

            $node = PoolUnit::firstWhere('code', $data['code']) ?? PoolUnit::createNode(
                array_merge($data, ['sort' => $sort, 'is_active' => $isActive]),
                $parent
            );

            if ($children) {
                $this->seedChildren($children, $node);
            }
        }
    }

    /**
     * Mapping org_units chi nhánh (branch-hn/dn/hcm) → pool_units chi nhánh tương ứng.
     * Đây là cầu nối tối thiểu để BO/user resolve pool scope theo chi nhánh.
     * Mapping sâu hơn (team → phòng KD cụ thể) chờ user duyệt riêng.
     */
    private function mapOrgToPool(): void
    {
        // Mapping chi nhánh (scope quyền) + cơ sở (list sale) + phòng KD (thành viên).
        // Phòng KD của HN CS1: PKD 1 = team Giang, PKD 2 = team Hợi (theo yêu cầu 2026-08-03).
        $pairs = [
            // org_code => [pool_code, ...]  (many-to-many)
            'branch-hn'      => ['pool-branch-hn',  'pool-cs-hn-1'],
            'branch-dn'      => ['pool-branch-dn',  'pool-cs-dn-1'],
            'branch-hcm'     => ['pool-branch-hcm', 'pool-cs-hcm-1'],
            'team-giang'     => ['pool-cs-hn-1-kd1'],
            'team-hoi-hn'    => ['pool-cs-hn-1-kd2'],
        ];

        foreach ($pairs as $orgCode => $poolCodes) {
            $org = OrgUnit::firstWhere('code', $orgCode);
            if (! $org) {
                continue;
            }
            foreach ($poolCodes as $poolCode) {
                $pool = PoolUnit::firstWhere('code', $poolCode);
                if (! $pool) {
                    continue;
                }
                DB::table('org_pool_map')->updateOrInsert(
                    ['org_unit_id' => $org->id, 'pool_unit_id' => $pool->id],
                    ['updated_at' => now(), 'created_at' => now()]
                );
            }
        }
    }
}
