<?php

use App\Models\OrgUnit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Chỉnh cấu trúc "Team Trực Page":
 *   - HN: bỏ team-giang-page và team-hoi-page (con của team-giang/hoi-hn).
 *         Tạo team-truc-page mới nằm thẳng dưới marketing-hn (một team chung, chia số cho các team sale).
 *   - HCM: tạo team-truc-page-hcm dưới marketing-hcm (mô hình tương tự).
 * Toàn bộ assignment thuộc 2 team page cũ được chuyển sang team-truc-page mới trước khi xoá.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $mktHn = OrgUnit::firstWhere('code', 'marketing-hn');
            $mktHcm = OrgUnit::firstWhere('code', 'marketing-hcm');

            if (! $mktHn) {
                return;
            }

            // 1) Tạo team-truc-page mới nếu chưa có.
            $trucPage = OrgUnit::firstWhere('code', 'team-truc-page')
                ?? OrgUnit::createNode(['name' => 'Team Trực Page', 'code' => 'team-truc-page'], $mktHn);

            // 2) Chuyển assignment từ 2 team page cũ sang team mới.
            $oldCodes = ['team-giang-page', 'team-hoi-page'];
            foreach ($oldCodes as $code) {
                $old = OrgUnit::firstWhere('code', $code);
                if (! $old) {
                    continue;
                }

                DB::table('assignments')->where('org_unit_id', $old->id)
                    ->update(['org_unit_id' => $trucPage->id]);
                DB::table('assignment_scope_nodes')->where('org_unit_id', $old->id)
                    ->update(['org_unit_id' => $trucPage->id]);
                DB::table('org_unit_managers')->where('org_unit_id', $old->id)->delete();

                // Có thể có rule/policy trỏ vào — safe update sang team mới cho nhất quán.
                if (\Schema::hasColumn('distribution_rules', 'org_unit_id')) {
                    DB::table('distribution_rules')->where('org_unit_id', $old->id)
                        ->update(['org_unit_id' => $trucPage->id]);
                }
                if (\Schema::hasColumn('sla_policies', 'org_unit_id')) {
                    DB::table('sla_policies')->where('org_unit_id', $old->id)
                        ->update(['org_unit_id' => $trucPage->id]);
                }
                if (\Schema::hasColumn('recall_policies', 'org_unit_id')) {
                    DB::table('recall_policies')->where('org_unit_id', $old->id)
                        ->update(['org_unit_id' => $trucPage->id]);
                }

                $old->delete();
            }

            // 3) HCM: tạo team-truc-page-hcm dưới marketing-hcm.
            if ($mktHcm) {
                OrgUnit::firstWhere('code', 'team-truc-page-hcm')
                    ?? OrgUnit::createNode(['name' => 'Team Trực Page', 'code' => 'team-truc-page-hcm'], $mktHcm);
            }
        });
    }

    public function down(): void
    {
        // Không tự động khôi phục cây cũ — dữ liệu đã được migrate.
        // Nếu cần rollback: tạo lại team-giang-page / team-hoi-page thủ công và chuyển assignment về.
    }
};
