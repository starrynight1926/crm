<?php

use App\Models\Assignment;
use App\Models\DailyAttendance;
use App\Models\OrgUnit;
use App\Models\PoolUnit;
use App\Models\UpsConfig;
use App\Models\UpsDailyConfirm;
use App\Models\User;
use App\Services\Ups\UpsBucketResolver;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

new class extends Component
{
    /** [pool_unit_id => user_id] */
    public array $picker = [];
    /** [pool_unit_id => 'greet' | 'receive'] Vị trí: Tiếp đón / Nhận số. */
    public array $slot = [];
    /** [pool_unit_id => 'auto' | 'A' | 'B' | 'C' | 'OFF'] Tier — chỉ dùng khi slot=greet. */
    public array $tier = [];

    /** Read-only mode (dùng cho /ups-today) — ẩn form check-in, override, chốt. */
    public bool $readOnly = false;

    public function mount(bool $readOnly = false): void
    {
        $this->readOnly = $readOnly;
        if (! $readOnly) {
            abort_unless(auth()->user()?->hasPermission('ups.view'), 403);
        } else {
            abort_unless(auth()->check(), 403);
        }
    }

    private function branchesForUser(): array
    {
        $user = auth()->user();
        if ($user->hasPermission('user.manage')) {
            return PoolUnit::where('kind', 'branch')->orderBy('sort')->get()->all();
        }
        // 2026-08-05 fix: trước chỉ check direct assignment.org_unit_id → user assignment ở team-nhap-lead
        // (không map trực tiếp tới branch) bị miss. Giờ gom TOÀN BỘ ANCESTORS của assignment → tra org_pool_map.
        $ancestorOrgIds = [];
        foreach ($user->effectiveAssignments() as $assignment) {
            foreach (array_filter(explode('/', trim((string) $assignment->orgUnit->path, '/'))) as $seg) {
                $ancestorOrgIds[(int) $seg] = true;
            }
        }
        if (! $ancestorOrgIds) return [];

        $mappedPoolIds = DB::table('org_pool_map')->whereIn('org_unit_id', array_keys($ancestorOrgIds))->pluck('pool_unit_id')->all();
        if (! $mappedPoolIds) return [];

        // Từ pool đã map, đi lên tới kind=branch (nếu map là facility/department → lấy cha branch).
        $branchIds = [];
        foreach (PoolUnit::whereIn('id', $mappedPoolIds)->get() as $p) {
            $n = $p;
            while ($n && $n->kind !== 'branch') $n = $n->parent;
            if ($n) $branchIds[$n->id] = true;
        }
        if (! $branchIds) return [];

        return PoolUnit::whereIn('id', array_keys($branchIds))->orderBy('sort')->get()->all();
    }

    /** Sale user thuộc riêng 1 cơ sở (facility pool) — dựa org_pool_map cấp facility. */
    private function saleUsersOfFacility(PoolUnit $facility): \Illuminate\Support\Collection
    {
        $orgIds = DB::table('org_pool_map')->where('pool_unit_id', $facility->id)->pluck('org_unit_id')->all();
        if (! $orgIds) {
            return collect();
        }
        $subtreeIds = [];
        foreach (OrgUnit::whereIn('id', $orgIds)->get() as $org) {
            $subtreeIds = array_merge($subtreeIds, $org->subtreeIds());
        }
        $subtreeIds = array_unique($subtreeIds);
        if (! $subtreeIds) {
            return collect();
        }

        return User::query()
            ->whereHas('assignments', function ($q) use ($subtreeIds) {
                $q->whereIn('org_unit_id', $subtreeIds)
                    ->whereHas('role', fn ($r) => $r->where('name', 'like', '%ale%'));
            })
            ->orderBy('name')->get();
    }

    /** API tân — nhận đủ 4 tham số từ Alpine, không dùng $picker/$slot/$tier array nữa. */
    public function checkInFull(int $facilityPoolUnitId, int|string $saleId, string $slot, string $tier = 'auto'): void
    {
        $this->picker[$facilityPoolUnitId] = (string) $saleId;
        $this->slot[$facilityPoolUnitId] = $slot;
        $this->tier[$facilityPoolUnitId] = $tier;
        $this->checkIn($facilityPoolUnitId);
    }

    public function checkIn(int $facilityPoolUnitId): void
    {
        abort_unless(auth()->user()?->hasPermission('ups.checkin'), 403);
        $saleId = (int) ($this->picker[$facilityPoolUnitId] ?? 0);
        $slot = $this->slot[$facilityPoolUnitId] ?? '';
        $tier = $this->tier[$facilityPoolUnitId] ?? 'auto';

        if ($saleId <= 0) {
            session()->flash('ups_msg', 'Chưa chọn sale.');

            return;
        }
        if (! in_array($slot, ['greet', 'receive'], true)) {
            session()->flash('ups_msg', 'Chưa chọn vị trí (Tiếp đón / Nhận số).');

            return;
        }

        $facility = PoolUnit::where('id', $facilityPoolUnitId)->where('kind', 'facility')->firstOrFail();
        $today = now()->toDateString();
        $now = now();

        $existing = DailyAttendance::where('user_id', $saleId)->whereDate('work_date', $today)->first();

        if ($slot === 'receive') {
            // MKT: nếu đã check-in rồi thì chỉ bật is_mkt, không tạo record mới
            if ($existing) {
                if ($existing->is_mkt) {
                    session()->flash('ups_msg', 'Sale này đã có trong MKT List.');
                } else {
                    $existing->update(['is_mkt' => true]);
                    session()->flash('ups_msg', $existing->user->name . ' đã được thêm vào MKT List.');
                }
                $this->resetPicker($facilityPoolUnitId);
                return;
            }
            // Chưa check-in → tạo mới, chỉ có MKT flag, chưa có bucket tiếp đón
            DailyAttendance::create([
                'facility_pool_unit_id' => $facility->id,
                'user_id' => $saleId,
                'work_date' => $today,
                'checkin_at' => $now,
                'list_bucket' => null,
                'is_mkt' => true,
                'is_off' => false,
            ]);
            $this->resetPicker($facilityPoolUnitId);
            return;
        }

        // Tiếp đón (greet)
        if ($existing) {
            if ($existing->list_bucket) {
                session()->flash('ups_msg', 'Sale này đã check-in hôm nay (bucket ' . $existing->list_bucket . ').');
                $this->resetPicker($facilityPoolUnitId);
                return;
            }
            // Đã có record (chỉ MKT) → gán thêm bucket tiếp đón
            $bucket = $tier === 'auto'
                ? app(UpsBucketResolver::class)->resolve($now, $facility->id)
                : $tier;
            $existing->update([
                'list_bucket' => $bucket,
                'is_off' => $bucket === 'OFF',
                'checkin_at' => $existing->checkin_at ?? $now,
            ]);
            $this->resetPicker($facilityPoolUnitId);
            return;
        }

        $bucket = $tier === 'auto'
            ? app(UpsBucketResolver::class)->resolve($now, $facility->id)
            : $tier;

        DailyAttendance::create([
            'facility_pool_unit_id' => $facility->id,
            'user_id' => $saleId,
            'work_date' => $today,
            'checkin_at' => $now,
            'list_bucket' => $bucket,
            'is_off' => $bucket === 'OFF',
        ]);
        $this->resetPicker($facilityPoolUnitId);
    }

    private function resetPicker(int $facilityPoolUnitId): void
    {
        $this->picker[$facilityPoolUnitId] = '';
        $this->slot[$facilityPoolUnitId] = '';
        $this->tier[$facilityPoolUnitId] = 'auto';
    }

    public function moveBucket(int $attendanceId, string $newBucket): void
    {
        abort_unless(auth()->user()?->hasPermission('ups.override'), 403);
        abort_unless(in_array($newBucket, DailyAttendance::BUCKETS, true), 422);

        $att = DailyAttendance::findOrFail($attendanceId);
        $att->update([
            'list_bucket' => $newBucket,
            'is_off' => $newBucket === 'OFF',
            'override_by' => auth()->id(),
            'override_at' => now(),
        ]);
    }

    public function toggleMkt(int $attendanceId): void
    {
        abort_unless(auth()->user()?->hasPermission('ups.override'), 403);
        $att = DailyAttendance::findOrFail($attendanceId);
        $att->update([
            'is_mkt' => ! $att->is_mkt,
            'override_by' => auth()->id(),
            'override_at' => now(),
        ]);
    }

    public function removeCheckin(int $attendanceId): void
    {
        abort_unless(auth()->user()?->hasPermission('ups.override'), 403);
        DailyAttendance::findOrFail($attendanceId)->delete();
    }

    public function confirmDaily(int $facilityPoolUnitId): void
    {
        abort_unless(auth()->user()?->hasPermission('ups.confirm_daily'), 403);
        $today = now()->toDateString();
        UpsDailyConfirm::updateOrCreate(
            ['facility_pool_unit_id' => $facilityPoolUnitId, 'work_date' => $today],
            ['confirmed_by' => auth()->id(), 'confirmed_at' => now()]
        );
        session()->flash('ups_msg', 'Đã chốt UPS hôm nay cho cơ sở này.');
    }

    public function unconfirmDaily(int $facilityPoolUnitId): void
    {
        abort_unless(auth()->user()?->hasPermission('ups.confirm_daily'), 403);
        UpsDailyConfirm::where('facility_pool_unit_id', $facilityPoolUnitId)
            ->whereDate('work_date', now()->toDateString())
            ->delete();
        session()->flash('ups_msg', 'Đã hủy chốt UPS — cơ sở tạm khóa chia số cho tới khi chốt lại.');
    }

    public function with(): array
    {
        $today = now()->toDateString();
        $branches = $this->branchesForUser();

        // Chỉ ẩn khỏi dropdown khi sale đã có MẶT ở CẢ Tiếp đón (list_bucket) LẪN MKT (is_mkt).
        // Còn thiếu 1 trong 2 → vẫn hiện để BO chọn slot còn lại (dual-list).
        $checkedInIds = DailyAttendance::whereDate('work_date', $today)
            ->whereNotNull('list_bucket')
            ->where('is_mkt', true)
            ->pluck('user_id')->all();

        $data = [];
        foreach ($branches as $branch) {
            $facilities = PoolUnit::where('parent_id', $branch->id)
                ->where('kind', 'facility')->where('is_active', true)
                ->orderBy('sort')->get();

            $facilityBlocks = [];
            foreach ($facilities as $facility) {
                $sales = $this->saleUsersOfFacility($facility);
                $atts = DailyAttendance::with(['user.assignments.role'])
                    ->where('facility_pool_unit_id', $facility->id)
                    ->whereDate('work_date', $today)
                    ->orderBy('checkin_at')->get();

                // Bucket groups: chỉ A/B/C/OFF (bỏ MKT ra khỏi bucket)
                $bucketGroups = $atts->filter(fn ($a) => $a->list_bucket !== null)->groupBy('list_bucket');
                // MKT list: tất cả có is_mkt=true
                $mktList = $atts->filter(fn ($a) => $a->is_mkt);

                // Trạng thái từng sale hôm nay: [user_id => ['greet'=>bool, 'mkt'=>bool]]
                $saleStatus = [];
                foreach ($atts as $a) {
                    $saleStatus[$a->user_id] = [
                        'greet' => $a->list_bucket !== null,
                        'mkt'   => (bool) $a->is_mkt,
                    ];
                }

                $facilityBlocks[] = [
                    'facility' => $facility,
                    'all' => $atts,
                    'buckets' => $bucketGroups,
                    'mktList' => $mktList,
                    'saleStatus' => $saleStatus,
                    'availableSales' => $sales->reject(fn ($u) => in_array($u->id, $checkedInIds, true))->values(),
                    'confirmed' => UpsDailyConfirm::isConfirmed($facility->id, $today),
                    'cutoff' => UpsConfig::cutoffFor($facility->id),
                ];
            }
            $data[] = ['branch' => $branch, 'facilities' => $facilityBlocks];
        }

        return [
            'branchData' => $data,
            'canCheckin' => ! $this->readOnly && auth()->user()->hasPermission('ups.checkin'),
            'canOverride' => ! $this->readOnly && auth()->user()->hasPermission('ups.override'),
            'canConfirm' => ! $this->readOnly && auth()->user()->hasPermission('ups.confirm_daily'),
            'buckets' => DailyAttendance::BUCKETS,
        ];
    }
}; ?>

