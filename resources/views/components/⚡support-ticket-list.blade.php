<?php

use App\Models\SupportTicket;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $filterStatus = '';

    public function with(): array
    {
        $u = auth()->user();
        $isAdmin = $u && $u->username === 'admin';

        $q = SupportTicket::query()->latest();
        if (!$isAdmin) {
            abort_unless($u, 401, 'Cần đăng nhập để xem danh sách ticket.');
            $q->where('user_id', $u->id);
        }
        if ($this->filterStatus !== '') {
            $q->where('status', $this->filterStatus);
        }

        return [
            'items' => $q->paginate(30),
            'isAdmin' => $isAdmin,
            'counts' => $isAdmin ? [
                'cho_xu_ly' => SupportTicket::where('status', 'cho_xu_ly')->count(),
                'da_xu_ly' => SupportTicket::where('status', 'da_xu_ly')->count(),
                'tu_choi' => SupportTicket::where('status', 'tu_choi')->count(),
            ] : null,
        ];
    }
}; ?>

<div wire:poll.5s class="max-w-5xl mx-auto px-6 py-8">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold mb-1">Hỗ trợ / Phản hồi</h1>
            <p class="text-sm text-ink/60">
                @if ($isAdmin)
                    Tất cả ticket của người dùng ({{ $counts['cho_xu_ly'] }} chờ · {{ $counts['da_xu_ly'] }} đã xử lý · {{ $counts['tu_choi'] }} từ chối)
                @else
                    Danh sách ticket bạn đã gửi
                @endif
            </p>
        </div>
    </div>

    <div class="mb-4 flex gap-2 flex-wrap">
        <button wire:click="$set('filterStatus', '')"
                class="px-3 py-1.5 rounded-full text-sm border {{ $filterStatus === '' ? 'bg-gold-600 text-white border-gold-600' : 'border-gold-200 hover:bg-gold-50' }}">Tất cả</button>
        @foreach (\App\Models\SupportTicket::STATUSES as $key => $label)
            <button wire:click="$set('filterStatus', '{{ $key }}')"
                    class="px-3 py-1.5 rounded-full text-sm border {{ $filterStatus === $key ? 'bg-gold-600 text-white border-gold-600' : 'border-gold-200 hover:bg-gold-50' }}">{{ $label }}</button>
        @endforeach
    </div>

    <div class="bg-white rounded-xl shadow-card border border-gold-100 divide-y divide-gold-100">
        @forelse ($items as $t)
        <a href="/ho-tro/{{ $t->id }}" class="block p-4 hover:bg-gold-50/50 transition">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 mb-1 flex-wrap">
                        <span class="font-semibold text-ink">#{{ $t->id }} — {{ $t->name }}</span>
                        @if ($t->co_so)<span class="text-xs px-2 py-0.5 rounded bg-gold-100 text-gold-800">{{ $t->co_so }}</span>@endif
                        <span class="text-xs px-2 py-0.5 rounded border {{ $t->statusColor() }}">{{ $t->statusLabel() }}</span>
                    </div>
                    <p class="text-sm text-ink/70 line-clamp-2">{{ $t->description }}</p>
                    <p class="text-xs text-ink/40 mt-1">
                        {{ $t->created_at->format('d/m/Y H:i') }}
                        @if ($t->contact) · {{ $t->contact }}@endif
                    </p>
                </div>
            </div>
        </a>
        @empty
        <div class="p-8 text-center text-ink/50">Chưa có ticket nào.</div>
        @endforelse
    </div>

    <div class="mt-4">{{ $items->links() }}</div>
</div>
