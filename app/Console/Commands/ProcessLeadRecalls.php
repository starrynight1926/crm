<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadDistributionLog;
use App\Services\DistributionEngine;
use App\Services\RecallPolicyResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('leads:process-recalls')]
#[Description('Thu hồi lead có recall_at <= now VÀ đủ điều kiện (theo recall_conditions của policy). Bỏ qua lead is_permanent_assignment.')]
class ProcessLeadRecalls extends Command
{
    /** Booking status coi là "đã đặt lịch" (không recall theo điều kiện no_booking). */
    private const BOOKED_STATUSES = [
        Lead::BOOKING_BOOKED,
        Lead::BOOKING_KHACH_DA_TOI,
        Lead::BOOKING_KHACH_TOI_TRE,
        Lead::BOOKING_DA_XONG,
    ];

    /** Phân loại coi là "chưa tiến triển" (nếu vẫn ở đây → điều kiện no_progress đúng). */
    private const NO_PROGRESS_CLASSIFICATIONS = ['new', 'lead', 'missed', 'goi_lai_sau', 'klld'];

    public function handle(DistributionEngine $engine): int
    {
        $recalled = 0;
        $skippedByCondition = 0;

        Lead::query()
            ->with('orgUnit')
            ->where('pool_level', Lead::POOL_PERSONAL)
            ->where('is_permanent_assignment', false)
            ->whereNotNull('recall_at')
            ->where('recall_at', '<=', now())
            ->whereNotNull('owner_id')
            ->chunkById(200, function ($leads) use ($engine, &$recalled, &$skippedByCondition) {
                foreach ($leads as $lead) {
                    $policy = $lead->orgUnit ? RecallPolicyResolver::for($lead->orgUnit) : ['recall_conditions' => []];
                    $conditions = $policy['recall_conditions'] ?? [];

                    if (! $this->shouldRecall($lead, $conditions)) {
                        $skippedByCondition++;
                        continue;
                    }

                    $prevOwner = $lead->owner_id;
                    $prevOrg = $lead->org_unit_id;
                    $engine->recall($lead, Lead::POOL_TEAM, null);
                    LeadDistributionLog::create([
                        'lead_id' => $lead->id,
                        'action' => LeadDistributionLog::ACTION_RECALL,
                        'from_pool_level' => Lead::POOL_PERSONAL,
                        'to_pool_level' => Lead::POOL_TEAM,
                        'from_owner_id' => $prevOwner,
                        'org_unit_id' => $prevOrg,
                        'reason' => 'Hết hạn recall_at ' . ($conditions ? '+ khớp điều kiện [' . implode(',', $conditions) . ']' : '(chỉ deadline)'),
                        'created_at' => now(),
                    ]);
                    $lead->update(['recall_at' => null]);
                    $recalled++;
                }
            });

        $this->info("Đã thu hồi {$recalled} lead. Bỏ qua {$skippedByCondition} lead (deadline hết nhưng chưa đủ điều kiện).");
        return self::SUCCESS;
    }

    /**
     * Rule: nếu KHÔNG có conditions → recall ngay (backward-compat).
     * Nếu có conditions → phải THỎA HẾT (AND) mới recall.
     */
    private function shouldRecall(Lead $lead, array $conditions): bool
    {
        if (empty($conditions)) return true;

        foreach ($conditions as $cond) {
            if (! $this->matchesCondition($lead, $cond)) return false;
        }
        return true;
    }

    private function matchesCondition(Lead $lead, string $cond): bool
    {
        return match ($cond) {
            'no_activity' => $lead->last_care_at === null
                          || $lead->last_care_at <= $lead->assigned_at,
            'no_booking'  => ! in_array($lead->booking_status, self::BOOKED_STATUSES, true),
            'no_progress' => in_array($lead->classification, self::NO_PROGRESS_CLASSIFICATIONS, true),
            default       => true,
        };
    }
}
