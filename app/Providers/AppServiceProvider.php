<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 2026-08-02: override config services.booking từ AppSetting (UI /settings/booking-connection)
        // để user sửa token/URL không cần đụng .env. Bọc try/catch — bootstrap có thể chạy trước migration.
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('app_settings')) {
                if ($url = \App\Models\AppSetting::get('booking_url')) {
                    config(['services.booking.url' => $url, 'services.booking.api_url' => rtrim($url, '/') . '/api']);
                }
                if ($token = \App\Models\AppSetting::get('booking_api_token')) {
                    config(['services.booking.api_token' => $token]);
                }
            }
        } catch (\Throwable) {
            // ignore
        }
    }
}
