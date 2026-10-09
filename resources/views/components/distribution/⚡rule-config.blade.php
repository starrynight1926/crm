<?php

use App\Models\DistributionRule;
use App\Models\OrgUnit;
use App\Models\SlaPolicy;
use App\Models\User;
use Livewire\Component;

new class extends Component
{
    // ----- Form rule -----
    public bool $showModal = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $level = DistributionRule::LEVEL_POOL_TO_TEAM;

    public string $poolUnitId = ''; // Phase 6.24 — thay cho $orgUnitId cũ

    // 2026-08-03: cascade select cho Kho áp dụng.
    // Cấp: company (readonly Longevity) → branch (Địa điểm) → facility (Cơ sở) → department (Phòng ban).
    // poolUnitId = cấp thấp nhất được chọn. Được phép dừng ở branch (kho về "Địa điểm") theo yêu cầu.
    public string $poolBranchId = '';
    public string $poolFacilityId = '';
    public string $poolDepartmentId = '';

    public int $priority = 10;

    public string $strategy = 'round_robin';

    public string $condRegion = '';

    public string $condCamp = '';

    public string $condPage = '';

    /** @var array<int, array{type: string, id: string, weight: string}> */
    public array $targets = [];

    // ----- SLA -----
    public string $slaMode = 'off';

    public int $slaHours = 24;

    public string $slaRecallTo = 'team';

    public function mount(): void
    {
        $default = SlaPolicy::whereNull('org_unit_id')->first();
        if ($default) {
            $this->slaMode = $default->mode;
            $this->slaHours = $default->recall_after_hours;
            $this->slaRecallTo = $default->recall_to;
        }
    }

    public function openCreate(string $level): void
    {
        $this->reset('editingId', 'name', 'poolUnitId', 'poolBranchId', 'poolFacilityId', 'poolDepartmentId', 'condRegion', 'condCamp', 'condPage');
        $this->level = $level;
        $this->priority = ((int) DistributionRule::where('level', $level)->max('priority')) + 10;
        $this->strategy = 'round_robin';
        $this->targets = [['type' => $level === DistributionRule::LEVEL_POOL_TO_TEAM ? 'org_unit' : 'user', 'id' => '', 'weight' => '1']];
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $rule = DistributionRule::with('targets')->findOrFail($id);
        $this->editingId = $rule->id;
        $this->name = $rule->name;
        $this->level = $rule->level;
        $this->poolUnitId = (string) ($rule->pool_unit_id ?? '');
        // 2026-08-03: walk parent chain để populate 3 cascade selects.
        $this->poolBranchId = $this->poolFacilityId = $this->poolDepartmentId = '';
        if ($rule->pool_unit_id) {
            $node = \App\Models\PoolUnit::find($rule->pool_unit_id);
            while ($node) {
                if ($node->kind === 'department') $this->poolDepartmentId = (string) $node->id;
                if ($node->kind === 'facility')   $this->poolFacilityId   = (string) $node->id;
                if ($node->kind === 'branch')     $this->poolBranchId     = (string) $node->id;
                $node = $node->parent_id ? \App\Models\PoolUnit::find($node->parent_id) : null;
            }
        }
        $this->priority = $rule->priority;
        $this->strategy = $rule->strategy;
        $conditions = $rule->conditions ?? [];
        $this->condRegion = implode(', ', $conditions['region'] ?? []);
        $this->condCamp = implode(', ', $conditions['camp'] ?? []);
        $this->condPage = implode(', ', $conditions['page'] ?? []);
        $this->targets = $rule->targets->map(fn ($t) => [
            'type' => $t->target_type, 'id' => (string) $t->target_id, 'weight' => (string) $t->weight,
        ])->all();
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function addTarget(): void
    {
        $this->targets[] = [
            'type' => $this->level === DistributionRule::LEVEL_POOL_TO_TEAM ? 'pool_unit' : 'user',
            'id' => '', 'weight' => '1',
        ];
    }

    public function removeTarget(int $index): void
    {
        unset($this->targets[$index]);
        $this->targets = array_values($this->targets);
    }

    public function save(): void
    {
        // 2026-08-03 cascade: poolUnitId = cấp thấp nhất được chọn (dept > facility > branch).
        // Cho phép dừng ở branch (kho về "Địa điểm") hoặc facility (kho về "Cơ sở").
        if ($this->level === DistributionRule::LEVEL_TEAM_TO_USER) {
            $this->poolUnitId = $this->poolDepartmentId ?: ($this->poolFacilityId ?: $this->poolBranchId);
        }

        $this->validate([
            'name' => 'required|string|max:100',
            'priority' => 'required|integer|min:0',
            'strategy' => 'required|in:round_robin,weighted,top_revenue,top_close_rate',
            'poolUnitId' => $this->level === DistributionRule::LEVEL_TEAM_TO_USER ? 'required|exists:pool_units,id' : 'nullable',
        ], ['poolUnitId.required' => 'Chọn ít nhất tới Địa điểm cho kho áp dụng.'], ['name' => 'tên rule', 'poolUnitId' => 'kho áp dụng']);

        $targets = array_values(array_filter($this->targets, fn ($t) => $t['id'] !== ''));
        if ($targets === []) {
            $this->addError('targets', 'Cần ít nhất một đích chia.');
            return;
        }
        if (count(array_unique(array_column($targets, 'id'))) !== count($targets)) {
            $this->addError('targets', 'Đích chia bị lặp.');
            return;
        }

        $parse = fn (string $s) => array_values(array_filter(array_map('trim', explode(',', $s))));
        $conditions = array_filter([
            'region' => $parse($this->condRegion),
            'camp' => $parse($this->condCamp),
            'page' => $parse($this->condPage),
        ]);

        $attributes = [
            'name' => $this->name,
            'level' => $this->level,
            'pool_unit_id' => $this->level === DistributionRule::LEVEL_TEAM_TO_USER ? (int) $this->poolUnitId : null,
            'org_unit_id' => null, // Phase 6.24 — legacy column, không dùng nữa
            'priority' => $this->priority,
            'strategy' => $this->strategy,
            'conditions' => $conditions ?: null,
        ];

        $rule = $this->editingId
            ? tap(DistributionRule::findOrFail($this->editingId))->update($attributes)
            : DistributionRule::create($attributes);

        $rule->targets()->delete();
        foreach ($targets as $i => $t) {
            $rule->targets()->create([
                'target_type' => $t['type'],
                'target_id' => (int) $t['id'],
                'weight' => max(1, (int) $t['weight']),
                'position' => $i,
            ]);
        }

        $this->showModal = false;
    }

    public function toggleActive(int $id): void
    {
        $rule = DistributionRule::findOrFail($id);
        $rule->update(['active' => ! $rule->active]);
    }

    public function deleteRule(int $id): void
    {
        DistributionRule::findOrFail($id)->delete();
    }

    public function saveSla(): void
    {
        $this->validate([
            'slaMode' => 'required|in:auto,manual,off',
            'slaHours' => 'required|integer|min:1|max:720',
            'slaRecallTo' => 'required|in:common,team',
        ]);

        SlaPolicy::updateOrCreate(
            ['org_unit_id' => null],
            ['mode' => $this->slaMode, 'recall_after_hours' => $this->slaHours, 'recall_to' => $this->slaRecallTo]
        );

        session()->flash('sla_status', 'Đã lưu chính sách thu hồi.');
    }

    // 2026-08-03: cascade reset khi đổi cấp cha.
    public function updatedPoolBranchId(): void   { $this->poolFacilityId = ''; $this->poolDepartmentId = ''; }
    public function updatedPoolFacilityId(): void { $this->poolDepartmentId = ''; }

    public function with(): array
    {
        // 2026-08-03: cascade — chia PoolUnit theo kind cho 3 dropdown.
        $branches    = \App\Models\PoolUnit::where('is_active', true)->where('kind', 'branch')->orderBy('sort')->orderBy('name')->get();
        $facilities  = $this->poolBranchId
            ? \App\Models\PoolUnit::where('is_active', true)->where('kind', 'facility')->where('parent_id', $this->poolBranchId)->orderBy('sort')->orderBy('name')->get()
            : collect();
        $departments = $this->poolFacilityId
            ? \App\Models\PoolUnit::where('is_active', true)->where('kind', 'department')->where('parent_id', $this->poolFacilityId)->orderBy('sort')->orderBy('name')->get()
            : collect();
        $company     = \App\Models\PoolUnit::where('kind', 'company')->first();

        return [
            'l1Rules' => DistributionRule::where('level', 'pool_to_team')->with('targets')->orderBy('priority')->get(),
            'l2Rules' => DistributionRule::where('level', 'team_to_user')->with('targets', 'poolUnit')->orderBy('pool_unit_id')->orderBy('priority')->get(),
            'poolUnits' => \App\Models\PoolUnit::where('is_active', true)->orderBy('path')->get(),
            'poolBranches' => $branches,
            'poolFacilities' => $facilities,
            'poolDepartments' => $departments,
            'poolCompany' => $company,
            'users' => User::where('status', 'active')->orderBy('name')->get(),
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <h1 class="text-3xl font-bold mb-1">Cấu hình Chia số & Rule lead</h1>
        <p class="text-sm text-ink/60">Rule khớp theo thứ tự ưu tiên (số nhỏ chạy trước), khớp rule đầu tiên thì dừng. Lead không khớp rule nào nằm lại kho chờ chia tay.</p>
    </div>

    @foreach ([['level' => 'pool_to_team', 'title' => 'Cấp 1 — Kho chung → Team', 'rules' => $l1Rules], ['level' => 'team_to_user', 'title' => 'Cấp 2 — Team → Sale', 'rules' => $l2Rules]] as $section)
        <div class="bg-white border border-gold-200 rounded-xl shadow-card mb-6">
            <div class="px-6 py-4 border-b border-gold-100 flex items-center justify-between">
                <h2 class="text-lg font-bold">{{ $section['title'] }}</h2>
                <button wire:click="openCreate('{{ $section['level'] }}')" class="text-sm font-semibold text-gold-700 border border-gold-300 hover:bg-gold-50 px-4 py-2 rounded-md">+ Thêm rule</button>
            </div>
            <div class="overflow-x-auto">
            <table class="w-full text-sm min-w-[720px]">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-ink/50 bg-gold-50/60">
                        <th class="px-5 py-3 font-semibold w-20">Ưu tiên</th>
                        <th class="px-5 py-3 font-semibold">Tên rule</th>
                        @if ($section['level'] === 'team_to_user')<th class="px-5 py-3 font-semibold">Team</th>@endif
                        <th class="px-5 py-3 font-semibold">Điều kiện</th>
                        <th class="px-5 py-3 font-semibold">Thuật toán</th>
                        <th class="px-5 py-3 font-semibold">Đích chia</th>
                        <th class="px-5 py-3 font-semibold text-right">Hành động</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gold-100">
                    @forelse ($section['rules'] as $rule)
                        <tr class="{{ $rule->active ? '' : 'opacity-50' }}">
                            <td class="px-5 py-3.5 font-mono text-ink/50">{{ $rule->priority }}</td>
                            <td class="px-5 py-3.5 font-semibold">{{ $rule->name }}</td>
                            @if ($section['level'] === 'team_to_user')<td class="px-5 py-3.5">{{ $rule->poolUnit?->name }}</td>@endif
                            <td class="px-5 py-3.5 text-xs text-ink/60">
                                @forelse ($rule->conditions ?? [] as $field => $values)
                                    <div><strong>{{ ['region' => 'Khu vực', 'camp' => 'Camp', 'page' => 'Page'][$field] ?? $field }}:</strong> {{ implode(', ', $values) }}</div>
                                @empty
                                    <span class="italic text-ink/40">Khớp tất cả</span>
                                @endforelse
                            </td>
                            <td class="px-5 py-3.5 text-xs">{{ \App\Models\DistributionRule::STRATEGIES[$rule->strategy] }}</td>
                            <td class="px-5 py-3.5">
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($rule->targets as $target)
                                        <span class="text-xs bg-gold-50 border border-gold-200 px-2 py-0.5 rounded">
                                            {{ $target->targetLabel() }}@if ($rule->strategy === 'weighted') ({{ $target->weight }})@endif
                                        </span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                <button wire:click="openEdit({{ $rule->id }})" class="text-xs font-semibold text-gold-700 border border-gold-300 hover:bg-gold-50 px-3 py-1.5 rounded-md">Sửa</button>
                                <button wire:click="toggleActive({{ $rule->id }})" class="text-xs font-semibold text-ink/60 border border-gold-200 hover:bg-gold-50 px-3 py-1.5 rounded-md">{{ $rule->active ? 'Tắt' : 'Bật' }}</button>
                                <button wire:click="deleteRule({{ $rule->id }})" wire:confirm="Xóa rule '{{ $rule->name }}'?" class="text-xs font-semibold text-red-700 border border-red-200 hover:bg-red-50 px-3 py-1.5 rounded-md">Xóa</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-5 py-8 text-center text-ink/40">Chưa có rule nào.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
    @endforeach

    {{-- SLA policy mặc định --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6 max-w-2xl">
        <h2 class="text-lg font-bold mb-1">Thu hồi lead theo SLA (mặc định toàn công ty)</h2>
        <p class="text-sm text-ink/50 mb-4">Quá X giờ nhận số mà không chăm sóc → thu hồi và chia lại tự động.</p>
        @if (session('sla_status'))
            <p class="mb-3 text-sm text-green-700">{{ session('sla_status') }}</p>
        @endif
        <div class="flex flex-wrap items-end gap-4">
            <div>
                <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">Chế độ</label>
                <select wire:model.live="slaMode" class="border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                    <option value="auto">Tự động theo SLA</option>
                    <option value="manual">Thủ công (quản lý tự rút)</option>
                    <option value="off">Tắt (đã chia là cố định)</option>
                </select>
            </div>
            @if ($slaMode === 'auto')
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">Quá (giờ)</label>
                    <input type="number" wire:model="slaHours" min="1" class="border border-gold-200 rounded-md px-3 py-2 text-sm w-24 focus:outline-none focus:border-gold-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">Thu hồi về</label>
                    <select wire:model="slaRecallTo" class="border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                        <option value="team">Kho team</option>
                        <option value="common">Kho chung</option>
                    </select>
                </div>
            @endif
            <button wire:click="saveSla" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-5 py-2 rounded-md">Lưu chính sách</button>
        </div>
    </div>

    {{-- Modal rule --}}
    @if ($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/40" wire:click="$set('showModal', false)"></div>
            <div class="relative bg-white rounded-xl shadow-xl border border-gold-200 w-full max-w-2xl p-7 max-h-[90vh] overflow-y-auto">
                <h3 class="text-xl font-bold mb-5">
                    {{ $editingId ? 'Sửa rule' : 'Thêm rule' }} — {{ $level === 'pool_to_team' ? 'Cấp 1 (Kho chung → Team)' : 'Cấp 2 (Team → Sale)' }}
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">Tên rule</label>
                        <input type="text" wire:model="name" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                        @error('name')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">Ưu tiên (nhỏ chạy trước)</label>
                        <input type="number" wire:model="priority" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                    </div>
                </div>

                @if ($level === 'team_to_user')
                    {{-- 2026-08-03: cascade Công ty → Địa điểm → Cơ sở → Phòng ban. Được phép dừng ở Địa điểm/Cơ sở. --}}
                    <div class="mb-4">
                        <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">Kho áp dụng</label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            <div>
                                <label class="block text-[10px] text-ink/50 mb-1">Công ty</label>
                                <input type="text" readonly value="{{ $poolCompany?->name ?? 'Longevity Medical' }}"
                                       class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm bg-slate-50 text-ink/70 cursor-not-allowed">
                            </div>
                            <div>
                                <label class="block text-[10px] text-ink/50 mb-1">Địa điểm <span class="text-red-500">*</span></label>
                                <select wire:model.live="poolBranchId" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                                    <option value="">— chọn địa điểm —</option>
                                    @foreach ($poolBranches as $b)
                                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] text-ink/50 mb-1">Cơ sở <span class="text-ink/40">(tuỳ chọn — dừng ở địa điểm = kho địa điểm)</span></label>
                                <select wire:model.live="poolFacilityId" @disabled(! $poolBranchId) class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500 disabled:bg-slate-100 disabled:text-ink/40">
                                    <option value="">{{ $poolBranchId ? '— chọn cơ sở —' : '— chọn địa điểm trước —' }}</option>
                                    @foreach ($poolFacilities as $f)
                                        <option value="{{ $f->id }}">{{ $f->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] text-ink/50 mb-1">Phòng ban <span class="text-ink/40">(tuỳ chọn)</span></label>
                                <select wire:model="poolDepartmentId" @disabled(! $poolFacilityId) class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500 disabled:bg-slate-100 disabled:text-ink/40">
                                    <option value="">{{ $poolFacilityId ? '— chọn phòng ban —' : '— chọn cơ sở trước —' }}</option>
                                    @foreach ($poolDepartments as $d)
                                        <option value="{{ $d->id }}">{{ $d->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        @error('poolUnitId')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                @endif

                <div class="border border-gold-100 rounded-lg p-4 mb-4">
                    <h4 class="font-bold text-sm mb-3">Điều kiện lọc (bỏ trống = khớp tất cả; nhiều giá trị cách nhau dấu phẩy)</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs text-ink/50 mb-1">Khu vực</label>
                            <input type="text" wire:model="condRegion" placeholder="Hà Nội, TP. Hồ Chí Minh" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                        </div>
                        <div>
                            <label class="block text-xs text-ink/50 mb-1">Camp</label>
                            <input type="text" wire:model="condCamp" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                        </div>
                        <div>
                            <label class="block text-xs text-ink/50 mb-1">Page</label>
                            <input type="text" wire:model="condPage" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">Thuật toán chia</label>
                    <select wire:model.live="strategy" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                        @foreach (\App\Models\DistributionRule::STRATEGIES as $key => $label)
                            <option value="{{ $key }}">{{ $label }}@if (in_array($key, ['top_revenue', 'top_close_rate'])) — hoàn thiện ở Phase 6, tạm chia lần lượt @endif</option>
                        @endforeach
                    </select>
                </div>

                <div class="border border-gold-100 rounded-lg p-4 mb-4">
                    <div class="flex items-center justify-between mb-3">
                        <h4 class="font-bold text-sm">Đích chia ({{ $level === 'pool_to_team' ? 'team' : 'sale' }})</h4>
                        <button wire:click="addTarget" class="text-xs font-semibold text-gold-700 hover:underline">+ thêm đích</button>
                    </div>
                    @error('targets')<p class="text-xs text-red-600 mb-2">{{ $message }}</p>@enderror
                    <div class="space-y-2">
                        @foreach ($targets as $index => $target)
                            <div class="flex items-center gap-3" wire:key="target-{{ $index }}">
                                <select wire:model="targets.{{ $index }}.id" class="flex-1 border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                                    <option value="">— chọn —</option>
                                    @if ($level === 'pool_to_team')
                                        @foreach ($poolUnits as $unit)
                                            <option value="{{ $unit->id }}">{{ str_repeat('— ', $unit->depth) }}{{ $unit->name }}</option>
                                        @endforeach
                                    @else
                                        @foreach ($users as $u)
                                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                                        @endforeach
                                    @endif
                                </select>
                                @if ($strategy === 'weighted')
                                    <input type="number" wire:model="targets.{{ $index }}.weight" min="1" title="Tỉ trọng"
                                           class="w-20 border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                                @endif
                                <button wire:click="removeTarget({{ $index }})" class="text-red-400 hover:text-red-600 text-lg leading-none px-1">×</button>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="flex justify-end gap-3">
                    <button wire:click="$set('showModal', false)" class="text-sm text-ink/60 px-4 py-2">Hủy</button>
                    <button wire:click="save" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-6 py-2 rounded-md">Lưu rule</button>
                </div>
            </div>
        </div>
    @endif
</div>
