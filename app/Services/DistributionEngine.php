<?php

namespace App\Services;

use App\Models\DistributionRule;
use App\Models\Lead;
use App\Models\LeadCap;
use App\Models\LeadDistributionLog;
use App\Models\OrgUnit;
use App\Models\RuleCounter;
use App\Models\RuleTarget;
use App\Models\User;
use App\Models\UserLeadSetting;
use App\Notifications\LeadAssigned;
use App\Support\NotificationEvents;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

/**
 * Engine chia số (scope.md mục 6):
 * - Cấp 1 (pool_to_team): kho chung → kho team. Cấp 2 (team_to_user): kho team → kho cá nhân.
 * - Rule khớp theo priority tăng dần, khớp rule đầu tiên thì dừng; không khớp → lead nằm lại kho.
 * - Strategy: round_robin / weighted (top_revenue / top_close_rate tạm fallback round_robin, hoàn thiện Phase 6).
 * - Constraints: bật/tắt nhận số, trần lead 3 cấp (chạm trần nhảy đích kế tiếp).
 * - Chống race khi lead về dồn dập: lock rule_counters bằng SELECT ... FOR UPDATE trong transaction.
 */
class DistributionEngine
{
    /** Chia 1 lead từ vị trí hiện tại xuống sâu nhất có thể. */
    public function distribute(Lead $lead): void
    {
        // Phase 6.20 — eager load customValues để accessor page/camp không N+1 khi match rule
        $lead->loadMissing('customValues');
        if ($lead->pool_level === Lead::POOL_COMMON) {
            $this->runLevel($lead, DistributionRule::LEVEL_POOL_TO_TEAM);
        }

        if ($lead->refresh()->pool_level === Lead::POOL_TEAM) {
            $lead->loadMissing('customValues');
            $this->runLevel($lead, DistributionRule::LEVEL_TEAM_TO_USER);
        }
    }

    private function runLevel(Lead $lead, string $level): void
    {
        $rules = DistributionRule::query()
            ->where('level', $level)
            ->where('active', true)
            ->when(
                $level === DistributionRule::LEVEL_TEAM_TO_USER,
                fn ($q) => $q->where('org_unit_id', $lead->org_unit_id)
            )
            ->orderBy('priority')
            ->orderBy('id')
            ->with('targets')
            ->get();

        foreach ($rules as $rule) {
            if (! $rule->matches($lead)) {
                continue;
            }

            // Khớp rule đầu tiên thì dừng — kể cả khi mọi đích kẹt trần (lead nằm lại kho)
            $target = $this->pickTarget($rule);
            if ($target) {
                $this->applyTarget($lead, $rule, $target);
            }

            return;
        }
    }

    /**
     * Chọn đích theo strategy, có lock chống race.
     * Trả null nếu mọi đích đều không đủ điều kiện (tắt nhận số / chạm trần).
     */
    private function pickTarget(DistributionRule $rule): ?RuleTarget
    {
        $period = now()->toDateString();

        // insertOrIgnore NGOÀI transaction: race-safe (firstOrCreate nổ duplicate key khi 2 worker
        // cùng tạo) và không kéo gap lock vào transaction bên dưới
        RuleCounter::insertOrIgnore(
            $rule->targets->map(fn ($target) => [
                'rule_id' => $rule->id,
                'target_id' => $target->target_id,
                'period_key' => $period,
                'delivered_count' => 0,
            ])->all()
        );

        // attempts=5: MySQL có thể deadlock khi nhiều worker lock cùng bộ counter — retry là xử lý chuẩn
        return DB::transaction(function () use ($rule, $period) {
            $counters = RuleCounter::query()
                ->where('rule_id', $rule->id)
                ->where('period_key', $period)
                ->orderBy('target_id') // lock theo thứ tự cố định, giảm deadlock chéo
                ->lockForUpdate()
                ->get()
                ->keyBy('target_id');

            $eligible = $rule->targets->filter(fn (RuleTarget $t) => $this->isEligible($t));
            if ($eligible->isEmpty()) {
                return null;
            }

            if (in_array($rule->strategy, ['top_revenue', 'top_close_rate'], true)) {
                // Ưu tiên đích có metric cao nhất trong cửa sổ cấu hình (đọc stats_daily)
                $picked = $eligible->sortBy([
                    fn (RuleTarget $a, RuleTarget $b) => $this->metricFor($b, $rule) <=> $this->metricFor($a, $rule),
                    fn (RuleTarget $a, RuleTarget $b) => $a->position <=> $b->position,
                ])->first();
            } else {
                $useWeight = $rule->strategy === 'weighted';

                // Chọn đích có (đã nhận / tỉ trọng) nhỏ nhất; hòa thì theo position
                $picked = $eligible->sortBy([
                    fn (RuleTarget $a, RuleTarget $b) => $this->ratio($a, $counters, $useWeight) <=> $this->ratio($b, $counters, $useWeight),
                    fn (RuleTarget $a, RuleTarget $b) => $a->position <=> $b->position,
                ])->first();
            }

            $counters[$picked->target_id]->increment('delivered_count');

            return $picked;
        }, attempts: 5);
    }

