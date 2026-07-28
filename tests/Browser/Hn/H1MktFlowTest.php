<?php

namespace Tests\Browser\Hn;

use App\Models\Lead;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\Browser\Traits\HcmFlowHelpers;
use Tests\DuskTestCase;

/**
 * H1 — HN Marketing full flow via team-giang subtree.
 */
class H1MktFlowTest extends DuskTestCase
{
    use HcmFlowHelpers;

    private const PHONE = '0900021001';
    private const NAME = 'Khách HN MKT';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupTestLeads('090002100');
    }

    public function testH1_MktFullFlow(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAs($browser, 'test.hn.trucpage@longevity.com.vn');
            $this->fillLeadForm($browser, self::NAME, self::PHONE, 'mkt');
            $browser->assertSee('Bước tiếp theo: chia về kho team, chờ CM chia cho nhân viên booking.')
                    ->press('Lưu thông tin')
                    ->pause(2000);
        });

        $lead = $this->findLeadByPhone(self::PHONE);
        $this->assertNotNull($lead);
        $this->assertEquals('mkt', $lead->source_group);
        $this->assertStringEndsWith('-MKT', $lead->code);
        $this->assertEquals(Lead::PHASE_BOOKING, $lead->pipeline_phase);
        $this->assertEquals(Lead::PSTATUS_WAITING, $lead->pipeline_status);

        // Simulate CM booking chia + verify readonly + book gate.
        $booking1 = User::where('email', 'test.hn.booking1@longevity.com.vn')->firstOrFail();
        $lead->update([
            'owner_id' => $booking1->id,
            'org_unit_id' => \App\Models\OrgUnit::where('code', 'team-giang-booking')->value('id'),
            'pool_level' => Lead::POOL_PERSONAL,
            'assigned_at' => now(),
        ]);
        $lead->refresh();
        $this->assertTrue($lead->canBookAction($booking1));
        $this->assertFalse($lead->canEditPersonalInfo($booking1), 'Team booking readonly ở phase Booking');
    }
}
