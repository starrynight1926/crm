<?php

namespace Tests\Feature;

use App\Models\DistributionRule;
use App\Models\Lead;
use App\Models\OrgUnit;
use App\Models\Payment;
use App\Models\User;
use App\Services\DistributionEngine;
use App\Services\StatsAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StatsAndTopStrategyTest extends TestCase
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
        $this->sale1 = User::factory()->create(['name' => 'Sale 1']);
        $this->sale2 = User::factory()->create(['name' => 'Sale 2']);
    }

    private function makeLead(array $attrs = []): Lead
    {
        static $i = 0;
        $i++;

        return Lead::create(array_merge([
            'received_date' => now()->toDateString(),
            'name' => "Khách $i",
            'phone' => '09' . str_pad((string) (60000000 + $i), 8, '0', STR_PAD_LEFT),
        ], $attrs));
    }

    // ---------- Aggregator ----------

    public function test_aggregate_funnel_counts_by_dimensions(): void
    {
        $this->makeLead(['org_unit_id' => $this->teamA->id, 'owner_id' => $this->sale1->id, 'camp' => 'C1', 'classification' => 'close']);
        $this->makeLead(['org_unit_id' => $this->teamA->id, 'owner_id' => $this->sale1->id, 'camp' => 'C1', 'classification' => 'follow']);
        $this->makeLead(['org_unit_id' => $this->teamA->id, 'owner_id' => $this->sale2->id, 'camp' => 'C2', 'classification' => 'new']);

        app(StatsAggregator::class)->aggregateDay(now()->toDateString());

        $row1 = DB::table('stats_daily')->where('user_id', $this->sale1->id)->first();
        $this->assertSame(2, (int) $row1->total);
        $this->assertSame(1, (int) $row1->close);
        $this->assertSame(1, (int) $row1->follow);

        $row2 = DB::table('stats_daily')->where('user_id', $this->sale2->id)->first();
        $this->assertSame(1, (int) $row2->total);
        $this->assertSame(0, (int) $row2->close);
    }

    public function test_aggregate_revenue_from_payments(): void
    {
        $lead = $this->makeLead(['org_unit_id' => $this->teamA->id, 'owner_id' => $this->sale1->id]);
        Payment::create(['lead_id' => $lead->id, 'amount' => 5_000_000, 'paid_at' => now(), 'collected_by' => $this->sale1->id]);
        Payment::create(['lead_id' => $lead->id, 'amount' => 2_000_000, 'paid_at' => now(), 'collected_by' => $this->sale1->id]);

        app(StatsAggregator::class)->aggregateDay(now()->toDateString());

        $this->assertSame(
            7_000_000,
            (int) DB::table('stats_daily')->where('user_id', $this->sale1->id)->sum('revenue_collected')
        );
    }

    public function test_aggregate_is_idempotent(): void
    {
        $this->makeLead(['owner_id' => $this->sale1->id]);

        $aggregator = app(StatsAggregator::class);
        $aggregator->aggregateDay(now()->toDateString());
        $aggregator->aggregateDay(now()->toDateString()); // chạy lại không nhân đôi

        $this->assertSame(1, (int) DB::table('stats_daily')->sum('total'));
    }

    // ---------- Strategy top_revenue / top_close_rate ----------

    private function makeTopRule(string $strategy, array $config = []): DistributionRule
    {
        $rule = DistributionRule::create([
            'name' => 'Top', 'level' => 'team_to_user', 'org_unit_id' => $this->teamA->id,
            'strategy' => $strategy, 'strategy_config' => $config ?: ['metric_window' => 'month'],
        ]);
        $rule->targets()->create(['target_type' => 'user', 'target_id' => $this->sale1->id, 'weight' => 1, 'position' => 0]);
        $rule->targets()->create(['target_type' => 'user', 'target_id' => $this->sale2->id, 'weight' => 1, 'position' => 1]);

        return $rule;
    }

    public function test_top_revenue_picks_highest_earner(): void
    {
        // sale2 doanh thu cao hơn trong tháng
        DB::table('stats_daily')->insert([
            ['date' => now()->toDateString(), 'user_id' => $this->sale1->id, 'revenue_collected' => 1_000_000, 'total' => 0, 'lead' => 0, 'follow' => 0, 'net' => 0, 'booking' => 0, 'show' => 0, 'close' => 0],
            ['date' => now()->toDateString(), 'user_id' => $this->sale2->id, 'revenue_collected' => 9_000_000, 'total' => 0, 'lead' => 0, 'follow' => 0, 'net' => 0, 'booking' => 0, 'show' => 0, 'close' => 0],
        ]);
        $this->makeTopRule('top_revenue');

        $lead = $this->makeLead(['pool_level' => Lead::POOL_TEAM, 'org_unit_id' => $this->teamA->id]);
        (new DistributionEngine())->distribute($lead);

        $this->assertSame($this->sale2->id, $lead->refresh()->owner_id);
    }

    public function test_top_close_rate_picks_highest_rate(): void
    {
        // sale1: 5/10 = 50%; sale2: 3/4 = 75%
        DB::table('stats_daily')->insert([
            ['date' => now()->toDateString(), 'user_id' => $this->sale1->id, 'total' => 10, 'close' => 5, 'lead' => 0, 'follow' => 0, 'net' => 0, 'booking' => 0, 'show' => 0, 'revenue_collected' => 0],
            ['date' => now()->toDateString(), 'user_id' => $this->sale2->id, 'total' => 4, 'close' => 3, 'lead' => 0, 'follow' => 0, 'net' => 0, 'booking' => 0, 'show' => 0, 'revenue_collected' => 0],
        ]);
        $this->makeTopRule('top_close_rate');

        $lead = $this->makeLead(['pool_level' => Lead::POOL_TEAM, 'org_unit_id' => $this->teamA->id]);
        (new DistributionEngine())->distribute($lead);

        $this->assertSame($this->sale2->id, $lead->refresh()->owner_id);
    }

    public function test_top_revenue_respects_constraints(): void
    {
        DB::table('stats_daily')->insert([
            ['date' => now()->toDateString(), 'user_id' => $this->sale2->id, 'revenue_collected' => 9_000_000, 'total' => 0, 'lead' => 0, 'follow' => 0, 'net' => 0, 'booking' => 0, 'show' => 0, 'close' => 0],
        ]);
        // sale2 tắt nhận số → dù doanh thu cao vẫn phải né
        \App\Models\UserLeadSetting::create(['user_id' => $this->sale2->id, 'receiving' => false]);
        $this->makeTopRule('top_revenue');

        $lead = $this->makeLead(['pool_level' => Lead::POOL_TEAM, 'org_unit_id' => $this->teamA->id]);
        (new DistributionEngine())->distribute($lead);

        $this->assertSame($this->sale1->id, $lead->refresh()->owner_id);
    }

    public function test_no_stats_ties_break_by_position(): void
    {
        $this->makeTopRule('top_revenue');

        $lead = $this->makeLead(['pool_level' => Lead::POOL_TEAM, 'org_unit_id' => $this->teamA->id]);
        (new DistributionEngine())->distribute($lead);

        $this->assertSame($this->sale1->id, $lead->refresh()->owner_id); // position 0
    }
}
