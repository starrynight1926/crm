<?php

use App\Models\Facility;
use App\Models\OrgUnit;
use App\Models\SbService;
use App\Models\User;
use App\Support\AdminScope;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * 2026-10-05 — Quick Sheets: trang chỉnh nhanh nhiều entity cùng chỗ (Google-Sheets style).
 *   Super admin only. 5 tab:
 *     - users     : Nhân sự
 *     - dich_vu   : Dịch vụ        (sb_services la_dich_vu=1)
 *     - lam_sang  : Dịch vụ lâm sàng (sb_services la_dich_vu=0, thuoc_nhom='kham_ls')
 *     - tu_van    : Tư vấn         (sb_services la_dich_vu=0, thuoc_nhom='tu_van')
 *     - org       : Cơ sở & phòng ban (OrgUnit, dropdown parent)
 *
 * 2026-10-05 (rev2): 3 tab service đổi từ bảng `services` (local, không có co_so_id) sang
 * `sb_services` (mirror sbooking, có sbooking_co_so_id). Lọc theo AdminScope branch;
 * nếu Toàn công ty hiện thêm cột "Cơ sở". READ-ONLY: dịch vụ do sbooking làm master,
 * sửa ở sbooking rồi `php artisan sb:sync-services` để sb_services cập nhật.
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

    /** 2026-10-05: Sync từ sbooking — pull toàn bộ mirror dữ liệu (dịch vụ, bác sĩ, phòng, mapping). */
    public function syncFromSbooking(): void
    {
        abort_unless(AdminScope::isSuperAdmin(), 403);
        $results = [];
        foreach (['sb:sync-services', 'sb:sync-bac-si', 'sb:sync-dich-vu-phong', 'sb:sync-rooms'] as $cmd) {
            try {
                \Artisan::call($cmd);
                $out = trim(\Artisan::output());
                $results[] = "✓ {$cmd}: " . (\Illuminate\Support\Str::of($out)->explode("\n")->last() ?: 'done');
            } catch (\Throwable $e) {
                $results[] = "✗ {$cmd}: " . $e->getMessage();
            }
        }
        session()->flash('sync_ok', implode(' | ', $results));
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

    /** Map OrgUnit branch code → sbooking_co_so_id. Khớp Facility.booking_co_so_slug. */
    public const BRANCH_TO_SB_COSO = [
        'branch-hn'  => 1,  // CS1: 59NTN
        'branch-hcm' => 2,  // CS2: 207NVT
        'branch-dn'  => 3,  // CS3: 11-15TĐN
    ];

    /** Short label hiển thị cột "Cơ sở" trong bảng. */
    public const SB_COSO_LABELS = [
        1 => 'CS1: 59NTN',
        2 => 'CS2: 207NVT',
        3 => 'CS3: 11&15TDN',
    ];

    /** sbooking_co_so_id scope hiện tại (null = toàn công ty, int = 1 cơ sở). */
    protected function currentSbCoSoId(): ?int
    {
        $branchId = AdminScope::currentBranchId();
        if (! $branchId) return null;
        $code = OrgUnit::where('id', $branchId)->value('code');
        return self::BRANCH_TO_SB_COSO[$code] ?? null;
    }

    public function addRow(): void
    {
        try {
            match ($this->tab) {
                'users'    => $this->addUser(),
                'org'      => $this->addOrg(),
                // 3 tab service đọc từ sb_services (mirror sbooking) → read-only.
                'dich_vu', 'lam_sang', 'tu_van' => throw new \Exception('Dịch vụ do sbooking làm master — thêm/sửa ở sbooking rồi chạy sync.'),
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

    // 2026-10-05 (rev2): addService() đã bỏ — sb_services là mirror, không ghi được.

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
                'dich_vu', 'lam_sang', 'tu_van' => throw new \Exception('Dịch vụ do sbooking làm master — sửa ở sbooking rồi chạy sync.'),
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
            'org'      => OrgUnit::find($id),
            default    => null,
        };
    }

    protected function rowToArray($row): array
    {
        return match ($this->tab) {
            'users'    => $row->only(['name','email','phone','job_title','username','status']),
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
            // 2026-10-05: scope hiện tại + label để template biết có cột "Cơ sở" hay không.
            'currentSbCoSoId' => $this->currentSbCoSoId(),
            'sbCoSoLabels'    => self::SB_COSO_LABELS,
            'currentBranchName' => AdminScope::currentBranchName(),
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
        // Filter theo tab → group dịch vụ.
        $q = SbService::query();
        if ($this->tab === 'dich_vu') {
            $q->where('la_dich_vu', true);
        } elseif ($this->tab === 'lam_sang') {
            $q->where('la_dich_vu', false)->where('thuoc_nhom', 'kham_ls');
        } elseif ($this->tab === 'tu_van') {
            $q->where('la_dich_vu', false)->where('thuoc_nhom', 'tu_van');
        }

        // AdminScope branch → sbooking_co_so_id. null = toàn công ty, không filter.
        $sbCoSoId = $this->currentSbCoSoId();
        if ($sbCoSoId) $q->where('sbooking_co_so_id', $sbCoSoId);

        if ($this->search !== '') {
            $s = trim($this->search);
            $q->where('ten', 'like', "%$s%");
        }
        return $q->orderBy('sbooking_co_so_id')->orderBy('ten')->paginate(30);
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
            <button wire:click="syncFromSbooking" wire:loading.attr="disabled" wire:target="syncFromSbooking"
                    class="text-[12px] font-semibold text-white bg-emerald-600 hover:bg-emerald-700 disabled:opacity-60 px-3 py-1 rounded inline-flex items-center gap-1"
                    title="Chạy 4 lệnh: sb:sync-services, sb:sync-bac-si, sb:sync-dich-vu-phong, sb:sync-rooms">
                <span wire:loading.remove wire:target="syncFromSbooking">⚡ Sync từ sbooking</span>
                <span wire:loading wire:target="syncFromSbooking">⏳ Đang sync…</span>
            </button>
            <a href="{{ route('settings.index') }}" class="text-gray-600 hover:text-gray-900 underline">← Thiết lập</a>
        </div>
    </div>
    @if (session('sync_ok'))
        <div class="bg-emerald-50 border-b border-emerald-200 text-emerald-800 text-[11px] px-3 py-1.5">
            ✓ Sync xong: {{ session('sync_ok') }}
        </div>
    @endif

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
                                    <button wire:click="cancelEdit({{ $u->id }})" class="text-[11px] text-gray-600 hover:underline ml-1">Hủy</button>
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

            {{-- ========== TAB: SERVICE (3 variants dùng chung UI — read-only từ sb_services) ========== --}}
            @case('dich_vu')
            @case('lam_sang')
            @case('tu_van')
                @php $showCoSoCol = $currentSbCoSoId === null; $colCount = $showCoSoCol ? 7 : 6; @endphp
                <thead class="sticky top-0 z-10"><tr class="bg-[#f1f3f4] text-gray-700">
                    <th class="{{ $thCls }} w-14 text-center">ID</th>
                    @if ($showCoSoCol)
                        <th class="{{ $thCls }} w-36">Cơ sở</th>
                    @endif
                    <th class="{{ $thCls }}">Tên dịch vụ</th>
                    <th class="{{ $thCls }} w-24 text-center">Thời lượng</th>
                    <th class="{{ $thCls }} w-28">Thuộc nhóm</th>
                    <th class="{{ $thCls }} w-20 text-center">Loại</th>
                    <th class="{{ $thCls }} w-20 text-center">Hoạt động</th>
                </tr></thead>
                <tbody>
                    <tr>
                        <td colspan="{{ $colCount }}" class="bg-amber-50 border border-amber-200 text-amber-800 text-[11px] px-3 py-1.5">
                            ⚠ Dịch vụ do <b>sbooking</b> làm master — bảng này read-only mirror.
                            Sửa ở <code class="bg-white px-1 rounded">sbooking.sweetsica.com</code>, rồi chạy
                            <code class="bg-white px-1 rounded">php artisan sb:sync-services</code> để cập nhật.
                            @if ($currentBranchName)
                                · Đang lọc: <b>{{ $currentBranchName }}</b> (sbooking_co_so_id={{ $currentSbCoSoId }}).
                            @else
                                · Đang xem <b>toàn công ty</b> — chọn cơ sở ở navbar để lọc 1 CS.
                            @endif
                        </td>
                    </tr>
                    @forelse ($rows as $s)
                        <tr wire:key="svc-{{ $s->id }}" class="{{ $s->active ? '' : 'bg-gray-50 text-gray-500' }} hover:bg-[#f8f9fa]">
                            <td class="{{ $tdCls }} text-center text-gray-500">{{ $s->id }}</td>
                            @if ($showCoSoCol)
                                <td class="{{ $tdCls }} text-[11px] font-semibold">{{ $sbCoSoLabels[$s->sbooking_co_so_id] ?? ('#' . $s->sbooking_co_so_id) }}</td>
                            @endif
                            <td class="{{ $tdCls }}">{{ $s->ten }}</td>
                            <td class="{{ $tdCls }} text-center tabular-nums">{{ $s->thoi_gian_phut }}'</td>
                            <td class="{{ $tdCls }} text-[11px] uppercase">{{ $s->thuoc_nhom }}</td>
                            <td class="{{ $tdCls }} text-center">{{ $s->la_dich_vu ? '💆 DV' : '🩺 TK' }}</td>
                            <td class="{{ $tdCls }} text-center">{{ $s->active ? '✓' : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $colCount }}" class="border border-gray-300 p-6 text-center text-gray-400 italic">Không có dịch vụ nào khớp.</td></tr>
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
                                    <button wire:click="cancelEdit({{ $o->id }})" class="text-[11px] text-gray-600 hover:underline ml-1">Hủy</button>
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
