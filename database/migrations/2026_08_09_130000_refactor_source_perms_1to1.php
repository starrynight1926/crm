<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-08-09 — Refactor source-up perms từ 5 perm nhóm (trucpage/sale/tele/sa/admin)
 * → 7 perm 1-1 với 7 nguồn (mkt, mkt_br, sa, ba, bdm, bod, wi).
 *
 * Lý do: UI phân quyền hiện tại ghi 5 perm dài, admin không biết role nào up nguồn nào.
 * Sau refactor: 7 checkbox tên nguồn, tick trực tiếp.
 *
 * Migration:
 *   1. Tạo 6 perm mới (sa đã tồn tại).
 *   2. Attach cho role theo mapping cũ:
 *      - source.up.trucpage → source.up.mkt
 *      - source.up.sale     → source.up.mkt_br
 *      - source.up.tele     → source.up.ba
 *      - source.up.admin    → source.up.bdm + bod + wi
 *      - source.up.sa       → keep
 *   3. Xoá 4 perm cũ (trucpage, sale, tele, admin).
 */
return new class extends Migration
{
    private const NEW_PERMS = [
        'source.up.mkt'    => 'Đăng nguồn: MKT (Marketing)',
        'source.up.mkt_br' => 'Đăng nguồn: MKT BR (Marketing BR)',
        'source.up.ba'     => 'Đăng nguồn: BA (Bạn giới thiệu)',
        'source.up.bdm'    => 'Đăng nguồn: BDM',
        'source.up.bod'    => 'Đăng nguồn: BOD',
        'source.up.wi'     => 'Đăng nguồn: WI (Walk-in)',
    ];

    private const OLD_TO_NEW = [
        'source.up.trucpage' => ['source.up.mkt'],
        'source.up.sale'     => ['source.up.mkt_br'],
        'source.up.tele'     => ['source.up.ba'],
        'source.up.admin'    => ['source.up.bdm', 'source.up.bod', 'source.up.wi'],
    ];

    public function up(): void
    {
        // 1. Tạo perm mới nếu chưa có.
        $maxPos = (int) DB::table('permissions')->max('position');
        foreach (self::NEW_PERMS as $key => $label) {
            $exists = DB::table('permissions')->where('key', $key)->exists();
            if (! $exists) {
                DB::table('permissions')->insert([
                    'key' => $key,
                    'label' => $label,
                    'group' => 'source_up',
                    'position' => ++$maxPos,
                ]);
            }
        }
        // Update label cho source.up.sa cho đồng bộ tên.
        DB::table('permissions')->where('key', 'source.up.sa')->update([
            'label' => 'Đăng nguồn: SA (Sale hẹn lại)',
        ]);

        // 2. Attach new perms cho role theo mapping cũ.
        $permIds = DB::table('permissions')->whereIn('key', array_keys(self::NEW_PERMS))->pluck('id', 'key');
        foreach (self::OLD_TO_NEW as $oldKey => $newKeys) {
            $oldId = DB::table('permissions')->where('key', $oldKey)->value('id');
            if (! $oldId) continue;
            $roleIds = DB::table('permission_role')->where('permission_id', $oldId)->pluck('role_id');
            foreach ($roleIds as $roleId) {
                foreach ($newKeys as $newKey) {
                    $newId = $permIds[$newKey] ?? null;
                    if (! $newId) continue;
                    $exists = DB::table('permission_role')
                        ->where('role_id', $roleId)->where('permission_id', $newId)->exists();
                    if (! $exists) {
                        DB::table('permission_role')->insert([
                            'role_id' => $roleId, 'permission_id' => $newId,
                        ]);
                    }
                }
            }
        }

        // 3. Xoá 4 perm cũ (attachments cascade).
        $oldIds = DB::table('permissions')->whereIn('key', array_keys(self::OLD_TO_NEW))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $oldIds)->delete();
        DB::table('permissions')->whereIn('id', $oldIds)->delete();
    }

    public function down(): void
    {
        // Re-tạo 4 perm cũ (không attach ngược — mapping M-N không lossless).
        $maxPos = (int) DB::table('permissions')->max('position');
        foreach (array_keys(self::OLD_TO_NEW) as $key) {
            $exists = DB::table('permissions')->where('key', $key)->exists();
            if (! $exists) {
                DB::table('permissions')->insert([
                    'key' => $key,
                    'label' => 'Legacy source-up perm (' . $key . ')',
                    'group' => 'source_up',
                    'position' => ++$maxPos,
                ]);
            }
        }
        // Xoá 6 perm mới.
        $newIds = DB::table('permissions')->whereIn('key', array_keys(self::NEW_PERMS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $newIds)->delete();
        DB::table('permissions')->whereIn('id', $newIds)->delete();
    }
};
