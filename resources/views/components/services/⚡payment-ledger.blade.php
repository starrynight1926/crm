<?php

use App\Models\CustomerService;
use App\Models\Payment;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $fDateFrom = '';

    public string $fDateTo = '';

    public string $tab = 'payments'; // payments / outstanding

    public function updated($property): void
    {
        if (in_array($property, ['fDateFrom', 'fDateTo'])) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        $payments = Payment::query()
            ->with(['lead', 'customerService.service', 'collector'])
            ->when($this->fDateFrom, fn ($q) => $q->where('paid_at', '>=', $this->fDateFrom))
            ->when($this->fDateTo, fn ($q) => $q->where('paid_at', '<=', $this->fDateTo))
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(15);

        // Công nợ: dịch vụ chưa thu đủ
        $withDebt = CustomerService::query()
            ->with(['lead', 'service'])
            ->withSum('payments', 'amount')
            ->where('status', '!=', CustomerService::STATUS_CANCELLED)
            ->get()
            ->filter(fn ($cs) => $cs->agreed_price > (int) ($cs->payments_sum_amount ?? 0))
            ->sortByDesc(fn ($cs) => $cs->agreed_price - (int) ($cs->payments_sum_amount ?? 0))
            ->values();

        return [
            'payments' => $payments,
            'withDebt' => $withDebt,
            'stats' => [
                'today' => (int) Payment::whereDate('paid_at', today())->sum('amount'),
                'month' => (int) Payment::whereYear('paid_at', now()->year)->whereMonth('paid_at', now()->month)->sum('amount'),
                'debt' => (int) $withDebt->sum(fn ($cs) => $cs->agreed_price - (int) ($cs->payments_sum_amount ?? 0)),
            ],
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <h1 class="text-3xl font-bold mb-1">Ghi nhận Thu tiền & Công nợ</h1>
        <p class="text-sm text-ink/60">Thu tiền ghi nhận tại màn chi tiết khách hàng (theo dịch vụ/phase). Công nợ = giá chốt − đã thu, tính động.</p>
    </div>

    {{-- Stats --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white border border-gold-200 rounded-xl shadow-card p-5">
            <div class="text-xs font-semibold uppercase tracking-widest text-ink/50 mb-2">Thực thu hôm nay</div>
            <div class="text-2xl font-extrabold font-mono text-green-700">{{ number_format($stats['today']) }}₫</div>
        </div>
        <div class="bg-white border border-gold-200 rounded-xl shadow-card p-5">
            <div class="text-xs font-semibold uppercase tracking-widest text-ink/50 mb-2">Thực thu tháng {{ now()->format('m/Y') }}</div>
            <div class="text-2xl font-extrabold font-mono text-green-700">{{ number_format($stats['month']) }}₫</div>
        </div>
        <div class="bg-white border border-gold-200 rounded-xl shadow-card p-5">
            <div class="text-xs font-semibold uppercase tracking-widest text-ink/50 mb-2">Tổng công nợ còn lại</div>
            <div class="text-2xl font-extrabold font-mono {{ $stats['debt'] > 0 ? 'text-red-600' : 'text-ink/40' }}">{{ number_format($stats['debt']) }}₫</div>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="border-b border-gold-200 mb-5 flex gap-1 text-sm font-semibold uppercase tracking-wide">
        <button wire:click="$set('tab', 'payments')" class="px-4 py-3 border-b-2 -mb-px {{ $tab === 'payments' ? 'border-gold-600 text-gold-700' : 'border-transparent text-ink/50 hover:text-gold-700' }}">Sổ thu tiền</button>
        <button wire:click="$set('tab', 'outstanding')" class="px-4 py-3 border-b-2 -mb-px {{ $tab === 'outstanding' ? 'border-gold-600 text-gold-700' : 'border-transparent text-ink/50 hover:text-gold-700' }}">
            Công nợ <span class="ml-1 text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded-full">{{ $withDebt->count() }}</span>
        </button>
    </div>

    @if ($tab === 'payments')
        <div class="bg-white border border-gold-200 rounded-xl shadow-card">
            <div class="px-5 py-4 border-b border-gold-100 flex flex-wrap items-center gap-3">
                <label class="text-xs font-semibold text-ink/50">Từ</label>
                <input type="date" wire:model.live="fDateFrom" class="border border-gold-200 rounded-md px-2.5 py-1.5 text-sm focus:outline-none focus:border-gold-500">
                <label class="text-xs font-semibold text-ink/50">Đến</label>
                <input type="date" wire:model.live="fDateTo" class="border border-gold-200 rounded-md px-2.5 py-1.5 text-sm focus:outline-none focus:border-gold-500">
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-ink/50 bg-gold-50/60">
                        <th class="px-5 py-3 font-semibold">Ngày</th>
                        <th class="px-5 py-3 font-semibold">Khách hàng</th>
                        <th class="px-5 py-3 font-semibold">Dịch vụ</th>
                        <th class="px-5 py-3 font-semibold text-right">Số tiền</th>
                        <th class="px-5 py-3 font-semibold">Hình thức</th>
                        <th class="px-5 py-3 font-semibold">Người thu</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gold-100">
                    @forelse ($payments as $payment)
                        <tr class="hover:bg-gold-50/40">
                            <td class="px-5 py-3">{{ $payment->paid_at->format('d/m/Y') }}</td>
                            <td class="px-5 py-3">
                                <a href="{{ route('leads.show', $payment->lead_id) }}" class="font-semibold text-gold-700 hover:underline">{{ $payment->lead?->name }}</a>
                                <span class="text-xs text-ink/40 font-mono ml-1">{{ $payment->lead?->code }}</span>
                            </td>
                            <td class="px-5 py-3 text-ink/60">{{ $payment->customerService?->service?->name ?: '—' }}</td>
                            <td class="px-5 py-3 text-right font-mono font-bold text-green-700">{{ number_format($payment->amount) }}₫</td>
                            <td class="px-5 py-3 text-xs">{{ \App\Models\Payment::METHODS[$payment->method] }}</td>
                            <td class="px-5 py-3">{{ $payment->collector?->name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-10 text-center text-ink/40">Chưa có khoản thu nào.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-5 py-4 border-t border-gold-100">{{ $payments->links() }}</div>
        </div>
    @else
        <div class="bg-white border border-gold-200 rounded-xl shadow-card">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-ink/50 bg-gold-50/60">
                        <th class="px-5 py-3 font-semibold">Khách hàng</th>
                        <th class="px-5 py-3 font-semibold">Dịch vụ</th>
                        <th class="px-5 py-3 font-semibold text-right">Giá chốt</th>
                        <th class="px-5 py-3 font-semibold text-right">Đã thu</th>
                        <th class="px-5 py-3 font-semibold text-right">Còn nợ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gold-100">
                    @forelse ($withDebt as $cs)
                        @php $paid = (int) ($cs->payments_sum_amount ?? 0); @endphp
                        <tr class="hover:bg-gold-50/40">
                            <td class="px-5 py-3">
                                <a href="{{ route('leads.show', $cs->lead_id) }}" class="font-semibold text-gold-700 hover:underline">{{ $cs->lead?->name }}</a>
                                <span class="text-xs text-ink/40 font-mono ml-1">{{ $cs->lead?->code }}</span>
                            </td>
                            <td class="px-5 py-3 text-ink/60">{{ $cs->service?->name }}</td>
                            <td class="px-5 py-3 text-right font-mono">{{ number_format($cs->agreed_price) }}₫</td>
                            <td class="px-5 py-3 text-right font-mono text-green-700">{{ number_format($paid) }}₫</td>
                            <td class="px-5 py-3 text-right font-mono font-bold text-red-600">{{ number_format($cs->agreed_price - $paid) }}₫</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-10 text-center text-ink/40">Không có công nợ nào 🎉</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
