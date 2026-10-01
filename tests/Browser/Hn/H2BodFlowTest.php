<?php

namespace Tests\Browser\Hn;

use App\Models\Lead;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\Browser\Traits\HcmFlowHelpers;
use Tests\DuskTestCase;

/**
 * H2 — Nguồn BOD, sale up trực tiếp (không có perm lead.distribute) → tự nhận.
 * Next-step: "Lead sẽ tự động chia cho BẠN (Trần Huy Kiên)..."
 */
class H2BodFlowTest extends DuskTestCase
{
    use HcmFlowHelpers;

    private const PHONE = '0900020002';
    private const NAME = '[TEST-H2-BOD] Khách BOD';
    private const UP_EMAIL = 'hn.sale03@longevity.com.vn';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupTestLeads(substr(self::PHONE, 0, 8));
    }

    public function testH2_BodSelfAssign(): void
    {
        $sale = User::where('email', self::UP_EMAIL)->firstOrFail();
        $expected = 'Lead sẽ tự động chia cho BẠN (' . $sale->name . '). Không qua duyệt — nhập xong là xong.';

        $this->browse(function (Browser $browser) use ($expected) {
            $this->loginAs($browser, self::UP_EMAIL);
            $this->fillLeadForm($browser, self::NAME, self::PHONE, 'bod');
            $this->assertNextStepBanner($browser, $expected);
            $this->assertTruongBoSungAtPhase1($browser);
        });
    }
}
