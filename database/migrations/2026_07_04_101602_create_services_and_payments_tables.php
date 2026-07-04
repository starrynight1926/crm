<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Danh mục dịch vụ (ERD B4)
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 50)->unique();
            $table->string('pricing_type', 20)->default('package'); // package (trọn gói) / per_phase (theo phase)
            $table->unsignedBigInteger('package_price')->nullable(); // VND
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('service_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name');
            $table->unsignedBigInteger('phase_price')->nullable(); // dùng khi per_phase
        });

        // Dịch vụ gắn vào khách
        Schema::create('customer_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agreed_price'); // giá chốt thực tế (override giá niêm yết)
            $table->string('status', 20)->default('active'); // active / completed / cancelled
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // Tiến độ từng phase của khách: ai làm, ngày làm, note bàn giao
        Schema::create('customer_service_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_phase_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending'); // pending / done / skipped
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->text('handover_note')->nullable();

            $table->unique(['customer_service_id', 'service_phase_id'], 'csp_service_phase_unique');
        });

        // Sổ thu tiền — công nợ = agreed_price − Σ payments (tính, không lưu)
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_service_phase_id')->nullable()->constrained('customer_service_phases')->nullOnDelete();
            $table->unsignedBigInteger('amount'); // VND
            $table->string('method', 20)->default('cash'); // cash / transfer / card
            $table->date('paid_at');
            $table->foreignId('collected_by')->constrained('users');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['paid_at']);
            $table->index(['collected_by', 'paid_at']);
        });

        // Mẫu % đóng góp (ERD B5)
        Schema::create('contribution_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('items'); // [{role_label, percent}]
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // % đóng góp khi Close — app enforce Σ = 100 mỗi deal
        Schema::create('contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role_label', 40); // collector / care_1 / care_2 / phase_worker...
            $table->decimal('percent', 5, 2);
            $table->foreignId('set_by')->constrained('users');
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at']);
            $table->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contributions');
        Schema::dropIfExists('contribution_templates');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('customer_service_phases');
        Schema::dropIfExists('customer_services');
        Schema::dropIfExists('service_phases');
        Schema::dropIfExists('services');
    }
};
