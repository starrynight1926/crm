<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Lead;
use App\Models\LeadDistributionLog;
use App\Models\OrgUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase66FlowsTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $company;
    private User $admin;
    private User $regular; // NV thường không có permission distribute nào

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->company = OrgUnit::createNode(['name' => 'Cty', 'code' => 'company']);

        $adminRole = Role::create(['name' => 'Admin', 'is_system' => true]);
        $adminRole->permissions()->sync(Permission::pluck('id'));
        $this->admin = User::factory()->create();
        Assignment::create([
            'user_id' => $this->admin->id, 'role_id' => $adminRole->id,
            'org_unit_id' => $this->company->id, 'data_scope' => Assignment::SCOPE_CUSTOM,
        ])->scopeNodes()->sync([$this->company->id]);

        $saleRole = Role::create(['name' => 'Sale']);
        $saleRole->permissions()->sync(Permission::whereIn('key', ['lead.view', 'lead.create'])->pluck('id'));
        $this->regular = User::factory()->create();
        Assignment::create([
            'user_id' => $this->regular->id, 'role_id' => $saleRole->id,
            'org_unit_id' => $this->company->id, 'data_scope' => Assignment::SCOPE_SELF,
        ]);
    }

    public function test_admin_thay_du_7_nhom_nguon(): void
    {
        $sources = Lead::allowedSourceGroupsFor($this->admin);
        $this->assertCount(7, $sources);
    }

    public function test_nv_thuong_khong_co_source_perm_thi_khong_thay_nguon_nao(): void
    {
        // Phase 6.21f — mọi source đều gate bằng perm source.up.{trucpage,sale,tele,admin}.
        // NV thường (role Sale test này) chỉ có lead.view + lead.create → không có source.up.* → 0 nguồn.
        $sources = Lead::allowedSourceGroupsFor($this->regular);
        $this->assertCount(0, $sources);
    }

    public function test_route_leads_approvals_can_permission(): void
    {
        // NV thường không có permission
        $this->actingAs($this->regular)->get('/leads/approvals')->assertForbidden();
        // Admin OK
        $this->actingAs($this->admin)->get('/leads/approvals')->assertOk();
    }

    public function test_route_ops_rules_can_ops_manage(): void
    {
        $this->actingAs($this->regular)->get('/ops/rules')->assertForbidden();
        $this->actingAs($this->admin)->get('/ops/rules')->assertOk();
    }

    public function test_duyet_walk_in_lead_set_approval_approved(): void
    {
        $lead = Lead::create([
            'name' => 'Khách walk-in', 'phone' => '0900000001',
            'received_date' => now()->toDateString(),
            'classification' => 'new', 'pool_level' => Lead::POOL_COMMON,
            'source_group' => Lead::SOURCE_WI,
            'approval_status' => Lead::APPROVAL_PENDING,
            'org_unit_id' => $this->company->id,
        ]);

        \Livewire\Livewire::actingAs($this->admin)
            ->test('leads.lead-approvals')
            ->call('approve', $lead->id);

        $lead->refresh();
        $this->assertSame(Lead::APPROVAL_APPROVED, $lead->approval_status);
        $this->assertSame($this->admin->id, $lead->approval_by);
        $this->assertTrue(LeadDistributionLog::where('lead_id', $lead->id)
            ->where('action', LeadDistributionLog::ACTION_APPROVE)->exists());
    }
}
