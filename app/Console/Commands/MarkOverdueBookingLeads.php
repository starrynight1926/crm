<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadDistributionLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('leads:mark-overdue-booking {--days=7 : Số ngày ở kho booking coi là quá hạn}')]
#[Description('Phase 6.6 — đánh dấu overdue cho lead nhóm marketing/data_cold/bdm chưa được chia sau X ngày (không xóa).')]
class MarkOverdueBookingLeads extends Command
{
    public function handle(): int
    {
        $days = (int) $this->option('days') ?: 7;
        $marked = 0;

        Lead::query()
            ->whereIn('source_group', [Lead::SOURCE_MARKETING, Lead::SOURCE_DATA_COLD, Lead::SOURCE_BDM])
            ->where('pool_level', Lead::POOL_COMMON)
            ->whereNull('overdue_marked_at')
            ->where('created_at', '<=', now()->subDays($days))
            ->chunkById(200, function ($leads) use (&$marked) {
                foreach ($leads as $lead) {
                    $lead->update(['overdue_marked_at' => now()]);
                    LeadDistributionLog::create([
                        'lead_id' => $lead->id,
                        'action' => LeadDistributionLog::ACTION_MARK_OVERDUE,
                        'from_pool_level' => $lead->pool_level,
                        'to_pool_level' => $lead->pool_level,
                        'reason' => "Ở kho booking quá {$this->option('days')} ngày chưa xử lý",
                        'created_at' => now(),
                    ]);
                    $marked++;
                }
            });

        $this->info("Đã đánh dấu overdue {$marked} lead ở kho booking.");
        return self::SUCCESS;
    }
}
