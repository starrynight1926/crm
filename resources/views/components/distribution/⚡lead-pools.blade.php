<?php

use App\Models\CustomField;
use App\Models\Lead;
use App\Models\OrgUnit;
use App\Models\User;
use App\Services\DistributionEngine;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $tab = Lead::POOL_COMMON; // common / team / personal

    public string $fOrgUnit = '';

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

    // Popup chi tiết
    public ?int $detailLeadId = null;

    public function switchTab(string $tab): void
    {
        abort_unless(in_array($tab, [Lead::POOL_COMMON, Lead::POOL_TEAM, Lead::POOL_PERSONAL]), 422);
        $this->tab = $tab;
        $this->assigningLeadId = null;
        $this->poolingLeadId = null;
        $this->bulkMode = '';
        $this->selected = [];
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    /** Query kho theo tab + filter, chưa phân trang. */
    private function filtered()
    {
        $user = auth()->user();

        return Lead::query()
            ->where('pool_level', $this->tab)
            ->with(['owner', 'orgUnit'])
            ->when($this->tab !== Lead::POOL_COMMON, fn ($q) => $q->visibleTo($user))
            ->when($this->tab === Lead::POOL_TEAM && $this->fOrgUnit, fn ($q) => $q->where('org_unit_id', $this->fOrgUnit))
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

    /** Phòng/team (loại node gốc công ty) được phép đưa lead vào kho, theo phạm vi. */
    private function poolOrgs()
    {
        $ids = auth()->user()->visibleOrgUnitIds();

        return OrgUnit::where('active', true)
            ->where('depth', '>', 0) // node gốc "Công ty" = kho chung, dùng option riêng
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
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

    public function autoDistribute(int $leadId): void
    {
        abort_unless(auth()->user()->hasPermission('lead.distribute'), 403);
        $lead = Lead::findOrFail($leadId);
        app(DistributionEngine::class)->distribute($lead);

        $lead->refresh();
        session()->flash('status', $lead->owner_id
            ? "Đã chia {$lead->name} cho {$lead->owner->name}."
            : ($lead->pool_level === Lead::POOL_TEAM
                ? "{$lead->name} đã về kho team {$lead->orgUnit?->name}, chưa có rule/sale nhận tiếp."
                : "Không có rule nào khớp — {$lead->name} vẫn ở kho chung."));
    }

    public function startAssign(int $leadId): void
    {
        abort_unless(auth()->user()->hasPermission('lead.distribute'), 403);
        $this->assigningLeadId = $leadId;
        $this->poolingLeadId = null;
        $this->assignUserId = '';
    }

    public function confirmAssign(): void
    {
        abort_unless(auth()->user()->hasPermission('lead.distribute'), 403);
        $this->validate(['assignUserId' => 'required|exists:users,id'], [], ['assignUserId' => 'sale nhận']);

        $lead = Lead::findOrFail($this->assigningLeadId);
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
        abort_unless(auth()->user()->hasPermission('lead.distribute'), 403);
        $this->poolingLeadId = $leadId;
        $this->assigningLeadId = null;
        $this->poolOrgId = '';
    }

    public function confirmPool(): void
    {
        abort_unless(auth()->user()->hasPermission('lead.distribute'), 403);
        if ($this->poolOrgId === '') {
            $this->addError('poolOrgId', 'Chọn kho để chuyển.');
            return;
        }

        $lead = Lead::findOrFail($this->poolingLeadId);
        $this->moveOne($lead, $this->poolOrgId, auth()->id());

        $this->poolingLeadId = null;
        session()->flash('status', "Đã chuyển {$lead->name} về "
            . ($this->poolOrgId === 'common' ? 'kho chung công ty.' : 'kho phòng/team.'));
    }

    public function recall(int $leadId): void
    {
        abort_unless(auth()->user()->hasPermission('lead.recall'), 403);
        $lead = Lead::findOrFail($leadId);
        app(DistributionEngine::class)->recall($lead, Lead::POOL_TEAM, auth()->id());
        session()->flash('status', "Đã thu hồi {$lead->name} về kho team.");
    }

    public function pullLead(int $leadId): void
    {
        abort_unless(auth()->user()->hasPermission('lead.pull_pool'), 403);
        $lead = Lead::findOrFail($leadId);
        if ($lead->pool_level === Lead::POOL_PERSONAL) {
            session()->flash('error', 'Lead đã có người giữ.');
            return;
        }
        app(DistributionEngine::class)->pull($lead, auth()->user());
        session()->flash('status', "Đã kéo {$lead->name} về kho của bạn.");
    }

    // ---------- Thao tác hàng loạt ----------

    /** Lead hợp lệ trong lựa chọn, thuộc đúng tab hiện tại. */
    private function selectedLeads()
    {
        return Lead::whereIn('id', $this->selected)->where('pool_level', $this->tab)->get();
    }

    public function bulkAssign(): void
    {
        abort_unless(auth()->user()->hasPermission('lead.distribute'), 403);
        $this->validate(['bulkUserId' => 'required|exists:users,id'], [], ['bulkUserId' => 'sale nhận']);

        $user = User::findOrFail((int) $this->bulkUserId);
        $engine = app(DistributionEngine::class);
        $n = 0;
        foreach ($this->selectedLeads() as $lead) {
            $engine->manualAssign($lead, $user, auth()->id());
            $n++;
        }
        $this->bulkMode = '';
        $this->selected = [];
        session()->flash('status', "Đã chia tay {$n} lead cho {$user->name}.");
    }

    public function bulkPool(): void
    {
        abort_unless(auth()->user()->hasPermission('lead.distribute'), 403);
        if ($this->bulkOrgId === '') {
            $this->addError('bulkOrgId', 'Chọn kho để chuyển.');
            return;
        }

        $n = 0;
        foreach ($this->selectedLeads() as $lead) {
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
            'counts' => [
                Lead::POOL_COMMON => Lead::where('pool_level', Lead::POOL_COMMON)->count(),
                Lead::POOL_TEAM => Lead::where('pool_level', Lead::POOL_TEAM)->visibleTo($user)->count(),
                Lead::POOL_PERSONAL => Lead::where('pool_level', Lead::POOL_PERSONAL)->visibleTo($user)->count(),
            ],
            'teamOptions' => OrgUnit::where('active', true)->orderBy('path')->get(),
            'poolOrgs' => $this->poolOrgs(),
            'assignableUsers' => User::where('status', 'active')
                // Chỉ user "nhận lead": role có lead.update NHƯNG không có quyền chia số
                // → loại Team trực page (up lead), CM booking/sale, TL, DM, Admin, Manager (họ chia số, không nhận).
                ->whereHas('assignments.role.permissions', fn ($q) => $q->where('key', 'lead.update'))
                ->whereDoesntHave('assignments.role.permissions', fn ($q) => $q->whereIn('key', ['lead.distribute', 'lead.distribute_booking', 'lead.distribute_sale']))
                ->orderBy('name')
                ->get(),
            'canDistribute' => $user->hasPermission('lead.distribute'),
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
        <p class="text-sm text-ink/60">3 cấp kho: chung → team → cá nhân. Lead về là chia ngay theo rule; kho là nơi xử lý phần chưa chia được.</p>
    </div>

    @if (session('status'))
        <p class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-4 py-2">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p class="mb-4 text-sm text-red-600 bg-red-50 border border-red-200 rounded-md px-4 py-2">{{ session('error') }}</p>
    @endif

    {{-- Tabs 3 kho --}}
    <div class="border-b border-gold-200 mb-5 flex gap-1 text-sm font-semibold uppercase tracking-wide">
        @foreach ([Lead::POOL_COMMON => 'Kho chung', Lead::POOL_TEAM => 'Kho team', Lead::POOL_PERSONAL => 'Kho cá nhân'] as $key => $label)
            <button wire:click="switchTab('{{ $key }}')"
                    class="px-4 py-3 border-b-2 -mb-px {{ $tab === $key ? 'border-gold-600 text-gold-700' : 'border-transparent text-ink/50 hover:text-gold-700' }}">
                {{ $label }}
                <span class="ml-1 text-xs bg-gold-100 text-gold-800 px-2 py-0.5 rounded-full">{{ $counts[$key] }}</span>
            </button>
        @endforeach
        <div class="flex-1"></div>
        @if ($tab === Lead::POOL_TEAM)
            <select wire:model.live="fOrgUnit" class="border border-gold-200 rounded-md px-3 py-1.5 text-sm bg-white font-normal normal-case mb-2 focus:outline-none focus:border-gold-500">
                <option value="">Tất cả team</option>
                @foreach ($teamOptions as $unit)
                    <option value="{{ $unit->id }}">{{ str_repeat('— ', $unit->depth) }}{{ $unit->name }}</option>
                @endforeach
            </select>
        @endif
    </div>

    {{-- Thanh thao tác hàng loạt --}}
    @if ($tab !== 'personal' && $canDistribute && count($selected) > 0)
        <div class="mb-3 bg-gold-50 border border-gold-200 rounded-lg px-4 py-3 flex flex-wrap items-center gap-3">
            <span class="text-sm font-semibold text-gold-800">Đã chọn {{ count($selected) }} lead</span>
            <div class="flex-1"></div>
            @if ($bulkMode === 'assign')
                <select wire:model="bulkUserId" class="border border-gold-200 rounded-md px-2.5 py-1.5 text-sm bg-white">
                    <option value="">— chọn sale —</option>
                    @foreach ($assignableUsers as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                </select>
                <button wire:click="bulkAssign" class="text-sm font-semibold bg-gold-600 text-white px-4 py-1.5 rounded-md">Xác nhận chia tay</button>
                <button wire:click="$set('bulkMode', '')" class="text-sm text-ink/50">Hủy</button>
                @error('bulkUserId')<p class="w-full text-xs text-red-600">{{ $message }}</p>@enderror
            @elseif ($bulkMode === 'pool')
                <select wire:model="bulkOrgId" class="border border-gold-200 rounded-md px-2.5 py-1.5 text-sm bg-white">
                    <option value="">— chọn kho —</option>
                    <option value="common">Kho chung công ty</option>
                    @foreach ($poolOrgs as $o)<option value="{{ $o->id }}">Kho {{ str_repeat('— ', $o->depth) }}{{ $o->name }}</option>@endforeach
                </select>
                <button wire:click="bulkPool" class="text-sm font-semibold bg-gold-600 text-white px-4 py-1.5 rounded-md">Xác nhận chuyển kho</button>
                <button wire:click="$set('bulkMode', '')" class="text-sm text-ink/50">Hủy</button>
                @error('bulkOrgId')<p class="w-full text-xs text-red-600">{{ $message }}</p>@enderror
            @else
                <button wire:click="$set('bulkMode', 'assign')" class="text-sm font-semibold text-gold-700 border border-gold-300 hover:bg-white px-4 py-1.5 rounded-md">Chia thủ công hàng loạt</button>
                <button wire:click="$set('bulkMode', 'pool')" class="text-sm font-semibold text-gold-700 border border-gold-300 hover:bg-white px-4 py-1.5 rounded-md">Chia về kho hàng loạt</button>
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
                            <td class="px-4 py-3"><input type="checkbox" value="{{ $lead->id }}" wire:model.live="selected" class="rounded border-gold-300 text-gold-600 focus:ring-gold-500 w-4 h-4"></td>
                        @endif
                        <td class="px-4 py-3 font-mono text-xs text-gold-700">
                            <a href="{{ route('leads.show', $lead) }}" class="hover:underline">{{ $lead->code ?: '#' . $lead->id }}</a>
                        </td>
                        <td class="px-4 py-3">
                            <button wire:click="showDetail({{ $lead->id }})" class="font-semibold text-gold-700 hover:underline">{{ $lead->name }}</button>
                        </td>
                        <td class="px-4 py-3 font-mono">{{ $lead->phoneFor(auth()->user()) }}</td>
                        <td class="px-4 py-3 text-ink/60">{{ $lead->region ?: '—' }} · {{ $lead->ad_source ?: '—' }}</td>
                        @if ($tab !== 'common')<td class="px-4 py-3">{{ $lead->orgUnit?->name ?: '—' }}</td>@endif
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
                                        @foreach ($poolOrgs as $o)<option value="{{ $o->id }}">Kho {{ str_repeat('— ', $o->depth) }}{{ $o->name }}</option>@endforeach
                                    </select>
                                    <button wire:click="confirmPool" class="text-xs font-semibold bg-gold-600 text-white px-3 py-1.5 rounded-md">OK</button>
                                    <button wire:click="$set('poolingLeadId', null)" class="text-xs text-ink/50">Hủy</button>
                                </span>
                                @error('poolOrgId')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                            @else
                                @if ($tab !== 'personal' && $canDistribute)
                                    <button wire:click="autoDistribute({{ $lead->id }})" class="text-xs font-semibold text-gold-700 border border-gold-300 hover:bg-gold-50 px-3 py-1.5 rounded-md" title="Chạy engine theo rule">Chia tự động</button>
                                    <button wire:click="startAssign({{ $lead->id }})" class="text-xs font-semibold text-ink/60 border border-gold-200 hover:bg-gold-50 px-3 py-1.5 rounded-md">Chia thủ công</button>
                                    <button wire:click="startPool({{ $lead->id }})" class="text-xs font-semibold text-ink/60 border border-gold-200 hover:bg-gold-50 px-3 py-1.5 rounded-md">Chia về kho</button>
                                @endif
                                @if ($tab !== 'personal' && $canPull)
                                    <button wire:click="pullLead({{ $lead->id }})" class="text-xs font-semibold text-green-700 border border-green-200 hover:bg-green-50 px-3 py-1.5 rounded-md">Kéo về tôi</button>
                                @endif
                                @if ($tab === 'personal' && $canRecall)
                                    <button wire:click="recall({{ $lead->id }})" wire:confirm="Thu hồi lead này về kho team?" class="text-xs font-semibold text-red-700 border border-red-200 hover:bg-red-50 px-3 py-1.5 rounded-md">Thu hồi</button>
                                @endif
                                @if ($tab === 'personal' && $canDistribute)
                                    <button wire:click="startAssign({{ $lead->id }})" class="text-xs font-semibold text-ink/60 border border-gold-200 hover:bg-gold-50 px-3 py-1.5 rounded-md">Chuyển người</button>
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
                                'Nguồn' => $detailLead->ad_source ?: '—',
                                'Khu vực' => $detailLead->region ?: '—',
                                'Camp' => $detailLead->camp ?: '—',
                                'Phân loại' => $detailLead->classificationLabel(),
                                'Kho' => ucfirst($detailLead->pool_level) . ($detailLead->orgUnit ? ' · ' . $detailLead->orgUnit->name : ''),
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
