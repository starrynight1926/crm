<?php

namespace App\Console\Commands;

use App\Models\CallLog;
use App\Models\CustomField;
use App\Models\Lead;
use App\Models\LeadCustomValue;
use App\Models\LeadStatusLog;
use App\Models\LeadPhaseClosure;
use App\Services\DistributionEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 2026-08-07 rev2 — Thu hồi lead cá nhân theo Quy tắc PKD Update.docx.
 *
 *   Cột 1,2,3 (day 1, ≥24h): Ngày gọi + Ghi nhận tình trạng + Bước tiếp theo.
 *     → Nghiệp vụ: cần ≥1 call_log có note khác rỗng (bao hàm ngày gọi + ghi nhận).
 *       Riêng "Bước tiếp theo" là kỳ vọng day-3 (đóng phase 2). Day-1 chỉ cần thấy sale
 *       đã liên hệ (có ghi chú), chưa cần chốt bước tiếp.
 *
 *   Cột 4,5 (day 3, ≥72h): thêm Phân loại + Kết quả.
 *     → CustomField `phan_loai` + `ket_qua` (đã đưa về scope Công ty ở migration 2026-08-07)
 *       phải có value; và LeadPhaseClosure phase=2 đã đóng (Bước tiếp theo được chốt).
 *
 * Mặc định áp cho MỌI lead cá nhân. Ô tick "Không thu hồi" (skip_recall=true) ở form
 * chia số dùng để exempt lead khỏi luật (VD lead đặc biệt CM giữ tay).
 *
 * Chạy hourly (routes/console.php). Idempotent.
 * Usage: php artisan leads:recall-by-columns [--dry-run]
 */
class RecallByColumnUpdates extends Command
{
    protected $signature = 'leads:recall-by-columns {--dry-run} {--day1-minutes=1440 : Ngưỡng day 1 tính bằng phút (mặc định 1440 = 24h)} {--day3-minutes=4320 : Ngưỡng day 3 tính bằng phút (mặc định 4320 = 72h)}';

    protected $description = 'Thu hồi lead cá nhân theo quy tắc PKD (day1: có ghi nhận cuộc gọi; day3: đủ phân loại + kết quả + đóng phase 2). Ngưỡng cấu hình qua --day1-minutes / --day3-minutes.';

    public function handle(DistributionEngine $engine): int
    {
        $recalled = ['day1' => 0, 'day3' => 0];
        $now = now();
        $day1Minutes = max(1, (int) $this->option('day1-minutes'));
        $day3Minutes = max(1, (int) $this->option('day3-minutes'));

        Lead::query()
            ->where('skip_recall', false)
            ->where('pool_level', Lead::POOL_PERSONAL)
            ->whereNotNull('assigned_at')
            ->with('orgUnit')
            ->chunkById(200, function ($leads) use ($engine, &$recalled, $now, $day1Minutes, $day3Minutes) {
                foreach ($leads as $lead) {
                    $minutesSinceAssigned = $lead->assigned_at->diffInMinutes($now, false);

                    // Day 1 — chưa có call_log nào có ghi nhận (note ≠ '').
                    if ($minutesSinceAssigned >= $day1Minutes && ! $this->hasCallWithNote($lead)) {
                        $this->recallLead($lead, $engine, "Thu hồi tự động (day1 ≥ {$day1Minutes}p): chưa có ghi nhận cuộc gọi nào.");
                        $recalled['day1']++;
                        continue;
                    }

                    // Day 3 — đủ điều kiện cột 4+5 + bước tiếp theo.
                    if ($minutesSinceAssigned >= $day3Minutes) {
                        $missing = $this->missingDay3Requirements($lead);
                        if ($missing !== []) {
                            $this->recallLead($lead, $engine, "Thu hồi tự động (day3 ≥ {$day3Minutes}p): thiếu " . implode(', ', $missing) . '.');
                            $recalled['day3']++;
                        }
                    }
                }
            });

        $this->info("Recall xong. Day1={$recalled['day1']}, Day3={$recalled['day3']}.");
        Log::info('leads:recall-by-columns', $recalled);
        return self::SUCCESS;
    }

    private function hasCallWithNote(Lead $lead): bool
    {
        return CallLog::where('lead_id', $lead->id)
            ->whereNotNull('note')->where('note', '!=', '')
            ->exists();
    }

    /**
     * Trả về danh sách nhãn thiếu (rỗng = đủ điều kiện day 3).
     * @return array<int, string>
     */
    private function missingDay3Requirements(Lead $lead): array
    {
        $missing = [];

        if (! $this->hasCallWithNote($lead)) {
            $missing[] = 'ghi nhận cuộc gọi';
        }

        $fields = CustomField::applicableTo($lead->orgUnit);
        foreach (['phan_loai' => 'Phân loại', 'ket_qua' => 'Kết quả'] as $key => $label) {
            $field = $fields->firstWhere('key', $key);
            if (! $field) {
                // Field chưa tồn tại trong scope → coi như không áp dụng (không chặn thu hồi vì thiếu field).
                continue;
            }
            $filled = LeadCustomValue::where('lead_id', $lead->id)
                ->where('custom_field_id', $field->id)
                ->whereNotNull('value')->where('value', '!=', '')
                ->exists();
            if (! $filled) $missing[] = $label;
        }

        $phase2Closed = LeadPhaseClosure::where('lead_id', $lead->id)->where('phase', 2)->exists();
        if (! $phase2Closed) $missing[] = 'đóng phase 2 (Bước tiếp theo)';

        return $missing;
    }

    private function recallLead(Lead $lead, DistributionEngine $engine, string $reason): void
    {
        if ($this->option('dry-run')) {
            $this->line("[DRY] {$lead->code}: {$reason}");
            return;
        }
        $engine->recall($lead, Lead::POOL_TEAM, null);
        LeadStatusLog::record($lead, 'note', null, $reason, null);
    }
}
