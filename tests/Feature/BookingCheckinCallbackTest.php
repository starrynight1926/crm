<?php

namespace Tests\Feature;

use App\Models\BookingLateLog;
use App\Models\BookingLog;
use App\Models\Facility;
use App\Models\Lead;
use App\Models\LeadPhaseClosure;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase C1.b rev5 — callback sbooking đánh dấu khách tới (da_toi / toi_tre)
 * → scrm auto-close phase 4+5 + tạo BookingLateLog nếu trễ.
 */
class BookingCheckinCallbackTest extends TestCase
{
    use RefreshDatabase;

    private function boot(): array
    {
        $org = OrgUnit::create(['code' => 'root', 'name' => 'Root', 'path' => '/']);
        $role = Role::create(['name' => 'sb_pusher', 'label' => 'SB']);
        $facility = Facility::create(['name' => 'HN', 'parent_id' => null, 'active' => true, 'sbooking_co_so_id' => 1]);

        $pusher = User::create(['name' => 'SB Admin', 'email' => 'sb@t.com', 'password' => bcrypt('x'), 'org_unit_id' => $org->id, 'role_id' => $role->id]);
        $pusher->update(['api_token' => 'test-token-abc']);

        $lead = Lead::create([
            'name' => 'A', 'phone' => '0900000001', 'code' => 'KH-001-MKT',
            'org_unit_id' => $org->id, 'source_group' => 'mkt',
            'imported_by' => $pusher->id, 'pool_level' => 'personal',
            'received_date' => now()->toDateString(), 'phase' => 4,
        ]);

        $bl = BookingLog::create([
            'lead_id' => $lead->id, 'user_id' => $pusher->id,
            'type' => 'tham_kham', 'status' => 'da_xac_nhan',
            'scheduled_at' => now()->subMinutes(30), // hẹn 30 phút trước
            'facility_id' => $facility->id,
            'sbooking_booking_id' => 100, 'sbooking_booking_ma' => 'BKG-260801-000100',
            'sync_status' => 'approved',
        ]);

        return [$lead, $bl, $pusher];
    }

    public function test_da_toi_closes_phase_4_and_5_no_late_log(): void
    {
        [$lead, $bl, $pusher] = $this->boot();

        $r = $this->withHeaders(['Authorization' => 'Bearer test-token-abc'])
            ->postJson('/api/leads/KH-001-MKT/booking-event', [
                'type' => 'status', 'booking_ma' => 'BKG-260801-000100',
                'sbooking_booking_id' => 100, 'trang_thai_khach' => 'da_toi',
            ]);
        $r->assertOk();

        $this->assertEquals(5, $lead->fresh()->phase);
        $this->assertTrue(LeadPhaseClosure::where('lead_id', $lead->id)->where('phase', 4)->exists());
        $this->assertTrue(LeadPhaseClosure::where('lead_id', $lead->id)->where('phase', 5)->exists());
        $this->assertSame(0, BookingLateLog::where('lead_id', $lead->id)->count());
    }

    public function test_toi_tre_creates_late_log_and_moves_phase_5(): void
    {
        [$lead, $bl, $pusher] = $this->boot();

        $r = $this->withHeaders(['Authorization' => 'Bearer test-token-abc'])
            ->postJson('/api/leads/KH-001-MKT/booking-event', [
                'type' => 'status', 'booking_ma' => 'BKG-260801-000100',
                'sbooking_booking_id' => 100, 'trang_thai_khach' => 'toi_tre',
            ]);
        $r->assertOk();

        $this->assertEquals(5, $lead->fresh()->phase);
        $this->assertSame(1, BookingLateLog::where('lead_id', $lead->id)->count());
        $late = BookingLateLog::where('lead_id', $lead->id)->first();
        $this->assertSame(100, $late->sbooking_booking_id);
        $this->assertNotNull($late->late_minutes);
        $this->assertGreaterThan(0, $late->late_minutes);
    }

    public function test_owner_without_perm_cannot_checkin(): void
    {
        // Verify owner (sale) không có phase.close.checkin + không có phase.rollback → canCheckin=false.
        // Logic: hàm check isVisibleTo trước. Ở đây bypass bằng cách check trực tiếp expected behavior.
        [$lead, $bl, $pusher] = $this->boot();
        // Pusher là imported_by nên isVisibleTo=true. Nhưng không có phase.close.checkin.
        $this->assertFalse($lead->canCheckin($pusher), 'User không có perm → không check-in được');
    }
}
