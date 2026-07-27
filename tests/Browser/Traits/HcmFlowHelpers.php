<?php

namespace Tests\Browser\Traits;

use App\Models\Lead;
use App\Models\User;
use Laravel\Dusk\Browser;

/**
 * Helpers cho các test luồng HCM E2E: login theo email, cleanup lead test, prefill form.
 */
trait HcmFlowHelpers
{
    protected function loginAs(Browser $browser, string $email, string $password = '123456'): void
    {
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
}
