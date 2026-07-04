<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Thu hồi lead quá SLA (chỉ tác dụng khi có policy mode=auto)
Schedule::command('leads:recall-overdue')->everyTenMinutes();

// Aggregate hôm nay mỗi 2 phút (độ tươi dashboard 1–3 phút); chốt cứng hôm qua sau nửa đêm
Schedule::command('stats:aggregate')->everyTwoMinutes();
Schedule::command('stats:aggregate', ['--from' => now()->subDay()->toDateString()])->dailyAt('00:30');

// Kéo lead từ Ads API mỗi 15 phút
Schedule::command('ads:sync')->everyFifteenMinutes();

// Prune audit_logs cũ hơn 12 tháng (chạy hàng đêm)
Schedule::command('audit:prune')->dailyAt('01:00');
