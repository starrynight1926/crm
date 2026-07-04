<?php

use App\Models\Assignment;
use App\Models\Permission;
use App\Models\Role;
use Livewire\Component;

new class extends Component
{
    public ?int $selectedRoleId = null;

    public string $name = '';

    public string $description = '';

    /** @var array<int, bool> permission_id => checked */
    public array $checked = [];

    public bool $creating = false;

    public function mount(): void
    {
        $first = Role::orderBy('id')->first();
        if ($first) {
            $this->selectRole($first->id);
        }
    }

    public function selectRole(int $roleId): void
    {
        $role = Role::with('permissions')->findOrFail($roleId);
        $this->selectedRoleId = $role->id;
        $this->creating = false;
        $this->name = $role->name;
        $this->description = $role->description ?? '';
        $ids = $role->permissions->pluck('id')->all();
        $this->checked = Permission::pluck('id')
            ->mapWithKeys(fn ($id) => [$id => in_array($id, $ids)])
            ->all();
    }

    public function startCreate(): void
    {
        $this->selectedRoleId = null;
        $this->creating = true;
        $this->name = '';
        $this->description = '';
        $this->checked = Permission::pluck('id')->mapWithKeys(fn ($id) => [$id => false])->all();
    }

    public function toggleAll(bool $state): void
    {
        $this->checked = array_map(fn () => $state, $this->checked);
    }

    public function toggleGroup(string $group, bool $state): void
    {
        foreach (Permission::where('group', $group)->pluck('id') as $id) {
            $this->checked[$id] = $state;
        }
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
        ], [], ['name' => 'tên vai trò']);

        if ($this->creating) {
            $role = Role::create(['name' => $this->name, 'description' => $this->description]);
        } else {
            $role = Role::findOrFail($this->selectedRoleId);
            $role->update(['name' => $this->name, 'description' => $this->description]);
        }

        $role->permissions()->sync(array_keys(array_filter($this->checked)));
        $this->selectRole($role->id);
        $this->dispatch('saved');
        session()->flash('status', "Đã lưu vai trò \"{$role->name}\".");
    }

    public function deleteRole(): void
    {
        $role = Role::findOrFail($this->selectedRoleId);

        if ($role->is_system) {
            session()->flash('error', 'Không thể xóa vai trò hệ thống.');
            return;
        }
        if (Assignment::where('role_id', $role->id)->exists()) {
            session()->flash('error', 'Vai trò đang được gán cho nhân viên, gỡ hết assignment trước khi xóa.');
            return;
        }

        $role->delete();
        $first = Role::orderBy('id')->first();
        $first ? $this->selectRole($first->id) : $this->startCreate();
    }

    public function with(): array
    {
        return [
            'roles' => Role::withCount('permissions')->orderBy('id')->get(),
            'groups' => Permission::orderBy('position')->get()->groupBy('group'),
            'groupLabels' => [
                'lead' => 'Quản lý Lead',
                'distribution' => 'Chia số & Kho lead',
                'organization' => 'Tổ chức & Nhân sự',
                'service' => 'Dịch vụ & Thanh toán',
                'report' => 'Báo cáo',
                'system' => 'Hệ thống & Kết nối',
            ],
        ];
    }
};
?>

