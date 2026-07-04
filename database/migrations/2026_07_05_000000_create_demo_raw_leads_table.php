<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demo staging — bảng tạm chứa data upload từ nhiều nguồn.
 * Standalone, không đụng raw_leads thật. Reset = TRUNCATE demo_raw_leads.
 * Chạy trên PostgreSQL (connection pgsql).
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        Schema::connection($this->connection)->create('demo_raw_leads', function (Blueprint $table) {
            $table->id();
            $table->string('batch_id', 40)->index();      // 1 lần upload = 1 batch
            $table->string('source_key', 40)->index();    // key nguồn (nguon_1..)
            $table->string('source_name');                // tên nguồn lúc upload
            $table->jsonb('payload');                      // nguyên dòng (label => value)
            $table->string('ho_ten')->nullable();
            $table->string('so_dien_thoai', 20)->nullable()->index();
            $table->string('nguon')->nullable()->index(); // giá trị cột role=source
            $table->date('ngay')->nullable();
            $table->string('status', 10)->default('valid')->index(); // valid | invalid
            $table->text('error_reason')->nullable();
            $table->unsignedInteger('row_no')->nullable(); // dòng thứ mấy trong file
            $table->timestampTz('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('demo_raw_leads');
    }
};
