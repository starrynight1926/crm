<?php

namespace Tests\Browser\Hn;

use App\Models\Lead;
use Laravel\Dusk\Browser;
use Tests\Browser\Traits\HcmFlowHelpers;
use Tests\DuskTestCase;

/**
 * H1 — Nguồn MKT (Trực Page cơ sở HN) up lead.
 * Fix 2026-08-08: next-step banner đổi thành "Nhập phân loại, nguồn → Tele sale chuẩn bị."
 * và panel "Trường bổ sung" phải hiển thị ngay ở phase 1.
 */
class H1MktFlowTest extends DuskTestCase
{
    use HcmFlowHelpers;

    private const PHONE = '0900010001';
    private const NAME = '[TEST-H1-MKT] Khách MKT';
    private const UP_EMAIL = 'hn.page01@longevity.com.vn';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupTestLeads(substr(self::PHONE, 0, 8));
    }

    public function testH1_MktNextStepAndTruongBoSung(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAs($browser, self::UP_EMAIL);
            $this->fillLeadForm($browser, self::NAME, self::PHONE, 'mkt');
            $this->assertNextStepBanner($browser, 'Nhập phân loại, nguồn → Tele sale chuẩn bị.');
            $this->assertTruongBoSungAtPhase1($browser);
        });
    }
}
