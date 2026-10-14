<?php

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadPhaseClosure;
use App\Models\LeadStatusLog;
use App\Models\OrgUnit;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Phase 6.27.d — Kho lead thiếu info.
 * - List lead `no_phone=true` trong scope user.
 * - Form "Thêm lead thiếu info" tạo lead null SĐT (nguồn SA/BA/WI, cơ sở bật toggle).
 * - Action: claim (gán owner) + điền SĐT (bỏ no_phone, chặn khi trùng).
 * - Tab Gộp lead chờ 6.27.f (LeadMerger service).
 */
new #[Layout('layouts.app')]
#[Title('Lead thiếu info')]
class extends Component {
    use WithPagination;

    // Add-form state
    public bool $showCreate = false;
    public string $cName = '';
    public ?string $cSource = null;
    public ?int $cBranchId = null;
    public string $cReceivedDate = '';
    public string $cNote = '';

    // Inline fill-phone state
    public ?int $fillingLeadId = null;
    public string $fillingPhone = '';
    public ?int $duplicateLeadId = null;

    public function mount(): void
    {
        $this->cReceivedDate = now()->format('Y-m-d');
    }

    private function availableBranches()
    {
        $user = auth()->user();
        $ancestorIds = [];
        foreach ($user->effectiveAssignments() as $a) {
            foreach (array_filter(explode('/', trim((string) $a->orgUnit->path, '/'))) as $seg) {
                $ancestorIds[(int) $seg] = true;
            }
        }
        $q = OrgUnit::where('depth', 1)
            ->where('code', 'like', 'branch-%')
            ->where('allow_lead_without_phone', true)
            ->where('active', true);
        if (! $user->is_admin && $user->email !== 'admin@longevity.com.vn') {
            if ($ancestorIds === []) return collect();
            $q->whereIn('id', array_keys($ancestorIds));
        }
        return $q->orderBy('position')->get();
    }

    public function openCreate(): void
    {
        abort_unless(auth()->user()->hasPermission('lead.access_no_phone_pool'), 403);
        $branches = $this->availableBranches();
        if ($branches->isEmpty()) {
            session()->flash('status', 'Không có cơ sở nào bật "Lead không SĐT" trong phạm vi của bạn.');
            return;
        }
        $this->reset(['cName', 'cSource', 'cBranchId', 'cNote', 'duplicateLeadId']);
        $this->cReceivedDate = now()->format('Y-m-d');
        if ($branches->count() === 1) {
            $this->cBranchId = $branches->first()->id;
        }
        $this->showCreate = true;
    }

    public function closeCreate(): void
    {
        $this->showCreate = false;
        $this->resetErrorBag();
    }

    public function createNoPhoneLead(): void
    {
        abort_unless(auth()->user()->hasPermission('lead.access_no_phone_pool'), 403);
        $this->validate([
            'cName' => 'required|string|max:150',
            'cSource' => 'required|in:' . implode(',', [Lead::SOURCE_SA, Lead::SOURCE_BA, Lead::SOURCE_WI]),
            'cBranchId' => 'required|integer|exists:org_units,id',
            'cReceivedDate' => 'required|date',
            'cNote' => 'nullable|string|max:1000',
        ], [], [
            'cName' => 'tên khách',
            'cSource' => 'nguồn',
            'cBranchId' => 'cơ sở',
            'cReceivedDate' => 'ngày',
        ]);

        $branch = OrgUnit::find($this->cBranchId);
        if (! $branch || ! $branch->allow_lead_without_phone || ! str_starts_with((string) $branch->code, 'branch-')) {
            $this->addError('cBranchId', 'Cơ sở không hợp lệ hoặc chưa bật "Lead không SĐT".');
            return;
        }
        if (! $this->availableBranches()->contains('id', $branch->id)) {
            $this->addError('cBranchId', 'Cơ sở nằm ngoài phạm vi của bạn.');
            return;
        }

        $user = auth()->user();
        [$pphase, $pstatus] = Lead::initialPipelineFor($this->cSource, null);
        $lead = Lead::create([
            'name' => $this->cName,
            'phone' => null,
            'no_phone' => true,
            'received_date' => $this->cReceivedDate,
            'note' => $this->cNote ?: null,
            'source_group' => $this->cSource,
            'org_unit_id' => $branch->id,
            'pool_level' => Lead::POOL_TEAM,
            'owner_id' => null,
            'imported_by' => $user->id,
            'approval_status' => Lead::APPROVAL_NONE,
            'booking_status' => Lead::BOOKING_NOT_BOOKED,
            'pipeline_phase' => $pphase,
            'pipeline_status' => $pstatus,
        ]);
        $lead->generateCode();

        LeadPhaseClosure::updateOrCreate(
            ['lead_id' => $lead->id, 'phase' => Lead::CF_PHASE_NEW],
            ['closed_by' => $user->id, 'closed_at' => now(), 'note' => 'Auto-close khi tạo lead thiếu info']
        );
        LeadStatusLog::record($lead, 'created', null, "Tạo lead thiếu SĐT ({$branch->shortName}) bởi {$user->name}", $user->id);
        AuditLog::record('create_no_phone', $lead);

        $this->showCreate = false;
        $this->reset(['cName', 'cSource', 'cBranchId', 'cNote']);
        $this->cReceivedDate = now()->format('Y-m-d');
        session()->flash('status', "Đã tạo lead thiếu info: {$lead->name}.");
    }

