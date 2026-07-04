<?php

use App\Jobs\ProcessRawLead;
use App\Models\RawLead;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $fSource = '';

    public ?int $editingId = null;

    public string $editName = '';

    public string $editPhone = '';

    /** Sửa nhanh tên/SĐT rồi chạy lại pipeline. */
    public function startFix(int $id): void
    {
        $raw = RawLead::findOrFail($id);
        $this->editingId = $id;
        $this->editName = (string) ($raw->payload['name'] ?? '');
        $this->editPhone = (string) ($raw->payload['phone'] ?? '');
    }

    public function retry(): void
    {
        $raw = RawLead::findOrFail($this->editingId);

        $payload = $raw->payload;
        $payload['name'] = trim($this->editName);
        $payload['phone'] = trim($this->editPhone);

        $raw->update([
            'payload' => $payload,
            'status' => RawLead::STATUS_PENDING,
            'error_reason' => null,
        ]);

        ProcessRawLead::dispatch($raw->id);
        $this->editingId = null;
        session()->flash('status', "Đã đưa dòng #{$raw->id} vào chuẩn hóa lại.");
    }

    public function discard(int $id): void
    {
        RawLead::where('status', RawLead::STATUS_FAILED)->findOrFail($id)->delete();
    }

    public function with(): array
    {
        return [
            'rawLeads' => RawLead::query()
                ->where('status', RawLead::STATUS_FAILED)
                ->when($this->fSource, fn ($q) => $q->where('source_type', $this->fSource))
                ->orderByDesc('created_at')
                ->paginate(15),
            'failedCount' => RawLead::where('status', RawLead::STATUS_FAILED)->count(),
        ];
    }
};
?>

<div>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-3xl font-bold mb-1">Lead lỗi (chưa chuẩn hóa được)</h1>
            <p class="text-sm text-ink/60">{{ $failedCount }} dòng bị pipeline từ chối — sửa lại rồi chạy lại, hoặc loại bỏ.</p>
        </div>
        <select wire:model.live="fSource" class="border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
            <option value="">Tất cả nguồn</option>
            <option value="excel">Import Excel/CSV</option>
            <option value="webhook">Webhook</option>
            <option value="ads_api">Ads API</option>
            <option value="manual">Nhập tay</option>
        </select>
    </div>

    @if (session('status'))
        <p class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-4 py-2">{{ session('status') }}</p>
    @endif

    <div class="bg-white border border-gold-200 rounded-xl shadow-card divide-y divide-gold-100">
        @forelse ($rawLeads as $raw)
            <div class="px-6 py-4">
                <div class="flex flex-wrap items-center gap-3 mb-2">
                    <span class="text-xs text-ink/40">#{{ $raw->id }}</span>
                    <span class="text-xs bg-gold-50 border border-gold-200 px-2 py-0.5 rounded">{{ $raw->source_type }}{{ $raw->source_ref ? " · {$raw->source_ref}" : '' }}</span>
                    <span class="text-xs text-ink/40">{{ $raw->created_at?->format('d/m/Y H:i') }}</span>
                    <span class="text-sm font-semibold text-red-600 ml-auto">{{ $raw->error_reason }}</span>
                </div>

                @if ($editingId === $raw->id)
                    <div class="flex flex-wrap items-end gap-3 bg-gold-50/50 border border-gold-200 rounded-lg p-4 mt-2">
                        <div>
                            <label class="block text-xs font-semibold text-ink/50 mb-1">Tên</label>
                            <input type="text" wire:model="editName" class="border border-gold-200 rounded-md px-3 py-2 text-sm w-56 focus:outline-none focus:border-gold-500">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-ink/50 mb-1">SĐT</label>
                            <input type="text" wire:model="editPhone" class="border border-gold-200 rounded-md px-3 py-2 text-sm w-44 font-mono focus:outline-none focus:border-gold-500">
                        </div>
                        <button wire:click="retry" class="bg-gold-600 hover:bg-gold-700 text-white text-sm font-semibold px-5 py-2 rounded-md">Chạy lại pipeline</button>
                        <button wire:click="$set('editingId', null)" class="text-sm text-ink/50 px-2 py-2">Hủy</button>
                    </div>
                @else
                    <div class="flex flex-wrap items-center gap-4">
                        <div class="flex-1 text-sm text-ink/70 font-mono truncate">{{ json_encode($raw->payload, JSON_UNESCAPED_UNICODE) }}</div>
                        <button wire:click="startFix({{ $raw->id }})" class="text-xs font-semibold text-gold-700 border border-gold-300 hover:bg-gold-50 px-4 py-2 rounded-md shrink-0">Sửa & chạy lại</button>
                        <button wire:click="discard({{ $raw->id }})" wire:confirm="Loại bỏ dòng lỗi này?" class="text-xs font-semibold text-red-700 border border-red-200 hover:bg-red-50 px-4 py-2 rounded-md shrink-0">Loại bỏ</button>
                    </div>
                @endif
            </div>
        @empty
            <div class="px-6 py-10 text-center text-ink/40">Không có lead lỗi nào 🎉</div>
        @endforelse

        <div class="px-6 py-4">{{ $rawLeads->links() }}</div>
    </div>
</div>
