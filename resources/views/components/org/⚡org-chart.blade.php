<?php

use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Component;

new class extends Component
{
    public ?int $editingId = null;

    public string $editName = '';

    public ?int $addingParentId = null;

    public bool $addingRoot = false;

    public string $newName = '';

    public ?int $managingUnitId = null;

    public array $managerIds = [];

    public string $managerSearch = '';

    public function startEdit(int $id): void
    {
        $unit = OrgUnit::findOrFail($id);
        $this->editingId = $id;
        $this->editName = $unit->name;
        $this->cancelAdd();
    }

    public function saveEdit(): void
    {
        $this->validate(['editName' => 'required|string|max:100'], [], ['editName' => 'tên đơn vị']);
        OrgUnit::findOrFail($this->editingId)->update(['name' => $this->editName]);
        $this->cancelEdit();
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editName = '';
    }

    public function startAdd(?int $parentId): void
    {
        $this->addingParentId = $parentId;
        $this->addingRoot = $parentId === null;
        $this->newName = '';
        $this->cancelEdit();
    }

    public function cancelAdd(): void
    {
        $this->addingParentId = null;
        $this->addingRoot = false;
        $this->newName = '';
    }

    public function saveAdd(): void
    {
        $this->validate(['newName' => 'required|string|max:100'], [], ['newName' => 'tên đơn vị']);

        $parent = $this->addingParentId ? OrgUnit::findOrFail($this->addingParentId) : null;

        $base = Str::slug($this->newName) ?: 'unit';
        $code = $base;
        $i = 1;
        while (OrgUnit::where('code', $code)->exists()) {
            $code = $base . '-' . (++$i);
        }

        $position = OrgUnit::where('parent_id', $this->addingParentId)->max('position') + 1;
        OrgUnit::createNode(['name' => $this->newName, 'code' => $code, 'position' => $position], $parent);

        $this->cancelAdd();
    }

    public function startManagers(int $id): void
    {
        $unit = OrgUnit::with('managers:id')->findOrFail($id);
        $this->managingUnitId = $id;
        $this->managerIds = $unit->managers->pluck('id')->all();
        $this->managerSearch = '';
        $this->cancelEdit();
        $this->cancelAdd();
    }

    public function cancelManagers(): void
    {
        $this->managingUnitId = null;
        $this->managerIds = [];
        $this->managerSearch = '';
    }

    public function toggleManager(int $userId): void
    {
        if (in_array($userId, $this->managerIds, true)) {
            $this->managerIds = array_values(array_filter($this->managerIds, fn ($i) => $i !== $userId));
        } else {
            $this->managerIds[] = $userId;
        }
    }

    public function saveManagers(): void
    {
        if (! $this->managingUnitId) return;
        OrgUnit::findOrFail($this->managingUnitId)->managers()->sync($this->managerIds);
        $this->cancelManagers();
    }

    public function toggleActive(int $id): void
    {
        $unit = OrgUnit::findOrFail($id);
        $unit->update(['active' => ! $unit->active]);
    }

    public function deleteUnit(int $id): void
    {
        $unit = OrgUnit::findOrFail($id);

        if ($unit->children()->exists()) {
            session()->flash('error', 'Đơn vị còn đơn vị con, xóa/di chuyển con trước.');
            return;
        }
        if (Assignment::where('org_unit_id', $id)->exists()) {
            session()->flash('error', 'Đơn vị đang có nhân sự được gán, gỡ assignment trước.');
            return;
        }

        $unit->delete();
    }

    public function with(): array
    {
        $managerUsers = collect();
        if ($this->managingUnitId) {
            $managerUsers = User::query()
                ->where('status', User::STATUS_ACTIVE)
                ->when($this->managerSearch !== '', fn ($q) => $q->where(function ($qq) {
                    $qq->where('name', 'like', '%' . $this->managerSearch . '%')
                       ->orWhere('email', 'like', '%' . $this->managerSearch . '%');
                }))
                ->orderBy('name')
                ->limit(50)
                ->get(['id', 'name', 'email', 'job_title']);
        }

        return [
            'tree' => OrgUnit::whereNull('parent_id')
                ->with('children.children.children.children.children') // đủ sâu cho hiển thị, cây thực tế hiếm khi quá 6 cấp
                ->orderBy('position')
                ->get(),
            'memberCounts' => Assignment::query()
                ->where('active', true)
                ->selectRaw('org_unit_id, count(distinct user_id) as c')
                ->groupBy('org_unit_id')
                ->pluck('c', 'org_unit_id'),
            'managersByUnit' => \DB::table('org_unit_managers')
                ->join('users', 'users.id', '=', 'org_unit_managers.user_id')
                ->select('org_unit_managers.org_unit_id', 'users.name', 'users.job_title')
                ->orderBy('users.name')
                ->get()
                ->groupBy('org_unit_id'),
            'managerUsers' => $managerUsers,
        ];
    }
};
?>

<div>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-3xl font-bold mb-2">Sơ đồ Tổ chức</h1>
            <p class="text-sm text-ink/60 max-w-2xl">
                Cấu trúc bộ máy dạng cây, sâu tùy ý. Phạm vi dữ liệu (data scope) của từng nhân viên
                được tích theo node trong màn <a href="{{ route('org.users') }}" class="text-gold-600 underline">Quản lý nhân viên</a>.
            </p>
        </div>
        <button wire:click="startAdd(null)"
                class="bg-gold-600 hover:bg-gold-700 text-white font-semibold px-5 py-2.5 rounded-md text-sm">
            + Thêm đơn vị gốc
        </button>
    </div>

    @if (session('error'))
        <p class="mb-4 text-sm text-red-600 bg-red-50 border border-red-200 rounded-md px-4 py-2">{{ session('error') }}</p>
    @endif

    @if ($addingRoot)
        <div class="mb-4 bg-white border border-gold-300 rounded-lg p-4 flex items-center gap-3 max-w-xl">
            <input type="text" wire:model="newName" wire:keydown.enter="saveAdd" placeholder="Tên đơn vị gốc" autofocus
                   class="flex-1 border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
            <button wire:click="saveAdd" class="bg-gold-600 text-white text-sm font-semibold px-4 py-2 rounded-md">Thêm</button>
            <button wire:click="cancelAdd" class="text-sm text-ink/50 px-2">Hủy</button>
        </div>
        @error('newName')<p class="mb-4 -mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
    @endif

    <div class="space-y-3">
        @foreach ($tree as $node)
            @include('partials.org-node', ['node' => $node, 'memberCounts' => $memberCounts, 'managersByUnit' => $managersByUnit, 'managerUsers' => $managerUsers ?? collect()])
        @endforeach
    </div>

    @if ($tree->isEmpty())
        <div class="bg-white border border-gold-200 rounded-xl p-10 text-center text-ink/50">
            Chưa có đơn vị nào. Bấm "Thêm đơn vị gốc" để bắt đầu.
        </div>
    @endif
</div>
