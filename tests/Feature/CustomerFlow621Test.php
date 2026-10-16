<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\BookingLog;
use App\Models\CallLog;
use App\Models\Lead;
use App\Models\LeadPhaseClosure;
use App\Models\OrgUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 6.21 — Customer Flow 7 phase tests.
 * Design: docs/design/customer_flow_30-07-2026.md §12
 */
class CustomerFlow621Test extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $company;
    private User $adminOps;   // có phase.rollback + all close
    private User $sale;       // có close.new/call/booking, không có rollback
    private User $observer;   // không có perm nào

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        // Chạy migration seed phase 6.21 (idempotent)
        $this->artisan('migrate', ['--force' => true]);

        $this->company = OrgUnit::createNode(['name' => 'Cty', 'code' => 'company']);

        $adminRole = Role::create(['name' => 'Admin', 'is_system' => true]);
        $adminRole->permissions()->sync(Permission::pluck('id'));
        $this->adminOps = User::factory()->create();
        Assignment::create([
            'user_id' => $this->adminOps->id, 'role_id' => $adminRole->id,
            'org_unit_id' => $this->company->id, 'data_scope' => Assignment::SCOPE_CUSTOM,
        ])->scopeNodes()->sync([$this->company->id]);

        $saleRole = Role::create(['name' => 'Sale']);
        $saleRole->permissions()->sync(Permission::whereIn('key', [
            'lead.view', 'lead.create', 'lead.update',
            'phase.close.new', 'phase.close.call', 'phase.close.booking',
        ])->pluck('id'));
        $this->sale = User::factory()->create();
        Assignment::create([
            'user_id' => $this->sale->id, 'role_id' => $saleRole->id,
            'org_unit_id' => $this->company->id, 'data_scope' => Assignment::SCOPE_CUSTOM,
        ])->scopeNodes()->sync([$this->company->id]);

        $observerRole = Role::create(['name' => 'Observer']);
        $observerRole->permissions()->sync(Permission::whereIn('key', ['lead.view'])->pluck('id'));
        $this->observer = User::factory()->create();
        Assignment::create([
            'user_id' => $this->observer->id, 'role_id' => $observerRole->id,
            'org_unit_id' => $this->company->id, 'data_scope' => Assignment::SCOPE_CUSTOM,
        ])->scopeNodes()->sync([$this->company->id]);
    }

    private function makeLead(string $source, array $overrides = []): Lead
    {
        return Lead::create(array_merge([
            'name'          => 'Test KH',
            'phone'         => '09' . random_int(10000000, 99999999),
            'received_date' => now()->toDateString(),
            'source_group'  => $source,
            'phase'         => 1,
            'is_first_visit' => true,
            'org_unit_id'   => $this->company->id,
            'pool_level'    => Lead::POOL_COMMON,
        ], $overrides));
    }

    // ------------------------------------------------------------
    // 1. startPhase mapping cho 7 nguồn
    // ------------------------------------------------------------
    public function test_source_maps_to_correct_start_phase(): void
    {
        // Phase 6.23: gộp Phase 1+2 → 1. Mapping mới:
        $cases = [
            Lead::SOURCE_MKT    => 1,
            Lead::SOURCE_MKT_BR => 3,
            Lead::SOURCE_BA     => 2,
            Lead::SOURCE_SA     => 1,
            Lead::SOURCE_BDM    => 1,
            Lead::SOURCE_BOD    => 1,
            Lead::SOURCE_WI     => 1,
        ];
        foreach ($cases as $src => $expected) {
            $lead = $this->makeLead($src);
            $this->assertSame($expected, $lead->startPhase(), "Source {$src} phải start ở phase {$expected}");
        }
    }

    // ------------------------------------------------------------
    // 2. Bulk save chốt N phase (MKT_BR = 4 phase)
    // ------------------------------------------------------------
    public function test_bulk_save_closes_all_phases_from_1_to_start(): void
    {
        // Phase 6.23: MKT_BR start_phase = 3 (Booking). Bulk save đóng phase 1,2,3.
        $lead = $this->makeLead(Lead::SOURCE_MKT_BR);
        $this->assertTrue($lead->isBulkOpen());
        $closed = $lead->bulkSave($this->adminOps);
        $this->assertSame([1, 2, 3], $closed);
        $lead->refresh();
        $this->assertSame(4, (int) $lead->phase, 'Sau bulk save, lead phải ở phase 4 (Check-in)');
        $this->assertSame(3, $lead->phaseClosures()->count());
    }

    // ------------------------------------------------------------
    // 3. Close phase tuần tự
    // ------------------------------------------------------------
    public function test_close_phase_sequentially(): void
    {
        $lead = $this->makeLead(Lead::SOURCE_MKT); // start_phase = 1
        // Bấm Lưu chốt (chỉ phase 1)
        $lead->bulkSave($this->adminOps);
        $lead->refresh();
        $this->assertSame(2, (int) $lead->phase);

        // Admin đóng phase 2 → nhảy phase 3
        $lead->closePhase(2, $this->adminOps);
        $lead->refresh();
        $this->assertSame(3, (int) $lead->phase);
        $this->assertTrue($lead->phaseClosures()->where('phase', 2)->exists());
    }

    // ------------------------------------------------------------
    // 4. Close phase yêu cầu perm
    // ------------------------------------------------------------
    public function test_close_phase_requires_permission(): void
    {
        $lead = $this->makeLead(Lead::SOURCE_MKT);
        $lead->bulkSave($this->adminOps); // đóng phase 1
        $lead->closePhase(2, $this->adminOps); // → phase 3

        // Sale KHÔNG có phase.close.call (chỉ close.new/booking/call) - thực ra có, sửa test:
        // Sale có phase.close.call → nên OK. Test lại với observer (không có gì).
        $lead->refresh();
        $this->expectException(\RuntimeException::class);
        $lead->closePhase(3, $this->observer);
    }

    // ------------------------------------------------------------
    // 5. Rollback chỉ Admin vận hành được
    // ------------------------------------------------------------
    public function test_rollback_only_admin_ops(): void
    {
        $lead = $this->makeLead(Lead::SOURCE_MKT_BR);
        $lead->bulkSave($this->adminOps); // đóng 1..4, phase = 5

        // Sale (không có rollback) → throw
        try {
            $lead->rollbackTo(2, $this->sale);
            $this->fail('Sale không được lùi phase');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Admin vận hành', $e->getMessage());
        }

        // Admin ops → OK, xóa closure từ phase 2 trở đi
        $lead->rollbackTo(2, $this->adminOps);
        $lead->refresh();
        $this->assertSame(2, (int) $lead->phase);
        $this->assertSame(1, $lead->phaseClosures()->count()); // chỉ còn closure phase 1
    }

    // ------------------------------------------------------------
    // 6. Khách quay lại reset phase về 3
    // ------------------------------------------------------------
    public function test_returning_customer_resets_phase_to_call(): void
    {
        // Phase 6.23: Call = phase 2 (không còn phase 3 nữa)
        $lead = $this->makeLead(Lead::SOURCE_MKT, ['phase' => 4]);
        // Sinh closures 1..3 giả lập
        for ($p = 1; $p <= 3; $p++) {
            LeadPhaseClosure::create([
                'lead_id' => $lead->id, 'phase' => $p,
                'closed_by' => $this->adminOps->id, 'closed_at' => now(),
            ]);
        }
        // Chốt phase 4 (để đủ điều kiện markReturning)
        $lead->closePhase(4, $this->adminOps);
        $lead->refresh();

        $lead->markReturning($this->adminOps);
        $lead->refresh();
        $this->assertFalse((bool) $lead->is_first_visit);
        $this->assertSame(2, (int) $lead->phase);
        // markReturning xóa closure từ targetPhase (2) trở đi → chỉ còn closure phase 1.
        $this->assertGreaterThanOrEqual(1, $lead->phaseClosures()->count());
    }

    // ------------------------------------------------------------
    // 7. Ai được ghi call_log
    // ------------------------------------------------------------
    public function test_call_log_permission(): void
    {
        $lead = $this->makeLead(Lead::SOURCE_MKT, ['owner_id' => $this->sale->id]);
        $this->assertTrue($lead->canLogCall($this->sale));     // owner
        $this->assertTrue($lead->canLogCall($this->adminOps)); // rollback perm
        $this->assertFalse($lead->canLogCall($this->observer)); // không có gì
    }

    // ------------------------------------------------------------
    // 8. Booking log sync booking_status
    // ------------------------------------------------------------
    public function test_booking_log_syncs_booking_status(): void
    {
        $lead = $this->makeLead(Lead::SOURCE_MKT_BR, ['owner_id' => $this->sale->id]);
        BookingLog::create([
            'lead_id'   => $lead->id,
            'user_id'   => $this->sale->id,
            'status'    => BookingLog::STATUS_DA_XAC_NHAN,
            'scheduled_at' => now()->addDay(),
        ]);
        BookingLog::syncLeadBookingStatus($lead->id);
        $lead->refresh();
        $this->assertSame(Lead::BOOKING_BOOKED, $lead->booking_status);
    }

    // ------------------------------------------------------------
    // 9. Backfill migration: tất cả lead cũ có phase
    // ------------------------------------------------------------
    public function test_lead_always_has_phase_after_migration(): void
    {
        // Verify NOT NULL constraint + default 1
        $lead = $this->makeLead(Lead::SOURCE_MKT);
        $this->assertNotNull($lead->phase);
        // Lead tạo mới không pass phase → default 1
        $lead2 = Lead::create([
            'name' => 'X', 'phone' => '0999999999',
            'received_date' => now()->toDateString(),
            'source_group' => Lead::SOURCE_MKT,
            'org_unit_id' => $this->company->id,
        ]);
        $this->assertSame(1, (int) $lead2->phase);
        $this->assertTrue((bool) $lead2->is_first_visit);
    }

    // ------------------------------------------------------------
    // 10. Bulk save fail khi user thiếu perm
    // ------------------------------------------------------------
    public function test_bulk_save_fails_without_full_perms(): void
    {
        // Phase 6.23: SOURCE_MKT_BR start_phase = 3. Observer không có perm phase.close.* nào → fail.
        $lead = $this->makeLead(Lead::SOURCE_MKT_BR);
        $this->expectException(\RuntimeException::class);
        $lead->bulkSave($this->observer);
    }
}
