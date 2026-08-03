<?php

namespace App\Console\Commands;

use App\Models\CustomField;
use App\Models\Lead;
use App\Models\LeadCustomValue;
use App\Models\LeadStatusLog;
use App\Services\DistributionEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 2026-08-04 — Thu hồi lead theo quy tắc PKD Update.docx:
 *   - Col 1,2,3 (page, camp, phan_loai) không update sau 1 ngày → thu hồi
 *   - Col 4,5 (ket_qua, sic) không update sau 3 ngày → thu hồi
 *
 * Chỉ áp dụng cho leads có recall_by_columns=true (CM/Admin tick ở form chia số).
 * Reset flag sau khi thu hồi để không loop.
 *
 * Idempotent. Schedule hourly ở routes/console.php.
 * Usage: php artisan leads:recall-by-columns [--dry-run]
 */
class RecallByColumnUpdates extends Command
{
    protected $signature = 'leads:recall-by-columns {--dry-run}';

    protected $description = 'Thu hồi lead cá nhân theo quy tắc cột (1 ngày col 1-3, 3 ngày col 4-5)';

    private const COLS_DAY_1 = ['page', 'camp', 'phan_loai'];
    private const COLS_DAY_3 = ['ket_qua', 'sic'];

    public function handle(DistributionEngine $engine): int
    {
        $recalled = ['day1' => 0, 'day3' => 0];
        $now = now();

        Lead::query()
            ->where('recall_by_columns', true)
            ->where('pool_level', Lead::POOL_PERSONAL)
            ->whereNotNull('assigned_at')
            ->with('orgUnit')
            ->chunkById(200, function ($leads) use ($engine, &$recalled, $now) {
                foreach ($leads as $lead) {
                    // Field áp dụng cho lead này = công ty + org tree (Phase 6.20 CustomField logic).
                    $fields = CustomField::applicableTo($lead->orgUnit);
                    $fieldsByKey = $fields->pluck('id', 'key')->all();
                    $day1Ids = array_values(array_intersect_key($fieldsByKey, array_flip(self::COLS_DAY_1)));
                    $day3Ids = array_values(array_intersect_key($fieldsByKey, array_flip(self::COLS_DAY_3)));

                    $hoursSinceAssigned = $lead->assigned_at->diffInHours($now, false);

                    if ($hoursSinceAssigned >= 24 && $day1Ids) {
                        $filled = LeadCustomValue::where('lead_id', $lead->id)
                            ->whereIn('custom_field_id', $day1Ids)
                            ->whereNotNull('value')->where('value', '!=', '')
                            ->count();
                        if ($filled < count($day1Ids)) {
                            $this->recallLead($lead, $engine, 'Thu hồi tự động: quá 1 ngày chưa cập nhật đủ 3 cột đầu (page/camp/phan_loai)');
                            $recalled['day1']++;
                            continue;
                        }
                    }

                    if ($hoursSinceAssigned >= 72 && $day3Ids) {
                        $filled = LeadCustomValue::where('lead_id', $lead->id)
                            ->whereIn('custom_field_id', $day3Ids)
                            ->whereNotNull('value')->where('value', '!=', '')
                            ->count();
                        if ($filled < count($day3Ids)) {
                            $this->recallLead($lead, $engine, 'Thu hồi tự động: quá 3 ngày chưa cập nhật đủ 5 cột (thêm ket_qua/sic)');
                            $recalled['day3']++;
                        }
                    }
                }
            });

        $this->info("Recall xong. Day1={$recalled['day1']}, Day3={$recalled['day3']}.");
        Log::info('leads:recall-by-columns', $recalled);
        return self::SUCCESS;
    }

    private function recallLead(Lead $lead, DistributionEngine $engine, string $reason): void
    {
        if ($this->option('dry-run')) {
            $this->line("[DRY] {$lead->code}: {$reason}");
            return;
        }
        $engine->recall($lead, Lead::POOL_TEAM, null);
        $lead->update(['recall_by_columns' => false]); // reset flag để không loop
        LeadStatusLog::record($lead, 'note', null, $reason, null);
    }
}
