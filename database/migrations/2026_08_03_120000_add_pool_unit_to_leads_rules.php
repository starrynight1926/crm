<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.24 (2026-08-03) — Thêm pool_unit_id vào leads / distribution_rules / sla_policies.
 *
 * Data đã wipe ở 6.23 → không cần backfill. Cột org_unit_id giữ nguyên (unused legacy,
 * drop ở phase sau khi UI/engine ổn định).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            $t->foreignId('pool_unit_id')->nullable()->after('org_unit_id')
                ->constrained('pool_units')->nullOnDelete();
            $t->index('pool_unit_id');
        });

        Schema::table('distribution_rules', function (Blueprint $t) {
            $t->foreignId('pool_unit_id')->nullable()->after('org_unit_id')
                ->constrained('pool_units')->nullOnDelete();
        });

        if (Schema::hasTable('sla_policies')) {
            Schema::table('sla_policies', function (Blueprint $t) {
                $t->foreignId('pool_unit_id')->nullable()->after('org_unit_id')
                    ->constrained('pool_units')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            $t->dropForeign(['pool_unit_id']);
            $t->dropColumn('pool_unit_id');
        });
        Schema::table('distribution_rules', function (Blueprint $t) {
            $t->dropForeign(['pool_unit_id']);
            $t->dropColumn('pool_unit_id');
        });
        if (Schema::hasTable('sla_policies')) {
            Schema::table('sla_policies', function (Blueprint $t) {
                $t->dropForeign(['pool_unit_id']);
                $t->dropColumn('pool_unit_id');
            });
        }
    }
};
