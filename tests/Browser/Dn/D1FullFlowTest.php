<?php

namespace Tests\Browser\Dn;

use App\Models\Lead;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\Browser\Traits\HcmFlowHelpers;
use Tests\DuskTestCase;

/**
 * D1 — DN đặc thù: Team sale ĐN làm cả tele + book + sale (union quyền).
 * Test: sale ĐN có thể up tất cả nguồn team trực page KHÔNG được (role vẫn là "Team sale ĐN"
 * không có distribute_booking). Nhưng sale có book_action và update_booking (union quyền
 * bên booking side).
 */
class D1FullFlowTest extends DuskTestCase
{
    use HcmFlowHelpers;

    private const PHONE_REF = '0900031001';
    private const PHONE_WI  = '0900031002';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupTestLeads('090003100');
    }

    public function testD1_DnSaleUpBodAndBookRights(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAs($browser, 'test.dn.sale1@longevity.com.vn');
            $this->fillLeadForm($browser, 'Khách DN REF', self::PHONE_REF, 'bod');
            $browser->assertSee('Test DN Sale 1')
                    ->press('Lưu thông tin')
                    ->pause(2000);
        });

        $lead = $this->findLeadByPhone(self::PHONE_REF);
        $sale = User::where('email', 'test.dn.sale1@longevity.com.vn')->first();
        $this->assertNotNull($lead);
        $this->assertEquals($sale->id, $lead->owner_id);
        $this->assertEquals(Lead::PHASE_SALE, $lead->pipeline_phase);
        // Team sale ĐN có book_action perm trực tiếp (không cần override).
        $this->assertTrue($sale->hasPermission('lead.book_action'), 'Team sale ĐN có book_action');
        $this->assertTrue($sale->hasPermission('lead.update_booking'), 'Team sale ĐN có update_booking');
        $this->assertTrue($sale->hasPermission('lead.update_sale'), 'Team sale ĐN có update_sale (full flow)');
        $this->assertTrue($lead->canBookAction($sale));
    }

    public function testD1_DnSaleUpWalkInGoesToTeamPool(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAs($browser, 'test.dn.sale2@longevity.com.vn');
            $this->fillLeadForm($browser, 'Khách DN WI', self::PHONE_WI, 'wi');
            $browser->press('Lưu thông tin')->pause(2000);
        });

        $lead = $this->findLeadByPhone(self::PHONE_WI);
        $this->assertNotNull($lead);
        $this->assertEquals('wi', $lead->source_group);
        // Phase 6.22 — Sale up WI → auto set org = team của sale, chờ CM team chia.
        $this->assertNull($lead->owner_id, 'WI chưa có owner');
        $this->assertEquals(Lead::POOL_TEAM, $lead->pool_level);
        $this->assertEquals(
            \App\Models\OrgUnit::where('code', 'team-dn-sale')->value('id'),
            $lead->org_unit_id,
            'WI vào kho team của sale (team-dn-sale)'
        );
    }
}
