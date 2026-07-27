<?php

namespace Tests\Browser\Hcm;

use App\Models\Lead;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\Browser\Traits\HcmFlowHelpers;
use Tests\DuskTestCase;

/**
 * T4 — Nguồn Bạn giới thiệu (REF): Sale up, auto owner=self, override edit.
 */
class T4ReferralFlowTest extends DuskTestCase
{
    use HcmFlowHelpers;

    private const PHONE = '0900014001';
    private const NAME = 'Khách REF Test';
    private const SALE_EMAIL = 'test.hcm.sale1@longevity.com.vn';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupTestLeads('090001400');
    }

    public function testT4_ReferralFullFlow(): void
    {
        // ─── T4.1: Sale up lead REF, hint auto-owner ─────────────
        $this->browse(function (Browser $browser) {
            $this->loginAs($browser, self::SALE_EMAIL);
            $this->fillLeadForm($browser, self::NAME, self::PHONE, 'referral');
            $browser->assertSee('Bước tiếp theo: lead sẽ tự động chia cho')
                    ->assertSee('Test HCM Sale 1')
                    ->press('Lưu thông tin')
                    ->pause(2000);
        });

        $lead = $this->findLeadByPhone(self::PHONE);
        $sale = User::where('email', self::SALE_EMAIL)->first();
        $this->assertNotNull($lead);
        $this->assertEquals('referral', $lead->source_group);
        $this->assertEquals($sale->id, $lead->owner_id, 'REF auto-assign owner');
        $this->assertEquals(Lead::PHASE_SALE, $lead->pipeline_phase);
        $this->assertStringEndsWith('-REF', $lead->code);

        // ─── T4.2: Override edit + book_action cho REF owner ─────
        $this->assertTrue($lead->canEditPersonalInfo($sale),
            'REF + owner phải sửa được info dù role Sale không có update_sale');
        $this->assertTrue($lead->canBookAction($sale),
            'REF + owner phải book_action được dù role Sale không có perm');
    }
}
