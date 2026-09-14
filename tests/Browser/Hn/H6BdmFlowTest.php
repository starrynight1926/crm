<?php

namespace Tests\Browser\Hn;

use Laravel\Dusk\Browser;
use Tests\Browser\Traits\HcmFlowHelpers;
use Tests\DuskTestCase;

/**
 * H6 — Nguồn BDM, CM up.
 * Fix 2026-08-08: next-step giống MKT/MKT_BR.
 */
class H6BdmFlowTest extends DuskTestCase
{
    use HcmFlowHelpers;

    private const PHONE = '0900060006';
    private const NAME = '[TEST-H6-BDM] Khách BDM';
    private const UP_EMAIL = 'hn.cms01@longevity.com.vn';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupTestLeads(substr(self::PHONE, 0, 8));
    }

    public function testH6_BdmNextStep(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAs($browser, self::UP_EMAIL);
            $this->fillLeadForm($browser, self::NAME, self::PHONE, 'bdm');
            $this->assertNextStepBanner($browser, 'Nhập phân loại, nguồn → Tele sale chuẩn bị.');
            $this->assertTruongBoSungAtPhase1($browser);
        });
    }
}
