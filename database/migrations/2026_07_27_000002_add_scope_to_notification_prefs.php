<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_prefs', function (Blueprint $table) {
            // scope: 'off' | 'own' | 'team' | 'facility' | 'all'
            // Mặc định 'all' — nếu row tồn tại nghĩa là admin đã bật.
            $table->string('scope', 12)->default('all')->after('event_key');
        });

        // Chuyển dữ liệu cũ: enabled=false → scope='off'; true → giữ 'all'.
        DB::table('notification_prefs')->where('enabled', false)->update(['scope' => 'off']);

        Schema::table('notification_prefs', function (Blueprint $table) {
            $table->dropColumn('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('notification_prefs', function (Blueprint $table) {
            $table->boolean('enabled')->default(true)->after('event_key');
        });
        DB::table('notification_prefs')->where('scope', 'off')->update(['enabled' => false]);
        Schema::table('notification_prefs', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};
