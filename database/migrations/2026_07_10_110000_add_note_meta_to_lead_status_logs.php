<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ghi chú khách (field='note') mang thêm:
 *  - images: danh sách đường dẫn ảnh đính kèm (đánh giá trước/sau khi dùng dịch vụ).
 *  - is_return: cờ "Khách trở lại" — đếm số lần tick = Tần suất quay lại.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_status_logs', function (Blueprint $table) {
            $table->json('images')->nullable()->after('new_value');
            $table->boolean('is_return')->default(false)->after('images');
            $table->index(['lead_id', 'is_return']);
        });
    }

    public function down(): void
    {
        Schema::table('lead_status_logs', function (Blueprint $table) {
            $table->dropIndex(['lead_id', 'is_return']);
            $table->dropColumn(['images', 'is_return']);
        });
    }
};