    public function claim(int $leadId): void
    {
        abort_unless(auth()->user()->hasPermission('lead.access_no_phone_pool'), 403);
        $lead = Lead::findOrFail($leadId);
        abort_unless($lead->isVisibleTo(auth()->user()) && $lead->no_phone, 403);

        if ($lead->owner_id) {
            session()->flash('status', 'Lead đã có người phụ trách.');
            return;
        }

        $lead->update([
            'owner_id' => auth()->id(),
            'assigned_at' => now(),
            'pool_level' => Lead::POOL_PERSONAL,
        ]);
        LeadStatusLog::record($lead, 'claim_no_phone', null, 'Claim từ kho thiếu info', auth()->id());
        AuditLog::record('claim_no_phone', $lead);
        session()->flash('status', "Đã claim lead {$lead->name}.");
    }

    public function startFillPhone(int $leadId): void
    {
        abort_unless(auth()->user()->hasPermission('lead.access_no_phone_pool'), 403);
        $this->fillingLeadId = $leadId;
        $this->fillingPhone = '';
        $this->duplicateLeadId = null;
        $this->resetErrorBag('fillingPhone');
    }

    public function cancelFillPhone(): void
    {
        $this->fillingLeadId = null;
        $this->fillingPhone = '';
        $this->duplicateLeadId = null;
        $this->resetErrorBag('fillingPhone');
    }

    public function confirmFillPhone(): void
    {
        abort_unless(auth()->user()->hasPermission('lead.access_no_phone_pool'), 403);
        $this->validate(['fillingPhone' => 'required|string'], [], ['fillingPhone' => 'SĐT']);

        $lead = Lead::findOrFail($this->fillingLeadId);
        abort_unless($lead->isVisibleTo(auth()->user()) && $lead->no_phone, 403);

        $normalized = Lead::normalizePhone($this->fillingPhone);
        if (! $normalized) {
            $this->addError('fillingPhone', 'SĐT không hợp lệ.');
            return;
        }

        $existing = Lead::withTrashed()->where('phone', $normalized)->first();
        if ($existing) {
            $this->duplicateLeadId = $existing->isVisibleTo(auth()->user()) ? $existing->id : null;
            $msg = $existing->trashed()
                ? "SĐT thuộc lead đã xóa (id {$existing->id}) — liên hệ Admin."
                : "SĐT đã tồn tại ở lead khác — không ghi đè, cần Admin gộp (6.27.f).";
            $this->addError('fillingPhone', $msg);
            return;
        }

        $lead->update(['phone' => $normalized, 'no_phone' => false]);
        LeadStatusLog::record($lead, 'fill_phone', null, "Điền SĐT: {$normalized}", auth()->id());
        AuditLog::record('fill_phone', $lead);

        $this->fillingLeadId = null;
        $this->fillingPhone = '';
        session()->flash('status', "Đã điền SĐT cho lead {$lead->name}.");
    }

    public function with(): array
    {
        $user = auth()->user();
        return [
            'leads' => Lead::query()
                ->where('no_phone', true)
                ->visibleTo($user)
                ->with(['receiver', 'owner', 'orgUnit'])
                ->latest()
                ->paginate(20),
            'branches' => $this->availableBranches(),
        ];
    }
}; ?>

