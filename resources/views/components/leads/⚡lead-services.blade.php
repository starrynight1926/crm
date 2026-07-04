<?php

use App\Models\CustomerService;
use App\Models\CustomerServicePhase;
use App\Models\Lead;
use App\Models\LeadStatusLog;
use App\Models\Payment;
use App\Models\Service;
use Livewire\Component;

new class extends Component
{
    public Lead $lead;

    // Gắn dịch vụ
    public bool $showAttach = false;

    public string $serviceId = '';

    public string $agreedPrice = '';

    // Hoàn thành phase
    public ?int $completingPhaseId = null;

    public string $handoverNote = '';

    // Thu tiền
    public ?int $payingServiceId = null;

    public string $payAmount = '';

    public string $payMethod = 'cash';

    public string $payPhaseId = ''; // tùy chọn — gắn khoản thu vào phase cụ thể

    public function mount(Lead $lead): void
    {
        $this->lead = $lead;
    }

    public function updatedServiceId(): void
    {
        $service = Service::find((int) $this->serviceId);
        $this->agreedPrice = $service ? (string) $service->listPrice() : '';
    }

    public function attach(): void
    {
        abort_unless(auth()->user()->hasPermission('lead.update'), 403);
        $this->validate([
            'serviceId' => 'required|exists:services,id',
            'agreedPrice' => 'required|numeric|min:0',
        ], [], ['serviceId' => 'dịch vụ', 'agreedPrice' => 'giá chốt']);

        $cs = CustomerService::create([
            'lead_id' => $this->lead->id,
            'service_id' => (int) $this->serviceId,
            'agreed_price' => (int) $this->agreedPrice,
            'started_at' => now(),
        ]);
        $cs->initPhases();

        LeadStatusLog::record($this->lead, 'note', null, 'Gắn dịch vụ: ' . $cs->service->name . ' (giá chốt ' . number_format($cs->agreed_price) . '₫)', auth()->id());

        $this->showAttach = false;
        $this->reset('serviceId', 'agreedPrice');
    }

    public function startComplete(int $phaseId): void
    {
        $this->completingPhaseId = $phaseId;
        $this->handoverNote = '';
    }

    public function completePhase(): void
    {
        abort_unless(auth()->user()->hasPermission('lead.update'), 403);

        $phase = CustomerServicePhase::with('customerService.service', 'phase')->findOrFail($this->completingPhaseId);

        $phase->update([
            'status' => CustomerServicePhase::STATUS_DONE,
            'done_by' => auth()->id(),
            'done_at' => now(),
            'handover_note' => trim($this->handoverNote) ?: null,
        ]);

        $cs = $phase->customerService;
        // Xong hết phase → dịch vụ completed
        if ($cs->phases()->where('status', CustomerServicePhase::STATUS_PENDING)->doesntExist()) {
            $cs->update(['status' => CustomerService::STATUS_COMPLETED, 'completed_at' => now()]);
        }

        $this->lead->update(['last_care_at' => now()]);
        $this->completingPhaseId = null;
    }

    public function undoPhase(int $phaseId): void
    {
        abort_unless(auth()->user()->hasPermission('lead.update'), 403);

        $phase = CustomerServicePhase::findOrFail($phaseId);
        $phase->update(['status' => CustomerServicePhase::STATUS_PENDING, 'done_by' => null, 'done_at' => null]);
        $phase->customerService->update(['status' => CustomerService::STATUS_ACTIVE, 'completed_at' => null]);
    }

    public function startPay(int $customerServiceId): void
    {
        abort_unless(auth()->user()->hasPermission('payment.record'), 403);
        $this->payingServiceId = $customerServiceId;
        $this->payAmount = '';
        $this->payMethod = 'cash';
        $this->payPhaseId = '';
    }

    public function confirmPay(): void
    {
        abort_unless(auth()->user()->hasPermission('payment.record'), 403);
        $this->validate(['payAmount' => 'required|numeric|min:1000'], [], ['payAmount' => 'số tiền']);

        $cs = CustomerService::findOrFail($this->payingServiceId);

        Payment::create([
            'lead_id' => $this->lead->id,
            'customer_service_id' => $cs->id,
            'customer_service_phase_id' => $this->payPhaseId !== ''
                ? $cs->phases()->where('id', (int) $this->payPhaseId)->value('id')
                : null,
            'amount' => (int) $this->payAmount,
            'method' => $this->payMethod,
            'paid_at' => now()->toDateString(),
            'collected_by' => auth()->id(),
        ]);

        LeadStatusLog::record($this->lead, 'note', null, 'Thu tiền ' . number_format((int) $this->payAmount) . '₫ cho dịch vụ ' . $cs->service->name, auth()->id());
        $this->payingServiceId = null;
    }

    public function with(): array
    {
        return [
            'customerServices' => $this->lead->customerServices()
                ->with(['service', 'phases.phase', 'phases.doneBy', 'payments.collector'])
                ->get(),
            'availableServices' => Service::where('active', true)->orderBy('name')->get(),
            'canEdit' => auth()->user()->hasPermission('lead.update'),
            'canPay' => auth()->user()->hasPermission('payment.record'),
        ];
    }
};
?>

