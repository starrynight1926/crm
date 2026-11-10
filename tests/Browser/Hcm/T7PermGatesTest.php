<?php

namespace Tests\Browser\Hcm;

use Laravel\Dusk\Browser;
use Tests\Browser\Traits\HcmFlowHelpers;
use Tests\DuskTestCase;

/**
 * T7 — Perm gates: mỗi role thấy đúng nhóm nguồn cho phép chọn (mô hình 7 nguồn).
 */
class T7PermGatesTest extends DuskTestCase
{
    use HcmFlowHelpers;

    /** Team Nhập Lead: Nhóm 1 (mkt/mkt_br/bdm) + Nhóm 2 (bod/sa/ba) + Nhóm 3 (wi) đều enabled. */
    public function testT7_NhapLeadSeesAllSources(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAs($browser, 'test.hcm.trucpage@longevity.com.vn');
            $browser->visit('/leads/create')
                    ->waitFor('select[wire\\:model\\.live="sourceGroup"]', 10);
            $sources = $browser->script("
                return Array.from(document.querySelectorAll('select[wire\\\\:model\\\\.live=\"sourceGroup\"] option')).map(o => ({v:o.value, d:o.disabled}));
            ");
            $map = collect($sources[0])->keyBy('v');
            foreach (['mkt', 'mkt_br', 'bdm', 'bod', 'sa', 'ba', 'wi'] as $sg) {
                $this->assertFalse($map->get($sg)['d'], "Team Nhập Lead thấy nguồn $sg");
            }
        });
    }

    /** Sale (role "Sale"): chỉ Nhóm 2 (bod/sa/ba) + Nhóm 3 (wi) enabled; Nhóm 1 disabled. */
    public function testT7_SaleSeesOnlyDirectSources(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAs($browser, 'test.hcm.sale1@longevity.com.vn');
            $browser->visit('/leads/create')
                    ->waitFor('select[wire\\:model\\.live="sourceGroup"]', 10);
            $sources = $browser->script("
                return Array.from(document.querySelectorAll('select[wire\\\\:model\\\\.live=\"sourceGroup\"] option')).map(o => ({v:o.value, d:o.disabled}));
            ");
            $map = collect($sources[0])->keyBy('v');
            $this->assertTrue($map->get('mkt')['d']);
            $this->assertTrue($map->get('mkt_br')['d']);
            $this->assertTrue($map->get('bdm')['d']);
            foreach (['bod', 'sa', 'ba', 'wi'] as $sg) {
                $this->assertFalse($map->get($sg)['d'], "Sale thấy nguồn $sg");
            }
        });
    }
}
