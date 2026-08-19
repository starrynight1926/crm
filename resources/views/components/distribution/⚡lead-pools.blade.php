<?php

use App\Models\CustomField;
use App\Models\Lead;
use App\Models\OrgUnit;
use App\Models\User;
use App\Services\DistributionEngine;
use App\Services\Ups\UpsGate;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    // 2026-08-05: 5 tab thay 3 — tách kho team theo cấp PoolUnit.
    // company | branch | facility | department | personal
    public string $tab = 'company';

    // 2026-08-19: label khớp cascade "Công ty → Địa điểm → Cơ sở → Phòng ban".
    //   DB kind: branch=Địa điểm, facility=Cơ sở, department=Phòng ban.
    //   Trước đây label lệch 1 cấp: branch→"Chi nhánh", facility→"địa điểm", department→"cơ sở"
    //   khiến lead pool_unit=facility (CS Lô 2 & 3) hiện ở tab "Kho địa điểm" gây nhầm lẫn.
    public const TAB_KINDS = [
        'company'    => ['label' => 'Kho công ty',    'pool_level' => Lead::POOL_COMMON,   'pool_kind' => null],
        'branch'     => ['label' => 'Kho Địa điểm',   'pool_level' => Lead::POOL_TEAM,     'pool_kind' => 'branch'],
        'facility'   => ['label' => 'Kho Cơ sở',      'pool_level' => Lead::POOL_TEAM,     'pool_kind' => 'facility'],
        'department' => ['label' => 'Kho Phòng ban',  'pool_level' => Lead::POOL_TEAM,     'pool_kind' => 'department'],
        'personal'   => ['label' => 'Kho cá nhân',    'pool_level' => Lead::POOL_PERSONAL, 'pool_kind' => null],
    ];

    public string $fOrgUnit = '';

    // 2026-08-03 cascade: 3 select thay dropdown flat cho filter Team.
    public string $fBranchId = '';
    public string $fFacilityId = '';
    public string $fDepartmentId = '';

    public int $perPage = 20;

    /** @var array<int> id các lead được tick để thao tác hàng loạt */
    public array $selected = [];

    // Chia thủ công 1 lead
    public ?int $assigningLeadId = null;

    public string $assignUserId = '';

    // Phase 6.6 — mốc thu hồi khi chia (chỉ hiện với user có lead.recall)
    public string $assignRecallMode = 'default'; // default (dùng recall_after_days) | custom | permanent
    public ?int $assignRecallDays = null;

    // Chia về kho phòng/team 1 lead
    public ?int $poolingLeadId = null;

    public string $poolOrgId = '';

    // Thao tác hàng loạt
    public string $bulkMode = ''; // '' | 'assign' | 'pool'

    public string $bulkUserId = '';

    public string $bulkOrgId = '';

    // 2026-08-19: recall mode cho bulk assign — đồng bộ với confirmAssign per-lead.
    public string $bulkRecallMode = 'default'; // default | custom | permanent
    public ?int $bulkRecallDays = null;

    // Popup chi tiết
    public ?int $detailLeadId = null;

    public function mount(): void
    {
        // Sale cá nhân → default tab 'personal' (họ không có perm distribute).
        if (! auth()->user()->hasAnyPermission(['lead.distribute', 'lead.distribute_to_team', 'lead.distribute_to_sale'])) {
            $this->tab = 'personal';
        }
        // 2026-08-19: coerce về tab hợp lệ nếu default 'company' không nằm trong visibleTabs
        //   (CM/TL/Admin cơ sở ẩn tab công ty nhưng $tab='company' vẫn khiến query trả kho cty).
        $this->coerceTabIfHidden();
    }

    private function coerceTabIfHidden(): void
    {
        $tabs = $this->visibleTabs();
        if (! array_key_exists($this->tab, $tabs)) {
            $this->tab = array_key_first($tabs) ?: 'personal';
        }
    }

    public function switchTab(string $tab): void
    {
        abort_unless(array_key_exists($tab, self::TAB_KINDS), 422);
        // 2026-08-19: chặn user request tab bị ẩn qua URL/frontend.
        abort_unless(array_key_exists($tab, $this->visibleTabs()), 403, 'Bạn không có quyền xem tab này.');
        $this->tab = $tab;
        $this->assigningLeadId = null;
        $this->poolingLeadId = null;
        $this->bulkMode = '';
        $this->selected = [];
        $this->resetPage();
    }

    /** 2026-08-05: tab hợp lệ cho user hiện tại (Sale cá nhân chỉ thấy 'personal').
     *  2026-08-19: tab "Kho công ty" chỉ cho user có `report.view_all` (Admin công ty / DM / Manager cấp cao).
     *    CM/TL/Admin cơ sở không thấy tab — họ chỉ quản lead trong scope cơ sở/team. */
    public function visibleTabs(): array
    {
        $user = auth()->user();
        $canDistribute = $user->hasAnyPermission(['lead.distribute', 'lead.distribute_to_team', 'lead.distribute_to_sale']);
        if (! $canDistribute) {
            // Sale cá nhân → chỉ Kho cá nhân.
            return ['personal' => self::TAB_KINDS['personal']];
        }
        $tabs = self::TAB_KINDS;
        if (! $user->hasPermission('report.view_all')) {
            unset($tabs['company']);
        }
        return $tabs;
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    // 2026-08-03 cascade filter Team.
    public function updatedFBranchId(): void   { $this->fFacilityId = ''; $this->fDepartmentId = ''; $this->recomputeFOrgUnit(); }
    public function updatedFFacilityId(): void { $this->fDepartmentId = ''; $this->recomputeFOrgUnit(); }
    public function updatedFDepartmentId(): void { $this->recomputeFOrgUnit(); }
    private function recomputeFOrgUnit(): void
    {
        $this->fOrgUnit = (string) ($this->fDepartmentId ?: ($this->fFacilityId ?: $this->fBranchId));
        $this->resetPage();
    }

    /** Query kho theo tab + filter, chưa phân trang. */
    private function filtered()
    {
        $user = auth()->user();
        $spec = self::TAB_KINDS[$this->tab] ?? self::TAB_KINDS['company'];

        return Lead::query()
            ->where('pool_level', $spec['pool_level'])
            ->with(['owner', 'poolUnit'])
            // 2026-08-19: bỏ skip visibleTo cho tab company — trước đây skip khiến CM cơ sở
            //   thấy TOÀN BỘ lead kho công ty nationwide. Giờ apply đồng nhất mọi tab.
            //   scopeVisibleTo đã handle case pool_common qua perm lead.view_pool + org scope.
            ->visibleTo($user)
            // Lọc theo kind của PoolUnit cho các tab team (branch/facility/department).
            ->when($spec['pool_kind'], fn ($q) => $q->whereHas('poolUnit', fn ($pq) => $pq->where('kind', $spec['pool_kind'])))
            ->when($this->tab === 'department' && $this->fOrgUnit, fn ($q) => $q->where('pool_unit_id', $this->fOrgUnit))
            ->orderByDesc('id');
    }

    private function currentPageIds(): array
    {
        return $this->filtered()->paginate($this->perPage)->pluck('id')->all();
    }

    public function toggleAllOnPage(): void
    {
        $pageIds = $this->currentPageIds();
        $allSelected = $pageIds !== [] && count(array_intersect($pageIds, $this->selected)) === count($pageIds);
        $this->selected = $allSelected
            ? array_values(array_diff($this->selected, $pageIds))
            : array_values(array_unique(array_merge($this->selected, $pageIds)));
    }

    /**
     * Phase 6.24 — Danh sách Kho (chi nhánh / cơ sở / phòng KD) để đưa lead vào.
     * Node gốc "Công ty" = kho chung, dùng option 'common' riêng.
     */
    private function poolOrgs()
    {
        return \App\Models\PoolUnit::where('is_active', true)
            ->where('depth', '>', 0)
            ->orderBy('path')
            ->get();
    }

    /** Chuyển 1 lead về đích kho theo lựa chọn: 'common' = kho chung công ty, còn lại = org id (kho team). */
    private function moveOne(Lead $lead, string $target, int $actorId): void
    {
        $engine = app(DistributionEngine::class);
        if ($target === 'common') {
            $engine->recall($lead, Lead::POOL_COMMON, $actorId);
        } else {
            $engine->moveToTeam($lead, (int) $target, $actorId);
        }
    }

    // ---------- Thao tác đơn ----------

    /**
     * Phase 6.20 — Gate chia lead theo cấp pool:
     * - Kho công ty (POOL_COMMON) → cần lead.distribute_to_team (CM cơ sở) hoặc lead.distribute (compat).
     * - Kho team (POOL_TEAM) → cần lead.distribute_to_sale (CM team) hoặc lead.distribute (compat).
     *
     * Ngoại lệ (trực page): user có distribute_to_team NHƯNG không có lead.distribute
     * (không phải CM cơ sở toàn quyền) → chỉ chia được lead CHÍNH MÌNH đã import
     * (imported_by = user). Lead do nguồn khác vào kho chung vẫn chờ CM chia.
     */
    /** Guard UPS: chi nhánh chưa chốt UPS hôm nay → chặn chia. */
    private function upsGuard(): void
    {
        abort_if(
            app(UpsGate::class)->isBlockedFor(auth()->user()),
            423,
            'UPS chưa được chốt — liên hệ bộ phận BO để xác nhận.'
        );
    }

    public function canDistributeLead(Lead $lead): bool
    {
        $user = auth()->user();
        if ($user->hasPermission('lead.distribute')) return true;
        if ($lead->pool_level === Lead::POOL_COMMON) {
            if (! $user->hasPermission('lead.distribute_to_team')) return false;
            return $lead->imported_by === $user->id;
        }
        if ($lead->pool_level === Lead::POOL_TEAM) return $user->hasPermission('lead.distribute_to_sale');
        return false;
    }

    public function autoDistribute(int $leadId): void
    {
        $this->upsGuard();
        $lead = Lead::findOrFail($leadId);
        abort_unless($this->canDistributeLead($lead), 403);
        app(DistributionEngine::class)->distribute($lead);

        $lead->refresh();
        session()->flash('status', $lead->owner_id
            ? "Đã chia {$lead->name} cho {$lead->owner->name}."
            : ($lead->pool_level === Lead::POOL_TEAM
                ? "{$lead->name} đã về kho team {$lead->poolUnit?->name}, chưa có rule/sale nhận tiếp."
                : "Không có rule nào khớp — {$lead->name} vẫn ở kho chung."));
    }

    public function startAssign(int $leadId): void
    {
        $lead = Lead::findOrFail($leadId);
        abort_unless($this->canDistributeLead($lead), 403);
        // #12: lead 'Gọi lại sau' đang khoá 1 ngày → chờ tự về địa điểm, không cho chia lại.
        if ($lead->classification === 'goi_lai_sau' && $lead->recall_at && $lead->recall_at->isFuture()) {
            session()->flash('error', 'Lead "Gọi lại sau" đang khoá cho tele/sale đến ' . $lead->recall_at->format('H:i d/m') . '. Chờ tự về kho địa điểm.');
            return;
        }
        $this->assigningLeadId = $leadId;
        $this->poolingLeadId = null;
        $this->assignUserId = '';
    }

    public function confirmAssign(): void
    {
        $this->upsGuard();
        $this->validate(['assignUserId' => 'required|exists:users,id'], [], ['assignUserId' => 'sale nhận']);

        $lead = Lead::findOrFail($this->assigningLeadId);
        abort_unless($this->canDistributeLead($lead), 403);
        $user = User::findOrFail((int) $this->assignUserId);
        app(DistributionEngine::class)->manualAssign($lead, $user, auth()->id());

        // Phase 6.6: nếu người chia có quyền recall, áp mốc thu hồi
        if (auth()->user()->hasPermission('lead.recall')) {
            $fresh = $lead->refresh();
            $policyOrg = $fresh->org_unit_id ? \App\Models\OrgUnit::find($fresh->org_unit_id) : null;
            $policy = $policyOrg ? \App\Services\RecallPolicyResolver::for($policyOrg) : null;
            if ($this->assignRecallMode === 'permanent' && ($policy === null || $policy['allow_permanent_assignment'])) {
                $fresh->update(['is_permanent_assignment' => true, 'recall_at' => null]);
            } elseif ($this->assignRecallMode === 'custom' && $this->assignRecallDays > 0) {
                $fresh->update(['is_permanent_assignment' => false, 'recall_at' => now()->addDays($this->assignRecallDays)]);
            } elseif ($policy && $policy['recall_after_days']) {
                $fresh->update(['is_permanent_assignment' => false, 'recall_at' => now()->addDays($policy['recall_after_days'])]);
            }
        }

        $this->assigningLeadId = null;
        $this->assignRecallMode = 'default';
        $this->assignRecallDays = null;
        session()->flash('status', "Đã chia tay {$lead->name} cho {$user->name}.");
    }

    public function startPool(int $leadId): void
    {
        abort_unless($this->canDistributeLead(Lead::findOrFail($leadId)), 403);
        $this->poolingLeadId = $leadId;
        $this->assigningLeadId = null;
        $this->poolOrgId = '';
    }

    public function confirmPool(): void
    {
        $this->upsGuard();
        if ($this->poolOrgId === '') {
            $this->addError('poolOrgId', 'Chọn kho để chuyển.');
            return;
        }

        $lead = Lead::findOrFail($this->poolingLeadId);
        abort_unless($this->canDistributeLead($lead), 403);
        $this->moveOne($lead, $this->poolOrgId, auth()->id());

        $this->poolingLeadId = null;
        session()->flash('status', "Đã chuyển {$lead->name} về "
            . ($this->poolOrgId === 'common' ? 'kho chung công ty.' : 'kho phòng/team.'));
    }

    public function recall(int $leadId): void
    {
        abort_unless(auth()->user()->hasPermission('lead.recall'), 403);
        $lead = Lead::findOrFail($leadId);
        if ($lead->classification === 'goi_lai_sau' && $lead->recall_at && $lead->recall_at->isFuture()) {
            session()->flash('error', 'Lead "Gọi lại sau" đang khoá đến ' . $lead->recall_at->format('H:i d/m') . ', không thu hồi được.');
            return;
        }
        app(DistributionEngine::class)->recall($lead, Lead::POOL_TEAM, auth()->id());
        session()->flash('status', "Đã thu hồi {$lead->name} về kho địa điểm.");
    }

    // 2026-08-13: bỏ pullLead ("Kéo về tôi") theo yêu cầu — CM/DM không tự kéo lead
    //   về owner mình, phải chia rõ ràng cho sale.

    // ---------- Thao tác hàng loạt ----------

    /** Lead hợp lệ trong lựa chọn, thuộc đúng tab hiện tại. */
    private function selectedLeads()
    {
        // 2026-08-10 fix: $this->tab là key ('company/branch/...'), còn pool_level DB
        //   là enum ('common/team/personal'). Trước đây where('pool_level', $this->tab)
        //   luôn 0 hit → bulk chia không tác dụng.
        $poolLevel = self::TAB_KINDS[$this->tab]['pool_level'] ?? null;
        if (! $poolLevel) return collect();
        return Lead::whereIn('id', $this->selected)->where('pool_level', $poolLevel)->get();
    }

    public function bulkAssign(): void
    {
        $this->upsGuard();
        $this->validate(['bulkUserId' => 'required|exists:users,id'], [], ['bulkUserId' => 'sale nhận']);

        $user = User::findOrFail((int) $this->bulkUserId);
        $engine = app(DistributionEngine::class);
        $canRecall = auth()->user()->hasPermission('lead.recall');
        $n = 0;
        foreach ($this->selectedLeads() as $lead) {
            abort_unless($this->canDistributeLead($lead), 403);
            $engine->manualAssign($lead, $user, auth()->id());
            // 2026-08-19: áp recall mode cho từng lead — mirror confirmAssign per-lead.
            if ($canRecall) {
                $fresh = $lead->refresh();
                $policyOrg = $fresh->org_unit_id ? \App\Models\OrgUnit::find($fresh->org_unit_id) : null;
                $policy = $policyOrg ? \App\Services\RecallPolicyResolver::for($policyOrg) : null;
                if ($this->bulkRecallMode === 'permanent' && ($policy === null || $policy['allow_permanent_assignment'])) {
                    $fresh->update(['is_permanent_assignment' => true, 'recall_at' => null]);
                } elseif ($this->bulkRecallMode === 'custom' && $this->bulkRecallDays > 0) {
                    $fresh->update(['is_permanent_assignment' => false, 'recall_at' => now()->addDays($this->bulkRecallDays)]);
                } elseif ($policy && $policy['recall_after_days']) {
                    $fresh->update(['is_permanent_assignment' => false, 'recall_at' => now()->addDays($policy['recall_after_days'])]);
                }
            }
            $n++;
        }
        $this->bulkMode = '';
        $this->selected = [];
        $this->bulkRecallMode = 'default';
        $this->bulkRecallDays = null;
        session()->flash('status', "Đã chia tay {$n} lead cho {$user->name}.");
    }

    /**
     * 2026-08-13 — Chia tự động hàng loạt: dùng DistributionEngine::distribute
     * cho từng lead (rule-based, chia về team hoặc sale theo rule config).
     */
    public function bulkAutoDistribute(): void
    {
        $this->upsGuard();
        $engine = app(DistributionEngine::class);
        $n = 0; $fail = 0;
        foreach ($this->selectedLeads() as $lead) {
            abort_unless($this->canDistributeLead($lead), 403);
            try {
                $engine->distribute($lead);
                $lead->refresh();
                $lead->owner_id ? $n++ : $fail++;
            } catch (\Throwable $e) {
                $fail++;
            }
        }
        $this->bulkMode = '';
        $this->selected = [];
        session()->flash('status', "Chia tự động: {$n} lead đã có sale/kho, {$fail} không chia được (thiếu rule / UPS list rỗng).");
    }

    public function bulkPool(): void
    {
        $this->upsGuard();
        // Gate per-lead in the loop below via canDistributeLead().
        if ($this->bulkOrgId === '') {
            $this->addError('bulkOrgId', 'Chọn kho để chuyển.');
            return;
        }

        $n = 0;
        foreach ($this->selectedLeads() as $lead) {
            abort_unless($this->canDistributeLead($lead), 403);
            $this->moveOne($lead, $this->bulkOrgId, auth()->id());
            $n++;
        }
        $this->bulkMode = '';
        $this->selected = [];
        session()->flash('status', "Đã chuyển {$n} lead về "
            . ($this->bulkOrgId === 'common' ? 'kho chung công ty.' : 'kho phòng/team.'));
    }

    public function showDetail(int $leadId): void
    {
        $lead = Lead::findOrFail($leadId);
        abort_unless($this->tab === Lead::POOL_COMMON || $lead->isVisibleTo(auth()->user()), 403);
        $this->detailLeadId = $leadId;
    }

    public function with(): array
    {
        $user = auth()->user();
        $leads = $this->filtered()->paginate($this->perPage);
        $pageIds = $leads->pluck('id')->all();

        return [
            'leads' => $leads,
            'allPageSelected' => $pageIds !== [] && count(array_intersect($pageIds, $this->selected)) === count($pageIds),
            'counts' => collect(self::TAB_KINDS)->map(function ($spec, $key) use ($user) {
                // 2026-08-19: đồng bộ với query filtered() — visibleTo cho mọi tab.
                $q = Lead::where('pool_level', $spec['pool_level'])->visibleTo($user);
                if ($spec['pool_kind']) $q->whereHas('poolUnit', fn ($pq) => $pq->where('kind', $spec['pool_kind']));
                return $q->count();
            })->all(),
            'visibleTabs' => $this->visibleTabs(),
            'teamOptions' => \App\Models\PoolUnit::where('is_active', true)->where('depth', '>', 0)->orderBy('path')->get(),
            'poolOrgs' => $this->poolOrgs(),
            'assignableUsers' => (function () use ($user) {
                // 2026-08-19: siết scope — CM/TL/DM cơ sở chỉ chia được cho nhân sự trong
                //   phạm vi org của mình. Trước đây trả full list nationwide → CM ĐN thấy sale HN/HCM.
                //   Super admin (visibleOrgIds rỗng do scope tất cả) → không filter, thấy toàn công ty.
                $visibleOrgIds = $user->visibleOrgUnitIds();
                return User::where('status', 'active')
                    ->whereHas('assignments.role.permissions', fn ($q) => $q->where('key', 'lead.update'))
                    ->whereDoesntHave('assignments.role.permissions', fn ($q) => $q->whereIn('key', ['lead.distribute', 'lead.distribute_tele', 'lead.distribute_sale']))
                    ->when($visibleOrgIds !== [], fn ($q) => $q->whereHas('assignments', fn ($qq) => $qq
                        ->effective()->whereIn('org_unit_id', $visibleOrgIds)))
                    ->orderBy('name')
                    ->get();
            })(),
            'canDistribute' => $user->hasAnyPermission(['lead.distribute', 'lead.distribute_to_team', 'lead.distribute_to_sale']),
            'canRecall' => $user->hasPermission('lead.recall'),
            'canPull' => $user->hasPermission('lead.pull_pool'),
            'detailLead' => $this->detailLeadId
                ? Lead::with(['owner', 'receiver', 'orgUnit', 'customValues.field'])->find($this->detailLeadId)
                : null,
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <h1 class="text-3xl font-bold mb-1">Quản lý Kho Lead tập trung</h1>
        <p class="text-sm text-ink/60">5 cấp kho: Công ty → Địa điểm → Cơ sở → Phòng ban → Cá nhân. Sale cá nhân chỉ thấy kho của mình.</p>
    </div>

    @if (session('status'))
        <p class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-4 py-2">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p class="mb-4 text-sm text-red-600 bg-red-50 border border-red-200 rounded-md px-4 py-2">{{ session('error') }}</p>
    @endif

    {{-- 2026-08-05: 5 tab kho (Công ty / Chi nhánh / Địa điểm / Cơ sở / Cá nhân). Sale cá nhân chỉ thấy tab Cá nhân. --}}
    <div class="border-b border-gold-200 mb-5 flex gap-1 text-sm font-semibold uppercase tracking-wide flex-wrap">
        @foreach ($visibleTabs as $key => $spec)
            <button wire:click="switchTab('{{ $key }}')"
                    class="px-4 py-3 border-b-2 -mb-px {{ $tab === $key ? 'border-gold-600 text-gold-700' : 'border-transparent text-ink/50 hover:text-gold-700' }}">
                {{ $spec['label'] }}
                <span class="ml-1 text-xs bg-gold-100 text-gold-800 px-2 py-0.5 rounded-full">{{ $counts[$key] ?? 0 }}</span>
            </button>
        @endforeach
        <div class="flex-1"></div>
        {{-- Filter phòng ban (kho Cơ sở = department) — chỉ khi tab='department'. --}}
        @if ($tab === 'department')
            <div class="flex flex-wrap items-center gap-2 mb-2">
                <select wire:model.live="fBranchId" class="border border-gold-200 rounded-md px-2.5 py-1.5 text-sm bg-white">
                    <option value="">Tất cả Chi nhánh</option>
                    @foreach (\App\Models\PoolUnit::where('is_active',true)->where('kind','branch')->orderBy('sort')->orderBy('name')->get() as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="fFacilityId" @disabled(! $fBranchId) class="border border-gold-200 rounded-md px-2.5 py-1.5 text-sm bg-white disabled:bg-slate-100">
                    <option value="">{{ $fBranchId ? 'Tất cả Địa điểm' : '—' }}</option>
                    @foreach (($fBranchId ? \App\Models\PoolUnit::where('is_active',true)->where('kind','facility')->where('parent_id',$fBranchId)->orderBy('sort')->orderBy('name')->get() : []) as $f)
                        <option value="{{ $f->id }}">{{ $f->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="fDepartmentId" @disabled(! $fFacilityId) class="border border-gold-200 rounded-md px-2.5 py-1.5 text-sm bg-white disabled:bg-slate-100">
                    <option value="">{{ $fFacilityId ? 'Tất cả Cơ sở' : '—' }}</option>
                    @foreach (($fFacilityId ? \App\Models\PoolUnit::where('is_active',true)->where('kind','department')->where('parent_id',$fFacilityId)->orderBy('sort')->orderBy('name')->get() : []) as $d)
                        <option value="{{ $d->id }}">{{ $d->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </div>

    {{-- Thanh thao tác hàng loạt --}}
    @if ($tab !== 'personal' && $canDistribute && count($selected) > 0)
        <div class="mb-3 bg-gold-50 border border-gold-200 rounded-lg px-4 py-3 flex flex-wrap items-center gap-3">
            <span class="text-sm font-semibold text-gold-800">Đã chọn {{ count($selected) }} lead</span>
            <div class="flex-1"></div>
            @if ($bulkMode === 'assign')
                <select wire:model="bulkUserId" class="border border-gold-200 rounded-md px-2 py-1.5 text-xs bg-white focus:outline-none focus:border-gold-500">
                    <option value="">— chọn sale —</option>
                    @foreach ($assignableUsers as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                </select>
                @if (auth()->user()->hasPermission('lead.recall'))
                    <select wire:model.live="bulkRecallMode" class="border border-gold-200 rounded-md px-2 py-1.5 text-xs bg-white">
                        <option value="default">Mặc định (theo Quy tắc)</option>
                        <option value="custom">Thu hồi sau X ngày…</option>
                        <option value="permanent">Chia vĩnh viễn</option>
                    </select>
                    @if ($bulkRecallMode === 'custom')
                        <input type="number" min="1" wire:model="bulkRecallDays" placeholder="ngày" class="w-20 border border-gold-200 rounded-md px-2 py-1.5 text-xs">
                    @endif
                @endif
                <button wire:click="bulkAssign" class="text-xs font-semibold bg-gold-600 text-white px-3 py-1.5 rounded-md">OK</button>
                <button wire:click="$set('bulkMode', '')" class="text-xs text-ink/50">Hủy</button>
                @error('bulkUserId')<p class="w-full text-xs text-red-600">{{ $message }}</p>@enderror
            @elseif ($bulkMode === 'pool')
                <select wire:model="bulkOrgId" class="border border-gold-200 rounded-md px-2.5 py-1.5 text-sm bg-white">
                    <option value="">— chọn kho —</option>
                    <option value="common">Kho chung công ty</option>
                    {{-- 2026-08-03: group by branch cho dễ đọc thay flat. Inline select không đủ chỗ cho 3 cascade → dùng optgroup. --}}
                    @foreach (\App\Models\PoolUnit::where('is_active',true)->where('kind','branch')->orderBy('sort')->get() as $__b)
                        <optgroup label="📍 {{ $__b->name }}">
                            <option value="{{ $__b->id }}">Kho địa điểm: {{ $__b->name }}</option>
                            @foreach ($poolOrgs->where('parent_id', $__b->id) as $__f)
                                <option value="{{ $__f->id }}">&nbsp;&nbsp;📍 Địa điểm: {{ $__f->name }}</option>
                                @foreach ($poolOrgs->where('parent_id', $__f->id) as $__d)
                                    <option value="{{ $__d->id }}">&nbsp;&nbsp;&nbsp;&nbsp;👥 {{ $__d->name }}</option>
                                @endforeach
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <button wire:click="bulkPool" class="text-sm font-semibold bg-gold-600 text-white px-4 py-1.5 rounded-md">Xác nhận chuyển kho</button>
                <button wire:click="$set('bulkMode', '')" class="text-sm text-ink/50">Hủy</button>
                @error('bulkOrgId')<p class="w-full text-xs text-red-600">{{ $message }}</p>@enderror
            @else
                <button wire:click="$set('bulkMode', 'assign')" class="text-sm font-semibold text-gold-700 border border-gold-300 hover:bg-white px-4 py-1.5 rounded-md">Chia thủ công hàng loạt</button>
                <button wire:click="$set('bulkMode', 'pool')" class="text-sm font-semibold text-gold-700 border border-gold-300 hover:bg-white px-4 py-1.5 rounded-md">Chia về kho hàng loạt</button>
                {{-- 2026-08-13: chia tự động theo rule engine cho toàn bộ lead đã tick. --}}
                <button wire:click="bulkAutoDistribute" wire:confirm="Chia tự động {{ count($selected) }} lead theo rule engine (rule chia số)?"
                        class="text-sm font-semibold bg-emerald-600 text-white hover:bg-emerald-700 px-4 py-1.5 rounded-md">
                    Chia tự động hàng loạt
                </button>
                <button wire:click="$set('selected', [])" class="text-sm text-ink/50">Bỏ chọn</button>
            @endif
        </div>
    @endif

    <div class="bg-white border border-gold-200 rounded-xl shadow-card overflow-x-auto">
        <table class="w-full text-sm whitespace-nowrap">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wider text-ink/50 bg-gold-50/60">
                    @if ($tab !== 'personal' && $canDistribute)
                        <th class="px-4 py-3 w-10"><input type="checkbox" wire:click="toggleAllOnPage" @checked($allPageSelected) class="rounded border-gold-300 text-gold-600 focus:ring-gold-500 w-4 h-4"></th>
                    @endif
                    <th class="px-4 py-3 font-semibold">Mã KH</th>
                    <th class="px-4 py-3 font-semibold">Tên</th>
                    <th class="px-4 py-3 font-semibold">SĐT</th>
                    <th class="px-4 py-3 font-semibold">Khu vực / Nguồn</th>
                    @if ($tab !== 'common')<th class="px-4 py-3 font-semibold">Team</th>@endif
                    @if ($tab === 'personal')<th class="px-4 py-3 font-semibold">Đang giữ</th><th class="px-4 py-3 font-semibold">Nhận lúc</th>@endif
                    <th class="px-4 py-3 font-semibold text-right">Hành động</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gold-100">
                @forelse ($leads as $lead)
                    <tr class="hover:bg-gold-50/40">
                        @if ($tab !== 'personal' && $canDistribute)
                            <td class="px-4 py-3">
                                @if ($this->canDistributeLead($lead))
                                    <input type="checkbox" value="{{ $lead->id }}" wire:model.live="selected" class="rounded border-gold-300 text-gold-600 focus:ring-gold-500 w-4 h-4">
                                @endif
                            </td>
                        @endif
                        <td class="px-4 py-3 font-mono text-xs text-gold-700">
                            <a href="{{ route('leads.show', $lead) }}" class="hover:underline">{{ $lead->code ?: '#' . $lead->id }}</a>
                        </td>
                        <td class="px-4 py-3">
                            <button wire:click="showDetail({{ $lead->id }})" class="font-semibold text-gold-700 hover:underline">{{ $lead->name }}</button>
                        </td>
                        <td class="px-4 py-3 font-mono">{{ $lead->phoneFor(auth()->user()) }}</td>
                        <td class="px-4 py-3 text-ink/60">{{ $lead->region ?: '—' }}</td>
                        @if ($tab !== 'common')<td class="px-4 py-3">{{ $lead->poolUnit?->name ?: '—' }}</td>@endif
                        @if ($tab === 'personal')
                            <td class="px-4 py-3 font-semibold text-gold-700">{{ $lead->owner?->name }}</td>
                            <td class="px-4 py-3 text-ink/50">{{ $lead->assigned_at?->diffForHumans() }}</td>
                        @endif
                        <td class="px-4 py-3 text-right">
                            @if ($assigningLeadId === $lead->id)
                                <span class="inline-flex flex-wrap items-center gap-2">
                                    <select wire:model="assignUserId" class="border border-gold-200 rounded-md px-2 py-1.5 text-xs bg-white focus:outline-none focus:border-gold-500">
                                        <option value="">— chọn sale —</option>
                                        @foreach ($assignableUsers as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                                    </select>
                                    @if (auth()->user()->hasPermission('lead.recall'))
                                        <select wire:model.live="assignRecallMode" class="border border-gold-200 rounded-md px-2 py-1.5 text-xs bg-white">
                                            <option value="default">Mặc định (theo Quy tắc)</option>
                                            <option value="custom">Thu hồi sau X ngày…</option>
                                            <option value="permanent">Chia vĩnh viễn</option>
                                        </select>
                                        @if ($assignRecallMode === 'custom')
                                            <input type="number" min="1" wire:model="assignRecallDays" placeholder="ngày" class="w-20 border border-gold-200 rounded-md px-2 py-1.5 text-xs">
                                        @endif
                                    @endif
                                    <button wire:click="confirmAssign" class="text-xs font-semibold bg-gold-600 text-white px-3 py-1.5 rounded-md">OK</button>
                                    <button wire:click="$set('assigningLeadId', null)" class="text-xs text-ink/50">Hủy</button>
                                </span>
                                @error('assignUserId')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                            @elseif ($poolingLeadId === $lead->id)
                                <span class="inline-flex items-center gap-2">
                                    <select wire:model="poolOrgId" class="border border-gold-200 rounded-md px-2 py-1.5 text-xs bg-white focus:outline-none focus:border-gold-500">
                                        <option value="">— chọn kho —</option>
                                        <option value="common">Kho chung công ty</option>
                                        {{-- 2026-08-03: group by branch cho dễ đọc thay flat. Inline select không đủ chỗ cho 3 cascade → dùng optgroup. --}}
                    @foreach (\App\Models\PoolUnit::where('is_active',true)->where('kind','branch')->orderBy('sort')->get() as $__b)
                        <optgroup label="📍 {{ $__b->name }}">
                            <option value="{{ $__b->id }}">Kho địa điểm: {{ $__b->name }}</option>
                            @foreach ($poolOrgs->where('parent_id', $__b->id) as $__f)
                                <option value="{{ $__f->id }}">&nbsp;&nbsp;📍 Địa điểm: {{ $__f->name }}</option>
                                @foreach ($poolOrgs->where('parent_id', $__f->id) as $__d)
                                    <option value="{{ $__d->id }}">&nbsp;&nbsp;&nbsp;&nbsp;👥 {{ $__d->name }}</option>
                                @endforeach
                            @endforeach
                        </optgroup>
                    @endforeach
                                    </select>
                                    <button wire:click="confirmPool" class="text-xs font-semibold bg-gold-600 text-white px-3 py-1.5 rounded-md">OK</button>
                                    <button wire:click="$set('poolingLeadId', null)" class="text-xs text-ink/50">Hủy</button>
                                </span>
                                @error('poolOrgId')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                            @else
                                @if ($tab !== 'personal' && $canDistribute && $this->canDistributeLead($lead))
                                    {{-- 2026-08-19: thứ tự đồng bộ với bulk (thanh trên): Thủ công → Về kho → Tự động. --}}
                                    <button wire:click="startAssign({{ $lead->id }})" class="text-xs font-semibold text-ink/60 border border-gold-200 hover:bg-gold-50 px-3 py-1.5 rounded-md">Chia thủ công</button>
                                    <button wire:click="startPool({{ $lead->id }})" class="text-xs font-semibold text-ink/60 border border-gold-200 hover:bg-gold-50 px-3 py-1.5 rounded-md">Chia về kho</button>
                                    <button wire:click="autoDistribute({{ $lead->id }})" class="text-xs font-semibold text-gold-700 border border-gold-300 hover:bg-gold-50 px-3 py-1.5 rounded-md" title="Chạy engine theo rule">Chia tự động</button>
                                @endif
                                {{-- 2026-08-13: bỏ nút "Kéo về tôi" — CM/DM phải chia rõ cho sale, không tự kéo. --}}
                                @php
                                    // #12: lead 'Gọi lại sau' đang trong lock 1 ngày → CM/DM không được thu hồi/chia lại,
                                    // chờ ProcessLeadRecalls tự đưa về kho địa điểm.
                                    $_goiLaiLocked = $lead->classification === 'goi_lai_sau'
                                        && $lead->recall_at && $lead->recall_at->isFuture();
                                @endphp
                                @if ($tab === 'personal' && $canRecall && ! $_goiLaiLocked)
                                    <button wire:click="recall({{ $lead->id }})" wire:confirm="Thu hồi lead này về kho địa điểm?" class="text-xs font-semibold text-red-700 border border-red-200 hover:bg-red-50 px-3 py-1.5 rounded-md">Thu hồi</button>
                                @endif
                                @if ($tab === 'personal' && $canDistribute && ! $_goiLaiLocked)
                                    <button wire:click="startAssign({{ $lead->id }})" class="text-xs font-semibold text-ink/60 border border-gold-200 hover:bg-gold-50 px-3 py-1.5 rounded-md">Chuyển người</button>
                                @endif
                                @if ($_goiLaiLocked)
                                    <span class="text-[10px] text-amber-700 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded" title="Lead 'Gọi lại sau' khoá trong tay tele/sale đến {{ $lead->recall_at->format('d/m H:i') }}">🔒 Khoá đến {{ $lead->recall_at->format('H:i d/m') }}</span>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-10 text-center text-ink/40">Kho trống.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-5 py-4 border-t border-gold-100 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2 text-sm text-ink/60">
                <span>Hiển thị</span>
                <select wire:model.live="perPage" class="border border-gold-200 rounded-md px-2 py-1.5 text-sm bg-white focus:outline-none focus:border-gold-500">
                    @foreach ([20, 50, 100, 200] as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach
                </select>
                <span>/ trang</span>
            </div>
            {{ $leads->links() }}
        </div>
    </div>

    {{-- Popup chi tiết khách --}}
    @if ($detailLead)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/40" wire:click="$set('detailLeadId', null)"></div>
            <div class="relative bg-white rounded-xl shadow-xl border border-gold-200 w-full max-w-2xl p-7 max-h-[90vh] overflow-y-auto">
                <div class="flex items-start justify-between gap-3 mb-4">
                    <div>
                        <h3 class="text-xl font-bold">{{ $detailLead->name }}</h3>
                        <p class="text-sm text-ink/50 font-mono">{{ $detailLead->code ?: '#' . $detailLead->id }}</p>
                    </div>
                    <a href="{{ route('leads.show', $detailLead) }}" class="text-sm font-semibold text-gold-700 hover:underline shrink-0">Mở trang đầy đủ →</a>
                </div>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gold-100">
                        @php
                            $rows = [
                                'SĐT' => $detailLead->phoneFor(auth()->user()),
                                'Ngày thu thập' => $detailLead->received_date?->format('d/m/Y'),
                                'Khu vực' => $detailLead->region ?: '—',
                                'Camp' => $detailLead->camp ?: '—',
                                'Phân loại' => $detailLead->classificationLabel(),
                                'Kho' => ucfirst($detailLead->pool_level) . ($detailLead->poolUnit ? ' · ' . $detailLead->poolUnit->name : ''),
                                'Người thu thập' => $detailLead->receiver?->name ?? '—',
                                'Người phụ trách' => $detailLead->owner?->name ?? '— (chưa chia)',
                                'Ghi chú' => $detailLead->note ?: '—',
                            ];
                        @endphp
                        @foreach ($rows as $label => $val)
                            <tr>
                                <td class="py-2 pr-4 text-ink/50 w-40 align-top">{{ $label }}</td>
                                <td class="py-2 font-medium">{{ $val }}</td>
                            </tr>
                        @endforeach
                        @foreach ($detailLead->customValues as $cv)
                            @php $cf = $cv->field; @endphp
                            @if ($cf)
                                <tr>
                                    <td class="py-2 pr-4 text-ink/50 align-top">{{ $cf->label }}</td>
                                    <td class="py-2 font-medium">{{ $cf->field_type === 'select' ? $cf->optionLabel((string) $cv->value) : ($cf->field_type === 'tick' ? ((string) $cv->value !== '' ? '✓' : '—') : $cv->value) }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
                <div class="flex justify-end mt-6">
                    <button wire:click="$set('detailLeadId', null)" class="text-sm text-ink/60 px-4 py-2">Đóng</button>
                </div>
            </div>
        </div>
    @endif
</div>
