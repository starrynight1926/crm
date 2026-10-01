<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demo staging — "quy tắc trường" do người dùng tự tạo: tên + danh sách trường.
 * Dùng làm mẫu khi nhập file (thay bộ config cứng cũ). Reset dễ = truncate.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        Schema::connection($this->connection)->create('demo_field_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // fields = [{label, role(name|phone|source|date|''), required(bool)}]
            $table->jsonb('fields');
            $table->string('created_by', 20)->nullable(); // danh tính demo tạo (nv1/nv2/ql)
            $table->timestampTz('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('demo_field_rules');
    }
};
