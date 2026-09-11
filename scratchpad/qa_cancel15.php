<?php
/** E2E rule 15' auto-cancel — local side verify. */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{BookingLog, Lead};
use Illuminate\Support\Facades\Artisan;

$leadPhone = '0900555001';
Lead::where('phone','84900555001')->orWhere('phone',$leadPhone)->delete();

$lead = Lead::create([
    'name'=>'QA Cancel15 '.uniqid(), 'phone'=>$leadPhone,
    'received_date'=>today(), 'classification'=>'new',
    'pool_level'=>Lead::POOL_PERSONAL, 'owner_id'=>27,
    'source_group'=>Lead::SOURCE_MKT, 'pipeline_phase'=>Lead::PHASE_BOOKING,
    'booking_status'=>Lead::BOOKING_BOOKED,
]);
echo "Lead#{$lead->id} tạo, booking_status=booked\n";

$bl = BookingLog::create([
    'lead_id'=>$lead->id, 'user_id'=>27,
    'status'=>BookingLog::STATUS_DA_XAC_NHAN,
    'sync_status'=>'synced',
    'scheduled_at'=>now()->subMinutes(20), // trễ 20' > threshold 15'
    'scheduled_end_at'=>now()->subMinutes(-10),
    'sbooking_booking_id'=>null, // không push (không có sbooking đầu bên kia)
    'sbooking_booking_ma'=>'BKG-QA-'.$lead->id,
    'note'=>'QA test 15\' cancel',
]);
echo "BookingLog#{$bl->id} tạo, scheduled_at={$bl->scheduled_at} (trễ 20')\n";

echo "\n== Chạy bookings:auto-cancel-late ==\n";
$exit = Artisan::call('bookings:auto-cancel-late');
echo Artisan::output()."\n";

$bl->refresh(); $lead->refresh();
echo "== Verify ==\n";
printf("  BookingLog.status:      %s  (expect huy_doi_lich)  %s\n", $bl->status, $bl->status===BookingLog::STATUS_HUY_DOI_LICH?'✅':'❌');
printf("  BookingLog.sync_status: %s  (expect canceled)      %s\n", $bl->sync_status, $bl->sync_status==='canceled'?'✅':'❌');
printf("  BookingLog.sync_error:  %s\n", $bl->sync_error);
printf("  Lead.booking_status:    %s  (expect khach_huy)     %s\n", $lead->booking_status, $lead->booking_status===Lead::BOOKING_KHACH_HUY?'✅':'❌');

echo "\n== LeadStatusLog cuối cùng ==\n";
$log = $lead->statusLogs()->latest()->first();
echo $log ? "  {$log->created_at}: {$log->note}\n" : "  (không có log)\n";

echo "\n== Kiểm payload push (không gọi thật, xem code path) ==\n";
$sb = app(\App\Services\SbookingClient::class);
$ref = new \ReflectionMethod($sb, 'pushBookingUpdate');
echo "  pushBookingUpdate exists: ✅\n";
$src = file_get_contents((new \ReflectionClass($sb))->getFileName());
$hasHuyBranch = preg_match("/sync_status.*canceled.*trang_thai.*huy/s", $src);
echo "  Có branch push trang_thai=huy: ".($hasHuyBranch?'✅':'❌')."\n";
