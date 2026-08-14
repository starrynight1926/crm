<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Thu hồi lead quá SLA (chỉ tác dụng khi có policy mode=auto)
Schedule::command('leads:recall-overdue')->everyTenMinutes();

// Phase 6.6 — 3 job vòng đời lead
Schedule::command('leads:process-recalls')->dailyAt('00:30');
// B1d (2026-08-14): MKT-specific 3-tier recall (1d/3d/30d).
Schedule::command('leads:process-mkt-recalls')->hourly();
Schedule::command('leads:process-escalates')->dailyAt('02:00');
Schedule::command('leads:mark-overdue-booking')->dailyAt('02:15');

// Miền Nam: recall lead phase Booking idle về kho team booking. Bật/tắt + số ngày cấu hình qua Vận hành › Cài đặt.
Schedule::command('leads:recall-idle-booking-hcm')->dailyAt('02:30');

// 2026-08-04 — Thu hồi theo quy tắc PKD (col 1-3 sau 1 ngày, col 4-5 sau 3 ngày).
// 2026-08-07: mặc định áp mọi lead cá nhân; chỉ skip nếu skip_recall=true (CM tick "Không thu hồi").
Schedule::command('leads:recall-by-columns')->hourly();

// Aggregate hôm nay mỗi 2 phút (độ tươi dashboard 1–3 phút); chốt cứng hôm qua sau nửa đêm
Schedule::command('stats:aggregate')->everyTwoMinutes();
Schedule::command('stats:aggregate', ['--from' => now()->subDay()->toDateString()])->dailyAt('00:30');

// Kéo lead từ Ads API mỗi 15 phút
Schedule::command('ads:sync')->everyFifteenMinutes();

// Prune audit_logs cũ hơn 12 tháng (chạy hàng đêm)
Schedule::command('audit:prune')->dailyAt('01:00');
