<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\DistributionEngine;
use Illuminate\Console\Command;

/**
 * B1d (2026-08-14) — Thu hồi lead nguồn MKT theo state machine:
 *   Gán sale → 1d để ghi cuộc gọi.
 *   Ghi cuộc gọi → 3d để tiến triển phân loại.
 *   Tạo booking → 30d để lịch tiến triển.
 *
 * Chỉ áp nguồn MKT. Các nguồn khác dùng ProcessLeadRecalls hiện có.
 * Thu hồi = owner_id → null (về kho); KHÔNG xóa thông tin/call log/booking log.
 */
class ProcessMktRecalls extends Command
{
    protected $signature = 'leads:process-mkt-recalls';

    protected $description = 'Thu hồi lead MKT có mkt_recall_at <= now (đặt owner về kho, giữ nguyên thông tin).';

    public function handle(DistributionEngine $engine): int
    {
        $recalled = 0;

        Lead::query()
            ->where('source_group', Lead::SOURCE_MKT)
            ->whereNotNull('owner_id')
            ->whereNotNull('mkt_recall_at')
            ->where('mkt_recall_at', '<=', now())
            ->chunkById(200, function ($leads) use ($engine, &$recalled) {
                foreach ($leads as $lead) {
                    $engine->recall($lead, Lead::POOL_TEAM, null);
                    $recalled++;
                }
            });

        $this->info("MKT recall: đã thu hồi {$recalled} lead về kho.");
        return self::SUCCESS;
    }
}
