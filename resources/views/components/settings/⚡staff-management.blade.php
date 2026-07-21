<?php

use App\Models\Facility;
use App\Models\StaffMember;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithFileUploads;
use PhpOffice\PhpSpreadsheet\IOFactory;

new class extends Component
{
    use WithFileUploads;

    public string $tab = 'staff';

    // Filters cho tab Nhân sự
    public ?int $filterFacilityId = null;
    public string $search = '';

    // Form thêm/sửa staff
    public ?int $editingStaffId = null;
    public string $staffName = '';
    public string $staffTitle = '';
    public ?int $staffFacilityId = null;
    public bool $staffActive = true;

    // Form thêm/sửa facility
    public ?int $editingFacilityId = null;
    public string $facilityName = '';
    public ?int $facilityParentId = null;
    public bool $facilityActive = true;
    public string $facilityBookingSlug = '';

    // Import
    public $importFile = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermission('staff.manage'), 403);
    }

    /* ---------------- STAFF ---------------- */

    public function editStaff(int $id): void
    {
        $s = StaffMember::findOrFail($id);
        $this->editingStaffId = $s->id;
        $this->staffName = $s->name;
        $this->staffTitle = $s->title ?? '';
        $this->staffFacilityId = $s->facility_id;
        $this->staffActive = (bool) $s->active;
    }

    public function resetStaffForm(): void
    {
        $this->reset(['editingStaffId', 'staffName', 'staffTitle', 'staffFacilityId', 'staffActive']);
        $this->staffActive = true;
    }

    public function saveStaff(): void
    {
        $this->validate([
            'staffName' => 'required|string|max:255',
            'staffTitle' => 'nullable|string|max:255',
            'staffFacilityId' => 'required|exists:facilities,id',
        ]);

        StaffMember::updateOrCreate(
            ['id' => $this->editingStaffId],
            [
                'name' => trim($this->staffName),
                'title' => trim($this->staffTitle) ?: null,
                'facility_id' => $this->staffFacilityId,
                'role' => StaffMember::ROLE_DOCTOR,
                'active' => $this->staffActive,
            ]
        );

        session()->flash('status', $this->editingStaffId ? 'Đã cập nhật nhân sự.' : 'Đã thêm nhân sự.');
        $this->resetStaffForm();
    }

    public function toggleStaffActive(int $id): void
    {
        $s = StaffMember::findOrFail($id);
        $s->update(['active' => ! $s->active]);
    }

    public function deleteStaff(int $id): void
    {
        StaffMember::whereKey($id)->delete();
        session()->flash('status', 'Đã xóa nhân sự.');
    }

    /* ---------------- FACILITY ---------------- */

    public function editFacility(int $id): void
    {
        $f = Facility::findOrFail($id);
        $this->editingFacilityId = $f->id;
        $this->facilityName = $f->name;
        $this->facilityParentId = $f->parent_id;
        $this->facilityActive = (bool) $f->active;
        $this->facilityBookingSlug = (string) $f->booking_co_so_slug;
    }

    public function resetFacilityForm(): void
    {
        $this->reset(['editingFacilityId', 'facilityName', 'facilityParentId', 'facilityActive', 'facilityBookingSlug']);
        $this->facilityActive = true;
    }

    public function saveFacility(): void
    {
        $this->validate([
            'facilityName' => 'required|string|max:255',
            'facilityBookingSlug' => 'nullable|string|max:60|regex:/^[a-z0-9\-]+$/',
        ], [
            'facilityBookingSlug.regex' => 'Slug chỉ được chứa chữ thường, số và dấu gạch ngang.',
        ]);

        Facility::updateOrCreate(
            ['id' => $this->editingFacilityId],
            [
                'name' => trim($this->facilityName),
                'parent_id' => $this->facilityParentId ?: null,
                'active' => $this->facilityActive,
                'booking_co_so_slug' => trim($this->facilityBookingSlug) ?: null,
            ]
        );

        session()->flash('status', $this->editingFacilityId ? 'Đã cập nhật cơ sở.' : 'Đã thêm cơ sở.');
        $this->resetFacilityForm();
    }

    public function toggleFacilityActive(int $id): void
    {
        $f = Facility::findOrFail($id);
        $f->update(['active' => ! $f->active]);
    }

    public function deleteFacility(int $id): void
    {
        $f = Facility::findOrFail($id);
        if (StaffMember::where('facility_id', $id)->exists() || Facility::where('parent_id', $id)->exists()) {
            session()->flash('error', 'Không xóa được: cơ sở này còn nhân sự hoặc cơ sở con.');
            return;
        }
        $f->delete();
        session()->flash('status', 'Đã xóa cơ sở.');
    }

    /* ---------------- IMPORT ---------------- */

    public function import(): void
    {
        $this->validate(['importFile' => 'required|file|mimes:xlsx,xls,csv|max:5120']);

        $ss = IOFactory::load($this->importFile->getRealPath());
        $rows = $ss->getActiveSheet()->toArray(null, true, true, true);

        $added = 0;
        $updated = 0;
        foreach ($rows as $rn => $r) {
            if ($rn < 2) continue; // header ở row 1
            $facilityName = trim((string) ($r['A'] ?? ''));
            $deptName = trim((string) ($r['B'] ?? ''));
            $name = trim((string) ($r['C'] ?? ''));
            $title = trim((string) ($r['D'] ?? ''));
            $active = strtolower(trim((string) ($r['E'] ?? '1'))) === '0' ? false : true;

            if ($name === '' || $facilityName === '') continue;

            $root = Facility::firstOrCreate(['name' => $facilityName, 'parent_id' => null], ['active' => true]);
            $dept = $deptName
                ? Facility::firstOrCreate(['name' => $deptName, 'parent_id' => $root->id], ['active' => true])
                : $root;

            $existing = StaffMember::where('facility_id', $dept->id)->where('name', $name)->first();
            if ($existing) {
                $existing->update(['title' => $title ?: null, 'active' => $active]);
                $updated++;
            } else {
                StaffMember::create([
                    'facility_id' => $dept->id,
                    'name' => $name,
                    'title' => $title ?: null,
                    'role' => StaffMember::ROLE_DOCTOR,
                    'active' => $active,
                ]);
                $added++;
            }
        }

        session()->flash('status', "Import xong: thêm mới {$added}, cập nhật {$updated}.");
        $this->importFile = null;
    }

    /* ---------------- DATA ---------------- */

    public function with(): array
    {
        $facilities = Facility::with('parent')->orderBy('parent_id')->orderBy('name')->get();

        $q = StaffMember::with('facility.parent')->orderBy('name');
        if ($this->filterFacilityId) $q->where('facility_id', $this->filterFacilityId);
        if ($this->search !== '') $q->where(fn ($x) => $x->where('name', 'like', '%' . $this->search . '%')
                                                          ->orWhere('title', 'like', '%' . $this->search . '%'));

        return [
            'facilities' => $facilities,
            'facilityOptions' => $facilities->filter(fn ($f) => $f->parent_id !== null)->values(),
            'rootFacilities' => $facilities->filter(fn ($f) => $f->parent_id === null)->values(),
            'staff' => $q->paginate(50),
            'staffCount' => StaffMember::count(),
        ];
    }
};
?>

