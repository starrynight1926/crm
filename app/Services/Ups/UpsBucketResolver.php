<?php

namespace App\Services\Ups;

use App\Models\UpsConfig;
use Carbon\CarbonInterface;

/**
 * Bucket resolver cho UPS check-in.
 *
 * Rule tạm (Phase 6.22 — user duyệt 2026-08-03):
 *   - checkin_at ≤ cutoff  → 'A'
 *   - checkin_at > cutoff  → 'OFF'
 *   - checkin_at null      → null (BO chưa check-in → chưa bucket)
 *
 * Tier engine (B/C/MKT) sẽ thay ở phase sau — chỉ đổi thân hàm resolve().
 */
class UpsBucketResolver
{
    public const BUCKET_A = 'A';
    public const BUCKET_OFF = 'OFF';

    public function resolve(
        ?CarbonInterface $checkinAt,
        int $facilityPoolUnitId,
    ): ?string {
        return $this->resolveWithCutoff(
            $checkinAt,
            UpsConfig::cutoffFor($facilityPoolUnitId),
        );
    }

    /** Version thuần logic — inject cutoff, không đụng DB. Dùng cho unit test. */
    public function resolveWithCutoff(?CarbonInterface $checkinAt, string $cutoff): ?string
    {
        if ($checkinAt === null) {
            return null;
        }

        // 2026-08-04 fix Bug U6: normalize cutoff về H:i:s trước khi compare string.
        // "08:36" <= "08:36:00" (string compare) = false → sale check-in đúng cutoff bị đẩy OFF.
        $normalizedCutoff = strlen($cutoff) === 5 ? $cutoff . ':00' : substr($cutoff, 0, 8);
        $localTime = $checkinAt->copy()->setTimezone('Asia/Ho_Chi_Minh')->format('H:i:s');

        return $localTime <= $normalizedCutoff
            ? self::BUCKET_A
            : self::BUCKET_OFF;
    }
}
