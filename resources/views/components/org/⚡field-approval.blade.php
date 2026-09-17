<?php

use App\Models\CustomField;
use Livewire\Component;

new class extends Component
{
    public array $rejecting = []; // field_id => reason (mở ô lý do)

    /** Org ids mà user được duyệt = con (nghiêm ngặt) của node user giữ field.approve. */
    private function approvableOrgIds(): array
    {
        $user = auth()->user();
        $ids = [];
        foreach ($user->effectiveAssignments() as $assignment) {
            if ($assignment->role->permissions->contains('key', 'field.approve')) {
                $node = $assignment->orgUnit;
                // con nghiêm ngặt: cả subtree TRỪ chính node (cấp trên duyệt cấp dưới)
                $ids = array_merge($ids, array_diff($node->subtreeIds(), [$node->id]));
            }
        }

        return array_values(array_unique($ids));
    }

    private function guardCanApprove(CustomField $field): void
    {
        abort_unless(
            $field->org_unit_id !== null && in_array($field->org_unit_id, $this->approvableOrgIds(), true),
            403
        );
    }

    public function approve(int $id): void
    {
        $field = CustomField::findOrFail($id);
        $this->guardCanApprove($field);

        $field->update([
            'status' => CustomField::STATUS_ACTIVE,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'reject_reason' => null,
        ]);
        session()->flash('approve_status', "Đã duyệt trường \"{$field->label}\" — áp lên lead từ giờ.");
    }

    public function startReject(int $id): void
    {
        $this->rejecting[$id] = '';
    }

    public function reject(int $id): void
    {
        $field = CustomField::findOrFail($id);
        $this->guardCanApprove($field);

        $reason = trim($this->rejecting[$id] ?? '');
        if ($reason === '') {
            $this->addError("reject_{$id}", 'Nhập lý do từ chối.');
            return;
        }

        $field->update([
            'status' => CustomField::STATUS_REJECTED,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'reject_reason' => $reason,
        ]);
        unset($this->rejecting[$id]);
        session()->flash('approve_status', "Đã từ chối trường \"{$field->label}\".");
    }

    public function with(): array
    {
        $orgIds = $this->approvableOrgIds();

        return [
            'pending' => $orgIds === [] ? collect() : CustomField::query()
                ->where('status', CustomField::STATUS_PENDING)
                ->whereIn('org_unit_id', $orgIds)
                ->with(['orgUnit', 'requester'])
                ->orderBy('created_at')
                ->get(),
        ];
    }
};
?>

<div>
    <h2 class="text-2xl font-bold mb-2">Duyệt trường bắt buộc</h2>
    <p class="text-sm text-ink/60 mb-6 max-w-2xl">
        Trường bắt buộc do cấp dưới đề xuất chờ bạn duyệt. Duyệt xong mới áp lên lead. Bạn chỉ thấy trường của các phòng/nhóm nằm dưới quyền duyệt của mình.
    </p>

    @if (session('approve_status'))
        <p class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-4 py-2">{{ session('approve_status') }}</p>
    @endif

    <div class="bg-white border border-gold-200 rounded-xl shadow-card divide-y divide-gold-100">
        @forelse ($pending as $field)
            <div class="px-5 py-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="font-semibold">
                            {{ $field->label }}
                            <span class="text-xs text-ink/40">({{ \App\Models\CustomField::TYPES[$field->field_type] ?? $field->field_type }})</span>
                            @if ($field->affects_code)<span class="ml-1 text-xs text-gold-700">#nối mã KH</span>@endif
                        </div>
                        <div class="text-xs text-ink/50 mt-1">
                            Phòng/nhóm: <strong>{{ $field->orgUnit?->name }}</strong>
                            · đề xuất bởi {{ $field->requester?->name ?? '—' }}
                            · {{ $field->created_at?->diffForHumans() }}
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button wire:click="approve({{ $field->id }})" class="text-xs font-semibold text-white bg-green-600 hover:bg-green-700 px-4 py-1.5 rounded-md">Duyệt</button>
                        <button wire:click="startReject({{ $field->id }})" class="text-xs font-semibold text-red-700 border border-red-200 hover:bg-red-50 px-4 py-1.5 rounded-md">Từ chối</button>
                    </div>
                </div>
                @if (array_key_exists($field->id, $rejecting))
                    <div class="mt-3 flex items-center gap-2">
                        <input type="text" wire:model="rejecting.{{ $field->id }}" placeholder="Lý do từ chối" class="flex-1 border border-gold-200 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:border-gold-500">
                        <button wire:click="reject({{ $field->id }})" class="text-xs font-semibold text-white bg-red-600 hover:bg-red-700 px-4 py-1.5 rounded-md">Xác nhận từ chối</button>
                    </div>
                    @error("reject_{$field->id}")<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                @endif
            </div>
        @empty
            <div class="px-5 py-10 text-center text-ink/40">Không có trường nào chờ bạn duyệt. 🎉</div>
        @endforelse
    </div>
</div>
