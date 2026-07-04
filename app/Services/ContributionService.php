<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\CustomerServicePhase;
use App\Models\Lead;
use App\Models\LeadStatusLog;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * % đóng góp khi deal Close (scope.md 8.2): lead team đánh % cho từng người
 * tham gia, tổng bắt buộc = 100. Người tham gia gợi ý từ lịch sử tường minh
 * (người nhận, người giữ, người làm phase, người chăm sóc) — không suy đoán.
 */
class ContributionService
{
    /**
     * Gợi ý người tham gia deal + vai trò, lấy từ dữ liệu đã ghi nhận.
     *
     * @return Collection<int, array{user: User, role_label: string}>
     */
    public function suggestParticipants(Lead $lead): Collection
    {
        $participants = collect();

        if ($lead->receiver) {
            $participants->push(['user' => $lead->receiver, 'role_label' => 'collector']);
        }
        if ($lead->owner) {
            $participants->push(['user' => $lead->owner, 'role_label' => 'closer']);
        }

        // Người chăm sóc từ lịch sử status log
        $careUserIds = LeadStatusLog::where('lead_id', $lead->id)
            ->whereNotNull('user_id')
            ->whereIn('field', ['classification', 'status_1', 'status_2', 'note'])
            ->pluck('user_id')
            ->unique();
        foreach (User::whereIn('id', $careUserIds)->get() as $user) {
            $participants->push(['user' => $user, 'role_label' => 'care_1']);
        }

        // Người làm phase (mục 8.1 — nguồn tường minh)
        $phaseWorkerIds = CustomerServicePhase::whereIn(
            'customer_service_id',
            $lead->customerServices()->pluck('id')
        )->whereNotNull('done_by')->pluck('done_by')->unique();
        foreach (User::whereIn('id', $phaseWorkerIds)->get() as $user) {
            $participants->push(['user' => $user, 'role_label' => 'phase_worker']);
        }

        // Mỗi người một dòng, giữ vai trò đầu tiên tìm thấy
        return $participants->unique(fn ($p) => $p['user']->id)->values();
    }

    /**
     * Lưu bảng % đóng góp cho deal. $rows = [[user_id, role_label, percent], ...].
     * Ném InvalidArgumentException nếu tổng ≠ 100 hoặc trùng người.
     */
    public function save(Lead $lead, array $rows, int $setBy, ?int $customerServiceId = null): void
    {
        $rows = array_values(array_filter($rows, fn ($r) => (float) ($r['percent'] ?? 0) > 0));

        if ($rows === []) {
            throw new InvalidArgumentException('Cần ít nhất một người tham gia có % > 0.');
        }

        $total = round(array_sum(array_map(fn ($r) => (float) $r['percent'], $rows)), 2);
        if ($total !== 100.0) {
            throw new InvalidArgumentException("Tổng % phải đúng 100 (hiện tại: {$total}).");
        }

        $userIds = array_column($rows, 'user_id');
        if (count($userIds) !== count(array_unique($userIds))) {
            throw new InvalidArgumentException('Mỗi người chỉ xuất hiện một dòng.');
        }

        // Ghi đè bảng % cũ của deal (nếu sửa lại)
        Contribution::where('lead_id', $lead->id)->delete();

        foreach ($rows as $row) {
            Contribution::create([
                'lead_id' => $lead->id,
                'customer_service_id' => $customerServiceId,
                'user_id' => (int) $row['user_id'],
                'role_label' => $row['role_label'] ?? 'other',
                'percent' => (float) $row['percent'],
                'set_by' => $setBy,
                'created_at' => now(),
            ]);
        }
    }
}
