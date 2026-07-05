<?php

use App\Models\IngestLog;
use App\Models\Lead;
use App\Models\SourceConnection;
use App\Services\AdsSync\AdsSyncService;
use Illuminate\Support\Str;
use Livewire\Component;

new class extends Component
{
    public bool $showModal = false;

    public ?int $editingId = null;

    public string $type = 'webhook';

    public string $name = '';

    /** @var array<int, array{target: string, source: string}> */
    public array $mappingRows = [];

    // Credentials cho Ads API
    public string $credToken = '';

    public string $credFormId = '';

    public function openCreate(): void
    {
        $this->reset('editingId', 'name', 'credToken', 'credFormId');
        $this->type = 'webhook';
        $this->mappingRows = [['target' => '', 'source' => '']];
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $connection = SourceConnection::findOrFail($id);
        $this->editingId = $connection->id;
        $this->type = $connection->type;
        $this->name = $connection->name;
        $this->mappingRows = collect($connection->field_mapping ?: [])
            ->map(fn ($source, $target) => ['target' => $target, 'source' => $source])
            ->values()->all() ?: [['target' => '', 'source' => '']];
        $credentials = $connection->credentials ?? [];
        $this->credToken = $credentials['access_token'] ?? '';
        $this->credFormId = $credentials['form_id'] ?? '';
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function addMappingRow(): void
    {
        $this->mappingRows[] = ['target' => '', 'source' => ''];
    }

    public function removeMappingRow(int $index): void
    {
        unset($this->mappingRows[$index]);
        $this->mappingRows = array_values($this->mappingRows);
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:100',
            'type' => 'required|in:webhook,facebook_ads,tiktok_ads,google_ads',
        ], [], ['name' => 'tên kết nối']);

        $mapping = collect($this->mappingRows)
            ->filter(fn ($r) => trim($r['target']) !== '' && trim($r['source']) !== '')
            ->mapWithKeys(fn ($r) => [trim($r['target']) => trim($r['source'])])
            ->all();

        $attributes = [
            'type' => $this->type,
            'name' => $this->name,
            'field_mapping' => $mapping ?: null,
        ];

        if ($this->type !== 'webhook') {
            $attributes['credentials'] = array_filter([
                'access_token' => trim($this->credToken) ?: null,
                'form_id' => trim($this->credFormId) ?: null,
            ]);
        }

        if ($this->editingId) {
            SourceConnection::findOrFail($this->editingId)->update($attributes);
        } else {
            if ($this->type === 'webhook') {
                $attributes['webhook_token'] = Str::random(48);
            }
            SourceConnection::create($attributes);
        }

        $this->showModal = false;
    }

    public function toggleActive(int $id): void
    {
        $connection = SourceConnection::findOrFail($id);
        $connection->update(['active' => ! $connection->active]);
    }

    public function regenerateToken(int $id): void
    {
        SourceConnection::where('type', 'webhook')->findOrFail($id)
            ->update(['webhook_token' => Str::random(48)]);
        session()->flash('status', 'Đã tạo token mới — cập nhật lại URL trên landing page.');
    }

    public function syncNow(int $id): void
    {
        $connection = SourceConnection::findOrFail($id);
        $result = app(AdsSyncService::class)->syncConnection($connection);

        session()->flash(
            $result['error'] ? 'error' : 'status',
            $result['error'] ?: "Đã kéo về {$result['fetched']} lead từ {$connection->name}."
        );
    }

    public function with(): array
    {
        return [
            'connections' => SourceConnection::orderBy('type')->orderBy('name')->get(),
            'recentLogs' => IngestLog::orderByDesc('created_at')->limit(10)->get(),
            'typeLabels' => [
                'webhook' => 'Webhook (landing page)',
                'facebook_ads' => 'Facebook Lead Ads',
                'tiktok_ads' => 'TikTok Lead Ads',
                'google_ads' => 'Google Ads',
            ],
        ];
    }
};
?>

