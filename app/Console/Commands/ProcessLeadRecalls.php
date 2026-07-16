<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadDistributionLog;
use App\Services\DistributionEngine;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('leads:process-recalls')]
#[Description('Phase 6.6 — thu hồi lead có recall_at <= now (CM đặt mốc lúc chia). Bỏ qua lead is_permanent_assignment.')]
class ProcessLeadRecalls extends Command
{
    public function handle(DistributionEngine $engine): int
    {
        $recalled = 0;

        Lead::query()
            ->where('pool_level', Lead::POOL_PERSONAL)
            ->where('is_permanent_assignment', false)
            ->whereNotNull('recall_at')
            ->where('recall_at', '<=', now())
            ->whereNotNull('owner_id')
            ->chunkById(200, function ($leads) use ($engine, &$recalled) {
                foreach ($leads as $lead) {
                    $prevOwner = $lead->owner_id;
                    $prevOrg = $lead->org_unit_id;
                    // Thu hồi về pool team để CM team chia lại
                    $engine->recall($lead, Lead::POOL_TEAM, null);
                    LeadDistributionLog::create([
                        'lead_id' => $lead->id,
                        'action' => LeadDistributionLog::ACTION_RECALL,
                        'from_pool_level' => Lead::POOL_PERSONAL,
                        'to_pool_level' => Lead::POOL_TEAM,
                        'from_owner_id' => $prevOwner,
                        'org_unit_id' => $prevOrg,
                        'reason' => 'Hết hạn recall_at (Phase 6.6)',
                        'created_at' => now(),
                    ]);
                    $lead->update(['recall_at' => null]);
                    $recalled++;
                }
            });

        $this->info("Đã thu hồi {$recalled} lead hết hạn recall_at về pool team.");
        return self::SUCCESS;
    }
}
