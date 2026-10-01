<?php

namespace Tests\Browser\Hn;

use Laravel\Dusk\Browser;
use Tests\Browser\Traits\HcmFlowHelpers;
use Tests\DuskTestCase;

/**
 * H5 — Nguồn MKT_BR, CM up.
 * Fix 2026-08-08: next-step giống MKT ("Nhập phân loại, nguồn → Tele sale chuẩn bị.")
 */
class H5MktBrFlowTest extends DuskTestCase
{
    use HcmFlowHelpers;

    private const PHONE = '0900050005';
    private const NAME = '[TEST-H5-MKTBR] Khách MKT_BR';
    private const UP_EMAIL = 'hn.cms01@longevity.com.vn';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupTestLeads(substr(self::PHONE, 0, 8));
    }

    public function testH5_MktBrNextStep(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAs($browser, self::UP_EMAIL);
            $this->fillLeadForm($browser, self::NAME, self::PHONE, 'mkt_br');
            $this->assertNextStepBanner($browser, 'Nhập phân loại, nguồn → Tele sale chuẩn bị.');
            $this->assertTruongBoSungAtPhase1($browser);
        });
    }
}
