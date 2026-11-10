<?php

namespace Tests\Feature;

use App\Models\BookingLog;
use App\Models\Facility;
use App\Models\Lead;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\SbService;
use App\Models\Service;
use App\Models\User;
use App\Services\SbookingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SbookingClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.booking.api_url' => 'http://sbooking.test/api',
            'services.booking.api_token' => 'test-token-1234567890',
        ]);
    }

    private function makeLead(array $facilityAttrs = ['sbooking_co_so_id' => 3]): array
    {
        $org = OrgUnit::create(['code' => 'root', 'name' => 'Root', 'path' => '/']);
        $facility = Facility::create(array_merge(
            ['name' => 'HN 59 NTN', 'parent_id' => null, 'active' => true, 'booking_co_so_slug' => '59ntn'],
            $facilityAttrs
        ));
        $role = Role::create(['name' => 'test', 'label' => 'Test']);
        $user = User::create([
            'name' => 'Sale', 'email' => 't@t.com', 'password' => bcrypt('x'),
            'org_unit_id' => $org->id, 'role_id' => $role->id,
        ]);

        $lead = Lead::create([
            'name' => 'Nguyen A', 'phone' => '0912345678', 'code' => 'KH-001-MKT',
            'org_unit_id' => $org->id, 'source_group' => 'mkt',
            'imported_by' => $user->id, 'pool_level' => 'personal',
            'received_date' => now()->toDateString(),
        ]);

        return [$lead, $facility, $user];
    }

    public function test_pushes_and_marks_synced_on_success(): void
    {
        [$lead, $facility, $user] = $this->makeLead();
        Http::fake([
            'sbooking.test/api/bookings' => Http::response(['id' => 555, 'ma_booking' => 'BKG-260801-000555', 'khach_hang_id' => 77, 'trang_thai' => 'cho_duyet'], 201),
        ]);

        $bl = BookingLog::create([
            'lead_id' => $lead->id, 'user_id' => $user->id,
            'type' => 'tham_kham', 'status' => 'da_xac_nhan',
            'scheduled_at' => '2026-08-05 14:30:00',
            'facility_id' => $facility->id,
        ]);

        $ok = app(SbookingClient::class)->pushBooking($bl);

        $this->assertTrue($ok);
        $bl->refresh();
        $this->assertSame(555, $bl->sbooking_booking_id);
        $this->assertSame('synced', $bl->sync_status);
        $this->assertNull($bl->sync_error);
        $this->assertNotNull($bl->synced_at);
    }

    public function test_fails_when_facility_not_mapped(): void
    {
        [$lead, $facility, $user] = $this->makeLead(['sbooking_co_so_id' => null]);

        $bl = BookingLog::create([
            'lead_id' => $lead->id, 'user_id' => $user->id,
            'type' => 'tham_kham', 'status' => 'da_xac_nhan',
            'facility_id' => $facility->id,
        ]);

        $ok = app(SbookingClient::class)->pushBooking($bl);

        $this->assertFalse($ok);
        $bl->refresh();
        $this->assertSame('failed', $bl->sync_status);
        $this->assertStringContainsString('sbooking_co_so_id', $bl->sync_error);
    }

    public function test_fails_when_http_error(): void
    {
        [$lead, $facility, $user] = $this->makeLead();
        Http::fake([
            'sbooking.test/api/bookings' => Http::response(['message' => 'boom'], 500),
        ]);

        $bl = BookingLog::create([
            'lead_id' => $lead->id, 'user_id' => $user->id,
            'type' => 'tham_kham', 'status' => 'da_xac_nhan',
            'facility_id' => $facility->id,
        ]);

        $ok = app(SbookingClient::class)->pushBooking($bl);

        $this->assertFalse($ok);
        $bl->refresh();
        $this->assertSame('failed', $bl->sync_status);
        $this->assertStringContainsString('HTTP 500', $bl->sync_error);
    }

    public function test_maps_service_by_name_to_sb_service(): void
    {
        [$lead, $facility, $user] = $this->makeLead();
        $svc = Service::create(['name' => 'Signature Nam', 'code' => 'SIG-M', 'pricing_type' => 'package', 'active' => true]);
        SbService::create([
            'sbooking_id' => 99, 'sbooking_co_so_id' => 3,
            'ten' => 'Signature Nam', 'thoi_gian_phut' => 60,
            'thuoc_nhom' => 'khac', 'la_dich_vu' => true, 'active' => true,
        ]);

        Http::fake([
            'sbooking.test/api/bookings' => Http::response(['id' => 1, 'khach_hang_id' => 1, 'trang_thai' => 'cho_duyet'], 201),
        ]);

        $bl = BookingLog::create([
            'lead_id' => $lead->id, 'user_id' => $user->id,
            'type' => 'dich_vu', 'status' => 'da_xac_nhan',
            'facility_id' => $facility->id, 'service_id' => $svc->id,
        ]);

        app(SbookingClient::class)->pushBooking($bl);

        Http::assertSent(function ($request) {
            return $request['dich_vu_id'] === 99;
        });
    }
}
