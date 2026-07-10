<?php

use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\ContributionTemplate;
use App\Models\Lead;
use App\Models\LeadStatusLog;
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

    public function addNote(): void
    {
        abort_unless($this->canEditLead(), 403);
        // Cho phép ghi chú rỗng nếu chỉ đính ảnh; nhưng phải có ít nhất nội dung hoặc ảnh.
        $this->validate([
            'newNote' => 'nullable|string|max:2000',
            'noteImages' => 'array|max:10',
            'noteImages.*' => 'image|max:5120', // ≤5MB mỗi ảnh
            // Mã tiếp đón: bắt buộc + không trùng khi tick "Khách trở lại"; bỏ qua khi không phải return.
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
            $paths, $this->noteIsReturn, $this->noteIsReturn ? trim($this->noteReceptionCode) : null
        );
        $this->lead->update(['note' => $this->newNote ?: $this->lead->note, 'last_care_at' => now()]);

        $this->reset(['newNote', 'noteImages', 'noteIsReturn', 'noteReceptionCode']);
        $this->lead->refresh();
    }

    /** Tần suất quay lại = số ghi chú đã tick "Khách trở lại". */
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

        // Deal Close → mở popup % đóng góp (Màn 10)
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
            // Đã chia rồi → mở lại để sửa
            $this->contribRows = $existing->map(fn ($c) => [
                'user_id' => $c->user_id, 'name' => $c->user->name,
                'role_label' => $c->role_label, 'percent' => (string) round((float) $c->percent, 2),
            ])->all();
        } else {
            // Gợi ý người tham gia từ lịch sử + áp template mặc định theo vai trò
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

        return [
            'logs' => $this->lead->statusLogs()->with('user')->limit(50)->get(),
            'canEdit' => $this->canEditLead(),
            'customFields' => $customFields,
            'customValues' => $customValues,
            'contributions' => Contribution::with('user')->where('lead_id', $this->lead->id)->orderByDesc('percent')->get(),
            'canSetContribution' => auth()->user()->hasPermission('contribution.set'),
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
                Sửa thông tin
            </a>
        @endif
    </div>

    @if (session('status'))
        <p class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-4 py-2">{{ session('status') }}</p>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-[380px_1fr] gap-6 items-start">
        <div class="space-y-6">
            {{-- Thông tin chi tiết --}}
            <div class="bg-white border-l-4 border-gold-600 border-y border-r border-y-gold-200 border-r-gold-200 rounded-xl shadow-card p-6">
                <h2 class="text-xs font-bold uppercase tracking-[0.15em] text-ink/60 border-b border-gold-100 pb-3 mb-4">Thông tin chi tiết</h2>
                <dl class="space-y-3.5 text-sm">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Ngày</dt>
                            <dd class="font-medium">{{ $lead->received_date->format('d/m/Y') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Page</dt>
                            <dd class="font-medium">{{ $lead->page ?: '—' }}</dd>
                        </div>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Số điện thoại</dt>
                        <dd class="font-mono font-semibold text-gold-700 flex items-center gap-2">
                            @if ($phoneRevealed)
                                {{ $lead->phone }}
                            @else
                                {{ \App\Models\Lead::maskPhone($lead->phone) }}
                                @if ($lead->canViewFullPhone(auth()->user()))
                                    <button wire:click="revealPhone" class="text-xs font-sans font-semibold text-gold-600 border border-gold-300 px-2 py-0.5 rounded hover:bg-gold-50" title="Ghi audit log khi xem">
                                        Hiện số
                                    </button>
                                @endif
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Camp (chiến dịch)</dt>
                        <dd class="font-medium">{{ $lead->camp ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Insight / Link</dt>
                        <dd class="font-medium">
                            {{ $lead->insight ?: '—' }}
                            @if ($lead->link)
                                <a href="{{ $lead->link }}" target="_blank" rel="noopener" class="block text-gold-600 underline truncate">{{ $lead->link }}</a>
                            @endif
                        </dd>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Nguồn</dt>
                            <dd class="font-medium">{{ $lead->ad_source ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Người nhận</dt>
                            <dd class="font-medium">{{ $lead->receiver?->name ?: 'Hệ thống' }}</dd>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Lead chia cho</dt>
                            <dd class="font-medium text-gold-700">{{ $lead->owner?->name ?: 'Chưa chia' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Khu vực</dt>
                            <dd class="font-medium">{{ $lead->region ?: '—' }}</dd>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Tình trạng lần 1</dt>
                            <dd class="font-medium">{{ $lead->status_1 ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-ink/40 mb-0.5">Tình trạng lần 2</dt>
                            <dd class="font-medium">{{ $lead->status_2 ?: '—' }}</dd>
                        </div>
                    </div>
                    <div>
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
                </dl>
            </div>

            {{-- % đóng góp (hiện khi đã chia hoặc deal close) --}}
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

            {{-- Trường bổ sung theo phòng ban --}}
            @if ($customFields->isNotEmpty())
                <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                    <h2 class="text-xs font-bold uppercase tracking-[0.15em] text-ink/60 border-b border-gold-100 pb-3 mb-4">
                        Trường bổ sung {{ $lead->orgUnit ? '(' . $lead->orgUnit->name . ')' : '' }}
                    </h2>
                    <dl class="space-y-3.5 text-sm">
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
                <textarea wire:model="newNote" rows="4" placeholder="Nhập nội dung tương tác hoặc ghi chú quan trọng về khách hàng..."
                          class="w-full border border-gold-200 rounded-lg px-4 py-3 text-sm bg-gold-50/40 focus:outline-none focus:border-gold-500 mb-3"></textarea>
                @error('newNote')<p class="text-xs text-red-600 mb-2">{{ $message }}</p>@enderror

                {{-- Upload ảnh --}}
                <div class="mb-3">
                    <label class="flex items-center gap-2 text-sm font-semibold text-gold-700 border border-dashed border-gold-300 rounded-lg px-4 py-2.5 cursor-pointer hover:bg-gold-50 w-fit">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/></svg>
                        Đính kèm ảnh
                        <input type="file" wire:model="noteImages" accept="image/*" multiple class="hidden">
                    </label>
                    <div wire:loading wire:target="noteImages" class="text-xs text-ink/40 mt-1">Đang tải ảnh…</div>
                    @error('noteImages.*')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    {{-- Preview ảnh sắp lưu --}}
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
                            <input type="checkbox" wire:model.live="noteIsReturn" class="rounded border-gold-300 text-gold-600 w-4 h-4">
                            <span class="font-semibold text-gold-800">🔁 Khách trở lại</span>
                        </label>
                        @if ($noteIsReturn)
                            <div>
                                <input type="text" wire:model="noteReceptionCode" placeholder="Mã tiếp đón *"
                                       class="border border-gold-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:border-gold-500 w-44">
                                @error('noteReceptionCode')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                            </div>
                        @endif
                    </div>
                    <button wire:click="addNote" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-6 py-2.5 rounded-md">Lưu Ghi chú</button>
                </div>
            </div>

            {{-- Timeline lịch sử --}}
            <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold mb-5">Lịch sử tương tác</h2>
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
                                    @if ($log->is_return)
                                        <span class="text-[10px] font-bold uppercase tracking-wider bg-green-100 border border-green-300 text-green-800 px-2 py-0.5 rounded">🔁 Khách trở lại</span>
                                        @if ($log->reception_code)
                                            <span class="text-[10px] font-bold uppercase tracking-wider bg-gold-100 border border-gold-300 text-gold-800 px-2 py-0.5 rounded">Mã tiếp đón: {{ $log->reception_code }}</span>
                                        @endif
                                    @endif
                                    <span class="font-semibold text-sm">{{ $log->user?->name ?? 'Hệ thống' }}</span>
                                    <span class="text-xs text-ink/40 ml-auto">{{ $log->created_at->format('d/m/Y H:i') }}</span>
                                </div>
                                <div class="text-sm text-ink/80">
                                    @if ($log->field === 'classification')
                                        Cập nhật trạng thái:
                                        <span class="text-ink/50">{{ \App\Models\Lead::CLASSIFICATIONS[$log->old_value] ?? $log->old_value ?? '—' }}</span>
                                        →
                                        <strong class="text-gold-700">{{ \App\Models\Lead::CLASSIFICATIONS[$log->new_value] ?? $log->new_value }}</strong>
                                    @elseif ($log->field === 'created')
                                        {{ $log->new_value }}
                                    @else
                                        {{ $log->new_value ?: '—' }}
                                    @endif
                                </div>
                                {{-- Ảnh đính kèm --}}
                                @if (! empty($log->images))
                                    <div class="flex flex-wrap gap-2 mt-2">
                                        @foreach ($log->images as $path)
                                            <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($path) }}" target="_blank">
                                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($path) }}" class="w-20 h-20 object-cover rounded-md border border-gold-200 hover:opacity-90">
                                            </a>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-ink/40">Chưa có hoạt động nào.</p>
                    @endforelse
                </div>
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
