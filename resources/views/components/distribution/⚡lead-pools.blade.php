<?php

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

    public ?int $assigningLeadId = null;

    public string $assignUserId = '';

    public function switchTab(string $tab): void
    {
        abort_unless(in_array($tab, [Lead::POOL_COMMON, Lead::POOL_TEAM, Lead::POOL_PERSONAL]), 422);
        $this->tab = $tab;
        $this->assigningLeadId = null;
        $this->resetPage();
    }

    /** Chạy engine chia tự động cho 1 lead đang nằm kho. */
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
        $this->assignUserId = '';
    }

    public function confirmAssign(): void
    {
        abort_unless(auth()->user()->hasPermission('lead.distribute'), 403);
        $this->validate(['assignUserId' => 'required|exists:users,id'], [], ['assignUserId' => 'sale nhận']);

        $lead = Lead::findOrFail($this->assigningLeadId);
        $user = User::findOrFail((int) $this->assignUserId);
        app(DistributionEngine::class)->manualAssign($lead, $user, auth()->id());

        $this->assigningLeadId = null;
        session()->flash('status', "Đã chia tay {$lead->name} cho {$user->name}.");
    }

    public function recall(int $leadId): void
    {
        abort_unless(auth()->user()->hasPermission('lead.recall'), 403);

        $lead = Lead::findOrFail($leadId);
        app(DistributionEngine::class)->recall($lead, Lead::POOL_TEAM, auth()->id());

        session()->flash('status', "Đã thu hồi {$lead->name} về kho team.");
    }

    public function pullLead(int $leadId): void // không đặt tên pull() — đụng Livewire\Component::pull()
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

    public function with(): array
    {
        $user = auth()->user();

        $leads = Lead::query()
            ->where('pool_level', $this->tab)
            ->with(['owner', 'orgUnit'])
            // Kho team/cá nhân: giới hạn theo data scope; kho chung ai có quyền vào màn này đều thấy (SĐT vẫn mask)
            ->when($this->tab !== Lead::POOL_COMMON, fn ($q) => $q->visibleTo($user))
            ->when($this->tab === Lead::POOL_TEAM && $this->fOrgUnit, fn ($q) => $q->where('org_unit_id', $this->fOrgUnit))
            ->orderByDesc('id')
            ->paginate(15);

        return [
            'leads' => $leads,
            'counts' => [
                Lead::POOL_COMMON => Lead::where('pool_level', Lead::POOL_COMMON)->count(),
                Lead::POOL_TEAM => Lead::where('pool_level', Lead::POOL_TEAM)->visibleTo($user)->count(),
                Lead::POOL_PERSONAL => Lead::where('pool_level', Lead::POOL_PERSONAL)->visibleTo($user)->count(),
            ],
            'teamOptions' => OrgUnit::where('active', true)->orderBy('path')->get(),
            'assignableUsers' => User::where('status', 'active')->orderBy('name')->get(),
            'canDistribute' => $user->hasPermission('lead.distribute'),
            'canRecall' => $user->hasPermission('lead.recall'),
            'canPull' => $user->hasPermission('lead.pull_pool'),
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

    <div class="bg-white border border-gold-200 rounded-xl shadow-card overflow-x-auto">
        <table class="w-full text-sm whitespace-nowrap">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wider text-ink/50 bg-gold-50/60">
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
                        <td class="px-4 py-3 font-mono text-xs text-gold-700">
                            <a href="{{ route('leads.show', $lead) }}" class="hover:underline">{{ $lead->code ?: '#' . $lead->id }}</a>
                        </td>
                        <td class="px-4 py-3 font-semibold">{{ $lead->name }}</td>
                        <td class="px-4 py-3 font-mono">{{ $lead->phoneFor(auth()->user()) }}</td>
                        <td class="px-4 py-3 text-ink/60">{{ $lead->region ?: '—' }} · {{ $lead->ad_source ?: '—' }}</td>
                        @if ($tab !== 'common')<td class="px-4 py-3">{{ $lead->orgUnit?->name ?: '—' }}</td>@endif
                        @if ($tab === 'personal')
                            <td class="px-4 py-3 font-semibold text-gold-700">{{ $lead->owner?->name }}</td>
                            <td class="px-4 py-3 text-ink/50">{{ $lead->assigned_at?->diffForHumans() }}</td>
                        @endif
                        <td class="px-4 py-3 text-right">
                            @if ($assigningLeadId === $lead->id)
                                <span class="inline-flex items-center gap-2">
                                    <select wire:model="assignUserId" class="border border-gold-200 rounded-md px-2 py-1.5 text-xs bg-white focus:outline-none focus:border-gold-500">
                                        <option value="">— chọn sale —</option>
                                        @foreach ($assignableUsers as $u)
                                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                                        @endforeach
                                    </select>
                                    <button wire:click="confirmAssign" class="text-xs font-semibold bg-gold-600 text-white px-3 py-1.5 rounded-md">OK</button>
                                    <button wire:click="$set('assigningLeadId', null)" class="text-xs text-ink/50">Hủy</button>
                                </span>
                                @error('assignUserId')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                            @else
                                @if ($tab !== 'personal' && $canDistribute)
                                    <button wire:click="autoDistribute({{ $lead->id }})" class="text-xs font-semibold text-gold-700 border border-gold-300 hover:bg-gold-50 px-3 py-1.5 rounded-md" title="Chạy engine theo rule">Chia tự động</button>
                                    <button wire:click="startAssign({{ $lead->id }})" class="text-xs font-semibold text-ink/60 border border-gold-200 hover:bg-gold-50 px-3 py-1.5 rounded-md">Chia tay</button>
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
                    <tr><td colspan="8" class="px-4 py-10 text-center text-ink/40">Kho trống.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-5 py-4 border-t border-gold-100">{{ $leads->links() }}</div>
    </div>
</div>
