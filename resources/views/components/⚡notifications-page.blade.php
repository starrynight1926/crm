<?php

use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public function markAllRead(): void
    {
        auth()->user()->unreadNotifications()->whereNull('hidden_at')->update(['read_at' => now()]);
    }

    public function markRead(string $id): void
    {
        auth()->user()->notifications()->where('id', $id)->whereNull('read_at')->update(['read_at' => now()]);
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
        $q = auth()->user()->notifications()->whereNull('hidden_at')->latest();
        return [
            'items'  => $q->paginate(50),
            'unread' => auth()->user()->notifications()->whereNull('hidden_at')->whereNull('read_at')->count(),
        ];
    }
};
?>

<div wire:poll.5s>
    <div class="flex items-center justify-between gap-3 flex-wrap mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gold-700">Thông báo</h1>
            <p class="text-sm text-ink/60">{{ $items->total() }} thông báo · {{ $unread }} chưa đọc</p>
        </div>
        <div class="flex items-center gap-2">
            @if ($unread > 0)
                <button wire:click="markAllRead" class="px-3 py-2 text-sm bg-white border border-gold-200 rounded-md hover:bg-gold-50">
                    Đánh dấu tất cả đã đọc
                </button>
            @endif
            @if ($items->total() > 0)
                <button wire:click="hideAll" wire:confirm="Xóa tất cả thông báo? (Admin vẫn xem được trong nhật ký)"
                        class="px-3 py-2 text-sm bg-white border border-gold-200 rounded-md text-red-600 hover:bg-red-50">
                    Xóa tất cả
                </button>
            @endif
        </div>
    </div>

    @if ($items->isEmpty())
        <div class="bg-white border border-gold-200 rounded-lg p-12 text-center text-ink/50">
            <svg class="w-16 h-16 mx-auto opacity-30" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31"/>
            </svg>
            <p class="mt-3">Chưa có thông báo nào.</p>
        </div>
    @else
        <div class="space-y-2">
            @foreach ($items as $n)
                @php
                    $d     = $n->data;
                    $link  = $d['link'] ?? (isset($d['lead_id']) ? '/leads/'.$d['lead_id'] : '#');
                    $title = $d['tieu_de'] ?? $d['message'] ?? 'Thông báo';
                    $body  = $d['noi_dung'] ?? null;
                    $actor = $d['actor'] ?? null;
                    $event = $d['event'] ?? '';
                    $bodyLower = mb_strtolower((string) $body);
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
                        $event === 'lead.transferred' => ['purple', '↪️'],
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
                        'red' => 'text-red-800', 'amber' => 'text-amber-800',
                        'emerald' => 'text-emerald-800', 'blue' => 'text-blue-800',
                        'purple' => 'text-purple-800', 'gold' => 'text-gold-800',
                    ][$color];
                @endphp
                <div wire:key="n-{{ $n->id }}"
                     class="border-l-4 {{ $borderCls }} border border-gold-100 rounded-lg p-4 flex items-start gap-3 {{ $n->read_at ? 'opacity-70' : '' }}">
                    <div class="text-2xl leading-none mt-0.5">{{ $icon }}</div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-3">
                            <a href="{{ $link }}"
                               @if (! $n->read_at) wire:click="markRead('{{ $n->id }}')" @endif
                               class="{{ $textCls }} {{ $n->read_at ? 'font-normal' : 'font-semibold' }} hover:underline">{{ $title }}</a>
                            <span class="text-xs text-ink/50 whitespace-nowrap shrink-0">{{ $n->created_at->diffForHumans() }}</span>
                        </div>
                        @if ($body)
                            <p class="text-sm text-ink/70 mt-1 whitespace-pre-wrap">{{ $body }}</p>
                        @endif
                        @if ($actor)
                            <p class="text-xs text-ink/50 mt-1">Bởi: {{ $actor }}</p>
                        @endif
                    </div>
                    <div class="flex flex-col gap-1 shrink-0">
                        @if (! $n->read_at)
                            <button wire:click="markRead('{{ $n->id }}')" title="Đánh dấu đã đọc"
                                    class="w-8 h-8 rounded-full text-ink/40 hover:bg-blue-50 hover:text-blue-600 flex items-center justify-center">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                            </button>
                        @endif
                        <button wire:click="hide('{{ $n->id }}')" title="Xóa"
                                class="w-8 h-8 rounded-full text-ink/40 hover:bg-red-50 hover:text-red-600 flex items-center justify-center">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-6">{{ $items->links() }}</div>
    @endif
</div>
