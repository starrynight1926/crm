<?php

use App\Models\Lead;
use App\Models\SlaPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

new class extends Component
{
    /** Lọc stats_daily theo data scope: org trong phạm vi HOẶC dòng của chính mình. */
    private function scopedStats()
    {
        $user = auth()->user();
        $orgIds = $user->visibleOrgUnitIds();

        return DB::table('stats_daily')->where(function ($q) use ($orgIds, $user) {
            if ($orgIds !== []) {
                $q->orWhereIn('org_unit_id', $orgIds);
            }
            $q->orWhere('user_id', $user->id);
        });
    }

    public function with(): array
    {
        $user = auth()->user();

        // Funnel tháng hiện tại (từ stats_daily — aggregate 2 phút/lần)
        $funnel = $this->scopedStats()
            ->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->toDateString()])
            ->selectRaw('sum(total) total, sum(`lead`) `lead`, sum(`follow`) `follow`, sum(net) net, sum(booking) booking, sum(`show`) `show`, sum(`close`) `close`, sum(revenue_collected) revenue')
            ->first();

        // Top sale tháng (close + doanh thu)
        $topSales = $this->scopedStats()
            ->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->toDateString()])
            ->whereNotNull('user_id')
            ->selectRaw('user_id, sum(`close`) closes, sum(revenue_collected) revenue, sum(total) total')
            ->groupBy('user_id')
            ->orderByDesc('closes')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get();
        $topSaleUsers = User::whereIn('id', $topSales->pluck('user_id'))->get()->keyBy('id');

        // Lead quá SLA / chưa chăm (live)
        $defaultPolicy = SlaPolicy::whereNull('org_unit_id')->first();
        $hours = $defaultPolicy?->recall_after_hours ?? 24;
        $overdue = Lead::visibleTo($user)
            ->where('pool_level', Lead::POOL_PERSONAL)
            ->whereNotNull('assigned_at')
            ->where('assigned_at', '<=', now()->subHours($hours))
            ->where(fn ($q) => $q->whereNull('last_care_at')->orWhereColumn('last_care_at', '<', 'assigned_at'))
            ->with('owner')
            ->orderBy('assigned_at')
            ->limit(8)
            ->get();

        return [
            'todayCount' => Lead::visibleTo($user)->whereDate('received_date', today())->count(),
            'funnel' => $funnel,
            'topSales' => $topSales,
            'topSaleUsers' => $topSaleUsers,
            'overdue' => $overdue,
            'recentLeads' => Lead::visibleTo($user)->with('owner')->orderByDesc('id')->limit(6)->get(),
        ];
    }
};
?>

