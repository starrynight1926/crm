<?php

use Livewire\Component;

new class extends Component
{
    public bool $open = false;

    public function toggle(): void
    {
        // 2026-08-02: KHÔNG auto mark all read khi mở — chỉ mark khi user click entry hoặc bấm "Đánh dấu tất cả".
        $this->open = ! $this->open;
    }

    public function markRead(string $id): void
    {
        auth()->user()->notifications()->where('id', $id)->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function markAllRead(): void
    {
        auth()->user()->unreadNotifications()->whereNull('hidden_at')->update(['read_at' => now()]);
    }

    public function hide(string $id): void
    {
        $n = auth()->user()->notifications()->where('id', $id)->first();
        if ($n && ! $n->hidden_at) {
            $n->forceFill(['hidden_at' => now()])->save();
        }
    }

    public function hideAll(): void
    {
        auth()->user()->notifications()->whereNull('hidden_at')->update(['hidden_at' => now()]);
    }

    public function with(): array
    {
        return [
            'unread' => auth()->user()->notifications()->whereNull('hidden_at')->whereNull('read_at')->count(),
            'notifications' => $this->open
                ? auth()->user()->notifications()->whereNull('hidden_at')->latest()->limit(10)->get()
                : collect(),
        ];
    }
};
?>

<div class="relative" wire:poll.10s>
    <button wire:click="toggle" class="relative p-2 rounded-md text-ink/60 hover:text-gold-700 hover:bg-gold-50">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"/>
        </svg>
        @if ($unread > 0)
            <span class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] bg-red-600 text-white text-[10px] font-bold rounded-full flex items-center justify-center px-1">
                {{ $unread > 9 ? '9+' : $unread }}
            </span>
        @endif
    </button>

    @if ($open)
        <div class="absolute right-0 top-11 w-96 bg-white border border-gold-200 rounded-lg shadow-card z-50">
            <div class="px-4 py-3 border-b border-gold-100 flex items-center justify-between gap-2">
                <div class="font-bold text-sm">Thông báo @if ($unread > 0) <span class="text-xs font-normal text-red-600">({{ $unread }} chưa đọc)</span> @endif</div>
                @if ($notifications->isNotEmpty())
                    <div class="flex items-center gap-3">
                        @if ($unread > 0)
                            <button type="button" wire:click="markAllRead"
                                    class="text-xs text-blue-600 hover:underline">Đánh dấu đã đọc</button>
                        @endif
                        <button type="button" wire:click="hideAll"
                                wire:confirm="Xóa tất cả thông báo? (Admin vẫn xem được trong nhật ký)"
                                class="text-xs text-red-600 hover:underline">Xóa tất cả</button>
                    </div>
                @endif
            </div>
            <div class="max-h-96 overflow-y-auto divide-y divide-gold-50">
                @forelse ($notifications as $n)
                    @php
                        $d    = $n->data;
                        $link = $d['link'] ?? (isset($d['lead_id']) ? '/leads/'.$d['lead_id'] : '#');
                        $title= $d['tieu_de'] ?? $d['message'] ?? 'Thông báo';
                        $body = $d['noi_dung'] ?? null;
                        $event = $d['event'] ?? '';
                        $bodyLower = mb_strtolower((string) $body);
                        // Map màu + icon theo event + keyword trong body.
                        //  - Xanh lá: duyệt / booked / assigned / đến / hoàn thành.
                        //  - Đỏ: từ chối / hủy / no-show / recalled / delete.
                        //  - Amber: đến trễ / cảnh báo.
                        //  - Xanh dương: đổi trạng thái / đổi lịch / ghi chú / bình luận.
                        //  - Gold: default (lead mới, chuyển).
                        [$color, $icon] = match (true) {
                            str_contains($bodyLower, 'từ chối'), str_contains($bodyLower, 'hủy'), str_contains($bodyLower, 'no-show'),
                            $event === 'lead.recalled', str_contains($title, 'bị xóa')
                                => ['red', '❌'],
                            str_contains($bodyLower, 'đến trễ'), str_contains($bodyLower, 'tới trễ')
                                => ['amber', '⏰'],
                            str_contains($bodyLower, 'đã duyệt'), str_contains($bodyLower, 'da_duyet'),
                            str_contains($bodyLower, 'đã tới'), str_contains($bodyLower, 'da_toi'),
                            str_contains($bodyLower, 'đã xong'), str_contains($bodyLower, 'da_xong'),
                            $event === 'lead.booked', $event === 'lead.assigned'
                                => ['emerald', '✅'],
                            $event === 'booking.status_changed', $event === 'booking.rescheduled',
                            $event === 'booking.note_added', $event === 'lead.note_added'
                                => ['blue', '💬'],
                            $event === 'lead.transferred'
                                => ['purple', '↪️'],
                            default => ['gold', '🔔'],
                        };
                        $borderCls = [
                            'red' => 'border-l-red-500 bg-red-50/60',
                            'amber' => 'border-l-amber-500 bg-amber-50/60',
                            'emerald' => 'border-l-emerald-500 bg-emerald-50/60',
                            'blue' => 'border-l-blue-500 bg-blue-50/60',
                            'purple' => 'border-l-purple-500 bg-purple-50/60',
                            'gold' => 'border-l-gold-500 bg-gold-50/40',
                        ][$color];
                        $textCls = [
                            'red' => 'text-red-800',
                            'amber' => 'text-amber-800',
                            'emerald' => 'text-emerald-800',
                            'blue' => 'text-blue-800',
                            'purple' => 'text-purple-800',
                            'gold' => 'text-gold-800',
                        ][$color];
                    @endphp
                    <div class="relative group border-l-4 {{ $borderCls }} {{ $n->read_at ? 'opacity-70' : '' }}" wire:key="notif-{{ $n->id }}">
                        <a href="{{ $link }}"
                           @if (! $n->read_at) wire:click="markRead('{{ $n->id }}')" @endif
                           class="block px-3 py-2.5 pr-9 hover:bg-white text-sm transition-colors">
                            <div class="flex items-start gap-2">
                                <span class="text-base leading-none mt-0.5">{{ $icon }}</span>
                                <div class="flex-1 min-w-0">
                                    <div class="{{ $textCls }} {{ $n->read_at ? 'font-normal' : 'font-semibold' }} truncate">{{ $title }}</div>
                                    @if ($body)
                                        <div class="text-xs text-ink/60 line-clamp-2 mt-0.5">{{ $body }}</div>
                                    @endif
                                    <div class="text-[11px] text-ink/40 mt-0.5">{{ $n->created_at->diffForHumans() }}</div>
                                </div>
                            </div>
                        </a>
                        <button type="button" wire:click="hide('{{ $n->id }}')" title="Xóa"
                                class="absolute top-2 right-2 w-6 h-6 rounded-full text-ink/40 hover:bg-red-100 hover:text-red-600 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                @empty
                    <div class="px-4 py-8 text-center text-ink/40">
                        <svg class="w-10 h-10 mx-auto opacity-40 mb-2" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.143 17.082a24.248 24.248 0 003.844.148m-3.844-.148a23.856 23.856 0 01-5.455-1.31 8.964 8.964 0 002.3-5.542m3.155 6.852a3 3 0 005.667 1.97m1.965-2.277L21 21m-4.225-4.225a23.81 23.81 0 003.536-1.003A8.967 8.967 0 0118 9.75V9A6 6 0 006.53 6.53m10.245 10.245L6.53 6.53M3 3l3.53 3.53"/>
                        </svg>
                        <p class="text-sm">Không có thông báo mới</p>
                    </div>
                @endforelse
            </div>
            <a href="{{ route('notifications.index') }}" class="block px-4 py-2.5 border-t border-gold-100 text-center text-sm font-semibold text-gold-700 hover:bg-gold-50 flex items-center justify-center gap-1.5">
                📋 Xem tất cả thông báo
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
            </a>
        </div>
    @endif
</div>
