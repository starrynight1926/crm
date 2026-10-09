<?php
/** E2E HTTP push cancel sang sbooking (real HTTP). */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$token = 'qatoken12345'; // khớp SCRM_API_TOKEN trong ../lara-sbooking/.env
echo "== Setup token 2 bên ==\n";
echo "  Token: $token\n";

$pdoDs = new \PDO('mysql:host=127.0.0.1;dbname=lara-datasource;charset=utf8mb4','root','');
$pdoDs->exec("REPLACE INTO app_settings (`key`,`value`,`created_at`,`updated_at`) VALUES ('booking_api_token', ".$pdoDs->quote($token).", NOW(), NOW())");
echo "  Datasource: booking_api_token SET\n";

// Sbooking token phải encrypt bằng APP_KEY của sbooking. Dùng heredoc + escapeshellarg.
$cmd = ['php', '../lara-sbooking/artisan', 'tinker', '--execute', "App\\Models\\AppSetting::set('scrm_api_token', encrypt('$token'));"];
$proc = proc_open($cmd, [1=>['pipe','w'], 2=>['pipe','w']], $pipes);
$stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);

$check = new \PDO('mysql:host=127.0.0.1;dbname=lara-sbooking;charset=utf8mb4','root','');
$v = $check->query("SELECT value FROM app_settings WHERE `key`='scrm_api_token'")->fetchColumn();
echo "  Sbooking: scrm_api_token ".($v?'SET ('.strlen($v).' chars encrypted)':'MISS')."\n";

// Seed 1 booking bên sbooking (+ khach_hang tối thiểu nếu chưa có)
$pdoSb = new \PDO('mysql:host=127.0.0.1;dbname=lara-sbooking;charset=utf8mb4','root','');
$khId = $pdoSb->query("SELECT id FROM khach_hang LIMIT 1")->fetchColumn();
if (! $khId) {
    $pdoSb->exec("INSERT INTO khach_hang (ho_ten, so_dien_thoai, created_at, updated_at) VALUES ('QA KH', '0900000001', NOW(), NOW())");
    $khId = $pdoSb->lastInsertId();
}
$pdoSb->exec("INSERT INTO booking (ma_booking, co_so_id, loai_dat_lich, khach_hang_id, ngay_dat, gio_thuc_hien, gio_ket_thuc, so_luong, trang_thai, da_duyet, ghi_chu, nguoi_tao_id, created_at, updated_at) VALUES ('BKG-QAE2E-".uniqid()."', 1, 'phong_kham', $khId, CURDATE(), '08:00:00', '08:30:00', 1, 'da_duyet', 1, 'QA E2E push cancel', 1, NOW(), NOW())");
$id = $pdoSb->lastInsertId();
echo "\n== Seed sbooking booking id=$id (trang_thai=da_duyet) ==\n";

echo "\n== Push PUT /api/bookings/$id (trang_thai=huy) ==\n";
$response = Http::withToken($token)->acceptJson()->timeout(10)
    ->put("http://127.0.0.1:8001/api/bookings/$id", [
        'trang_thai' => 'huy',
        'ly_do_huy' => 'QA test khách trễ 20 phút',
    ]);

echo "  HTTP status: {$response->status()}\n";
echo "  Response: ".substr($response->body(),0,300)."\n";

echo "\n== Verify sbooking row updated ==\n";
$row = $pdoSb->query("SELECT trang_thai, ly_do_tu_choi FROM booking WHERE id=$id")->fetch(\PDO::FETCH_ASSOC);
printf("  trang_thai:    %s  %s\n", $row['trang_thai'], $row['trang_thai']==='huy'?'✅':'❌');
printf("  ly_do_tu_choi: %s  %s\n", $row['ly_do_tu_choi']??'(null)', str_contains($row['ly_do_tu_choi']??'', 'Auto-hủy 15')?'✅':'❌');

echo "\n  Cleanup: xoá booking test\n";
$pdoSb->exec("DELETE FROM booking WHERE id=$id");
