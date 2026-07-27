<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.23 — Preset filter + column cho lead list.
 * Mẫu chia sẻ ở cấp org_unit (VD "GR Daily Report" cấp Công ty), khác report_templates
 * (aggregate) — preset này là list from row-by-row (dùng cho export Excel như Google Sheet).
 *
 * config = [
 *   'filters' => [
 *     'date_field' => 'received_date' | 'booked_at' | 'last_care_at',
 *     'date_range' => 'today' | 'yesterday' | 'this_month' | 'last_month' | 'custom',
 *     'classification' => ['booking','close',...] | null,
 *     'source_group' => ['marketing','referral',...] | null,
 *     'booking_status' => [...] | null,
 *   ],
 *   'columns' => ['stt','received_date','facility','name','phone','birthday','owner','receiver','source_group','note'],
 * ]
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('list_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_unit_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('config');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('org_unit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('list_presets');
    }
};
