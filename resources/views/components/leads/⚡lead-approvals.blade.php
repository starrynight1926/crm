<?php

use App\Models\Lead;
use App\Models\LeadDistributionLog;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')]
#[Title('Duyệt Khách tự đến')]
class extends Component {
    use WithPagination;

    public ?int $rejectingLeadId = null;
    public string $rejectReason = '';

    public function approve(int $leadId): void
    {
        abort_unless(auth()->user()->hasPermission('lead.approve_source'), 403);
        $lead = Lead::findOrFail($leadId);
        abort_unless($lead->isVisibleTo(auth()->user()), 403);
        $lead->update([
            'approval_status' => Lead::APPROVAL_APPROVED,
            'approval_by' => auth()->id(),
            'approved_at' => now(),
        ]);
        LeadDistributionLog::create([
            'lead_id' => $lead->id,
            'action' => LeadDistributionLog::ACTION_APPROVE,
            'actor_id' => auth()->id(),
            'org_unit_id' => $lead->org_unit_id,
            'created_at' => now(),
        ]);
        session()->flash('status', "Đã duyệt {$lead->name}. Vào màn Kho lead để chia cho sale.");
    }

    public function startReject(int $leadId): void
    {
        abort_unless(auth()->user()->hasPermission('lead.approve_source'), 403);
        $this->rejectingLeadId = $leadId;
        $this->rejectReason = '';
    }

    public function confirmReject(): void
    {
        abort_unless(auth()->user()->hasPermission('lead.approve_source'), 403);
        $this->validate(['rejectReason' => 'required|string|max:300'], [], ['rejectReason' => 'lý do']);
        $lead = Lead::findOrFail($this->rejectingLeadId);
        abort_unless($lead->isVisibleTo(auth()->user()), 403);
        $lead->update([
            'approval_status' => Lead::APPROVAL_REJECTED,
            'approval_by' => auth()->id(),
            'approved_at' => now(),
        ]);
        LeadDistributionLog::create([
            'lead_id' => $lead->id,
            'action' => LeadDistributionLog::ACTION_REJECT,
            'actor_id' => auth()->id(),
            'org_unit_id' => $lead->org_unit_id,
            'reason' => $this->rejectReason,
            'created_at' => now(),
        ]);
        $this->rejectingLeadId = null;
        $this->rejectReason = '';
        session()->flash('status', "Đã từ chối {$lead->name}.");
    }

    public function with(): array
    {
        $user = auth()->user();
        return [
            'leads' => Lead::query()
                ->where('source_group', Lead::SOURCE_WALK_IN)
                ->where('approval_status', Lead::APPROVAL_PENDING)
                ->visibleTo($user)
                ->with('receiver')
                ->latest()
                ->paginate(20),
        ];
    }
}; ?>

<div class="max-w-6xl mx-auto px-6 py-8">
    <div class="mb-6">
        <h1 class="text-3xl font-bold mb-1">Duyệt Khách tự đến</h1>
        <p class="text-sm text-ink/60">Danh sách lead nhóm "Khách tự đến" đang chờ CM duyệt.</p>
    </div>

    @if (session('status'))
        <div class="mb-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-md px-4 py-2 text-sm">{{ session('status') }}</div>
    @endif

    <div class="bg-white border border-gold-200 rounded-xl shadow-card overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gold-50 text-ink/70 text-xs uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-3 text-left">Khách hàng</th>
                    <th class="px-4 py-3 text-left">SĐT</th>
                    <th class="px-4 py-3 text-left">Người up</th>
                    <th class="px-4 py-3 text-left">Ngày</th>
                    <th class="px-4 py-3 text-left">Ghi chú</th>
                    <th class="px-4 py-3 text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gold-100">
                @forelse ($leads as $lead)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-semibold">{{ $lead->name }}</div>
                            <div class="text-xs text-ink/50">{{ $lead->code }}</div>
                        </td>
                        <td class="px-4 py-3">{{ \App\Models\Lead::maskPhone($lead->phone) }}</td>
                        <td class="px-4 py-3">{{ $lead->receiver?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink/60">{{ $lead->received_date?->format('d/m/Y') }}</td>
                        <td class="px-4 py-3 text-ink/60">{{ \Illuminate\Support\Str::limit($lead->note, 60) }}</td>
                        <td class="px-4 py-3 text-right">
                            @if ($rejectingLeadId === $lead->id)
                                <span class="inline-flex items-center gap-2">
                                    <input wire:model="rejectReason" placeholder="Lý do từ chối…" class="border border-gold-200 rounded-md px-2 py-1.5 text-xs">
                                    <button wire:click="confirmReject" class="text-xs font-semibold bg-red-600 text-white px-3 py-1.5 rounded-md">OK</button>
                                    <button wire:click="$set('rejectingLeadId', null)" class="text-xs text-ink/50">Hủy</button>
                                </span>
                                @error('rejectReason')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                            @else
                                <button wire:click="approve({{ $lead->id }})" class="text-xs font-semibold bg-emerald-600 text-white px-3 py-1.5 rounded-md">Duyệt</button>
                                <button wire:click="startReject({{ $lead->id }})" class="text-xs font-semibold border border-red-200 text-red-700 px-3 py-1.5 rounded-md">Từ chối</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-ink/50">Không có lead nào đang chờ duyệt.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $leads->links() }}</div>
</div>
