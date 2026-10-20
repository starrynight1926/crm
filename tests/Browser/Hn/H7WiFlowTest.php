<?php

namespace Tests\Browser\Hn;

use Laravel\Dusk\Browser;
use Tests\Browser\Traits\HcmFlowHelpers;
use Tests\DuskTestCase;

/**
 * H7 — Nguồn WI (Walk-in), CM up → về kho team sale.
 */
class H7WiFlowTest extends DuskTestCase
{
    use HcmFlowHelpers;

    private const PHONE = '0900070007';
    private const NAME = '[TEST-H7-WI] Khách WI';
    private const UP_EMAIL = 'hn.cms01@longevity.com.vn';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupTestLeads(substr(self::PHONE, 0, 8));
    }

    public function testH7_WiPoolTeam(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAs($browser, self::UP_EMAIL);
            $this->fillLeadForm($browser, self::NAME, self::PHONE, 'wi');
            $this->assertNextStepBanner($browser, 'Lead sẽ về kho team → chờ CM team sale chia cho nhân viên.');
            $this->assertTruongBoSungAtPhase1($browser);
        });
    }
}
