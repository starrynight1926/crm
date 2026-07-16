<?php

use App\Models\Assignment;
use App\Models\Lead;
use App\Models\OrgUnit;
use App\Models\Permission;
use App\Models\User;
use App\Services\RecallPolicyResolver;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')]
#[Title('Quy tắc vận hành')]
class extends Component {
    public string $tab = 'roles'; // roles | policies | overdue

    public ?int $editOrgId = null;
    public ?int $editRecallDays = null;
    public ?int $editEscalateDays = null;
    public bool $editAllowPermanent = true;

    public function switchTab(string $t): void
    {
        abort_unless(in_array($t, ['roles', 'policies', 'overdue']), 422);
        $this->tab = $t;
        $this->editOrgId = null;
    }

    public function editPolicy(int $orgId): void
    {
        $this->editOrgId = $orgId;
        $existing = DB::table('recall_policies')->where('org_unit_id', $orgId)->first();
        $this->editRecallDays = $existing?->recall_after_days;
        $this->editEscalateDays = $existing?->escalate_after_days;
        $this->editAllowPermanent = (bool) ($existing?->allow_permanent_assignment ?? true);
    }

    public function savePolicy(): void
    {
        abort_unless(auth()->user()->hasPermission('ops.manage'), 403);
        $this->validate([
            'editRecallDays' => 'nullable|integer|min:1|max:365',
            'editEscalateDays' => 'nullable|integer|min:1|max:365',
        ]);
        DB::table('recall_policies')->updateOrInsert(
            ['org_unit_id' => $this->editOrgId],
            [
                'recall_after_days' => $this->editRecallDays,
                'escalate_after_days' => $this->editEscalateDays,
                'allow_permanent_assignment' => $this->editAllowPermanent,
                'set_by' => auth()->id(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $this->editOrgId = null;
        session()->flash('status', 'Đã lưu Quy tắc.');
    }

    public function clearPolicy(int $orgId): void
    {
        abort_unless(auth()->user()->hasPermission('ops.manage'), 403);
        DB::table('recall_policies')->where('org_unit_id', $orgId)->delete();
        $this->editOrgId = null;
        session()->flash('status', 'Đã xóa cấu hình node.');
    }

    private function usersWithPermission(string $permKey): array
    {
        $perm = Permission::firstWhere('key', $permKey);
        if (! $perm) return [];
        return User::query()
            ->whereHas('assignments.role.permissions', fn ($q) => $q->where('permissions.id', $perm->id))
            ->with(['assignments.role', 'assignments.orgUnit'])
            ->get()
            ->all();
    }

    public function with(): array
    {
        $data = [];
        if ($this->tab === 'roles') {
            $data['permMatrix'] = [
                'lead.distribute_team' => ['Chia số cho team', $this->usersWithPermission('lead.distribute_team')],
                'lead.distribute_ctv' => ['Phân bổ nguồn CTV', $this->usersWithPermission('lead.distribute_ctv')],
                'lead.approve_source' => ['Duyệt Khách tự đến', $this->usersWithPermission('lead.approve_source')],
                'lead.recall' => ['Thu hồi số', $this->usersWithPermission('lead.recall')],
            ];
        } elseif ($this->tab === 'policies') {
            $data['orgs'] = OrgUnit::orderBy('path')->get();
            $data['policies'] = DB::table('recall_policies')->get()->keyBy('org_unit_id');
        } elseif ($this->tab === 'overdue') {
            $data['overdueLeads'] = Lead::whereNotNull('overdue_marked_at')
                ->orderByDesc('overdue_marked_at')
                ->limit(100)
                ->get();
        }
        return $data;
    }
}; ?>

<div class="max-w-6xl mx-auto px-6 py-8">
    <div class="mb-6">
        <h1 class="text-3xl font-bold mb-1">Quy tắc vận hành</h1>
        <p class="text-sm text-ink/60">Giám sát phân bổ, cấu hình thời gian thu hồi/escalate, danh sách overdue.</p>
    </div>

    @if (session('status'))
        <div class="mb-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-md px-4 py-2 text-sm">{{ session('status') }}</div>
    @endif

    <div class="border-b border-gold-200 mb-5 flex gap-1 text-sm font-semibold uppercase tracking-wide">
        @foreach (['roles' => 'Phân bổ', 'policies' => 'Thời gian recall/escalate', 'overdue' => 'Overdue booking'] as $key => $label)
            <button wire:click="switchTab('{{ $key }}')" class="px-4 py-3 border-b-2 -mb-px {{ $tab === $key ? 'border-gold-600 text-gold-700' : 'border-transparent text-ink/50 hover:text-gold-700' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'roles')
        <div class="space-y-4">
            @foreach ($permMatrix as $key => [$label, $users])
                <div class="bg-white border border-gold-200 rounded-xl shadow-card p-5">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <div class="font-semibold">{{ $label }}</div>
                            <div class="text-xs text-ink/50">{{ $key }}</div>
                        </div>
                        <div class="text-xs text-ink/60">{{ count($users) }} user</div>
                    </div>
                    @if (count($users) === 0)
                        <p class="text-sm text-ink/50 italic">Chưa có user nào có quyền này.</p>
                    @else
                        <div class="flex flex-wrap gap-2">
                            @foreach ($users as $u)
                                <span class="inline-flex items-center gap-2 bg-gold-50 border border-gold-200 rounded-full px-3 py-1 text-xs">
                                    <span class="font-semibold">{{ $u->name }}</span>
                                    <span class="text-ink/50">— {{ $u->assignments->pluck('role.name')->filter()->unique()->implode(', ') }}</span>
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @elseif ($tab === 'policies')
        <div class="bg-white border border-gold-200 rounded-xl shadow-card overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gold-50 text-ink/70 text-xs uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-3 text-left">Cấp / Team</th>
                        <th class="px-4 py-3 text-left">Thu hồi (ngày)</th>
                        <th class="px-4 py-3 text-left">Escalate (ngày)</th>
                        <th class="px-4 py-3 text-left">Cho phép "Chia vĩnh viễn"</th>
                        <th class="px-4 py-3 text-left">Hiệu lực (resolved)</th>
                        <th class="px-4 py-3 text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gold-100">
                    @foreach ($orgs as $org)
                        @php($p = $policies[$org->id] ?? null)
                        @php($resolved = \App\Services\RecallPolicyResolver::for($org))
                        <tr class="{{ $editOrgId === $org->id ? 'bg-gold-50' : '' }}">
                            <td class="px-4 py-3">
                                <span style="padding-left:{{ $org->depth * 12 }}px" class="font-semibold">{{ $org->name }}</span>
                                <div class="text-xs text-ink/50">{{ $org->code }}</div>
                            </td>
                            @if ($editOrgId === $org->id)
                                <td class="px-4 py-3"><input type="number" wire:model="editRecallDays" min="1" max="365" class="w-24 border border-gold-200 rounded-md px-2 py-1 text-sm"></td>
                                <td class="px-4 py-3"><input type="number" wire:model="editEscalateDays" min="1" max="365" class="w-24 border border-gold-200 rounded-md px-2 py-1 text-sm"></td>
                                <td class="px-4 py-3"><input type="checkbox" wire:model="editAllowPermanent" class="accent-gold-600"></td>
                                <td class="px-4 py-3 text-ink/50">—</td>
                                <td class="px-4 py-3 text-right space-x-1">
                                    <button wire:click="savePolicy" class="text-xs font-semibold bg-gold-600 text-white px-3 py-1.5 rounded-md">Lưu</button>
                                    <button wire:click="$set('editOrgId', null)" class="text-xs text-ink/50">Hủy</button>
                                </td>
                            @else
                                <td class="px-4 py-3">{{ $p?->recall_after_days ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $p?->escalate_after_days ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $p ? ($p->allow_permanent_assignment ? 'Bật' : 'Tắt') : '—' }}</td>
                                <td class="px-4 py-3 text-xs text-ink/60">
                                    R: {{ $resolved['recall_after_days'] ?? '—' }}d ·
                                    E: {{ $resolved['escalate_after_days'] ?? '—' }}d ·
                                    {{ $resolved['allow_permanent_assignment'] ? 'Cho phép vĩnh viễn' : 'Cấm vĩnh viễn' }}
                                    <div class="text-ink/40">Nguồn: {{ $resolved['source'] }}</div>
                                </td>
                                <td class="px-4 py-3 text-right space-x-1">
                                    <button wire:click="editPolicy({{ $org->id }})" class="text-xs font-semibold border border-gold-300 text-gold-700 px-3 py-1.5 rounded-md">Sửa</button>
                                    @if ($p)
                                        <button wire:click="clearPolicy({{ $org->id }})" onclick="return confirm('Xóa cấu hình node này?')" class="text-xs text-red-600">Xóa</button>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-xs text-ink/50 mt-3">Ancestor cấp cao nhất có cấu hình sẽ thắng — team con bị buộc theo phòng cha nếu phòng cha đã set.</p>
    @else
        <div class="bg-white border border-gold-200 rounded-xl shadow-card overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gold-50 text-ink/70 text-xs uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-3 text-left">Khách hàng</th>
                        <th class="px-4 py-3 text-left">Nhóm nguồn</th>
                        <th class="px-4 py-3 text-left">Ngày vào</th>
                        <th class="px-4 py-3 text-left">Đánh dấu overdue</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gold-100">
                    @forelse ($overdueLeads as $l)
                        <tr>
                            <td class="px-4 py-3"><a href="{{ route('leads.show', $l) }}" class="font-semibold text-gold-700 hover:underline">{{ $l->name }}</a><div class="text-xs text-ink/50">{{ $l->code }}</div></td>
                            <td class="px-4 py-3">{{ \App\Models\Lead::SOURCE_GROUPS[$l->source_group] ?? '—' }}</td>
                            <td class="px-4 py-3 text-ink/60">{{ $l->created_at?->format('d/m/Y') }}</td>
                            <td class="px-4 py-3 text-ink/60">{{ $l->overdue_marked_at?->format('d/m/Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-ink/50">Không có lead overdue.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
