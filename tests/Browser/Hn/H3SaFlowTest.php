<?php

namespace Tests\Browser\Hn;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\Browser\Traits\HcmFlowHelpers;
use Tests\DuskTestCase;

/**
 * H3 — Nguồn SA, sale up trực tiếp → tự nhận.
 */
class H3SaFlowTest extends DuskTestCase
{
    use HcmFlowHelpers;

    private const PHONE = '0900030003';
    private const NAME = '[TEST-H3-SA] Khách SA';
    private const UP_EMAIL = 'hn.sale04@longevity.com.vn';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupTestLeads(substr(self::PHONE, 0, 8));
    }

    public function testH3_SaSelfAssign(): void
    {
        $sale = User::where('email', self::UP_EMAIL)->firstOrFail();
        $expected = 'Lead sẽ tự động chia cho BẠN (' . $sale->name . '). Không qua duyệt — nhập xong là xong.';

        $this->browse(function (Browser $browser) use ($expected) {
            $this->loginAs($browser, self::UP_EMAIL);
            $this->fillLeadForm($browser, self::NAME, self::PHONE, 'sa');
            $this->assertNextStepBanner($browser, $expected);
            $this->assertTruongBoSungAtPhase1($browser);
        });
    }
}
