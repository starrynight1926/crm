<?php

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Livewire\Component;

new class extends Component
{
    public bool $open = false;
    public bool $sent = false;
    public string $name = '';
    public string $co_so = '';
    public string $contact = '';
    public string $description = '';

    public function mount(): void
    {
        if ($u = auth()->user()) {
            $this->name = $u->name ?? '';
            $this->contact = $u->email ?? '';
        }
    }

    public function submit()
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'co_so' => ['nullable', 'string', 'max:80'],
            'contact' => ['nullable', 'string', 'max:120'],
            'description' => ['required', 'string', 'min:5', 'max:5000'],
        ]);

        $ticket = SupportTicket::create($data + [
            'user_id' => auth()->id(),
            'status' => 'cho_xu_ly',
        ]);

        SupportTicketMessage::create([
            'ticket_id' => $ticket->id,
            'sender_type' => 'user',
            'sender_name' => $this->name,
            'sender_user_id' => auth()->id(),
            'body' => $this->description,
        ]);

        if (auth()->check()) {
            return redirect('/ho-tro/' . $ticket->id);
        }

        // Guest: hiển thị success trong modal, không redirect.
        $this->reset(['description']);
        $this->sent = true;
    }
}; ?>

<div x-data="{ menu: false }" @click.outside="menu = false" class="fixed bottom-5 right-5 z-[9999]">
    {{-- Popover 2 mục — 2026-09-04: gộp trigger "tạo" + "danh sách" vào 1 bubble. --}}
    <div x-show="menu" x-cloak x-transition
         class="absolute bottom-16 right-0 min-w-[220px] bg-white rounded-xl shadow-2xl border border-gold-100 overflow-hidden">
        <button type="button" @click="menu = false" wire:click="$set('open', true)"
                class="w-full flex items-center gap-3 px-4 py-3 hover:bg-gold-50 text-left">
            <span class="material-symbols-outlined text-[20px] text-ink/70">edit_note</span>
            <span class="text-sm text-ink">Tạo ticket hỗ trợ</span>
        </button>
        <a href="/ho-tro"
           class="flex items-center gap-3 px-4 py-3 hover:bg-gold-50 border-t border-gold-100">
            <span class="material-symbols-outlined text-[20px] text-ink/70">list</span>
            <span class="text-sm text-ink">Danh sách ticket</span>
        </a>
    </div>

    {{-- Bubble "?" duy nhất --}}
    <button type="button" @click="menu = !menu"
            title="Hỗ trợ"
            class="w-14 h-14 rounded-full bg-gold-600 hover:bg-gold-700 text-white shadow-lg flex items-center justify-center text-2xl font-bold transition-transform hover:scale-110">
        ?
    </button>

    {{-- Modal --}}
    @if ($open)
    <div class="fixed inset-0 z-[10000] bg-black/40 flex items-center justify-center p-4"
         wire:click.self="$set('open', false)">
        <div class="bg-white rounded-xl shadow-2xl max-w-lg w-full max-h-[90vh] overflow-auto">
            <div class="flex items-center justify-between px-5 py-4 border-b border-gold-100">
                <h3 class="text-lg font-bold text-ink">Phản hồi / Yêu cầu hỗ trợ</h3>
                <button type="button" wire:click="$set('open', false)" class="text-ink/40 hover:text-ink text-2xl leading-none">×</button>
            </div>
            @if ($sent)
            <div class="p-6 text-center">
                <div class="text-4xl mb-3">✅</div>
                <p class="font-semibold mb-1">Đã gửi ticket!</p>
                <p class="text-sm text-ink/60">Admin sẽ phản hồi qua thông tin liên hệ bạn cung cấp.</p>
                <button type="button" wire:click="$set('open', false)" class="mt-4 px-4 py-2 rounded-lg bg-gold-600 hover:bg-gold-700 text-white font-semibold">Đóng</button>
            </div>
            @else
            <form wire:submit="submit" class="p-5 space-y-3">
                <div>
                    <label class="block text-sm font-medium mb-1">Họ tên <span class="text-red-600">*</span></label>
                    <input type="text" wire:model="name" required
                           class="w-full px-3 py-2 border border-gold-200 rounded-lg focus:outline-none focus:border-gold-500">
                    @error('name')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Cơ sở</label>
                    <input type="text" wire:model="co_so" placeholder="HN / HCM / DN..."
                           class="w-full px-3 py-2 border border-gold-200 rounded-lg focus:outline-none focus:border-gold-500">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Thông tin liên hệ</label>
                    <input type="text" wire:model="contact" placeholder="Email hoặc SĐT"
                           class="w-full px-3 py-2 border border-gold-200 rounded-lg focus:outline-none focus:border-gold-500">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Mô tả vấn đề <span class="text-red-600">*</span></label>
                    <textarea wire:model="description" rows="5" required minlength="5"
                              class="w-full px-3 py-2 border border-gold-200 rounded-lg focus:outline-none focus:border-gold-500"></textarea>
                    @error('description')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="$set('open', false)"
                            class="px-4 py-2 rounded-lg border border-gold-200 text-ink hover:bg-gold-50">Hủy</button>
                    <button type="submit"
                            class="px-4 py-2 rounded-lg bg-gold-600 hover:bg-gold-700 text-white font-semibold">Gửi</button>
                </div>
            </form>
            @endif
        </div>
    </div>
    @endif
</div>
