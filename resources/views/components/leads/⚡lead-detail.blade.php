<?php

use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\ContributionTemplate;
use App\Models\Lead;
use App\Models\LeadStatusLog;
use App\Models\Payment;
use App\Services\ContributionService;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public Lead $lead;

    public string $newNote = '';

    /** Ảnh đính kèm ghi chú (đánh giá trước/sau khi dùng dịch vụ). */
    public array $noteImages = [];

    /** Cờ "Khách trở lại" cho ghi chú này — đếm để ra tần suất quay lại. */
    public bool $noteIsReturn = false;

    /** Cờ "Khách tới lần đầu". Exclusive với noteIsReturn. */
    public bool $noteIsFirstVisit = false;

    /** Mã tiếp đón — bắt buộc khi tick "Khách trở lại". */
    public string $noteReceptionCode = '';

    public bool $phoneRevealed = false;

    public function mount(Lead $lead): void
    {
        abort_unless($lead->isVisibleTo(auth()->user()) || auth()->user()->hasPermission('lead.view_phone'), 403);
        $this->lead = $lead;
    }

    /** Xem SĐT đầy đủ — mọi lần xem đều ghi audit log. */
    public function revealPhone(): void
    {
        abort_unless($this->lead->canViewFullPhone(auth()->user()), 403);

        AuditLog::record('view_phone', $this->lead);
        $this->phoneRevealed = true;
    }

    /** Chỉ sửa/chăm được lead trong phạm vi mình (không phải lead đang nằm kho chung/ngoài scope). */
    private function canEditLead(): bool
    {
        return auth()->user()->hasPermission('lead.update') && $this->lead->isVisibleTo(auth()->user());
    }

    public function updatedNoteIsReturn(): void
    {
        if ($this->noteIsReturn) {
            $this->noteIsFirstVisit = false;
        }
    }

    public function updatedNoteIsFirstVisit(): void
    {
        if ($this->noteIsFirstVisit) {
            $this->noteIsReturn = false;
            $this->noteReceptionCode = '';
        }
    }

    public function addNote(): void
    {
        abort_unless($this->canEditLead(), 403);
        $this->validate([
            'newNote' => 'nullable|string|max:2000',
            'noteImages' => 'array|max:10',
            'noteImages.*' => 'image|max:5120',
            'noteReceptionCode' => $this->noteIsReturn
                ? ['required', 'string', 'max:60', 'unique:lead_status_logs,reception_code']
                : ['nullable'],
        ], [
            'noteReceptionCode.required' => 'Phải nhập mã tiếp đón khi tick "Khách trở lại".',
            'noteReceptionCode.unique' => 'Mã tiếp đón này đã tồn tại, nhập mã khác.',
        ], ['newNote' => 'ghi chú', 'noteImages.*' => 'ảnh', 'noteReceptionCode' => 'mã tiếp đón']);

        if (trim($this->newNote) === '' && $this->noteImages === []) {
            $this->addError('newNote', 'Nhập ghi chú hoặc đính kèm ít nhất 1 ảnh.');
            return;
        }

        $paths = [];
        foreach ($this->noteImages as $img) {
            $paths[] = $img->store('lead-notes/' . $this->lead->id, 'public');
        }

        LeadStatusLog::record(
            $this->lead, 'note', $this->lead->note, $this->newNote ?: null, auth()->id(),
            $paths, $this->noteIsReturn, $this->noteIsReturn ? trim($this->noteReceptionCode) : null,
            $this->noteIsFirstVisit
        );
        $this->lead->update(['note' => $this->newNote ?: $this->lead->note, 'last_care_at' => now()]);

        $this->reset(['newNote', 'noteImages', 'noteIsReturn', 'noteIsFirstVisit', 'noteReceptionCode']);
        $this->lead->refresh();
    }

    /** Tần suất quay lại = số mã tiếp đón. */
    public function returnCount(): int
    {
        return LeadStatusLog::where('lead_id', $this->lead->id)->where('is_return', true)->count();
    }

    public function updateClassification(string $value): void
    {
        abort_unless($this->canEditLead(), 403);
        abort_unless(array_key_exists($value, Lead::CLASSIFICATIONS), 422);

        if ($value === $this->lead->classification) {
            return;
        }

        LeadStatusLog::record($this->lead, 'classification', $this->lead->classification, $value, auth()->id());
        $this->lead->update(['classification' => $value, 'last_care_at' => now()]);
        AuditLog::record('update', $this->lead, ['classification' => $value]);
        $this->lead->refresh();

        if ($value === 'close' && auth()->user()->hasPermission('contribution.set')) {
            $this->openContribution();
        }
    }

    // ---------- % đóng góp khi Close (Màn 10) ----------

    public bool $showContribution = false;

    /** @var array<int, array{user_id: int, name: string, role_label: string, percent: string}> */
    public array $contribRows = [];

    public function openContribution(): void
    {
        abort_unless(auth()->user()->hasPermission('contribution.set'), 403);

        $existing = Contribution::with('user')->where('lead_id', $this->lead->id)->get();

        if ($existing->isNotEmpty()) {
            $this->contribRows = $existing->map(fn ($c) => [
                'user_id' => $c->user_id, 'name' => $c->user->name,
                'role_label' => $c->role_label, 'percent' => (string) round((float) $c->percent, 2),
            ])->all();
        } else {
            $participants = app(ContributionService::class)->suggestParticipants($this->lead);
            $template = ContributionTemplate::firstWhere('is_default', true);
            $templatePercents = collect($template?->items ?? [])->pluck('percent', 'role_label');

            $this->contribRows = $participants->map(fn ($p) => [
                'user_id' => $p['user']->id,
                'name' => $p['user']->name,
                'role_label' => $p['role_label'],
                'percent' => (string) ($templatePercents[$p['role_label']] ?? 0),
            ])->all();
        }

        $this->resetErrorBag();
        $this->showContribution = true;
    }

    public function saveContribution(): void
    {
        abort_unless(auth()->user()->hasPermission('contribution.set'), 403);

        try {
            app(ContributionService::class)->save(
                $this->lead,
                $this->contribRows,
                auth()->id(),
                $this->lead->customerServices()->latest('id')->value('id')
            );
        } catch (\InvalidArgumentException $e) {
            $this->addError('contribRows', $e->getMessage());
            return;
        }

        AuditLog::record('contribution_set', $this->lead);
        $this->showContribution = false;
        session()->flash('status', 'Đã lưu bảng % đóng góp.');
    }

    public function with(): array
    {
        $customFields = \App\Models\CustomField::applicableTo($this->lead->orgUnit);
        $customValues = $this->lead->customValues->pluck('value', 'custom_field_id');

        $this->lead->load([
            'facility.parent', 'doctor.facility.parent',
            'consultant1.facility.parent', 'consultant2.facility.parent', 'consultant3.facility.parent',
            'performingDoctor.facility.parent',
            'upsells.staffMember', 'upsells.service',
        ]);

        $lastPayment = Payment::where('lead_id', $this->lead->id)->orderByDesc('paid_at')->first();
        $totalPaid = Payment::where('lead_id', $this->lead->id)->sum('amount');
        $paymentMethods = Payment::where('lead_id', $this->lead->id)
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')
            ->pluck('total', 'method');

        return [
            'logs' => $this->lead->statusLogs()->with('user')->paginate(15, pageName: 'logPage'),
            'canEdit' => $this->canEditLead(),
            'customFields' => $customFields,
            'customValues' => $customValues,
            'contributions' => Contribution::with('user')->where('lead_id', $this->lead->id)->orderByDesc('percent')->get(),
            'canSetContribution' => auth()->user()->hasPermission('contribution.set'),
            'lastPayment' => $lastPayment,
            'totalPaid' => $totalPaid,
            'paymentMethods' => $paymentMethods,
        ];
    }
};
?>

