<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demo staging — gắn người upload (danh tính demo: nv1 / nv2 / ql) để
 * phân quyền xem theo người: nhân viên chỉ thấy data mình up, quản lý thấy hết.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        Schema::connection($this->connection)->table('demo_raw_leads', function (Blueprint $table) {
            $table->string('uploaded_by', 20)->nullable()->index()->after('source_name');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('demo_raw_leads', function (Blueprint $table) {
            $table->dropColumn('uploaded_by');
        });
    }
};
