<?php

use App\Models\OrgUnit;
use App\Models\Service;
use App\Models\User;
use App\Support\AdminScope;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * 2026-10-05 — Quick Sheets: trang chỉnh nhanh nhiều entity cùng chỗ (Google-Sheets style).
 *   Super admin only. 5 tab:
 *     - users     : Nhân sự
 *     - dich_vu   : Dịch vụ        (services.service_type = 'dich_vu')
 *     - lam_sang  : Dịch vụ lâm sàng (services.service_type = 'tham_kham')
 *     - tu_van    : Tư vấn         (services.service_type = 'tu_van')
 *     - org       : Cơ sở & phòng ban (OrgUnit, dropdown parent)
 */
new class extends Component
{
    use WithPagination;

    public string $tab = 'users';
    public string $search = '';
    public array $draft = [];
    public array $editing = []; // [row_id => fields]

    protected function queryString(): array
    {
        return ['tab' => ['except' => 'users']];
    }

    public function mount(): void
    {
        abort_unless(AdminScope::isSuperAdmin(), 403);
        $this->resetDraft();
    }

    public function updatedTab(): void
    {
        $this->resetPage();
        $this->resetDraft();
        $this->editing = [];
        $this->search = '';
        $this->resetErrorBag();
    }

    public function updatedSearch(): void { $this->resetPage(); }

    protected function resetDraft(): void
    {
        $this->draft = match ($this->tab) {
            'users'    => ['name'=>'', 'email'=>'', 'phone'=>'', 'job_title'=>'', 'username'=>'', 'status'=>'active'],
            'dich_vu', 'lam_sang', 'tu_van'
                       => ['code'=>'', 'name'=>'', 'pricing_type'=>'package', 'package_price'=>null, 'price_usd'=>null, 'active'=>true, 'notes'=>''],
            'org'      => ['name'=>'', 'code'=>'', 'parent_id'=>null, 'position'=>0, 'active'=>true],
            default    => [],
        };
    }

    /** Map tab → service_type khi tab là service-based. */
    protected function serviceTypeOfTab(): ?string
    {
        return match ($this->tab) {
            'dich_vu'  => 'dich_vu',
            'lam_sang' => 'tham_kham',
            'tu_van'   => 'tu_van',
            default    => null,
        };
    }

    public function addRow(): void
    {
        try {
            match ($this->tab) {
                'users'    => $this->addUser(),
                'dich_vu', 'lam_sang', 'tu_van' => $this->addService(),
                'org'      => $this->addOrg(),
                default    => null,
            };
        } catch (\Throwable $e) {
            $this->addError('draft', $e->getMessage());
            return;
        }
        $this->resetDraft();
    }

    protected function addUser(): void
    {
        $data = $this->draft;
        if (! $data['name']) throw new \Exception('Thiếu họ tên');
        if (! $data['email']) throw new \Exception('Thiếu email');
        if (User::where('email', $data['email'])->exists()) throw new \Exception('Email đã tồn tại');
        User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'phone'    => $data['phone'] ?: null,
            'job_title'=> $data['job_title'] ?: null,
            'username' => $data['username'] ?: null,
            'status'   => $data['status'] ?: 'active',
            // Password rỗng — super admin set sau ở /settings/staff hoặc user tự reset.
            'password' => bcrypt(\Illuminate\Support\Str::random(20)),
        ]);
    }

    protected function addService(): void
    {
        $data = $this->draft;
        if (! $data['name']) throw new \Exception('Thiếu tên dịch vụ');
        Service::create([
            'name'          => $data['name'],
            'code'          => $data['code'] ?: null,
            'service_type'  => $this->serviceTypeOfTab(),
            'pricing_type'  => $data['pricing_type'] ?: 'package',
            'package_price' => $data['package_price'] !== null && $data['package_price'] !== '' ? (int) $data['package_price'] : null,
            'price_usd'     => $data['price_usd'] !== null && $data['price_usd'] !== '' ? (float) $data['price_usd'] : null,
            'active'        => (bool) $data['active'],
            'notes'         => $data['notes'] ?: null,
        ]);
    }

    protected function addOrg(): void
    {
        $data = $this->draft;
        if (! $data['name']) throw new \Exception('Thiếu tên đơn vị');
        $parent = $data['parent_id'] ? OrgUnit::find((int) $data['parent_id']) : null;
        $depth  = $parent ? ($parent->depth + 1) : 0;
        $path   = $parent ? ($parent->path) : '/'; // tạm; sẽ update sau save để có id
        $row = OrgUnit::create([
            'name'      => $data['name'],
            'code'      => $data['code'] ?: null,
            'parent_id' => $parent?->id,
            'depth'     => $depth,
            'position'  => (int) ($data['position'] ?? 0),
            'active'    => (bool) $data['active'],
            'path'      => $path,
        ]);
        $row->update(['path' => ($parent ? $parent->path : '/') . $row->id . '/']);
    }

    public function startEdit(int $id): void
    {
        $row = $this->findRow($id);
        if (! $row) return;
        $this->editing[$id] = $this->rowToArray($row);
    }

    public function cancelEdit(int $id): void { unset($this->editing[$id]); }

    public function saveEdit(int $id): void
    {
        $row = $this->findRow($id);
        if (! $row || ! isset($this->editing[$id])) return;
        $data = $this->editing[$id];
        try {
            match ($this->tab) {
                'users'    => $row->update([
                    'name'      => $data['name'] ?: $row->name,
                    'email'     => $data['email'] ?: $row->email,
                    'phone'     => $data['phone'] ?: null,
                    'job_title' => $data['job_title'] ?: null,
                    'username'  => $data['username'] ?: null,
                    'status'    => $data['status'] ?: $row->status,
                ]),
                'dich_vu', 'lam_sang', 'tu_van' => $row->update([
                    'name'          => $data['name'] ?: $row->name,
                    'code'          => $data['code'] ?: null,
                    'pricing_type'  => $data['pricing_type'] ?: 'package',
                    'package_price' => $data['package_price'] !== null && $data['package_price'] !== '' ? (int) $data['package_price'] : null,
                    'price_usd'     => $data['price_usd'] !== null && $data['price_usd'] !== '' ? (float) $data['price_usd'] : null,
                    'active'        => (bool) $data['active'],
                    'notes'         => $data['notes'] ?: null,
                ]),
                'org'      => $this->saveOrgEdit($row, $data),
                default    => null,
            };
        } catch (\Throwable $e) {
            $this->addError('row_' . $id, $e->getMessage());
            return;
        }
        unset($this->editing[$id]);
    }

    protected function saveOrgEdit(OrgUnit $row, array $data): void
    {
        $newParentId = $data['parent_id'] !== '' && $data['parent_id'] !== null ? (int) $data['parent_id'] : null;
        if ($newParentId === $row->id) throw new \Exception('Không thể đặt parent là chính nó');
        $parent = $newParentId ? OrgUnit::find($newParentId) : null;
        // Cấm chọn parent là 1 descendant của row (gây cycle).
        if ($parent && str_contains($parent->path, '/' . $row->id . '/')) {
            throw new \Exception('Không thể đặt parent là đơn vị con của chính nó');
        }
        $depth = $parent ? $parent->depth + 1 : 0;
        $path  = ($parent ? $parent->path : '/') . $row->id . '/';
        $row->update([
            'name'      => $data['name'] ?: $row->name,
            'code'      => $data['code'] ?: null,
            'parent_id' => $parent?->id,
            'depth'     => $depth,
            'position'  => (int) ($data['position'] ?? 0),
            'active'    => (bool) $data['active'],
            'path'      => $path,
        ]);
    }

    public function deleteRow(int $id): void
    {
        $row = $this->findRow($id);
        if (! $row) return;
        try {
            if ($this->tab === 'org' && OrgUnit::where('parent_id', $id)->exists()) {
                throw new \Exception('Đơn vị còn con — xóa con trước');
            }
            $row->delete();
        } catch (\Throwable $e) {
            $this->addError('row_' . $id, $e->getMessage());
        }
        unset($this->editing[$id]);
    }

    protected function findRow(int $id)
    {
        return match ($this->tab) {
            'users'    => User::find($id),
            'dich_vu', 'lam_sang', 'tu_van' => Service::find($id),
            'org'      => OrgUnit::find($id),
            default    => null,
        };
    }

    protected function rowToArray($row): array
    {
        return match ($this->tab) {
            'users'    => $row->only(['name','email','phone','job_title','username','status']),
            'dich_vu', 'lam_sang', 'tu_van' => $row->only(['code','name','pricing_type','package_price','price_usd','active','notes']),
            'org'      => $row->only(['name','code','parent_id','position','active']),
            default    => [],
        };
    }

    public function with(): array
    {
        $rows = match ($this->tab) {
            'users'    => $this->userQuery(),
            'dich_vu', 'lam_sang', 'tu_van' => $this->serviceQuery(),
            'org'      => $this->orgQuery(),
            default    => null,
        };

        return [
            'rows'           => $rows,
            'orgOptions'     => $this->tab === 'org' ? OrgUnit::orderBy('path')->get(['id','name','depth','path']) : collect(),
            'statusOptions'  => ['active' => '🟢 Active', 'inactive' => '⚪ Inactive', 'banned' => '🔴 Banned'],
            'pricingOptions' => ['package' => 'Trọn gói', 'phase' => 'Theo buổi'],
        ];
    }

    protected function userQuery()
    {
        $q = User::query()->orderByDesc('id');
        if ($this->search !== '') {
            $s = trim($this->search);
            $q->where(fn ($qq) => $qq->where('name', 'like', "%$s%")
                ->orWhere('email', 'like', "%$s%")
                ->orWhere('phone', 'like', "%$s%")
                ->orWhere('job_title', 'like', "%$s%"));
        }
        return $q->paginate(30);
    }

    protected function serviceQuery()
    {
        $q = Service::query()->where('service_type', $this->serviceTypeOfTab())->orderByDesc('id');
        if ($this->search !== '') {
            $s = trim($this->search);
            $q->where(fn ($qq) => $qq->where('name', 'like', "%$s%")->orWhere('code', 'like', "%$s%"));
        }
        return $q->paginate(30);
    }

    protected function orgQuery()
    {
        $q = OrgUnit::query()->orderBy('path');
        if ($this->search !== '') {
            $s = trim($this->search);
            $q->where(fn ($qq) => $qq->where('name', 'like', "%$s%")->orWhere('code', 'like', "%$s%"));
        }
        return $q->paginate(50);
    }
};

