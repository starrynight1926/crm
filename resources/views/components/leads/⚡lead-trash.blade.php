<?php

use App\Models\AuditLog;
use App\Models\Lead;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermission('phase.rollback'), 403);
    }

    public function updated($property): void
    {
        if ($property === 'search') $this->resetPage();
    }

    public function restore(int $id): void
    {
        abort_unless(auth()->user()->hasPermission('phase.rollback'), 403);
        $lead = Lead::withTrashed()->find($id);
        if (! $lead || ! $lead->trashed()) return;
        $lead->restore();
        AuditLog::record('restore', $lead, ['name' => $lead->name]);
        session()->flash('status', "Đã khôi phục \"{$lead->name}\".");
    }

    public function purge(int $id): void
    {
        abort_unless(auth()->user()->hasPermission('phase.rollback'), 403);
        $lead = Lead::withTrashed()->find($id);
        if (! $lead) return;
        $name = $lead->name;
        $lead->forceDelete();
        AuditLog::record('force_delete', null, ['lead_id' => $id, 'name' => $name]);
        session()->flash('status', "Đã xóa hẳn \"{$name}\" (không khôi phục được nữa).");
    }

    #[Computed]
    public function leads()
    {
        $q = Lead::onlyTrashed()->orderByDesc('deleted_at');
        if ($this->search !== '') {
            $s = trim($this->search);
            $q->where(function ($qq) use ($s) {
                $qq->where('name', 'like', "%{$s}%")
                    ->orWhere('phone', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%");
            });
        }
        return $q->paginate(20);
    }
};
?>

<div class="max-w-6xl mx-auto p-6">
    <div class="mb-6">
        <div class="text-sm text-ink/50 mb-1">
            <a href="{{ route('leads.index') }}" class="hover:text-gold-600">Khách hàng</a>
            <span class="mx-1">›</span>
            <span class="text-gold-700 font-medium">Thùng rác</span>
        </div>
        <h1 class="text-2xl font-bold flex items-center gap-2">🗑 Thùng rác khách hàng</h1>
        <p class="text-sm text-ink/60 mt-1">Danh sách khách hàng đã bị xóa mềm. Có thể khôi phục hoặc xóa hẳn (không hoàn tác).</p>
    </div>

    @if (session('status'))
        <div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-800 rounded text-sm">{{ session('status') }}</div>
    @endif

    <div class="bg-white border border-gold-100 rounded-lg">
        <div class="p-4 border-b border-gold-100">
            <input type="text" wire:model.live.debounce.400ms="search" placeholder="Tìm theo tên / SĐT / mã KH..."
                   class="w-full md:w-96 border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-ink/50 bg-gold-50/60">
                        <th class="px-4 py-3 font-semibold">Mã KH</th>
                        <th class="px-4 py-3 font-semibold">Tên khách hàng</th>
                        <th class="px-4 py-3 font-semibold">SĐT</th>
                        <th class="px-4 py-3 font-semibold">Xóa lúc</th>
                        <th class="px-4 py-3 font-semibold text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gold-100">
                    @forelse ($this->leads as $lead)
                        <tr class="hover:bg-gold-50/40">
                            <td class="px-4 py-3 font-mono text-xs text-gold-700">{{ $lead->code ?: '—' }}</td>
                            <td class="px-4 py-3 font-semibold">{{ $lead->name }}</td>
                            <td class="px-4 py-3 font-mono">{{ $lead->phone }}</td>
                            <td class="px-4 py-3 text-ink/60">{{ $lead->deleted_at?->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button wire:click="restore({{ $lead->id }})"
                                            wire:confirm="Khôi phục &quot;{{ $lead->name }}&quot;?"
                                            class="text-xs bg-emerald-600 hover:bg-emerald-700 text-white font-semibold px-3 py-1 rounded">
                                        ↩ Khôi phục
                                    </button>
                                    <button wire:click="purge({{ $lead->id }})"
                                            wire:confirm="XÓA HẲN &quot;{{ $lead->name }}&quot;? Hành động này không hoàn tác được."
                                            class="text-xs bg-red-600 hover:bg-red-700 text-white font-semibold px-3 py-1 rounded">
                                        🗑 Xóa hẳn
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-ink/40 italic">Thùng rác trống — chưa có khách hàng nào bị xóa.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-5 py-4 border-t border-gold-100 flex items-center justify-between text-sm text-ink/60">
            <span>Tổng: {{ number_format($this->leads->total()) }} lead trong thùng rác</span>
            {{ $this->leads->links() }}
        </div>
    </div>
</div>
