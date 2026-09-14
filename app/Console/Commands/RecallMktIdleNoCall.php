<?php

namespace App\Console\Commands;

use App\Models\CallLog;
use App\Models\Lead;
use App\Services\DistributionEngine;
use Illuminate\Console\Command;

/**
 * 2026-09-07 — Thu hồi lead nguồn MKT nếu Tele không ghi cuộc gọi trong 2 phút
 * kể từ khi được UPS chia (assigned_at). Recall về kho cơ sở (POOL_TEAM giữ
 * pool_unit_id) để hệ thống chia lại cho Tele khác.
 *
 * Điều kiện thu hồi:
 *   - source_group = MKT
 *   - owner_id NOT NULL (đã được UPS chia)
 *   - pipeline_phase = booking (chưa tạo booking) — sau booking không recall vì
 *     đã thoát khỏi giai đoạn Tele gọi.
 *   - assigned_at <= now() - 2 phút
 *   - KHÔNG có call_log nào (không phân biệt trạng thái) trong khoảng
 *     [assigned_at, now()].
 */
class RecallMktIdleNoCall extends Command
{
    protected $signature = 'leads:recall-mkt-idle-no-call {--minutes=2 : Số phút chờ trước khi thu hồi}';

    protected $description = 'MKT: Tele không ghi cuộc gọi trong X phút sau khi nhận → thu hồi về kho cơ sở.';

    public function handle(DistributionEngine $engine): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $threshold = now()->subMinutes($minutes);
        $recalled = 0;

        Lead::query()
            ->where('source_group', Lead::SOURCE_MKT)
            ->where('pipeline_phase', Lead::PHASE_BOOKING)
            ->whereNotNull('owner_id')
            ->whereNotNull('assigned_at')
            ->where('assigned_at', '<=', $threshold)
            ->whereDoesntHave('callLogs') // chưa có call_log nào
            ->chunkById(200, function ($leads) use ($engine, &$recalled) {
                foreach ($leads as $lead) {
                    $engine->recall($lead, Lead::POOL_TEAM, null);
                    $recalled++;
                }
            });

        $this->info("MKT idle-no-call recall (≥{$minutes}p): thu hồi {$recalled} lead về kho cơ sở.");
        return self::SUCCESS;
    }
}
