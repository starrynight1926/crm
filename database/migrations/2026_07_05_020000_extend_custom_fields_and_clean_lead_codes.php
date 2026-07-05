<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trường tùy biến đa cấp + workflow duyệt + mã phân loại nối vào mã KH.
 *
 * - custom_fields: thêm ràng buộc (rules), cờ nối mã (affects_code), trạng thái duyệt.
 * - leads: bỏ type_code/source_code cứng (vai trò chuyển sang classification field
 *   cấu hình được). Mã core cố định = KH-{id}; các đoạn sau do classification sinh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            // Ràng buộc theo kiểu: number {min,max}, text {maxlength}, code {code_kind}
            $table->json('rules')->nullable()->after('options');
            // Trường "mã phân loại" nối giá trị vào mã KH
            $table->boolean('affects_code')->default(false)->after('rules');
            // Workflow duyệt: active (đang áp) / pending (chờ duyệt) / rejected
            $table->string('status', 10)->default('active')->after('affects_code');
            $table->unsignedBigInteger('requested_by')->nullable()->after('status');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('requested_by');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('reject_reason')->nullable()->after('reviewed_at');

            $table->index('status');
            $table->index('affects_code');
        });

        // Trường cũ (Phase 2.5) coi như đã duyệt để không vỡ dữ liệu hiện có
        DB::table('custom_fields')->update(['status' => 'active']);

        // Dọn mã cứng khỏi leads — vai trò do classification field đảm nhiệm
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['type_code', 'source_code']);
        });

        // source_connections không còn gán loại data cứng cho lead vào
        if (Schema::hasColumn('source_connections', 'default_type_code')) {
            Schema::table('source_connections', function (Blueprint $table) {
                $table->dropColumn('default_type_code');
            });
        }
    }

    public function down(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->dropColumn(['rules', 'affects_code', 'status', 'requested_by', 'reviewed_by', 'reviewed_at', 'reject_reason']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->string('type_code', 10)->default('N')->after('code');
            $table->string('source_code', 10)->nullable()->after('type_code');
        });

        Schema::table('source_connections', function (Blueprint $table) {
            $table->string('default_type_code', 10)->nullable();
        });
    }
};
