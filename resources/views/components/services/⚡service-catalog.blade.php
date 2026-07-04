<?php

use App\Models\Contribution;
use App\Models\ContributionTemplate;
use App\Models\CustomerService;
use App\Models\Service;
use Illuminate\Support\Str;
use Livewire\Component;

new class extends Component
{
    // ----- Form dịch vụ -----
    public bool $showModal = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $pricingType = Service::PRICING_PACKAGE;

    public string $packagePrice = '';

    /** @var array<int, array{name: string, price: string}> */
    public array $phaseRows = [];

    // ----- Template % -----
    public bool $showTemplateModal = false;

    public ?int $editingTemplateId = null;

    public string $templateName = '';

    /** @var array<int, array{role_label: string, percent: string}> */
    public array $templateItems = [];

    public bool $templateDefault = false;

    // ---------- Dịch vụ ----------

    public function openCreate(): void
    {
        $this->reset('editingId', 'name', 'packagePrice');
        $this->pricingType = Service::PRICING_PACKAGE;
        $this->phaseRows = [['name' => '', 'price' => '']];
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $service = Service::with('phases')->findOrFail($id);
        $this->editingId = $service->id;
        $this->name = $service->name;
        $this->pricingType = $service->pricing_type;
        $this->packagePrice = (string) ($service->package_price ?? '');
        $this->phaseRows = $service->phases->map(fn ($p) => [
            'name' => $p->name, 'price' => (string) ($p->phase_price ?? ''),
        ])->all() ?: [['name' => '', 'price' => '']];
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function addPhaseRow(): void
    {
        $this->phaseRows[] = ['name' => '', 'price' => ''];
    }

    public function removePhaseRow(int $index): void
    {
        unset($this->phaseRows[$index]);
        $this->phaseRows = array_values($this->phaseRows);
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:150',
            'pricingType' => 'required|in:package,per_phase',
            'packagePrice' => $this->pricingType === Service::PRICING_PACKAGE ? 'required|numeric|min:0' : 'nullable',
        ], [], ['name' => 'tên dịch vụ', 'packagePrice' => 'giá trọn gói']);

        $phases = array_values(array_filter($this->phaseRows, fn ($r) => trim($r['name']) !== ''));
        if ($phases === []) {
            $this->addError('phaseRows', 'Dịch vụ cần ít nhất một phase (mốc tiến độ).');
            return;
        }
        if ($this->pricingType === Service::PRICING_PER_PHASE) {
            foreach ($phases as $row) {
                if (! is_numeric($row['price']) || (float) $row['price'] < 0) {
                    $this->addError('phaseRows', 'Giá theo phase: mỗi phase cần giá hợp lệ.');
                    return;
                }
            }
        }

        $attributes = [
            'name' => $this->name,
            'pricing_type' => $this->pricingType,
            'package_price' => $this->pricingType === Service::PRICING_PACKAGE ? (int) $this->packagePrice : null,
        ];

        if ($this->editingId) {
            $service = Service::findOrFail($this->editingId);
            $service->update($attributes);
        } else {
            $base = strtoupper(Str::slug($this->name, ''));
            $code = substr($base, 0, 10) ?: 'SVC';
            $i = 1;
            while (Service::where('code', $code)->exists()) {
                $code = substr($base, 0, 8) . (++$i);
            }
            $service = Service::create($attributes + ['code' => $code]);
        }

        // Đồng bộ phases: đơn giản là thay thế nếu chưa có khách dùng, còn có khách thì chỉ thêm/sửa tên
        if (CustomerService::where('service_id', $service->id)->exists() && $service->phases()->count() > count($phases)) {
            $this->addError('phaseRows', 'Dịch vụ đã có khách sử dụng — không thể bớt phase, chỉ thêm hoặc đổi tên.');
            return;
        }

        $existing = $service->phases()->orderBy('position')->get();
        foreach ($phases as $i => $row) {
            $data = ['position' => $i + 1, 'name' => trim($row['name']), 'phase_price' => is_numeric($row['price']) ? (int) $row['price'] : null];
            if (isset($existing[$i])) {
                $existing[$i]->update($data);
            } else {
                $service->phases()->create($data);
            }
        }
        // Xóa phase thừa (chỉ khi chưa có khách — đã chặn ở trên)
        $service->phases()->where('position', '>', count($phases))->delete();

        $this->showModal = false;
    }

    public function toggleActive(int $id): void
    {
        $service = Service::findOrFail($id);
        $service->update(['active' => ! $service->active]);
    }

    // ---------- Template % ----------

    public function openCreateTemplate(): void
    {
        $this->reset('editingTemplateId', 'templateName');
        $this->templateItems = [
            ['role_label' => 'collector', 'percent' => '20'],
            ['role_label' => 'care_1', 'percent' => '30'],
            ['role_label' => 'closer', 'percent' => '50'],
        ];
        $this->templateDefault = ! ContributionTemplate::where('is_default', true)->exists();
        $this->resetErrorBag();
        $this->showTemplateModal = true;
    }

    public function openEditTemplate(int $id): void
    {
        $template = ContributionTemplate::findOrFail($id);
        $this->editingTemplateId = $template->id;
        $this->templateName = $template->name;
        $this->templateItems = array_map(fn ($i) => ['role_label' => $i['role_label'], 'percent' => (string) $i['percent']], $template->items);
        $this->templateDefault = $template->is_default;
        $this->resetErrorBag();
        $this->showTemplateModal = true;
    }

    public function addTemplateItem(): void
    {
        $this->templateItems[] = ['role_label' => 'other', 'percent' => '0'];
    }

    public function removeTemplateItem(int $index): void
    {
        unset($this->templateItems[$index]);
        $this->templateItems = array_values($this->templateItems);
    }

    public function saveTemplate(): void
    {
        $this->validate(['templateName' => 'required|string|max:100'], [], ['templateName' => 'tên mẫu']);

        $items = array_values(array_filter($this->templateItems, fn ($i) => is_numeric($i['percent']) && (float) $i['percent'] > 0));
        $total = round(array_sum(array_map(fn ($i) => (float) $i['percent'], $items)), 2);
        if ($total !== 100.0) {
            $this->addError('templateItems', "Tổng % phải đúng 100 (hiện tại: {$total}).");
            return;
        }

        $template = ContributionTemplate::updateOrCreate(
            ['id' => $this->editingTemplateId],
            ['name' => $this->templateName, 'items' => $items, 'is_default' => $this->templateDefault]
        );

        if ($this->templateDefault) {
            ContributionTemplate::where('id', '!=', $template->id)->update(['is_default' => false]);
        }

        $this->showTemplateModal = false;
    }

    public function deleteTemplate(int $id): void
    {
        ContributionTemplate::findOrFail($id)->delete();
    }

    public function with(): array
    {
        return [
            'services' => Service::withCount('phases')->orderBy('name')->get(),
            'customerCounts' => CustomerService::selectRaw('service_id, count(*) c')->groupBy('service_id')->pluck('c', 'service_id'),
            'templates' => ContributionTemplate::orderByDesc('is_default')->orderBy('name')->get(),
        ];
    }
};
?>

