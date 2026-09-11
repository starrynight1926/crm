<?php

namespace Tests\Browser\Hcm;

use App\Models\Lead;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\Browser\Traits\HcmFlowHelpers;
use Tests\DuskTestCase;

/**
 * T1 — Marketing full flow: gom hết step vào 1 method để data flow xuyên suốt.
 */
class T1MktFlowTest extends DuskTestCase
{
    use HcmFlowHelpers;

    private const PHONE = '0900011001';
    private const NAME = 'Khách MKT Test 1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupTestLeads('090001100');
    }

    public function testT1_MktFullFlow(): void
    {
        // ─── T1.1: Team Nhập Lead up lead ─────────────────────────
        $this->browse(function (Browser $browser) {
            $this->loginAs($browser, 'test.hcm.trucpage@longevity.com.vn');
            $this->fillLeadForm($browser, self::NAME, self::PHONE, 'mkt');
            $browser->assertSee('Bước tiếp theo: chia về kho team, chờ CM chia cho nhân viên booking.')
                    ->press('Lưu thông tin')
                    ->pause(2000);
        });

        $lead = $this->findLeadByPhone(self::PHONE);
        $this->assertNotNull($lead, 'T1.1: lead phải được tạo');
        $this->assertEquals('mkt', $lead->source_group);
        $this->assertEquals(Lead::PHASE_BOOKING, $lead->pipeline_phase);
        $this->assertEquals(Lead::PSTATUS_WAITING, $lead->pipeline_status);
        $this->assertStringEndsWith('-MKT', $lead->code);
        $this->assertNull($lead->owner_id);

        // ─── T1.2: Simulate CM booking chia cho booking1 qua DB ───
        // (UI chia số có logic phức tạp — trực tiếp update state để test flow tiếp theo.)
        $booking1 = User::where('email', 'test.hcm.booking1@longevity.com.vn')->firstOrFail();
        $leadOrg = \App\Models\OrgUnit::where('code', 'team-ashley-booking')->value('id');
        $lead->update(['owner_id' => $booking1->id, 'org_unit_id' => $leadOrg, 'pool_level' => Lead::POOL_PERSONAL, 'assigned_at' => now()]);
        $this->assertEquals($booking1->id, $lead->fresh()->owner_id);

        // ─── T1.3: Team booking1 vào chi tiết → thấy readonly + nút Đặt booking ──
        $this->browse(function (Browser $browser) use ($lead) {
            $this->loginAs($browser, 'test.hcm.booking1@longevity.com.vn');
            $browser->visit('/leads/' . $lead->id)
                    ->waitForText('Đặt booking', 10)
                    ->assertSee('Đặt booking');
            // Verify canBookAction đúng qua model
        });
        $lead->refresh();
        $this->assertTrue($lead->canBookAction($booking1), 'Team booking1 có perm book_action');
        $this->assertFalse($lead->canEditPersonalInfo($booking1), 'Team booking1 KHÔNG có update_booking → không sửa được');
    }
}
