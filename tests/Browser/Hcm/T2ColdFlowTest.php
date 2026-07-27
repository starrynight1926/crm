<?php

namespace Tests\Browser\Hcm;

use App\Models\Lead;
use Laravel\Dusk\Browser;
use Tests\Browser\Traits\HcmFlowHelpers;
use Tests\DuskTestCase;

class T2ColdFlowTest extends DuskTestCase
{
    use HcmFlowHelpers;

    private const PHONE = '0900012001';
    private const NAME = 'Khách COLD Test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupTestLeads('090001200');
    }

    public function testT2_1_TrucPageUpLeadCold(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAs($browser, 'test.hcm.trucpage@longevity.com.vn');
            $this->fillLeadForm($browser, self::NAME, self::PHONE, 'data_cold');
            $browser->assertSee('Bước tiếp theo: chia về kho team, chờ CM chia cho nhân viên booking.')
                    ->press('Lưu thông tin')
                    ->pause(2000);
        });

        $lead = $this->findLeadByPhone(self::PHONE);
        $this->assertNotNull($lead);
        $this->assertEquals('data_cold', $lead->source_group);
        $this->assertStringEndsWith('-COLD', $lead->code);
        $this->assertEquals(Lead::PHASE_BOOKING, $lead->pipeline_phase);
    }
}
