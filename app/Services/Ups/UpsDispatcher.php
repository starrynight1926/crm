<?php

namespace App\Services\Ups;

use App\Models\DailyAttendance;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6.25 — Chọn sale kế tiếp từ UPS List theo bucket, round-robin,
 * skip sale is_busy hoặc is_off.
 */
class UpsDispatcher
{
    public const BUCKET_ORDER_GREET = ['A', 'B', 'C', 'OFF'];

    /**
     * Chọn 1 sale từ MKT List của cơ sở hôm nay (round-robin).
     * Return null nếu list rỗng.
     */
    public function pickMkt(int $facilityPoolUnitId, ?string $workDate = null): ?User
    {
        // 2026-09-04 v2: round-robin cross-bucket theo priority A→B→C (OFF loại).
        //   Mỗi người trong bucket nhận 1 lượt trước khi sang bucket sau.
        //   Vòng: A1 → A3 (A2 nếu bận) → A4 → ... → B1 → B2 → ... → C1 → ... → wrap A.
        //   Trước đây strict priority "A độc chiếm khi rảnh" → user báo 11/11 lead về Trâm.
        return $this->pickCrossBucket($facilityPoolUnitId, $workDate);
    }

    /**
     * Round-robin chọn sale từ UPS List hôm nay của cơ sở.
     * 2026-08-14: UPS đã chốt → mọi sale check-in đều là ứng viên chia MKT
     * (loại 'OFF' và sale tự dừng nhận lead). Không cần tick +M riêng nữa.
     */
    public function pickFromMkt(int $facilityPoolUnitId, ?string $workDate = null, bool $includeBusy = false): ?User
    {
        $workDate ??= now()->toDateString();

        return DB::transaction(function () use ($facilityPoolUnitId, $workDate, $includeBusy) {
            $q = DailyAttendance::with('user')
                ->where('facility_pool_unit_id', $facilityPoolUnitId)
                ->whereDate('work_date', $workDate)
                ->where('list_bucket', '!=', 'OFF')
                // B3 (2026-08-14): is_busy = "đang tiếp đón" thông tin, không skip.
                ->where('dung_nhan_lead', false)
                ->orderBy('checkin_at');
            $sales = $q->get()->pluck('user')->filter()->values();

            if ($sales->isEmpty()) return null;

            $bucket = 'MKT';
            $state = DB::table('ups_rr_state')
                ->where('facility_pool_unit_id', $facilityPoolUnitId)
                ->where('work_date', $workDate)
                ->where('bucket', $bucket)
                ->lockForUpdate()
                ->first();

            $lastUserId = $state?->last_user_id;
            $lastIdx = -1;
            if ($lastUserId) {
                foreach ($sales as $i => $s) {
                    if ($s->id === $lastUserId) { $lastIdx = $i; break; }
                }
            }
            $nextIdx = ($lastIdx + 1) % $sales->count();
            $picked = $sales[$nextIdx];

            DB::table('ups_rr_state')->updateOrInsert(
                [
                    'facility_pool_unit_id' => $facilityPoolUnitId,
                    'work_date' => $workDate,
                    'bucket' => $bucket,
                ],
                [
                    'last_user_id' => $picked->id,
                    'updated_at' => now(),
                    'created_at' => $state ? $state->created_at : now(),
                ]
            );

            return $picked;
        });
    }

    /**
     * Chọn 1 sale từ Sale-tiếp-đón (A → B → C → OFF), round-robin trong bucket.
     * Skip sale đang bận.
     *
     * 2026-08-04 fix Bug U4: nếu TẤT CẢ sale trong mọi bucket đều busy → wrap-around,
     * chọn theo round-robin từ A→B→C→OFF bất chấp is_busy (khách 4 vẫn phải có sale
     * dù cả 3 người bận). User quy tắc: "khách 4 về người 1".
     */
    public function pickGreet(int $facilityPoolUnitId, ?string $workDate = null): ?User
    {
        // 2026-09-04 v2: giống pickMkt — round-robin cross-bucket A→B→C.
        //   User (2026-09-04): "chia hết A1..A4 sang B1 tiếp tục", "A2 bận thì skip".
        return $this->pickCrossBucket($facilityPoolUnitId, $workDate);
    }

    /**
     * 2026-09-04 — Round-robin qua toàn bộ sale active theo priority bucket A→B→C.
     * Mỗi call trả sale kế tiếp trong danh sách [A_sorted, B_sorted, C_sorted]
     * (skip OFF, skip dung_nhan_lead=true). Vòng lặp tự nhiên bằng modulo count.
     *
     * State: bảng ups_rr_state, bucket sentinel '__CROSS' để tách khỏi state per-bucket cũ.
     */
    public function pickCrossBucket(int $facilityPoolUnitId, ?string $workDate = null): ?User
    {
        $workDate ??= now()->toDateString();

        return DB::transaction(function () use ($facilityPoolUnitId, $workDate) {
            // Ordered: bucket priority (A=1, B=2, C=3) rồi checkin_at asc.
            $sales = DailyAttendance::with('user')
                ->where('facility_pool_unit_id', $facilityPoolUnitId)
                ->whereDate('work_date', $workDate)
                ->whereIn('list_bucket', self::BUCKET_ORDER_GREET) // A, B, C, OFF theo const
                ->where('list_bucket', '!=', 'OFF')
                ->where('dung_nhan_lead', false)
                ->orderByRaw("FIELD(list_bucket, 'A', 'B', 'C')")
                ->orderBy('checkin_at')
                ->orderBy('id')
                ->get()->pluck('user')->filter()->values();

            if ($sales->isEmpty()) return null;

            $bucket = '__CROSS';
            $state = DB::table('ups_rr_state')
                ->where('facility_pool_unit_id', $facilityPoolUnitId)
                ->where('work_date', $workDate)
                ->where('bucket', $bucket)
                ->lockForUpdate()
                ->first();

            $lastUserId = $state?->last_user_id;
            $lastIdx = -1;
            if ($lastUserId) {
                foreach ($sales as $i => $s) {
                    if ($s->id === $lastUserId) { $lastIdx = $i; break; }
                }
            }
            $nextIdx = ($lastIdx + 1) % $sales->count();
            $picked = $sales[$nextIdx];

            DB::table('ups_rr_state')->updateOrInsert(
                [
                    'facility_pool_unit_id' => $facilityPoolUnitId,
                    'work_date' => $workDate,
                    'bucket' => $bucket,
                ],
                [
                    'last_user_id' => $picked->id,
                    'updated_at' => now(),
                    'created_at' => $state ? $state->created_at : now(),
                ]
            );

            return $picked;
        });
    }

