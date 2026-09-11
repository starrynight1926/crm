<?php

use Illuminate\Support\Facades\Hash;
use Livewire\Component;

new class extends Component
{
    public string $current = '';

    public string $new = '';

    public string $confirm = '';

    public function save(): void
    {
        $this->validate([
            'current' => 'required|string',
            'new' => 'required|string|different:current|confirmed:confirm',
            'confirm' => 'required|string',
        ], [
            'new.different' => 'Mật khẩu mới phải khác mật khẩu hiện tại.',
            'new.confirmed' => 'Xác nhận mật khẩu không khớp.',
        ], [
            'current' => 'mật khẩu hiện tại',
            'new' => 'mật khẩu mới',
            'confirm' => 'xác nhận mật khẩu',
        ]);

        if (! Hash::check($this->current, auth()->user()->password)) {
            $this->addError('current', 'Mật khẩu hiện tại không đúng.');
            return;
        }

        auth()->user()->update(['password' => $this->new]);

        $this->reset('current', 'new', 'confirm');
        session()->flash('ok', 'Đã đổi mật khẩu thành công.');
    }
};
?>

<div class="max-w-lg">
    <div class="flex items-center gap-3 mb-1">
        <svg class="w-7 h-7 text-gold-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
        </svg>
        <h1 class="text-3xl font-bold">Đổi mật khẩu</h1>
    </div>
    <p class="text-sm text-ink/60 mb-7">Nhập mật khẩu hiện tại và mật khẩu mới (tối thiểu 8 ký tự).</p>

    @if (session('ok'))
        <div class="mb-4 rounded-md border border-green-200 bg-green-50 text-green-700 text-sm px-4 py-2">
            {{ session('ok') }}
        </div>
    @endif

    <form wire:submit="save" class="bg-white border border-gold-200 rounded-xl p-6 shadow-card space-y-4">
        <div>
            <label class="block text-sm font-semibold mb-1">Mật khẩu hiện tại</label>
            <input type="password" wire:model="current" autocomplete="current-password"
                   class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
            @error('current')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="block text-sm font-semibold mb-1">Mật khẩu mới</label>
            <input type="password" wire:model="new" autocomplete="new-password"
                   class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
            @error('new')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="block text-sm font-semibold mb-1">Xác nhận mật khẩu mới</label>
            <input type="password" wire:model="confirm" autocomplete="new-password"
                   class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
            @error('confirm')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>
        <div class="pt-2">
            <button type="submit"
                    class="bg-gold-600 hover:bg-gold-700 text-white text-xs font-semibold tracking-widest uppercase px-5 py-3 rounded-md">
                Lưu mật khẩu mới
            </button>
        </div>
    </form>
</div>
