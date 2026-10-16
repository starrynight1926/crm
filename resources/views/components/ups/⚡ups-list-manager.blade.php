<?php

use App\Models\PoolUnit;
use App\Models\User;
use App\Models\UpsListMember;
use App\Support\AdminScope;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * 2026-09-23 — Settings → Danh sách UPS List.
 * Tick tay ai được vào UPS check-in của TỪNG cơ sở (facility_pool_unit), thay heuristic
 * match tên role cũ. Chỉ super admin (AdminScope::isSuperAdmin()) được xem/sửa —
 * theo yêu cầu user, không mở rộng theo permission ops.manage/user.manage.
 */
new #[Layout('layouts.app')]
#[Title('Danh sách UPS List')]
class extends Component {
    public string $search = '';

    public function mount(): void
    {
        abort_unless(AdminScope::isSuperAdmin(), 403);
    }

    public function toggle(int $facilityPoolUnitId, int $userId): void
    {
        abort_unless(AdminScope::isSuperAdmin(), 403);

        $existing = UpsListMember::where('facility_pool_unit_id', $facilityPoolUnitId)
            ->where('user_id', $userId)
            ->first();

        if ($existing) {
            $existing->delete();
            return;
        }

        UpsListMember::create([
            'facility_pool_unit_id' => $facilityPoolUnitId,
            'user_id' => $userId,
            'added_by' => auth()->id(),
        ]);
    }

    public function with(): array
    {
        $branches = PoolUnit::where('kind', 'branch')->orderBy('sort')->get();

        $branchData = [];
        foreach ($branches as $branch) {
            $facilities = PoolUnit::where('parent_id', $branch->id)
                ->where('kind', 'facility')
                ->orderBy('sort')->get();
            if ($facilities->isEmpty()) {
                continue;
            }
            $branchData[] = ['branch' => $branch, 'facilities' => $facilities];
        }

        $users = User::where('status', User::STATUS_ACTIVE)
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%' . $this->search . '%'))
            ->orderBy('name')
            ->get();

        // [facility_id][user_id] = true
        $memberMap = [];
        foreach (UpsListMember::all() as $m) {
            $memberMap[$m->facility_pool_unit_id][$m->user_id] = true;
        }

        return [
            'branchData' => $branchData,
            'users' => $users,
            'memberMap' => $memberMap,
        ];
    }
}; ?>

<div class="max-w-6xl mx-auto px-6 py-8">
    <div class="mb-6">
        <h1 class="text-3xl font-bold mb-1">Danh sách UPS List</h1>
        <p class="text-sm text-ink/60">
            Tick người nào thì người đó mới hiện trong dropdown check-in UPS của cơ sở đó
            (<a href="{{ route('ups.list') }}" class="text-gold-700 hover:underline">/ups-list</a>).
            1 người có thể ở nhiều cơ sở (VD 207 Nguyễn Văn Thủ &amp; 137 Nguyễn Chí Thanh hay đổi người qua lại).
        </p>
    </div>

    <div class="mb-4">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Tìm theo tên nhân viên…"
               class="w-full max-w-sm border border-gold-200 rounded-md px-3 py-2 text-sm">
    </div>

    <div class="bg-white border border-gold-200 rounded-xl shadow-card overflow-x-auto">
        <table class="min-w-full text-sm border-collapse">
            <thead>
                <tr class="bg-gold-50 text-ink/70 text-xs uppercase tracking-wide">
                    <th class="border border-gold-200 px-4 py-2 text-left sticky left-0 bg-gold-50 z-10">Nhân viên</th>
                    @foreach ($branchData as $bd)
                        <th class="border border-gold-200 px-2 py-2 text-center" colspan="{{ count($bd['facilities']) }}">
                            {{ $bd['branch']->name }}
                        </th>
                    @endforeach
                </tr>
                <tr class="bg-gold-50/60 text-[11px] text-ink/60">
                    <th class="border border-gold-200 px-4 py-1.5 sticky left-0 bg-gold-50/60 z-10"></th>
                    @foreach ($branchData as $bd)
                        @foreach ($bd['facilities'] as $f)
                            <th class="border border-gold-200 px-2 py-1.5 text-center font-semibold whitespace-nowrap">{{ $f->name }}</th>
                        @endforeach
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gold-100">
                @forelse ($users as $u)
                    <tr>
                        <td class="border border-gold-200 px-4 py-2 font-semibold sticky left-0 bg-white z-10">
                            {{ $u->name }}
                            @if ($u->job_title)
                                <div class="text-xs text-ink/50 font-normal">{{ $u->job_title }}</div>
                            @endif
                        </td>
                        @foreach ($branchData as $bd)
                            @foreach ($bd['facilities'] as $f)
                                <td class="border border-gold-200 px-2 py-2 text-center">
                                    <input type="checkbox"
                                           wire:click="toggle({{ $f->id }}, {{ $u->id }})"
                                           @checked(! empty($memberMap[$f->id][$u->id]))
                                           class="w-4 h-4 accent-gold-600 cursor-pointer">
                                </td>
                            @endforeach
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ 1 + array_sum(array_map(fn ($bd) => count($bd['facilities']), $branchData)) }}"
                            class="px-4 py-6 text-center text-ink/50">Không tìm thấy nhân viên nào.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