    /**
     * Metric của đích theo cửa sổ của rule (scope 6.2): mặc định theo ngày,
     * cấu hình được week / month / custom. Đọc từ stats_daily.
     */
    private function metricFor(RuleTarget $target, DistributionRule $rule): float
    {
        $config = $rule->strategy_config ?? [];
        [$from, $to] = match ($config['metric_window'] ?? 'day') {
            'week' => [now()->startOfWeek()->toDateString(), now()->toDateString()],
            'month' => [now()->startOfMonth()->toDateString(), now()->toDateString()],
            'custom' => [
                $config['custom_from'] ?? now()->toDateString(),
                $config['custom_to'] ?? now()->toDateString(),
            ],
            default => [now()->toDateString(), now()->toDateString()],
        };

        $query = DB::table('stats_daily')->whereBetween('date', [$from, $to]);

        if ($target->target_type === 'user') {
            $query->where('user_id', $target->target_id);
        } else {
            $subtreeIds = OrgUnit::find($target->target_id)?->subtreeIds() ?? [$target->target_id];
            $query->whereIn('org_unit_id', $subtreeIds);
        }

        if ($rule->strategy === 'top_revenue') {
            return (float) $query->sum('revenue_collected');
        }

        // top_close_rate = close / total trong cửa sổ
        $row = $query->selectRaw('sum(`close`) as c, sum(total) as t')->first();

        return $row->t > 0 ? $row->c / $row->t : 0.0;
    }

    private function ratio(RuleTarget $target, $counters, bool $useWeight): float
    {
        $delivered = $counters[$target->target_id]->delivered_count ?? 0;
        $weight = $useWeight ? max($target->weight, 1) : 1;

        return $delivered / $weight;
    }

    /** Đích còn nhận được lead không: bật nhận số + chưa chạm trần (cả trần cấp cha). */
    private function isEligible(RuleTarget $target): bool
    {
        if ($target->target_type === 'user') {
            if (! UserLeadSetting::isReceiving($target->target_id)) {
                return false;
            }

            $cap = LeadCap::capFor('user', $target->target_id);
            if ($cap !== null && $this->userReceivedToday($target->target_id) >= $cap) {
                return false;
            }

            return true;
        }

        // Đích org_unit: check trần của chính nó và mọi cấp cha (trần phòng ban)
        $org = OrgUnit::find($target->target_id);
        if (! $org || ! $org->active) {
            return false;
        }

        foreach ($this->selfAndAncestors($org) as $node) {
            $cap = LeadCap::capFor('org_unit', $node->id);
            if ($cap !== null && $this->orgReceivedToday($node) >= $cap) {
                return false;
            }
        }

        return true;
    }

    /** @return OrgUnit[] node + tổ tiên (theo materialized path) */
    private function selfAndAncestors(OrgUnit $org): array
    {
        $ids = array_filter(array_map('intval', explode('/', trim($org->path, '/'))));

        return OrgUnit::whereIn('id', $ids)->get()->all();
    }

