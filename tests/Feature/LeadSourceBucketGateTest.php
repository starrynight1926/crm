<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\DailyAttendance;
use App\Models\Lead;
use App\Models\OrgUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Rule 2026-08-09: `Lead::allowedSourceGroupsFor()` chịu chi phối bởi UPS bucket hôm nay.
 *  - Bucket MKT → mở SA, khóa BA.
 *  - Bucket A/B/C/OFF → mở BA, khóa SA.
 *  - Không có attendance hôm nay → fallback perm mặc định.
 *  - User có `lead.source_all` bypass hoàn toàn.
 */
class LeadSourceBucketGateTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $org;
    private int $poolUnitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = OrgUnit::createNode(['name' => 'QA Org', 'code' => 'qa-org']);
        $this->poolUnitId = DB::table('pool_units')->insertGetId([
            'name' => 'QA Pool', 'code' => 'qa-pool', 'kind' => 'facility',
            'path' => '/', 'depth' => 0, 'sort' => 0, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([
            'lead.view', 'lead.create', 'lead.source_all',
            'source.up.mkt', 'source.up.mkt_br', 'source.up.sa', 'source.up.ba', 'source.up.bdm', 'source.up.bod', 'source.up.wi',
        ] as $key) {
            Permission::firstOrCreate(['key' => $key], ['label' => $key, 'group' => 'test']);
        }
    }

    private function makeUser(array $perms, string $roleName = 'Sale QA'): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $role->permissions()->sync(Permission::whereIn('key', $perms)->pluck('id')->all());

        $user = User::factory()->create();
        Assignment::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'org_unit_id' => $this->org->id,
            'scope_type' => 'self',
        ]);

        return $user->fresh(['assignments.role.permissions']);
    }

    private function setBucket(User $user, ?string $bucket): void
    {
        DailyAttendance::updateOrCreate(
            ['user_id' => $user->id, 'work_date' => now()->toDateString()],
            [
                'facility_pool_unit_id' => $this->poolUnitId,
                'list_bucket' => $bucket,
                'checkin_at' => now(),
            ],
        );
    }

    public function test_mkt_bucket_allows_sa_denies_ba(): void
    {
        $user = $this->makeUser(['lead.view', 'lead.create', 'source.up.mkt_br', 'source.up.sa', 'source.up.ba']);
        $this->setBucket($user, 'MKT');

        $allowed = Lead::allowedSourceGroupsFor($user);

        $this->assertArrayHasKey(Lead::SOURCE_SA, $allowed, 'MKT bucket phải mở SA');
        $this->assertArrayNotHasKey(Lead::SOURCE_BA, $allowed, 'MKT bucket phải khóa BA');
    }

    public function test_greet_bucket_allows_ba_denies_sa(): void
    {
        $user = $this->makeUser(['lead.view', 'lead.create', 'source.up.mkt_br', 'source.up.sa', 'source.up.ba']);
        $this->setBucket($user, 'A');

        $allowed = Lead::allowedSourceGroupsFor($user);

        $this->assertArrayHasKey(Lead::SOURCE_BA, $allowed, 'A bucket phải mở BA');
        $this->assertArrayNotHasKey(Lead::SOURCE_SA, $allowed, 'A bucket phải khóa SA');
    }

    public function test_off_bucket_still_greet_side(): void
    {
        $user = $this->makeUser(['lead.view', 'lead.create', 'source.up.mkt_br', 'source.up.sa', 'source.up.ba']);
        $this->setBucket($user, 'OFF');

        $allowed = Lead::allowedSourceGroupsFor($user);

        $this->assertArrayHasKey(Lead::SOURCE_BA, $allowed);
        $this->assertArrayNotHasKey(Lead::SOURCE_SA, $allowed);
    }

    public function test_no_attendance_falls_back_to_perm(): void
    {
        $user = $this->makeUser(['lead.view', 'lead.create', 'source.up.ba']);
        // Không set attendance.

        $allowed = Lead::allowedSourceGroupsFor($user);

        $this->assertArrayHasKey(Lead::SOURCE_BA, $allowed, 'Không có bucket → theo perm source.up.tele');
        $this->assertArrayNotHasKey(Lead::SOURCE_SA, $allowed, 'Không có perm source.up.sa → không thấy SA');
    }

    public function test_source_all_bypasses_bucket_gate(): void
    {
        $user = $this->makeUser(['lead.view', 'lead.create', 'lead.source_all']);
        $this->setBucket($user, 'MKT');

        $allowed = Lead::allowedSourceGroupsFor($user);

        $this->assertArrayHasKey(Lead::SOURCE_SA, $allowed, 'source_all bypass bucket gate — SA vẫn hiện');
        $this->assertArrayHasKey(Lead::SOURCE_BA, $allowed, 'source_all bypass bucket gate — BA vẫn hiện');
    }
}