<div>
    <div class="flex items-center justify-between mb-4">
        <div>
            <div class="text-sm text-ink/50 mb-1">
                <a href="{{ route('settings.index') }}" class="hover:text-gold-600">Thiết lập</a>
                <span class="mx-1">›</span>
                <span class="text-gold-700 font-medium">Bác sĩ & Cơ sở</span>
            </div>
            <h1 class="text-3xl font-bold">Bác sĩ & Cơ sở</h1>
        </div>
    </div>

    @if (session('status'))
        <p class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-4 py-2">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p class="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-2">{{ session('error') }}</p>
    @endif

    <div class="flex gap-1 border-b border-gold-200 mb-6">
        <button wire:click="$set('tab', 'staff')"
                class="px-4 py-2 text-sm font-semibold {{ $tab === 'staff' ? 'text-gold-700 border-b-2 border-gold-600 -mb-px' : 'text-ink/60 hover:text-gold-700' }}">
            Nhân sự chuyên môn ({{ $staffCount }})
        </button>
        <button wire:click="$set('tab', 'facility')"
                class="px-4 py-2 text-sm font-semibold {{ $tab === 'facility' ? 'text-gold-700 border-b-2 border-gold-600 -mb-px' : 'text-ink/60 hover:text-gold-700' }}">
            Cơ sở ({{ $facilities->count() }})
        </button>
    </div>

    {{-- ═══ TAB NHÂN SỰ ═══ --}}
    @if ($tab === 'staff')
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- Cột trái: form add/edit --}}
            <div class="lg:col-span-1 space-y-6">
                <div class="bg-white border border-gold-200 rounded-xl shadow-card p-5">
                    <h2 class="font-bold text-gold-700 mb-4">{{ $editingStaffId ? 'Cập nhật nhân sự' : 'Thêm nhân sự' }}</h2>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-ink/60 mb-1">Tên <span class="text-red-500">*</span></label>
                            <input type="text" wire:model="staffName" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm">
                            @error('staffName')<p class="text-xs text-red-600 mt-0.5">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-ink/60 mb-1">Chức vụ</label>
                            <input type="text" wire:model="staffTitle" placeholder="VD: Bác sĩ chuyên khoa YHCT" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm">
                            @error('staffTitle')<p class="text-xs text-red-600 mt-0.5">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-ink/60 mb-1">Cơ sở <span class="text-red-500">*</span></label>
                            <select wire:model="staffFacilityId" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm bg-white">
                                <option value="">— Chọn cơ sở —</option>
                                @foreach ($facilityOptions as $f)
                                    <option value="{{ $f->id }}">{{ $f->parent?->name }} › {{ $f->name }}</option>
                                @endforeach
                            </select>
                            @error('staffFacilityId')<p class="text-xs text-red-600 mt-0.5">{{ $message }}</p>@enderror
                        </div>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="staffActive" class="rounded border-gold-300">
                            <span>Đang hoạt động</span>
                        </label>
                        <div class="flex gap-2 pt-2">
                            <button wire:click="saveStaff" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-4 py-2 rounded-md">
                                {{ $editingStaffId ? 'Lưu' : 'Thêm mới' }}
                            </button>
                            @if ($editingStaffId)
                                <button wire:click="resetStaffForm" class="text-sm text-ink/60 border border-gold-200 px-4 py-2 rounded-md hover:bg-gold-50">Hủy</button>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Import Excel --}}
                <div class="bg-white border border-gold-200 rounded-xl shadow-card p-5">
                    <h2 class="font-bold text-gold-700 mb-2">Import từ Excel</h2>
                    <p class="text-xs text-ink/50 mb-3">Cột: <strong>A</strong>=Cơ sở, <strong>B</strong>=Phòng ban, <strong>C</strong>=Tên, <strong>D</strong>=Chức vụ, <strong>E</strong>=Active (1/0). Row 1 = header.</p>
                    <input type="file" wire:model="importFile" accept=".xlsx,.xls,.csv" class="text-xs w-full mb-2">
                    @error('importFile')<p class="text-xs text-red-600 mb-2">{{ $message }}</p>@enderror
                    <button wire:click="import" wire:loading.attr="disabled" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold text-sm px-4 py-2 rounded-md disabled:opacity-50">
                        <span wire:loading.remove wire:target="import">Import</span>
                        <span wire:loading wire:target="import">Đang xử lý...</span>
                    </button>
                </div>

                {{-- Export --}}
                <div class="bg-white border border-gold-200 rounded-xl shadow-card p-5">
                    <h2 class="font-bold text-gold-700 mb-2">Export Excel</h2>
                    <p class="text-xs text-ink/50 mb-3">Dump toàn bộ nhân sự (kể cả tắt) ra file .xlsx.</p>
                    <form method="POST" action="{{ route('settings.staff.export') }}">
                        @csrf
                        <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold text-sm px-4 py-2 rounded-md">Tải xuống</button>
                    </form>
                </div>
            </div>

            {{-- Cột phải: list --}}
            <div class="lg:col-span-2 bg-white border border-gold-200 rounded-xl shadow-card p-5">
                <div class="flex flex-wrap items-center gap-3 mb-4">
                    <input type="text" wire:model.live.debounce.300ms="search" placeholder="Tìm theo tên/chức vụ..." class="border border-gold-200 rounded-md px-3 py-2 text-sm flex-1 min-w-[200px]">
                    <select wire:model.live="filterFacilityId" class="border border-gold-200 rounded-md px-3 py-2 text-sm bg-white">
                        <option value="">Tất cả cơ sở</option>
                        @foreach ($facilityOptions as $f)
                            <option value="{{ $f->id }}">{{ $f->parent?->name }} › {{ $f->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b-2 border-gold-200 text-xs uppercase tracking-wider text-ink/50">
                                <th class="text-left py-2 px-2">Tên</th>
                                <th class="text-left py-2 px-2">Chức vụ</th>
                                <th class="text-left py-2 px-2">Cơ sở</th>
                                <th class="text-center py-2 px-2">Active</th>
                                <th class="text-right py-2 px-2">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($staff as $s)
                                <tr class="border-b border-gold-100 hover:bg-gold-50/50">
                                    <td class="py-2 px-2 font-medium">{{ $s->name }}</td>
                                    <td class="py-2 px-2 text-ink/70">{{ $s->title ?: '—' }}</td>
                                    <td class="py-2 px-2 text-xs text-ink/60">{{ $s->facility?->parent?->name }} › {{ $s->facility?->name }}</td>
                                    <td class="py-2 px-2 text-center">
                                        <button wire:click="toggleStaffActive({{ $s->id }})" class="text-xs">
                                            @if ($s->active)
                                                <span class="text-green-700 font-semibold">✓ Bật</span>
                                            @else
                                                <span class="text-ink/40">✕ Tắt</span>
                                            @endif
                                        </button>
                                    </td>
                                    <td class="py-2 px-2 text-right space-x-1">
                                        <button wire:click="editStaff({{ $s->id }})" class="text-xs text-gold-700 hover:underline">Sửa</button>
                                        <button wire:click="deleteStaff({{ $s->id }})"
                                                wire:confirm="Xóa nhân sự {{ $s->name }}?"
                                                class="text-xs text-red-600 hover:underline">Xóa</button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center py-6 text-ink/40 italic">Không có nhân sự nào.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">{{ $staff->links() }}</div>
            </div>
        </div>
    @endif

    {{-- ═══ TAB CƠ SỞ ═══ --}}
    @if ($tab === 'facility')
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-1 bg-white border border-gold-200 rounded-xl shadow-card p-5">
                <h2 class="font-bold text-gold-700 mb-4">{{ $editingFacilityId ? 'Cập nhật cơ sở' : 'Thêm cơ sở' }}</h2>
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-ink/60 mb-1">Tên <span class="text-red-500">*</span></label>
                        <input type="text" wire:model="facilityName" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm">
                        @error('facilityName')<p class="text-xs text-red-600 mt-0.5">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink/60 mb-1">Cơ sở cha (nếu là dept)</label>
                        <select wire:model="facilityParentId" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm bg-white">
                            <option value="">— Không (cơ sở gốc) —</option>
                            @foreach ($rootFacilities as $f)
                                @if ($f->id !== $editingFacilityId)
                                    <option value="{{ $f->id }}">{{ $f->name }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-ink/70 mb-1">Slug cơ sở bên Booking</label>
                        <input type="text" wire:model="facilityBookingSlug" placeholder="VD: 59ntn, 207nvt"
                               class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm font-mono">
                        <p class="text-xs text-ink/50 mt-0.5">Slug URL cơ sở trong lara-sbooking. Trống = nút Đặt booking bị vô hiệu.</p>
                        @error('facilityBookingSlug')<p class="text-xs text-red-600 mt-0.5">{{ $message }}</p>@enderror
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="facilityActive" class="rounded border-gold-300">
                        <span>Đang hoạt động</span>
                    </label>
                    <div class="flex gap-2 pt-2">
                        <button wire:click="saveFacility" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-4 py-2 rounded-md">
                            {{ $editingFacilityId ? 'Lưu' : 'Thêm mới' }}
                        </button>
                        @if ($editingFacilityId)
                            <button wire:click="resetFacilityForm" class="text-sm text-ink/60 border border-gold-200 px-4 py-2 rounded-md hover:bg-gold-50">Hủy</button>
                        @endif
                    </div>
                </div>
            </div>

            <div class="lg:col-span-2 bg-white border border-gold-200 rounded-xl shadow-card p-5">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b-2 border-gold-200 text-xs uppercase tracking-wider text-ink/50">
                            <th class="text-left py-2 px-2">Tên</th>
                            <th class="text-left py-2 px-2">Cha</th>
                            <th class="text-center py-2 px-2">Active</th>
                            <th class="text-right py-2 px-2">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($facilities as $f)
                            <tr class="border-b border-gold-100 hover:bg-gold-50/50">
                                <td class="py-2 px-2 font-medium {{ $f->parent_id ? 'pl-6' : '' }}">
                                    {{ $f->parent_id ? '↳ ' : '' }}{{ $f->name }}
                                </td>
                                <td class="py-2 px-2 text-ink/60">{{ $f->parent?->name ?? '—' }}</td>
                                <td class="py-2 px-2 text-center">
                                    <button wire:click="toggleFacilityActive({{ $f->id }})" class="text-xs">
                                        @if ($f->active)
                                            <span class="text-green-700 font-semibold">✓ Bật</span>
                                        @else
                                            <span class="text-ink/40">✕ Tắt</span>
                                        @endif
                                    </button>
                                </td>
                                <td class="py-2 px-2 text-right space-x-1">
                                    <button wire:click="editFacility({{ $f->id }})" class="text-xs text-gold-700 hover:underline">Sửa</button>
                                    <button wire:click="deleteFacility({{ $f->id }})"
                                            wire:confirm="Xóa cơ sở {{ $f->name }}?"
                                            class="text-xs text-red-600 hover:underline">Xóa</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center py-6 text-ink/40 italic">Chưa có cơ sở.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
