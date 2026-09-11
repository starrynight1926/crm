<?php

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $ticketId;

    public string $reply = '';

    public function mount(int $ticketId): void
    {
        $this->ticketId = $ticketId;
    }

    public function sendReply()
    {
        $this->validate(['reply' => ['required', 'string', 'min:1', 'max:5000']]);

        $ticket = SupportTicket::findOrFail($this->ticketId);
        $u = auth()->user();
        $isAdmin = $u && $u->username === 'admin';

        // Guest chưa login → không trả lời được (chỉ admin và chủ ticket).
        abort_unless($u, 401);
        abort_unless($isAdmin || $ticket->user_id === $u->id, 403);

        SupportTicketMessage::create([
            'ticket_id' => $ticket->id,
            'sender_type' => $isAdmin ? 'admin' : 'user',
            'sender_name' => $u->name,
            'sender_user_id' => $u->id,
            'body' => $this->reply,
        ]);

        $this->reply = '';
    }

    public function changeStatus(string $status)
    {
        $u = auth()->user();
        abort_unless($u && $u->username === 'admin', 403);
        abort_unless(array_key_exists($status, SupportTicket::STATUSES), 400);

        SupportTicket::where('id', $this->ticketId)->update(['status' => $status]);
    }

    public function with(): array
    {
        $ticket = SupportTicket::with('messages')->findOrFail($this->ticketId);
        $u = auth()->user();
        $isAdmin = $u && $u->username === 'admin';

        // Xem: admin xem hết; user chỉ ticket của mình; guest xem qua URL trực tiếp.
        if ($u && !$isAdmin && $ticket->user_id !== $u->id) {
            abort(403);
        }

        return ['ticket' => $ticket, 'isAdmin' => $isAdmin];
    }
}; ?>

<div wire:poll.5s class="max-w-3xl mx-auto px-6 py-8">
    <a href="/ho-tro" class="text-sm text-gold-700 hover:underline">← Quay lại danh sách</a>

    <div class="mt-4 bg-white rounded-xl shadow-card border border-gold-100 p-6">
        <div class="flex items-start justify-between gap-3 mb-4">
            <div>
                <h1 class="text-2xl font-bold mb-1">Ticket #{{ $ticket->id }}</h1>
                <p class="text-sm text-ink/60">
                    {{ $ticket->name }}
                    @if ($ticket->co_so) · {{ $ticket->co_so }}@endif
                    @if ($ticket->contact) · {{ $ticket->contact }}@endif
                    · {{ $ticket->created_at->format('d/m/Y H:i') }}
                </p>
            </div>
            <span class="text-xs px-3 py-1 rounded-full border {{ $ticket->statusColor() }} whitespace-nowrap">{{ $ticket->statusLabel() }}</span>
        </div>

        @if ($isAdmin)
        <div class="mb-4 flex gap-2 flex-wrap items-center text-sm">
            <span class="text-ink/60">Chuyển trạng thái:</span>
            @foreach (\App\Models\SupportTicket::STATUSES as $key => $label)
                <button wire:click="changeStatus('{{ $key }}')"
                        @class([
                            'px-3 py-1 rounded-full text-xs border',
                            'bg-gold-600 text-white border-gold-600' => $ticket->status === $key,
                            'border-gold-200 hover:bg-gold-50' => $ticket->status !== $key,
                        ])>{{ $label }}</button>
            @endforeach
        </div>
        @endif

        <div class="space-y-3">
            @foreach ($ticket->messages as $m)
            <div class="flex {{ $m->sender_type === 'admin' ? 'justify-start' : 'justify-end' }}">
                <div class="max-w-[75%] rounded-xl px-4 py-3 {{ $m->sender_type === 'admin' ? 'bg-gold-100 text-ink' : 'bg-gold-600 text-white' }}">
                    <div class="text-xs opacity-75 mb-1">
                        {{ $m->sender_type === 'admin' ? '🛡 Admin' : '👤' }} {{ $m->sender_name }} · {{ $m->created_at->format('d/m H:i') }}
                    </div>
                    <div class="whitespace-pre-wrap text-sm">{{ $m->body }}</div>
                </div>
            </div>
            @endforeach
        </div>

        @auth
        <form wire:submit="sendReply" class="mt-6 pt-4 border-t border-gold-100">
            <textarea wire:model="reply" rows="3" placeholder="Trả lời..." required
                      class="w-full px-3 py-2 border border-gold-200 rounded-lg focus:outline-none focus:border-gold-500"></textarea>
            @error('reply')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            <div class="mt-2 flex justify-end">
                <button type="submit" class="px-4 py-2 rounded-lg bg-gold-600 hover:bg-gold-700 text-white font-semibold">Gửi trả lời</button>
            </div>
        </form>
        @else
        <p class="mt-6 pt-4 border-t border-gold-100 text-sm text-ink/50 text-center">Đăng nhập để trả lời hoặc theo dõi ticket.</p>
        @endauth
    </div>
</div>
