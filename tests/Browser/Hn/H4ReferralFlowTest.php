<?php

namespace Tests\Browser\Hn;

use App\Models\Lead;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\Browser\Traits\HcmFlowHelpers;
use Tests\DuskTestCase;

class H4ReferralFlowTest extends DuskTestCase
{
    use HcmFlowHelpers;

    private const PHONE = '0900024001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupTestLeads('090002400');
    }

    public function testH4_SaleReferralAutoOwner(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAs($browser, 'test.hn.sale1@longevity.com.vn');
            $this->fillLeadForm($browser, 'Khách HN REF', self::PHONE, 'referral');
            $browser->assertSee('Test HN Sale 1')
                    ->press('Lưu thông tin')
                    ->pause(2000);
        });

        $lead = $this->findLeadByPhone(self::PHONE);
        $sale = User::where('email', 'test.hn.sale1@longevity.com.vn')->first();
        $this->assertNotNull($lead);
        $this->assertEquals($sale->id, $lead->owner_id);
        $this->assertEquals(Lead::PHASE_SALE, $lead->pipeline_phase);
        $this->assertTrue($lead->canEditPersonalInfo($sale));
        $this->assertTrue($lead->canBookAction($sale));
    }
}
