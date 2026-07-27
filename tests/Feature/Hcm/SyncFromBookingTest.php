<?php

namespace Tests\Feature\Hcm;

use App\Models\AppSetting;
use App\Models\Lead;
use App\Models\LeadStatusLog;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * T6 — Livewire lead-detail syncFromBooking(): gọi API /api/bookings bên booking, cập nhật lead.
 */
class SyncFromBookingTest extends TestCase
{
    private User $sale;
    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'lara_scrm']);
        \DB::purge('mysql');
        $this->sale = User::where('email', 'test.hcm.sale1@longevity.com.vn')->firstOrFail();
        Lead::where('phone', '0900018888')->forceDelete();
        $this->lead = Lead::create([
            'name' => 'Sync Test Lead',
            'phone' => '0900018888',
            'received_date' => now(),
            'classification' => 'new',
            'source_group' => 'referral',
            'pipeline_phase' => Lead::PHASE_SALE,
            'pipeline_status' => Lead::PSTATUS_IN_CARE,
            'booking_status' => Lead::BOOKING_NOT_BOOKED,
            'owner_id' => $this->sale->id,
            'org_unit_id' => \App\Models\OrgUnit::where('code', 'team-ashley-sale')->value('id'),
            'code' => 'KH-TEST-SYNC',
        ]);

        AppSetting::set('booking_url', 'http://127.0.0.1:1995');
        AppSetting::set('booking_api_token', 'demodemodemodemo');
        $this->actingAs($this->sale);
    }

    protected function tearDown(): void
    {
        $this->lead?->forceDelete();
        parent::tearDown();
    }

    public function testSyncFindsBookingAndUpdates(): void
    {
        Http::fake([
            '*/api/bookings*' => Http::response([
                'data' => [[
                    'id' => 99,
                    'ma_booking' => 'BKG-SYNC-001',
                    'trang_thai_khach' => 'da_toi',
                    'trang_thai' => 'da_duyet',
                ]],
                'meta' => ['total' => 1],
            ], 200),
        ]);

        Livewire::test('leads.lead-detail', ['lead' => $this->lead])
            ->call('syncFromBooking')
            ->assertHasNoErrors();

        $this->lead->refresh();
        $this->assertEquals(Lead::BOOKING_KHACH_DA_TOI, $this->lead->booking_status);
        $this->assertEquals('BKG-SYNC-001', $this->lead->booking_ma);
        $this->assertEquals('booking', $this->lead->classification);
        // 3 log: booking_status + note + classification (before was 'new' != 'booking').
        $this->assertGreaterThanOrEqual(3, LeadStatusLog::where('lead_id', $this->lead->id)->count());
    }

    public function testSyncEmptyResultDoesNotChangeState(): void
    {
        Http::fake([
            '*/api/bookings*' => Http::response(['data' => [], 'meta' => ['total' => 0]], 200),
        ]);

        Livewire::test('leads.lead-detail', ['lead' => $this->lead])
            ->call('syncFromBooking');

        $this->assertEquals(Lead::BOOKING_NOT_BOOKED, $this->lead->fresh()->booking_status);
    }

    public function testSyncMapsDaXongPriority(): void
    {
        Http::fake([
            '*/api/bookings*' => Http::response([
                'data' => [[
                    'ma_booking' => 'BKG-XONG-002',
                    'trang_thai_khach' => 'da_toi',
                    'trang_thai' => 'da_xong',
                ]],
            ], 200),
        ]);

        Livewire::test('leads.lead-detail', ['lead' => $this->lead])
            ->call('syncFromBooking');

        $this->assertEquals(Lead::BOOKING_DA_XONG, $this->lead->fresh()->booking_status);
    }
}