    private function userReceivedToday(int $userId): int
    {
        return LeadDistributionLog::where('to_owner_id', $userId)
            ->whereIn('action', [LeadDistributionLog::ACTION_DISTRIBUTE, LeadDistributionLog::ACTION_MANUAL, LeadDistributionLog::ACTION_PULL])
            ->whereDate('created_at', today())
            ->count();
    }

    private function orgReceivedToday(OrgUnit $org): int
    {
        $subtreeIds = $org->subtreeIds();

        return LeadDistributionLog::where('action', LeadDistributionLog::ACTION_DISTRIBUTE)
            ->where('to_pool_level', Lead::POOL_TEAM)
            ->whereIn('org_unit_id', $subtreeIds)
            ->whereDate('created_at', today())
            ->count();
    }

    private function applyTarget(Lead $lead, DistributionRule $rule, RuleTarget $target): void
    {
        if ($target->target_type === 'org_unit') {
            LeadDistributionLog::create([
                'lead_id' => $lead->id,
                'action' => LeadDistributionLog::ACTION_DISTRIBUTE,
                'from_pool_level' => $lead->pool_level,
                'to_pool_level' => Lead::POOL_TEAM,
                'org_unit_id' => $target->target_id,
                'rule_id' => $rule->id,
                'created_at' => now(),
            ]);

            $lead->update([
                'org_unit_id' => $target->target_id,
                'pool_level' => Lead::POOL_TEAM,
            ]);

            return;
        }

        LeadDistributionLog::create([
            'lead_id' => $lead->id,
            'action' => LeadDistributionLog::ACTION_DISTRIBUTE,
            'from_pool_level' => $lead->pool_level,
            'to_pool_level' => Lead::POOL_PERSONAL,
            'from_owner_id' => $lead->owner_id,
            'to_owner_id' => $target->target_id,
            'org_unit_id' => $lead->org_unit_id,
            'rule_id' => $rule->id,
            'created_at' => now(),
        ]);

        $lead->update([
            'owner_id' => $target->target_id,
            'pool_level' => Lead::POOL_PERSONAL,
            'assigned_at' => now(),
            // Đồng bộ với manualAssign: có owner thì chuyển WAITING → IN_CARE để badge
            // không kẹt "Chờ CM ... chia" sau khi đã chia (bug Wave 1 #6, 2026-07-31).
            'pipeline_status' => $lead->pipeline_status === Lead::PSTATUS_WAITING
                ? Lead::PSTATUS_IN_CARE
                : $lead->pipeline_status,
        ]);

        $this->autoClosePhase($lead, Lead::CF_PHASE_NEW, null);
        $this->notifyAssigned($lead, (int) $target->target_id);
    }

    protected function notifyAssigned(Lead $lead, int $userId, ?int $fromOwnerId = null): void
    {
        $payload = [
            'tieu_de'    => 'Bạn vừa nhận lead mới',
            'noi_dung'   => $lead->name.($lead->code ? " ({$lead->code})" : ''),
            'link'       => '/leads/'.$lead->id,
            'lead_id'    => $lead->id,
            'lead_code'  => $lead->code,
            'lead_name'  => $lead->name,
        ];
        App::make(NotificationDispatcher::class)
            ->send(NotificationEvents::LEAD_ASSIGNED, [$userId], $payload);

        if ($fromOwnerId && $fromOwnerId !== $userId) {
            App::make(NotificationDispatcher::class)->send(
                NotificationEvents::LEAD_TRANSFERRED,
                [$fromOwnerId, $userId],
                [
                    'tieu_de'  => 'Lead đã được chuyển',
                    'noi_dung' => $lead->name.($lead->code ? " ({$lead->code})" : ''),
                    'link'     => '/leads/'.$lead->id,
                    'lead_id'  => $lead->id,
                    'from_owner_id' => $fromOwnerId,
                    'to_owner_id'   => $userId,
                ]
            );
        }
    }

    // ---------- Thao tác ngoài luồng tự động ----------

