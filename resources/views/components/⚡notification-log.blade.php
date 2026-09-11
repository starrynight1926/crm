<?php

use App\Models\User;
use App\Support\NotificationEvents;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $q = '';
    public ?int $userId = null;
    public string $event = '';
    public string $status = '';
    public string $tu = '';
    public string $den = '';

    public function updating(): void { $this->resetPage(); }

    public function resetFilters(): void
    {
        $this->reset(['q', 'userId', 'event', 'status', 'tu', 'den']);
        $this->resetPage();
    }

    public function with(): array
    {
        $query = DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->orderByDesc('created_at');

        if ($this->userId) $query->where('notifiable_id', $this->userId);
        if ($this->event !== '') $query->where('data->event', $this->event);

        match ($this->status) {
            'unread'  => $query->whereNull('read_at'),
            'read'    => $query->whereNotNull('read_at'),
            'hidden'  => $query->whereNotNull('hidden_at'),
            'visible' => $query->whereNull('hidden_at'),
            default   => null,
        };

        if ($this->tu)  $query->whereDate('created_at', '>=', $this->tu);
        if ($this->den) $query->whereDate('created_at', '<=', $this->den);
        if ($this->q !== '') $query->where('data', 'like', '%'.$this->q.'%');

        $items = $query->paginate(30);
        $userIds = $items->getCollection()->pluck('notifiable_id')->unique()->all();

        return [
            'items'    => $items,
            'userMap'  => User::whereIn('id', $userIds)->get()->keyBy('id'),
            'allUsers' => User::orderBy('name')->get(['id', 'name']),
            'events'   => NotificationEvents::catalog(),
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gold-700">Nhật ký thông báo</h1>
        <p class="text-sm text-ink/60">Toàn bộ thông báo đã gửi cho người dùng — kể cả những cái họ đã ẩn.</p>
    </div>

    <div class="bg-white border border-gold-200 rounded-lg p-4 mb-4">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-3">
            <div>
                <label class="text-xs text-ink/60 block mb-1">Người nhận</label>
                <select wire:model.live="userId" class="w-full border-gold-200 rounded-md text-sm">
                    <option value="">— Tất cả —</option>
                    @foreach ($allUsers as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs text-ink/60 block mb-1">Loại sự kiện</label>
                <select wire:model.live="event" class="w-full border-gold-200 rounded-md text-sm">
                    <option value="">— Tất cả —</option>
                    @foreach ($events as $key => $meta)
                        <option value="{{ $key }}">{{ $meta['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs text-ink/60 block mb-1">Trạng thái</label>
                <select wire:model.live="status" class="w-full border-gold-200 rounded-md text-sm">
                    <option value="">— Tất cả —</option>
                    <option value="unread">Chưa đọc</option>
                    <option value="read">Đã đọc</option>
                    <option value="visible">Còn hiện</option>
                    <option value="hidden">User đã ẩn</option>
                </select>
            </div>
            <div>
                <label class="text-xs text-ink/60 block mb-1">Từ ngày</label>
                <input type="date" wire:model.live="tu" class="w-full border-gold-200 rounded-md text-sm">
            </div>
            <div>
                <label class="text-xs text-ink/60 block mb-1">Đến ngày</label>
                <input type="date" wire:model.live="den" class="w-full border-gold-200 rounded-md text-sm">
            </div>
            <div>
                <label class="text-xs text-ink/60 block mb-1">Tìm nội dung</label>
                <input type="text" wire:model.live.debounce.400ms="q" placeholder="Từ khóa..." class="w-full border-gold-200 rounded-md text-sm">
            </div>
        </div>
        <div class="mt-3 flex items-center gap-2">
            <button wire:click="resetFilters" class="px-3 py-1.5 text-xs border border-gold-200 rounded hover:bg-gold-50">Xóa lọc</button>
            <span class="text-xs text-ink/60 ml-auto">Tổng: <strong>{{ $items->total() }}</strong></span>
        </div>
    </div>

    <div class="bg-white border border-gold-200 rounded-lg overflow-hidden">
        @if ($items->isEmpty())
            <div class="p-12 text-center text-ink/50">Không có thông báo nào khớp bộ lọc.</div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gold-50 text-xs uppercase tracking-wide text-ink/60">
                        <tr>
                            <th class="text-left px-4 py-2">Thời gian</th>
                            <th class="text-left px-4 py-2">Người nhận</th>
                            <th class="text-left px-4 py-2">Loại</th>
                            <th class="text-left px-4 py-2">Nội dung</th>
                            <th class="text-left px-4 py-2">Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gold-50">
                    @foreach ($items as $n)
                        @php
                            $d = $n->data;
                            $u = $userMap[$n->notifiable_id] ?? null;
                        @endphp
                        <tr class="hover:bg-gold-50/50">
                            <td class="px-4 py-3 whitespace-nowrap text-ink/70">
                                <div>{{ $n->created_at->format('d/m/Y H:i') }}</div>
                                <div class="text-[11px] opacity-70">{{ $n->created_at->diffForHumans() }}</div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $u?->name ?? '#'.$n->notifiable_id }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <code class="text-[11px] px-2 py-0.5 bg-gold-50 rounded">{{ $d['event'] ?? '—' }}</code>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-semibold">{{ $d['tieu_de'] ?? $d['message'] ?? 'Thông báo' }}</div>
                                <div class="text-ink/60 line-clamp-2">{{ $d['noi_dung'] ?? '' }}</div>
                                @if (! empty($d['actor']))
                                    <div class="text-[11px] text-ink/50 mt-0.5">Bởi: {{ $d['actor'] }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="flex flex-col gap-1">
                                    @if ($n->hidden_at)
                                        <span class="text-[11px] px-2 py-0.5 bg-red-50 text-red-700 rounded font-semibold w-fit">User đã ẩn</span>
                                    @endif
                                    <span class="text-[11px] px-2 py-0.5 rounded font-semibold w-fit {{ $n->read_at ? 'bg-gray-100 text-gray-600' : 'bg-blue-50 text-blue-700' }}">
                                        {{ $n->read_at ? 'Đã đọc' : 'Chưa đọc' }}
                                    </span>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3 border-t border-gold-100">{{ $items->links() }}</div>
        @endif
    </div>
</div>
