<?php

use App\Models\Lead;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $fClassification = '';

    public string $fCamp = '';

    public string $fAdSource = '';

    public string $fDateFrom = '';

    public string $fDateTo = '';

    public function updated($property): void
    {
        if (in_array($property, ['search', 'fClassification', 'fCamp', 'fAdSource', 'fDateFrom', 'fDateTo'])) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        $user = auth()->user();

        $leads = Lead::query()
            ->visibleTo($user)
            ->with(['owner', 'receiver', 'orgUnit'])
            ->when($this->search, function ($q) {
                $normalized = Lead::normalizePhone($this->search);
                $q->where(fn ($qq) => $qq
                    ->where('name', 'like', "%{$this->search}%")
                    ->orWhere('code', 'like', "%{$this->search}%")
                    ->when($normalized, fn ($qqq) => $qqq->orWhere('phone', $normalized)));
            })
            ->when($this->fClassification, fn ($q) => $q->where('classification', $this->fClassification))
            ->when($this->fCamp, fn ($q) => $q->where('camp', $this->fCamp))
            ->when($this->fAdSource, fn ($q) => $q->where('ad_source', $this->fAdSource))
            ->when($this->fDateFrom, fn ($q) => $q->where('received_date', '>=', $this->fDateFrom))
            ->when($this->fDateTo, fn ($q) => $q->where('received_date', '<=', $this->fDateTo))
            ->orderByDesc('received_date')
            ->orderByDesc('id')
            ->paginate(15);

        return [
            'leads' => $leads,
            'campOptions' => Lead::visibleTo($user)->whereNotNull('camp')->distinct()->orderBy('camp')->pluck('camp'),
            'adSourceOptions' => Lead::visibleTo($user)->whereNotNull('ad_source')->distinct()->orderBy('ad_source')->pluck('ad_source'),
        ];
    }
};
?>

<div>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-3xl font-bold mb-1">Danh sách khách hàng</h1>
            <p class="text-sm text-ink/60">Quản lý và theo dõi các khách hàng tiềm năng trên tất cả các kênh.</p>
        </div>
        <div class="flex items-center gap-3">
            @if (auth()->user()->hasPermission('lead.import'))
                <a href="{{ route('leads.failed') }}" class="text-sm font-semibold text-ink/60 border border-gold-200 px-4 py-2.5 rounded-md hover:bg-gold-50">Lead lỗi</a>
                <a href="{{ route('leads.import') }}" class="text-sm font-semibold text-gold-700 border border-gold-300 px-4 py-2.5 rounded-md hover:bg-gold-50">⬆ Import</a>
            @endif
            @if (auth()->user()->hasPermission('lead.create'))
                <a href="{{ route('leads.create') }}"
                   class="bg-gold-600 hover:bg-gold-700 text-white font-semibold px-5 py-2.5 rounded-md text-sm">
                    + Tạo mới Lead
                </a>
            @endif
        </div>
    </div>

    {{-- Bộ lọc --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card px-5 py-4 mb-5 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
        <div>
            <label class="block text-xs font-semibold text-ink/50 mb-1">Từ ngày</label>
            <input type="date" wire:model.live="fDateFrom" class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm focus:outline-none focus:border-gold-500">
        </div>
        <div>
            <label class="block text-xs font-semibold text-ink/50 mb-1">Đến ngày</label>
            <input type="date" wire:model.live="fDateTo" class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm focus:outline-none focus:border-gold-500">
        </div>
        <div>
            <label class="block text-xs font-semibold text-ink/50 mb-1">Chiến dịch</label>
            <select wire:model.live="fCamp" class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                <option value="">Tất cả</option>
                @foreach ($campOptions as $camp)
                    <option value="{{ $camp }}">{{ $camp }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-ink/50 mb-1">Nguồn</label>
            <select wire:model.live="fAdSource" class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                <option value="">Tất cả</option>
                @foreach ($adSourceOptions as $source)
                    <option value="{{ $source }}">{{ $source }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-ink/50 mb-1">Phân loại kết quả</label>
            <select wire:model.live="fClassification" class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                <option value="">Tất cả</option>
                @foreach (\App\Models\Lead::CLASSIFICATIONS as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-ink/50 mb-1">Tìm kiếm</label>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Tên hoặc SĐT..."
                   class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm focus:outline-none focus:border-gold-500">
        </div>
    </div>

    {{-- Bảng --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card overflow-x-auto">
        <table class="w-full text-sm whitespace-nowrap">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wider text-ink/50 bg-gold-50/60">
                    <th class="px-4 py-3 font-semibold">Mã KH</th>
                    <th class="px-4 py-3 font-semibold">Ngày</th>
                    <th class="px-4 py-3 font-semibold">Page</th>
                    <th class="px-4 py-3 font-semibold">Tên khách hàng</th>
                    <th class="px-4 py-3 font-semibold">SĐT</th>
                    <th class="px-4 py-3 font-semibold">Camp</th>
                    <th class="px-4 py-3 font-semibold">Nguồn QC</th>
                    <th class="px-4 py-3 font-semibold">Khu vực</th>
                    <th class="px-4 py-3 font-semibold">Chia cho</th>
                    <th class="px-4 py-3 font-semibold">Phân loại</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gold-100">
                @forelse ($leads as $lead)
                    <tr class="hover:bg-gold-50/40 cursor-pointer" onclick="window.location='{{ route('leads.show', $lead) }}'">
                        <td class="px-4 py-3 font-mono text-xs text-gold-700">{{ $lead->code ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $lead->received_date->format('d/m/Y') }}</td>
                        <td class="px-4 py-3 text-ink/60">{{ $lead->page ?: '—' }}</td>
                        <td class="px-4 py-3 font-semibold text-gold-700">{{ $lead->name }}</td>
                        <td class="px-4 py-3 font-mono">{{ $lead->phoneFor(auth()->user()) }}</td>
                        <td class="px-4 py-3 text-ink/60">{{ $lead->camp ?: '—' }}</td>
                        <td class="px-4 py-3">
                            @if ($lead->ad_source)
                                <span class="text-xs bg-gold-50 border border-gold-200 px-2 py-0.5 rounded">{{ $lead->ad_source }}</span>
                            @else — @endif
                        </td>
                        <td class="px-4 py-3 text-ink/60">{{ $lead->region ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $lead->owner?->name ?: '—' }}</td>
                        <td class="px-4 py-3">
                            @php
                                $cls = $lead->classification;
                                $badge = match(true) {
                                    in_array($cls, ['close', 'show', 'booking']) => 'bg-green-50 border-green-200 text-green-700',
                                    in_array($cls, ['klld', 'missed']) => 'bg-red-50 border-red-200 text-red-600',
                                    $cls === 'new' => 'bg-blue-50 border-blue-200 text-blue-700',
                                    default => 'bg-gold-50 border-gold-200 text-gold-700',
                                };
                            @endphp
                            <span class="text-xs border px-2 py-0.5 rounded-full {{ $badge }}">{{ $lead->classificationLabel() }}</span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-4 py-10 text-center text-ink/40">Không có khách hàng nào trong phạm vi của bạn.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="px-5 py-4 border-t border-gold-100 flex items-center justify-between text-sm text-ink/60">
            <span>Hiển thị {{ $leads->count() }} trong tổng số {{ number_format($leads->total()) }} khách hàng</span>
            {{ $leads->links() }}
        </div>
    </div>
</div>