<div>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-3xl font-bold mb-1">Quản lý Kết nối nguồn lead</h1>
            <p class="text-sm text-ink/60">
                Webhook cho landing page + Ads API tự đồng bộ 15 phút/lần. Lead lỗi xem tại
                <a href="{{ route('leads.failed') }}" class="text-gold-600 underline">màn Lead lỗi</a>.
            </p>
        </div>
        <button wire:click="openCreate" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold px-5 py-2.5 rounded-md text-sm">+ Thêm kết nối</button>
    </div>

    @if (session('status'))<p class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-4 py-2">{{ session('status') }}</p>@endif
    @if (session('error'))<p class="mb-4 text-sm text-red-600 bg-red-50 border border-red-200 rounded-md px-4 py-2">{{ session('error') }}</p>@endif

    <div class="space-y-4 mb-8">
        @forelse ($connections as $connection)
            <div class="bg-white border border-gold-200 rounded-xl shadow-card p-5 {{ $connection->active ? '' : 'opacity-60' }}">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="w-10 h-10 rounded-lg {{ $connection->type === 'webhook' ? 'bg-blue-50 border-blue-200 text-blue-600' : 'bg-gold-50 border-gold-200 text-gold-600' }} border flex items-center justify-center">
                        @if ($connection->type === 'webhook')
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244"/></svg>
                        @else
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12.75 19.5v-.75a7.5 7.5 0 00-7.5-7.5H4.5m0-6.75h.75c7.87 0 14.25 6.38 14.25 14.25v.75M6 18.75a.75.75 0 11-1.5 0 .75.75 0 011.5 0z"/></svg>
                        @endif
                    </span>
                    <div class="flex-1">
                        <div class="font-bold">{{ $connection->name }}</div>
                        <div class="text-xs text-ink/50">
                            {{ $typeLabels[$connection->type] }}
                            @if ($connection->last_synced_at) · sync cuối {{ $connection->last_synced_at->diffForHumans() }} @endif
                        </div>
                    </div>
                    @if ($connection->type !== 'webhook')
                        <button wire:click="syncNow({{ $connection->id }})" class="text-xs font-semibold text-gold-700 border border-gold-300 hover:bg-gold-50 px-3 py-1.5 rounded-md">Sync ngay</button>
                    @endif
                    <button wire:click="openEdit({{ $connection->id }})" class="text-xs font-semibold text-ink/60 border border-gold-200 hover:bg-gold-50 px-3 py-1.5 rounded-md">Sửa</button>
                    <button wire:click="toggleActive({{ $connection->id }})" class="text-xs font-semibold {{ $connection->active ? 'text-red-700 border-red-200 hover:bg-red-50' : 'text-green-700 border-green-200 hover:bg-green-50' }} border px-3 py-1.5 rounded-md">
                        {{ $connection->active ? 'Tắt' : 'Bật' }}
                    </button>
                </div>

                @if ($connection->type === 'webhook')
                    <div class="mt-3 bg-cream border border-gold-100 rounded-lg px-4 py-3 flex flex-wrap items-center gap-3">
                        <code class="flex-1 text-xs font-mono text-ink/70 break-all">POST {{ url('/webhook/lead/' . $connection->webhook_token) }}</code>
                        <button onclick="navigator.clipboard.writeText('{{ url('/webhook/lead/' . $connection->webhook_token) }}'); this.textContent='Đã copy ✓'"
                                class="text-xs font-semibold text-gold-700 border border-gold-300 hover:bg-gold-50 px-3 py-1.5 rounded-md">Copy URL</button>
                        <button wire:click="regenerateToken({{ $connection->id }})" wire:confirm="Token cũ sẽ hết hiệu lực ngay. Tạo mới?"
                                class="text-xs font-semibold text-red-700 border border-red-200 hover:bg-red-50 px-3 py-1.5 rounded-md">Đổi token</button>
                    </div>
                @endif
            </div>
        @empty
            <div class="bg-white border border-gold-200 rounded-xl p-10 text-center text-ink/40">Chưa có kết nối nào.</div>
        @endforelse
    </div>

    {{-- Nhật ký ingest gần nhất --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card">
        <div class="px-6 py-4 border-b border-gold-100"><h2 class="font-bold">Nhật ký kết nối gần nhất</h2></div>
        <div class="divide-y divide-gold-50">
            @forelse ($recentLogs as $log)
                <div class="px-6 py-2.5 flex items-center gap-3 text-xs">
                    <span class="font-mono {{ $log->http_status >= 400 ? 'text-red-600' : 'text-green-700' }} font-bold w-10">{{ $log->http_status }}</span>
                    <span class="w-20 text-ink/50">{{ $log->source_type }}</span>
                    <span class="flex-1 font-mono text-ink/60 truncate">{{ json_encode($log->response, JSON_UNESCAPED_UNICODE) }}</span>
                    <span class="text-ink/40">{{ $log->created_at?->format('d/m H:i') }}</span>
                </div>
            @empty
                <p class="px-6 py-8 text-sm text-ink/40 text-center">Chưa có log nào.</p>
            @endforelse
        </div>
    </div>

    {{-- Modal --}}
    @if ($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/40" wire:click="$set('showModal', false)"></div>
            <div class="relative bg-white rounded-xl shadow-xl border border-gold-200 w-full max-w-xl p-7 max-h-[90vh] overflow-y-auto">
                <h3 class="text-xl font-bold mb-5">{{ $editingId ? 'Sửa kết nối' : 'Thêm kết nối' }}</h3>
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">Loại</label>
                        <select wire:model.live="type" @disabled($editingId) class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                            @foreach ($typeLabels as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">Tên kết nối</label>
                    <input type="text" wire:model="name" placeholder="VD: Landing khai trương / Form FB chính" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                    @error('name')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>

                @if ($type !== 'webhook')
                    <div class="border border-gold-100 rounded-lg p-4 mb-4 space-y-3">
                        <h4 class="font-bold text-sm">Credentials (mã hóa khi lưu)</h4>
                        @if ($type === 'facebook_ads')
                            <div>
                                <label class="block text-xs text-ink/50 mb-1">Page Access Token (quyền leads_retrieval)</label>
                                <input type="password" wire:model="credToken" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm font-mono focus:outline-none focus:border-gold-500">
                            </div>
                            <div>
                                <label class="block text-xs text-ink/50 mb-1">Lead Form ID</label>
                                <input type="text" wire:model="credFormId" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm font-mono focus:outline-none focus:border-gold-500">
                            </div>
                        @else
                            <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-3 py-2">
                                Adapter {{ $typeLabels[$type] }} cần tài khoản Ads thật để tích hợp — khung code đã sẵn, điền credentials khi có.
                            </p>
                            <div>
                                <label class="block text-xs text-ink/50 mb-1">Access Token</label>
                                <input type="password" wire:model="credToken" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm font-mono focus:outline-none focus:border-gold-500">
                            </div>
                        @endif
                    </div>
                @endif

                <div class="border border-gold-100 rounded-lg p-4 mb-4">
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="font-bold text-sm">Map field nguồn → field chuẩn</h4>
                        <button wire:click="addMappingRow" class="text-xs font-semibold text-gold-700 hover:underline">+ thêm</button>
                    </div>
                    <p class="text-xs text-ink/50 mb-3">Bỏ trống nếu nguồn đã gửi đúng tên field chuẩn (name, phone, camp...).</p>
                    <div class="space-y-2">
                        @foreach ($mappingRows as $index => $row)
                            <div class="flex items-center gap-2" wire:key="map-{{ $index }}">
                                <select wire:model="mappingRows.{{ $index }}.target" class="flex-1 border border-gold-200 rounded-md px-2.5 py-1.5 text-sm bg-white focus:outline-none focus:border-gold-500">
                                    <option value="">— field chuẩn —</option>
                                    @foreach (['name', 'phone', 'received_date', 'page', 'camp', 'insight', 'link', 'ad_source', 'region', 'note'] as $field)
                                        <option value="{{ $field }}">{{ $field }}</option>
                                    @endforeach
                                </select>
                                <span class="text-ink/30">←</span>
                                <input type="text" wire:model="mappingRows.{{ $index }}.source" placeholder="tên field bên nguồn" class="flex-1 border border-gold-200 rounded-md px-2.5 py-1.5 text-sm focus:outline-none focus:border-gold-500">
                                <button wire:click="removeMappingRow({{ $index }})" class="text-red-400 hover:text-red-600 text-lg leading-none px-1">×</button>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="flex justify-end gap-3">
                    <button wire:click="$set('showModal', false)" class="text-sm text-ink/60 px-4 py-2">Hủy</button>
                    <button wire:click="save" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-6 py-2 rounded-md">Lưu kết nối</button>
                </div>
            </div>
        </div>
    @endif
</div>
