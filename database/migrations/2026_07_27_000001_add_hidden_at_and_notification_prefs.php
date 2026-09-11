<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable()->after('read_at');
            $table->index(['notifiable_type', 'notifiable_id', 'hidden_at']);
        });

        // Ma trận cấu hình role × event → có nhận thông báo hay không.
        // Vắng dòng = không nhận (mặc định tắt, admin phải bật rõ ràng cho từng role).
        Schema::create('notification_prefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('event_key', 60);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['role_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_prefs');
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['notifiable_type', 'notifiable_id', 'hidden_at']);
            $table->dropColumn('hidden_at');
        });
    }
};
