<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadDistributionLog;
use App\Models\OrgUnit;
use Illuminate\Console\Command;

/**
 * Miền Nam (HCM): lead từ Team Trực Page → chia cho Team Booking. Sau X ngày:
 *  - Không phát sinh dữ liệu mới (last_care_at KHÔNG update sau assigned_at) VÀ
 *  - Không có lịch đặt (booking_status != booked, khach_da_toi, khach_toi_tre, da_xong)
 *  → recall về kho team booking (owner_id = null, pool_level = TEAM), chờ CM booking chia tay khác.
 *
 * Chạy: php artisan leads:recall-idle-booking-hcm --days=1
 */
class RecallIdleBookingLeads extends Command
{
    protected $signature = 'leads:recall-idle-booking-hcm {--days=1 : Số ngày idle tối đa} {--branch=branch-hcm : Code branch áp dụng}';

    protected $description = 'Recall lead phase Booking bị idle (không update + không đặt lịch) sau N ngày về kho team booking.';

    public function handle(): int
    {
        // Ưu tiên option CLI, fallback AppSetting (admin sửa qua UI Cài đặt Vận hành).
        $enabled = \App\Models\AppSetting::get('idle_booking_recall_enabled', '1') === '1';
        if (! $enabled && ! $this->option('days')) {
            $this->info('Idle booking recall đang tắt (Cài đặt Vận hành). Bỏ qua.');
            return self::SUCCESS;
        }

        $days = (int) ($this->option('days') ?: \App\Models\AppSetting::get('idle_booking_recall_days', '1'));
        $branchCode = $this->option('branch') ?: \App\Models\AppSetting::get('idle_booking_recall_branch', 'branch-hcm');

        $branch = OrgUnit::where('code', $branchCode)->first();
        if (! $branch) {
            $this->error("Không tìm thấy branch code={$branchCode}.");
            return self::FAILURE;
        }
        $subtreeIds = OrgUnit::where('path', 'like', $branch->path . '%')->pluck('id')->all();

        $activeBookings = [
            Lead::BOOKING_BOOKED,
            Lead::BOOKING_KHACH_DA_TOI,
            Lead::BOOKING_KHACH_TOI_TRE,
            Lead::BOOKING_DA_XONG,
        ];

        $threshold = now()->subDays($days);
        $recalled = 0;

        Lead::query()
            ->whereIn('org_unit_id', $subtreeIds)
            ->where('pipeline_phase', Lead::PHASE_BOOKING)
            ->where('pool_level', Lead::POOL_PERSONAL)
            ->whereNotNull('owner_id')
            ->where('assigned_at', '<=', $threshold)
            ->whereNotIn('booking_status', $activeBookings)
            ->where(function ($q) {
                $q->whereNull('last_care_at')
                  ->orWhereColumn('last_care_at', '<=', 'assigned_at');
            })
            ->chunkById(200, function ($leads) use (&$recalled, $days) {
                foreach ($leads as $lead) {
                    // Tìm team booking org của lead (subtree ancestor có code kết thúc bằng -booking).
                    $teamOrg = $this->teamBookingOrg($lead);
                    if (! $teamOrg) continue;

                    $prevOwner = $lead->owner_id;
                    $prevPool = $lead->pool_level;
                    $lead->update([
                        'owner_id' => null,
                        'pool_level' => Lead::POOL_TEAM,
                        'org_unit_id' => $teamOrg->id,
                        'assigned_at' => null,
                        'recall_at' => null,
                    ]);

                    LeadDistributionLog::create([
                        'lead_id' => $lead->id,
                        'action' => LeadDistributionLog::ACTION_RECALL,
                        'from_pool_level' => $prevPool,
                        'to_pool_level' => Lead::POOL_TEAM,
                        'from_owner_id' => $prevOwner,
                        'to_owner_id' => null,
                        'org_unit_id' => $teamOrg->id,
                        'reason' => "Idle >{$days} ngày ở Team Booking (không update + không có lịch đặt) — HCM auto recall.",
                        'created_at' => now(),
                    ]);
                    $recalled++;
                }
            });

        $this->info("Đã recall {$recalled} lead idle về kho team booking (branch={$branchCode}, idle >{$days} ngày).");
        return self::SUCCESS;
    }

    private function teamBookingOrg(Lead $lead): ?OrgUnit
    {
        // Walk up từ org của lead, tìm ancestor có code chứa '-booking' (VD team-ashley-booking, team-giang-booking).
        $org = $lead->orgUnit;
        while ($org) {
            if (str_contains($org->code ?? '', '-booking')) return $org;
            $org = $org->parent;
        }
        return null;
    }
}