<div>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-3xl font-bold mb-1">Quản lý & Theo dõi Dịch vụ</h1>
            <p class="text-sm text-ink/60">Danh mục dịch vụ với các phase tiến độ. Gắn dịch vụ cho khách và theo dõi phase ngay trong màn chi tiết khách hàng.</p>
        </div>
        <button wire:click="openCreate" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold px-5 py-2.5 rounded-md text-sm">+ Thêm dịch vụ</button>
    </div>

    <div class="bg-white border border-gold-200 rounded-xl shadow-card mb-8 overflow-x-auto">
        <table class="w-full text-sm min-w-[680px]">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wider text-ink/50 bg-gold-50/60">
                    <th class="px-5 py-3 font-semibold">Dịch vụ</th>
                    <th class="px-5 py-3 font-semibold">Cách tính giá</th>
                    <th class="px-5 py-3 font-semibold text-right">Giá niêm yết</th>
                    <th class="px-5 py-3 font-semibold text-right">Số phase</th>
                    <th class="px-5 py-3 font-semibold text-right">Khách đang dùng</th>
                    <th class="px-5 py-3 font-semibold text-right">Hành động</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gold-100">
                @forelse ($services as $service)
                    <tr class="{{ $service->active ? '' : 'opacity-50' }}">
                        <td class="px-5 py-3.5">
                            <div class="font-semibold">{{ $service->name }}</div>
                            <div class="text-xs text-ink/40">{{ $service->code }}</div>
                        </td>
                        <td class="px-5 py-3.5 text-xs">
                            @if ($service->pricing_type === 'package')
                                <span class="bg-gold-50 border border-gold-200 px-2 py-0.5 rounded">Trọn gói (phase là mốc tiến độ)</span>
                            @else
                                <span class="bg-blue-50 border border-blue-200 text-blue-700 px-2 py-0.5 rounded">Theo từng phase (trả đến đâu tính đến đó)</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-right font-mono">{{ number_format($service->listPrice()) }}₫</td>
                        <td class="px-5 py-3.5 text-right">{{ $service->phases_count }}</td>
                        <td class="px-5 py-3.5 text-right">{{ $customerCounts[$service->id] ?? 0 }}</td>
                        <td class="px-5 py-3.5 text-right whitespace-nowrap">
                            <button wire:click="openEdit({{ $service->id }})" class="text-xs font-semibold text-gold-700 border border-gold-300 hover:bg-gold-50 px-3 py-1.5 rounded-md">Sửa</button>
                            <button wire:click="toggleActive({{ $service->id }})" class="text-xs font-semibold text-ink/60 border border-gold-200 hover:bg-gold-50 px-3 py-1.5 rounded-md">{{ $service->active ? 'Ngưng bán' : 'Bán lại' }}</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-5 py-8 text-center text-ink/40">Chưa có dịch vụ nào.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Template % đóng góp --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card max-w-3xl">
        <div class="px-6 py-4 border-b border-gold-100 flex items-center justify-between">
            <div>
                <h2 class="text-lg font-bold">Mẫu % đóng góp mặc định</h2>
                <p class="text-sm text-ink/50">Khi deal Close, popup chia % sẽ áp mẫu mặc định — lead team chỉ sửa khi cần.</p>
            </div>
            <button wire:click="openCreateTemplate" class="text-sm font-semibold text-gold-700 border border-gold-300 hover:bg-gold-50 px-4 py-2 rounded-md">+ Thêm mẫu</button>
        </div>
        <div class="divide-y divide-gold-100">
            @forelse ($templates as $template)
                <div class="px-6 py-4 flex items-center gap-4">
                    <div class="flex-1">
                        <span class="font-semibold">{{ $template->name }}</span>
                        @if ($template->is_default)
                            <span class="text-[10px] font-semibold uppercase tracking-wider bg-gold-600 text-white px-2 py-0.5 rounded ml-1">Mặc định</span>
                        @endif
                        <div class="text-xs text-ink/50 mt-1">
                            {{ collect($template->items)->map(fn ($i) => (\App\Models\Contribution::ROLE_LABELS[$i['role_label']] ?? $i['role_label']) . ' ' . $i['percent'] . '%')->join(' – ') }}
                        </div>
                    </div>
                    <button wire:click="openEditTemplate({{ $template->id }})" class="text-xs font-semibold text-gold-700 border border-gold-300 hover:bg-gold-50 px-3 py-1.5 rounded-md">Sửa</button>
                    <button wire:click="deleteTemplate({{ $template->id }})" wire:confirm="Xóa mẫu này?" class="text-xs font-semibold text-red-700 border border-red-200 hover:bg-red-50 px-3 py-1.5 rounded-md">Xóa</button>
                </div>
            @empty
                <p class="px-6 py-8 text-sm text-ink/40 text-center">Chưa có mẫu nào — popup % sẽ để trống cho lead team tự điền.</p>
            @endforelse
        </div>
    </div>

    {{-- Modal dịch vụ --}}
    @if ($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/40" wire:click="$set('showModal', false)"></div>
            <div class="relative bg-white rounded-xl shadow-xl border border-gold-200 w-full max-w-xl p-7 max-h-[90vh] overflow-y-auto">
                <h3 class="text-xl font-bold mb-5">{{ $editingId ? 'Sửa dịch vụ' : 'Thêm dịch vụ' }}</h3>
                <div class="space-y-4 mb-4">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">Tên dịch vụ</label>
                        <input type="text" wire:model="name" placeholder="VD: Liệu trình da liễu" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                        @error('name')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">Cách tính giá</label>
                            <select wire:model.live="pricingType" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                                <option value="package">Trọn gói</option>
                                <option value="per_phase">Theo từng phase</option>
                            </select>
                        </div>
                        @if ($pricingType === 'package')
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">Giá trọn gói (₫)</label>
                                <input type="number" wire:model="packagePrice" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                                @error('packagePrice')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                            </div>
                        @endif
                    </div>
                </div>

                <div class="border border-gold-100 rounded-lg p-4 mb-4">
                    <div class="flex items-center justify-between mb-3">
                        <h4 class="font-bold text-sm">Các phase {{ $pricingType === 'package' ? '(mốc tiến độ)' : '(kèm giá từng phase)' }}</h4>
                        <button wire:click="addPhaseRow" class="text-xs font-semibold text-gold-700 hover:underline">+ thêm phase</button>
                    </div>
                    @error('phaseRows')<p class="text-xs text-red-600 mb-2">{{ $message }}</p>@enderror
                    <div class="space-y-2">
                        @foreach ($phaseRows as $index => $row)
                            <div class="flex items-center gap-3" wire:key="phase-{{ $index }}">
                                <span class="text-xs text-ink/40 w-6">{{ $index + 1 }}.</span>
                                <input type="text" wire:model="phaseRows.{{ $index }}.name" placeholder="Tên phase" class="flex-1 border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                                @if ($pricingType === 'per_phase')
                                    <input type="number" wire:model="phaseRows.{{ $index }}.price" placeholder="Giá ₫" class="w-32 border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                                @endif
                                <button wire:click="removePhaseRow({{ $index }})" class="text-red-400 hover:text-red-600 text-lg leading-none px-1">×</button>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="flex justify-end gap-3">
                    <button wire:click="$set('showModal', false)" class="text-sm text-ink/60 px-4 py-2">Hủy</button>
                    <button wire:click="save" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-6 py-2 rounded-md">Lưu dịch vụ</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal template % --}}
    @if ($showTemplateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/40" wire:click="$set('showTemplateModal', false)"></div>
            <div class="relative bg-white rounded-xl shadow-xl border border-gold-200 w-full max-w-md p-7">
                <h3 class="text-xl font-bold mb-5">{{ $editingTemplateId ? 'Sửa mẫu %' : 'Thêm mẫu %' }}</h3>
                <div class="mb-4">
                    <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">Tên mẫu</label>
                    <input type="text" wire:model="templateName" placeholder="VD: Mẫu chuẩn 20-30-50" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                    @error('templateName')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>
                <div class="border border-gold-100 rounded-lg p-4 mb-4">
                    <div class="flex items-center justify-between mb-3">
                        <h4 class="font-bold text-sm">Vai trò & %</h4>
                        <button wire:click="addTemplateItem" class="text-xs font-semibold text-gold-700 hover:underline">+ thêm dòng</button>
                    </div>
                    @error('templateItems')<p class="text-xs text-red-600 mb-2">{{ $message }}</p>@enderror
                    <div class="space-y-2">
                        @foreach ($templateItems as $index => $item)
                            <div class="flex items-center gap-3" wire:key="titem-{{ $index }}">
                                <select wire:model="templateItems.{{ $index }}.role_label" class="flex-1 border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                                    @foreach (\App\Models\Contribution::ROLE_LABELS as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <input type="number" wire:model="templateItems.{{ $index }}.percent" min="0" max="100" class="w-20 border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                                <span class="text-sm text-ink/40">%</span>
                                <button wire:click="removeTemplateItem({{ $index }})" class="text-red-400 hover:text-red-600 text-lg leading-none px-1">×</button>
                            </div>
                        @endforeach
                    </div>
                </div>
                <label class="flex items-center gap-2.5 text-sm mb-5 cursor-pointer">
                    <input type="checkbox" wire:model="templateDefault" class="rounded border-gold-300 text-gold-600 focus:ring-gold-500 w-5 h-5">
                    Đặt làm mẫu mặc định
                </label>
                <div class="flex justify-end gap-3">
                    <button wire:click="$set('showTemplateModal', false)" class="text-sm text-ink/60 px-4 py-2">Hủy</button>
                    <button wire:click="saveTemplate" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-6 py-2 rounded-md">Lưu mẫu</button>
                </div>
            </div>
        </div>
    @endif
</div>
