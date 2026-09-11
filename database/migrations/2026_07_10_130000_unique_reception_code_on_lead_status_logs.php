<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mã tiếp đón không được trùng. MySQL cho phép nhiều NULL trong unique index nên
 * các ghi chú thường (reception_code = null) không bị ảnh hưởng.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_status_logs', function (Blueprint $table) {
            $table->unique('reception_code');
        });
    }

    public function down(): void
    {
        Schema::table('lead_status_logs', function (Blueprint $table) {
            $table->dropUnique(['reception_code']);
        });
    }
};
