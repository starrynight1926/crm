<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-08-19 — Fix mapping scrm.users.sbooking_user_id cho team Đà Nẵng.
 *
 * Sự cố: admin sbooking bấm Duyệt booking → 422 "Sale không thuộc cơ sở
 *   của booking này". Root cause: SCRM push booking với tiep_don_user_id =
 *   sbooking_user_id sai (trỏ user co_so=1 HN thay vì co_so=3 DN).
 *
 *   Cụ thể:
 *   - Kim Phấn (SCRM #9) sbooking_user_id = 19 (là user dupe cũ ở HN,
 *     giờ đã rename dn.cms01_legacy). Đúng phải là 47.
 *   - Các user DN còn lại (Bông + 7 sale) — sbooking_user_id NULL, chưa
 *     bao giờ map.
 *
 * Migration: đọc bảng users bên sbooking (qua raw query, cross-DB),
 *   match theo name+co_so_id=3, update scrm.users.sbooking_user_id.
 *   Idempotent: chỉ update nếu khác giá trị hiện tại.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sbookingDb = env('SBOOKING_DB_NAME', 'lara-sbooking');
        $mapped = [];
        $skipped = [];

        // Lấy danh sách user ĐN bên sbooking (co_so_id=3) — chỉ non-legacy.
        $sbUsers = DB::select("SELECT id, name FROM `{$sbookingDb}`.users WHERE co_so_id = 3 AND username NOT LIKE '%_legacy'");
        $byName = [];
        foreach ($sbUsers as $r) $byName[trim($r->name)] = (int) $r->id;

        // Match theo name; update mapping bên SCRM.
        foreach (DB::table('users')->get(['id', 'name', 'sbooking_user_id']) as $u) {
            $target = $byName[trim((string) $u->name)] ?? null;
            if (! $target) continue;
            if ((int) $u->sbooking_user_id === $target) continue;

            DB::table('users')->where('id', $u->id)->update([
                'sbooking_user_id' => $target,
                'updated_at' => now(),
            ]);
            $mapped[] = "  {$u->name} (SCRM #{$u->id}): sbooking_user_id " . ($u->sbooking_user_id ?? 'NULL') . " → {$target}";
        }

        if (app()->runningInConsole()) {
            echo "  → Fix DN sbooking mapping: " . count($mapped) . " user updated\n";
            foreach ($mapped as $m) echo $m . "\n";
        }
    }

    public function down(): void
    {
        // No-op — không lưu snapshot cũ. Có thể chạy lại nếu cần.
    }
};
