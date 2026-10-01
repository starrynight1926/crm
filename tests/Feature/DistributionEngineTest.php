<?php

namespace Tests\Feature;

use App\Models\DistributionRule;
use App\Models\Lead;
use App\Models\LeadCap;
use App\Models\LeadDistributionLog;
use App\Models\OrgUnit;
use App\Models\User;
use App\Models\UserLeadSetting;
use App\Services\DistributionEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DistributionEngineTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $root;
    private OrgUnit $sales;
    private OrgUnit $teamA;
    private OrgUnit $teamB;
    private User $sale1;
    private User $sale2;
    private User $sale3;
    private DistributionEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->root = OrgUnit::createNode(['name' => 'Công ty', 'code' => 'company']);
        $this->sales = OrgUnit::createNode(['name' => 'Kinh doanh', 'code' => 'sales'], $this->root);
        $this->teamA = OrgUnit::createNode(['name' => 'Team A', 'code' => 'team-a'], $this->sales);
        $this->teamB = OrgUnit::createNode(['name' => 'Team B', 'code' => 'team-b'], $this->sales);

        $this->sale1 = User::factory()->create(['name' => 'Sale 1']);
        $this->sale2 = User::factory()->create(['name' => 'Sale 2']);
        $this->sale3 = User::factory()->create(['name' => 'Sale 3']);

        $this->engine = new DistributionEngine();
    }

    private function makeLead(array $attrs = []): Lead
    {
        static $i = 100;
        $i++;

        return Lead::create(array_merge([
            'received_date' => now()->toDateString(),
            'name' => "Khách $i",
            'phone' => '09' . str_pad((string) (70000000 + $i), 8, '0', STR_PAD_LEFT),
            'pool_level' => Lead::POOL_COMMON,
        ], $attrs));
    }

    private function makeRuleL1(array $attrs = [], array $targets = []): DistributionRule
    {
        $rule = DistributionRule::create(array_merge([
            'name' => 'L1', 'level' => DistributionRule::LEVEL_POOL_TO_TEAM, 'strategy' => 'round_robin',
        ], $attrs));
        foreach ($targets as $i => [$type, $id, $weight]) {
            $rule->targets()->create(['target_type' => $type, 'target_id' => $id, 'weight' => $weight, 'position' => $i]);
        }

        return $rule;
    }

    private function makeRuleL2(OrgUnit $org, array $userWeights, string $strategy = 'round_robin', array $attrs = []): DistributionRule
    {
        $rule = DistributionRule::create(array_merge([
            'name' => 'L2 ' . $org->name, 'level' => DistributionRule::LEVEL_TEAM_TO_USER,
            'org_unit_id' => $org->id, 'strategy' => $strategy,
        ], $attrs));
        $i = 0;
        foreach ($userWeights as $userId => $weight) {
            $rule->targets()->create(['target_type' => 'user', 'target_id' => $userId, 'weight' => $weight, 'position' => $i++]);
        }

        return $rule;
    }

    // ---------- Chia 2 cấp end-to-end ----------

    public function test_full_flow_common_to_team_to_user(): void
    {
        $this->makeRuleL1([], [['org_unit', $this->teamA->id, 1]]);
        $this->makeRuleL2($this->teamA, [$this->sale1->id => 1]);

        $lead = $this->makeLead();
        $this->engine->distribute($lead);
        $lead->refresh();

        $this->assertSame(Lead::POOL_PERSONAL, $lead->pool_level);
        $this->assertSame($this->teamA->id, $lead->org_unit_id);
        $this->assertSame($this->sale1->id, $lead->owner_id);
        $this->assertNotNull($lead->assigned_at);
        $this->assertSame(2, LeadDistributionLog::where('lead_id', $lead->id)->count()); // L1 + L2
        Notification::assertSentTo($this->sale1, \App\Notifications\LeadAssigned::class);
    }

    public function test_no_matching_rule_leaves_lead_in_common_pool(): void
    {
        $this->makeRuleL1(['conditions' => ['region' => ['Hà Nội']]], [['org_unit', $this->teamA->id, 1]]);

        $lead = $this->makeLead(['region' => 'Đà Nẵng']);
        $this->engine->distribute($lead);

        $this->assertSame(Lead::POOL_COMMON, $lead->refresh()->pool_level);
    }

    // ---------- Matching theo priority + điều kiện ----------

    public function test_first_matching_rule_by_priority_wins(): void
    {
        $this->makeRuleL1(['priority' => 10, 'conditions' => ['region' => ['Hà Nội']]], [['org_unit', $this->teamA->id, 1]]);
        $this->makeRuleL1(['priority' => 20], [['org_unit', $this->teamB->id, 1]]); // khớp tất

        $hn = $this->makeLead(['region' => 'Hà Nội']);
        $dn = $this->makeLead(['region' => 'Đà Nẵng']);
        $this->engine->distribute($hn);
        $this->engine->distribute($dn);

        $this->assertSame($this->teamA->id, $hn->refresh()->org_unit_id);
        $this->assertSame($this->teamB->id, $dn->refresh()->org_unit_id);
    }

    public function test_condition_multiple_fields_all_must_match(): void
    {
        $this->makeRuleL1(
            ['conditions' => ['region' => ['Hà Nội'], 'insight' => ['VIP']]],
            [['org_unit', $this->teamA->id, 1]]
        );

        $match = $this->makeLead(['region' => 'Hà Nội', 'insight' => 'VIP']);
        $noMatch = $this->makeLead(['region' => 'Hà Nội', 'insight' => 'normal']);
        $this->engine->distribute($match);
        $this->engine->distribute($noMatch);

        $this->assertSame(Lead::POOL_TEAM, $match->refresh()->pool_level);
        $this->assertSame(Lead::POOL_COMMON, $noMatch->refresh()->pool_level);
    }

    // ---------- Strategy ----------

    public function test_round_robin_distributes_evenly_in_order(): void
    {
        $this->makeRuleL2($this->teamA, [$this->sale1->id => 1, $this->sale2->id => 1, $this->sale3->id => 1]);

        $owners = [];
        for ($i = 0; $i < 6; $i++) {
            $lead = $this->makeLead(['pool_level' => Lead::POOL_TEAM, 'org_unit_id' => $this->teamA->id]);
            $this->engine->distribute($lead);
            $owners[] = $lead->refresh()->owner_id;
        }

        $this->assertSame(
            [$this->sale1->id, $this->sale2->id, $this->sale3->id, $this->sale1->id, $this->sale2->id, $this->sale3->id],
            $owners
        );
    }

    public function test_weighted_follows_ratio(): void
    {
        // Tỉ trọng 3-1: trong 8 lead, sale1 nhận 6, sale2 nhận 2
        $this->makeRuleL2($this->teamA, [$this->sale1->id => 3, $this->sale2->id => 1], 'weighted');

        $counts = [$this->sale1->id => 0, $this->sale2->id => 0];
        for ($i = 0; $i < 8; $i++) {
            $lead = $this->makeLead(['pool_level' => Lead::POOL_TEAM, 'org_unit_id' => $this->teamA->id]);
            $this->engine->distribute($lead);
            $counts[$lead->refresh()->owner_id]++;
        }

        $this->assertSame(6, $counts[$this->sale1->id]);
        $this->assertSame(2, $counts[$this->sale2->id]);
    }

    public function test_top_revenue_falls_back_to_round_robin_for_now(): void
    {
        $this->makeRuleL2($this->teamA, [$this->sale1->id => 1, $this->sale2->id => 1], 'top_revenue');

        $lead = $this->makeLead(['pool_level' => Lead::POOL_TEAM, 'org_unit_id' => $this->teamA->id]);
        $this->engine->distribute($lead);

        $this->assertNotNull($lead->refresh()->owner_id); // vẫn chia được, không nổ
    }

    // ---------- Constraints ----------

    public function test_user_not_receiving_is_skipped(): void
    {
        UserLeadSetting::create(['user_id' => $this->sale1->id, 'receiving' => false]);
        $this->makeRuleL2($this->teamA, [$this->sale1->id => 1, $this->sale2->id => 1]);

        for ($i = 0; $i < 3; $i++) {
            $lead = $this->makeLead(['pool_level' => Lead::POOL_TEAM, 'org_unit_id' => $this->teamA->id]);
            $this->engine->distribute($lead);
            $this->assertSame($this->sale2->id, $lead->refresh()->owner_id);
        }
    }

    public function test_off_until_past_means_receiving_again(): void
    {
        UserLeadSetting::create(['user_id' => $this->sale1->id, 'receiving' => false, 'off_until' => now()->subDay()]);
        $this->makeRuleL2($this->teamA, [$this->sale1->id => 1]);

        $lead = $this->makeLead(['pool_level' => Lead::POOL_TEAM, 'org_unit_id' => $this->teamA->id]);
        $this->engine->distribute($lead);

        $this->assertSame($this->sale1->id, $lead->refresh()->owner_id);
    }

    public function test_user_cap_moves_to_next_target(): void
    {
        LeadCap::create(['scope_type' => 'user', 'scope_id' => $this->sale1->id, 'daily_cap' => 2]);
        $this->makeRuleL2($this->teamA, [$this->sale1->id => 1, $this->sale2->id => 1]);

        $owners = [];
        for ($i = 0; $i < 5; $i++) {
            $lead = $this->makeLead(['pool_level' => Lead::POOL_TEAM, 'org_unit_id' => $this->teamA->id]);
            $this->engine->distribute($lead);
            $owners[] = $lead->refresh()->owner_id;
        }

        // sale1 chỉ nhận đúng 2, còn lại dồn sang sale2
        $this->assertSame(2, count(array_filter($owners, fn ($o) => $o === $this->sale1->id)));
        $this->assertSame(3, count(array_filter($owners, fn ($o) => $o === $this->sale2->id)));
    }

    public function test_all_targets_capped_leaves_lead_in_team_pool(): void
    {
        LeadCap::create(['scope_type' => 'user', 'scope_id' => $this->sale1->id, 'daily_cap' => 0]);
        $this->makeRuleL2($this->teamA, [$this->sale1->id => 1]);

        $lead = $this->makeLead(['pool_level' => Lead::POOL_TEAM, 'org_unit_id' => $this->teamA->id]);
        $this->engine->distribute($lead);

        $this->assertSame(Lead::POOL_TEAM, $lead->refresh()->pool_level);
        $this->assertNull($lead->owner_id);
    }

    public function test_org_cap_including_parent_dept_cap(): void
    {
        // Trần phòng Kinh doanh (cha) = 1/ngày → teamA nhận 1 lead là kẹt cả nhánh
        LeadCap::create(['scope_type' => 'org_unit', 'scope_id' => $this->sales->id, 'daily_cap' => 1]);
        $this->makeRuleL1([], [['org_unit', $this->teamA->id, 1], ['org_unit', $this->teamB->id, 1]]);

        $l1 = $this->makeLead();
        $l2 = $this->makeLead();
        $this->engine->distribute($l1);
        $this->engine->distribute($l2);

        $this->assertSame(Lead::POOL_TEAM, $l1->refresh()->pool_level);
        // teamB cũng thuộc phòng Kinh doanh → kẹt trần cha, lead 2 nằm lại kho chung
        $this->assertSame(Lead::POOL_COMMON, $l2->refresh()->pool_level);
    }

    // ---------- Counters ----------

    public function test_counters_reset_by_period_day(): void
    {
        $rule = $this->makeRuleL2($this->teamA, [$this->sale1->id => 1, $this->sale2->id => 1]);

        $lead = $this->makeLead(['pool_level' => Lead::POOL_TEAM, 'org_unit_id' => $this->teamA->id]);
        $this->engine->distribute($lead);
        $this->assertSame($this->sale1->id, $lead->refresh()->owner_id);

        // Sang ngày mới: counter mới → quay lại sale1 (không tiếp con trỏ hôm qua)
        $this->travel(1)->days();
        $lead2 = $this->makeLead(['pool_level' => Lead::POOL_TEAM, 'org_unit_id' => $this->teamA->id]);
        $this->engine->distribute($lead2);
        $this->assertSame($this->sale1->id, $lead2->refresh()->owner_id);
    }
}
