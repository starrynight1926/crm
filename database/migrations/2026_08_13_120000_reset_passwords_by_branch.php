<?php

use App\Models\User;
use App\Support\DefaultPassword;
use Illuminate\Database\Migrations\Migration;

/**
 * 2026-08-13: Reset password mặc định theo cơ sở làm việc.
 *
 * Trước đây `DefaultPassword::forEmail` map theo prefix email (`hn.` / `hcm.` / `dn.`),
 * nhưng nhân sự thật HCM/ĐN dùng email surname (`tnkn@`, `hbtl@`, `tbt@`, ...)
 * → rơi vào fallback → cấp nhầm pass HN (`59@ntn`).
 *
 * Migration này reset lại toàn bộ user theo cơ sở qua assignment:
 *   HN → 59@ntn, HCM → 207@nvt, ĐN → l23@tdn, VH/admin → 59ntn.
 *
 * Chỉ ảnh hưởng cột users.password. Không đụng data khác.
 */
return new class extends Migration {
    public function up(): void
    {
        User::query()->with('assignments')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                $user->password = DefaultPassword::forUser($user);
                $user->saveQuietly();
            }
        });
    }

    public function down(): void
    {
        // Không rollback được password đã hash.
    }
};
