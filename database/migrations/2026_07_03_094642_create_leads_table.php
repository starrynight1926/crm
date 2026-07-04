<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('raw_lead_id')->nullable(); // truy vết về Postgres, tham chiếu logic không FK
            $table->date('received_date'); // Ngày
            $table->string('page')->nullable();
            $table->string('camp')->nullable();
            $table->text('insight')->nullable();
            $table->string('link', 500)->nullable();
            $table->string('ad_source')->nullable(); // Nguồn quảng cáo
            $table->string('name');
            $table->string('phone', 20)->unique(); // E.164, unique chống trùng
            $table->string('region')->nullable(); // KHU VỰC
            $table->string('classification', 20)->default('new');
            $table->text('status_1')->nullable(); // Ghi nhận tình trạng lần 1
            $table->text('status_2')->nullable(); // Ghi nhận tình trạng lần 2
            $table->text('note')->nullable();
            $table->string('pool_level', 10)->default('common'); // common / team / personal
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete(); // CHIA CHO
            $table->foreignId('receiver_id')->nullable()->constrained('users')->nullOnDelete(); // Người nhận LEAD
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete(); // team đang giữ
            $table->dateTime('assigned_at')->nullable();
            $table->dateTime('last_care_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['org_unit_id', 'classification']);
            $table->index(['owner_id', 'classification']);
            $table->index('received_date');
            $table->index('camp');
            $table->index('ad_source');
            $table->index('pool_level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
