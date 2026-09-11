<?php

use App\Models\User;
use App\Support\DefaultPassword;
use Illuminate\Database\Migrations\Migration;

/**
 * Đặt lại password mặc định (dev) theo cơ sở dựa trên prefix email.
 *
 * HN   → 59@ntn   |   HCM → 207@nvt   |   ĐN → 23@tdn   |   VH → 59ntn
 *
 * Chỉ áp cho user có email khớp pattern <cơ_sở>.<vị_trí>NN (đã rename qua
 * RenameUsersToPositionFormatSeeder) + admin.hn/hcm/dn + admin. User import
 * từ sbooking (bs./ktv./dd.) không đụng — chờ owner quyết password riêng.
 */
return new class extends Migration {
    public function up(): void
    {
        User::query()
            ->whereNotNull('email')
            ->orderBy('id')
            ->chunk(200, function ($users) {
                foreach ($users as $u) {
                    $local = strtolower(strstr($u->email, '@', true) ?: $u->email);
                    // Chỉ đụng các nhóm được rename chuẩn — bỏ qua bs./ktv./dd./…
                    if (! preg_match('/^(admin|admin\.hn|admin\.hcm|admin\.dn|hn|hcm|dn|vh)(\..+)?$/', $local)) {
                        continue;
                    }
                    // User cast 'password' => 'hashed' → set plaintext, ORM tự bcrypt.
                    $u->password = DefaultPassword::forEmail($u->email);
                    $u->saveQuietly();
                }
            });
    }

    public function down(): void
    {
        // Không rollback được vì password cũ đã bị đè. Nếu cần rollback, chạy seeder gốc.
    }
};
