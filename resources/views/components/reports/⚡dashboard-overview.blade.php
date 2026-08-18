<?php

use App\Models\Lead;
use App\Models\LeadStatusLog;
use App\Models\User;
use Livewire\Component;

new class extends Component
{
    // Filter cho "Lead hôm nay" ở cuối dashboard.
    public string $fPhase = '';
    public string $fSource = '';
    public string $fSearch = '';
    // 2026-08-09: chip filter Tất cả / Tele / Booking cho sale kiêm nhiều role trong ngày.
    public string $fRole = '';

    // 2026-08-06 (T16): dropdown per-row cho widget Kho số (lead_id => user_id).
    public array $poolAssignments = [];

    private function seesAllReports(): bool
    {
        return auth()->user()->hasPermission('report.view_all');
    }

    /**
     * 2026-08-09: nhân viên không có quyền chia số (VD sale) chỉ thấy leads MÌNH phụ trách trên dashboard —
     * không thấy leads của cả team. CM/Manager (có lead.distribute) giữ scope team qua visibleTo.
     */
    private function isPersonalScopeOnly(): bool
    {
        $u = auth()->user();
        return ! $u->hasPermission('report.view_all')
            && ! $u->hasPermission('lead.distribute');
    }

    private function reportLeadQuery()
    {
        $u = auth()->user();
        if ($this->seesAllReports()) return Lead::query();
        if ($this->isPersonalScopeOnly()) {
            return Lead::query()->where(function ($q) use ($u) {
                $q->where('owner_id', $u->id)->orWhere('receiver_id', $u->id);
            });
        }
        return Lead::visibleTo($u);
    }

    /**
     * Áp filter chip "Tất cả / Tele / Booking" theo pipeline_phase (vai trò user đang giữ với lead).
     *   Tele    = pipeline_phase='booking' (chăm sóc, đang đặt lịch)
     *   Booking = pipeline_phase='sale'    (đã book, chuyển sale close/tiếp đón)
     * KHÔNG dùng cột `phase` (CF 1-6) vì Trực Page tạo lead xong phase vẫn = 1 dù đã auto-assign owner.
     */
    private function applyRoleFilter($q)
    {
        if ($this->fRole === 'tele')    return $q->where('pipeline_phase', Lead::PHASE_BOOKING);
        if ($this->fRole === 'booking') return $q->where('pipeline_phase', Lead::PHASE_SALE);
        return $q;
    }

    /* ========== 2026-08-06 (T16) Widget "Kho số" ========== */

    public function canViewPool(): bool
    {
        return auth()->user()->hasPermission('lead.view_pool');
    }

    public function canPullPool(): bool
    {
        return auth()->user()->hasPermission('lead.pull_pool');
    }

    /**
     * Lead trong kho theo scope user:
     *   - Kho công ty (pool_level=common, org_unit_id=null) → mọi user có view_pool thấy.
     *   - Kho team (pool_level=team) → chỉ user có org_unit trong memberOrgUnitIds() thấy.
     * Owner_id null (còn trong kho, chưa gán cá nhân).
     */
    private function poolLeads()
    {
        if (! $this->canViewPool()) return collect();
        $orgIds = \App\Support\AdminScope::orgUnitIds();

        return Lead::query()
            ->whereNull('owner_id')
            ->where('pool_level', '!=', Lead::POOL_PERSONAL)
            ->where(function ($q) use ($orgIds) {
                $q->where('pool_level', Lead::POOL_COMMON);
                // orgIds=null (super admin toàn công ty) → không filter kho team.
                // orgIds=[]  → user không có scope → không thấy kho team.
                if ($orgIds === null) {
                    $q->orWhere('pool_level', Lead::POOL_TEAM);
                } elseif ($orgIds !== []) {
                    $q->orWhere(fn ($qq) => $qq->where('pool_level', Lead::POOL_TEAM)->whereIn('org_unit_id', $orgIds));
                }
            })
            ->with(['owner', 'receiver', 'importer'])
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }

    private function poolLeadsCount(): int
    {
        if (! $this->canViewPool()) return 0;
        $orgIds = \App\Support\AdminScope::orgUnitIds();
        return Lead::query()->whereNull('owner_id')
            ->where('pool_level', '!=', Lead::POOL_PERSONAL)
            ->where(function ($q) use ($orgIds) {
                $q->where('pool_level', Lead::POOL_COMMON);
                if ($orgIds === null) {
                    $q->orWhere('pool_level', Lead::POOL_TEAM);
                } elseif ($orgIds !== []) {
                    $q->orWhere(fn ($qq) => $qq->where('pool_level', Lead::POOL_TEAM)->whereIn('org_unit_id', $orgIds));
                }
            })->count();
    }

    /** Danh sách Sale trong scope của người chia (để dropdown chia thẳng). */
    private function poolSaleUsers()
    {
        if (! $this->canPullPool()) return collect();
        $subtreeIds = \App\Support\AdminScope::orgUnitIds();

        $q = User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('assignments', function ($qq) use ($subtreeIds) {
                $qq->whereHas('role', fn ($r) => $r->where('name', 'like', '%ale%'));
                // Super admin toàn công ty ($subtreeIds=null) → không lọc org, thấy toàn Sale.
                if (is_array($subtreeIds)) {
                    $qq->whereIn('org_unit_id', $subtreeIds ?: [0]);
                }
            });

        return $q->orderBy('name')->get(['id', 'name', 'email']);
    }

    /** Chia thẳng 1 lead trong kho cho user đã chọn ở dropdown. Set owner + advance phase=CALL. */
    public function assignFromPool(int $leadId): void
    {
        if (! $this->canPullPool()) {
            session()->flash('pool_error', 'Không có quyền phân bổ từ kho số.');
            return;
        }
        $userId = (int) ($this->poolAssignments[$leadId] ?? 0);
        if (! $userId) {
            session()->flash('pool_error', 'Chọn nhân sự trước khi bấm Chia.');
            return;
        }
        $lead = Lead::find($leadId);
        if (! $lead || ! $lead->isVisibleTo(auth()->user())) {
            session()->flash('pool_error', 'Lead không tồn tại hoặc ngoài phạm vi.');
            return;
        }
        $target = User::find($userId);
        if (! $target) {
            session()->flash('pool_error', 'Nhân sự không tồn tại.');
            return;
        }
        $subtreeIds = \App\Support\AdminScope::orgUnitIds();
        if (is_array($subtreeIds)) {
            $ok = $target->assignments()
                ->whereIn('org_unit_id', $subtreeIds ?: [0])
                ->exists();
            if (! $ok) {
                session()->flash('pool_error', 'Nhân sự ngoài phạm vi của bạn.');
                return;
            }
        }
        // subtreeIds=null (super admin toàn công ty) → bỏ qua check scope.

        $before = $lead->owner_id;
        // 2026-08-12: sync org_unit_id theo sale nhận để CM/Team Leader thấy lead
        // trong kho cá nhân (visibleTo subtree cần match org_unit_id).
        $saleOrgId = \App\Models\Assignment::where('user_id', $target->id)
            ->orderBy('created_at')->value('org_unit_id');
        $lead->update([
            'owner_id'        => $target->id,
            'assigned_at'     => now(),
            'pool_level'      => Lead::POOL_PERSONAL,
            'org_unit_id'     => $saleOrgId ?: $lead->org_unit_id,
            'pipeline_status' => Lead::PSTATUS_IN_CARE,
            'phase'           => max((int) $lead->phase, Lead::CF_PHASE_CALL),
        ]);
        LeadStatusLog::record($lead, 'owner_id', $before, $target->id, auth()->id());
        LeadStatusLog::record($lead, 'note', null, 'Kho số: chia thẳng cho ' . $target->name . ' (làm Tele care)', auth()->id());
        unset($this->poolAssignments[$leadId]);
        session()->flash('pool_ok', "Đã chia lead {$lead->code} cho {$target->name}.");
    }

    /**
     * 2026-08-04 (T9): 3 widget chính = số lead theo 3 phase đầu của Customer Flow.
     * Click widget → nhảy sang /leads?phase=X (filter phase đã có sẵn ở lead-list).
     */
    private function mainWidgets(): array
    {
        // 2026-08-09: header ghi "Số liệu <hôm nay>" → widget cũng phải filter today
        // để khớp với danh sách "Lead hôm nay" bên dưới. Trước đây count all-time
        // → widget 2, danh sách 0 → user confuse.
        $today = today();
        $q = fn () => $this->reportLeadQuery()->whereDate('received_date', $today);

        return [
            [
                'label' => 'Lead mới nhập',
                'desc' => 'Khách được nhập, đang ở Phase 1 (Tạo mới & Chia số)',
                'value' => $q()->where('phase', Lead::CF_PHASE_NEW)->count(),
                'color' => 'blue',
                'icon' => 'M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z',
                'link' => route('leads.index') . '?phase=' . Lead::CF_PHASE_NEW,
            ],
            [
                'label' => 'Lead Tele chăm sóc',
                'desc' => 'Đang ở Phase 2 (Gọi điện) — Tele care',
                'value' => $q()->where('phase', Lead::CF_PHASE_CALL)->count(),
                'color' => 'amber',
                'icon' => 'M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z',
                'link' => route('leads.index') . '?phase=' . Lead::CF_PHASE_CALL,
            ],
            [
                'label' => 'Lead đang booking',
                'desc' => 'Đang ở Phase 3 (Booking thăm khám) — chờ khách đến',
                'value' => $q()->where('phase', Lead::CF_PHASE_BOOKING)->count(),
                'color' => 'emerald',
                'icon' => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75',
                'link' => route('leads.index') . '?phase=' . Lead::CF_PHASE_BOOKING,
            ],
        ];
    }

    /** "Lead hôm nay" — danh sách nhận trong ngày, filter compact. */
    private function todayLeads()
    {
        $today = now()->toDateString();
        $q = $this->reportLeadQuery()
            ->with(['owner', 'receiver'])
            ->whereDate('received_date', $today);

        $q = $this->applyRoleFilter($q);
        if ($this->fPhase !== '') $q->where('phase', (int) $this->fPhase);
        if ($this->fSource !== '') $q->where('source_group', $this->fSource);
        if ($this->fSearch !== '') {
            $s = trim($this->fSearch);
            $q->where(fn ($qq) => $qq->where('name', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%")
                ->orWhere('code', 'like', "%{$s}%"));
        }
        return $q->orderByDesc('id')->limit(50)->get();
    }

    public function with(): array
    {
        return [
            'widgets' => $this->mainWidgets(),
            'todayLeads' => $this->todayLeads(),
            'todayCount' => $this->applyRoleFilter($this->reportLeadQuery())->whereDate('received_date', today())->count(),
            'poolLeads' => $this->poolLeads(),
            'poolLeadsCount' => $this->poolLeadsCount(),
            'poolSaleUsers' => $this->poolSaleUsers(),
        ];
    }
};
?>

<div wire:poll.15s>
    <div class="flex flex-wrap items-center justify-between gap-4 mb-8">
        <div>
            <h1 class="text-3xl font-bold mb-1">Dashboard</h1>
            <p class="text-sm text-ink/60">Số liệu {{ now()->format('d/m/Y') }} — tự cập nhật mỗi phút.</p>
        </div>
        <div class="flex items-center gap-2">
            @if (auth()->user()->hasPermission('lead.create'))
                <a href="{{ route('leads.create') }}" class="text-sm font-semibold text-white bg-gold-600 hover:bg-gold-700 px-4 py-2.5 rounded-md inline-flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    Thêm mới lead
                </a>
            @endif
            @if (auth()->user()->hasAnyPermission(['report.view', 'report.view_all']))
                <a href="{{ route('reports.index') }}" class="text-sm font-semibold text-gold-700 border border-gold-300 hover:bg-gold-50 px-4 py-2.5 rounded-md">Xem báo cáo chi tiết →</a>
            @endif
        </div>
    </div>

    {{-- 2026-08-04 (T9): 3 widget theo Customer Flow phase — click sang /leads filter phase --}}
    @php
        $colorMap = [
            'blue'    => 'bg-blue-50 border-blue-200 hover:border-blue-400 text-blue-800',
            'amber'   => 'bg-amber-50 border-amber-200 hover:border-amber-400 text-amber-800',
            'emerald' => 'bg-emerald-50 border-emerald-200 hover:border-emerald-400 text-emerald-800',
        ];
    @endphp
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        @foreach ($widgets as $w)
            <a href="{{ $w['link'] }}" class="block border-2 rounded-xl p-5 shadow-card transition-all {{ $colorMap[$w['color']] }}">
                <div class="flex items-start justify-between mb-3">
                    <svg class="w-8 h-8 opacity-80" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $w['icon'] }}"/></svg>
                    <svg class="w-5 h-5 opacity-40" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                </div>
                <div class="text-4xl font-extrabold tabular-nums leading-none mb-2">{{ number_format($w['value']) }}</div>
                <div class="font-bold text-base">{{ $w['label'] }}</div>
                <div class="text-xs opacity-70 mt-1 leading-snug">{{ $w['desc'] }}</div>
            </a>
        @endforeach
    </div>

    {{-- 2026-08-12: Widget "Kho lead" bỏ để tránh trùng menu Khách hàng > Kho lead. Toàn bộ chức năng đã có sẵn ở /distribution/pools. --}}
    @if (false && $this->canViewPool())
        <div class="bg-white border border-purple-200 rounded-xl shadow-card mb-6">
            <div class="px-5 py-4 border-b border-purple-100 bg-purple-50/50 flex flex-wrap items-center gap-3 justify-between">
                <h2 class="font-bold text-lg flex items-center gap-2 text-purple-800">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
                    Kho lead — chờ chia
                    <span class="text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full">{{ number_format($poolLeadsCount) }}</span>
                    @if ($poolLeadsCount > 10)<span class="text-xs text-ink/50 italic">· hiện 10 mới nhất</span>@endif
                </h2>
                <div class="flex items-center gap-2">
                    @if ($this->canPullPool())
                        <span class="text-[11px] px-2 py-0.5 rounded bg-emerald-100 text-emerald-700 font-semibold">✓ Bạn được phân bổ</span>
                    @else
                        <span class="text-[11px] px-2 py-0.5 rounded bg-slate-100 text-slate-600">Chỉ xem</span>
                    @endif
                    <a href="{{ route('distribution.pools') }}" class="text-xs font-semibold text-purple-700 border border-purple-300 hover:bg-purple-100 px-3 py-1.5 rounded">Xem tất cả →</a>
                </div>
            </div>
            @if (session('pool_ok'))
                <div class="px-5 py-2 bg-emerald-50 text-emerald-800 text-sm border-b border-emerald-200">✓ {{ session('pool_ok') }}</div>
            @endif
            @if (session('pool_error'))
                <div class="px-5 py-2 bg-red-50 text-red-800 text-sm border-b border-red-200">⚠ {{ session('pool_error') }}</div>
            @endif
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs uppercase tracking-wider text-ink/50 bg-purple-50/40">
                        <tr class="text-left">
                            <th class="px-4 py-2.5 font-semibold">Mã KH</th>
                            <th class="px-4 py-2.5 font-semibold">Họ tên</th>
                            <th class="px-4 py-2.5 font-semibold">Ngày sinh</th>
                            <th class="px-4 py-2.5 font-semibold">SĐT</th>
                            <th class="px-4 py-2.5 font-semibold text-center">Phase</th>
                            <th class="px-4 py-2.5 font-semibold">Tele care</th>
                            <th class="px-4 py-2.5 font-semibold">Sale care</th>
                            <th class="px-4 py-2.5 font-semibold">Người tạo</th>
                            <th class="px-4 py-2.5 font-semibold">Ngày tạo</th>
                            @if ($this->canPullPool())
                                <th class="px-4 py-2.5 font-semibold text-right">Chia thẳng</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-purple-50">
                        @forelse ($poolLeads as $pl)
                            @php
                                $teleCare = $pl->pipeline_phase === Lead::PHASE_BOOKING ? $pl->owner?->name : ($pl->receiver?->name);
                                $saleCare = $pl->pipeline_phase === Lead::PHASE_SALE ? $pl->owner?->name : null;
                            @endphp
                            <tr class="hover:bg-purple-50/30">
                                <td class="px-4 py-2 font-mono text-xs text-purple-700"><a href="{{ route('leads.edit', $pl) }}" class="hover:underline">{{ $pl->code ?? '—' }}</a></td>
                                <td class="px-4 py-2 font-semibold">{{ $pl->name }}</td>
                                <td class="px-4 py-2 text-ink/70">{{ $pl->birthday?->format('d/m/Y') ?? '—' }}</td>
                                <td class="px-4 py-2 font-mono">{{ $pl->phoneFor(auth()->user()) }}</td>
                                <td class="px-4 py-2 text-center"><span class="text-xs font-bold bg-blue-50 text-blue-700 px-2 py-0.5 rounded">P{{ $pl->phase }}</span></td>
                                <td class="px-4 py-2 text-ink/70">{{ $teleCare ?: '—' }}</td>
                                <td class="px-4 py-2 text-ink/70">{{ $saleCare ?: '—' }}</td>
                                <td class="px-4 py-2 text-ink/70">{{ $pl->importer?->name ?? '—' }}</td>
                                <td class="px-4 py-2 text-xs text-ink/60">{{ $pl->created_at?->format('d/m H:i') ?? '—' }}</td>
                                @if ($this->canPullPool())
                                    <td class="px-4 py-2">
                                        <div class="flex items-center gap-1.5 justify-end">
                                            <select wire:model="poolAssignments.{{ $pl->id }}" class="text-xs border border-purple-300 rounded px-2 py-1 bg-white max-w-[160px]">
                                                <option value="">— chọn Sale —</option>
                                                @foreach ($poolSaleUsers as $su)
                                                    <option value="{{ $su->id }}">{{ $su->name }}</option>
                                                @endforeach
                                            </select>
                                            <button type="button" wire:click="assignFromPool({{ $pl->id }})" class="text-xs font-semibold bg-purple-600 hover:bg-purple-700 text-white px-3 py-1 rounded whitespace-nowrap">Chia</button>
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $this->canPullPool() ? 10 : 9 }}" class="px-4 py-10 text-center text-ink/40 italic">
                                    Kho trống — không có lead nào chờ chia trong phạm vi của bạn.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- 2026-08-04 (T9): Lead hôm nay — filter + list --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card">
        <div class="px-5 py-4 border-b border-gold-100 flex flex-wrap items-center gap-3 justify-between">
            <h2 class="font-bold text-lg flex items-center gap-2">
                <svg class="w-5 h-5 text-gold-600" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Lead hôm nay
                <span class="text-xs bg-gold-100 text-gold-700 px-2 py-0.5 rounded-full">{{ number_format($todayCount) }}</span>
            </h2>
            <div class="flex flex-wrap items-center gap-2 text-sm">
                {{-- 2026-08-09: chip filter cho user kiêm nhiều role trong ngày (sale hôm nay làm tele, mai làm book). --}}
                @php
                    $roleChips = [
                        ['k' => '', 'label' => 'Tất cả'],
                        ['k' => 'tele', 'label' => 'Tele'],
                        ['k' => 'booking', 'label' => 'Booking'],
                    ];
                @endphp
                <div class="inline-flex rounded-md border border-gold-200 bg-white overflow-hidden text-xs font-semibold">
                    @foreach ($roleChips as $c)
                        <button type="button" wire:click="$set('fRole','{{ $c['k'] }}')"
                            class="px-3 py-1.5 border-r border-gold-200 last:border-r-0 {{ $fRole === $c['k'] ? 'bg-gold-600 text-white' : 'text-ink/70 hover:bg-gold-50' }}">
                            {{ $c['label'] }}
                        </button>
                    @endforeach
                </div>
                <input wire:model.live.debounce.400ms="fSearch" placeholder="🔍 Tên / SĐT / mã KH" class="border border-slate-300 rounded px-2.5 py-1.5 text-sm w-52">
                <select wire:model.live="fPhase" class="border border-slate-300 rounded px-2 py-1.5 text-sm">
                    <option value="">Mọi Phase</option>
                    @foreach (\App\Models\Lead::CF_PHASE_LABELS as $i => $label)
                        <option value="{{ $i }}">Phase {{ $i }} — {{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="fSource" class="border border-slate-300 rounded px-2 py-1.5 text-sm">
                    <option value="">Mọi nguồn</option>
                    @foreach (\App\Models\Lead::SOURCE_GROUPS as $k => $v)
                        <option value="{{ $k }}">{{ strtoupper($k) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs uppercase tracking-wider text-ink/50 bg-gold-50/60">
                    <tr class="text-left">
                        <th class="px-4 py-2.5 font-semibold">Mã KH</th>
                        <th class="px-4 py-2.5 font-semibold">Tên</th>
                        <th class="px-4 py-2.5 font-semibold">SĐT</th>
                        <th class="px-4 py-2.5 font-semibold">Nguồn</th>
                        <th class="px-4 py-2.5 font-semibold text-center">Phase</th>
                        <th class="px-4 py-2.5 font-semibold">Tele phụ trách</th>
                        <th class="px-4 py-2.5 font-semibold">Trạng thái booking</th>
                        <th class="px-4 py-2.5 font-semibold">Sale tiếp đón</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gold-50">
                    @forelse ($todayLeads as $lead)
                        {{-- 2026-08-05: mọi user click → /edit (form 7 phase). Route gate canOpenEditForm sẽ tự chặn nếu không có quyền. --}}
                        @php
                            $rowHref = route('leads.edit', $lead);
                            // 2026-08-19: tele phụ trách = owner (phase booking) hoặc receiver (phase khác).
                            //   Bỏ qua receiver nếu = imported_by (dữ liệu cũ lỡ set creator vào receiver_id
                            //   khi tạo lead) và chưa có owner — lead thực chất còn trong kho, chưa có tele.
                            $__teleUser = $lead->pipeline_phase === \App\Models\Lead::PHASE_BOOKING
                                ? $lead->owner
                                : ($lead->receiver_id && $lead->receiver_id !== $lead->imported_by ? $lead->receiver : null);
                            $teleCare = $__teleUser?->name;
                            $saleCare = $lead->pipeline_phase === \App\Models\Lead::PHASE_SALE ? $lead->owner?->name : null;
                        @endphp
                        <tr class="hover:bg-gold-50/40 cursor-pointer" onclick="window.location='{{ $rowHref }}'">
                            <td class="px-4 py-2 font-mono text-xs text-gold-700">{{ $lead->code ?? '—' }}</td>
                            <td class="px-4 py-2 font-semibold">{{ $lead->name }}</td>
                            <td class="px-4 py-2 font-mono">{{ $lead->phoneFor(auth()->user()) }}</td>
                            <td class="px-4 py-2 text-xs uppercase text-ink/60">{{ $lead->source_group ?? '—' }}</td>
                            <td class="px-4 py-2 text-center">
                                <span class="text-xs font-bold bg-blue-50 text-blue-700 px-2 py-0.5 rounded">P{{ $lead->phase }}</span>
                            </td>
                            <td class="px-4 py-2 text-ink/70">{{ $teleCare ?: '—' }}</td>
                            <td class="px-4 py-2">
                                @php $bs = $lead->booking_status; @endphp
                                <span class="text-xs px-2 py-0.5 rounded-full border {{ \App\Models\Lead::BOOKING_STATUS_COLORS[$bs] ?? 'bg-slate-50 border-slate-200 text-slate-700' }}">
                                    {{ (\App\Models\Lead::BOOKING_STATUS_ICONS[$bs] ?? '') }} {{ \App\Models\Lead::BOOKING_STATUSES[$bs] ?? '—' }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-ink/70">{{ $saleCare ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-10 text-center text-ink/40 italic">
                                @if ($fSearch || $fPhase || $fSource)
                                    Không có lead nào khớp bộ lọc.
                                @else
                                    Chưa có lead nào hôm nay.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
