<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Thêm imported_by (user_id) vào bảng leads để người nhập file lead luôn
 * thấy được data mình đã nạp — không bị data scope filter mất do được chia
 * cho sale khác.
 *
 * Backfill: các lead từ pipeline import (raw_leads có import_batch_id) →
 * lookup uploaded_by từ import_batches. Match raw ↔ lead qua SĐT (unique
 * hoá qua bảng leads.phone) trong cùng batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('leads', 'imported_by')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->foreignId('imported_by')->nullable()->after('receiver_id')
                    ->constrained('users')->nullOnDelete();
                $table->index('imported_by');
            });
        }

        // Backfill: raw_leads ở connection pgsql (raw zone), không JOIN được với
        // leads ở mysql. Duyệt từng batch → lấy raw_lead.clean_lead_id → update
        // leads.imported_by = batch.uploaded_by.
        $batches = DB::connection('pgsql')->table('import_batches')
            ->whereNotNull('uploaded_by')->get(['id', 'uploaded_by']);
        foreach ($batches as $b) {
            $cleanIds = DB::connection('pgsql')->table('raw_leads')
                ->where('import_batch_id', $b->id)
                ->whereNotNull('clean_lead_id')
                ->pluck('clean_lead_id');
            if ($cleanIds->isEmpty()) continue;
            DB::connection('mysql')->table('leads')
                ->whereIn('id', $cleanIds)
                ->whereNull('imported_by')
                ->update(['imported_by' => $b->uploaded_by]);
        }
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['imported_by']);
            $table->dropIndex(['imported_by']);
            $table->dropColumn('imported_by');
        });
    }
};
