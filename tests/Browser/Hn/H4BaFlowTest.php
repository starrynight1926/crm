<?php

namespace Tests\Browser\Hn;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\Browser\Traits\HcmFlowHelpers;
use Tests\DuskTestCase;

/**
 * H4 — Nguồn BA, tài khoản booking up trực tiếp → tự nhận.
 */
class H4BaFlowTest extends DuskTestCase
{
    use HcmFlowHelpers;

    private const PHONE = '0900040004';
    private const NAME = '[TEST-H4-BA] Khách BA';
    private const UP_EMAIL = 'hn.book01@longevity.com.vn';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupTestLeads(substr(self::PHONE, 0, 8));
    }

    public function testH4_BaSelfAssign(): void
    {
        $booking = User::where('email', self::UP_EMAIL)->firstOrFail();
        $expected = 'Lead sẽ tự động chia cho BẠN (' . $booking->name . '). Không qua duyệt — nhập xong là xong.';

        $this->browse(function (Browser $browser) use ($expected) {
            $this->loginAs($browser, self::UP_EMAIL);
            $this->fillLeadForm($browser, self::NAME, self::PHONE, 'ba');
            $this->assertNextStepBanner($browser, $expected);
            $this->assertTruongBoSungAtPhase1($browser);
        });
    }
}
