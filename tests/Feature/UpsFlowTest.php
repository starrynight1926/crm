<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\DailyAttendance;
use App\Models\OrgUnit;
use App\Models\Permission;
use App\Models\PoolUnit;
use App\Models\Role;
use App\Models\UpsDailyConfirm;
use App\Models\User;
use App\Services\Ups\UpsGate;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UpsFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $bo;
    private User $cm;   // CM sale — người bị gate chặn khi UPS chưa chốt
    private User $sale;
    private PoolUnit $branchPool;
    private PoolUnit $facilityPool;
    private OrgUnit $branchOrg;

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions
        foreach (['ups.view', 'ups.checkin', 'ups.override', 'ups.confirm_daily', 'lead.distribute_sale', 'lead.view'] as $key) {
            Permission::create(['key' => $key, 'label' => $key, 'group' => 'ups']);
        }

        // Org tree: root > branch HN > team-sale
        $root = OrgUnit::createNode(['name' => 'Cty', 'code' => 'org-root']);
        $this->branchOrg = OrgUnit::createNode(['name' => 'HN', 'code' => 'org-branch-hn'], $root);
        $teamSale = OrgUnit::createNode(['name' => 'Team Sale HN', 'code' => 'org-team-sale-hn'], $this->branchOrg);

        // Pool tree: Longevity > branch HN > facility CS1
        $poolRoot = PoolUnit::createNode(['name' => 'Longevity', 'code' => 'p-root', 'kind' => 'company']);
        $this->branchPool = PoolUnit::createNode(['name' => 'HN', 'code' => 'p-branch-hn', 'kind' => 'branch'], $poolRoot);
        $this->facilityPool = PoolUnit::createNode(['name' => 'CS1', 'code' => 'p-cs-hn-1', 'kind' => 'facility'], $this->branchPool);

        // Map org branch → pool branch
        DB::table('org_pool_map')->insert([
            'org_unit_id' => $this->branchOrg->id,
            'pool_unit_id' => $this->branchPool->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Roles
        $roleBO = Role::create(['name' => 'BO (Lễ Tân)']);
        $roleBO->permissions()->sync(Permission::whereIn('key', ['ups.view', 'ups.checkin', 'ups.override', 'ups.confirm_daily'])->pluck('id'));

        $roleCm = Role::create(['name' => 'CM sale']);
        $roleCm->permissions()->sync(Permission::whereIn('key', ['lead.distribute_sale', 'lead.view'])->pluck('id'));

        $roleSale = Role::create(['name' => 'Sale']);
        $roleSale->permissions()->sync(Permission::whereIn('key', ['lead.view'])->pluck('id'));

        // Users
        $this->bo = User::factory()->create();
        Assignment::create(['user_id' => $this->bo->id, 'role_id' => $roleBO->id, 'org_unit_id' => $this->branchOrg->id, 'data_scope' => 'team', 'active' => true]);

        $this->cm = User::factory()->create();
        Assignment::create(['user_id' => $this->cm->id, 'role_id' => $roleCm->id, 'org_unit_id' => $teamSale->id, 'data_scope' => 'team', 'active' => true]);

        $this->sale = User::factory()->create();
        Assignment::create(['user_id' => $this->sale->id, 'role_id' => $roleSale->id, 'org_unit_id' => $teamSale->id, 'data_scope' => 'self', 'active' => true]);
    }

    public function test_checkin_before_cutoff_returns_bucket_A(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 08:00:00', 'Asia/Ho_Chi_Minh'));

        DailyAttendance::create([
            'facility_pool_unit_id' => $this->facilityPool->id,
            'user_id' => $this->sale->id,
            'work_date' => now()->toDateString(),
            'checkin_at' => now(),
            'list_bucket' => app(\App\Services\Ups\UpsBucketResolver::class)->resolve(now(), $this->facilityPool->id),
        ]);

        $this->assertSame('A', DailyAttendance::first()->list_bucket);
    }

    public function test_checkin_at_836_returns_OFF(): void
    {
        Carbon::setTestNow('2026-08-03 08:36:00');

        $bucket = app(\App\Services\Ups\UpsBucketResolver::class)->resolve(now(), $this->facilityPool->id);
        $this->assertSame('OFF', $bucket);
    }

    public function test_ups_gate_blocks_cm_when_no_facility_confirmed(): void
    {
        Carbon::setTestNow('2026-08-03 09:00:00');

        $gate = app(UpsGate::class);
        $this->assertTrue($gate->isBlockedFor($this->cm->fresh()));
    }

    public function test_ups_gate_unblocks_cm_after_bo_confirms_daily(): void
    {
        Carbon::setTestNow('2026-08-03 09:00:00');

        UpsDailyConfirm::create([
            'facility_pool_unit_id' => $this->facilityPool->id,
            'work_date' => now()->toDateString(),
            'confirmed_by' => $this->bo->id,
            'confirmed_at' => now(),
        ]);

        $gate = app(UpsGate::class);
        $this->assertFalse($gate->isBlockedFor($this->cm->fresh()));
    }

    public function test_admin_bypass_ups_gate_always(): void
    {
        Carbon::setTestNow('2026-08-03 09:00:00');

        Permission::create(['key' => 'user.manage', 'label' => 'admin', 'group' => 'organization']);
        $admin = User::factory()->create();
        $roleAdmin = Role::create(['name' => 'Admin']);
        $roleAdmin->permissions()->sync(Permission::whereIn('key', ['user.manage'])->pluck('id'));
        Assignment::create(['user_id' => $admin->id, 'role_id' => $roleAdmin->id, 'org_unit_id' => $this->branchOrg->id, 'data_scope' => 'team', 'active' => true]);

        $this->assertFalse(app(UpsGate::class)->isBlockedFor($admin->fresh()));
    }

    public function test_route_ups_index_requires_ups_view_permission(): void
    {
        $this->actingAs($this->sale)->get('/ups-list')->assertForbidden();
        $this->actingAs($this->bo)->get('/ups-list')->assertOk();
        // /ups-today: mọi user auth đều xem được (read-only)
        $this->actingAs($this->sale)->get('/ups-today')->assertOk();
    }
}
