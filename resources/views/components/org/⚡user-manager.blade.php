<?php

use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $filterOrgUnit = '';

    public string $filterRole = '';

    public string $viewMode = 'tree'; // tree | list

    // ----- Modal user -----
    public bool $showUserModal = false;

    public ?int $editingUserId = null;

    public string $uname = '';

    public string $uemail = '';

    public string $uphone = '';

    public string $upassword = '';

    public string $ujobTitle = '';

    public string $ustatus = User::STATUS_ACTIVE;

    // ----- Modal assignment -----
    public bool $showAssignModal = false;

    public ?int $assignUserId = null;

    public string $aRoleId = '';

    public string $aOrgUnitId = '';

    public string $aScope = Assignment::SCOPE_SELF;

    /** @var array<int, bool> org_unit_id => checked (khi scope = custom) */
    public array $aScopeNodes = [];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilterOrgUnit(): void
    {
        $this->resetPage();
    }

    public function updatedFilterRole(): void
    {
        $this->resetPage();
    }

    // ---------- User CRUD ----------

    public function openCreateUser(): void
    {
        $this->reset('editingUserId', 'uname', 'uemail', 'uphone', 'upassword', 'ujobTitle');
        $this->ustatus = User::STATUS_ACTIVE;
        $this->resetErrorBag();
        $this->showUserModal = true;
    }

    public function openEditUser(int $userId): void
    {
        $user = User::findOrFail($userId);
        $this->editingUserId = $user->id;
        $this->uname = $user->name;
        $this->uemail = $user->email;
        $this->uphone = $user->phone ?? '';
        $this->upassword = '';
        $this->ujobTitle = $user->job_title ?? '';
        $this->ustatus = $user->status;
        $this->resetErrorBag();
        $this->showUserModal = true;
    }

    public function saveUser(): void
    {
        $data = $this->validate([
            'uname' => 'required|string|max:100',
            'uemail' => ['required', 'email', Rule::unique('users', 'email')->ignore($this->editingUserId)],
            'uphone' => 'nullable|string|max:20',
            'upassword' => $this->editingUserId ? 'nullable|string|min:8' : 'required|string|min:8',
            'ujobTitle' => 'nullable|string|max:100',
            'ustatus' => 'required|in:active,locked',
        ], [], [
            'uname' => 'họ tên', 'uemail' => 'email', 'uphone' => 'SĐT', 'upassword' => 'mật khẩu', 'ujobTitle' => 'chức danh',
        ]);

        $attributes = [
            'name' => $data['uname'],
            'email' => $data['uemail'],
            'phone' => $data['uphone'] ?: null,
            'job_title' => $data['ujobTitle'] ?: null,
            'status' => $data['ustatus'],
        ];
        if ($data['upassword']) {
            $attributes['password'] = $data['upassword'];
        }

        $this->editingUserId
            ? User::findOrFail($this->editingUserId)->update($attributes)
            : User::create($attributes);

        $this->showUserModal = false;
    }

    public function toggleLock(int $userId): void
    {
        if ($userId === auth()->id()) {
            session()->flash('error', 'Không thể tự khóa tài khoản của chính mình.');
            return;
        }
        $user = User::findOrFail($userId);
        $user->update(['status' => $user->isLocked() ? User::STATUS_ACTIVE : User::STATUS_LOCKED]);
    }

    // ---------- Assignment ----------

    public function openAssignments(int $userId): void
    {
        $this->assignUserId = $userId;
        $this->resetAssignmentForm();
        $this->resetErrorBag();
        $this->showAssignModal = true;
    }

    public function resetAssignmentForm(): void
    {
        $this->aRoleId = '';
        $this->aOrgUnitId = '';
        $this->aScope = Assignment::SCOPE_SELF;
        $this->aScopeNodes = [];
    }

    public function addAssignment(): void
    {
        $this->validate([
            'aRoleId' => 'required|exists:roles,id',
            'aOrgUnitId' => 'required|exists:org_units,id',
            'aScope' => 'required|in:self,team,custom',
        ], [], ['aRoleId' => 'vai trò', 'aOrgUnitId' => 'đơn vị', 'aScope' => 'phạm vi dữ liệu']);

        $checkedNodes = array_keys(array_filter($this->aScopeNodes));
        if ($this->aScope === Assignment::SCOPE_CUSTOM && $checkedNodes === []) {
            $this->addError('aScopeNodes', 'Chọn ít nhất một node trên cây tổ chức.');
            return;
        }

        $exists = Assignment::where('user_id', $this->assignUserId)
            ->where('role_id', $this->aRoleId)
            ->where('org_unit_id', $this->aOrgUnitId)
            ->exists();
        if ($exists) {
            $this->addError('aRoleId', 'Nhân viên đã có assignment với vai trò này tại đơn vị này.');
            return;
        }

        $assignment = Assignment::create([
            'user_id' => $this->assignUserId,
            'role_id' => $this->aRoleId,
            'org_unit_id' => $this->aOrgUnitId,
            'data_scope' => $this->aScope,
        ]);

        if ($this->aScope === Assignment::SCOPE_CUSTOM) {
            $assignment->scopeNodes()->sync($checkedNodes);
        }

        $this->resetAssignmentForm();
    }

    public function toggleAssignment(int $assignmentId): void
    {
        $assignment = Assignment::where('user_id', $this->assignUserId)->findOrFail($assignmentId);
        $assignment->update(['active' => ! $assignment->active]);
    }

    public function removeAssignment(int $assignmentId): void
    {
        Assignment::where('user_id', $this->assignUserId)->findOrFail($assignmentId)->delete();
    }

    // ---------- Data ----------

    public function with(): array
    {
        $users = User::query()
            ->with(['assignments' => fn ($q) => $q->with('role', 'orgUnit')])
            ->when($this->search, fn ($q) => $q->where(fn ($qq) => $qq
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")))
            ->when($this->filterOrgUnit, function ($q) {
                $subtree = OrgUnit::find($this->filterOrgUnit)?->subtreeIds() ?? [];
                $q->whereHas('assignments', fn ($qq) => $qq->whereIn('org_unit_id', $subtree));
            })
            ->when($this->filterRole, fn ($q) => $q->whereHas('assignments', fn ($qq) => $qq->where('role_id', $this->filterRole)))
            ->orderBy('name')
            ->paginate(10);

        $allOrgUnits = OrgUnit::orderBy('path')->get();
        $allRoles = Role::orderBy('name')->get();

        $orgTree = [];
        if ($this->viewMode === 'tree') {
            $orgTree = $allOrgUnits->load(['children']);
            $allAssignments = \App\Models\Assignment::with(['user', 'role'])
                ->whereHas('user', fn ($q) => $q->where('status', User::STATUS_ACTIVE))
                ->where('active', true)
                ->get()
                ->groupBy('org_unit_id');

            $managersByUnit = \DB::table('org_unit_managers')
                ->join('users', 'users.id', '=', 'org_unit_managers.user_id')
                ->select('org_unit_managers.org_unit_id', 'users.id as user_id', 'users.name as user_name', 'users.job_title')
                ->orderBy('users.name')
                ->get()
                ->groupBy('org_unit_id');

            $orgTree = $allOrgUnits->map(function ($unit) use ($allAssignments, $managersByUnit) {
                $members = ($allAssignments[$unit->id] ?? collect())->map(fn ($a) => [
                    'user_id' => $a->user_id,
                    'user_name' => $a->user->name,
                    'user_email' => $a->user->email,
                    'job_title' => $a->user->job_title,
                    'role' => $a->role->name,
                    'scope' => $a->data_scope,
                    'locked' => $a->user->isLocked(),
                ])->unique('user_id')->values()->all();

                return [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'depth' => $unit->depth,
                    'parent_id' => $unit->parent_id,
                    'active' => $unit->active,
                    'members' => $members,
                    'managers' => ($managersByUnit[$unit->id] ?? collect())->map(fn ($m) => [
                        'user_id' => $m->user_id,
                        'user_name' => $m->user_name,
                        'job_title' => $m->job_title,
                    ])->values()->all(),
                ];
            })->all();
        }

        $unassigned = [];
        if ($this->viewMode === 'tree') {
            $assignedUserIds = \App\Models\Assignment::where('active', true)->pluck('user_id')->unique();
            $unassigned = User::where('status', User::STATUS_ACTIVE)
                ->whereNotIn('id', $assignedUserIds)
                ->orderBy('name')
                ->get(['id', 'name', 'email']);
        }

        return [
            'users' => $users,
            'allRoles' => $allRoles,
            'allOrgUnits' => $allOrgUnits,
            'assignUser' => $this->assignUserId ? User::with(['assignments.role', 'assignments.orgUnit', 'assignments.scopeNodes'])->find($this->assignUserId) : null,
            'orgTree' => $orgTree,
            'unassigned' => $unassigned,
            'stats' => [
                'total' => User::count(),
                'active' => User::where('status', User::STATUS_ACTIVE)->count(),
                'locked' => User::where('status', User::STATUS_LOCKED)->count(),
            ],
        ];
    }
};
?>

<div>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-3xl font-bold mb-1">Quản lý nhân viên</h1>
            <p class="text-sm text-ink/60">Điều hành và phân quyền cho đội ngũ nhân sự của bạn.</p>
        </div>
        <button wire:click="openCreateUser"
                class="bg-gold-600 hover:bg-gold-700 text-white font-semibold px-5 py-2.5 rounded-md text-sm">
            + Thêm nhân viên
        </button>
    </div>

    @if (session('error'))
        <p class="mb-4 text-sm text-red-600 bg-red-50 border border-red-200 rounded-md px-4 py-2">{{ session('error') }}</p>
    @endif

    {{-- View mode tabs --}}
    <div class="border-b border-gold-200 mb-5 flex gap-1 text-sm font-semibold uppercase tracking-wide">
        <button wire:click="$set('viewMode', 'tree')" class="px-4 py-3 border-b-2 -mb-px {{ $viewMode === 'tree' ? 'border-gold-600 text-gold-700' : 'border-transparent text-ink/50 hover:text-gold-700' }}">Sơ đồ tổ chức</button>
        <button wire:click="$set('viewMode', 'list')" class="px-4 py-3 border-b-2 -mb-px {{ $viewMode === 'list' ? 'border-gold-600 text-gold-700' : 'border-transparent text-ink/50 hover:text-gold-700' }}">Danh sách</button>
    </div>

    @if ($viewMode === 'tree')
        {{-- Tree view --}}
        <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6" x-data="{ collapsed: {} }">
            @php
                $roots = collect($orgTree)->where('parent_id', null);
                $byParent = collect($orgTree)->groupBy('parent_id');
            @endphp

            @foreach ($roots as $root)
                @include('components.org._org-tree-node', ['node' => $root, 'byParent' => $byParent])
            @endforeach

            @if (count($unassigned))
                <div class="mt-6 pt-4 border-t border-gold-100">
                    <h3 class="text-sm font-bold text-ink/50 uppercase tracking-wider mb-3">Chưa gán đơn vị</h3>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($unassigned as $u)
                            <span class="inline-flex items-center gap-2 text-sm bg-gray-50 border border-gray-200 text-gray-600 px-3 py-1.5 rounded-lg">
                                <span class="w-7 h-7 rounded bg-gray-200 text-gray-500 text-xs font-bold flex items-center justify-center">{{ mb_substr($u->name, 0, 1) }}</span>
                                {{ $u->name }}
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @else

    <div class="bg-white border border-gold-200 rounded-xl shadow-card">
        {{-- Filter bar --}}
        <div class="px-5 py-4 border-b border-gold-100 flex flex-wrap items-center gap-3">
            <select wire:model.live="filterOrgUnit" class="border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                <option value="">Tất cả đơn vị</option>
                @foreach ($allOrgUnits as $unit)
                    <option value="{{ $unit->id }}">{{ str_repeat('— ', $unit->depth) }}{{ $unit->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="filterRole" class="border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                <option value="">Tất cả vai trò</option>
                @foreach ($allRoles as $role)
                    <option value="{{ $role->id }}">{{ $role->name }}</option>
                @endforeach
            </select>
            <div class="flex-1"></div>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Tìm kiếm nhân viên..."
                   class="border border-gold-200 rounded-md px-3 py-2 text-sm w-64 focus:outline-none focus:border-gold-500">
        </div>

        {{-- Table --}}
        <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[640px]">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wider text-ink/50 bg-gold-50/60">
                    <th class="px-5 py-3 font-semibold">Nhân viên</th>
                    <th class="px-5 py-3 font-semibold">Chức danh</th>
                    <th class="px-5 py-3 font-semibold">Vai trò @ Đơn vị</th>
                    <th class="px-5 py-3 font-semibold">Trạng thái</th>
                    <th class="px-5 py-3 font-semibold text-right">Hành động</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gold-100">
                @forelse ($users as $user)
                    <tr class="hover:bg-gold-50/40">
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-3">
                                <span class="w-10 h-10 rounded-md bg-gold-100 text-gold-700 font-bold flex items-center justify-center">{{ $user->initials() }}</span>
                                <div>
                                    <div class="font-semibold">{{ $user->name }}</div>
                                    <div class="text-xs text-ink/50">{{ $user->email }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-5 py-4 text-sm text-ink/70">{{ $user->job_title ?: '—' }}</td>
                        <td class="px-5 py-4">
                            <div class="flex flex-wrap gap-1.5">
                                @forelse ($user->assignments as $a)
                                    <span class="inline-flex items-center gap-1 text-xs {{ $a->active ? 'bg-gold-50 border-gold-200 text-gold-800' : 'bg-gray-50 border-gray-200 text-gray-400 line-through' }} border px-2 py-1 rounded">
                                        <strong>{{ $a->role->name }}</strong> @ {{ $a->orgUnit->name }}
                                        <span class="text-[10px] uppercase opacity-60">({{ ['self' => 'bản thân', 'team' => 'team', 'custom' => 'tùy chọn'][$a->data_scope] }})</span>
                                    </span>
                                @empty
                                    <span class="text-xs text-ink/30 italic">Chưa gán vai trò</span>
                                @endforelse
                            </div>
                        </td>
                        <td class="px-5 py-4">
                            @if ($user->isLocked())
                                <span class="inline-flex items-center gap-1.5 text-xs font-medium text-red-700"><span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>Đã khóa</span>
                            @else
                                <span class="inline-flex items-center gap-1.5 text-xs font-medium text-green-700"><span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>Đang làm việc</span>
                            @endif
                        </td>
                        <td class="px-5 py-4 text-right whitespace-nowrap">
                            <button wire:click="openAssignments({{ $user->id }})" class="text-xs font-semibold text-gold-700 border border-gold-300 hover:bg-gold-50 px-3 py-1.5 rounded-md">Phân quyền</button>
                            <button wire:click="openEditUser({{ $user->id }})" class="text-xs font-semibold text-ink/60 border border-gold-200 hover:bg-gold-50 px-3 py-1.5 rounded-md">Sửa</button>
                            <button wire:click="toggleLock({{ $user->id }})" wire:confirm="{{ $user->isLocked() ? 'Mở khóa' : 'Khóa' }} tài khoản {{ $user->name }}?"
                                    class="text-xs font-semibold {{ $user->isLocked() ? 'text-green-700 border-green-200 hover:bg-green-50' : 'text-red-700 border-red-200 hover:bg-red-50' }} border px-3 py-1.5 rounded-md">
                                {{ $user->isLocked() ? 'Mở khóa' : 'Khóa' }}
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-5 py-10 text-center text-ink/40">Không tìm thấy nhân viên nào.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>

        <div class="px-5 py-4 border-t border-gold-100 flex flex-wrap gap-2 items-center justify-between text-sm text-ink/60">
            <span>Hiển thị {{ $users->count() }} trong tổng số {{ $users->total() }} nhân viên</span>
            {{ $users->links() }}
        </div>
    </div>

    {{-- Stats --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-6">
        <div class="bg-white border border-gold-200 rounded-xl shadow-card p-5">
            <div class="text-xs font-semibold uppercase tracking-widest text-ink/50 mb-2">Tổng nhân viên</div>
            <div class="text-3xl font-extrabold">{{ $stats['total'] }}</div>
        </div>
        <div class="bg-white border border-gold-200 rounded-xl shadow-card p-5">
            <div class="text-xs font-semibold uppercase tracking-widest text-ink/50 mb-2">Đang làm việc</div>
            <div class="text-3xl font-extrabold text-green-700">{{ $stats['active'] }}</div>
        </div>
        <div class="bg-white border border-gold-200 rounded-xl shadow-card p-5">
            <div class="text-xs font-semibold uppercase tracking-widest text-ink/50 mb-2">Đã khóa</div>
            <div class="text-3xl font-extrabold text-red-700">{{ $stats['locked'] }}</div>
        </div>
    </div>

    @endif {{-- end viewMode list/tree --}}

    {{-- Modal: user form --}}
    @if ($showUserModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/40" wire:click="$set('showUserModal', false)"></div>
            <div class="relative bg-white rounded-xl shadow-xl border border-gold-200 w-full max-w-lg p-7">
                <h3 class="text-xl font-bold mb-5">{{ $editingUserId ? 'Cập nhật nhân viên' : 'Thêm nhân viên' }}</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">Họ tên</label>
                        <input type="text" wire:model="uname" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                        @error('uname')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">Email</label>
                            <input type="email" wire:model="uemail" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                            @error('uemail')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">SĐT</label>
                            <input type="text" wire:model="uphone" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">Chức danh</label>
                        <input type="text" wire:model="ujobTitle" placeholder="VD: Clinic Manager, Team Leader, SHC, HC..." class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                        @error('ujobTitle')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">
                                Mật khẩu {{ $editingUserId ? '(bỏ trống nếu giữ nguyên)' : '' }}
                            </label>
                            <input type="password" wire:model="upassword" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                            @error('upassword')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">Trạng thái</label>
                            <select wire:model="ustatus" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                                <option value="active">Đang làm việc</option>
                                <option value="locked">Khóa</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-3 mt-7">
                    <button wire:click="$set('showUserModal', false)" class="text-sm text-ink/60 px-4 py-2">Hủy</button>
                    <button wire:click="saveUser" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-6 py-2 rounded-md">Lưu</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal: assignments --}}
    @if ($showAssignModal && $assignUser)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/40" wire:click="$set('showAssignModal', false)"></div>
            <div class="relative bg-white rounded-xl shadow-xl border border-gold-200 w-full max-w-2xl p-7 max-h-[90vh] overflow-y-auto">
                <h3 class="text-xl font-bold mb-1">Phân quyền: {{ $assignUser->name }}</h3>
                <p class="text-sm text-ink/50 mb-5">Mỗi assignment = vai trò + đơn vị + phạm vi dữ liệu. Một người có thể giữ nhiều vai trò ở nhiều đơn vị.</p>

                {{-- Assignment hiện có --}}
                <div class="space-y-2 mb-6">
                    @forelse ($assignUser->assignments as $a)
                        <div class="border {{ $a->active ? 'border-gold-200 bg-gold-50/50' : 'border-gray-200 bg-gray-50 opacity-70' }} rounded-lg px-4 py-3 flex items-center gap-3">
                            <div class="flex-1 text-sm">
                                <strong>{{ $a->role->name }}</strong> @ {{ $a->orgUnit->name }}
                                <span class="text-xs text-ink/50">
                                    · scope: {{ ['self' => 'Chỉ dữ liệu bản thân', 'team' => 'Dữ liệu team (cả node con)', 'custom' => 'Tùy chọn'][$a->data_scope] }}
                                </span>
                                @if ($a->data_scope === 'custom')
                                    <div class="text-xs text-ink/50 mt-1">
                                        Node: {{ $a->scopeNodes->pluck('name')->join(', ') ?: '—' }}
                                    </div>
                                @endif
                            </div>
                            <button wire:click="toggleAssignment({{ $a->id }})" class="text-xs font-semibold {{ $a->active ? 'text-ink/50' : 'text-green-700' }} border border-gold-200 px-3 py-1.5 rounded-md hover:bg-gold-50">
                                {{ $a->active ? 'Tạm ngưng' : 'Kích hoạt' }}
                            </button>
                            <button wire:click="removeAssignment({{ $a->id }})" wire:confirm="Gỡ assignment này?" class="text-xs font-semibold text-red-700 border border-red-200 px-3 py-1.5 rounded-md hover:bg-red-50">Gỡ</button>
                        </div>
                    @empty
                        <p class="text-sm text-ink/40 italic">Chưa có assignment nào.</p>
                    @endforelse
                </div>

                {{-- Thêm assignment --}}
                <div class="border-t border-gold-100 pt-5">
                    <h4 class="font-bold mb-3">Thêm assignment</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">Vai trò</label>
                            <select wire:model="aRoleId" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                                <option value="">— chọn —</option>
                                @foreach ($allRoles as $role)
                                    <option value="{{ $role->id }}">{{ $role->name }}</option>
                                @endforeach
                            </select>
                            @error('aRoleId')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">Đơn vị</label>
                            <select wire:model="aOrgUnitId" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                                <option value="">— chọn —</option>
                                @foreach ($allOrgUnits as $unit)
                                    <option value="{{ $unit->id }}">{{ str_repeat('— ', $unit->depth) }}{{ $unit->name }}</option>
                                @endforeach
                            </select>
                            @error('aOrgUnitId')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">Phạm vi dữ liệu</label>
                            <select wire:model.live="aScope" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                                <option value="self">Chỉ dữ liệu bản thân</option>
                                <option value="team">Dữ liệu team (cả node con)</option>
                                <option value="custom">Chọn phòng ban cụ thể</option>
                            </select>
                        </div>
                    </div>

                    @if ($aScope === 'custom')
                        <div class="border border-gold-200 rounded-lg p-4 mb-3 max-h-56 overflow-y-auto">
                            <p class="text-xs text-ink/50 mb-2">Tích node nào thì thấy dữ liệu node đó <strong>và toàn bộ node con</strong>:</p>
                            @foreach ($allOrgUnits as $unit)
                                <label class="flex items-center gap-2.5 text-sm py-1 cursor-pointer" style="padding-left: {{ $unit->depth * 24 }}px">
                                    <input type="checkbox" wire:model="aScopeNodes.{{ $unit->id }}"
                                           class="rounded border-gold-300 text-gold-600 focus:ring-gold-500 w-4 h-4">
                                    {{ $unit->name }}
                                </label>
                            @endforeach
                        </div>
                        @error('aScopeNodes')<p class="text-xs text-red-600 mb-2">{{ $message }}</p>@enderror
                    @endif

                </div>

                <div class="flex justify-end items-center gap-3 mt-6 pt-5 border-t border-gold-100">
                    <button wire:click="$set('showAssignModal', false)" class="text-sm font-semibold text-ink/60 border border-gold-200 px-5 py-2 rounded-md hover:bg-gold-50">Đóng</button>
                    <button wire:click="addAssignment" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-6 py-2 rounded-md">Lưu phân quyền</button>
                </div>
            </div>
        </div>
    @endif
</div>