<div class="grid grid-cols-1 lg:grid-cols-[320px_1fr] gap-6 items-start">
    {{-- Danh sách role --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card">
        <div class="px-5 py-4 border-b border-gold-100 flex items-center justify-between">
            <h2 class="text-lg font-bold">Danh sách Role</h2>
            <button wire:click="startCreate" class="w-8 h-8 rounded-md text-gold-600 hover:bg-gold-50 flex items-center justify-center text-2xl leading-none" title="Thêm vai trò">+</button>
        </div>
        <div class="p-3 space-y-2">
            @foreach ($roles as $role)
                <button wire:click="selectRole({{ $role->id }})"
                        class="w-full text-left px-4 py-3 rounded-lg border flex items-center gap-3
                               {{ $selectedRoleId === $role->id ? 'bg-gold-100 border-gold-300' : 'bg-white border-gold-100 hover:border-gold-300' }}">
                    <span class="w-9 h-9 rounded-lg {{ $selectedRoleId === $role->id ? 'bg-gold-600 text-white' : 'bg-gold-50 text-gold-600' }} flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
                        </svg>
                    </span>
                    <span class="flex-1">
                        <span class="block font-semibold">{{ $role->name }}</span>
                        <span class="block text-xs text-ink/50">{{ $role->description ?: $role->permissions_count . ' quyền' }}</span>
                    </span>
                    @if ($role->is_system)
                        <span class="text-[10px] font-semibold uppercase tracking-wider bg-gold-50 border border-gold-200 text-gold-700 px-2 py-0.5 rounded">Hệ thống</span>
                    @endif
                </button>
            @endforeach
            @if ($creating)
                <div class="w-full px-4 py-3 rounded-lg border bg-gold-100 border-gold-300 font-semibold text-gold-700">
                    Vai trò mới...
                </div>
            @endif
        </div>
    </div>

    {{-- Chi tiết quyền hạn --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card">
        <div class="px-6 py-4 border-b border-gold-100 flex flex-wrap items-center gap-4">
            <h2 class="text-lg font-bold shrink-0">Chi tiết quyền hạn:</h2>
            <input type="text" wire:model="name" placeholder="Tên vai trò"
                   class="border border-gold-200 rounded-md px-3 py-1.5 text-sm font-semibold uppercase tracking-wide bg-gold-50 focus:outline-none focus:border-gold-500 w-44">
            <input type="text" wire:model="description" placeholder="Mô tả ngắn"
                   class="border border-gold-200 rounded-md px-3 py-1.5 text-sm flex-1 min-w-40 focus:outline-none focus:border-gold-500">
            <label class="flex items-center gap-2 text-sm font-semibold cursor-pointer shrink-0">
                <input type="checkbox" wire:click="toggleAll($event.target.checked)"
                       @checked($checked && !in_array(false, $checked, true))
                       class="rounded border-gold-300 text-gold-600 focus:ring-gold-500 w-5 h-5">
                Chọn tất cả
            </label>
        </div>
        @error('name')
            <p class="px-6 pt-3 text-sm text-red-600">{{ $message }}</p>
        @enderror
        @if (session('error'))
            <p class="px-6 pt-3 text-sm text-red-600">{{ session('error') }}</p>
        @endif
        @if (session('status'))
            <p class="px-6 pt-3 text-sm text-green-700">{{ session('status') }}</p>
        @endif

        <div class="divide-y divide-gold-100">
            @foreach ($groups as $group => $permissions)
                <div class="px-6 py-4 grid grid-cols-1 sm:grid-cols-[240px_1fr] gap-3">
                    <div>
                        <div class="font-bold">{{ $groupLabels[$group] ?? $group }}</div>
                        <button type="button" wire:click="toggleGroup('{{ $group }}', true)" class="text-xs text-gold-600 hover:underline">chọn nhóm</button>
                        ·
                        <button type="button" wire:click="toggleGroup('{{ $group }}', false)" class="text-xs text-ink/40 hover:underline">bỏ nhóm</button>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2">
                        @foreach ($permissions as $permission)
                            <label class="flex items-center gap-2.5 text-sm cursor-pointer">
                                <input type="checkbox" wire:model="checked.{{ $permission->id }}"
                                       class="rounded border-gold-300 text-gold-600 focus:ring-gold-500 w-5 h-5">
                                <span>{{ $permission->label }}</span>
                                <code class="text-[10px] text-ink/30">{{ $permission->key }}</code>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <div class="px-6 py-4 border-t border-gold-100 bg-gold-50/50 rounded-b-xl flex items-center justify-between">
            <div class="text-xs text-ink/50 flex items-center gap-1.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/>
                </svg>
                Thay đổi có hiệu lực ngay sau khi người dùng tải lại trang.
            </div>
            <div class="flex items-center gap-3">
                @if (!$creating && $selectedRoleId)
                    <button wire:click="deleteRole" wire:confirm="Xóa vai trò này?"
                            class="text-sm text-red-700 border border-red-200 hover:bg-red-50 px-4 py-2 rounded-md">Xóa</button>
                @endif
                <button wire:click="save"
                        class="bg-gold-600 hover:bg-gold-700 text-white font-semibold px-6 py-2 rounded-md">Lưu cấu hình</button>
            </div>
        </div>
    </div>
</div>
