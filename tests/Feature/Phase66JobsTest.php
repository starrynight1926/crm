<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadDistributionLog;
use App\Models\OrgUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase66JobsTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $company;
    private OrgUnit $sales;
    private OrgUnit $teamA;
    private User $sale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = OrgUnit::createNode(['name' => 'Công ty', 'code' => 'company']);
        $this->sales = OrgUnit::createNode(['name' => 'KD', 'code' => 'sales'], $this->company);
        $this->teamA = OrgUnit::createNode(['name' => 'Team A', 'code' => 'team-a'], $this->sales);
        $this->sale = User::factory()->create();
    }

    private function makeLead(array $attrs = []): Lead
    {
        static $i = 0;
        $i++;
        return Lead::create(array_merge([
            'name' => 'Test ' . $i,
            'phone' => '09' . str_pad((string) (10000000 + $i), 8, '0', STR_PAD_LEFT),
            'received_date' => now()->toDateString(),
            'classification' => 'new',
            'pool_level' => Lead::POOL_COMMON,
        ], $attrs));
    }

    public function test_process_recalls_thu_hoi_lead_het_han(): void
    {
        $lead = $this->makeLead([
            'pool_level' => Lead::POOL_PERSONAL,
            'owner_id' => $this->sale->id,
            'org_unit_id' => $this->teamA->id,
            'recall_at' => now()->subMinute(),
            'is_permanent_assignment' => false,
            'assigned_at' => now()->subDay(),
        ]);

        $this->artisan('leads:process-recalls')->assertSuccessful();

        $lead->refresh();
        $this->assertSame(Lead::POOL_TEAM, $lead->pool_level, 'Lead phải về pool team');
        $this->assertNull($lead->owner_id, 'owner đã bị bỏ');
        $this->assertNull($lead->recall_at, 'recall_at reset');
        $this->assertTrue(LeadDistributionLog::where('lead_id', $lead->id)
            ->where('action', LeadDistributionLog::ACTION_RECALL)->exists());
    }

    public function test_process_recalls_bo_qua_lead_chia_vinh_vien(): void
    {
        $lead = $this->makeLead([
            'pool_level' => Lead::POOL_PERSONAL,
            'owner_id' => $this->sale->id,
            'org_unit_id' => $this->teamA->id,
            'recall_at' => now()->subDay(),
            'is_permanent_assignment' => true,
        ]);

        $this->artisan('leads:process-recalls')->assertSuccessful();
        $lead->refresh();
        $this->assertSame(Lead::POOL_PERSONAL, $lead->pool_level, 'Chia vĩnh viễn không bị thu hồi tự động');
    }

    public function test_process_escalates_len_cap_cha_khi_qua_han(): void
    {
        DB::table('recall_policies')->insert([
            'org_unit_id' => $this->sales->id,
            'recall_after_days' => 7,
            'escalate_after_days' => 3,
            'allow_permanent_assignment' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lead = $this->makeLead([
            'pool_level' => Lead::POOL_TEAM,
            'org_unit_id' => $this->teamA->id,
            'assigned_at' => now()->subDays(5),
        ]);

        $this->artisan('leads:process-escalates')->assertSuccessful();

        $lead->refresh();
        $this->assertSame($this->sales->id, $lead->org_unit_id, 'Lead escalate lên cấp cha');
        $this->assertTrue(LeadDistributionLog::where('lead_id', $lead->id)
            ->where('action', LeadDistributionLog::ACTION_ESCALATE)->exists());
    }

    public function test_process_escalates_bo_qua_khi_chua_qua_han(): void
    {
        DB::table('recall_policies')->insert([
            'org_unit_id' => $this->sales->id,
            'recall_after_days' => 7,
            'escalate_after_days' => 3,
            'allow_permanent_assignment' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $lead = $this->makeLead([
            'pool_level' => Lead::POOL_TEAM,
            'org_unit_id' => $this->teamA->id,
            'assigned_at' => now()->subDay(),
        ]);

        $this->artisan('leads:process-escalates')->assertSuccessful();
        $lead->refresh();
        $this->assertSame($this->teamA->id, $lead->org_unit_id);
    }

    public function test_mark_overdue_booking_chi_ap_dung_nhom_1_2_3(): void
    {
        $mkt = $this->makeLead(['source_group' => Lead::SOURCE_MARKETING]);
        $dc = $this->makeLead(['source_group' => Lead::SOURCE_DATA_COLD]);
        $ref = $this->makeLead(['source_group' => Lead::SOURCE_REFERRAL]);
        $recent = $this->makeLead(['source_group' => Lead::SOURCE_MARKETING]);
        Lead::whereIn('id', [$mkt->id, $dc->id, $ref->id])->update(['created_at' => now()->subDays(10)]);
        Lead::where('id', $recent->id)->update(['created_at' => now()->subDay()]);

        $this->artisan('leads:mark-overdue-booking', ['--days' => 7])->assertSuccessful();

        $this->assertNotNull($mkt->refresh()->overdue_marked_at);
        $this->assertNotNull($dc->refresh()->overdue_marked_at);
        $this->assertNull($ref->refresh()->overdue_marked_at, 'Referral không thuộc nhóm booking');
        $this->assertNull($recent->refresh()->overdue_marked_at, 'Lead mới không bị mark');
    }
}
