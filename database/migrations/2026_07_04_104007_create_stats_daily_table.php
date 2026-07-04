<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Aggregate tính sẵn (ERD B7): job chạy 2 phút/lần cho hôm nay, chốt cứng qua đêm.
        // 1 dòng = 1 tổ hợp chiều có dữ liệu. Báo cáo tháng = Σ ngày.
        Schema::create('stats_daily', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('org_unit_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete(); // owner (funnel) / người thu (revenue)
            $table->string('camp')->nullable();
            $table->string('ad_source')->nullable();
            // Counters funnel (theo classification hiện tại của lead nhận trong ngày)
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('lead')->default(0);
            $table->unsignedInteger('follow')->default(0);
            $table->unsignedInteger('net')->default(0);
            $table->unsignedInteger('booking')->default(0);
            $table->unsignedInteger('show')->default(0);
            $table->unsignedInteger('close')->default(0);
            $table->unsignedBigInteger('revenue_collected')->default(0); // Σ payments theo paid_at

            $table->unique(['date', 'org_unit_id', 'user_id', 'camp', 'ad_source'], 'stats_daily_dims_unique');
            $table->index(['date', 'user_id']);
            $table->index(['date', 'org_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stats_daily');
    }
};
