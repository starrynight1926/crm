<?php

namespace Tests\Feature\Hcm;

use App\Models\Lead;
use App\Models\LeadStatusLog;
use App\Models\User;
use Tests\TestCase;

/**
 * T9 — POST /api/leads/{code}/booking-event: server-to-server push từ booking.
 * Verify auth qua Bearer token + 3 loại event (status/comment/edit).
 */
class BookingEventEndpointTest extends TestCase
{
    private User $sale;
    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        // Sau bootstrap: chuyển sang MySQL dev DB (có seed HCM).
        config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'lara_scrm']);
        \DB::purge('mysql');
        $this->sale = User::where('email', 'test.hcm.sale1@longevity.com.vn')->firstOrFail();
        // Đảm bảo có api_token.
        if (! $this->sale->api_token) {
            $this->sale->update(['api_token' => bin2hex(random_bytes(24))]);
        }

        // Tạo test lead visible cho sale.
        Lead::where('phone', '0900019999')->forceDelete();
        $this->lead = Lead::create([
            'name' => 'Push Test Lead',
            'phone' => '0900019999',
            'received_date' => now(),
            'classification' => 'new',
            'source_group' => 'bod',
            'pipeline_phase' => Lead::PHASE_SALE,
            'pipeline_status' => Lead::PSTATUS_IN_CARE,
            'booking_status' => Lead::BOOKING_BOOKED,
            'owner_id' => $this->sale->id,
            'org_unit_id' => \App\Models\OrgUnit::where('code', 'team-ashley-sale')->value('id'),
            'code' => 'KH-TEST-PUSH',
        ]);
    }

    protected function tearDown(): void
    {
        $this->lead?->forceDelete();
        parent::tearDown();
    }

    public function testMissingTokenReturns401(): void
    {
        $this->postJson('/api/leads/' . $this->lead->code . '/booking-event', [
            'type' => 'status',
        ])->assertStatus(401);
    }

    public function testInvalidTokenReturns401(): void
    {
        $this->postJson('/api/leads/' . $this->lead->code . '/booking-event',
            ['type' => 'status'],
            ['Authorization' => 'Bearer invalid-token-here']
        )->assertStatus(401);
    }

    public function testStatusPushKhachDaToi(): void
    {
        $this->postJson('/api/leads/' . $this->lead->code . '/booking-event',
            ['type' => 'status', 'trang_thai_khach' => 'da_toi', 'booking_ma' => 'BKG-TEST-001'],
            ['Authorization' => 'Bearer ' . $this->sale->api_token]
        )->assertOk()->assertJson(['ok' => true, 'lead_code' => $this->lead->code]);

        $this->lead->refresh();
        $this->assertEquals(Lead::BOOKING_KHACH_DA_TOI, $this->lead->booking_status);
        $this->assertEquals('BKG-TEST-001', $this->lead->booking_ma);
        $this->assertTrue(LeadStatusLog::where('lead_id', $this->lead->id)->where('field', 'booking_status')->exists());
    }

    public function testStatusPushDaXongPriority(): void
    {
        // trang_thai=da_xong PHẢI thắng trang_thai_khach=da_toi.
        $this->postJson('/api/leads/' . $this->lead->code . '/booking-event',
            ['type' => 'status', 'trang_thai_khach' => 'da_toi', 'trang_thai' => 'da_xong', 'booking_ma' => 'BKG-XONG-001'],
            ['Authorization' => 'Bearer ' . $this->sale->api_token]
        )->assertOk();

        $this->assertEquals(Lead::BOOKING_DA_XONG, $this->lead->fresh()->booking_status);
    }

    public function testStatusPushKhachHuy(): void
    {
        $this->postJson('/api/leads/' . $this->lead->code . '/booking-event',
            ['type' => 'status', 'trang_thai_khach' => 'huy', 'booking_ma' => 'BKG-HUY-001'],
            ['Authorization' => 'Bearer ' . $this->sale->api_token]
        )->assertOk();

        $this->assertEquals(Lead::BOOKING_KHACH_HUY, $this->lead->fresh()->booking_status);
    }

    public function testCommentPushCreatesNote(): void
    {
        $before = $this->lead->booking_status;

        $this->postJson('/api/leads/' . $this->lead->code . '/booking-event',
            ['type' => 'comment', 'booking_ma' => 'BKG-CMT-001', 'comment' => 'Khách hài lòng ★★★★★'],
            ['Authorization' => 'Bearer ' . $this->sale->api_token]
        )->assertOk();

        // booking_status không đổi khi push comment.
        $this->assertEquals($before, $this->lead->fresh()->booking_status);
        // Log note phải chứa nội dung.
        $log = LeadStatusLog::where('lead_id', $this->lead->id)->where('field', 'note')->latest()->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Khách hài lòng', $log->new_value);
        $this->assertStringContainsString('BKG-CMT-001', $log->new_value);
    }

    public function testEditPushCreatesNote(): void
    {
        $this->postJson('/api/leads/' . $this->lead->code . '/booking-event',
            ['type' => 'edit', 'booking_ma' => 'BKG-EDIT-001', 'summary' => 'Đổi giờ 09:00 → 10:30'],
            ['Authorization' => 'Bearer ' . $this->sale->api_token]
        )->assertOk();

        $log = LeadStatusLog::where('lead_id', $this->lead->id)->where('field', 'note')->latest()->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('đổi', mb_strtolower($log->new_value));
    }

    public function testUnknownLeadCodeReturns404(): void
    {
        $this->postJson('/api/leads/KH-NOT-EXIST/booking-event',
            ['type' => 'status'],
            ['Authorization' => 'Bearer ' . $this->sale->api_token]
        )->assertStatus(404);
    }
}
