<?php

namespace Tests\Browser\Hn;

use App\Models\Lead;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\Browser\Traits\HcmFlowHelpers;
use Tests\DuskTestCase;

/**
 * H8 — Regression Phase 6.24: lead nằm trong Kho số (pool_unit_id) với org_unit_id=NULL
 * phải visible với admin/CM/BO có scope đến kho đó. Trước fix 2026-08-08, scope check chỉ
 * lấy org_unit_id → mọi lead MKT do Trực Page up mode "pool" bị vô hình.
 */
class H8PoolVisibilityTest extends DuskTestCase
{
    use HcmFlowHelpers;

    private const PHONE = '0900080008';
    private const NAME = '[TEST-H8-POOL] Kho HN MKT';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupTestLeads(substr(self::PHONE, 0, 8));
    }

    public function testH8_PoolLeadVisibleToAdmin(): void
    {
        $trucPage = User::where('email', 'hn.page01@longevity.com.vn')->firstOrFail();
        // Kho Marketing HN = pool_unit id 3 (org_pool_map: org 2 → pool 3).
        $lead = Lead::create([
            'name' => self::NAME,
            'phone' => self::PHONE,
            'received_date' => now()->toDateString(),
            'source_group' => 'mkt',
            'classification' => 'new',
            'booking_status' => 'not_booked',
            'imported_by' => $trucPage->id,
            'org_unit_id' => null,
            'pool_unit_id' => 3,
            'pool_level' => Lead::POOL_TEAM,
            'pipeline_phase' => 'booking',
        ]);
        $lead->code = 'TEST-H8-' . $lead->id;
        $lead->save();

        $admin = User::where('email', 'admin@longevity.com.vn')->firstOrFail();
        $cm = User::where('email', 'hn.cms01@longevity.com.vn')->firstOrFail();

        $this->assertTrue($lead->isVisibleTo($admin), 'Admin phải thấy lead trong Kho số');
        $this->assertTrue($lead->isVisibleTo($cm), 'CM Hà Nội phải thấy lead trong Kho MKT HN');
        $this->assertTrue(
            Lead::visibleTo($admin)->whereKey($lead->id)->exists(),
            'scopeVisibleTo(admin) phải trả lead pool_unit_id'
        );

        $this->browse(function (Browser $browser) use ($lead) {
            $this->loginAs($browser, 'admin@longevity.com.vn');
            $browser->visit('/leads?phase=1')
                    ->pause(1500)
                    ->assertSee($lead->code);
        });
    }
}
