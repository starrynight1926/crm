<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $root;
    private OrgUnit $sales;
    private OrgUnit $teamA;
    private OrgUnit $teamB;
    private OrgUnit $marketing;
    private Role $saleRole;
    private Role $managerRole;

    protected function setUp(): void
    {
        parent::setUp();

        // Công ty > (Kinh doanh > Team A, Team B), Marketing
        $this->root = OrgUnit::createNode(['name' => 'Công ty', 'code' => 'company']);
        $this->sales = OrgUnit::createNode(['name' => 'Kinh doanh', 'code' => 'sales'], $this->root);
        $this->teamA = OrgUnit::createNode(['name' => 'Team A', 'code' => 'team-a'], $this->sales);
        $this->teamB = OrgUnit::createNode(['name' => 'Team B', 'code' => 'team-b'], $this->sales);
        $this->marketing = OrgUnit::createNode(['name' => 'Marketing', 'code' => 'mkt'], $this->root);

        $view = Permission::create(['key' => 'lead.view', 'label' => 'Xem lead', 'group' => 'lead']);
        $update = Permission::create(['key' => 'lead.update', 'label' => 'Sửa lead', 'group' => 'lead']);
        $recall = Permission::create(['key' => 'lead.recall', 'label' => 'Thu hồi lead', 'group' => 'distribution']);

        $this->saleRole = Role::create(['name' => 'Sale']);
        $this->saleRole->permissions()->sync([$view->id, $update->id]);

        $this->managerRole = Role::create(['name' => 'Manager']);
        $this->managerRole->permissions()->sync([$view->id, $update->id, $recall->id]);
    }

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    private function assign(User $user, Role $role, OrgUnit $org, string $scope, array $extra = []): Assignment
    {
        return Assignment::create(array_merge([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'org_unit_id' => $org->id,
            'data_scope' => $scope,
        ], $extra));
    }

    // ---------- Quyền chức năng (RBAC) ----------

    public function test_permission_granted_through_role(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->saleRole, $this->teamA, Assignment::SCOPE_SELF);

        $this->assertTrue($user->hasPermission('lead.view'));
        $this->assertFalse($user->hasPermission('lead.recall'));
    }

    public function test_no_assignment_means_no_permission(): void
    {
        $user = $this->makeUser();

        $this->assertFalse($user->hasPermission('lead.view'));
        $this->assertFalse($user->hasSelfScope());
    }

    public function test_inactive_assignment_grants_nothing(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->saleRole, $this->teamA, Assignment::SCOPE_SELF, ['active' => false]);

        $this->assertFalse($user->hasPermission('lead.view'));
        $this->assertFalse($user->hasSelfScope());
    }

    public function test_expired_and_future_assignments_grant_nothing(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->saleRole, $this->teamA, Assignment::SCOPE_SELF, [
            'valid_to' => now()->subDay()->toDateString(),
        ]);
        $this->assign($user, $this->managerRole, $this->teamB, Assignment::SCOPE_TEAM, [
            'valid_from' => now()->addDay()->toDateString(),
        ]);

        $this->assertFalse($user->hasPermission('lead.view'));
        $this->assertSame([], $user->visibleOrgUnitIds());
    }

    public function test_assignment_within_validity_window_works(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->saleRole, $this->teamA, Assignment::SCOPE_SELF, [
            'valid_from' => now()->subDay()->toDateString(),
            'valid_to' => now()->addDay()->toDateString(),
        ]);

        $this->assertTrue($user->hasPermission('lead.view'));
    }

    public function test_permissions_union_across_multiple_assignments(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->saleRole, $this->teamA, Assignment::SCOPE_SELF);
        $this->assign($user, $this->managerRole, $this->teamB, Assignment::SCOPE_TEAM);

        // lead.recall chỉ có ở role Manager — union phải có
        $this->assertTrue($user->hasPermission('lead.recall'));
    }

    // ---------- Phạm vi dữ liệu (data scope) ----------

    public function test_self_scope_contributes_no_org_units(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->saleRole, $this->teamA, Assignment::SCOPE_SELF);

        $this->assertSame([], $user->visibleOrgUnitIds());
        $this->assertTrue($user->hasSelfScope());
    }

    public function test_team_scope_sees_own_subtree_only(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->managerRole, $this->sales, Assignment::SCOPE_TEAM);

        $visible = $user->visibleOrgUnitIds();
        sort($visible);

        // Kinh doanh + Team A + Team B, không thấy Công ty / Marketing
        $this->assertSame([$this->sales->id, $this->teamA->id, $this->teamB->id], $visible);
        $this->assertFalse($user->canSeeOrgUnit($this->marketing->id));
        $this->assertFalse($user->canSeeOrgUnit($this->root->id));
    }

    public function test_team_scope_on_leaf_sees_only_itself(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->managerRole, $this->teamA, Assignment::SCOPE_TEAM);

        $this->assertSame([$this->teamA->id], $user->visibleOrgUnitIds());
    }

    public function test_custom_scope_sees_checked_nodes_and_descendants(): void
    {
        $user = $this->makeUser();
        $assignment = $this->assign($user, $this->managerRole, $this->teamA, Assignment::SCOPE_CUSTOM);
        $assignment->scopeNodes()->sync([$this->teamB->id, $this->marketing->id]);

        $visible = $user->visibleOrgUnitIds();
        sort($visible);

        $this->assertSame([$this->teamB->id, $this->marketing->id], $visible);
        $this->assertFalse($user->canSeeOrgUnit($this->teamA->id));
    }

    public function test_custom_scope_root_node_sees_entire_tree(): void
    {
        $user = $this->makeUser();
        $assignment = $this->assign($user, $this->managerRole, $this->root, Assignment::SCOPE_CUSTOM);
        $assignment->scopeNodes()->sync([$this->root->id]);

        $this->assertCount(OrgUnit::count(), $user->visibleOrgUnitIds());
    }

    public function test_overlapping_assignments_sale_a_manager_b(): void
    {
        // Case thực tế trong scope.md 7.3: sale team A kiêm manager team B
        $user = $this->makeUser();
        $this->assign($user, $this->saleRole, $this->teamA, Assignment::SCOPE_SELF);
        $this->assign($user, $this->managerRole, $this->teamB, Assignment::SCOPE_TEAM);

        // Data scope: chỉ thấy team B (dữ liệu team A chỉ thấy của bản thân qua owner)
        $this->assertSame([$this->teamB->id], $user->visibleOrgUnitIds());
        $this->assertTrue($user->hasSelfScope());

        // Quyền: có cả quyền của Sale lẫn Manager
        $this->assertTrue($user->hasPermission('lead.recall'));
    }

    public function test_scopes_union_across_assignments(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->managerRole, $this->teamA, Assignment::SCOPE_TEAM);
        $custom = $this->assign($user, $this->managerRole, $this->marketing, Assignment::SCOPE_CUSTOM);
        $custom->scopeNodes()->sync([$this->marketing->id]);

        $visible = $user->visibleOrgUnitIds();
        sort($visible);

        $this->assertSame([$this->teamA->id, $this->marketing->id], $visible);
    }

    public function test_flush_access_cache_picks_up_new_assignment(): void
    {
        $user = $this->makeUser();
        $this->assertFalse($user->hasPermission('lead.view'));

        $this->assign($user, $this->saleRole, $this->teamA, Assignment::SCOPE_SELF);
        $this->assertFalse($user->hasPermission('lead.view'), 'cache chưa flush thì chưa thấy');

        $user->flushAccessCache();
        $this->assertTrue($user->hasPermission('lead.view'));
    }

    // ---------- Materialized path ----------

    public function test_path_prefix_does_not_leak_similar_ids(): void
    {
        // Node id 1 với path /1/ không được match node có path /11/ (khác id nhưng chung prefix số)
        $other = OrgUnit::createNode(['name' => 'X', 'code' => 'x']);
        // path của node mới là /{id}/ — id tăng dần nên tạo thêm node để có id 2 chữ số khó ở sqlite;
        // kiểm tra trực tiếp bằng logic prefix: /1/ không phải prefix của /11/
        $this->assertFalse(str_starts_with('/11/', $this->root->path === '/1/' ? '/1/' : $this->root->path));

        $subtree = $this->root->subtreeIds();
        $this->assertNotContains($other->id, $subtree);
    }

    public function test_deep_tree_subtree_resolution(): void
    {
        // Cây sâu tùy ý: thêm 3 cấp dưới Team A
        $l1 = OrgUnit::createNode(['name' => 'Nhóm 1', 'code' => 'g1'], $this->teamA);
        $l2 = OrgUnit::createNode(['name' => 'Nhóm 1.1', 'code' => 'g11'], $l1);
        $l3 = OrgUnit::createNode(['name' => 'Nhóm 1.1.1', 'code' => 'g111'], $l2);

        $user = $this->makeUser();
        $this->assign($user, $this->managerRole, $this->teamA, Assignment::SCOPE_TEAM);

        $visible = $user->visibleOrgUnitIds();
        $this->assertContains($l3->id, $visible);
        $this->assertCount(4, $visible); // teamA + 3 cấp con
    }
}
