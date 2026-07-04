<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\SlaPolicy;
use App\Services\DistributionEngine;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('leads:recall-overdue')]
#[Description('Thu hồi lead quá SLA không chăm (policy mode=auto) và chia lại')]
class RecallOverdueLeads extends Command
{
    public function handle(DistributionEngine $engine): int
    {
        $policies = SlaPolicy::where('mode', SlaPolicy::MODE_AUTO)->get();
        if ($policies->isEmpty()) {
            $this->info('Không có SLA policy nào bật auto.');
            return self::SUCCESS;
        }

        $default = $policies->firstWhere('org_unit_id', null);
        $recalled = 0;

        // Lead cá nhân, quá hạn theo policy của org (hoặc mặc định), chưa chăm sau khi nhận
        Lead::query()
            ->where('pool_level', Lead::POOL_PERSONAL)
            ->whereNotNull('assigned_at')
            ->chunkById(200, function ($leads) use ($policies, $default, $engine, &$recalled) {
                foreach ($leads as $lead) {
                    $policy = $policies->firstWhere('org_unit_id', $lead->org_unit_id) ?? $default;
                    if (! $policy) {
                        continue;
                    }

                    $deadline = $lead->assigned_at->addHours($policy->recall_after_hours);
                    $cared = $lead->last_care_at !== null && $lead->last_care_at->gte($lead->assigned_at);

                    if ($cared || $deadline->isFuture()) {
                        continue;
                    }

                    $engine->recall($lead, $policy->recall_to, null); // null = hệ thống
                    $engine->distribute($lead->refresh()); // chia lại ngay
                    $recalled++;
                }
            });

        $this->info("Đã thu hồi & chia lại {$recalled} lead quá SLA.");

        return self::SUCCESS;
    }
}
