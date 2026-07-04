<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rule chia số 2 cấp (ERD B3)
        Schema::create('distribution_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('priority')->default(0); // khớp rule đầu tiên theo priority tăng dần
            $table->string('level', 20); // pool_to_team (cấp 1) / team_to_user (cấp 2)
            $table->foreignId('org_unit_id')->nullable()->constrained()->cascadeOnDelete(); // rule cấp 2 thuộc team nào
            $table->json('conditions')->nullable(); // {region:[], camp:[], ad_source:[], page:[]}
            $table->string('strategy', 30)->default('round_robin'); // round_robin / weighted / top_revenue / top_close_rate
            $table->json('strategy_config')->nullable(); // metric_window, custom_range...
            $table->timestamps();

            $table->index(['level', 'active', 'priority']);
        });

        Schema::create('rule_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('distribution_rules')->cascadeOnDelete();
            $table->string('target_type', 20); // org_unit / user
            $table->unsignedBigInteger('target_id');
            $table->unsignedSmallInteger('weight')->default(1); // tỉ trọng 5-3-2...
            $table->unsignedSmallInteger('position')->default(0);

            $table->unique(['rule_id', 'target_type', 'target_id']);
        });

        // Con trỏ round-robin / weighted, reset theo chu kỳ (period_key = ngày)
        Schema::create('rule_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('distribution_rules')->cascadeOnDelete();
            $table->unsignedBigInteger('target_id');
            $table->string('period_key', 20);
            $table->unsignedInteger('delivered_count')->default(0);

            $table->unique(['rule_id', 'target_id', 'period_key']);
        });

        // Trần lead 3 cấp: phòng ban / team dùng org_unit, cá nhân dùng user
        Schema::create('lead_caps', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 20); // org_unit / user
            $table->unsignedBigInteger('scope_id');
            $table->unsignedInteger('daily_cap');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['scope_type', 'scope_id']);
        });

        // Sale bật/tắt nhận số
        Schema::create('user_lead_settings', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('receiving')->default(true);
            $table->string('off_reason')->nullable();
            $table->date('off_until')->nullable(); // nghỉ phép đến ngày — qua ngày tự nhận lại
            $table->timestamps();
        });

        // Chính sách thu hồi SLA
        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_unit_id')->nullable()->unique()->constrained()->cascadeOnDelete(); // null = mặc định toàn cty
            $table->string('mode', 10)->default('off'); // auto / manual / off
            $table->unsignedSmallInteger('recall_after_hours')->default(24);
            $table->string('recall_to', 10)->default('team'); // common / team
            $table->timestamps();
        });

        // Log phân bổ
        Schema::create('lead_distribution_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('action', 20); // distribute / recall / pull / manual_assign
            $table->string('from_pool_level', 10)->nullable();
            $table->string('to_pool_level', 10)->nullable();
            $table->foreignId('from_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained('distribution_rules')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete(); // null = hệ thống
            $table->timestamp('created_at');

            $table->index(['lead_id', 'created_at']);
            $table->index(['to_owner_id', 'action', 'created_at']);
            $table->index(['org_unit_id', 'action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_distribution_logs');
        Schema::dropIfExists('sla_policies');
        Schema::dropIfExists('user_lead_settings');
        Schema::dropIfExists('lead_caps');
        Schema::dropIfExists('rule_counters');
        Schema::dropIfExists('rule_targets');
        Schema::dropIfExists('distribution_rules');
    }
};
