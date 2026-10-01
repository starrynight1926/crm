<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6.21 — Chuyển 2 custom_field `page` + `camp` từ cấp công ty (org null) → cấp phòng Marketing.
 * Nghiệp vụ: page + camp chỉ team Marketing dùng. Seed cho cả 3 cơ sở (HN/HCM/DN) để mỗi phòng có riêng.
 * Backfill: values đang gán cho field cấp công ty → re-map sang field Marketing ancestor của org lead.
 * Lead nào org_unit không thuộc subtree Marketing nào → fallback về Marketing HN.
 */
return new class extends Migration {
    private array $campOptions = [
        'Khoa', 'TBG Nhật', 'tiểu đường', 'tự ib', 'miền Nam', 'TBG Sing', 'XK Nhật',
        'XK 0 tuổi', 'Viên uống', 'website', 'trẻ hóa 0 tuổi', 'tự inbox', 'depoxy',
        'gói khám đột quỵ', 'gói khám tiểu đường', 'gói khám gan', 'gói khám cổ vai gáy',
        'quà tặng', 'TBG nhật-sing',
    ];

    public function up(): void
    {
        $now = now();

        // 1. Lấy id 3 org Marketing (fallback null nếu chưa seed)
        $orgs = DB::table('org_units')->whereIn('code', ['marketing-hn', 'marketing-hcm', 'marketing-dn'])->get()->keyBy('code');
        if ($orgs->isEmpty()) {
            return; // môi trường chưa seed OrgAndRoleSeeder — skip, seed sau
        }

        // 2. Backup lead_custom_values đang gán 2 field cũ (cấp công ty)
        $oldPage = DB::table('custom_fields')->whereNull('org_unit_id')->where('key', 'page')->first();
        $oldCamp = DB::table('custom_fields')->whereNull('org_unit_id')->where('key', 'camp')->first();

        $oldValues = collect();
        if ($oldPage) {
            $oldValues = $oldValues->merge(
                DB::table('lead_custom_values')->where('custom_field_id', $oldPage->id)->get()->map(fn ($r) => (array) $r + ['_key' => 'page'])
            );
        }
        if ($oldCamp) {
            $oldValues = $oldValues->merge(
                DB::table('lead_custom_values')->where('custom_field_id', $oldCamp->id)->get()->map(fn ($r) => (array) $r + ['_key' => 'camp'])
            );
        }

        // 3. Tạo 6 field mới (2 × 3 org), idempotent theo (org, key)
        $fieldIds = []; // [code => [key => id]]
        foreach ($orgs as $code => $org) {
            foreach (['page', 'camp'] as $key) {
                $existing = DB::table('custom_fields')->where('org_unit_id', $org->id)->where('key', $key)->first();
                if ($existing) {
                    $fieldIds[$code][$key] = $existing->id;
                    continue;
                }
                $fieldIds[$code][$key] = DB::table('custom_fields')->insertGetId([
                    'org_unit_id' => $org->id,
                    'key' => $key,
                    'label' => $key === 'page' ? 'PAGE' : 'Camp',
                    'field_type' => $key === 'camp' ? 'select' : 'text',
                    'options' => $key === 'camp' ? json_encode($this->campOptions, JSON_UNESCAPED_UNICODE) : null,
                    'required' => false,
                    'position' => $key === 'page' ? 10 : 11,
                    'active' => true,
                    'status' => 'active',
                    'affects_code' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // 4. Backfill: mỗi value cũ → tìm Marketing ancestor của lead → gán vào field mới
        $orgById = DB::table('org_units')->get()->keyBy('id');
        $marketingCodeByPathPrefix = fn (string $path) => (function () use ($path, $orgs) {
            foreach ($orgs as $code => $org) {
                if (str_contains($path, "/{$org->id}/")) {
                    return $code;
                }
            }
            return 'marketing-hn'; // fallback
        })();

        foreach ($oldValues as $v) {
            $lead = DB::table('leads')->where('id', $v['lead_id'])->first(['id', 'org_unit_id']);
            if (! $lead) continue;

            $orgPath = $lead->org_unit_id
                ? ($orgById[$lead->org_unit_id]?->path ?? '')
                : '';
            $mktCode = $marketingCodeByPathPrefix($orgPath);
            $newFieldId = $fieldIds[$mktCode][$v['_key']];

            DB::table('lead_custom_values')->updateOrInsert(
                ['lead_id' => $v['lead_id'], 'custom_field_id' => $newFieldId],
                ['value' => $v['value']]
            );
        }

        // 5. Xóa 2 field cũ (cấp công ty) + cascade lead_custom_values
        foreach ([$oldPage, $oldCamp] as $old) {
            if ($old) {
                DB::table('lead_custom_values')->where('custom_field_id', $old->id)->delete();
                DB::table('custom_fields')->where('id', $old->id)->delete();
            }
        }
    }

    public function down(): void
    {
        // Rollback: xóa 6 field Marketing, không tự khôi phục field cấp công ty (data test).
        DB::table('custom_fields')
            ->whereIn('org_unit_id', DB::table('org_units')->whereIn('code', ['marketing-hn', 'marketing-hcm', 'marketing-dn'])->pluck('id'))
            ->whereIn('key', ['page', 'camp'])
            ->delete();
    }
};
