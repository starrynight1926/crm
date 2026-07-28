<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chuyển từ mô hình 6 nguồn (marketing/data_cold/bdm/referral/ctv/walk_in)
 * sang 7 nguồn mới (mkt/mkt_br/bdm/bod/sa/ba/wi).
 *
 * - marketing → mkt
 * - walk_in   → wi
 * - bdm       giữ nguyên
 * - data_cold, referral, ctv → XÓA lead (không map, không còn trong hệ thống)
 *
 * Đồng thời: rename "Team Trực Page" → "Team Nhập Lead" (org_units + role name),
 * và xóa permission `lead.distribute_ctv` (không còn nguồn CTV).
 */
return new class extends Migration
{
    public function up(): void
    {
        // KHÔNG bọc DB::transaction — Schema::table(...->change()) là DDL, MySQL implicit-commit
        // sẽ làm vỡ transaction ("no active transaction").

        // 1) Xóa lead thuộc nguồn không còn trong hệ thống (data_cold / referral / ctv).
        DB::table('leads')->whereIn('source_group', ['data_cold', 'referral', 'ctv'])->delete();

        // 2) Rename mã nguồn: marketing → mkt, walk_in → wi. (bdm giữ nguyên.)
        DB::table('leads')->where('source_group', 'marketing')->update(['source_group' => 'mkt']);
        DB::table('leads')->where('source_group', 'walk_in')->update(['source_group' => 'wi']);

        // 3) Cập nhật comment cột source_group.
        if (Schema::hasColumn('leads', 'source_group')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->string('source_group', 20)->nullable()->comment('mkt|mkt_br|bdm|bod|sa|ba|wi')->change();
            });
        }

        // 4) Rename org_units: Team Trực Page → Team Nhập Lead (+ đổi code).
        $renames = [
            'team-truc-page'     => ['code' => 'team-nhap-lead',     'name' => 'Team Nhập Lead'],
            'team-truc-page-hcm' => ['code' => 'team-nhap-lead-hcm', 'name' => 'Team Nhập Lead'],
            'team-truc-page-dn'  => ['code' => 'team-nhap-lead-dn',  'name' => 'Team Nhập Lead'],
        ];
        foreach ($renames as $oldCode => $new) {
            DB::table('org_units')->where('code', $oldCode)->update($new);
        }

        // 5) Rename role "Team trực page" → "Team nhập lead" (nếu tồn tại).
        DB::table('roles')->where('name', 'Team trực page')->update([
            'name' => 'Team nhập lead',
            'description' => 'Team nhập lead marketing — up lead nguồn MKT / MKT BR / BDM',
        ]);

        // 6) Rename display name của các user tài khoản trực page.
        DB::table('users')->where('name', 'Tài khoản Trực Page cơ sở HN')
            ->update(['name' => 'Tài khoản Nhập Lead cơ sở HN']);
        DB::table('users')->where('name', 'Tài khoản Trực Page cơ sở HCM')
            ->update(['name' => 'Tài khoản Nhập Lead cơ sở HCM']);
        DB::table('users')->where('name', 'Tài khoản Trực Page cơ sở ĐN')
            ->update(['name' => 'Tài khoản Nhập Lead cơ sở ĐN']);
        DB::table('users')->where('name', 'Phạm Trực Page 1')
            ->update(['name' => 'Phạm Nhập Lead 1']);

        // 7) Xóa permission `lead.distribute_ctv` (không còn nguồn CTV để phân bổ).
        $ctvPermId = DB::table('permissions')->where('key', 'lead.distribute_ctv')->value('id');
        if ($ctvPermId) {
            DB::table('permission_role')->where('permission_id', $ctvPermId)->delete();
            DB::table('permissions')->where('id', $ctvPermId)->delete();
        }
    }

    public function down(): void
    {
        // Không rollback: data đã xóa (data_cold/referral/ctv) không thể khôi phục tự động.
        // Nếu cần lùi lại thì restore từ backup.
    }
};