<div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
    <div class="flex items-center justify-between mb-4">
        <h2 class="font-bold flex items-center gap-2">
            <span class="w-9 h-9 rounded-full bg-gold-50 border border-gold-200 text-gold-600 flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 4.97-4.03 9-9 9s-9-4.03-9-9 4.03-9 9-9 9 4.03 9 9z"/></svg>
            </span>
            Dịch vụ & Tiến độ
        </h2>
        @if ($canEdit)
            <button wire:click="$set('showAttach', {{ $showAttach ? 'false' : 'true' }})" class="text-xs font-semibold text-gold-700 border border-gold-300 hover:bg-gold-50 px-4 py-2 rounded-md">+ Gắn dịch vụ</button>
        @endif
    </div>

    @if ($showAttach)
        <div class="bg-gold-50/50 border border-gold-200 rounded-lg p-4 mb-4 flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-48">
                <label class="block text-xs font-semibold text-ink/50 mb-1">Dịch vụ</label>
                <select wire:model.live="serviceId" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                    <option value="">— chọn dịch vụ —</option>
                    @foreach ($availableServices as $service)
                        <option value="{{ $service->id }}">{{ $service->name }} ({{ number_format($service->listPrice()) }}₫)</option>
                    @endforeach
                </select>
                @error('serviceId')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-semibold text-ink/50 mb-1">Giá chốt (₫)</label>
                <input type="number" wire:model="agreedPrice" class="w-36 border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                @error('agreedPrice')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <button wire:click="attach" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-5 py-2 rounded-md">Gắn</button>
        </div>
    @endif

    <div class="space-y-5">
        @forelse ($customerServices as $cs)
            <div class="border border-gold-100 rounded-lg overflow-hidden">
                <div class="px-4 py-3 bg-gold-50/60 flex flex-wrap items-center gap-3">
                    <div class="flex-1">
                        <span class="font-bold">{{ $cs->service->name }}</span>
                        <span class="text-xs ml-2 {{ $cs->status === 'completed' ? 'text-green-700 bg-green-50 border-green-200' : 'text-gold-700 bg-gold-50 border-gold-200' }} border px-2 py-0.5 rounded-full">
                            {{ ['active' => 'Đang chăm sóc', 'completed' => 'Hoàn thành', 'cancelled' => 'Đã hủy'][$cs->status] }}
                        </span>
                        <span class="text-xs text-ink/50 ml-2">{{ $cs->doneCount() }}/{{ $cs->phases->count() }} phase</span>
                    </div>
                    <div class="text-right text-sm">
                        <div>Giá chốt: <strong class="font-mono">{{ number_format($cs->agreed_price) }}₫</strong></div>
                        <div class="text-xs">
                            Đã thu: <span class="font-mono text-green-700">{{ number_format($cs->totalPaid()) }}₫</span>
                            · Công nợ: <span class="font-mono {{ $cs->outstanding() > 0 ? 'text-red-600 font-bold' : 'text-ink/40' }}">{{ number_format($cs->outstanding()) }}₫</span>
                        </div>
                    </div>
                    @if ($canPay && $cs->outstanding() > 0)
                        <button wire:click="startPay({{ $cs->id }})" class="text-xs font-semibold text-green-700 border border-green-200 hover:bg-green-50 px-3 py-1.5 rounded-md">+ Thu tiền</button>
                    @endif
                </div>

                @if ($payingServiceId === $cs->id)
                    <div class="px-4 py-3 bg-green-50/50 border-t border-green-100 flex flex-wrap items-end gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-ink/50 mb-1">Số tiền (₫)</label>
                            <input type="number" wire:model="payAmount" class="w-36 border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                            @error('payAmount')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-ink/50 mb-1">Hình thức</label>
                            <select wire:model="payMethod" class="border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                                @foreach (\App\Models\Payment::METHODS as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-ink/50 mb-1">Gắn vào phase (tùy chọn)</label>
                            <select wire:model="payPhaseId" class="border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                                <option value="">— cả dịch vụ —</option>
                                @foreach ($cs->phases->sortBy(fn ($p) => $p->phase->position) as $csp)
                                    <option value="{{ $csp->id }}">{{ $csp->phase->position }}. {{ $csp->phase->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button wire:click="confirmPay" class="bg-green-700 hover:bg-green-800 text-white font-semibold text-sm px-5 py-2 rounded-md">Ghi nhận</button>
                        <button wire:click="$set('payingServiceId', null)" class="text-sm text-ink/50 px-2 py-2">Hủy</button>
                    </div>
                @endif

                {{-- Tiến độ phase --}}
                <div class="divide-y divide-gold-50">
                    @foreach ($cs->phases->sortBy(fn ($p) => $p->phase->position) as $csp)
                        <div class="px-4 py-2.5 flex items-center gap-3 text-sm {{ $csp->status === 'done' ? 'bg-green-50/30' : '' }}">
                            <span class="w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold {{ $csp->status === 'done' ? 'bg-green-600 text-white' : 'bg-gold-100 text-gold-700' }}">
                                {{ $csp->phase->position }}
                            </span>
                            <div class="flex-1">
                                <span class="{{ $csp->status === 'done' ? 'line-through text-ink/50' : 'font-medium' }}">{{ $csp->phase->name }}</span>
                                @if ($csp->status === 'done')
                                    <span class="text-xs text-ink/50 ml-2">
                                        ✓ {{ $csp->doneBy?->name }} · {{ $csp->done_at?->format('d/m/Y H:i') }}
                                    </span>
                                    @if ($csp->handover_note)
                                        <div class="text-xs text-gold-800 bg-gold-50 border border-gold-100 rounded px-2 py-1 mt-1">📝 {{ $csp->handover_note }}</div>
                                    @endif
                                @endif
                            </div>
                            @if ($csp->phase->phase_price)
                                <span class="text-xs font-mono text-ink/40">{{ number_format($csp->phase->phase_price) }}₫</span>
                            @endif
                            @if ($canEdit)
                                @if ($csp->status === 'pending')
                                    <button wire:click="startComplete({{ $csp->id }})" class="text-xs font-semibold text-gold-700 border border-gold-300 hover:bg-gold-50 px-3 py-1 rounded-md">Hoàn thành</button>
                                @else
                                    <button wire:click="undoPhase({{ $csp->id }})" class="text-xs text-ink/40 hover:text-red-600 px-2 py-1" title="Hoàn tác">↩</button>
                                @endif
                            @endif
                        </div>

                        @if ($completingPhaseId === $csp->id)
                            <div class="px-4 py-3 bg-gold-50/50 flex flex-wrap items-end gap-3">
                                <div class="flex-1 min-w-64">
                                    <label class="block text-xs font-semibold text-ink/50 mb-1">Note bàn giao (người care tiếp sẽ đọc)</label>
                                    <input type="text" wire:model="handoverNote" placeholder="VD: Da nhạy cảm, hẹn tái khám sau 2 tuần..." class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                                </div>
                                <button wire:click="completePhase" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-5 py-2 rounded-md">Xác nhận xong</button>
                                <button wire:click="$set('completingPhaseId', null)" class="text-sm text-ink/50 px-2 py-2">Hủy</button>
                            </div>
                        @endif
                    @endforeach
                </div>

                {{-- Lịch sử thu tiền --}}
                @if ($cs->payments->isNotEmpty())
                    <div class="px-4 py-3 border-t border-gold-100 bg-cream/50">
                        <div class="text-xs font-semibold uppercase tracking-wider text-ink/40 mb-2">Lịch sử thu tiền</div>
                        @foreach ($cs->payments->sortByDesc('paid_at') as $payment)
                            <div class="text-xs text-ink/60 flex items-center gap-2 py-0.5">
                                <span class="font-mono text-green-700 font-semibold">{{ number_format($payment->amount) }}₫</span>
                                <span>{{ \App\Models\Payment::METHODS[$payment->method] }}</span>
                                <span>· {{ $payment->paid_at->format('d/m/Y') }}</span>
                                <span>· thu bởi {{ $payment->collector->name }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <p class="text-sm text-ink/40 italic">Chưa gắn dịch vụ nào cho khách này.</p>
        @endforelse
    </div>
</div>