<div class="w-full px-4 py-4 2xl:px-8">
    <div class="flex items-start justify-between mb-4 flex-wrap gap-3">
        <div>
            <div class="flex items-center gap-3 flex-wrap">
                <h1 class="text-2xl font-bold tracking-wide">UPS SYSTEM{{ $readOnly ? ' — Hôm nay' : '' }}</h1>
                @if (auth()->user()->hasPermission('ups.view'))
                    <div class="inline-flex items-center bg-gold-50 border border-gold-200 rounded-md text-xs font-semibold overflow-hidden">
                        <a href="{{ route('ups.list') }}" class="px-3 py-1.5 {{ ! $readOnly ? 'bg-gold-600 text-white' : 'text-ink/70 hover:bg-gold-100' }}">Check-in (BO)</a>
                        <a href="{{ route('ups.today') }}" class="px-3 py-1.5 {{ $readOnly ? 'bg-gold-600 text-white' : 'text-ink/70 hover:bg-gold-100' }}">Xem hôm nay</a>
                    </div>
                @endif
            </div>
            <p class="text-sm text-ink/50 mt-1">{{ $readOnly ? 'Xem UPS đã được BO chốt hôm nay.' : 'Check-in đầu ngày · sau 8h36 tự vào OFF LIST' }}</p>
        </div>
        <div class="text-right" x-data="{now:''}" x-init="setInterval(()=>{ const d=new Date(); const p=n=>String(n).padStart(2,'0'); now=`${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}` },1000)">
            <div class="text-xs text-ink/50">Bây giờ là:</div>
            <div class="text-2xl font-bold tabular-nums tracking-wider" x-text="now">--:--:--</div>
            <div class="text-xs text-ink/40 mt-1">{{ now()->format('d/m/Y') }}</div>
        </div>
    </div>

    @if (session('ups_msg'))
        <div class="bg-gold-50 border border-gold-200 text-gold-800 text-sm px-4 py-2 rounded mb-4">{{ session('ups_msg') }}</div>
    @endif

    @forelse ($branchData as $bd)
        <section class="mb-8">
            <h2 class="text-lg font-bold mb-3 px-3 py-2 bg-gold-50 border-l-4 border-gold-600 inline-block">Chi nhánh: {{ $bd['branch']->name }}</h2>

            @forelse ($bd['facilities'] as $fb)
                @php $facility = $fb['facility']; @endphp
                <div class="bg-white border border-gold-200 rounded-xl shadow-card p-4 mb-4">
                    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
                        <div>
                            <div class="font-bold text-ink">{{ $facility->name }}</div>
                            <div class="text-xs text-ink/50">Cutoff: {{ substr($fb['cutoff'], 0, 5) }} · Ngày làm việc: {{ now()->format('d/m/Y') }}</div>
                        </div>
                        <div>
                            @if ($fb['confirmed'])
                                <span class="bg-emerald-100 text-emerald-700 text-xs font-semibold px-3 py-1.5 rounded mr-2">✓ Đã chốt UPS hôm nay</span>
                                @if ($canConfirm)
                                    <button wire:click="unconfirmDaily({{ $facility->id }})" wire:confirm="Hủy chốt UPS cơ sở này? Chia số sẽ bị khóa lại tới khi chốt lại." class="bg-white border border-red-300 text-red-600 hover:bg-red-50 text-xs font-semibold px-3 py-1.5 rounded">Hủy chốt UPS</button>
                                @endif
                            @elseif ($canConfirm)
                                <button wire:click="confirmDaily({{ $facility->id }})" wire:confirm="Chốt UPS cho cơ sở này hôm nay? Sau khi chốt Phase 1 mới cho phép chia số." class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-4 py-2 rounded">Chốt UPS hôm nay</button>
                            @else
                                <span class="text-xs text-ink/40">Chưa chốt UPS</span>
                            @endif
                        </div>
                    </div>

                    <div class="flex flex-col lg:flex-row gap-4">
                        {{-- ===== TRÁI: Bảng Check-in Sale (~300px) ===== --}}
                        <div class="lg:w-[400px] lg:flex-shrink-0">
                            <div class="text-xs font-bold uppercase tracking-wider text-ink/60 mb-2 flex items-center gap-2">
                                <span class="bg-gold-100 text-gold-700 px-2 py-0.5 rounded">Check-in Sale</span>
                                <span class="text-ink/40 font-normal normal-case">{{ $fb['all']->count() }} người đã tới</span>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm border border-gold-200 border-collapse">
                                    <thead>
                                        <tr class="bg-gold-50 text-xs uppercase tracking-wider">
                                            <th class="border border-gold-200 px-3 py-2 w-12 text-center">#</th>
                                            <th class="border border-gold-200 px-3 py-2 w-24 text-center">Giờ đến</th>
                                            <th class="border border-gold-200 px-3 py-2 text-center">Họ tên</th>
                                            <th class="border border-gold-200 px-3 py-2 text-center">Vai trò</th>
                                            @if ($canOverride && ! $fb['confirmed'])
                                                <th class="border border-gold-200 px-2 py-2 w-8"></th>
                                            @endif
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($fb['all'] as $i => $att)
                                            @php
                                                $roleName = optional($att->user->assignments->first()?->role)->name ?? '—';
                                                $jobTitle = $att->user->job_title ?: $roleName;
                                            @endphp
                                            <tr class="{{ $att->list_bucket === 'OFF' ? 'bg-red-50/50' : '' }}">
                                                <td class="border border-gold-200 px-3 py-2 text-center text-ink/60">{{ $i + 1 }}</td>
                                                <td class="border border-gold-200 px-3 py-2 text-center font-mono text-xs">{{ optional($att->checkin_at)?->setTimezone('Asia/Ho_Chi_Minh')->format('H:i:s') }}</td>
                                                <td class="border border-gold-200 px-3 py-2 font-semibold text-center">
                                                    {{ $att->user->name }}
                                                    @if ($att->list_bucket)
                                                        @php
                                                            $chipClass = match ($att->list_bucket) {
                                                                'A'   => 'bg-blue-100 text-blue-800',
                                                                'B'   => 'bg-teal-100 text-teal-800',
                                                                'C'   => 'bg-slate-200 text-slate-800',
                                                                'OFF' => 'bg-rose-100 text-rose-800',
                                                                default => 'bg-gold-100 text-gold-800',
                                                            };
                                                        @endphp
                                                        <span class="ml-1 text-[10px] font-bold px-1.5 py-0.5 rounded {{ $chipClass }}">{{ $att->list_bucket }}</span>
                                                    @endif
                                                    @if ($att->is_mkt)
                                                        <span class="ml-1 text-[10px] font-bold px-1.5 py-0.5 rounded bg-amber-100 text-amber-800">MKT</span>
                                                    @endif
                                                </td>
                                                <td class="border border-gold-200 px-3 py-2 text-xs text-ink/70 text-center">{{ $jobTitle }}</td>
                                                @if ($canOverride && ! $fb['confirmed'])
                                                    <td class="border border-gold-200 px-1 py-1.5 text-center">
                                                        <button wire:click="removeCheckin({{ $att->id }})" wire:confirm="Xóa check-in {{ $att->user->name }}?" class="text-red-400 hover:text-red-600 text-base leading-none">×</button>
                                                    </td>
                                                @endif
                                            </tr>
                                        @empty
                                            <tr><td colspan="5" class="border border-gold-200 px-3 py-6 text-center text-ink/40 text-sm">Chưa có sale nào check-in.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>

                            @if ($canCheckin && ! $fb['confirmed'])
                                <div class="mt-3 space-y-2" x-data='{
                                        user:"", slot:"", tier:"auto",
                                        status: {!! json_encode($fb["saleStatus"], JSON_UNESCAPED_UNICODE) !!},
                                        inGreet(){ return !!(this.user && this.status[this.user] && this.status[this.user].greet) },
                                        inMkt(){ return !!(this.user && this.status[this.user] && this.status[this.user].mkt) },
                                    }'
                                    x-effect='
                                        if (!user) { slot=""; return }
                                        if (inGreet() && !inMkt()) slot="receive";
                                        else if (inMkt() && !inGreet()) slot="greet";
                                        else if ((slot==="greet" && inGreet()) || (slot==="receive" && inMkt())) slot="";
                                    '>
                                    {{-- Hàng 1: Nhân viên (full width) --}}
                                    <select x-model="user" class="w-full border border-gold-200 rounded px-3 py-2 text-sm bg-white">
                                        <option value="">1. Chọn nhân viên…</option>
                                        @foreach ($fb['availableSales'] as $u)
                                            <option value="{{ $u->id }}">{{ $u->name }}{{ $u->job_title ? ' ('.$u->job_title.')' : '' }}</option>
                                        @endforeach
                                    </select>

                                    {{-- Hàng 2: Vị trí + Tier --}}
                                    <div class="grid grid-cols-2 gap-2">
                                        <select x-model="slot" :disabled="!user" class="w-full border border-gold-200 rounded px-3 py-2 text-sm bg-white disabled:opacity-50 disabled:cursor-not-allowed">
                                            <option value="">2. Vị trí…</option>
                                            <option value="greet" :disabled="inGreet()" x-text="'Tiếp đón' + (inGreet() ? ' — đã có' : '')"></option>
                                            <option value="receive" :disabled="inMkt()" x-text="'Nhận số (MKT)' + (inMkt() ? ' — đã có' : '')"></option>
                                        </select>
                                        <select x-model="tier" :disabled="slot !== 'greet'" class="w-full border border-gold-200 rounded px-3 py-2 text-sm bg-white disabled:opacity-50 disabled:cursor-not-allowed">
                                            <option value="auto">3. Tier: Tự động</option>
                                            <option value="A">A</option>
                                            <option value="B">B</option>
                                            <option value="C">C</option>
                                            <option value="OFF">OFF</option>
                                        </select>
                                    </div>

                                    {{-- Hàng 3: Check in (full width) --}}
                                    <button
                                        :disabled="!user || !slot"
                                        x-on:click="$wire.call('checkInFull', {{ $facility->id }}, user, slot, tier).then(()=>{ user=''; slot=''; tier='auto' })"
                                        class="w-full bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-4 py-2.5 rounded disabled:opacity-40 disabled:cursor-not-allowed">
                                        + Check in
                                    </button>
                                </div>
                                <div class="text-[11px] text-ink/40 mt-2">Tier "Tự động": ≤ {{ substr($fb['cutoff'], 0, 5) }} → A · sau → OFF · Nhận số → MKT (có thể ở cả 2 list).</div>
                            @endif
                        </div>

                        {{-- ===== PHẢI: Bảng UPS đầy đủ ===== --}}
                        <div class="flex-1 min-w-0">
                            <div class="text-xs font-bold uppercase tracking-wider text-ink/60 mb-2">
                                <span class="bg-gold-100 text-gold-700 px-2 py-0.5 rounded">Bảng UPS</span>
                            </div>
                            @php
                                $bucketMeta = [
                                    'A'   => ['head' => 'bg-blue-600 text-white',    'sub' => 'bg-blue-50 text-blue-900',      'cell' => 'bg-white'],
                                    'B'   => ['head' => 'bg-teal-600 text-white',    'sub' => 'bg-teal-50 text-teal-900',      'cell' => 'bg-white'],
                                    'C'   => ['head' => 'bg-slate-600 text-white',   'sub' => 'bg-slate-50 text-slate-800',    'cell' => 'bg-white'],
                                    'OFF' => ['head' => 'bg-rose-600 text-white',    'sub' => 'bg-rose-50 text-rose-900',      'cell' => 'bg-white'],
                                ];
                                $mktMeta = ['head' => 'bg-amber-500 text-white', 'sub' => 'bg-amber-50 text-amber-900', 'cell' => 'bg-white'];
                                $subA = [
                                    'A'   => ['title' => 'BOD / HOTLINE / MKT<br>AFF / WI / BR', 'desc' => '≥20TR · SHOW+TIỀN'],
                                    'B'   => ['title' => 'APPT / PNS<br>VOUCHER',                 'desc' => 'CÓ SHOW / CÓ TIỀN'],
                                    'C'   => ['title' => 'B BẬN',                                 'desc' => 'CHECK IN ON TIME'],
                                    'OFF' => ['title' => 'A, B, C BẬN',                           'desc' => '&gt;5p TRỄ'],
                                ];
                            @endphp
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm border-2 border-ink/10 border-collapse table-fixed" style="table-layout:fixed">
                                    <colgroup>
                                        @foreach ($buckets as $b)<col style="width:20%">@endforeach
                                        <col style="width:20%">
                                    </colgroup>
                                    <thead>
                                        <tr>
                                            @foreach ($buckets as $b)
                                                <th class="border border-ink/10 px-2 h-12 text-center text-base font-extrabold uppercase tracking-wider {{ $bucketMeta[$b]['head'] }}">{{ $b }} LIST</th>
                                            @endforeach
                                            <th class="border border-ink/10 px-2 h-12 text-center text-base font-extrabold uppercase tracking-wider {{ $mktMeta['head'] }}">MKT LIST</th>
                                        </tr>
                                        <tr>
                                            @foreach ($buckets as $b)
                                                <th class="border border-ink/10 px-2 h-14 align-middle text-center text-[11px] font-bold uppercase tracking-wide leading-tight break-words {{ $bucketMeta[$b]['sub'] }}">
                                                    {!! $subA[$b]['title'] !!}
                                                </th>
                                            @endforeach
                                            <th class="border border-ink/10 px-2 h-14 align-middle text-center text-[11px] font-bold uppercase tracking-wide leading-tight break-words {{ $mktMeta['sub'] }}">
                                                TM TEAM (HC)
                                            </th>
                                        </tr>
                                        <tr>
                                            @foreach ($buckets as $b)
                                                <th class="border border-ink/10 px-2 h-10 align-middle text-center text-[11px] font-medium uppercase tracking-wide leading-tight break-words {{ $bucketMeta[$b]['sub'] }}">
                                                    {!! $subA[$b]['desc'] !!}
                                                </th>
                                            @endforeach
                                            <th class="border border-ink/10 px-2 h-10 align-middle text-center text-[11px] font-medium uppercase tracking-wide leading-tight break-words {{ $mktMeta['sub'] }}">
                                                CHECK IN ON TIME
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr class="align-top">
                                            @foreach ($buckets as $b)
                                                <td class="border border-ink/10 px-2 py-2 text-center align-top {{ $bucketMeta[$b]['cell'] }}" style="min-height:120px;">
                                                    @forelse (($fb['buckets'][$b] ?? []) as $att)
                                                        <div class="mb-1.5 border border-ink/10 rounded px-2 py-1.5 shadow-sm bg-white">
                                                            <div class="font-bold text-[13px] leading-tight text-ink break-words">
                                                                {{ $att->user->name }}
                                                            </div>
                                                            <div class="flex items-center justify-center gap-2 mt-1">
                                                                <span class="text-[11px] text-ink/60 font-mono">{{ optional($att->checkin_at)?->setTimezone('Asia/Ho_Chi_Minh')->format('H:i') }}</span>
                                                                @if ($canOverride && ! $fb['confirmed'])
                                                                    <select x-on:change="$wire.call('moveBucket', {{ $att->id }}, $event.target.value); $event.target.value=''" class="text-[10px] border border-ink/20 rounded px-1 py-0 bg-white" title="Chuyển bucket">
                                                                        <option value="">↔</option>
                                                                        @foreach ($buckets as $bb)
                                                                            @if ($bb !== $b)<option value="{{ $bb }}">{{ $bb }}</option>@endif
                                                                        @endforeach
                                                                    </select>
                                                                    <button wire:click="toggleMkt({{ $att->id }})" class="text-[10px] border border-ink/20 rounded px-1 py-0.5 bg-white hover:bg-amber-50" title="{{ $att->is_mkt ? 'Bỏ khỏi MKT' : 'Thêm vào MKT' }}">
                                                                        {{ $att->is_mkt ? '−M' : '+M' }}
                                                                    </button>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    @empty
                                                        <div class="text-[11px] text-ink/30 italic py-6">— trống —</div>
                                                    @endforelse
                                                </td>
                                            @endforeach
                                            {{-- MKT column --}}
                                            <td class="border border-ink/10 px-2 py-2 text-center align-top {{ $mktMeta['cell'] }}" style="min-height:120px;">
                                                @forelse ($fb['mktList'] as $att)
                                                    <div class="mb-1.5 border border-ink/10 rounded px-2 py-1.5 shadow-sm bg-white">
                                                        <div class="font-bold text-[13px] leading-tight text-ink break-words">
                                                            {{ $att->user->name }}
                                                        </div>
                                                        <div class="flex items-center justify-center gap-2 mt-1">
                                                            <span class="text-[11px] text-ink/60 font-mono">{{ optional($att->checkin_at)?->setTimezone('Asia/Ho_Chi_Minh')->format('H:i') }}</span>
                                                            @if ($canOverride && ! $fb['confirmed'])
                                                                <button wire:click="toggleMkt({{ $att->id }})" class="text-[10px] border border-red-200 rounded px-1 py-0.5 bg-white hover:bg-red-50 text-red-600" title="Bỏ khỏi MKT">
                                                                    −M
                                                                </button>
                                                            @endif
                                                        </div>
                                                    </div>
                                                @empty
                                                    <div class="text-[11px] text-ink/30 italic py-6">— trống —</div>
                                                @endforelse
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            {{-- Ghi chú theo bảng gốc, gắn dưới bảng UPS mỗi cơ sở --}}
                            <div class="text-[11px] text-ink/60 mt-2 leading-relaxed uppercase tracking-wide">
                                <div><strong>Lưu ý:</strong> Chỉ SHC trở lên được tiếp khách marketing.</div>
                                <div>Incharge có quyền tước bỏ quyền tiếp khách nếu nhân viên không đủ tinh thần tiếp khách.</div>
                                <div>Off list khi chốt khách sẽ half 50% với Incharge.</div>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-ink/50 mb-4">Chi nhánh này chưa có cơ sở hoạt động.</p>
            @endforelse
        </section>
    @empty
        <div class="bg-white border border-gold-200 rounded-xl p-8 text-center">
            <p class="text-ink/60">Bạn chưa được gán scope chi nhánh nào để xem UPS.</p>
        </div>
    @endforelse

</div>
