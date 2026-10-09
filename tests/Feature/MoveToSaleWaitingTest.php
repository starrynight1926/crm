<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\OrgUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rule cứng (2026-07-31): chỉ được chuyển lead sang phase Sale
 * khi khách đã đặt booking (booking_status = booked).
 */
class MoveToSaleWaitingTest extends TestCase
{
    use RefreshDatabase;

    private function makeBookingLead(string $bookingStatus): Lead
    {
        $org = OrgUnit::createNode(['name' => 'Team Y', 'code' => 'team-y']);
        $user = User::factory()->create();

        return Lead::create([
            'name'            => 'Khách test',
            'phone'           => '0900000001',
            'source_group'    => Lead::SOURCE_MKT,
            'pipeline_phase'  => Lead::PHASE_BOOKING,
            'pipeline_status' => Lead::PSTATUS_IN_CARE,
            'booking_status'  => $bookingStatus,
            'owner_id'        => $user->id,
            'org_unit_id'     => $org->id,
            'pool_level'      => Lead::POOL_PERSONAL,
            'received_date'   => now()->toDateString(),
            'imported_by'     => $user->id,
        ]);
    }

    public function test_throws_when_booking_not_booked(): void
    {
        $lead = $this->makeBookingLead(Lead::BOOKING_NOT_BOOKED);

        $this->expectException(\DomainException::class);
        $lead->moveToSaleWaiting();
    }

    public function test_passes_when_booking_booked(): void
    {
        $lead = $this->makeBookingLead(Lead::BOOKING_BOOKED);

        $lead->moveToSaleWaiting();
        $lead->refresh();

        $this->assertSame(Lead::PHASE_SALE, $lead->pipeline_phase);
        $this->assertSame(Lead::PSTATUS_WAITING, $lead->pipeline_status);
        $this->assertNull($lead->owner_id);
    }
}