<div class="max-w-7xl mx-auto px-6 py-8">
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold mb-1">Lead thiếu info</h1>
            <p class="text-sm text-ink/60">Lead upload không SĐT (nguồn SA/BA/WI). Claim → gọi khách → điền SĐT để đưa vào pipeline chính thức.</p>
        </div>
        <button wire:click="openCreate"
                class="shrink-0 inline-flex items-center gap-2 px-4 py-2 bg-gold-600 text-white rounded-md text-sm font-semibold hover:bg-gold-700">
            + Thêm lead thiếu info
        </button>
    </div>

    @if (session('status'))
        <div class="mb-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-md px-4 py-2 text-sm">{{ session('status') }}</div>
    @endif

    @if ($showCreate)
        <div class="mb-6 bg-white border-2 border-gold-300 rounded-xl shadow-card p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold">Thêm lead thiếu info</h2>
                <button wire:click="closeCreate" class="text-ink/50 hover:text-ink/80 text-sm">✕ Đóng</button>
            </div>

            @if ($branches->isEmpty())
                <p class="text-sm text-red-600">Không có cơ sở nào bật toggle "Lead không SĐT" trong phạm vi của bạn. Vào <a href="{{ route('admin.catalog') }}" class="underline">Danh mục hệ thống → Cơ cấu tổ chức</a> để bật.</p>
            @else
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Tên khách <span class="text-red-500">*</span></label>
                        <input type="text" wire:model="cName" placeholder="Nguyễn Văn A"
                               class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                        @error('cName')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Nguồn <span class="text-red-500">*</span></label>
                        <select wire:model="cSource" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm bg-white focus:outline-none focus:border-gold-500">
                            <option value="">— Chọn nguồn —</option>
                            <option value="{{ \App\Models\Lead::SOURCE_SA }}">SA — Sale Appointment</option>
                            <option value="{{ \App\Models\Lead::SOURCE_BA }}">BA — Booking Appointment</option>
                            <option value="{{ \App\Models\Lead::SOURCE_WI }}">WI — Walk-in</option>
                        </select>
                        @error('cSource')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Cơ sở <span class="text-red-500">*</span></label>
                        <select wire:model="cBranchId" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm bg-white focus:outline-none focus:border-gold-500">
                            <option value="">— Chọn cơ sở —</option>
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}">{{ $b->shortName }}</option>
                            @endforeach
                        </select>
                        @error('cBranchId')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Ngày thu thập <span class="text-red-500">*</span></label>
                        <input type="date" wire:model="cReceivedDate"
                               class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                        @error('cReceivedDate')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium mb-1.5">Ghi chú</label>
                        <textarea wire:model="cNote" rows="2" placeholder="Ghi chú thêm (nguồn thực tế, khung giờ hẹn gọi lại...)"
                                  class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500"></textarea>
                        @error('cNote')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="mt-5 flex items-center gap-3">
                    <button wire:click="createNoPhoneLead"
                            class="px-4 py-2 bg-emerald-600 text-white rounded-md text-sm font-semibold hover:bg-emerald-700">
                        Lưu lead thiếu info
                    </button>
                    <button wire:click="closeCreate" class="text-sm text-ink/60 hover:text-ink/90">Hủy</button>
                </div>
            @endif
        </div>
    @endif

    <div class="bg-white border border-gold-200 rounded-xl shadow-card overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gold-50 text-ink/70 text-xs uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-3 text-left">Khách hàng</th>
                    <th class="px-4 py-3 text-left">Nguồn</th>
                    <th class="px-4 py-3 text-left">Cơ sở</th>
                    <th class="px-4 py-3 text-left">Người up</th>
                    <th class="px-4 py-3 text-left">Owner</th>
                    <th class="px-4 py-3 text-left">Ngày</th>
                    <th class="px-4 py-3 text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gold-100">
                @forelse ($leads as $lead)
                    <tr class="align-top">
                        <td class="px-4 py-3">
                            <div class="font-semibold">{{ $lead->name }}</div>
                            <div class="text-xs text-ink/50">{{ $lead->code }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-block px-2 py-0.5 rounded bg-slate-100 text-xs">{{ \App\Models\Lead::SOURCE_GROUP_CODES[$lead->source_group] ?? $lead->source_group }}</span>
                        </td>
                        <td class="px-4 py-3 text-ink/70">{{ $lead->orgUnit?->shortName ?? $lead->orgUnit?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink/70">{{ $lead->receiver?->name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @if ($lead->owner)
                                <span class="text-ink/80">{{ $lead->owner->name }}</span>
                            @else
                                <span class="italic text-ink/40">Chưa claim</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-ink/60">{{ $lead->received_date?->format('d/m/Y') }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @if ($fillingLeadId === $lead->id)
                                <div class="inline-flex flex-col items-end gap-1">
                                    <div class="inline-flex items-center gap-2">
                                        <input wire:model="fillingPhone" placeholder="0xxx xxx xxx"
                                               class="border border-gold-200 rounded-md px-2 py-1.5 text-xs font-mono w-36">
                                        <button wire:click="confirmFillPhone" class="text-xs font-semibold bg-emerald-600 text-white px-3 py-1.5 rounded-md">Lưu</button>
                                        <button wire:click="cancelFillPhone" class="text-xs text-ink/50">Hủy</button>
                                    </div>
                                    @error('fillingPhone')
                                        <p class="text-xs text-red-600">
                                            {{ $message }}
                                            @if ($duplicateLeadId)
                                                — <a href="{{ route('leads.show', $duplicateLeadId) }}" class="underline">mở lead #{{ $duplicateLeadId }}</a>
                                            @endif
                                        </p>
                                    @enderror
                                </div>
                            @else
                                @if (! $lead->owner_id)
                                    <button wire:click="claim({{ $lead->id }})"
                                            class="text-xs font-semibold bg-gold-600 text-white px-3 py-1.5 rounded-md">Claim</button>
                                @endif
                                <button wire:click="startFillPhone({{ $lead->id }})"
                                        class="text-xs font-semibold bg-emerald-600 text-white px-3 py-1.5 rounded-md">Điền SĐT</button>
                                <a href="{{ route('leads.edit', $lead) }}"
                                   class="text-xs font-semibold border border-gold-200 text-ink/70 px-3 py-1.5 rounded-md">Mở</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-ink/50">Không có lead nào trong kho thiếu info.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $leads->links() }}</div>

    <div class="mt-6 text-xs text-ink/50">
        <b>Gộp lead:</b> khi phát hiện trùng SĐT, chức năng gộp manual (dồn booking/log/note sang lead gốc + xóa clone) đang được chuẩn bị ở Phase 6.27.f — tạm thời cần Admin gộp tay.
    </div>
</div>
