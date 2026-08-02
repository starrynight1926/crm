<?php

use App\Models\BookingLog;
use App\Models\CallLog;
use App\Models\Lead;
use App\Models\LeadPhaseClosure;
use App\Models\Service;
use App\Models\StaffMember;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Phase 6.21 — Customer Flow 7 phase panel (2026-07-30).
 * Design: docs/design/customer_flow_30-07-2026.md
 * Mockup: docs/mockups/customer_flow_30-07-2026.html
 *
 * Panel này hiển thị arrow-breadcrumb 7 phase + 7 tab-phase + form add call/booking log
 * + nút Kết thúc phase / Lưu chốt N phase / Lùi phase (Admin vận hành).
 * Đặt TRÊN màn chi tiết KH, không đụng lead-detail/lead-form cũ.
 */
new class extends Component
{
    public Lead $lead;
    public int $activePhase = 1;

    // State form thêm call log
    public string $newCallStatus = CallLog::STATUS_THANH_CONG;
    public string $newCallNote = '';

    // State form thêm booking log
    public string $newBookingStatus = BookingLog::STATUS_CHO_XAC_NHAN;
    public string $newBookingScheduledAt = '';
    public ?int $newBookingDoctorId = null;
    public ?int $newBookingServiceId = null;
    public string $newBookingNote = '';

    // State check-in note
    public string $checkinNote = '';

    public function mount(Lead $lead): void
    {
        abort_unless($lead->isVisibleTo(auth()->user()), 403);
        $this->lead = $lead;
        // Default tab = phase hiện tại (hoặc openFrom nếu bulk mode)
        $this->activePhase = $lead->isBulkOpen() ? $lead->openFrom() : (int) $lead->phase;
        if ($this->activePhase < 1 || $this->activePhase > 7) $this->activePhase = 1;
    }

    public function selectPhase(int $idx): void
    {
        if ($idx < 1 || $idx > 7) return;
        if ($this->lead->phaseState($idx) === 'skipped') return;
        $this->activePhase = $idx;
    }

    public function addCallLog(): void
    {
        $user = auth()->user();
        if (! $this->lead->canLogCall($user)) {
            session()->flash('cf_error', 'Bạn không có quyền ghi log gọi cho lead này.');
            return;
        }
        $this->validate([
            'newCallStatus' => 'required|in:' . implode(',', array_keys(CallLog::STATUSES)),
            'newCallNote'   => 'nullable|string|max:1000',
        ]);
        CallLog::create([
            'lead_id'   => $this->lead->id,
            'user_id'   => $user->id,
            'status'    => $this->newCallStatus,
            'note'      => $this->newCallNote ?: null,
            'called_at' => now(),
        ]);
        $this->newCallNote = '';
        $this->lead->refresh();
        session()->flash('cf_ok', 'Đã ghi log cuộc gọi.');
    }

    public function addBookingLog(): void
    {
        $user = auth()->user();
        if (! $this->lead->canLogBooking($user)) {
            session()->flash('cf_error', 'Bạn không có quyền ghi log booking cho lead này.');
            return;
        }
        $this->validate([
            'newBookingStatus'      => 'required|in:' . implode(',', array_keys(BookingLog::STATUSES)),
            'newBookingScheduledAt' => 'nullable|date',
            'newBookingDoctorId'    => 'nullable|exists:staff_members,id',
            'newBookingServiceId'   => 'nullable|exists:services,id',
            'newBookingNote'        => 'nullable|string|max:1000',
        ]);
        BookingLog::create([
            'lead_id'      => $this->lead->id,
            'user_id'      => $user->id,
            'status'       => $this->newBookingStatus,
            'scheduled_at' => $this->newBookingScheduledAt ?: null,
            'doctor_id'    => $this->newBookingDoctorId,
            'service_id'   => $this->newBookingServiceId,
            'note'         => $this->newBookingNote ?: null,
        ]);
        BookingLog::syncLeadBookingStatus($this->lead->id);
        $this->reset(['newBookingScheduledAt', 'newBookingDoctorId', 'newBookingServiceId', 'newBookingNote']);
        $this->lead->refresh();
        session()->flash('cf_ok', 'Đã ghi log booking. Đã đồng bộ booking_status.');
    }

    public function bulkSavePhases(): void
    {
        try {
            $closed = $this->lead->bulkSave(auth()->user());
            $this->lead->refresh();
            $this->activePhase = min((int) $this->lead->phase, 5);
            session()->flash('cf_ok', 'Đã chốt ' . count($closed) . ' phase (từ ' . min($closed) . ' đến ' . max($closed) . ').');
        } catch (\Throwable $e) {
            session()->flash('cf_error', $e->getMessage());
        }
    }

    public function closePhase(int $idx): void
    {
        try {
            $this->lead->closePhase($idx, auth()->user());
            $this->lead->refresh();
            $this->activePhase = min((int) $this->lead->phase, 5);
            session()->flash('cf_ok', 'Đã kết thúc phase ' . $idx . '.');
        } catch (\Throwable $e) {
            session()->flash('cf_error', $e->getMessage());
        }
    }

    public function rollbackToPhase(int $idx): void
    {
        try {
            $this->lead->rollbackTo($idx, auth()->user());
            $this->lead->refresh();
            $this->activePhase = $idx;
            session()->flash('cf_ok', 'Đã lùi phase về ' . $idx . '.');
        } catch (\Throwable $e) {
            session()->flash('cf_error', $e->getMessage());
        }
    }

    public function markReturning(): void
    {
        try {
            $this->lead->markReturning(auth()->user());
            $this->lead->refresh();
            $this->activePhase = 3;
            session()->flash('cf_ok', 'Đã khởi động lần thăm khám mới.');
        } catch (\Throwable $e) {
            session()->flash('cf_error', $e->getMessage());
        }
    }

    public function with(): array
    {
        $user = auth()->user();
        return [
            'phases'         => Lead::CF_PHASE_LABELS,
            'callLogs'       => $this->lead->callLogs()->with('user')->orderByDesc('called_at')->get(),
            'bookingLogs'    => $this->lead->bookingLogs()->with(['user', 'doctor', 'service'])->orderByDesc('scheduled_at')->get(),
            'closures'       => $this->lead->phaseClosures()->with('closer')->get()->keyBy('phase'),
            'doctors'        => StaffMember::where('role', 'doctor')->where('active', true)->orderBy('name')->get(),
            'services'       => Service::where('active', true)->orderBy('name')->get(),
            'canLogCall'     => $this->lead->canLogCall($user),
            'canLogBooking'  => $this->lead->canLogBooking($user),
            'canRollback'    => $user->hasPermission(Lead::CF_ROLLBACK_PERM),
            'isBulkOpen'     => $this->lead->isBulkOpen(),
            'startPhase'     => $this->lead->startPhase(),
            'openFrom'       => $this->lead->openFrom(),
        ];
    }
}; ?>

