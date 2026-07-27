<?php

namespace Tests\Browser\Hcm;

use Laravel\Dusk\Browser;
use Tests\Browser\Traits\HcmFlowHelpers;
use Tests\DuskTestCase;

/**
 * T8 — Login trực tiếp booking system (:1995), verify tài khoản HCM login được.
 * Không đi full flow (đặt lịch có nhiều slot/dropdown phức tạp) — chỉ verify:
 *   - Login OK.
 *   - Cơ sở mặc định = 207nvt (HCM).
 *   - Menu chính có nút "Đặt lịch phòng khám".
 */
class T8BookingSideTest extends DuskTestCase
{
    use HcmFlowHelpers;

    public function testBookingUserCanLogin(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->driver->manage()->deleteAllCookies();
            $browser->visit('http://127.0.0.1:1995/login')
                    ->waitFor('input[name="username"]', 10)
                    ->type('username', 'test.hcm.booking1')
                    ->type('password', '207@nvt')
                    ->press('Đăng nhập')
                    ->pause(2000)
                    // nhan_vien role → home = form tạo mới (không phải trang quản lý).
                    ->assertPathBeginsWith('/207nvt/');
        });
    }

    public function testBookingSaleCanAccessCreateForm(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->driver->manage()->deleteAllCookies();
            $browser->visit('http://127.0.0.1:1995/login')
                    ->waitFor('input[name="username"]', 10)
                    ->type('username', 'test.hcm.sale1')
                    ->type('password', '207@nvt')
                    ->press('Đăng nhập')
                    ->pause(1500)
                    ->visit('http://127.0.0.1:1995/207nvt/tao-moi?ho_ten=Test&so_dien_thoai=0900017001&khach_ma=KH-TEST-BOOK')
                    ->pause(1500)
                    ->assertSee('Đặt lịch')
                    ->assertInputValue('input[name="ho_ten"]', 'Test')
                    ->assertInputValue('input[name="so_dien_thoai"]', '0900017001');
            // Verify hidden khach_ma inserted.
            $khMa = $browser->script("return document.querySelector('input[name=khach_ma]')?.value ?? 'none';")[0];
            $this->assertEquals('KH-TEST-BOOK', $khMa);
        });
    }
}
