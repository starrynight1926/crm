<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.21h (2026-07-30) — tách 2 danh mục:
 *   - Thăm khám (khám / xét nghiệm / tư vấn): 9 mục.
 *   - Dịch vụ (gói / therapy / liệu trình khách MUA): 40 mục.
 *
 * Thay đổi:
 *   1. services.service_type enum('tham_kham','dich_vu') default 'dich_vu'
 *   2. booking_logs.type varchar(20) nullable (KHÔNG default — bắt user chọn)
 *   3. Seed 9+40 mục vào services (idempotent).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('services', 'service_type')) {
            Schema::table('services', function (Blueprint $table) {
                $table->string('service_type', 20)->default('dich_vu')->after('name')
                    ->comment('tham_kham | dich_vu');
                $table->index('service_type');
            });
        }

        if (! Schema::hasColumn('booking_logs', 'type')) {
            Schema::table('booking_logs', function (Blueprint $table) {
                $table->string('type', 20)->nullable()->after('status')
                    ->comment('tham_kham | dich_vu — user chọn khi tạo booking');
                $table->index('type');
            });
        }

        // Backfill: services cũ tạm gán dich_vu (không phá data cũ)
        DB::table('services')->update(['service_type' => 'dich_vu']);

        // Seed danh mục Thăm khám (9 mục)
        $thamKham = [
            'Thăm khám lâm sàng (trừ tim mạch)',
            'Thăm khám tim mạch',
            'Thực hiện lâm sàng',
            'Siêu âm',
            'Chụp XQuang',
            'Lấy máu',
            'Đọc kết quả Gene',
            'Tư vấn - đọc kết quả',
            'Tư vấn',
        ];
        foreach ($thamKham as $name) {
            DB::table('services')->updateOrInsert(
                ['name' => $name],
                [
                    'code' => 'TK-' . substr(md5($name), 0, 8),
                    'service_type' => 'tham_kham',
                    'pricing_type' => 'package',
                    'active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        // Seed danh mục Sử dụng dịch vụ (40 mục)
        $dichVu = [
            'Gói khám sức khỏe chuyên sâu Signature nam',
            'Gói khám sức khỏe chuyên sâu Signature nữ',
            'Gói khám sức khỏe định kỳ Diamond Nam',
            'Gói khám sức khỏe định kỳ Diamond Nữ',
            'Gói khám sức khỏe Excutive Health Check Nam (Doanh nghiệp)',
            'Gói khám sức khỏe Excutive Health Check Nữ (Doanh nghiệp)',
            'Gói khám sức khỏe tổng quát',
            'Gói khám sức khỏe chuyên sâu về Cơ xương khớp',
            'Gói khám sức khỏe chuyên sâu về Tim mạch & đột quỵ',
            'Gói khám sức khỏe chuyên sâu về Gan',
            'Gói khám sức khỏe chuyên sâu về Tiểu đường',
            'Gói khám sức khỏe chuyên sâu về Tuyến giáp',
            'Gói khám sức khỏe chuyên sâu về Rối loạn chuyển hóa',
            'Gói khám VVIP Nữ',
            'Gói khám VVIP Nam',
            'Gói khám xét nghiệm và siêu âm tổng quát',
            'Gene2 me Plus',
            'Gene2 me',
            'TruAge',
            'Gene2 + Gene2 Plus + TruAge',
            'Return TruAge',
            'Thủy châm (1 vùng)',
            'BJR (1 vùng)',
            'HA 1%/khớp',
            'HA 2%/khớp',
            'PRP/khớp',
            'Y học Phương Đông',
            'DeepOxy & DetoxCell (xông)',
            'DeepOxy & DetoxCell (tổng hợp)',
            'STC Japan',
            'NK',
            'Recells',
            'Metaboost',
            'MesoF',
            'Thải độc (ILR)',
            'Miễn dịch (MAT)',
            'EAQ (1 vùng)',
            'HA 1%/khớp (Sản phẩm)',
            'HA 2%/khớp (Sản phẩm)',
            'PRP/khớp (Sản phẩm)',
        ];
        foreach ($dichVu as $name) {
            DB::table('services')->updateOrInsert(
                ['name' => $name],
                [
                    'code' => 'DV-' . substr(md5($name), 0, 8),
                    'service_type' => 'dich_vu',
                    'pricing_type' => 'package',
                    'active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex(['service_type']);
            $table->dropColumn('service_type');
        });
        Schema::table('booking_logs', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};
