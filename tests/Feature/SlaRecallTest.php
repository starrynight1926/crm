<?php

namespace Tests\Feature;

use App\Models\DistributionRule;
use App\Models\Lead;
use App\Models\LeadDistributionLog;
use App\Models\OrgUnit;
use App\Models\SlaPolicy;
use App\Models\User;
use App\Services\DistributionEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SlaRecallTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $teamA;
    private User $sale1;
    private User $sale2;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $root = OrgUnit::createNode(['name' => 'Công ty', 'code' => 'company']);
        $this->teamA = OrgUnit::createNode(['name' => 'Team A', 'code' => 'team-a'], $root);
        $this->sale1 = User::factory()->create();
        $this->sale2 = User::factory()->create();
    }

    private function assignedLead(User $owner, array $attrs = []): Lead
    {
        static $i = 0;
        $i++;

        return Lead::create(array_merge([
            'received_date' => now()->toDateString(),
            'name' => "Khách SLA $i",
            'phone' => '09' . str_pad((string) (80000000 + $i), 8, '0', STR_PAD_LEFT),
            'pool_level' => Lead::POOL_PERSONAL,
            'org_unit_id' => $this->teamA->id,
            'owner_id' => $owner->id,
            'assigned_at' => now()->subHours(30),
        ], $attrs));
    }

    public function test_overdue_uncared_lead_recalled_and_redistributed(): void
    {
        SlaPolicy::create(['mode' => 'auto', 'recall_after_hours' => 24, 'recall_to' => 'team']);
        // Rule L2 chia lại cho sale2
        $rule = DistributionRule::create(['name' => 'L2', 'level' => 'team_to_user', 'org_unit_id' => $this->teamA->id, 'strategy' => 'round_robin']);
        $rule->targets()->create(['target_type' => 'user', 'target_id' => $this->sale2->id, 'weight' => 1, 'position' => 0]);

        $lead = $this->assignedLead($this->sale1);

        $this->artisan('leads:recall-overdue')->assertSuccessful();

        $lead->refresh();
        $this->assertSame($this->sale2->id, $lead->owner_id); // đã chia lại
        $this->assertTrue(LeadDistributionLog::where('lead_id', $lead->id)->where('action', 'recall')->whereNull('actor_id')->exists());
    }

    public function test_cared_lead_not_recalled(): void
    {
        SlaPolicy::create(['mode' => 'auto', 'recall_after_hours' => 24, 'recall_to' => 'team']);
        $lead = $this->assignedLead($this->sale1, ['last_care_at' => now()->subHour()]);

        $this->artisan('leads:recall-overdue');

        $this->assertSame($this->sale1->id, $lead->refresh()->owner_id);
    }

    public function test_within_sla_not_recalled(): void
    {
        SlaPolicy::create(['mode' => 'auto', 'recall_after_hours' => 48, 'recall_to' => 'team']);
        $lead = $this->assignedLead($this->sale1); // mới 30h, hạn 48h

        $this->artisan('leads:recall-overdue');

        $this->assertSame($this->sale1->id, $lead->refresh()->owner_id);
    }

    public function test_mode_off_and_manual_do_nothing(): void
    {
        SlaPolicy::create(['mode' => 'off', 'recall_after_hours' => 1, 'recall_to' => 'team']);
        $lead = $this->assignedLead($this->sale1);

        $this->artisan('leads:recall-overdue');

        $this->assertSame($this->sale1->id, $lead->refresh()->owner_id);
    }

    public function test_org_specific_policy_overrides_default(): void
    {
        SlaPolicy::create(['mode' => 'auto', 'recall_after_hours' => 24, 'recall_to' => 'team']); // mặc định: 24h
        SlaPolicy::create(['org_unit_id' => $this->teamA->id, 'mode' => 'auto', 'recall_after_hours' => 48, 'recall_to' => 'team']); // team A: 48h

        $lead = $this->assignedLead($this->sale1); // 30h — quá hạn mặc định nhưng chưa quá hạn team A

        $this->artisan('leads:recall-overdue');

        $this->assertSame($this->sale1->id, $lead->refresh()->owner_id);
    }

    public function test_recall_to_common_clears_org(): void
    {
        SlaPolicy::create(['mode' => 'auto', 'recall_after_hours' => 24, 'recall_to' => 'common']);
        $lead = $this->assignedLead($this->sale1);

        $this->artisan('leads:recall-overdue');

        $lead->refresh();
        $this->assertNull($lead->owner_id);
        $this->assertNull($lead->org_unit_id);
        $this->assertSame(Lead::POOL_COMMON, $lead->pool_level);
    }

    // ---------- Thao tác thủ công ----------

    public function test_manual_assign_and_pull_log_correctly(): void
    {
        $engine = new DistributionEngine();
        $manager = User::factory()->create();

        $lead = $this->assignedLead($this->sale1, ['pool_level' => Lead::POOL_TEAM, 'owner_id' => null, 'assigned_at' => null]);

        $engine->manualAssign($lead, $this->sale1, $manager->id);
        $this->assertSame($this->sale1->id, $lead->refresh()->owner_id);
        $this->assertTrue(LeadDistributionLog::where('lead_id', $lead->id)->where('action', 'manual_assign')->where('actor_id', $manager->id)->exists());

        $engine->recall($lead->refresh(), Lead::POOL_TEAM, $manager->id);
        $this->assertNull($lead->refresh()->owner_id);

        $engine->pull($lead->refresh(), $this->sale2);
        $this->assertSame($this->sale2->id, $lead->refresh()->owner_id);
        $this->assertTrue(LeadDistributionLog::where('lead_id', $lead->id)->where('action', 'pull')->where('actor_id', $this->sale2->id)->exists());
    }
}
