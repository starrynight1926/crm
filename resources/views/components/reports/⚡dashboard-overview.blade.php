<?php

use App\Models\Lead;
use Livewire\Component;

new class extends Component
{
    // Filter cho "Lead hôm nay" ở cuối dashboard.
    public string $fPhase = '';
    public string $fSource = '';
    public string $fSearch = '';

    private function seesAllReports(): bool
    {
        return auth()->user()->hasPermission('report.view_all');
    }

    private function reportLeadQuery()
    {
        return $this->seesAllReports() ? Lead::query() : Lead::visibleTo(auth()->user());
    }

    /**
     * 2026-08-04 (T9): 3 widget chính = số lead theo 3 phase đầu của Customer Flow.
     * Click widget → nhảy sang /leads?phase=X (filter phase đã có sẵn ở lead-list).
     */
    private function mainWidgets(): array
    {
        $q = fn () => $this->reportLeadQuery();

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
            ->with('owner')
            ->whereDate('received_date', $today);

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
            'todayCount' => $this->reportLeadQuery()->whereDate('received_date', today())->count(),
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

    {{-- 2026-08-04 (T9): Lead hôm nay — filter + list --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card">
        <div class="px-5 py-4 border-b border-gold-100 flex flex-wrap items-center gap-3 justify-between">
            <h2 class="font-bold text-lg flex items-center gap-2">
                <svg class="w-5 h-5 text-gold-600" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Lead hôm nay
                <span class="text-xs bg-gold-100 text-gold-700 px-2 py-0.5 rounded-full">{{ number_format($todayCount) }}</span>
            </h2>
            <div class="flex flex-wrap items-center gap-2 text-sm">
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
                        <th class="px-4 py-2.5 font-semibold">Chia cho</th>
                        <th class="px-4 py-2.5 font-semibold">Trạng thái</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gold-50">
                    @php $isSale = auth()->user()->hasPermission('lead.consult'); @endphp
                    @forelse ($todayLeads as $lead)
                        @php $rowHref = $isSale ? route('leads.show', $lead) : route('leads.edit', $lead); @endphp
                        <tr class="hover:bg-gold-50/40 cursor-pointer" onclick="window.location='{{ $rowHref }}'">
                            <td class="px-4 py-2 font-mono text-xs text-gold-700">{{ $lead->code ?? '—' }}</td>
                            <td class="px-4 py-2 font-semibold">{{ $lead->name }}</td>
                            <td class="px-4 py-2 font-mono">{{ $lead->phoneFor(auth()->user()) }}</td>
                            <td class="px-4 py-2 text-xs uppercase text-ink/60">{{ $lead->source_group ?? '—' }}</td>
                            <td class="px-4 py-2 text-center">
                                <span class="text-xs font-bold bg-blue-50 text-blue-700 px-2 py-0.5 rounded">P{{ $lead->phase }}</span>
                            </td>
                            <td class="px-4 py-2 text-ink/70">{{ $lead->owner?->name ?: '—' }}</td>
                            <td class="px-4 py-2">
                                <span class="text-xs bg-gold-50 border border-gold-200 text-gold-700 px-2 py-0.5 rounded-full">{{ $lead->classificationLabel() }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center text-ink/40 italic">
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
