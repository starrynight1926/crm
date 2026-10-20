<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.20 — Chuyển `page` và `camp` từ cột core `leads` thành custom_field cấp công ty (org_unit_id=null).
 * 1. Tạo 2 custom_field: key='page' (label PAGE) + key='camp' (label Camp), org_unit null, không bắt buộc.
 * 2. Backfill: mỗi lead có page/camp != null → tạo record trong lead_custom_values.
 * 3. Drop 2 cột `page`, `camp` khỏi `leads`.
 */
return new class extends Migration {
    public function up(): void
    {
        $now = now();

        // 1. Tạo 2 custom_field (idempotent theo unique [org_unit_id, key])
        $pageId = DB::table('custom_fields')->where('org_unit_id', null)->where('key', 'page')->value('id')
            ?? DB::table('custom_fields')->insertGetId([
                'org_unit_id' => null,
                'key' => 'page',
                'label' => 'PAGE',
                'field_type' => 'text',
                'required' => false,
                'position' => 10,
                'active' => true,
                'status' => 'active',
                'affects_code' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $campId = DB::table('custom_fields')->where('org_unit_id', null)->where('key', 'camp')->value('id')
            ?? DB::table('custom_fields')->insertGetId([
                'org_unit_id' => null,
                'key' => 'camp',
                'label' => 'Camp',
                'field_type' => 'text',
                'required' => false,
                'position' => 11,
                'active' => true,
                'status' => 'active',
                'affects_code' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        // 2. Backfill lead_custom_values từ cột cũ
        DB::table('leads')->whereNotNull('page')->orderBy('id')->chunkById(500, function ($rows) use ($pageId) {
            $payload = [];
            foreach ($rows as $r) {
                $payload[] = ['lead_id' => $r->id, 'custom_field_id' => $pageId, 'value' => $r->page];
            }
            if ($payload) {
                DB::table('lead_custom_values')->upsert($payload, ['lead_id', 'custom_field_id'], ['value']);
            }
        });

        DB::table('leads')->whereNotNull('camp')->orderBy('id')->chunkById(500, function ($rows) use ($campId) {
            $payload = [];
            foreach ($rows as $r) {
                $payload[] = ['lead_id' => $r->id, 'custom_field_id' => $campId, 'value' => $r->camp];
            }
            if ($payload) {
                DB::table('lead_custom_values')->upsert($payload, ['lead_id', 'custom_field_id'], ['value']);
            }
        });

        // 3. Drop index trước, rồi drop 2 cột khỏi leads
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['camp']); // leads_camp_index từ migration gốc
            $table->dropColumn(['page', 'camp']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('page')->nullable();
            $table->string('camp')->nullable();
        });

        $pageId = DB::table('custom_fields')->where('org_unit_id', null)->where('key', 'page')->value('id');
        $campId = DB::table('custom_fields')->where('org_unit_id', null)->where('key', 'camp')->value('id');

        if ($pageId) {
            foreach (DB::table('lead_custom_values')->where('custom_field_id', $pageId)->get() as $r) {
                DB::table('leads')->where('id', $r->lead_id)->update(['page' => $r->value]);
            }
            DB::table('lead_custom_values')->where('custom_field_id', $pageId)->delete();
            DB::table('custom_fields')->where('id', $pageId)->delete();
        }
        if ($campId) {
            foreach (DB::table('lead_custom_values')->where('custom_field_id', $campId)->get() as $r) {
                DB::table('leads')->where('id', $r->lead_id)->update(['camp' => $r->value]);
            }
            DB::table('lead_custom_values')->where('custom_field_id', $campId)->delete();
            DB::table('custom_fields')->where('id', $campId)->delete();
        }
    }
};
