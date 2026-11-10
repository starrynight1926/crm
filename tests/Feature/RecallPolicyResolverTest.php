<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Services\RecallPolicyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecallPolicyResolverTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $company;
    private OrgUnit $sales;
    private OrgUnit $teamA;
    private OrgUnit $teamB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = OrgUnit::createNode(['name' => 'Công ty', 'code' => 'company']);
        $this->sales = OrgUnit::createNode(['name' => 'Phòng KD', 'code' => 'sales'], $this->company);
        $this->teamA = OrgUnit::createNode(['name' => 'Team A', 'code' => 'team-a'], $this->sales);
        $this->teamB = OrgUnit::createNode(['name' => 'Team B', 'code' => 'team-b'], $this->sales);
    }

    public function test_default_system_when_no_policy(): void
    {
        DB::table('system_settings')->insert([
            ['key' => 'default_recall_after_days', 'value' => '7', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'default_escalate_after_days', 'value' => '3', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'default_allow_permanent', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $r = RecallPolicyResolver::for($this->teamA);

        $this->assertSame(7, $r['recall_after_days']);
        $this->assertSame(3, $r['escalate_after_days']);
        $this->assertTrue($r['allow_permanent_assignment']);
        $this->assertSame('system', $r['source']);
    }

    public function test_team_uses_own_policy_when_department_not_set(): void
    {
        DB::table('recall_policies')->insert([
            'org_unit_id' => $this->teamA->id,
            'recall_after_days' => 5,
            'escalate_after_days' => 2,
            'allow_permanent_assignment' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $r = RecallPolicyResolver::for($this->teamA);

        $this->assertSame(5, $r['recall_after_days']);
        $this->assertSame(2, $r['escalate_after_days']);
        $this->assertSame('org:' . $this->teamA->id, $r['source']);
    }

    public function test_department_overrides_team(): void
    {
        DB::table('recall_policies')->insert([
            ['org_unit_id' => $this->sales->id, 'recall_after_days' => 10, 'escalate_after_days' => 4, 'allow_permanent_assignment' => false, 'created_at' => now(), 'updated_at' => now()],
            ['org_unit_id' => $this->teamA->id, 'recall_after_days' => 3, 'escalate_after_days' => 1, 'allow_permanent_assignment' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $r = RecallPolicyResolver::for($this->teamA);

        $this->assertSame(10, $r['recall_after_days'], 'Cha (phòng KD) phải override con (Team A)');
        $this->assertSame(4, $r['escalate_after_days']);
        $this->assertFalse($r['allow_permanent_assignment']);
        $this->assertSame('org:' . $this->sales->id, $r['source']);
    }

    public function test_highest_ancestor_wins(): void
    {
        // Cả company, sales, teamA đều có → company (cấp cao nhất) thắng
        DB::table('recall_policies')->insert([
            ['org_unit_id' => $this->company->id, 'recall_after_days' => 20, 'escalate_after_days' => 10, 'allow_permanent_assignment' => true, 'created_at' => now(), 'updated_at' => now()],
            ['org_unit_id' => $this->sales->id, 'recall_after_days' => 10, 'escalate_after_days' => 5, 'allow_permanent_assignment' => true, 'created_at' => now(), 'updated_at' => now()],
            ['org_unit_id' => $this->teamA->id, 'recall_after_days' => 3, 'escalate_after_days' => 1, 'allow_permanent_assignment' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $r = RecallPolicyResolver::for($this->teamA);

        $this->assertSame(20, $r['recall_after_days']);
        $this->assertSame('org:' . $this->company->id, $r['source']);
    }

    public function test_sibling_team_unaffected(): void
    {
        // Team B đặt policy — không được ảnh hưởng Team A
        DB::table('recall_policies')->insert([
            'org_unit_id' => $this->teamB->id,
            'recall_after_days' => 99,
            'escalate_after_days' => 88,
            'allow_permanent_assignment' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $r = RecallPolicyResolver::for($this->teamA);
        $this->assertNotSame(99, $r['recall_after_days'], 'Team A không được nhận policy của Team B');
        $this->assertSame('system', $r['source']);
    }

    public function test_null_value_falls_back_to_system_default(): void
    {
        DB::table('system_settings')->insert([
            ['key' => 'default_recall_after_days', 'value' => '7', 'created_at' => now(), 'updated_at' => now()],
        ]);
        // policy có nhưng recall_after_days null → fallback về default
        DB::table('recall_policies')->insert([
            'org_unit_id' => $this->teamA->id,
            'recall_after_days' => null,
            'escalate_after_days' => 4,
            'allow_permanent_assignment' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $r = RecallPolicyResolver::for($this->teamA);
        $this->assertSame(7, $r['recall_after_days'], 'null trong policy → fallback về system default');
        $this->assertSame(4, $r['escalate_after_days']);
    }

    public function test_root_node_no_policy_returns_system(): void
    {
        $r = RecallPolicyResolver::for($this->company);
        $this->assertSame('system', $r['source']);
        $this->assertTrue($r['allow_permanent_assignment'], 'Default allow_permanent = true khi không có system_settings');
    }

    public function test_deep_tree_ancestor_lookup(): void
    {
        $subTeam = OrgUnit::createNode(['name' => 'Nhóm nhỏ', 'code' => 'sub-team'], $this->teamA);

        DB::table('recall_policies')->insert([
            'org_unit_id' => $this->sales->id,
            'recall_after_days' => 15,
            'escalate_after_days' => 7,
            'allow_permanent_assignment' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $r = RecallPolicyResolver::for($subTeam);
        $this->assertSame(15, $r['recall_after_days'], 'Cháu (nhóm nhỏ) kế thừa ông (phòng KD)');
        $this->assertSame('org:' . $this->sales->id, $r['source']);
    }
}