?>

{{-- Google-Sheets style quick editor — toàn trang. --}}
<div class="-mx-4 md:-mx-6 -my-6 md:-my-8 bg-[#f8f9fa] min-h-[calc(100vh-5rem)] flex flex-col"
     style="font-family: Arial, Roboto, 'Helvetica Neue', sans-serif;">

    @php
        $tabs = [
            'users'    => '👤 Nhân sự',
            'dich_vu'  => '💆 Dịch vụ',
            'lam_sang' => '🩺 Dịch vụ lâm sàng',
            'tu_van'   => '💬 Tư vấn',
            'org'      => '🏢 Cơ sở & Phòng ban',
        ];
        $thCls  = 'border border-gray-300 px-2 py-1 font-semibold text-left whitespace-nowrap';
        $tdCls  = 'border border-gray-300 px-2 py-0.5 whitespace-nowrap';
        $inpCls = 'w-full px-1 py-0 border-0 bg-transparent focus:outline-none focus:ring-1 focus:ring-blue-500 focus:bg-white';
    @endphp

    {{-- Top toolbar: tabs + search --}}
    <div class="flex items-center justify-between gap-3 px-3 py-1.5 border-b border-gray-300 bg-white">
        <div class="flex items-center gap-2">
            <span class="text-sm font-semibold text-gray-800">⚡ Chỉnh nhanh</span>
            <span class="text-[11px] text-gray-500">Edit inline — Enter chuyển ô, Lưu để commit.</span>
        </div>
        <div class="flex items-center gap-3 text-[12px]">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="🔍 Tìm trong tab này"
                   class="border border-gray-300 rounded px-2 py-0.5 text-[12px] w-56">
            <a href="{{ route('settings.index') }}" class="text-gray-600 hover:text-gray-900 underline">← Thiết lập</a>
        </div>
    </div>

    {{-- Sheet area --}}
    <div class="flex-1 overflow-auto bg-white">
        @if ($errors->has('draft'))
            <div class="bg-red-50 border-b border-red-200 text-red-700 text-xs px-3 py-1.5">⚠ {{ $errors->first('draft') }}</div>
        @endif

        <table class="w-full border-collapse" style="font-size: 12px;">
            @switch($tab)

            {{-- ========== TAB: NHÂN SỰ ========== --}}
            @case('users')
                <thead class="sticky top-0 z-10"><tr class="bg-[#f1f3f4] text-gray-700">
                    <th class="{{ $thCls }} w-14 text-center">ID</th>
                    <th class="{{ $thCls }}">Họ tên</th>
                    <th class="{{ $thCls }}">Email</th>
                    <th class="{{ $thCls }}">SĐT</th>
                    <th class="{{ $thCls }}">Chức danh</th>
                    <th class="{{ $thCls }}">Username</th>
                    <th class="{{ $thCls }} w-32">Trạng thái</th>
                    <th class="{{ $thCls }} w-28 text-center">Thao tác</th>
                </tr></thead>
                <tbody>
                    <tr class="bg-[#e8f0fe]" wire:key="draft-user">
                        <td class="{{ $tdCls }} text-center text-blue-600">+</td>
                        <td class="{{ $tdCls }}"><input wire:model="draft.name" placeholder="Họ tên *" class="{{ $inpCls }}"></td>
                        <td class="{{ $tdCls }}"><input wire:model="draft.email" type="email" placeholder="email@* " class="{{ $inpCls }}"></td>
                        <td class="{{ $tdCls }}"><input wire:model="draft.phone" class="{{ $inpCls }}"></td>
                        <td class="{{ $tdCls }}"><input wire:model="draft.job_title" class="{{ $inpCls }}"></td>
                        <td class="{{ $tdCls }}"><input wire:model="draft.username" class="{{ $inpCls }}"></td>
                        <td class="{{ $tdCls }}">
                            <select wire:model="draft.status" class="{{ $inpCls }}">
                                @foreach ($statusOptions as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                            </select>
                        </td>
                        <td class="{{ $tdCls }} text-center">
                            <button wire:click="addRow" class="text-[11px] font-semibold text-white bg-blue-600 hover:bg-blue-700 px-2 py-0.5 rounded">+ Thêm</button>
                        </td>
                    </tr>
                    @forelse ($rows as $u)
                        @php $isEdit = isset($editing[$u->id]); @endphp
                        <tr wire:key="user-{{ $u->id }}" class="hover:bg-[#f8f9fa]">
                            <td class="{{ $tdCls }} text-center text-gray-500">{{ $u->id }}</td>
                            @if ($isEdit)
                                <td class="{{ $tdCls }}"><input wire:model="editing.{{ $u->id }}.name" class="{{ $inpCls }}"></td>
                                <td class="{{ $tdCls }}"><input wire:model="editing.{{ $u->id }}.email" type="email" class="{{ $inpCls }}"></td>
                                <td class="{{ $tdCls }}"><input wire:model="editing.{{ $u->id }}.phone" class="{{ $inpCls }}"></td>
                                <td class="{{ $tdCls }}"><input wire:model="editing.{{ $u->id }}.job_title" class="{{ $inpCls }}"></td>
                                <td class="{{ $tdCls }}"><input wire:model="editing.{{ $u->id }}.username" class="{{ $inpCls }}"></td>
                                <td class="{{ $tdCls }}">
                                    <select wire:model="editing.{{ $u->id }}.status" class="{{ $inpCls }}">
                                        @foreach ($statusOptions as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                                    </select>
                                </td>
                                <td class="{{ $tdCls }} text-center">
                                    <button wire:click="saveEdit({{ $u->id }})" class="text-[11px] text-green-700 hover:underline">💾 Lưu</button>
                                    <button wire:click="cancelEdit({{ $u->id }})" class="text-[11px] text-gray-600 hover:underline ml-1">✕</button>
                                </td>
                            @else
                                <td class="{{ $tdCls }}">{{ $u->name }}</td>
                                <td class="{{ $tdCls }} text-blue-700">{{ $u->email }}</td>
                                <td class="{{ $tdCls }}">{{ $u->phone }}</td>
                                <td class="{{ $tdCls }}">{{ $u->job_title }}</td>
                                <td class="{{ $tdCls }} font-mono text-[11px]">{{ $u->username }}</td>
                                <td class="{{ $tdCls }}">{{ $statusOptions[$u->status] ?? $u->status }}</td>
                                <td class="{{ $tdCls }} text-center">
                                    <button wire:click="startEdit({{ $u->id }})" class="text-[11px] text-blue-700 hover:underline">Sửa</button>
                                    <button wire:click="deleteRow({{ $u->id }})" wire:confirm="Xóa user '{{ $u->name }}'? Không hoàn tác." class="text-[11px] text-red-700 hover:underline ml-1">Xóa</button>
                                </td>
                            @endif
                        </tr>
                        @if ($errors->has('row_' . $u->id))
                            <tr><td colspan="8" class="bg-red-50 text-red-700 text-xs px-3 py-1">⚠ {{ $errors->first('row_' . $u->id) }}</td></tr>
                        @endif
                    @empty
                        <tr><td colspan="8" class="border border-gray-300 p-6 text-center text-gray-400 italic">Chưa có user — nhập ở dòng xanh dương phía trên.</td></tr>
                    @endforelse
                </tbody>
                @break

            {{-- ========== TAB: SERVICE (3 variants dùng chung UI) ========== --}}
            @case('dich_vu')
            @case('lam_sang')
            @case('tu_van')
                <thead class="sticky top-0 z-10"><tr class="bg-[#f1f3f4] text-gray-700">
                    <th class="{{ $thCls }} w-14 text-center">ID</th>
                    <th class="{{ $thCls }} w-28">Mã</th>
                    <th class="{{ $thCls }}">Tên dịch vụ</th>
                    <th class="{{ $thCls }} w-32">Kiểu giá</th>
                    <th class="{{ $thCls }} w-32">Giá gói (VND)</th>
                    <th class="{{ $thCls }} w-28">Giá USD</th>
                    <th class="{{ $thCls }} w-20 text-center">Hoạt động</th>
                    <th class="{{ $thCls }}">Ghi chú</th>
                    <th class="{{ $thCls }} w-28 text-center">Thao tác</th>
                </tr></thead>
                <tbody>
                    <tr class="bg-[#e8f0fe]" wire:key="draft-svc-{{ $tab }}">
                        <td class="{{ $tdCls }} text-center text-blue-600">+</td>
                        <td class="{{ $tdCls }}"><input wire:model="draft.code" class="{{ $inpCls }}"></td>
                        <td class="{{ $tdCls }}"><input wire:model="draft.name" placeholder="Tên dịch vụ *" class="{{ $inpCls }}"></td>
                        <td class="{{ $tdCls }}">
                            <select wire:model="draft.pricing_type" class="{{ $inpCls }}">
                                @foreach ($pricingOptions as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                            </select>
                        </td>
                        <td class="{{ $tdCls }}"><input type="number" wire:model="draft.package_price" class="{{ $inpCls }} text-right"></td>
                        <td class="{{ $tdCls }}"><input type="number" step="0.01" wire:model="draft.price_usd" class="{{ $inpCls }} text-right"></td>
                        <td class="{{ $tdCls }} text-center"><input type="checkbox" wire:model="draft.active"></td>
                        <td class="{{ $tdCls }}"><input wire:model="draft.notes" class="{{ $inpCls }}"></td>
                        <td class="{{ $tdCls }} text-center">
                            <button wire:click="addRow" class="text-[11px] font-semibold text-white bg-blue-600 hover:bg-blue-700 px-2 py-0.5 rounded">+ Thêm</button>
                        </td>
                    </tr>
                    @forelse ($rows as $s)
                        @php $isEdit = isset($editing[$s->id]); @endphp
                        <tr wire:key="svc-{{ $s->id }}" class="{{ $s->active ? '' : 'bg-gray-50 text-gray-500' }} hover:bg-[#f8f9fa]">
                            <td class="{{ $tdCls }} text-center text-gray-500">{{ $s->id }}</td>
                            @if ($isEdit)
                                <td class="{{ $tdCls }}"><input wire:model="editing.{{ $s->id }}.code" class="{{ $inpCls }}"></td>
                                <td class="{{ $tdCls }}"><input wire:model="editing.{{ $s->id }}.name" class="{{ $inpCls }}"></td>
                                <td class="{{ $tdCls }}">
                                    <select wire:model="editing.{{ $s->id }}.pricing_type" class="{{ $inpCls }}">
                                        @foreach ($pricingOptions as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                                    </select>
                                </td>
                                <td class="{{ $tdCls }}"><input type="number" wire:model="editing.{{ $s->id }}.package_price" class="{{ $inpCls }} text-right"></td>
                                <td class="{{ $tdCls }}"><input type="number" step="0.01" wire:model="editing.{{ $s->id }}.price_usd" class="{{ $inpCls }} text-right"></td>
                                <td class="{{ $tdCls }} text-center"><input type="checkbox" wire:model="editing.{{ $s->id }}.active"></td>
                                <td class="{{ $tdCls }}"><input wire:model="editing.{{ $s->id }}.notes" class="{{ $inpCls }}"></td>
                                <td class="{{ $tdCls }} text-center">
                                    <button wire:click="saveEdit({{ $s->id }})" class="text-[11px] text-green-700 hover:underline">💾 Lưu</button>
                                    <button wire:click="cancelEdit({{ $s->id }})" class="text-[11px] text-gray-600 hover:underline ml-1">✕</button>
                                </td>
                            @else
                                <td class="{{ $tdCls }} font-mono text-[11px]">{{ $s->code }}</td>
                                <td class="{{ $tdCls }}">{{ $s->name }}</td>
                                <td class="{{ $tdCls }}">{{ $pricingOptions[$s->pricing_type] ?? $s->pricing_type }}</td>
                                <td class="{{ $tdCls }} text-right tabular-nums">{{ $s->package_price !== null ? number_format($s->package_price) : '—' }}</td>
                                <td class="{{ $tdCls }} text-right tabular-nums">{{ $s->price_usd !== null ? number_format($s->price_usd, 2) : '—' }}</td>
                                <td class="{{ $tdCls }} text-center">{{ $s->active ? '✓' : '—' }}</td>
                                <td class="{{ $tdCls }} text-gray-600 text-[11px]">{{ $s->notes }}</td>
                                <td class="{{ $tdCls }} text-center">
                                    <button wire:click="startEdit({{ $s->id }})" class="text-[11px] text-blue-700 hover:underline">Sửa</button>
                                    <button wire:click="deleteRow({{ $s->id }})" wire:confirm="Xóa dịch vụ '{{ $s->name }}'? Không hoàn tác." class="text-[11px] text-red-700 hover:underline ml-1">Xóa</button>
                                </td>
                            @endif
                        </tr>
                        @if ($errors->has('row_' . $s->id))
                            <tr><td colspan="9" class="bg-red-50 text-red-700 text-xs px-3 py-1">⚠ {{ $errors->first('row_' . $s->id) }}</td></tr>
                        @endif
                    @empty
                        <tr><td colspan="9" class="border border-gray-300 p-6 text-center text-gray-400 italic">Chưa có mục nào. Thêm ở dòng xanh dương phía trên.</td></tr>
                    @endforelse
                </tbody>
                @break

            {{-- ========== TAB: ORG UNIT ========== --}}
            @case('org')
                <thead class="sticky top-0 z-10"><tr class="bg-[#f1f3f4] text-gray-700">
                    <th class="{{ $thCls }} w-14 text-center">ID</th>
                    <th class="{{ $thCls }}">Tên đơn vị</th>
                    <th class="{{ $thCls }} w-32">Mã</th>
                    <th class="{{ $thCls }}">Trực thuộc (parent)</th>
                    <th class="{{ $thCls }} w-14 text-center">Depth</th>
                    <th class="{{ $thCls }} w-20 text-center">Sort</th>
                    <th class="{{ $thCls }} w-20 text-center">Hoạt động</th>
                    <th class="{{ $thCls }} w-28 text-center">Thao tác</th>
                </tr></thead>
                <tbody>
                    <tr class="bg-[#e8f0fe]" wire:key="draft-org">
                        <td class="{{ $tdCls }} text-center text-blue-600">+</td>
                        <td class="{{ $tdCls }}"><input wire:model="draft.name" placeholder="Tên đơn vị *" class="{{ $inpCls }}"></td>
                        <td class="{{ $tdCls }}"><input wire:model="draft.code" class="{{ $inpCls }} font-mono text-[11px]"></td>
                        <td class="{{ $tdCls }}">
                            <select wire:model="draft.parent_id" class="{{ $inpCls }}">
                                <option value="">— (không có parent, root) —</option>
                                @foreach ($orgOptions as $o)<option value="{{ $o->id }}">{{ str_repeat('— ', $o->depth) }}{{ $o->name }}</option>@endforeach
                            </select>
                        </td>
                        <td class="{{ $tdCls }} text-center text-gray-400">auto</td>
                        <td class="{{ $tdCls }}"><input type="number" wire:model="draft.position" class="{{ $inpCls }} text-center"></td>
                        <td class="{{ $tdCls }} text-center"><input type="checkbox" wire:model="draft.active"></td>
                        <td class="{{ $tdCls }} text-center">
                            <button wire:click="addRow" class="text-[11px] font-semibold text-white bg-blue-600 hover:bg-blue-700 px-2 py-0.5 rounded">+ Thêm</button>
                        </td>
                    </tr>
                    @forelse ($rows as $o)
                        @php $isEdit = isset($editing[$o->id]); @endphp
                        <tr wire:key="org-{{ $o->id }}" class="{{ $o->active ? '' : 'bg-gray-50 text-gray-500' }} hover:bg-[#f8f9fa]">
                            <td class="{{ $tdCls }} text-center text-gray-500">{{ $o->id }}</td>
                            @if ($isEdit)
                                <td class="{{ $tdCls }}"><input wire:model="editing.{{ $o->id }}.name" class="{{ $inpCls }}"></td>
                                <td class="{{ $tdCls }}"><input wire:model="editing.{{ $o->id }}.code" class="{{ $inpCls }} font-mono text-[11px]"></td>
                                <td class="{{ $tdCls }}">
                                    <select wire:model="editing.{{ $o->id }}.parent_id" class="{{ $inpCls }}">
                                        <option value="">— (root) —</option>
                                        @foreach ($orgOptions as $po)
                                            @if ($po->id !== $o->id)<option value="{{ $po->id }}">{{ str_repeat('— ', $po->depth) }}{{ $po->name }}</option>@endif
                                        @endforeach
                                    </select>
                                </td>
                                <td class="{{ $tdCls }} text-center text-gray-400">auto</td>
                                <td class="{{ $tdCls }}"><input type="number" wire:model="editing.{{ $o->id }}.position" class="{{ $inpCls }} text-center"></td>
                                <td class="{{ $tdCls }} text-center"><input type="checkbox" wire:model="editing.{{ $o->id }}.active"></td>
                                <td class="{{ $tdCls }} text-center">
                                    <button wire:click="saveEdit({{ $o->id }})" class="text-[11px] text-green-700 hover:underline">💾 Lưu</button>
                                    <button wire:click="cancelEdit({{ $o->id }})" class="text-[11px] text-gray-600 hover:underline ml-1">✕</button>
                                </td>
                            @else
                                <td class="{{ $tdCls }}">{{ str_repeat('— ', $o->depth) }}{{ $o->name }}</td>
                                <td class="{{ $tdCls }} font-mono text-[11px]">{{ $o->code }}</td>
                                <td class="{{ $tdCls }}">{{ $o->parent?->name ?? '— (root) —' }}</td>
                                <td class="{{ $tdCls }} text-center">{{ $o->depth }}</td>
                                <td class="{{ $tdCls }} text-center">{{ $o->position }}</td>
                                <td class="{{ $tdCls }} text-center">{{ $o->active ? '✓' : '—' }}</td>
                                <td class="{{ $tdCls }} text-center">
                                    <button wire:click="startEdit({{ $o->id }})" class="text-[11px] text-blue-700 hover:underline">Sửa</button>
                                    <button wire:click="deleteRow({{ $o->id }})" wire:confirm="Xóa đơn vị '{{ $o->name }}'? Không hoàn tác." class="text-[11px] text-red-700 hover:underline ml-1">Xóa</button>
                                </td>
                            @endif
                        </tr>
                        @if ($errors->has('row_' . $o->id))
                            <tr><td colspan="8" class="bg-red-50 text-red-700 text-xs px-3 py-1">⚠ {{ $errors->first('row_' . $o->id) }}</td></tr>
                        @endif
                    @empty
                        <tr><td colspan="8" class="border border-gray-300 p-6 text-center text-gray-400 italic">Chưa có đơn vị nào.</td></tr>
                    @endforelse
                </tbody>
                @break
            @endswitch
        </table>

        <div class="px-3 py-2 bg-white border-t border-gray-200">{{ $rows?->links() }}</div>
    </div>

    {{-- Bottom tabs — Google Sheets style --}}
    <div class="flex items-center gap-0.5 border-t border-gray-300 bg-[#f8f9fa] px-2 py-1 overflow-x-auto">
        <span class="text-[11px] text-gray-500 mr-2 shrink-0">Sheet:</span>
        @foreach ($tabs as $k => $label)
            <button type="button" wire:click="$set('tab', '{{ $k }}')"
                    class="text-[12px] px-3 py-1 rounded-t border-x border-t border-gray-300 shrink-0
                           {{ $tab === $k ? 'bg-white text-gray-900 font-semibold border-b-white -mb-px' : 'bg-[#e8eaed] text-gray-600 hover:bg-gray-200' }}">
                {{ $label }}
            </button>
        @endforeach
        <span class="ml-auto text-[11px] text-gray-500 shrink-0">
            @isset($rows){{ method_exists($rows, 'total') ? $rows->total() : $rows->count() }} dòng · @endisset
            Admin only · auto-save mỗi hành động
        </span>
    </div>
</div>