    /** Thu hồi lead khỏi sale: về kho cơ sở (facility) hoặc kho chung. $actorId null = hệ thống (SLA). */
    public function recall(Lead $lead, string $recallTo = Lead::POOL_TEAM, ?int $actorId = null): void
    {
        $prevOwnerId = $lead->owner_id;
        $orgUnitId = $lead->org_unit_id;

        // 2026-08-12: nếu recall về TEAM, cần bảo đảm lead có pool_unit_id (Phase 6.24 dùng
        // pool_unit_id để phân tab facility/branch/department). Ưu tiên pool_unit_id sẵn có;
        // fallback suy từ owner cũ (org_pool_map). Không suy được → fallback về POOL_COMMON.
        $poolUnitId = null;
        if ($recallTo === Lead::POOL_TEAM) {
            $poolUnitId = $lead->pool_unit_id;
            if (! $poolUnitId && $prevOwnerId) {
                $poolUnitId = $this->resolvePoolUnitIdFromUser($prevOwnerId);
            }
        }
        $toCommon = $recallTo === Lead::POOL_COMMON
            || ($recallTo === Lead::POOL_TEAM && ! $poolUnitId && $lead->org_unit_id === null);

        LeadDistributionLog::create([
            'lead_id' => $lead->id,
            'action' => LeadDistributionLog::ACTION_RECALL,
            'from_pool_level' => $lead->pool_level,
            'to_pool_level' => $toCommon ? Lead::POOL_COMMON : Lead::POOL_TEAM,
            'from_owner_id' => $lead->owner_id,
            'org_unit_id' => $toCommon ? null : $lead->org_unit_id,
            'actor_id' => $actorId,
            'created_at' => now(),
        ]);

        $update = [
            'owner_id' => null,
            'assigned_at' => null,
            'pool_level' => $toCommon ? Lead::POOL_COMMON : Lead::POOL_TEAM,
            'org_unit_id' => $toCommon ? null : $lead->org_unit_id,
        ];
        if (! $toCommon && $poolUnitId) $update['pool_unit_id'] = $poolUnitId;
        if ($toCommon) $update['pool_unit_id'] = null;
        $lead->update($update);

        $payload = [
            'tieu_de'    => 'Lead bị thu hồi về kho',
            'noi_dung'   => $lead->name.($lead->code ? " ({$lead->code})" : '').' — quá hạn xử lý',
            'link'       => '/leads/'.$lead->id,
            'lead_id'    => $lead->id,
            'from_owner_id' => $prevOwnerId,
        ];
        $dispatcher = App::make(NotificationDispatcher::class);
        if ($prevOwnerId) {
            $dispatcher->send(NotificationEvents::LEAD_RECALLED, [$prevOwnerId], $payload);
        }
        $dispatcher->sendToRoles(NotificationEvents::LEAD_RECALLED, $payload, [
            'owner_id'    => $prevOwnerId,
            'org_unit_id' => $orgUnitId,
        ]);
    }

    /**
     * 2026-08-12 — Suy pool_unit_id (kind=facility) từ assignments của user qua org_pool_map.
     * Dùng khi recall lead PERSONAL về TEAM mà lead chưa có pool_unit_id. Trả null nếu không map được.
     */
    private function resolvePoolUnitIdFromUser(int $userId): ?int
    {
        $user = \App\Models\User::find($userId);
        if (! $user) return null;
        $ancestorOrgIds = [];
        foreach ($user->effectiveAssignments() as $assignment) {
            foreach (array_filter(explode('/', trim((string) $assignment->orgUnit?->path, '/'))) as $seg) {
                $ancestorOrgIds[(int) $seg] = true;
            }
        }
        if ($ancestorOrgIds === []) return null;
        $poolUnit = \App\Models\PoolUnit::where('kind', 'facility')
            ->where('is_active', true)
            ->whereIn('id', function ($q) use ($ancestorOrgIds) {
                $q->select('pool_unit_id')->from('org_pool_map')->whereIn('org_unit_id', array_keys($ancestorOrgIds));
            })
            ->orderBy('depth')
            ->first();
        return $poolUnit?->id;
    }

