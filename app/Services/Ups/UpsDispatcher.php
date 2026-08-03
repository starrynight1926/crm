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
        return $this->pickFromBucket($facilityPoolUnitId, 'MKT', $workDate);
    }

    /**
     * Chọn 1 sale từ Sale-tiếp-đón (A → B → C → OFF), round-robin trong bucket.
     * Skip sale đang bận.
     */
    public function pickGreet(int $facilityPoolUnitId, ?string $workDate = null): ?User
    {
        foreach (self::BUCKET_ORDER_GREET as $bucket) {
            $sale = $this->pickFromBucket($facilityPoolUnitId, $bucket, $workDate);
            if ($sale) {
                return $sale;
            }
        }

        return null;
    }

    /**
     * Chọn sale kế tiếp trong 1 bucket theo round-robin.
     * State lưu ở ups_rr_state (last_user_id đã chia gần nhất).
     * Skip sale is_busy=true.
     */
    public function pickFromBucket(int $facilityPoolUnitId, string $bucket, ?string $workDate = null): ?User
    {
        $workDate ??= now()->toDateString();

        $sales = DailyAttendance::with('user')
            ->where('facility_pool_unit_id', $facilityPoolUnitId)
            ->whereDate('work_date', $workDate)
            ->where('list_bucket', $bucket)
            ->where('is_busy', false)
            ->orderBy('checkin_at')
            ->get()
            ->pluck('user')
            ->filter()
            ->values();

        if ($sales->isEmpty()) {
            return null;
        }

        // Round-robin: tìm index của sale kế sau last_user_id
        $state = DB::table('ups_rr_state')
            ->where('facility_pool_unit_id', $facilityPoolUnitId)
            ->where('work_date', $workDate)
            ->where('bucket', $bucket)
            ->first();

        $lastUserId = $state?->last_user_id;
        $lastIdx = -1;
        if ($lastUserId) {
            foreach ($sales as $i => $s) {
                if ($s->id === $lastUserId) {
                    $lastIdx = $i;
                    break;
                }
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
}
