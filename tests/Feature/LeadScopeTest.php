<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Lead;
use App\Models\OrgUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadScopeTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $root;
    private OrgUnit $teamA;
    private OrgUnit $teamB;
    private Role $saleRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = OrgUnit::createNode(['name' => 'Công ty', 'code' => 'company']);
        $this->teamA = OrgUnit::createNode(['name' => 'Team A', 'code' => 'team-a'], $this->root);
        $this->teamB = OrgUnit::createNode(['name' => 'Team B', 'code' => 'team-b'], $this->root);

        $this->saleRole = Role::create(['name' => 'Sale']);
    }

    private function makeLead(array $attrs = []): Lead
    {
        static $i = 0;
        $i++;

        return Lead::create(array_merge([
            'received_date' => now()->toDateString(),
            'name' => "Khách $i",
            'phone' => '09' . str_pad((string) (10000000 + $i), 8, '0', STR_PAD_LEFT),
        ], $attrs));
    }

    private function makeSale(OrgUnit $org, string $scope): User
    {
        $user = User::factory()->create();
        Assignment::create([
            'user_id' => $user->id,
            'role_id' => $this->saleRole->id,
            'org_unit_id' => $org->id,
            'data_scope' => $scope,
        ]);

        return $user;
    }

    // ---------- visibleTo ----------

    public function test_self_scope_sees_only_owned_or_received_leads(): void
    {
        $sale = $this->makeSale($this->teamA, Assignment::SCOPE_SELF);

        $mine = $this->makeLead(['owner_id' => $sale->id, 'org_unit_id' => $this->teamA->id]);
        $received = $this->makeLead(['receiver_id' => $sale->id]);
        $this->makeLead(['org_unit_id' => $this->teamA->id]); // cùng team nhưng không phải của mình

        $visible = Lead::visibleTo($sale)->pluck('id')->all();
        sort($visible);

        $this->assertSame([$mine->id, $received->id], $visible);
    }

    public function test_team_scope_sees_all_leads_in_subtree(): void
    {
        $manager = $this->makeSale($this->root, Assignment::SCOPE_TEAM);

        $inA = $this->makeLead(['org_unit_id' => $this->teamA->id]);
        $inB = $this->makeLead(['org_unit_id' => $this->teamB->id]);
        $noOrg = $this->makeLead(); // kho chung, chưa gán team

        $visible = Lead::visibleTo($manager)->pluck('id')->all();

        $this->assertContains($inA->id, $visible);
        $this->assertContains($inB->id, $visible);
        $this->assertNotContains($noOrg->id, $visible);
    }

    public function test_team_scope_does_not_leak_other_branch(): void
    {
        $managerA = $this->makeSale($this->teamA, Assignment::SCOPE_TEAM);

        $inB = $this->makeLead(['org_unit_id' => $this->teamB->id]);

        $this->assertNotContains($inB->id, Lead::visibleTo($managerA)->pluck('id')->all());
    }

    public function test_user_without_assignment_sees_nothing(): void
    {
        $user = User::factory()->create();
        $this->makeLead(['org_unit_id' => $this->teamA->id]);
        $this->makeLead(['owner_id' => $user->id]); // dù đứng tên owner cũng không thấy vì không còn assignment

        $this->assertSame(0, Lead::visibleTo($user)->count());
    }

    public function test_overlap_sale_a_manager_b_sees_own_plus_team_b(): void
    {
        $user = User::factory()->create();
        Assignment::create(['user_id' => $user->id, 'role_id' => $this->saleRole->id, 'org_unit_id' => $this->teamA->id, 'data_scope' => 'self']);
        Assignment::create(['user_id' => $user->id, 'role_id' => $this->saleRole->id, 'org_unit_id' => $this->teamB->id, 'data_scope' => 'team']);

        $ownInA = $this->makeLead(['owner_id' => $user->id, 'org_unit_id' => $this->teamA->id]);
        $otherInA = $this->makeLead(['org_unit_id' => $this->teamA->id]);
        $anyInB = $this->makeLead(['org_unit_id' => $this->teamB->id]);

        $visible = Lead::visibleTo($user)->pluck('id')->all();

        $this->assertContains($ownInA->id, $visible);
        $this->assertContains($anyInB->id, $visible);
        $this->assertNotContains($otherInA->id, $visible);
    }

    // ---------- Mask SĐT ----------

    public function test_phone_masked_outside_scope(): void
    {
        $saleA = $this->makeSale($this->teamA, Assignment::SCOPE_TEAM);
        $leadB = $this->makeLead(['org_unit_id' => $this->teamB->id, 'phone' => '0901234567']);

        $this->assertSame('090***4567', $leadB->phoneFor($saleA));
        $this->assertFalse($leadB->canViewFullPhone($saleA));
    }

    public function test_phone_full_inside_scope(): void
    {
        $saleA = $this->makeSale($this->teamA, Assignment::SCOPE_TEAM);
        $leadA = $this->makeLead(['org_unit_id' => $this->teamA->id, 'phone' => '0901234567']);

        $this->assertSame('0901234567', $leadA->phoneFor($saleA));
    }

    public function test_phone_full_with_view_phone_permission(): void
    {
        $perm = Permission::create(['key' => 'lead.view_phone', 'label' => 'Xem SĐT', 'group' => 'lead']);
        $this->saleRole->permissions()->sync([$perm->id]);

        $saleA = $this->makeSale($this->teamA, Assignment::SCOPE_TEAM);
        $leadB = $this->makeLead(['org_unit_id' => $this->teamB->id, 'phone' => '0901234567']);

        $this->assertSame('0901234567', $leadB->phoneFor($saleA));
    }

    public function test_owner_sees_own_phone_even_with_self_scope(): void
    {
        $sale = $this->makeSale($this->teamA, Assignment::SCOPE_SELF);
        $lead = $this->makeLead(['owner_id' => $sale->id, 'phone' => '0901234567']);

        $this->assertSame('0901234567', $lead->phoneFor($sale));
    }

    // ---------- Chuẩn hóa & chống trùng ----------

    public function test_normalize_phone_variants(): void
    {
        $this->assertSame('0901234567', Lead::normalizePhone('0901234567'));
        $this->assertSame('0901234567', Lead::normalizePhone('+84 901 234 567'));
        $this->assertSame('0901234567', Lead::normalizePhone('84901234567'));
        $this->assertSame('0901234567', Lead::normalizePhone('901234567'));
        $this->assertSame('0901234567', Lead::normalizePhone('090-123-4567'));
        $this->assertNull(Lead::normalizePhone('12345'));
        $this->assertNull(Lead::normalizePhone('abc'));
        $this->assertNull(Lead::normalizePhone('090123456789'));
    }

    public function test_duplicate_phone_rejected_by_unique_index(): void
    {
        $this->makeLead(['phone' => '0900000001']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        Lead::create([
            'received_date' => now()->toDateString(),
            'name' => 'Trùng',
            'phone' => '0900000001',
        ]);
    }
}
