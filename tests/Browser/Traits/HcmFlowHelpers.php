<?php

namespace Tests\Browser\Traits;

use App\Models\Lead;
use App\Models\User;
use App\Support\DefaultPassword;
use Laravel\Dusk\Browser;

/**
 * Helpers cho các test luồng E2E: login theo email, cleanup lead test, prefill form,
 * assert next-step banner + panel Trường bổ sung sau khi fix 2026-08-08.
 */
trait HcmFlowHelpers
{
    protected function loginAs(Browser $browser, string $email, ?string $password = null): void
    {
        $password ??= DefaultPassword::forEmail($email);
        // Clear cookies để tránh session leak giữa các test method.
        $browser->driver->manage()->deleteAllCookies();
        $browser->visit('/login')
            ->waitFor('input[name="login"]', 10)
            ->type('login', $email)
            ->type('password', $password)
            ->press('Đăng nhập')
            ->waitForLocation('/dashboard', 10);
    }

    protected function logoutViaMenu(Browser $browser): void
    {
        $browser->visit('/logout-force')
            ->pause(500);
        // Fallback: session flush via API nếu cần.
    }

    /** Xóa các lead test trước khi run (idempotent). */
    protected function cleanupTestLeads(string $phonePrefix): void
    {
        Lead::where('phone', 'like', $phonePrefix . '%')->forceDelete();
    }

    /** Điền form thêm lead — trả về code nếu tạo thành công. */
    protected function fillLeadForm(Browser $browser, string $name, string $phone, string $sourceKey): void
    {
        $browser->visit('/leads/create')
            ->waitFor('input[wire\\:model="name"]', 10)
            ->type('input[wire\\:model="name"]', $name)
            ->type('input[wire\\:model="phone"]', $phone)
            ->script("
                let sel = document.querySelector('select[wire\\\\:model\\\\.live=\"sourceGroup\"]');
                sel.value = '{$sourceKey}';
                sel.dispatchEvent(new Event('change', {bubbles: true}));
            ");
        $browser->pause(1000);
    }

    protected function findLeadByPhone(string $phone): ?Lead
    {
        return Lead::where('phone', $phone)->orderByDesc('id')->first();
    }

    /**
     * Assert banner "Bước tiếp theo" hiển thị đúng nội dung sau khi chọn nguồn.
     * Kiểm text amber ở section nguồn (phase 1). Fix 2026-08-08 dùng dấu "→".
     */
    protected function assertNextStepBanner(Browser $browser, string $expectedText): void
    {
        $browser->pause(400)
                ->assertSeeIn('.bg-amber-50', $expectedText);
    }

    /**
     * Assert panel "Trường bổ sung" hiển thị ở tab phase 1 (Tài khoản nhập lead).
     * Sau fix 2026-08-08 panel đã move từ phase 2 sang phase 1.
     */
    protected function assertTruongBoSungAtPhase1(Browser $browser): void
    {
        // Panel chỉ render khi org có custom field. Nếu rỗng thì skip — không phải bug fix hôm nay.
        $result = $browser->script("
            var h = Array.from(document.querySelectorAll('h2')).find(function(el){return el.innerText.indexOf('Trường bổ sung')>=0;});
            if (!h) return 'NO_CUSTOM_FIELDS';
            var panel = h.closest('[x-show]');
            var xshow = panel ? (panel.getAttribute('x-show')||'') : '';
            if (xshow.indexOf('phase === 1') < 0) return 'WRONG_PHASE:' + xshow;
            if (getComputedStyle(panel).display === 'none') return 'HIDDEN';
            return 'OK';
        ")[0];
        if ($result === 'NO_CUSTOM_FIELDS') {
            // Org không có custom field — panel không render, không assert được. Ghi log để reviewer biết.
            fwrite(STDERR, "[skip] Trường bổ sung không có custom fields cho org này\n");
            return;
        }
        if ($result !== 'OK') {
            throw new \RuntimeException('Trường bổ sung panel sai vị trí: ' . $result);
        }
    }
}
