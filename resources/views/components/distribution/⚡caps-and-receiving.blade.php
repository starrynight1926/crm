<?php

use App\Models\LeadCap;
use App\Models\OrgUnit;
use App\Models\User;
use App\Models\UserLeadSetting;
use Livewire\Component;

new class extends Component
{
    // Form trần lead
    public string $capScopeType = 'user'; // user / org_unit

    public string $capScopeId = '';

    public string $capValue = '';

    // Form nhận số
    public string $rUserId = '';

    public string $offUntil = '';

    public string $offReason = '';

    public function saveCap(): void
    {
        $this->validate([
            'capScopeType' => 'required|in:user,org_unit',
            'capScopeId' => 'required|integer',
            'capValue' => 'required|integer|min:0',
        ], [], ['capScopeId' => 'đối tượng', 'capValue' => 'trần/ngày']);

        LeadCap::updateOrCreate(
            ['scope_type' => $this->capScopeType, 'scope_id' => (int) $this->capScopeId],
            ['daily_cap' => (int) $this->capValue, 'active' => true]
        );

        $this->reset('capScopeId', 'capValue');
        session()->flash('cap_status', 'Đã lưu trần lead.');
    }

    public function deleteCap(int $id): void
    {
        LeadCap::findOrFail($id)->delete();
    }

    public function toggleReceiving(int $userId): void
    {
        $setting = UserLeadSetting::firstOrCreate(['user_id' => $userId], ['receiving' => true]);
        $setting->update([
            'receiving' => ! $setting->receiving,
            'off_until' => null,
            'off_reason' => null,
        ]);
    }

    public function setOff(): void
    {
        $this->validate([
            'rUserId' => 'required|exists:users,id',
            'offUntil' => 'required|date|after:today',
        ], [], ['rUserId' => 'nhân viên', 'offUntil' => 'nghỉ đến ngày']);

        UserLeadSetting::updateOrCreate(
            ['user_id' => (int) $this->rUserId],
            ['receiving' => false, 'off_until' => $this->offUntil, 'off_reason' => $this->offReason ?: 'Nghỉ phép']
        );

        $this->reset('rUserId', 'offUntil', 'offReason');
        session()->flash('recv_status', 'Đã đặt lịch nghỉ — hết ngày nghỉ tự nhận số lại.');
    }

    public function with(): array
    {
        return [
            'caps' => LeadCap::where('active', true)->get(),
            'userNames' => User::pluck('name', 'id'),
            'orgNames' => OrgUnit::pluck('name', 'id'),
            'users' => User::where('status', 'active')->orderBy('name')->get(),
            'orgUnits' => OrgUnit::where('active', true)->orderBy('path')->get(),
            'settings' => UserLeadSetting::all()->keyBy('user_id'),
        ];
    }
};
?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    {{-- Trần lead 3 cấp --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
        <h2 class="text-lg font-bold mb-1">Trần lead / ngày</h2>
        <p class="text-sm text-ink/50 mb-4">Setup được cả 3 cấp (phòng ban, team dùng đơn vị; cá nhân dùng sale). Chạm trần thì engine nhảy đích kế tiếp.</p>
        @if (session('cap_status'))<p class="mb-3 text-sm text-green-700">{{ session('cap_status') }}</p>@endif

        <div class="flex flex-wrap items-end gap-3 mb-4">
            <div>
                <label class="block text-xs font-semibold text-ink/50 mb-1">Cấp</label>
                <select wire:model.live="capScopeType" class="border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                    <option value="user">Cá nhân</option>
                    <option value="org_unit">Phòng ban / Team</option>
                </select>
            </div>
            <div class="flex-1 min-w-40">
                <label class="block text-xs font-semibold text-ink/50 mb-1">Đối tượng</label>
                <select wire:model="capScopeId" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                    <option value="">— chọn —</option>
                    @if ($capScopeType === 'user')
                        @foreach ($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                    @else
                        @foreach ($orgUnits as $o)<option value="{{ $o->id }}">{{ str_repeat('— ', $o->depth) }}{{ $o->name }}</option>@endforeach
                    @endif
                </select>
                @error('capScopeId')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-semibold text-ink/50 mb-1">Trần/ngày</label>
                <input type="number" wire:model="capValue" min="0" class="w-24 border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                @error('capValue')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <button wire:click="saveCap" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-4 py-2 rounded-md">Lưu</button>
        </div>

        <div class="divide-y divide-gold-50">
            @forelse ($caps as $cap)
                <div class="flex items-center gap-3 py-2 text-sm">
                    <span class="text-xs {{ $cap->scope_type === 'user' ? 'bg-blue-50 border-blue-200 text-blue-700' : 'bg-gold-50 border-gold-200 text-gold-700' }} border px-2 py-0.5 rounded">
                        {{ $cap->scope_type === 'user' ? 'Cá nhân' : 'Đơn vị' }}
                    </span>
                    <span class="flex-1 font-medium">
                        {{ $cap->scope_type === 'user' ? ($userNames[$cap->scope_id] ?? '#' . $cap->scope_id) : ($orgNames[$cap->scope_id] ?? '#' . $cap->scope_id) }}
                    </span>
                    <span class="font-mono font-bold">{{ $cap->daily_cap }}/ngày</span>
                    <button wire:click="deleteCap({{ $cap->id }})" wire:confirm="Bỏ trần này?" class="text-red-400 hover:text-red-600 text-lg leading-none px-1">×</button>
                </div>
            @empty
                <p class="py-4 text-sm text-ink/40">Chưa đặt trần nào — mọi đích nhận không giới hạn.</p>
            @endforelse
        </div>
    </div>

    {{-- Bật/tắt nhận số --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
        <h2 class="text-lg font-bold mb-1">Nhận số & nghỉ phép</h2>
        <p class="text-sm text-ink/50 mb-4">Sale tắt nhận số bị loại khỏi vòng chia. Đặt lịch nghỉ có hạn — qua ngày tự nhận lại.</p>
        @if (session('recv_status'))<p class="mb-3 text-sm text-green-700">{{ session('recv_status') }}</p>@endif

        <div class="flex flex-wrap items-end gap-3 mb-4 pb-4 border-b border-gold-100">
            <div class="flex-1 min-w-40">
                <label class="block text-xs font-semibold text-ink/50 mb-1">Nhân viên nghỉ phép</label>
                <select wire:model="rUserId" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                    <option value="">— chọn —</option>
                    @foreach ($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                </select>
                @error('rUserId')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-semibold text-ink/50 mb-1">Nghỉ đến hết</label>
                <x-date-input field="offUntil" class="px-3 py-2" />
                @error('offUntil')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <button wire:click="setOff" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-4 py-2 rounded-md">Đặt nghỉ</button>
        </div>

        <div class="divide-y divide-gold-50">
            @foreach ($users as $u)
                @php
                    $setting = $settings[$u->id] ?? null;
                    $receiving = \App\Models\UserLeadSetting::isReceiving($u->id);
                @endphp
                <div class="flex items-center gap-3 py-2 text-sm">
                    <span class="w-2 h-2 rounded-full {{ $receiving ? 'bg-green-500' : 'bg-red-500' }}"></span>
                    <span class="flex-1 font-medium">{{ $u->name }}</span>
                    @if (! $receiving && $setting?->off_until)
                        <span class="text-xs text-amber-700">nghỉ đến {{ $setting->off_until->format('d/m/Y') }}</span>
                    @endif
                    <button wire:click="toggleReceiving({{ $u->id }})"
                            class="text-xs font-semibold {{ $receiving ? 'text-red-700 border-red-200 hover:bg-red-50' : 'text-green-700 border-green-200 hover:bg-green-50' }} border px-3 py-1.5 rounded-md">
                        {{ $receiving ? 'Tắt nhận số' : 'Bật nhận số' }}
                    </button>
                </div>
            @endforeach
        </div>
    </div>
</div>
