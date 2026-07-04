<?php

use Livewire\Component;

new class extends Component
{
    public bool $open = false;

    public function toggle(): void
    {
        $this->open = ! $this->open;

        if ($this->open) {
            auth()->user()->unreadNotifications->markAsRead();
        }
    }

    public function with(): array
    {
        return [
            'unread' => auth()->user()->unreadNotifications()->count(),
            'notifications' => $this->open
                ? auth()->user()->notifications()->latest()->limit(10)->get()
                : collect(),
        ];
    }
};
?>

{{-- Poll 10s: phương án chính khi chưa bật Reverb; có Reverb thì Echo đẩy realtime (Phase 4 ghi chú) --}}
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
        <div class="absolute right-0 top-11 w-80 bg-white border border-gold-200 rounded-lg shadow-card z-50">
            <div class="px-4 py-3 border-b border-gold-100 font-bold text-sm">Thông báo</div>
            <div class="max-h-96 overflow-y-auto divide-y divide-gold-50">
                @forelse ($notifications as $notification)
                    <a href="{{ isset($notification->data['lead_id']) ? route('leads.show', $notification->data['lead_id']) : '#' }}"
                       class="block px-4 py-3 hover:bg-gold-50 text-sm">
                        <div class="{{ $notification->read_at ? 'text-ink/60' : 'font-semibold' }}">{{ $notification->data['message'] ?? 'Thông báo' }}</div>
                        <div class="text-xs text-ink/40 mt-0.5">{{ $notification->created_at->diffForHumans() }}</div>
                    </a>
                @empty
                    <p class="px-4 py-6 text-sm text-ink/40 text-center">Chưa có thông báo nào.</p>
                @endforelse
            </div>
        </div>
    @endif
</div>
