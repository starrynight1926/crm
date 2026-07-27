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
    public string $tab = 'roles'; // roles | policies | overdue | jobs

    public ?int $editOrgId = null;
    public ?int $editRecallDays = null;
    public ?int $editEscalateDays = null;
    public bool $editAllowPermanent = true;
    /** @var string[] Danh sách điều kiện recall bổ sung: no_activity / no_booking / no_progress */
    public array $editRecallConditions = [];

    // Cấu hình job "recall idle booking" (miền Nam ban đầu, mở rộng branch được).
    public bool $idleRecallEnabled = true;
    public int $idleRecallDays = 1;
    public string $idleRecallBranch = 'branch-hcm';

    public function mountJobSettings(): void
    {
        $this->idleRecallEnabled = \App\Models\AppSetting::get('idle_booking_recall_enabled', '1') === '1';
        $this->idleRecallDays = (int) \App\Models\AppSetting::get('idle_booking_recall_days', '1');
        $this->idleRecallBranch = \App\Models\AppSetting::get('idle_booking_recall_branch', 'branch-hcm');
    }

    public function saveIdleRecall(): void
    {
        $this->validate([
            'idleRecallDays' => 'required|integer|min:1|max:60',
            'idleRecallBranch' => 'required|string|max:60',
        ]);
        \App\Models\AppSetting::set('idle_booking_recall_enabled', $this->idleRecallEnabled ? '1' : '0');
        \App\Models\AppSetting::set('idle_booking_recall_days', (string) $this->idleRecallDays);
        \App\Models\AppSetting::set('idle_booking_recall_branch', $this->idleRecallBranch);
        session()->flash('status', 'Đã lưu cấu hình recall idle booking. Job cron chạy hằng ngày 02:30 sẽ dùng giá trị mới.');
    }

    public function switchTab(string $t): void
    {
        abort_unless(in_array($t, ['roles', 'policies', 'overdue', 'jobs']), 422);
        $this->tab = $t;
        $this->editOrgId = null;
        if ($t === 'jobs') $this->mountJobSettings();
    }

    public function editPolicy(int $orgId): void
    {
        $this->editOrgId = $orgId;
        $existing = DB::table('recall_policies')->where('org_unit_id', $orgId)->first();
        if ($existing) {
            $this->editRecallDays = $existing->recall_after_days;
            $this->editEscalateDays = $existing->escalate_after_days;
            $this->editAllowPermanent = (bool) $existing->allow_permanent_assignment;
            $conds = $existing->recall_conditions ? json_decode($existing->recall_conditions, true) : [];
            $this->editRecallConditions = is_array($conds) ? $conds : [];
        } else {
            // Chưa có config riêng → prefill từ resolved policy (ancestor gần nhất hoặc system default).
            $org = OrgUnit::find($orgId);
            $resolved = $org ? RecallPolicyResolver::for($org) : null;
            $this->editRecallDays = $resolved['recall_after_days'] ?? 7;
            $this->editEscalateDays = $resolved['escalate_after_days'] ?? 3;
            $this->editAllowPermanent = (bool) ($resolved['allow_permanent_assignment'] ?? true);
            $this->editRecallConditions = $resolved['recall_conditions'] ?? [];
        }
    }

    public function savePolicy(): void
    {
        abort_unless(auth()->user()->hasPermission('ops.manage'), 403);
        $this->validate([
            'editRecallDays' => 'nullable|integer|min:1|max:365',
            'editEscalateDays' => 'nullable|integer|min:1|max:365',
        ]);
        $allowedConds = array_keys(RecallPolicyResolver::CONDITION_LABELS);
        $conds = array_values(array_intersect($this->editRecallConditions, $allowedConds));
        DB::table('recall_policies')->updateOrInsert(
            ['org_unit_id' => $this->editOrgId],
            [
                'recall_after_days' => $this->editRecallDays,
                'escalate_after_days' => $this->editEscalateDays,
                'allow_permanent_assignment' => $this->editAllowPermanent,
                'recall_conditions' => $conds ? json_encode($conds) : null,
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
                'lead.distribute_to_team' => ['CM cơ sở: chia kho công ty → team', $this->usersWithPermission('lead.distribute_to_team')],
                'lead.distribute_to_sale' => ['CM team: chia kho team → sale', $this->usersWithPermission('lead.distribute_to_sale')],
                'lead.distribute_booking' => ['Chia số kho Booking (phase)', $this->usersWithPermission('lead.distribute_booking')],
                'lead.distribute_sale' => ['Chia số kho Sale (phase)', $this->usersWithPermission('lead.distribute_sale')],
                'lead.distribute_ctv' => ['Phân bổ nguồn CTV', $this->usersWithPermission('lead.distribute_ctv')],
                'lead.read_booking' => ['Xem info Cập nhật khi phase Booking (readonly)', $this->usersWithPermission('lead.read_booking')],
                'lead.update_booking' => ['Sửa info khi Booking phase', $this->usersWithPermission('lead.update_booking')],
                'lead.book_action' => ['Bấm nút Đặt booking', $this->usersWithPermission('lead.book_action')],
                'lead.update_sale' => ['Sửa info khi Sale phase', $this->usersWithPermission('lead.update_sale')],
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
        @foreach (['roles' => 'Phân bổ', 'policies' => 'Thời gian recall/escalate', 'overdue' => 'Overdue booking', 'jobs' => 'Job cron'] as $key => $label)
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
        <div class="bg-blue-50 border border-blue-200 rounded-md px-4 py-3 mb-4 text-sm text-blue-900">
            <p class="font-semibold mb-1">Điều kiện kích hoạt "Thu hồi":</p>
            <ul class="list-disc list-inside space-y-0.5 text-xs">
                <li><strong>Deadline</strong>: sau <strong>N ngày</strong> kể từ `assigned_at`, `recall_at` hết hạn. Cron chạy mỗi giờ.</li>
                <li><strong>Điều kiện bổ sung</strong> (tick khi Sửa): chỉ recall nếu THỎA HẾT các điều kiện đã tick:
                    <ul class="list-disc list-inside ml-4 mt-1">
                        <li><em>Không cập nhật trường nào</em>: sale chưa động vào lead từ lúc chia (last_care_at ≤ assigned_at).</li>
                        <li><em>Chưa đặt lịch booking</em>: booking_status không phải booked/khách_đã_tới/tới_trễ/đã_xong.</li>
                        <li><em>Chưa tiến triển phân loại</em>: vẫn ở Mới/Lead/Missed/Gọi lại sau/KLLD.</li>
                    </ul>
                </li>
                <li>Không tick điều kiện nào → chỉ dùng deadline (hành vi cũ). Có tick → deadline hết vẫn có thể bị bỏ qua nếu sale đang cày.</li>
                <li>CM tick "Chia vĩnh viễn" ở form chia → hoàn toàn miễn recall.</li>
                <li>Team con không cấu hình → thừa hưởng từ ancestor gần nhất.</li>
            </ul>
        </div>
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
                                <td class="px-4 py-3 align-top">
                                    <input type="number" wire:model="editRecallDays" min="1" max="365" class="w-24 border border-gold-200 rounded-md px-2 py-1 text-sm">
                                    <div class="mt-2 space-y-1">
                                        <p class="text-[10px] font-bold uppercase text-ink/50">Điều kiện bổ sung (tất cả phải khớp):</p>
                                        @foreach (\App\Services\RecallPolicyResolver::CONDITION_LABELS as $condKey => $condLabel)
                                            <label class="flex items-start gap-1.5 text-xs cursor-pointer">
                                                <input type="checkbox" wire:model="editRecallConditions" value="{{ $condKey }}" class="rounded border-gold-300 w-3.5 h-3.5 mt-0.5">
                                                <span>{{ $condLabel }}</span>
                                            </label>
                                        @endforeach
                                        <p class="text-[10px] text-ink/40 italic">Không tick nào = chỉ dùng deadline (như cũ).</p>
                                    </div>
                                </td>
                                <td class="px-4 py-3 align-top"><input type="number" wire:model="editEscalateDays" min="1" max="365" class="w-24 border border-gold-200 rounded-md px-2 py-1 text-sm"></td>
                                <td class="px-4 py-3 align-top"><input type="checkbox" wire:model="editAllowPermanent" class="accent-gold-600"></td>
                                <td class="px-4 py-3 text-ink/50 align-top">—</td>
                                <td class="px-4 py-3 text-right space-x-1 align-top">
                                    <button wire:click="savePolicy" class="text-xs font-semibold bg-gold-600 text-white px-3 py-1.5 rounded-md">Lưu</button>
                                    <button wire:click="$set('editOrgId', null)" class="text-xs text-ink/50">Hủy</button>
                                </td>
                            @else
                                <td class="px-4 py-3">
                                    <div>{{ $p?->recall_after_days ?? '—' }}</div>
                                    @php
                                        $pConds = $p && $p->recall_conditions ? json_decode($p->recall_conditions, true) : [];
                                    @endphp
                                    @if (! empty($pConds))
                                        <div class="text-[10px] text-blue-700 mt-0.5">
                                            @foreach ($pConds as $c)
                                                <div>+ {{ \App\Services\RecallPolicyResolver::CONDITION_LABELS[$c] ?? $c }}</div>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
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
    @elseif ($tab === 'overdue')
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

    @if ($tab === 'jobs')
        <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6 max-w-3xl">
            <h2 class="font-bold text-gold-700 mb-1">Recall idle booking (miền Nam)</h2>
            <p class="text-xs text-ink/50 mb-4">
                Lead ở phase Booking được chia cho nhân viên booking. Sau N ngày mà <strong>không update</strong> +
                <strong>không có lịch đặt</strong> → tự thu hồi về kho team booking để CM chia lại. Job chạy hằng ngày lúc 02:30.
            </p>
            <div class="space-y-4">
                <label class="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="checkbox" wire:model="idleRecallEnabled" class="rounded border-gold-300 text-gold-600 w-4 h-4">
                    <span class="font-semibold">Bật job này</span>
                </label>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Số ngày idle tối đa</label>
                        <input type="number" min="1" max="60" wire:model="idleRecallDays"
                               class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm">
                        <p class="text-xs text-ink/50 mt-1">Sau bấy nhiêu ngày không action → recall.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Branch áp dụng</label>
                        <select wire:model="idleRecallBranch" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm">
                            @foreach (\App\Models\OrgUnit::where('parent_id', \App\Models\OrgUnit::where('code','company')->value('id'))->orderBy('name')->get() as $_b)
                                <option value="{{ $_b->code }}">{{ $_b->name }} ({{ $_b->code }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <button wire:click="saveIdleRecall" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-5 py-2.5 rounded-md">Lưu cấu hình</button>
            </div>
        </div>
    @endif
</div>
