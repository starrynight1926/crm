<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadDistributionLog;
use App\Models\OrgUnit;
use App\Services\RecallPolicyResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('leads:process-escalates')]
#[Description('Phase 6.6 — escalate lead ở pool team CM quá escalate_after_days → về kho CM cấp cha.')]
class ProcessLeadEscalates extends Command
{
    public function handle(): int
    {
        $escalated = 0;

        Lead::query()
            ->where('pool_level', Lead::POOL_TEAM)
            ->whereNotNull('org_unit_id')
            ->whereNotNull('assigned_at')
            ->chunkById(200, function ($leads) use (&$escalated) {
                foreach ($leads as $lead) {
                    $org = OrgUnit::find($lead->org_unit_id);
                    if (! $org || ! $org->parent_id) {
                        continue; // node gốc — không có cấp cha để escalate
                    }
                    $policy = RecallPolicyResolver::for($org);
                    $days = $policy['escalate_after_days'];
                    if (! $days) {
                        continue;
                    }
                    if ($lead->assigned_at->addDays($days)->isFuture()) {
                        continue;
                    }
                    $fromOrg = $lead->org_unit_id;
                    $lead->update([
                        'org_unit_id' => $org->parent_id,
                        'assigned_at' => now(),
                    ]);
                    LeadDistributionLog::create([
                        'lead_id' => $lead->id,
                        'action' => LeadDistributionLog::ACTION_ESCALATE,
                        'from_pool_level' => Lead::POOL_TEAM,
                        'to_pool_level' => Lead::POOL_TEAM,
                        'org_unit_id' => $fromOrg,
                        'reason' => "Quá {$days} ngày ở pool team, escalate lên cấp cha",
                        'created_at' => now(),
                    ]);
                    $escalated++;
                }
            });

        $this->info("Đã escalate {$escalated} lead lên kho CM cấp cha.");
        return self::SUCCESS;
    }
}
