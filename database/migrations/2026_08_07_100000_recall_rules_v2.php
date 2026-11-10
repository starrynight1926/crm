<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-08-07 — Recall rules v2 (theo Quy tắc PKD Update.docx đã đọc kỹ):
 *   - Cột 1,2,3 = Ngày gọi + Ghi nhận tình trạng + Bước tiếp theo (sale hành động, KHÔNG phải MKT tracking).
 *   - Cột 4,5 = Phân loại + Kết quả.
 *   - Job cũ map sai sang page/camp/phan_loai (MKT tracking) → gỡ.
 *
 * Migration này:
 *   1. Đưa CustomField `phan_loai` + `ket_qua` + `sic` về scope Công ty (org_unit_id=null).
 *      Trước đây bị bind vào 1 team duy nhất (org=8) → hầu hết user không thấy.
 *   2. Rename `leads.recall_by_columns` → `leads.skip_recall` + flip semantic:
 *      - Mặc định thu hồi (skip_recall=false cho mọi lead).
 *      - Tick ô "Không thu hồi" ở form chia số → skip_recall=true (exempt).
 */
return new class extends Migration
{
    public function up(): void
    {
        // (1) Đưa 3 field về công ty toàn bộ.
        DB::table('custom_fields')
            ->whereIn('key', ['phan_loai', 'ket_qua', 'sic'])
            ->update(['org_unit_id' => null, 'updated_at' => now()]);

        // (2) Rename cột. Backfill mặc định false (áp thu hồi cho mọi lead).
        if (Schema::hasColumn('leads', 'recall_by_columns')) {
            Schema::table('leads', function (Blueprint $t) {
                $t->renameColumn('recall_by_columns', 'skip_recall');
            });
            // Backfill: mọi lead skip_recall = false (áp thu hồi mặc định).
            // Lead cũ trước đây tick "áp" (recall_by_columns=true) giờ = "không skip" → hành vi giống cũ.
            // Lead cũ chưa tick (=false) giờ cũng = "không skip" → sẽ bị thu hồi nếu vi phạm; đúng ý user.
            DB::table('leads')->update(['skip_recall' => false]);
        }
    }

    public function down(): void
    {
        // Không revert scope của CustomField (rủi ro data cũ đã dùng ở scope mới).
        if (Schema::hasColumn('leads', 'skip_recall')) {
            Schema::table('leads', function (Blueprint $t) {
                $t->renameColumn('skip_recall', 'recall_by_columns');
            });
        }
    }
};
