<?php

use App\Models\NotificationPref;
use App\Models\Role;
use App\Services\NotificationDispatcher;
use App\Support\NotificationEvents;
use Livewire\Component;

new class extends Component
{
    public ?int $roleId = null;
    /** [event_key => scope] cho role đang chọn */
    public array $scopes = [];

    public function mount(): void
    {
        $first = Role::orderBy('id')->first();
        if ($first) $this->selectRole($first->id);
    }

    public function selectRole(int $id): void
    {
        $this->roleId = $id;
        $rows = NotificationPref::where('role_id', $id)->pluck('scope', 'event_key')->all();
        $this->scopes = [];
        foreach (NotificationEvents::keys() as $key) {
            $this->scopes[$key] = $rows[$key] ?? NotificationPref::SCOPE_OFF;
        }
    }

    public function updateScope(string $key, string $scope): void
    {
        if (! $this->roleId) return;
        if (! in_array($scope, NotificationPref::SCOPES, true)) return;

        $this->scopes[$key] = $scope;

        if ($scope === NotificationPref::SCOPE_OFF) {
            NotificationPref::where('role_id', $this->roleId)->where('event_key', $key)->delete();
        } else {
            NotificationPref::updateOrCreate(
                ['role_id' => $this->roleId, 'event_key' => $key],
                ['scope' => $scope]
            );
        }
        NotificationDispatcher::flushCache();
    }

    public function setAll(string $scope): void
    {
        if (! $this->roleId || ! in_array($scope, NotificationPref::SCOPES, true)) return;
        foreach (NotificationEvents::keys() as $key) {
            $this->updateScope($key, $scope);
        }
    }

    public function with(): array
    {
        return [
            'roles'  => Role::orderBy('id')->get(),
            'groups' => NotificationEvents::groups(),
            'currentRole' => $this->roleId ? Role::find($this->roleId) : null,
            'scopeOptions' => [
                NotificationPref::SCOPE_OFF      => ['Tắt',       'text-ink/40'],
                NotificationPref::SCOPE_OWN      => ['Cá nhân',   'text-blue-700'],
                NotificationPref::SCOPE_TEAM     => ['Team',      'text-emerald-700'],
                NotificationPref::SCOPE_FACILITY => ['Cơ sở',     'text-amber-700'],
                NotificationPref::SCOPE_ALL      => ['Tất cả',    'text-gold-700'],
            ],
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gold-700">Thiết lập thông báo</h1>
        <p class="text-sm text-ink/60">Chọn từng vai trò → với mỗi loại thông báo chọn phạm vi: <strong>Tắt</strong> / <strong>Cá nhân</strong> (chỉ khi user là owner) / <strong>Team</strong> (lead thuộc phòng user) / <strong>Cơ sở</strong> (lead thuộc cơ sở user) / <strong>Tất cả</strong>.</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[280px_1fr] gap-6">
        <div class="bg-white border border-gold-200 rounded-lg overflow-hidden">
            <div class="px-4 py-3 border-b border-gold-100 font-semibold text-sm">Danh sách vai trò</div>
            <ul class="divide-y divide-gold-50 max-h-[640px] overflow-y-auto">
                @forelse ($roles as $r)
                    <li>
                        <button wire:click="selectRole({{ $r->id }})"
                                class="w-full text-left px-4 py-3 hover:bg-gold-50 flex items-center gap-3 {{ $roleId === $r->id ? 'bg-gold-50' : '' }}">
                            <div class="w-8 h-8 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center text-xs font-bold shrink-0">
                                {{ mb_substr($r->name, 0, 1) }}
                            </div>
                            <div class="min-w-0">
                                <div class="font-semibold text-sm truncate">{{ $r->name }}</div>
                                @if ($r->description)
                                    <div class="text-xs text-ink/50 truncate">{{ $r->description }}</div>
                                @endif
                            </div>
                        </button>
                    </li>
                @empty
                    <li class="px-4 py-6 text-sm text-ink/40 text-center">Chưa có vai trò nào.</li>
                @endforelse
            </ul>
        </div>

        <div class="bg-white border border-gold-200 rounded-lg">
            @if (! $currentRole)
                <div class="p-12 text-center text-ink/50">Chọn một vai trò ở cột bên trái.</div>
            @else
                <div class="px-6 py-4 border-b border-gold-100 flex items-center justify-between gap-3 flex-wrap">
                    <div>
                        <div class="text-xs uppercase tracking-wide text-ink/50">Đang thiết lập cho</div>
                        <div class="text-lg font-bold text-gold-700">{{ $currentRole->name }}</div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-ink/50">Áp cho tất cả:</span>
                        @foreach ($scopeOptions as $s => [$label, $cls])
                            <button wire:click="setAll('{{ $s }}')"
                                    class="px-2.5 py-1 text-xs border border-gold-200 rounded hover:bg-gold-50 {{ $cls }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="p-6 space-y-6">
                    @foreach ($groups as $groupName => $events)
                        <div>
                            <h3 class="text-xs uppercase tracking-wide text-ink/50 mb-3 font-semibold">{{ $groupName }}</h3>
                            <div class="space-y-2">
                                @foreach ($events as $key => $meta)
                                    @php $cur = $scopes[$key] ?? 'off'; @endphp
                                    <div class="flex items-start gap-3 p-3 rounded-lg border border-gold-100 hover:bg-gold-50/30">
                                        <div class="flex-1 min-w-0">
                                            <div class="font-semibold text-sm">{{ $meta['label'] }}</div>
                                            <div class="text-xs text-ink/60">{{ $meta['desc'] }}</div>
                                            <code class="text-[10px] text-ink/40 font-mono">{{ $key }}</code>
                                        </div>
                                        <select wire:change="updateScope('{{ $key }}', $event.target.value)"
                                                class="shrink-0 border-gold-200 rounded-md text-sm font-semibold {{ $scopeOptions[$cur][1] ?? '' }}">
                                            @foreach ($scopeOptions as $s => [$label, $cls])
                                                <option value="{{ $s }}" @selected($cur === $s)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
