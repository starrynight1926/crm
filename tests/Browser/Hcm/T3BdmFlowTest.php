<?php

namespace Tests\Browser\Hcm;

use App\Models\Lead;
use Laravel\Dusk\Browser;
use Tests\Browser\Traits\HcmFlowHelpers;
use Tests\DuskTestCase;

class T3BdmFlowTest extends DuskTestCase
{
    use HcmFlowHelpers;

    private const PHONE = '0900013001';
    private const NAME = 'Khách BDM Test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupTestLeads('090001300');
    }

    public function testT3_1_TrucPageUpLeadBdm(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAs($browser, 'test.hcm.trucpage@longevity.com.vn');
            $this->fillLeadForm($browser, self::NAME, self::PHONE, 'bdm');
            $browser->assertSee('Bước tiếp theo: chia về kho team, chờ CM chia cho nhân viên booking.')
                    ->press('Lưu thông tin')
                    ->pause(2000);
        });

        $lead = $this->findLeadByPhone(self::PHONE);
        $this->assertNotNull($lead);
        $this->assertEquals('bdm', $lead->source_group);
        $this->assertStringEndsWith('-BDM', $lead->code);
        $this->assertEquals(Lead::PHASE_BOOKING, $lead->pipeline_phase);
    }
}