<div>
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <div class="text-sm text-ink/50 mb-1">
                <a href="{{ route('leads.index') }}" class="hover:text-gold-600">Khách hàng</a>
                <span class="mx-1">›</span>
                <span class="text-gold-700 font-medium">Chi tiết khách hàng</span>
            </div>
            <h1 class="text-3xl font-bold">{{ $lead->name }}</h1>
            @if ($lead->code)
                <div class="font-mono text-sm text-gold-700 mt-1">{{ $lead->code }}</div>
            @endif
        </div>
        @if ($canEdit)
            <a href="{{ route('leads.edit', $lead) }}"
               class="flex items-center gap-2 text-sm font-semibold text-ink/70 border border-gold-200 px-5 py-2.5 rounded-md hover:bg-gold-50">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487z"/></svg>
                Cập nhật thông tin
            </a>
        @endif
    </div>

    @if (session('status'))
        <p class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-4 py-2">{{ session('status') }}</p>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
        {{-- ═══ CỘT TRÁI: Thông tin khách hàng ═══ --}}
        <div class="space-y-6">
            {{-- Card chính: Thông tin cơ bản + Nhân sự + Trạng thái --}}
            <div class="bg-white border-l-4 border-gold-600 border-y border-r border-y-gold-200 border-r-gold-200 rounded-xl shadow-card p-6">
                <h2 class="text-xs font-bold uppercase tracking-[0.15em] text-ink/60 border-b border-gold-100 pb-3 mb-4">Thông tin khách hàng</h2>
                <dl class="space-y-3 text-sm">
                    {{-- SĐT nổi bật --}}
                    <div class="flex items-center gap-3 bg-gold-50 border border-gold-200 rounded-lg px-4 py-2.5">
                        <svg class="w-4 h-4 text-gold-600 shrink-0" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
                        <span class="font-mono font-bold text-gold-800 text-base">
                            @if ($phoneRevealed) {{ $lead->phone }}
                            @else {{ \App\Models\Lead::maskPhone($lead->phone) }}
                            @endif
                        </span>
                        @if ($lead->canViewFullPhone(auth()->user()))
                            @if ($phoneRevealed)
                                <button wire:click="$set('phoneRevealed', false)" class="text-xs font-semibold text-ink/50 border border-gray-300 px-2 py-0.5 rounded hover:bg-gray-100 ml-auto">Ẩn số</button>
                            @else
                                <button wire:click="revealPhone" class="text-xs font-semibold text-gold-600 border border-gold-300 px-2 py-0.5 rounded hover:bg-gold-100 ml-auto" title="Ghi audit log khi xem">Hiện số</button>
                            @endif
                        @endif
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Ngày</dt>
                            <dd class="font-medium">{{ $lead->received_date->format('d/m/Y') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Nguồn</dt>
                            <dd class="font-medium">{{ $lead->ad_source ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Tần suất quay lại</dt>
                            <dd class="font-bold text-gold-700">{{ $this->returnCount() }}</dd>
                        </div>
                    </div>

                    @if ($lead->page || $lead->camp)
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Page</dt>
                            <dd class="font-medium">{{ $lead->page ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Camp</dt>
                            <dd class="font-medium">{{ $lead->camp ?: '—' }}</dd>
                        </div>
                    </div>
                    @endif

                    @if ($lead->insight || $lead->link)
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Insight</dt>
                        <dd class="font-medium">
                            {{ $lead->insight ?: '' }}
                            @if ($lead->link)
                                <a href="{{ $lead->link }}" target="_blank" rel="noopener" class="block text-gold-600 underline truncate text-xs mt-0.5">{{ $lead->link }}</a>
                            @endif
                        </dd>
                    </div>
                    @endif

                    {{-- Nhóm nhân sự --}}
                    <div class="border-t border-gold-100 pt-3 mt-1">
                        <p class="text-xs font-bold uppercase tracking-wider text-ink/40 mb-2">Cơ sở & Nhân sự</p>
                        <div class="space-y-2">
                            @if ($lead->facility)
                            <div class="flex items-center gap-2">
                                <svg class="w-3.5 h-3.5 text-ink/30 shrink-0" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3H21"/></svg>
                                <span class="font-medium text-sm">
                                    @if ($lead->facility->parent) {{ $lead->facility->parent->name }} › @endif{{ $lead->facility->name }}
                                </span>
                            </div>
                            @endif
                            @if ($lead->doctor)
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] font-bold uppercase tracking-wider bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded shrink-0">BS tư vấn</span>
                                <span class="font-medium text-sm">{{ $lead->doctor->displayLabel() }}</span>
                            </div>
                            @endif
                            @foreach ([$lead->consultant1, $lead->consultant2, $lead->consultant3] as $i => $cv)
                                @if ($cv)
                                <div class="flex items-center gap-2">
                                    <span class="text-[10px] font-bold uppercase tracking-wider bg-green-100 text-green-700 px-1.5 py-0.5 rounded shrink-0">CVTV{{ $i + 1 }}</span>
                                    <span class="font-medium text-sm">{{ $cv->displayLabel() }}</span>
                                </div>
                                @endif
                            @endforeach
                            @if ($lead->service_name)
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] font-bold uppercase tracking-wider bg-gold-100 text-gold-700 px-1.5 py-0.5 rounded shrink-0">Dịch vụ</span>
                                <span class="font-medium text-sm">{{ $lead->service_name }}</span>
                            </div>
                            @endif
                        </div>
                    </div>

                    {{-- Phân phối --}}
                    <div class="border-t border-gold-100 pt-3 mt-1">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Lead chia cho</dt>
                                <dd class="font-medium text-gold-700">{{ $lead->owner?->name ?: 'Chưa chia' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Người nhận</dt>
                                <dd class="font-medium">{{ $lead->receiver?->name ?: 'Hệ thống' }}</dd>
                            </div>
                        </div>
                    </div>

                    {{-- Trạng thái --}}
                    <div class="border-t border-gold-100 pt-3 mt-1">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Tình trạng 1</dt>
                                <dd class="font-medium">{{ $lead->status_1 ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Tình trạng 2</dt>
                                <dd class="font-medium">{{ $lead->status_2 ?: '—' }}</dd>
                            </div>
                        </div>
                        @if ($lastPayment)
                        <div class="mt-2">
                            <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Ngày ghi nhận doanh thu</dt>
                            <dd class="font-medium">{{ $lastPayment->paid_at->format('d/m/Y') }}</dd>
                        </div>
                        @endif
                        <div class="mt-3">
                            <dt class="text-xs uppercase tracking-wider text-ink/40 mb-1">Phân loại kết quả</dt>
                            <dd>
                                @if ($canEdit)
                                    <select wire:change="updateClassification($event.target.value)"
                                            class="border border-gold-200 rounded-full px-3 py-1.5 text-sm bg-gold-50 text-gold-800 font-semibold focus:outline-none focus:border-gold-500">
                                        @foreach (\App\Models\Lead::CLASSIFICATIONS as $key => $label)
                                            <option value="{{ $key }}" @selected($lead->classification === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="text-sm bg-gold-50 border border-gold-200 text-gold-800 font-semibold px-3 py-1 rounded-full">{{ $lead->classificationLabel() }}</span>
                                @endif
                            </dd>
                        </div>
                    </div>
                </dl>
            </div>

            {{-- INSIGHT — gom vào 1 card nếu có --}}
            @if ($lead->birthday || $lead->address || $lead->medical_history || $lead->occupation)
            <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="text-xs font-bold uppercase tracking-[0.15em] text-ink/60 border-b border-gold-100 pb-3 mb-4">INSIGHT khách hàng</h2>
                <dl class="space-y-3 text-sm">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Ngày sinh</dt>
                            <dd class="font-medium">{{ $lead->birthday?->format('d/m/Y') ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Nghề nghiệp</dt>
                            <dd class="font-medium">{{ $lead->occupation ?: '—' }}</dd>
                        </div>
                    </div>
                    @if ($lead->address)
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Địa chỉ</dt>
                        <dd class="font-medium">{{ $lead->address }}</dd>
                    </div>
                    @endif
                    @if ($lead->medical_history)
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Khai thác tiền sử</dt>
                        <dd class="font-medium whitespace-pre-line">{{ $lead->medical_history }}</dd>
                    </div>
                    @endif
                </dl>

                {{-- LIỆU TRÌNH gom vào cùng card INSIGHT --}}
                @if ($lead->treatment_1 || $lead->treatment_2 || $lead->treatment_3 || $lead->treatment_4 || $lead->performingDoctor || $lead->quality_rating)
                <div class="border-t border-gold-100 mt-4 pt-4">
                    <p class="text-xs font-bold uppercase tracking-wider text-ink/40 mb-3">Liệu trình</p>
                    <dl class="space-y-2.5 text-sm">
                        <div class="grid grid-cols-4 gap-2">
                            @foreach (['treatment_1' => 'Lần 1', 'treatment_2' => 'Lần 2', 'treatment_3' => 'Lần 3', 'treatment_4' => 'Lần 4'] as $field => $label)
                            <div class="text-center">
                                <dt class="text-[10px] uppercase tracking-wider text-ink/40 mb-0.5">{{ $label }}</dt>
                                <dd class="font-medium text-xs">{{ $lead->{$field} ? $lead->{$field}->format('d/m') : '—' }}</dd>
                            </div>
                            @endforeach
                        </div>
                        @if ($lead->performingDoctor)
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] font-bold uppercase tracking-wider bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded shrink-0">BS thực hiện</span>
                            <span class="font-medium text-sm">{{ $lead->performingDoctor->displayLabel() }}</span>
                        </div>
                        @endif
                        @if ($lead->quality_rating)
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Đánh giá CLCM</dt>
                            <dd class="font-medium whitespace-pre-line">{{ $lead->quality_rating }}</dd>
                        </div>
                        @endif
                    </dl>
                </div>
                @endif
            </div>
            @endif

            {{-- DV tiềm năng & UPSELL + Tài chính — gom lại --}}
            @if ($lead->potential_service || $lead->upsells->isNotEmpty() || $totalPaid > 0)
            <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="text-xs font-bold uppercase tracking-[0.15em] text-ink/60 border-b border-gold-100 pb-3 mb-4">Tài chính & Dịch vụ phát sinh</h2>

                {{-- Tổng tiền thực trả --}}
                @if ($totalPaid > 0)
                <div class="bg-green-50 border border-green-200 rounded-lg px-4 py-3 mb-4">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold uppercase tracking-wider text-green-700">Tổng tiền thực trả</span>
                        <span class="font-mono font-bold text-lg text-green-700">{{ number_format($totalPaid, 0, ',', '.') }}₫</span>
                    </div>
                    @if ($paymentMethods->isNotEmpty())
                    <div class="mt-2 space-y-1">
                        @foreach ($paymentMethods as $method => $amount)
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-green-600">{{ \App\Models\Payment::METHODS[$method] ?? $method }}</span>
                                <span class="font-mono text-green-700">{{ number_format($amount, 0, ',', '.') }}₫</span>
                            </div>
                        @endforeach
                    </div>
                    @endif
                </div>
                @endif

                <dl class="space-y-3 text-sm">
                    @if ($lead->potential_service)
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Dịch vụ tiềm năng</dt>
                        <dd class="font-medium whitespace-pre-line">{{ $lead->potential_service }}</dd>
                    </div>
                    @endif

                    @if ($lead->upsells->isNotEmpty())
                    <div class="border-t border-gold-100 pt-3">
                        <dt class="text-xs uppercase tracking-wider text-ink/40 mb-2">DS Dịch vụ phát sinh</dt>
                        <div class="space-y-1.5">
                            @foreach ($lead->upsells as $up)
                                <div class="flex items-center justify-between bg-gold-50/50 border border-gold-100 rounded-lg px-3 py-2">
                                    <div>
                                        <span class="font-medium">{{ $up->service?->name ?? '—' }}</span>
                                        @if ($up->staffMember)
                                            <span class="text-xs text-ink/50 ml-1">— {{ $up->staffMember->name }}</span>
                                        @endif
                                    </div>
                                    <span class="font-mono font-semibold text-gold-700">{{ number_format($up->amount, 0, ',', '.') }}₫</span>
                                </div>
                            @endforeach
                        </div>
                        <div class="flex items-center justify-between border-t border-gold-200 mt-2 pt-2">
                            <span class="text-xs font-bold uppercase tracking-wider text-ink/60">Tổng phát sinh</span>
                            <span class="font-mono font-bold text-gold-700">{{ number_format($lead->upsells->sum('amount'), 0, ',', '.') }}₫</span>
                        </div>
                    </div>
                    @endif
                </dl>
            </div>
            @endif

            {{-- % đóng góp + Trường bổ sung --}}
            @if ($contributions->isNotEmpty() || $lead->classification === 'close')
                <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                    <div class="flex items-center justify-between border-b border-gold-100 pb-3 mb-4">
                        <h2 class="text-xs font-bold uppercase tracking-[0.15em] text-ink/60">% Đóng góp deal</h2>
                        @if ($canSetContribution)
                            <button wire:click="openContribution" class="text-xs font-semibold text-gold-700 border border-gold-300 hover:bg-gold-50 px-3 py-1.5 rounded-md">
                                {{ $contributions->isNotEmpty() ? 'Sửa' : 'Chia %' }}
                            </button>
                        @endif
                    </div>
                    @forelse ($contributions as $contribution)
                        <div class="flex items-center gap-3 py-1.5 text-sm">
                            <span class="flex-1 font-medium">{{ $contribution->user->name }}</span>
                            <span class="text-xs text-ink/50">{{ \App\Models\Contribution::ROLE_LABELS[$contribution->role_label] ?? $contribution->role_label }}</span>
                            <span class="font-mono font-bold text-gold-700">{{ round((float) $contribution->percent, 2) }}%</span>
                        </div>
                    @empty
                        <p class="text-sm text-ink/40 italic">Deal đã Close — chưa chia % đóng góp.</p>
                    @endforelse
                </div>
            @endif

            @if ($customFields->isNotEmpty())
                <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                    <h2 class="text-xs font-bold uppercase tracking-[0.15em] text-ink/60 border-b border-gold-100 pb-3 mb-4">
                        Trường bổ sung {{ $lead->orgUnit ? '(' . $lead->orgUnit->name . ')' : '' }}
                    </h2>
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        @foreach ($customFields as $field)
                            <div>
                                <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">
                                    {{ $field->label }}
                                    @if ($field->required)<span class="text-red-400">*</span>@endif
                                </dt>
                                <dd class="font-medium">{{ $customValues[$field->id] ?? '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endif
        </div>

        {{-- ═══ CỘT PHẢI: Tương tác + Dịch vụ ═══ --}}
        <div class="space-y-6">
            {{-- Thêm ghi chú --}}
            <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold flex items-center gap-2 mb-4">
                    <span class="w-9 h-9 rounded-full bg-gold-50 border border-gold-200 text-gold-600 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/></svg>
                    </span>
                    Thêm Ghi chú mới
                    <span class="ml-auto text-xs font-normal text-ink/50">Tần suất quay lại: <strong class="text-gold-700">{{ $this->returnCount() }}</strong></span>
                </h2>
                <textarea wire:model="newNote" rows="3" placeholder="Nhập nội dung tương tác hoặc ghi chú quan trọng về khách hàng..."
                          class="w-full border border-gold-200 rounded-lg px-4 py-3 text-sm bg-gold-50/40 focus:outline-none focus:border-gold-500 mb-3"></textarea>
                @error('newNote')<p class="text-xs text-red-600 mb-2">{{ $message }}</p>@enderror

                <div class="mb-3">
                    <label class="flex items-center gap-2 text-sm font-semibold text-gold-700 border border-dashed border-gold-300 rounded-lg px-4 py-2.5 cursor-pointer hover:bg-gold-50 w-fit">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/></svg>
                        Đính kèm ảnh
                        <input type="file" wire:model="noteImages" accept="image/*" multiple class="hidden">
                    </label>
                    <div wire:loading wire:target="noteImages" class="text-xs text-ink/40 mt-1">Đang tải ảnh…</div>
                    @error('noteImages.*')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    @if ($noteImages)
                        <div class="flex flex-wrap gap-2 mt-2">
                            @foreach ($noteImages as $img)
                                @if (is_object($img))
                                    <img src="{{ $img->temporaryUrl() }}" class="w-16 h-16 object-cover rounded-md border border-gold-200">
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input type="checkbox" wire:model.live="noteIsFirstVisit" class="rounded border-gold-300 text-green-600 w-4 h-4">
                            <span class="font-semibold text-green-700">🆕 Lần đầu</span>
                        </label>
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input type="checkbox" wire:model.live="noteIsReturn" class="rounded border-gold-300 text-gold-600 w-4 h-4">
                            <span class="font-semibold text-gold-800">🔁 Trở lại</span>
                        </label>
                        @if ($noteIsReturn)
                            <div>
                                <input type="text" wire:model="noteReceptionCode" placeholder="Mã tiếp đón *"
                                       class="border border-gold-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:border-gold-500 w-40">
                                @error('noteReceptionCode')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                            </div>
                        @endif
                    </div>
                    <button wire:click="addNote" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-5 py-2 rounded-md">Lưu Ghi chú</button>
                </div>
            </div>

            {{-- Timeline lịch sử --}}
            <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <div class="flex items-center justify-between mb-5">
                    <h2 class="font-bold">Lịch sử tương tác</h2>
                    <span class="text-xs text-ink/40">{{ $logs->total() }} ghi chú</span>
                </div>
                <div class="relative pl-6 space-y-4">
                    <div class="absolute left-2 top-1 bottom-1 w-px bg-gold-200"></div>
                    @forelse ($logs as $log)
                        <div class="relative">
                            <span class="absolute -left-6 top-2 w-4 h-4 rounded-full bg-white border-2 border-gold-400"></span>
                            <div class="bg-gold-50/50 border border-gold-100 rounded-lg px-4 py-3">
                                <div class="flex flex-wrap items-center gap-2 mb-1">
                                    <span class="text-[10px] font-bold uppercase tracking-wider {{ $log->field === 'created' ? 'bg-gold-600 text-white' : 'bg-gold-100 border border-gold-300 text-gold-800' }} px-2 py-0.5 rounded">
                                        {{ \App\Models\LeadStatusLog::FIELD_LABELS[$log->field] ?? $log->field }}
                                    </span>
                                    @if ($log->is_first_visit)
                                        <span class="text-[10px] font-bold uppercase tracking-wider bg-blue-100 border border-blue-300 text-blue-800 px-2 py-0.5 rounded">🆕 Lần đầu</span>
                                    @endif
                                    @if ($log->is_return)
                                        <span class="text-[10px] font-bold uppercase tracking-wider bg-green-100 border border-green-300 text-green-800 px-2 py-0.5 rounded">🔁 Trở lại</span>
                                        @if ($log->reception_code)
                                            <span class="text-[10px] font-bold uppercase tracking-wider bg-gold-100 border border-gold-300 text-gold-800 px-2 py-0.5 rounded">{{ $log->reception_code }}</span>
                                        @endif
                                    @endif
                                    <span class="font-semibold text-sm">{{ $log->user?->name ?? 'Hệ thống' }}</span>
                                    <span class="text-xs text-ink/40 ml-auto">{{ $log->created_at->format('d/m/Y H:i') }}</span>
                                </div>
                                <div class="text-sm text-ink/80">
                                    @if ($log->field === 'classification')
                                        <span class="text-ink/50">{{ \App\Models\Lead::CLASSIFICATIONS[$log->old_value] ?? $log->old_value ?? '—' }}</span>
                                        →
                                        <strong class="text-gold-700">{{ \App\Models\Lead::CLASSIFICATIONS[$log->new_value] ?? $log->new_value }}</strong>
                                    @elseif ($log->field === 'created')
                                        {{ $log->new_value }}
                                    @else
                                        {{ $log->new_value ?: '—' }}
                                    @endif
                                </div>
                                @if (! empty($log->images))
                                    <div class="flex flex-wrap gap-2 mt-2" x-data="{ lightbox: null }">
                                        @foreach ($log->images as $idx => $path)
                                            @php $url = \Illuminate\Support\Facades\Storage::disk('public')->url($path); @endphp
                                            <img src="{{ $url }}" alt="Ảnh {{ $idx + 1 }}"
                                                 class="w-12 h-12 object-cover rounded border border-gold-200 cursor-pointer hover:ring-2 hover:ring-gold-400"
                                                 @click="lightbox = '{{ $url }}'">
                                        @endforeach
                                        <template x-if="lightbox">
                                            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" @click.self="lightbox = null" @keydown.escape.window="lightbox = null">
                                                <img :src="lightbox" class="max-w-full max-h-[90vh] rounded-lg shadow-2xl">
                                                <button @click="lightbox = null" class="absolute top-4 right-4 text-white bg-black/50 rounded-full w-10 h-10 flex items-center justify-center text-xl hover:bg-black/70">&times;</button>
                                            </div>
                                        </template>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-ink/40">Chưa có hoạt động nào.</p>
                    @endforelse
                </div>
                @if ($logs->hasPages())
                    <div class="mt-5 pt-4 border-t border-gold-100">
                        {{ $logs->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Màn 10: Popup % đóng góp khi Close --}}
    @if ($showContribution)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/40" wire:click="$set('showContribution', false)"></div>
            <div class="relative bg-white rounded-xl shadow-xl border border-gold-200 w-full max-w-lg p-7">
                <h3 class="text-xl font-bold mb-1">Chia % đóng góp — {{ $lead->name }}</h3>
                <p class="text-sm text-ink/50 mb-5">
                    Người tham gia gợi ý từ lịch sử chăm sóc & phase đã làm. Tổng bắt buộc <strong>= 100%</strong>.
                </p>

                @error('contribRows')<p class="text-sm text-red-600 bg-red-50 border border-red-200 rounded-md px-3 py-2 mb-3">{{ $message }}</p>@enderror

                <div class="space-y-2.5 mb-4">
                    @forelse ($contribRows as $index => $row)
                        <div class="flex items-center gap-3" wire:key="crow-{{ $index }}">
                            <span class="flex-1 text-sm font-medium">{{ $row['name'] }}</span>
                            <select wire:model="contribRows.{{ $index }}.role_label" class="border border-gold-200 rounded-md px-2.5 py-1.5 text-xs bg-white focus:outline-none focus:border-gold-500">
                                @foreach (\App\Models\Contribution::ROLE_LABELS as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <input type="number" wire:model.live="contribRows.{{ $index }}.percent" min="0" max="100" step="0.5"
                                   class="w-20 border border-gold-200 rounded-md px-2.5 py-1.5 text-sm text-right focus:outline-none focus:border-gold-500">
                            <span class="text-sm text-ink/40">%</span>
                        </div>
                    @empty
                        <p class="text-sm text-ink/40 italic">Không tìm thấy người tham gia nào trong lịch sử.</p>
                    @endforelse
                </div>

                @php $contribTotal = round(array_sum(array_map(fn ($r) => (float) ($r['percent'] ?? 0), $contribRows)), 2); @endphp
                <div class="flex items-center justify-between border-t border-gold-100 pt-3 mb-5">
                    <span class="text-sm font-semibold">Tổng</span>
                    <span class="font-mono font-bold text-lg {{ $contribTotal === 100.0 ? 'text-green-700' : 'text-red-600' }}">{{ $contribTotal }}%</span>
                </div>

                <div class="flex justify-end gap-3">
                    <button wire:click="$set('showContribution', false)" class="text-sm text-ink/60 px-4 py-2">Để sau</button>
                    <button wire:click="saveContribution" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-6 py-2 rounded-md">Lưu bảng %</button>
                </div>
            </div>
        </div>
    @endif
</div>
