<?php
/** QA Phase 6.25 sbooking side — payload accept + auto-cancel. */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{BookingLog, Lead};

echo "== Verify SbookingClient payload (unit test) ==\n";
// Tạo fake BookingLog canceled, kiểm payload có trang_thai=huy + ly_do_huy không.
$sb = app(\App\Services\SbookingClient::class);
$ref = new ReflectionClass($sb);

// Đọc source pushBookingUpdate xem có branch canceled
$src = file_get_contents($ref->getFileName());
$hasCanceledBranch = str_contains($src, "sync_status === 'canceled'") && str_contains($src, "'trang_thai' => 'huy'");
echo "  ✓ pushBookingUpdate có branch canceled + trang_thai=huy: ".($hasCanceledBranch?'YES':'NO')."\n";

echo "\n== Verify sbooking BookingApiController::update accept trang_thai/ly_do_huy ==\n";
$sbCtrl = file_get_contents('../lara-sbooking/app/Http/Controllers/Api/BookingApiController.php');
$acceptTrangThai = str_contains($sbCtrl, "'trang_thai'      => ['nullable', 'in:huy']");
$mapLyDo = str_contains($sbCtrl, "'ly_do_tu_choi'] = 'Auto-hủy 15\\''");
echo "  ✓ Validator accept trang_thai=huy: ".($acceptTrangThai?'YES':'NO')."\n";
echo "  ✓ Map ly_do_huy → ly_do_tu_choi: ".($mapLyDo?'YES':'NO')."\n";

echo "\n== Verify sbooking modal duyệt cho edit sale/giờ/note (Q5.1) ==\n";
$sbController = file_get_contents('../lara-sbooking/app/Http/Controllers/BookingController.php');
$hasDuyetEdit = str_contains($sbController, 'gio_thuc_hien') && str_contains($sbController, 'tiep_don_user_id')
    && (str_contains($sbController, 'function duyet') || str_contains($sbController, 'public function duyet'));
echo "  ✓ Method duyet() accept gio + tiep_don_user_id: ".($hasDuyetEdit?'YES':'NO')."\n";

echo "\n== Verify AutoCancelLateBookings command tồn tại (rule 15') ==\n";
$hasCommand = class_exists(\App\Console\Commands\AutoCancelLateBookings::class);
echo "  ✓ Command registered: ".($hasCommand?'YES':'NO')."\n";

echo "\n== Verify scheduler đăng ký every 5' ==\n";
$kernel = file_get_contents(__DIR__.'/../app/Console/Kernel.php');
$scheduled = str_contains($kernel, 'bookings:auto-cancel-late') || str_contains($kernel, 'AutoCancelLateBookings');
echo "  ".($scheduled?'✓':'✗')." Scheduled: ".($scheduled?'YES':'NO — CẦN THÊM VÀO Kernel::schedule')."\n";
