<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $lastPos = (int) DB::table('permissions')->max('position');
        DB::table('permissions')->updateOrInsert(
            ['key' => 'system.backup'],
            [
                'label' => 'Sao lưu & khôi phục cấu hình / dữ liệu hệ thống',
                'group' => 'system',
                'position' => $lastPos + 1,
            ],
        );
    }

    public function down(): void
    {
        DB::table('permissions')->where('key', 'system.backup')->delete();
    }
};