<div class="bg-white border border-gold-200 rounded-xl shadow-card p-5 mb-4"
     x-data="{ activePhase: @entangle('activePhase') }">

    {{-- ========= ALERTS ========= --}}
    @if (session('cf_ok'))
        <div class="mb-3 px-3 py-2 bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded">
            ✓ {{ session('cf_ok') }}
        </div>
    @endif
    @if (session('cf_error'))
        <div class="mb-3 px-3 py-2 bg-red-50 border border-red-200 text-red-800 text-sm rounded">
            ✗ {{ session('cf_error') }}
        </div>
    @endif

    {{-- ========= HEADER: title + is_first_visit + markReturning ========= --}}
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
        <div>
            <div class="text-xs uppercase tracking-wide text-ink/50 font-semibold">Customer Flow</div>
            <div class="text-sm text-ink/70 mt-0.5">
                Đang ở <b>{{ $lead->customerFlowLabel() }}</b>
                @if ($isBulkOpen)
                    <span class="ml-1 text-xs px-2 py-0.5 rounded bg-blue-100 text-blue-700">Mở thông phase {{ $openFrom }}→{{ $startPhase }}</span>
                @endif
                @if (! $lead->is_first_visit)
                    <span class="ml-1 text-xs px-2 py-0.5 rounded bg-purple-100 text-purple-700">Khách quay lại (lần 2+)</span>
                @endif
            </div>
        </div>
        <div class="flex items-center gap-2">
            @if ($lead->is_first_visit && (int) $lead->phase === 5 && $closures->has(5))
                <button wire:click="markReturning" onclick="return confirm('Khởi động lần thăm khám mới? Lead sẽ reset về phase 3 (Gọi điện), lịch sử cũ giữ nguyên.')"
                        class="text-xs bg-purple-600 hover:bg-purple-700 text-white font-semibold px-3 py-1.5 rounded">
                    Khởi động lần thăm khám mới
                </button>
            @endif
        </div>
    </div>

    {{-- ========= ARROW BREADCRUMB ========= --}}
    <div class="flex gap-1 mb-4 overflow-x-auto pb-1">
        @foreach ($phases as $idx => $label)
            @php
                $state = $lead->phaseState($idx);
                $classes = match ($state) {
                    'done'     => 'bg-emerald-500 text-white',
                    'current'  => 'bg-amber-400 text-white',
                    'open'     => 'bg-blue-400 text-white',
                    'pending'  => 'bg-slate-200 text-slate-600',
                    'skipped'  => 'bg-slate-100 text-slate-400 line-through cursor-not-allowed',
                    'notbuilt' => 'bg-slate-300 text-slate-500 opacity-60 cursor-not-allowed',
                };
                $isDisabled = in_array($state, ['skipped', 'notbuilt'], true);
                $clip = $idx === 1 ? 'polygon(0 0,calc(100% - 14px) 0,100% 50%,calc(100% - 14px) 100%,0 100%)'
                      : ($idx === 7 ? 'polygon(0 0,100% 0,100% 100%,0 100%,14px 50%)'
                      : 'polygon(0 0,calc(100% - 14px) 0,100% 50%,calc(100% - 14px) 100%,0 100%,14px 50%)');
            @endphp
            <button type="button"
                    @if (! $isDisabled) wire:click="selectPhase({{ $idx }})" @endif
                    :class="{ 'ring-2 ring-offset-1 ring-indigo-500': activePhase === {{ $idx }} }"
                    @if ($isDisabled) disabled @endif
                    style="clip-path: {{ $clip }};"
                    class="flex-1 min-w-[130px] px-4 py-2.5 text-left transition {{ $classes }}">
                <div class="text-[10px] uppercase opacity-80 leading-none">Phase {{ $idx }}</div>
                <div class="text-xs font-semibold mt-0.5 leading-tight">{{ $label }}</div>
                @if ($state === 'done')
                    <div class="text-[10px] opacity-90 mt-0.5">✓ {{ $closures[$idx]?->closed_at?->format('d/m H:i') }}</div>
                @endif
            </button>
        @endforeach
    </div>

    {{-- ========= TAB CONTENT ========= --}}
    <div class="border border-slate-200 rounded-lg overflow-hidden">
        {{-- Tab bar text (mirror of breadcrumb) --}}
        <div class="flex border-b border-slate-200 bg-slate-50 overflow-x-auto text-sm">
            @foreach ($phases as $idx => $label)
                @php $state = $lead->phaseState($idx); @endphp
                <button type="button"
                        @if (! in_array($state, ['skipped', 'notbuilt'], true)) wire:click="selectPhase({{ $idx }})" @endif
                        @class([
                            'px-4 py-2 whitespace-nowrap border-b-2',
                            'border-indigo-600 text-indigo-700 font-semibold bg-white' => $activePhase === $idx,
                            'border-transparent text-ink/60 hover:text-ink' => $activePhase !== $idx && ! in_array($state, ['skipped', 'notbuilt']),
                            'border-transparent text-slate-300 cursor-not-allowed' => in_array($state, ['skipped', 'notbuilt']),
                        ])>
                    {{ $idx }}. {{ $label }}
                    @if ($state === 'done')<span class="text-emerald-500 ml-1">✓</span>@endif
                    @if ($state === 'notbuilt')<span class="text-[10px] text-slate-400 ml-1">(chưa build)</span>@endif
                </button>
            @endforeach
        </div>

        <div class="p-4 min-h-[200px]">

            {{-- ==================== PHASE 1: Thêm mới ==================== --}}
            @if ($activePhase === 1)
                <h3 class="font-semibold text-ink/80 mb-3">Phase 1 — Thêm mới lead</h3>
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <label class="block text-xs text-ink/50 mb-1">Họ tên</label>
                        <input class="w-full border border-slate-300 rounded px-3 py-2 bg-slate-50" value="{{ $lead->name }}" readonly>
                    </div>
                    <div>
                        <label class="block text-xs text-ink/50 mb-1">SĐT</label>
                        <input class="w-full border border-slate-300 rounded px-3 py-2 bg-slate-50" value="{{ $lead->phone }}" readonly>
                    </div>
                    <div>
                        <label class="block text-xs text-ink/50 mb-1">Nguồn</label>
                        <input class="w-full border border-slate-300 rounded px-3 py-2 bg-slate-50" value="{{ Lead::SOURCE_GROUPS[$lead->source_group] ?? $lead->source_group }}" readonly>
                    </div>
                    <div>
                        <label class="block text-xs text-ink/50 mb-1">Đến lần đầu</label>
                        <div class="px-3 py-2 border border-slate-300 rounded bg-slate-50">
                            {{ $lead->is_first_visit ? 'CÓ (khách mới)' : 'KHÔNG (khách quay lại)' }}
                        </div>
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs text-ink/50 mb-1">Insight ban đầu</label>
                        <div class="px-3 py-2 border border-slate-300 rounded bg-slate-50 min-h-[60px] text-ink/70">
                            {{ $lead->insight ?: '—' }}
                        </div>
                    </div>
                </div>
                <p class="text-xs text-ink/40 italic mt-3">Chỉnh sửa thông tin cá nhân ở màn "Cập nhật khách hàng".</p>
            @endif

            {{-- ==================== PHASE 2: Chia số ==================== --}}
            @if ($activePhase === 2)
                <h3 class="font-semibold text-ink/80 mb-3">Phase 2 — Chia số (Phân phối)</h3>
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <label class="block text-xs text-ink/50 mb-1">Nguồn</label>
                        <div class="px-3 py-2 border border-slate-300 rounded bg-slate-50">{{ Lead::SOURCE_GROUPS[$lead->source_group] ?? '—' }}</div>
                    </div>
                    <div>
                        <label class="block text-xs text-ink/50 mb-1">Team / Cơ sở</label>
                        <div class="px-3 py-2 border border-slate-300 rounded bg-slate-50">{{ $lead->orgUnit->name ?? '—' }}</div>
                    </div>
                    <div>
                        <label class="block text-xs text-ink/50 mb-1">Sale phụ trách (owner)</label>
                        <div class="px-3 py-2 border border-slate-300 rounded bg-slate-50">{{ $lead->owner->name ?? '— chưa chia —' }}</div>
                    </div>
                    <div>
                        <label class="block text-xs text-ink/50 mb-1">Người nhận (receiver)</label>
                        <div class="px-3 py-2 border border-slate-300 rounded bg-slate-50">{{ $lead->receiver->name ?? '—' }}</div>
                    </div>
                </div>
                <p class="text-xs text-ink/40 italic mt-3">Việc chia số thực hiện ở màn "Kho lead" hoặc "Danh sách khách hàng".</p>
            @endif

            {{-- ==================== PHASE 3: Gọi điện ==================== --}}
            @if ($activePhase === 3)
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold text-ink/80">Phase 3 — Gọi điện <span class="text-xs text-ink/50 font-normal">({{ $callLogs->count() }} cuộc)</span></h3>
                </div>

                @if ($callLogs->isNotEmpty())
                    <div class="border border-slate-200 rounded divide-y mb-4 text-sm">
                        @foreach ($callLogs as $cl)
                            <div class="p-3 flex items-start gap-3">
                                @php
                                    $badge = match($cl->status) {
                                        'thanh_cong' => 'bg-emerald-100 text-emerald-700',
                                        'that_bai' => 'bg-red-100 text-red-700',
                                        default => 'bg-slate-100 text-slate-600',
                                    };
                                @endphp
                                <span class="text-xs px-2 py-0.5 rounded whitespace-nowrap {{ $badge }}">{{ $cl->statusLabel() }}</span>
                                <div class="flex-1">
                                    <div class="text-xs text-ink/50">{{ $cl->called_at->format('d/m/Y H:i') }} · {{ $cl->user->name ?? 'system' }}</div>
                                    @if ($cl->note)<div class="text-ink/80 mt-1">{{ $cl->note }}</div>@endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($canLogCall)
                    <div class="border border-dashed border-slate-300 bg-slate-50 rounded p-3 space-y-2">
                        <div class="text-xs font-semibold text-ink/60">Thêm cuộc gọi mới</div>
                        <div class="grid grid-cols-3 gap-2">
                            <select wire:model="newCallStatus" class="border border-slate-300 rounded px-2 py-1.5 text-sm">
                                @foreach (CallLog::STATUSES as $k => $lbl)
                                    <option value="{{ $k }}">{{ $lbl }}</option>
                                @endforeach
                            </select>
                            <input wire:model="newCallNote" placeholder="Ghi chú cuộc gọi..." class="col-span-2 border border-slate-300 rounded px-2 py-1.5 text-sm">
                        </div>
                        <button wire:click="addCallLog" class="text-sm bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-1.5 rounded">+ Ghi cuộc gọi</button>
                    </div>
                @else
                    <p class="text-xs text-ink/40 italic">Bạn không có quyền ghi log gọi cho lead này (cần là owner, QL Sale, hoặc Admin vận hành).</p>
                @endif

                @if ($lead->status_1 || $lead->status_2)
                    <div class="mt-4 border-t border-slate-200 pt-3">
                        <div class="text-xs font-semibold text-ink/50 mb-1">Ghi chú cũ (legacy status_1/2)</div>
                        @if ($lead->status_1)<div class="text-xs text-ink/60">• Lần 1: {{ $lead->status_1 }}</div>@endif
                        @if ($lead->status_2)<div class="text-xs text-ink/60">• Lần 2: {{ $lead->status_2 }}</div>@endif
                    </div>
                @endif
            @endif

            {{-- ==================== PHASE 4: Booking ==================== --}}
            @if ($activePhase === 4)
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold text-ink/80">Phase 4 — Booking thăm khám <span class="text-xs text-ink/50 font-normal">({{ $bookingLogs->count() }} record)</span></h3>
                </div>

                @if ($bookingLogs->isNotEmpty())
                    <div class="border border-slate-200 rounded divide-y mb-4 text-sm">
                        @foreach ($bookingLogs as $bl)
                            <div class="p-3 flex items-start gap-3">
                                @php
                                    $badge = match($bl->status) {
                                        'da_xac_nhan' => 'bg-emerald-100 text-emerald-700',
                                        'huy_doi_lich' => 'bg-red-100 text-red-700',
                                        default => 'bg-amber-100 text-amber-700',
                                    };
                                @endphp
                                <span class="text-xs px-2 py-0.5 rounded whitespace-nowrap {{ $badge }}">{{ $bl->statusLabel() }}</span>
                                <div class="flex-1">
                                    <div class="text-xs text-ink/50">
                                        Lịch: {{ $bl->scheduled_at?->format('d/m/Y H:i') ?? 'chưa đặt' }}
                                        · BS: {{ $bl->doctor->name ?? '—' }}
                                        · DV: {{ $bl->service->name ?? '—' }}
                                        · bởi {{ $bl->user->name ?? 'system' }}
                                    </div>
                                    @if ($bl->note)<div class="text-ink/80 mt-1">{{ $bl->note }}</div>@endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($canLogBooking)
                    <div class="border border-dashed border-slate-300 bg-slate-50 rounded p-3 space-y-2">
                        <div class="text-xs font-semibold text-ink/60">Thêm booking mới</div>
                        <div class="grid grid-cols-4 gap-2">
                            <select wire:model="newBookingStatus" class="border border-slate-300 rounded px-2 py-1.5 text-sm">
                                @foreach (BookingLog::STATUSES as $k => $lbl)
                                    <option value="{{ $k }}">{{ $lbl }}</option>
                                @endforeach
                            </select>
                            <input type="datetime-local" wire:model="newBookingScheduledAt" class="border border-slate-300 rounded px-2 py-1.5 text-sm">
                            <select wire:model="newBookingDoctorId" class="border border-slate-300 rounded px-2 py-1.5 text-sm">
                                <option value="">— Bác sĩ —</option>
                                @foreach ($doctors as $d)
                                    <option value="{{ $d->id }}">{{ $d->name }}</option>
                                @endforeach
                            </select>
                            <select wire:model="newBookingServiceId" class="border border-slate-300 rounded px-2 py-1.5 text-sm">
                                <option value="">— Dịch vụ —</option>
                                @foreach ($services as $s)
                                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <input wire:model="newBookingNote" placeholder="Ghi chú sau booking..." class="w-full border border-slate-300 rounded px-2 py-1.5 text-sm">
                        <button wire:click="addBookingLog" class="text-sm bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-1.5 rounded">+ Ghi booking</button>
                    </div>
                @else
                    <p class="text-xs text-ink/40 italic">Bạn không có quyền ghi log booking cho lead này.</p>
                @endif
            @endif

            {{-- ==================== PHASE 5: Check-in ==================== --}}
            @if ($activePhase === 5)
                <h3 class="font-semibold text-ink/80 mb-3">Phase 5 — Check-in <span class="text-xs text-ink/50 font-normal">(tạm thời là bước cuối)</span></h3>
                <p class="text-sm text-ink/60 mb-2">
                    Sau khi Lễ tân check-in cho khách, bấm nút "Kết thúc phase 5" ở dưới để hoàn tất luồng.
                    @if ($closures->has(5))
                        <br><span class="text-emerald-600">✓ Đã check-in: {{ $closures[5]->closed_at->format('d/m/Y H:i') }} bởi {{ $closures[5]->closer->name ?? 'system' }}</span>
                    @endif
                </p>
            @endif

            {{-- ==================== PHASE 6 + 7 placeholder ==================== --}}
            @if ($activePhase === 6 || $activePhase === 7)
                <div class="text-center py-8">
                    <div class="inline-block px-6 py-8 border-2 border-dashed border-slate-300 rounded-lg text-slate-400">
                        <div class="text-sm font-semibold">Phase {{ $activePhase }} — chưa build</div>
                        <div class="text-xs mt-1">
                            @if ($activePhase === 6)
                                Sẽ tích hợp với module "Dịch vụ tiềm năng & Upsell" hiện có.
                            @else
                                Sẽ tích hợp với module "Liệu trình" hiện có.
                            @endif
                        </div>
                    </div>
                </div>
            @endif

        </div>

        {{-- ========= ACTION BAR ========= --}}
        <div class="border-t border-slate-200 bg-slate-50 p-3 flex flex-wrap items-center gap-2">
            @if ($isBulkOpen && $activePhase >= $openFrom && $activePhase <= $startPhase)
                <button wire:click="bulkSavePhases"
                        class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-sm px-4 py-2 rounded">
                    Lưu — chốt {{ $startPhase - $openFrom + 1 }} phase (từ {{ $openFrom }} đến {{ $startPhase }})
                </button>
            @elseif (! $isBulkOpen && $activePhase === (int) $lead->phase && $activePhase <= 5)
                <button wire:click="closePhase({{ $activePhase }})"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-sm px-4 py-2 rounded">
                    Kết thúc phase {{ $activePhase }} — {{ $phases[$activePhase] }}
                </button>
            @endif

            @if ($canRollback && $activePhase < (int) $lead->phase && $closures->has($activePhase))
                <button wire:click="rollbackToPhase({{ $activePhase }})"
                        onclick="return confirm('Lùi phase về {{ $phases[$activePhase] }}? Tất cả closure từ phase {{ $activePhase }} trở đi sẽ bị xóa.')"
                        class="bg-white border border-red-400 text-red-600 hover:bg-red-50 font-semibold text-sm px-4 py-2 rounded">
                    ⤺ Lùi phase về "{{ $phases[$activePhase] }}" (Admin vận hành)
                </button>
            @endif

            <div class="ml-auto text-xs text-ink/40">
                @if ($lead->phaseState($activePhase) === 'done')
                    Phase này đã chốt — chỉ xem.
                @elseif ($lead->phaseState($activePhase) === 'pending')
                    Chưa tới phase này — chốt phase {{ $lead->phase }} trước.
                @elseif ($lead->phaseState($activePhase) === 'notbuilt')
                    Phase chưa build.
                @endif
            </div>
        </div>
    </div>
</div>