    /**
     * Chọn sale kế tiếp trong 1 bucket theo round-robin.
     * State lưu ở ups_rr_state (last_user_id đã chia gần nhất).
     * Skip sale is_busy=true (trừ khi $includeBusy=true — fallback wrap-around).
     *
     * 2026-08-04 fix Bug U1: SELECT ... FOR UPDATE + transaction để tránh race condition
     * khi 2 request đồng thời gọi pickFromBucket (VD 2 khách check-in cùng lúc) → cả 2
     * cùng đọc last_user_id → cùng chọn sale kế → 1 sale bị chia 2 lead cùng lúc.
     */
    public function pickFromBucket(int $facilityPoolUnitId, string $bucket, ?string $workDate = null, bool $includeBusy = false): ?User
    {
        $workDate ??= now()->toDateString();

        return DB::transaction(function () use ($facilityPoolUnitId, $bucket, $workDate, $includeBusy) {
            $q = DailyAttendance::with('user')
                ->where('facility_pool_unit_id', $facilityPoolUnitId)
                ->whereDate('work_date', $workDate)
                ->where('list_bucket', $bucket)
                // 2026-08-10: dung_nhan_lead luôn loại khỏi vòng chia, kể cả wrap-around.
                // B3 (2026-08-14): is_busy = "đang tiếp đón" (informational), KHÔNG chặn nhận lead nữa.
                //   Chỉ dung_nhan_lead mới skip. $includeBusy giữ lại cho backward-compat nhưng no-op.
                ->where('dung_nhan_lead', false)
                ->orderBy('checkin_at');
            $sales = $q->get()->pluck('user')->filter()->values();

            if ($sales->isEmpty()) return null;

            // Lock row ups_rr_state (hoặc nothing nếu chưa tồn tại — lock table qua updateOrInsert).
            $state = DB::table('ups_rr_state')
                ->where('facility_pool_unit_id', $facilityPoolUnitId)
                ->where('work_date', $workDate)
                ->where('bucket', $bucket)
                ->lockForUpdate()
                ->first();

            $lastUserId = $state?->last_user_id;
            $lastIdx = -1;
            if ($lastUserId) {
                foreach ($sales as $i => $s) {
                    if ($s->id === $lastUserId) { $lastIdx = $i; break; }
                }
            }
            $nextIdx = ($lastIdx + 1) % $sales->count();
            $picked = $sales[$nextIdx];

            DB::table('ups_rr_state')->updateOrInsert(
                [
                    'facility_pool_unit_id' => $facilityPoolUnitId,
                    'work_date' => $workDate,
                    'bucket' => $bucket,
                ],
                [
                    'last_user_id' => $picked->id,
                    'updated_at' => now(),
                    'created_at' => $state ? $state->created_at : now(),
                ]
            );

            return $picked;
        });
    }

    /** Đánh dấu sale bận (tiếp khách) — tự động skip trong pickFromBucket lần sau. */
    public function markBusy(int $userId, ?string $workDate = null): bool
    {
        $workDate ??= now()->toDateString();
        $updated = DailyAttendance::where('user_id', $userId)
            ->whereDate('work_date', $workDate)
            ->update(['is_busy' => true, 'busy_since' => now()]);

        return $updated > 0;
    }

    /** Sale tiếp khách xong → sẵn sàng nhận số tiếp. */
    public function markFree(int $userId, ?string $workDate = null): bool
    {
        $workDate ??= now()->toDateString();
        $updated = DailyAttendance::where('user_id', $userId)
            ->whereDate('work_date', $workDate)
            ->update(['is_busy' => false, 'busy_since' => null]);

        return $updated > 0;
    }

    /** 2026-08-10 — Sale tự tick "Dừng nhận lead" → loại khỏi vòng chia tuyệt đối. */
    public function markPause(int $userId, ?string $workDate = null): bool
    {
        $workDate ??= now()->toDateString();
        $updated = DailyAttendance::where('user_id', $userId)
            ->whereDate('work_date', $workDate)
            ->update(['dung_nhan_lead' => true, 'dung_nhan_lead_since' => now()]);

        return $updated > 0;
    }

    /** Sale bấm "Nhận lead lại" → trở về vòng chia. */
    public function markResume(int $userId, ?string $workDate = null): bool
    {
        $workDate ??= now()->toDateString();
        $updated = DailyAttendance::where('user_id', $userId)
            ->whereDate('work_date', $workDate)
            ->update(['dung_nhan_lead' => false, 'dung_nhan_lead_since' => null]);

        return $updated > 0;
    }
}
