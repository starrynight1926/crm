<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_upsells', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_member_id')->nullable()->constrained('staff_members')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->decimal('amount', 15, 0)->default(0);
            $table->timestamps();

            $table->index('lead_id');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['upsell_cv1', 'upsell_cv2', 'upsell_cv3', 'upsell_service']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->decimal('upsell_cv1', 15, 0)->nullable()->after('potential_service');
            $table->decimal('upsell_cv2', 15, 0)->nullable()->after('upsell_cv1');
            $table->decimal('upsell_cv3', 15, 0)->nullable()->after('upsell_cv2');
            $table->decimal('upsell_service', 15, 0)->nullable()->after('upsell_cv3');
        });

        Schema::dropIfExists('lead_upsells');
    }
};
