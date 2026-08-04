<?php

use App\Models\Assignment;
use App\Models\CustomField;
use App\Models\Lead;
use App\Models\OrgUnit;
use App\Models\PoolUnit;
use App\Models\Service;
use App\Models\User;
use App\Services\SystemCatalogImporter;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    /** Tab đang mở: org | staff | service | lead | field */
    public string $activeTab = 'org';

    /** File Excel người dùng upload để import (transient). */
    public $importFile = null;

    /** Filter Phase cho tab Khách hàng (empty = tất cả). */
    public string $leadPhaseFilter = '';

    /** Report sau khi import: {created, updated, skipped, errors[]} */
    public ?array $importReport = null;

    public const TABS = [
        'org' => 'Cơ cấu tổ chức',
        'staff' => 'Nhân sự',
        'service' => 'Dịch vụ',
        'lead' => 'Khách hàng',
        'field' => 'Trường thông tin KH',
    ];

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasPermission('user.manage'), 403);
    }

    public function setTab(string $tab): void
    {
        if (! array_key_exists($tab, self::TABS)) return;
        $this->activeTab = $tab;
        $this->importReport = null;
        $this->importFile = null;
        $this->leadPhaseFilter = '';
        $this->resetErrorBag();
    }

    public function runImport(SystemCatalogImporter $importer): void
    {
        abort_unless(auth()->user()?->hasPermission('user.manage'), 403);

        $this->validate([
            'importFile' => 'required|file|max:10240|mimes:xlsx,xls,csv',
        ], [], ['importFile' => 'file']);

        $path = $this->importFile->getRealPath();
        $ext = $this->importFile->getClientOriginalExtension() ?: 'xlsx';

        try {
            $this->importReport = $importer->import($this->activeTab, $path, $ext);
        } catch (\Throwable $e) {
            $this->importReport = [
                'created' => 0, 'updated' => 0, 'skipped' => 0,
                'errors' => ['Import lỗi: ' . $e->getMessage()],
            ];
        }

        $this->importFile = null;
    }

    /** Rows hiện tại của tab để render bảng (chỉ mở lấy khi tab đang active). */
    public function rowsForActiveTab(): array
    {
        return match ($this->activeTab) {
            'org' => $this->orgRows(),
            'staff' => $this->staffRows(),
            'service' => $this->serviceRows(),
            'lead' => $this->leadRows(),
            'field' => $this->fieldRows(),
        };
    }

    private function orgRows(): array
    {
        return OrgUnit::orderBy('depth')->orderBy('position')->limit(500)->get()
            ->map(fn ($o) => [
                'code' => $o->code,
                'name' => $o->name,
                'depth' => $o->depth,
                'path' => $o->path,
                'active' => $o->active,
            ])->all();
    }

    private function staffRows(): array
    {
        return User::with('assignments.role', 'assignments.orgUnit')
            ->orderBy('email')->limit(500)->get()
            ->map(function ($u) {
                $assigns = $u->assignments->map(fn ($a) => ($a->role?->name ?? '?') . ' @ ' . ($a->orgUnit?->name ?? '?'))->implode('; ');
                return [
                    'username' => $u->username,
                    'name' => $u->name,
                    'email' => $u->email,
                    'status' => $u->status,
                    'assignments' => $assigns,
                ];
            })->all();
    }

    private function serviceRows(): array
    {
        return Service::orderBy('name')->limit(500)->get()
            ->map(fn ($s) => [
                'code' => $s->code,
                'name' => $s->name,
                'service_type' => $s->service_type,
                'pricing_type' => $s->pricing_type,
                'package_price' => number_format((float) $s->package_price, 0, ',', '.'),
                'active' => $s->active,
            ])->all();
    }

    private function leadRows(): array
    {
        $q = Lead::query()->orderByDesc('id');
        if ($this->leadPhaseFilter !== '') {
            $q->where('phase', (int) $this->leadPhaseFilter);
        }
        return $q->limit(200)->get()
            ->map(fn ($l) => [
                'code' => $l->code,
                'name' => $l->name,
                'phone' => $l->phone,
                'source_group' => $l->source_group,
                'phase' => $l->phase,
                'classification' => $l->classification,
                'received_date' => $l->received_date?->format('Y-m-d'),
            ])->all();
    }

    private function fieldRows(): array
    {
        return CustomField::with('orgUnit')->orderBy('position')->limit(500)->get()
            ->map(fn ($f) => [
                'key' => $f->key,
                'label' => $f->label,
                'field_type' => $f->field_type,
                'org' => $f->orgUnit?->name ?? '(công ty)',
                'required' => $f->required,
                'active' => $f->active,
            ])->all();
    }

    public function tabCounts(): array
    {
        return [
            'org' => OrgUnit::count(),
            'staff' => User::count(),
            'service' => Service::count(),
            'lead' => Lead::count(),
            'field' => CustomField::count(),
        ];
    }
}; ?>

