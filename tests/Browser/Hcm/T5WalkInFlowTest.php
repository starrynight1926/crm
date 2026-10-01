<?php

namespace Tests\Browser\Hcm;

use App\Models\Lead;
use Laravel\Dusk\Browser;
use Tests\Browser\Traits\HcmFlowHelpers;
use Tests\DuskTestCase;

/**
 * T5 — Nguồn Khách tự đến (Walk-in): Sale up, vào kho team-ashley-sale, chờ CM sale chia.
 */
class T5WalkInFlowTest extends DuskTestCase
{
    use HcmFlowHelpers;

    private const PHONE = '0900015001';
    private const NAME = 'Khách WI Test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupTestLeads('090001500');
    }

    public function testT5_1_SaleUpWalkIn(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAs($browser, 'test.hcm.sale2@longevity.com.vn');
            $this->fillLeadForm($browser, self::NAME, self::PHONE, 'wi');
            $browser->assertSee('Bước tiếp theo: chia về kho team, chờ CM team sale chia.')
                    ->press('Lưu thông tin')
                    ->pause(2000);
        });

        $lead = $this->findLeadByPhone(self::PHONE);
        $this->assertNotNull($lead);
        $this->assertEquals('wi', $lead->source_group);
        $this->assertStringEndsWith('-WI', $lead->code);
        $this->assertEquals(Lead::PHASE_SALE, $lead->pipeline_phase, 'WI vào phase Sale');
        $this->assertNull($lead->owner_id, 'WI chưa có owner, chờ CM sale chia');
        // Phase 6.22 — WI auto vào kho team của sale (team-ashley-sale).
        $this->assertEquals(Lead::POOL_TEAM, $lead->pool_level);
        $this->assertEquals(
            \App\Models\OrgUnit::where('code', 'team-ashley-sale')->value('id'),
            $lead->org_unit_id
        );
    }
}
