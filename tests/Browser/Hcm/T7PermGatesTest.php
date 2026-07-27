<?php

namespace Tests\Browser\Hcm;

use Laravel\Dusk\Browser;
use Tests\Browser\Traits\HcmFlowHelpers;
use Tests\DuskTestCase;

/**
 * T7 — Perm gates: mỗi role thấy đúng nhóm nguồn cho phép chọn.
 */
class T7PermGatesTest extends DuskTestCase
{
    use HcmFlowHelpers;

    /** Trực page: MKT/COLD/BDM/REF/WI enabled, CTV disabled. */
    public function testT7_TrucPageSeesCorrectSources(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAs($browser, 'test.hcm.trucpage@longevity.com.vn');
            $browser->visit('/leads/create')
                    ->waitFor('select[wire\\:model\\.live="sourceGroup"]', 10);
            $sources = $browser->script("
                return Array.from(document.querySelectorAll('select[wire\\\\:model\\\\.live=\"sourceGroup\"] option')).map(o => ({v:o.value, d:o.disabled}));
            ");
            $data = $sources[0];
            $map = collect($data)->keyBy('v');
            $this->assertFalse($map->get('marketing')['d']);
            $this->assertFalse($map->get('data_cold')['d']);
            $this->assertFalse($map->get('bdm')['d']);
            $this->assertFalse($map->get('referral')['d']);
            $this->assertTrue($map->get('ctv')['d'], 'Trực page KHÔNG có distribute_ctv');
            $this->assertFalse($map->get('walk_in')['d']);
        });
    }

    /** Sale (role "Sale"): chỉ REF/WI enabled, MKT/COLD/BDM/CTV disabled. */
    public function testT7_SaleSeesOnlyReferralAndWalkIn(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAs($browser, 'test.hcm.sale1@longevity.com.vn');
            $browser->visit('/leads/create')
                    ->waitFor('select[wire\\:model\\.live="sourceGroup"]', 10);
            $sources = $browser->script("
                return Array.from(document.querySelectorAll('select[wire\\\\:model\\\\.live=\"sourceGroup\"] option')).map(o => ({v:o.value, d:o.disabled}));
            ");
            $map = collect($sources[0])->keyBy('v');
            $this->assertTrue($map->get('marketing')['d']);
            $this->assertTrue($map->get('data_cold')['d']);
            $this->assertTrue($map->get('bdm')['d']);
            $this->assertTrue($map->get('ctv')['d']);
            $this->assertFalse($map->get('referral')['d']);
            $this->assertFalse($map->get('walk_in')['d']);
        });
    }
}