<div class="max-w-7xl mx-auto p-6">
    <div class="mb-6 flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gold-700">Danh mục hệ thống</h1>
            <p class="text-sm text-ink/60 mt-1">Xem – Nhập – Xuất dữ liệu core (chỉ Admin hệ thống). Import ghi thẳng vào DB, không qua raw pipeline.</p>
        </div>
    </div>

    {{-- Tabs --}}
    @php $counts = $this->tabCounts(); @endphp
    <div class="flex flex-wrap gap-1 border-b border-slate-200 mb-4">
        @foreach (self::TABS as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')"
                    class="px-4 py-2 text-sm font-semibold border-b-2 -mb-px transition {{ $activeTab === $key ? 'border-gold-600 text-gold-700' : 'border-transparent text-ink/60 hover:text-ink/90' }}">
                {{ $label }}
                <span class="ml-1.5 inline-block text-xs bg-slate-100 text-ink/60 px-1.5 py-0.5 rounded">{{ $counts[$key] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    {{-- Action bar mỗi tab --}}
    <div class="mb-4 flex flex-wrap items-center gap-2 bg-amber-50 border border-amber-200 rounded p-3">
        <span class="text-xs text-ink/70 font-semibold mr-2">📁 {{ self::TABS[$activeTab] }}:</span>

        {{-- Tải mẫu --}}
        <a href="{{ route('admin.catalog.template', ['tab' => $activeTab]) }}"
           class="text-xs bg-white border border-slate-300 hover:border-blue-400 text-blue-700 hover:bg-blue-50 px-3 py-1.5 rounded">
            📄 Tải file mẫu
        </a>

        {{-- Xuất --}}
        <a href="{{ route('admin.catalog.export', ['tab' => $activeTab]) }}"
           class="text-xs bg-white border border-slate-300 hover:border-emerald-400 text-emerald-700 hover:bg-emerald-50 px-3 py-1.5 rounded">
            ⬇ Xuất Excel (data hiện tại)
        </a>

        <div class="ml-auto flex items-center gap-2" wire:key="upload-{{ $activeTab }}">
            <input type="file" wire:model="importFile" accept=".xlsx,.xls,.csv"
                   class="text-xs file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:bg-blue-100 file:text-blue-700 hover:file:bg-blue-200">
            <button type="button" wire:click="runImport" wire:loading.attr="disabled" wire:target="runImport,importFile"
                    class="text-xs bg-blue-600 hover:bg-blue-700 disabled:bg-slate-400 text-white font-semibold px-3 py-1.5 rounded">
                ⬆ <span wire:loading.remove wire:target="runImport,importFile">Nhập</span>
                <span wire:loading wire:target="runImport,importFile">Đang nhập…</span>
            </button>
        </div>
    </div>
    @error('importFile') <p class="text-xs text-red-700 mb-2">⚠ {{ $message }}</p> @enderror

    {{-- Import report --}}
    @if ($importReport)
        <div class="mb-4 p-3 rounded border text-sm
                    {{ ($importReport['errors'] ?? []) ? 'bg-amber-50 border-amber-300' : 'bg-emerald-50 border-emerald-300' }}">
            <b class="text-ink/80">Kết quả import:</b>
            <span class="text-emerald-700 ml-2">+ Tạo mới: <b>{{ $importReport['created'] }}</b></span>
            <span class="text-blue-700 ml-2">↻ Cập nhật: <b>{{ $importReport['updated'] }}</b></span>
            <span class="text-slate-600 ml-2">↷ Bỏ qua: <b>{{ $importReport['skipped'] }}</b></span>
            @if (! empty($importReport['errors']))
                <ul class="mt-2 text-xs text-red-700 list-disc pl-5 space-y-0.5 max-h-40 overflow-auto">
                    @foreach ($importReport['errors'] as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    {{-- Filter Phase (chỉ tab Khách hàng) --}}
    @if ($activeTab === 'lead')
        <div class="mb-3 flex items-center gap-2">
            <label class="text-xs text-ink/60">Lọc theo Phase:</label>
            <select wire:model.live="leadPhaseFilter" class="text-xs border border-slate-300 rounded px-2 py-1">
                <option value="">Tất cả</option>
                @foreach (\App\Models\Lead::CF_PHASE_LABELS as $i => $label)
                    <option value="{{ $i }}">Phase {{ $i }} — {{ $label }}</option>
                @endforeach
            </select>
            <span class="text-xs text-ink/40 ml-auto">(hiển thị 200 record đầu — xuất Excel để lấy đủ)</span>
        </div>
    @endif

    {{-- Bảng data --}}
    @php $rows = $this->rowsForActiveTab(); @endphp
    <div class="bg-white border border-slate-200 rounded overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-ink/70 text-xs uppercase">
                <tr>
                    @if ($activeTab === 'org')
                        <th class="px-3 py-2 text-left">Code</th>
                        <th class="px-3 py-2 text-left">Tên</th>
                        <th class="px-3 py-2 text-left">Depth</th>
                        <th class="px-3 py-2 text-left">Path</th>
                        <th class="px-3 py-2 text-left">Active</th>
                    @elseif ($activeTab === 'staff')
                        <th class="px-3 py-2 text-left">Username</th>
                        <th class="px-3 py-2 text-left">Tên</th>
                        <th class="px-3 py-2 text-left">Email</th>
                        <th class="px-3 py-2 text-left">Status</th>
                        <th class="px-3 py-2 text-left">Assignments</th>
                    @elseif ($activeTab === 'service')
                        <th class="px-3 py-2 text-left">Code</th>
                        <th class="px-3 py-2 text-left">Tên</th>
                        <th class="px-3 py-2 text-left">Loại</th>
                        <th class="px-3 py-2 text-left">Pricing</th>
                        <th class="px-3 py-2 text-right">Giá</th>
                        <th class="px-3 py-2 text-center">Active</th>
                    @elseif ($activeTab === 'lead')
                        <th class="px-3 py-2 text-left">Mã KH</th>
                        <th class="px-3 py-2 text-left">Tên</th>
                        <th class="px-3 py-2 text-left">SĐT</th>
                        <th class="px-3 py-2 text-left">Nguồn</th>
                        <th class="px-3 py-2 text-center">Phase</th>
                        <th class="px-3 py-2 text-left">Trạng thái</th>
                        <th class="px-3 py-2 text-left">Ngày nhận</th>
                    @elseif ($activeTab === 'field')
                        <th class="px-3 py-2 text-left">Key</th>
                        <th class="px-3 py-2 text-left">Label</th>
                        <th class="px-3 py-2 text-left">Loại</th>
                        <th class="px-3 py-2 text-left">Phòng ban</th>
                        <th class="px-3 py-2 text-center">Bắt buộc</th>
                        <th class="px-3 py-2 text-center">Active</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-slate-50">
                        @if ($activeTab === 'org')
                            <td class="px-3 py-1.5 font-mono text-xs">{{ $r['code'] }}</td>
                            <td class="px-3 py-1.5">{{ $r['name'] }}</td>
                            <td class="px-3 py-1.5 text-xs text-ink/60">{{ $r['depth'] }}</td>
                            <td class="px-3 py-1.5 font-mono text-xs text-ink/50">{{ $r['path'] }}</td>
                            <td class="px-3 py-1.5">{!! $r['active'] ? '✅' : '⛔' !!}</td>
                        @elseif ($activeTab === 'staff')
                            <td class="px-3 py-1.5 font-mono text-xs">{{ $r['username'] }}</td>
                            <td class="px-3 py-1.5">{{ $r['name'] }}</td>
                            <td class="px-3 py-1.5 text-xs">{{ $r['email'] }}</td>
                            <td class="px-3 py-1.5 text-xs">{{ $r['status'] }}</td>
                            <td class="px-3 py-1.5 text-xs text-ink/60">{{ $r['assignments'] }}</td>
                        @elseif ($activeTab === 'service')
                            <td class="px-3 py-1.5 font-mono text-xs">{{ $r['code'] }}</td>
                            <td class="px-3 py-1.5">{{ $r['name'] }}</td>
                            <td class="px-3 py-1.5 text-xs">{{ $r['service_type'] }}</td>
                            <td class="px-3 py-1.5 text-xs">{{ $r['pricing_type'] }}</td>
                            <td class="px-3 py-1.5 text-xs text-right">{{ $r['package_price'] }}</td>
                            <td class="px-3 py-1.5 text-center">{!! $r['active'] ? '✅' : '⛔' !!}</td>
                        @elseif ($activeTab === 'lead')
                            <td class="px-3 py-1.5 font-mono text-xs">{{ $r['code'] }}</td>
                            <td class="px-3 py-1.5">{{ $r['name'] }}</td>
                            <td class="px-3 py-1.5 text-xs">{{ $r['phone'] }}</td>
                            <td class="px-3 py-1.5 text-xs uppercase">{{ $r['source_group'] }}</td>
                            <td class="px-3 py-1.5 text-center">{{ $r['phase'] }}</td>
                            <td class="px-3 py-1.5 text-xs">{{ $r['classification'] }}</td>
                            <td class="px-3 py-1.5 text-xs">{{ $r['received_date'] }}</td>
                        @elseif ($activeTab === 'field')
                            <td class="px-3 py-1.5 font-mono text-xs">{{ $r['key'] }}</td>
                            <td class="px-3 py-1.5">{{ $r['label'] }}</td>
                            <td class="px-3 py-1.5 text-xs">{{ $r['field_type'] }}</td>
                            <td class="px-3 py-1.5 text-xs">{{ $r['org'] }}</td>
                            <td class="px-3 py-1.5 text-center">{!! $r['required'] ? '⚠' : '' !!}</td>
                            <td class="px-3 py-1.5 text-center">{!! $r['active'] ? '✅' : '⛔' !!}</td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-3 py-6 text-center text-ink/40 italic">(chưa có dữ liệu)</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
