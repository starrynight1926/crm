<?php

namespace Tests\Feature;

use App\Models\SbService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncServicesFromSbookingTest extends TestCase
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

    public function test_pulls_and_upserts_dich_vu_from_sbooking(): void
    {
        Http::fake([
            'sbooking.test/api/sync/dich-vu' => Http::response([
                'count' => 2,
                'data' => [
                    ['id' => 10, 'co_so_id' => 1, 'ten' => 'Tư vấn', 'thoi_gian_phut' => 30, 'thuoc_nhom' => 'tu_van', 'la_dich_vu' => false, 'active' => true],
                    ['id' => 11, 'co_so_id' => null, 'ten' => 'Signature', 'thoi_gian_phut' => 60, 'thuoc_nhom' => 'khac', 'la_dich_vu' => true, 'active' => true],
                ],
            ], 200),
        ]);

        $this->artisan('sb:sync-services')->assertSuccessful();

        $this->assertDatabaseCount('sb_services', 2);
        $this->assertDatabaseHas('sb_services', [
            'sbooking_id' => 10,
            'sbooking_co_so_id' => 1,
            'ten' => 'Tư vấn',
            'la_dich_vu' => false,
        ]);
        $this->assertDatabaseHas('sb_services', [
            'sbooking_id' => 11,
            'sbooking_co_so_id' => null,
            'ten' => 'Signature',
            'la_dich_vu' => true,
        ]);
    }

    public function test_idempotent_updates_existing_by_sbooking_id(): void
    {
        SbService::create([
            'sbooking_id' => 10,
            'ten' => 'Cũ',
            'thoi_gian_phut' => 15,
            'thuoc_nhom' => 'khac',
            'la_dich_vu' => false,
            'active' => true,
        ]);

        Http::fake([
            'sbooking.test/api/sync/dich-vu' => Http::response([
                'count' => 1,
                'data' => [
                    ['id' => 10, 'co_so_id' => 2, 'ten' => 'Đã đổi tên', 'thoi_gian_phut' => 45, 'thuoc_nhom' => 'tu_van', 'la_dich_vu' => true, 'active' => false],
                ],
            ], 200),
        ]);

        $this->artisan('sb:sync-services')->assertSuccessful();

        $this->assertDatabaseCount('sb_services', 1);
        $this->assertDatabaseHas('sb_services', [
            'sbooking_id' => 10,
            'ten' => 'Đã đổi tên',
            'thoi_gian_phut' => 45,
            'thuoc_nhom' => 'tu_van',
            'la_dich_vu' => true,
            'active' => false,
            'sbooking_co_so_id' => 2,
        ]);
    }

    public function test_fails_when_token_missing(): void
    {
        config(['services.booking.api_token' => null]);

        $this->artisan('sb:sync-services')->assertFailed();
    }

    public function test_fails_on_http_error(): void
    {
        Http::fake([
            'sbooking.test/api/sync/dich-vu' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $this->artisan('sb:sync-services')->assertFailed();
    }
}