<div wire:poll.60s>
    <div class="flex flex-wrap items-center justify-between gap-4 mb-8">
        <div>
            <h1 class="text-3xl font-bold mb-1">Executive Dashboard</h1>
            <p class="text-sm text-ink/60">Số liệu tháng {{ now()->format('m/Y') }} — tự cập nhật mỗi phút.</p>
        </div>
        @if (auth()->user()->hasPermission('report.view'))
            <a href="{{ route('reports.index') }}" class="text-sm font-semibold text-gold-700 border border-gold-300 hover:bg-gold-50 px-4 py-2.5 rounded-md">Xem báo cáo chi tiết →</a>
        @endif
    </div>

    {{-- Stat cards funnel --}}
    @php
        $cards = [
            ['label' => 'Total lead', 'value' => (int) ($funnel->total ?? 0), 'icon' => 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z'],
            ['label' => 'Follow', 'value' => (int) ($funnel->follow ?? 0), 'icon' => 'M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z'],
            ['label' => 'Nét', 'value' => (int) ($funnel->net ?? 0), 'icon' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
            ['label' => 'Booking', 'value' => (int) ($funnel->booking ?? 0), 'icon' => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-9-6h.008v.008H12v-.008zM12 15h.008v.008H12V15zm0 2.25h.008v.008H12v-.008zM9.75 15h.008v.008H9.75V15zm0 2.25h.008v.008H9.75v-.008zM7.5 15h.008v.008H7.5V15zm0 2.25h.008v.008H7.5v-.008zm6.75-4.5h.008v.008h-.008v-.008zm0 2.25h.008v.008h-.008V15zm0 2.25h.008v.008h-.008v-.008zm2.25-4.5h.008v.008H16.5v-.008zm0 2.25h.008v.008H16.5V15z'],
            ['label' => 'Show', 'value' => (int) ($funnel->show ?? 0), 'icon' => 'M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
            ['label' => 'Close', 'value' => (int) ($funnel->close ?? 0), 'icon' => 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z'],
        ];
    @endphp
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-4">
        @foreach ($cards as $card)
            <div class="bg-white border border-gold-200 rounded-xl shadow-card p-5">
                <div class="w-10 h-10 rounded-lg bg-gold-50 border border-gold-100 text-gold-600 flex items-center justify-center mb-3">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $card['icon'] }}"/></svg>
                </div>
                <div class="text-xs font-semibold uppercase tracking-widest text-ink/50">{{ $card['label'] }}</div>
                <div class="text-2xl font-extrabold mt-0.5">{{ number_format($card['value']) }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
        <div class="bg-white border border-gold-200 rounded-xl shadow-card p-5 flex items-center gap-4">
            <div class="w-12 h-12 rounded-lg bg-gold-600 text-white flex items-center justify-center">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase tracking-widest text-ink/50">Lead về hôm nay</div>
                <div class="text-2xl font-extrabold">{{ number_format($todayCount) }}</div>
            </div>
        </div>
        <div class="bg-white border border-gold-200 rounded-xl shadow-card p-5 flex items-center gap-4">
            <div class="w-12 h-12 rounded-lg bg-green-700 text-white flex items-center justify-center">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase tracking-widest text-ink/50">Doanh thu thực thu tháng</div>
                <div class="text-2xl font-extrabold font-mono text-green-700">{{ number_format((int) ($funnel->revenue ?? 0)) }}₫</div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        {{-- Top sale --}}
        <div class="bg-white border border-gold-200 rounded-xl shadow-card">
            <div class="px-6 py-4 border-b border-gold-100"><h2 class="font-bold">Top sale tháng này</h2></div>
            <div class="divide-y divide-gold-50">
                @forelse ($topSales as $index => $row)
                    <div class="px-6 py-3 flex items-center gap-3 text-sm">
                        <span class="w-7 h-7 rounded-full {{ $index === 0 ? 'bg-gold-600 text-white' : 'bg-gold-100 text-gold-700' }} flex items-center justify-center font-bold text-xs">{{ $index + 1 }}</span>
                        <span class="flex-1 font-semibold">{{ $topSaleUsers[$row->user_id]?->name ?? '—' }}</span>
                        <span class="text-xs text-ink/50">{{ (int) $row->closes }} close / {{ (int) $row->total }} lead</span>
                        <span class="font-mono text-green-700 font-semibold">{{ number_format((int) $row->revenue) }}₫</span>
                    </div>
                @empty
                    <p class="px-6 py-8 text-sm text-ink/40 text-center">Chưa có dữ liệu tháng này.</p>
                @endforelse
            </div>
        </div>

        {{-- Lead quá SLA --}}
        <div class="bg-white border border-gold-200 rounded-xl shadow-card">
            <div class="px-6 py-4 border-b border-gold-100 flex items-center justify-between">
                <h2 class="font-bold">Lead chưa chăm / quá SLA</h2>
                <span class="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded-full font-semibold">{{ $overdue->count() }}</span>
            </div>
            <div class="divide-y divide-gold-50">
                @forelse ($overdue as $lead)
                    <a href="{{ route('leads.show', $lead) }}" class="px-6 py-3 flex items-center gap-3 text-sm hover:bg-gold-50/40">
                        <span class="flex-1">
                            <span class="font-semibold">{{ $lead->name }}</span>
                            <span class="text-xs text-ink/40 font-mono ml-1">{{ $lead->code }}</span>
                        </span>
                        <span class="text-xs text-ink/50">{{ $lead->owner?->name }}</span>
                        <span class="text-xs text-red-600 font-semibold">nhận {{ $lead->assigned_at->diffForHumans() }}</span>
                    </a>
                @empty
                    <p class="px-6 py-8 text-sm text-ink/40 text-center">Không có lead nào quá hạn 🎉</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Hoạt động gần đây --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card overflow-x-auto">
        <div class="px-6 py-4 border-b border-gold-100 flex items-center justify-between">
            <h2 class="font-bold">Lead mới nhất</h2>
            <a href="{{ route('leads.index') }}" class="text-xs font-semibold text-gold-700 hover:underline">Xem tất cả Lead →</a>
        </div>
        <table class="w-full text-sm whitespace-nowrap">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wider text-ink/50 bg-gold-50/60">
                    <th class="px-5 py-3 font-semibold">Ngày</th>
                    <th class="px-5 py-3 font-semibold">Mã KH</th>
                    <th class="px-5 py-3 font-semibold">Tên khách hàng</th>
                    <th class="px-5 py-3 font-semibold">SĐT</th>
                    <th class="px-5 py-3 font-semibold">Camp</th>
                    <th class="px-5 py-3 font-semibold">Chia cho</th>
                    <th class="px-5 py-3 font-semibold">Trạng thái</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gold-100">
                @foreach ($recentLeads as $lead)
                    <tr class="hover:bg-gold-50/40 cursor-pointer" onclick="window.location='{{ route('leads.show', $lead) }}'">
                        <td class="px-5 py-3">{{ $lead->received_date->format('d/m/Y') }}</td>
                        <td class="px-5 py-3 font-mono text-xs text-gold-700">{{ $lead->code }}</td>
                        <td class="px-5 py-3 font-semibold">{{ $lead->name }}</td>
                        <td class="px-5 py-3 font-mono">{{ $lead->phoneFor(auth()->user()) }}</td>
                        <td class="px-5 py-3 text-ink/60">{{ $lead->camp ?: '—' }}</td>
                        <td class="px-5 py-3">{{ $lead->owner?->name ?: '—' }}</td>
                        <td class="px-5 py-3">
                            <span class="text-xs bg-gold-50 border border-gold-200 text-gold-700 px-2 py-0.5 rounded-full">{{ $lead->classificationLabel() }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