    /**
     * Phase 6.24 — Chuyển lead vào kho pool cụ thể (chi nhánh / cơ sở / phòng KD).
     * Tham số $poolUnitId là id của PoolUnit (cây Kho số mới), không còn là org_unit.
     */
    public function moveToTeam(Lead $lead, int $poolUnitId, int $actorId): void
    {
        LeadDistributionLog::create([
            'lead_id' => $lead->id,
            'action' => LeadDistributionLog::ACTION_RECALL,
            'from_pool_level' => $lead->pool_level,
            'to_pool_level' => Lead::POOL_TEAM,
            'from_owner_id' => $lead->owner_id,
            'org_unit_id' => null,
            'actor_id' => $actorId,
            'created_at' => now(),
        ]);

        $lead->update([
            'owner_id' => null,
            'assigned_at' => null,
            'pool_level' => Lead::POOL_TEAM,
            'pool_unit_id' => $poolUnitId,
        ]);
    }

    /** Chia thủ công cho 1 sale cụ thể (quyền lead.distribute). */
    public function manualAssign(Lead $lead, User $user, int $actorId): void
    {
        $prevOwnerId = $lead->owner_id;

        LeadDistributionLog::create([
            'lead_id' => $lead->id,
            'action' => LeadDistributionLog::ACTION_MANUAL,
            'from_pool_level' => $lead->pool_level,
            'to_pool_level' => Lead::POOL_PERSONAL,
            'from_owner_id' => $lead->owner_id,
            'to_owner_id' => $user->id,
            'org_unit_id' => $lead->org_unit_id,
            'actor_id' => $actorId,
            'created_at' => now(),
        ]);

        $lead->update([
            'owner_id' => $user->id,
            'pool_level' => Lead::POOL_PERSONAL,
            'assigned_at' => now(),
            // Chuyển từ WAITING → IN_CARE khi đã có owner: badge hiển thị đúng
            // trạng thái "Đang chăm sóc" thay vì "Chờ CM chia".
            'pipeline_status' => $lead->pipeline_status === Lead::PSTATUS_WAITING
                ? Lead::PSTATUS_IN_CARE
                : $lead->pipeline_status,
        ]);

        $this->autoClosePhase($lead, Lead::CF_PHASE_NEW, $actorId);
        $this->notifyAssigned($lead, $user->id, $prevOwnerId);
    }

    /**
     * Auto-chốt phase khi có sự kiện chuẩn (chia số → P2 done, booked → P4 done, …).
     * Bỏ qua nếu phase đã closed trước đó, hoặc lead đang bulk-open (để user tự bấm
     * "Lưu — chốt N phase" bulk 1 phát). Fix Wave 1 #5-UI (2026-07-31).
     */
    protected function autoClosePhase(Lead $lead, int $phase, ?int $actorId): void
    {
        // closed_by NOT NULL → fallback: actor > owner > imported_by > first user.
        $closer = $actorId
            ?? $lead->owner_id
            ?? $lead->imported_by
            ?? $lead->receiver_id
            ?? \App\Models\User::orderBy('id')->value('id');
        if (! $closer) return; // Không tìm được user nào — skip (env test trống)
        for ($p = 1; $p <= $phase; $p++) {
            if (\App\Models\LeadPhaseClosure::where('lead_id', $lead->id)->where('phase', $p)->exists()) continue;
            \App\Models\LeadPhaseClosure::create([
                'lead_id' => $lead->id, 'phase' => $p,
                'closed_by' => $closer, 'closed_at' => now(),
                'note' => 'Auto-close (event)',
            ]);
        }
        if ((int) $lead->phase <= $phase) {
            $lead->update(['phase' => min($phase + 1, 5)]);
        }
    }

    /** Sale tự kéo lead từ kho về mình (quyền lead.pull_pool). */
    public function pull(Lead $lead, User $user): void
    {
        LeadDistributionLog::create([
            'lead_id' => $lead->id,
            'action' => LeadDistributionLog::ACTION_PULL,
            'from_pool_level' => $lead->pool_level,
            'to_pool_level' => Lead::POOL_PERSONAL,
            'to_owner_id' => $user->id,
            'org_unit_id' => $lead->org_unit_id,
            'actor_id' => $user->id,
            'created_at' => now(),
        ]);

        $lead->update([
            'owner_id' => $user->id,
            'pool_level' => Lead::POOL_PERSONAL,
            'assigned_at' => now(),
        ]);
    }
}
