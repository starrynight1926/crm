<?php

use App\Models\BookingLateLog;
use App\Models\BookingLog;
use App\Models\Facility;
use App\Models\Lead;
use App\Models\OrgUnit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Dashboard tổng quan cho /reports — 4 KPI + 3 charts.
 *
 * KPI (tháng này vs tháng trước):
 *   - Tổng KH tháng (leads.created_at)
 *   - % KH mới vs Trở lại (mới = tạo trong tháng; trở lại = có >=2 booking_logs)
 *   - Cơ cấu loại booking (dich_vu / tu_van / kham_ls) trong tháng
 *   - Lịch trễ (booking_late_logs)
 *
 * Chart:
 *   - Line: KH mới theo tháng (6 tháng gần nhất)
 *   - Donut: cơ cấu loại booking
 *   - Bar ngang: funnel phase 1..5 (snapshot hiện tại)
 *
 * Filter: chọn cơ sở (root facilities) hoặc "Toàn công ty".
 */
new class extends Component
{
    public ?int $facilityId = null;

    /** Nếu true → áp Lead::visibleTo($user) + scope BookingLog theo lead visible. */
    public bool $scoped = false;

    /** Chart "Khách theo nguồn": today | week | month | quarter | custom */
    public string $sourceRange = 'month';
    public string $sourceFrom = '';
    public string $sourceTo = '';

    public function mount(): void
    {
        $this->sourceFrom = now()->startOfMonth()->format('Y-m-d');
        $this->sourceTo = now()->endOfMonth()->format('Y-m-d');
    }

    public function updatedFacilityId(): void
    {
        $this->pushChartUpdate();
    }

    public function updatedSourceRange(string $v): void
    {
        if ($v === 'custom') {
            // giữ nguyên from/to user đã nhập (hoặc default tháng).
        }
        $this->pushChartUpdate();
    }

    public function updatedSourceFrom(): void { if ($this->sourceRange === 'custom') $this->pushChartUpdate(); }
    public function updatedSourceTo(): void { if ($this->sourceRange === 'custom') $this->pushChartUpdate(); }

    private function pushChartUpdate(): void
    {
        // Charts bọc wire:ignore → không re-render DOM. Dispatch payload để JS update data in-place.
        $this->dispatch('rc-chart-data', data: $this->chartPayload());
    }

    /** Trả [$from, $to] (Carbon) cho range chart nguồn. */
    private function sourcePeriod(): array
    {
        $now = now();
        return match ($this->sourceRange) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'quarter' => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()],
            'custom' => [
                \Illuminate\Support\Carbon::parse($this->sourceFrom ?: $now->copy()->startOfMonth())->startOfDay(),
                \Illuminate\Support\Carbon::parse($this->sourceTo ?: $now->copy()->endOfMonth())->endOfDay(),
            ],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
    }

    public function sourceRangeLabel(): string
    {
        [$f, $t] = $this->sourcePeriod();
        return match ($this->sourceRange) {
            'today' => 'hôm nay ' . $f->format('d/m/Y'),
            'week' => 'tuần ' . $f->format('d/m') . '–' . $t->format('d/m/Y'),
            'quarter' => 'quý ' . ceil($f->month / 3) . '/' . $f->year,
            'custom' => $f->format('d/m/Y') . ' → ' . $t->format('d/m/Y'),
            default => 'tháng ' . $f->format('m/Y'),
        };
    }

    public function chartPayload(): array
    {
        $withData = $this->with();
        return [
            'trend' => $withData['trend'],
            'type_data' => $withData['type_data'],
            'funnel' => $withData['funnel'],
            'source_data' => $withData['source_data'],
        ];
    }

    private function scopedLeads()
    {
        $u = auth()->user();
        $q = $this->scoped && $u ? Lead::visibleTo($u) : Lead::query();
        if ($branchOrgIds = $this->selectedBranchOrgUnitIds()) {
            $q->whereIn('org_unit_id', $branchOrgIds);
        }
        return $q;
    }

    private function scopedBookingLogs()
    {
        $u = auth()->user();
        $q = BookingLog::query();
        if ($this->scoped && $u) {
            $q->whereIn('lead_id', Lead::visibleTo($u)->select('id'));
        }
        // Booking log có facility_id (chuẩn sbooking), nhưng để nhất quán với leads scope
        // (lọc theo cơ sở của lead, không phải cơ sở booking) → filter qua lead_id.
        if ($branchOrgIds = $this->selectedBranchOrgUnitIds()) {
            $q->whereIn('lead_id', Lead::whereIn('org_unit_id', $branchOrgIds)->select('id'));
        }
        return $q;
    }

    private function facilitySubtreeIds(int $rootId): array
    {
        $ids = [$rootId];
        $children = Facility::where('parent_id', $rootId)->pluck('id')->all();
        return array_merge($ids, $children);
    }

    /**
     * Map facility được chọn → OrgUnit branch (subtree ids) để filter leads.
     * Leads bên CRM gần như không set facility_id (field này hướng sbooking), nhưng luôn có
     * org_unit_id. Match theo keyword tên facility ↔ OrgUnit branch code.
     * Return [] khi chưa chọn hoặc không map được (query không filter).
     */
    private function selectedBranchOrgUnitIds(): array
    {
        if (! $this->facilityId) return [];
        $fac = Facility::find($this->facilityId);
        if (! $fac) return [];
        $name = mb_strtolower((string) $fac->name);
        $branchCode = match (true) {
            str_contains($name, 'hà nội') || str_contains($name, 'ha noi') => 'branch-hn',
            str_contains($name, 'hồ chí minh') || str_contains($name, 'ho chi minh') => 'branch-hcm',
            str_contains($name, 'đà nẵng') || str_contains($name, 'da nang') => 'branch-dn',
            default => null,
        };
        if (! $branchCode) return [];
        $branch = OrgUnit::where('code', $branchCode)->first();
        return $branch ? $branch->subtreeIds() : [];
    }

    private function pct(int $part, int $total): float
    {
        return $total > 0 ? round($part * 100 / $total, 1) : 0.0;
    }

    public function with(): array
    {
        $now       = now();
        $monthFrom = $now->copy()->startOfMonth();
        $monthTo   = $now->copy()->endOfMonth();
        $prevFrom  = $now->copy()->subMonth()->startOfMonth();
        $prevTo    = $now->copy()->subMonth()->endOfMonth();

        // ---- KPI 1: tổng KH tháng
        $totalMonth = (clone $this->scopedLeads())
            ->whereBetween('created_at', [$monthFrom, $monthTo])->count();
        $totalPrev  = (clone $this->scopedLeads())
            ->whereBetween('created_at', [$prevFrom, $prevTo])->count();
        $totalDelta = $totalPrev > 0 ? round(($totalMonth - $totalPrev) * 100 / $totalPrev, 1) : null;

        // ---- KPI 2: mới vs trở lại (trong tháng)
        //   Mới    = leads.created_at trong tháng
        //   Trở lại = leads có >=2 booking_logs BẤT KỲ (không giới hạn tháng — khách quay lại tính từ đầu)
        $newLeads = $totalMonth;
        $returningLeadIds = (clone $this->scopedBookingLogs())
            ->select('lead_id')->groupBy('lead_id')
            ->havingRaw('COUNT(*) >= 2')->pluck('lead_id')->all();
        $returningInMonth = (clone $this->scopedLeads())
            ->whereIn('id', $returningLeadIds)
            ->whereHas('bookingLogs', fn ($q) => $q->whereBetween('scheduled_at', [$monthFrom, $monthTo]))
            ->count();
        $touchedLeadIds = (clone $this->scopedBookingLogs())
            ->whereBetween('scheduled_at', [$monthFrom, $monthTo])
            ->distinct()->pluck('lead_id')->all();
        $touchedCount = count($touchedLeadIds);
        $pctNew      = $this->pct(max(0, $touchedCount - $returningInMonth), $touchedCount);
        $pctReturn   = $this->pct($returningInMonth, $touchedCount);

        // ---- KPI 3: cơ cấu loại booking (tháng này)
        $typeCounts = (clone $this->scopedBookingLogs())
            ->whereBetween('scheduled_at', [$monthFrom, $monthTo])
            ->selectRaw('type, COUNT(*) as c')->groupBy('type')->pluck('c', 'type')->all();
        $typeTotal = array_sum($typeCounts);
        $typeLabels = ['dich_vu' => 'Dịch vụ', 'tu_van' => 'Tư vấn', 'kham_ls' => 'Khám LS'];
        $typeData = [];
        foreach ($typeLabels as $key => $label) {
            $c = (int) ($typeCounts[$key] ?? 0);
            $typeData[] = ['label' => $label, 'count' => $c, 'pct' => $this->pct($c, $typeTotal)];
        }
        $otherCount = $typeTotal - array_sum(array_column($typeData, 'count'));
        if ($otherCount > 0) {
            $typeData[] = ['label' => 'Khác', 'count' => $otherCount, 'pct' => $this->pct($otherCount, $typeTotal)];
        }

        // ---- KPI 4: lịch trễ tháng này
        $lateQ = BookingLateLog::query()
            ->when($this->facilityId, function ($qq) {
                $ids = $this->facilitySubtreeIds($this->facilityId);
                $qq->whereHas('bookingLog', fn ($b) => $b->whereIn('facility_id', $ids));
            });
        $lateMonth = (clone $lateQ)->whereBetween('created_at', [$monthFrom, $monthTo])->count();
        $lateTotalBookings = (clone $this->scopedBookingLogs())
            ->whereBetween('scheduled_at', [$monthFrom, $monthTo])->count();
        $latePct = $this->pct($lateMonth, $lateTotalBookings);

        // ---- Chart 1: trend 6 tháng KH mới
        $trend = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = $now->copy()->subMonths($i)->startOfMonth();
            $to = $m->copy()->endOfMonth();
            $trend[] = [
                'label' => $m->format('m/Y'),
                'count' => (clone $this->scopedLeads())->whereBetween('created_at', [$m, $to])->count(),
            ];
        }

        // ---- Chart: KH theo nguồn (trong khoảng user chọn — Hôm nay/Tuần/Tháng/Quý/Custom)
        [$srcFrom, $srcTo] = $this->sourcePeriod();
        $sourceCounts = (clone $this->scopedLeads())
            ->whereBetween('created_at', [$srcFrom, $srcTo])
            ->whereNotNull('source_group')
            ->selectRaw('source_group, COUNT(*) as c')
            ->groupBy('source_group')
            ->pluck('c', 'source_group')->all();
        $sourceData = [];
        foreach (Lead::SOURCE_GROUPS as $key => $label) {
            $sourceData[] = [
                'label' => $label,
                'code' => Lead::SOURCE_GROUP_CODES[$key] ?? $key,
                'count' => (int) ($sourceCounts[$key] ?? 0),
            ];
        }

        // ---- Chart 3: funnel phase (snapshot toàn bộ leads visible, không giới hạn tháng)
        $phaseCounts = (clone $this->scopedLeads())
            ->selectRaw('phase, COUNT(*) as c')->groupBy('phase')->pluck('c', 'phase')->all();
        $funnel = [];
        foreach ([1, 2, 3, 4, 5] as $p) {
            $c = (int) ($phaseCounts[$p] ?? 0);
            $funnel[] = [
                'phase' => $p,
                'label' => Lead::CF_PHASE_LABELS[$p] ?? ("Phase $p"),
                'count' => $c,
            ];
        }
        $funnelTotal = array_sum(array_column($funnel, 'count'));
        foreach ($funnel as &$f) $f['pct'] = $this->pct($f['count'], $funnelTotal);
        unset($f);

        return [
            // Tất cả cơ sở root active. (Trước đây filter theo "có lead" để ẩn seed rác id 7-12 —
            // rác đã dọn 2026-10-15, bỏ filter để dropdown luôn hiện đủ 3 cơ sở.)
            'facilities' => Facility::whereNull('parent_id')->where('active', true)->orderBy('name')->get(),
            'kpi' => [
                'total_month'      => $totalMonth,
                'total_prev'       => $totalPrev,
                'total_delta'      => $totalDelta,
                'pct_new'          => $pctNew,
                'pct_return'       => $pctReturn,
                'touched_count'    => $touchedCount,
                'late_month'       => $lateMonth,
                'late_pct'         => $latePct,
            ],
            'type_data' => $typeData,
            'type_total' => $typeTotal,
            'trend' => $trend,
            'funnel' => $funnel,
            'funnel_total' => $funnelTotal,
            'source_data' => $sourceData,
        ];
    }
}; ?>

<div class="mb-6">
    {{-- Initial payload cho JS khi mount; sau đó update qua event dispatch. --}}
    @php
        $__rcPayload = json_encode([
            'trend' => $trend,
            'type_data' => $type_data,
            'funnel' => $funnel,
            'source_data' => $source_data,
        ], JSON_UNESCAPED_UNICODE);
    @endphp
    <script type="application/json" id="rc-chart-data">{!! $__rcPayload !!}</script>

    {{-- Filter bar --}}
    <div class="flex items-center gap-3 mb-4">
        <h2 class="text-lg font-semibold text-ink">Tổng quan</h2>
        <span class="text-xs text-ink/50">— {{ now()->format('m/Y') }}</span>
        <div class="flex-1"></div>
        <label class="text-xs text-ink/60">Cơ sở:</label>
        <select wire:model.live="facilityId" class="border border-gold-200 rounded-md px-3 py-1.5 text-sm bg-white">
            <option value="">Toàn công ty</option>
            @foreach ($facilities as $f)
                <option value="{{ $f->id }}">{{ $f->name }}</option>
            @endforeach
        </select>
    </div>

    {{-- KPI cards --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        {{-- KPI 1: tổng KH --}}
        <div class="bg-white border border-gold-200 rounded-xl p-4 shadow-card">
            <div class="text-xs text-ink/50 uppercase tracking-wide">Tổng khách hàng</div>
            <div class="text-3xl font-bold text-ink mt-1">{{ number_format($kpi['total_month']) }}</div>
            <div class="text-xs mt-1
                @if ($kpi['total_delta'] === null) text-ink/40
                @elseif ($kpi['total_delta'] >= 0) text-emerald-700
                @else text-red-700 @endif">
                @if ($kpi['total_delta'] === null)
                    (không có dữ liệu tháng trước)
                @else
                    {{ $kpi['total_delta'] >= 0 ? '▲' : '▼' }} {{ abs($kpi['total_delta']) }}% so tháng trước ({{ number_format($kpi['total_prev']) }})
                @endif
            </div>
        </div>

        {{-- KPI 2: mới vs trở lại --}}
        <div class="bg-white border border-gold-200 rounded-xl p-4 shadow-card">
            <div class="text-xs text-ink/50 uppercase tracking-wide">Mới / Trở lại</div>
            <div class="flex items-baseline gap-3 mt-1">
                <div>
                    <span class="text-2xl font-bold text-emerald-700">{{ $kpi['pct_new'] }}%</span>
                    <span class="text-xs text-ink/50 ml-1">Mới</span>
                </div>
                <div>
                    <span class="text-2xl font-bold text-amber-700">{{ $kpi['pct_return'] }}%</span>
                    <span class="text-xs text-ink/50 ml-1">Trở lại</span>
                </div>
            </div>
            <div class="mt-2 h-2 rounded-full overflow-hidden bg-gold-100 flex">
                <div class="h-full bg-emerald-500" style="width: {{ $kpi['pct_new'] }}%"></div>
                <div class="h-full bg-amber-500"  style="width: {{ $kpi['pct_return'] }}%"></div>
            </div>
            <div class="text-xs text-ink/50 mt-1">Từ {{ number_format($kpi['touched_count']) }} khách có lịch tháng này</div>
        </div>

        {{-- KPI 3: cơ cấu loại (tóm tắt số) --}}
        <div class="bg-white border border-gold-200 rounded-xl p-4 shadow-card">
            <div class="text-xs text-ink/50 uppercase tracking-wide">Loại booking</div>
            <div class="text-sm mt-2 space-y-1">
                @foreach ($type_data as $t)
                    <div class="flex justify-between">
                        <span class="text-ink/70">{{ $t['label'] }}</span>
                        <span class="font-semibold">{{ $t['pct'] }}%<span class="text-ink/40 font-normal"> ({{ $t['count'] }})</span></span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- KPI 4: lịch trễ --}}
        <div class="bg-white border border-gold-200 rounded-xl p-4 shadow-card">
            <div class="text-xs text-ink/50 uppercase tracking-wide">Lịch trễ tháng</div>
            <div class="text-3xl font-bold {{ $kpi['late_pct'] > 10 ? 'text-red-700' : 'text-ink' }} mt-1">{{ number_format($kpi['late_month']) }}</div>
            <div class="text-xs text-ink/60 mt-1">{{ $kpi['late_pct'] }}% trên tổng lịch tháng này</div>
        </div>
    </div>

    {{-- Charts (wire:ignore để Livewire không clear canvas; data update via event). --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mb-6" wire:ignore>
        <div class="bg-white border border-gold-200 rounded-xl p-4 shadow-card">
            <div class="text-sm font-semibold text-ink mb-2">KH mới theo tháng (6 tháng)</div>
            <div class="relative h-56"><canvas id="rc-trend"></canvas></div>
        </div>
        <div class="bg-white border border-gold-200 rounded-xl p-4 shadow-card">
            <div class="text-sm font-semibold text-ink mb-2">Cơ cấu loại booking</div>
            <div class="relative h-56"><canvas id="rc-type"></canvas></div>
        </div>
        <div class="bg-white border border-gold-200 rounded-xl p-4 shadow-card">
            <div class="text-sm font-semibold text-ink mb-2">Funnel Phase (snapshot)</div>
            <div class="relative h-56"><canvas id="rc-funnel"></canvas></div>
            <div class="text-xs text-ink/50 mt-1" id="rc-funnel-total">Tổng: {{ number_format($funnel_total) }} khách</div>
        </div>
    </div>

    {{-- Chart: Số lượng khách theo nguồn (range chọn) --}}
    <div class="bg-white border border-gold-200 rounded-xl p-4 shadow-card mb-6">
        <div class="flex flex-wrap items-center gap-3 mb-3">
            <div class="text-sm font-semibold text-ink">Số lượng khách theo nguồn</div>
            <span class="text-xs text-ink/50">({{ $this->sourceRangeLabel() }})</span>
            <div class="flex-1"></div>
            <div class="inline-flex rounded-md border border-gold-200 overflow-hidden text-xs">
                @foreach ([
                    'today' => 'Hôm nay',
                    'week' => 'Tuần',
                    'month' => 'Tháng',
                    'quarter' => 'Quý',
                    'custom' => 'Chọn khoảng',
                ] as $k => $label)
                    <button type="button" wire:click="$set('sourceRange', '{{ $k }}')"
                            class="px-3 py-1.5 border-r border-gold-200 last:border-r-0 {{ $sourceRange === $k ? 'bg-gold-600 text-white font-semibold' : 'bg-white text-ink/70 hover:bg-gold-50' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
            @if ($sourceRange === 'custom')
                <div class="inline-flex items-center gap-2 text-xs">
                    <label class="text-ink/60">Từ</label>
                    <input type="date" wire:model.live="sourceFrom"
                           class="border border-gold-200 rounded-md px-2 py-1 bg-white">
                    <label class="text-ink/60">Tới</label>
                    <input type="date" wire:model.live="sourceTo"
                           class="border border-gold-200 rounded-md px-2 py-1 bg-white">
                </div>
            @endif
        </div>
        <div wire:ignore>
            <div class="relative h-64"><canvas id="rc-source"></canvas></div>
            <div id="rc-source-empty" class="text-xs text-ink/50 py-10 text-center" style="display:none">Chưa có khách nào trong khoảng này.</div>
        </div>
    </div>

    @once
        <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
        <script>
        (function(){
            const palette = ['#059669', '#f59e0b', '#3b82f6', '#a3a3a3'];
            const sourcePalette = ['#2563eb','#059669','#f59e0b','#8b5cf6','#ec4899','#14b8a6','#f43f5e','#6366f1','#64748b'];
            const S = window.__rcCharts = {};

            function buildAll(d){
                if (!window.Chart) { setTimeout(() => buildAll(d), 50); return; }
                const trendEl = document.getElementById('rc-trend');
                if (trendEl && !S.trend) S.trend = new Chart(trendEl, {
                    type: 'line',
                    data: { labels: [], datasets: [{ label:'KH mới', data:[], borderColor:'#b58b3a', backgroundColor:'rgba(181,139,58,.15)', tension:.3, fill:true }] },
                    options: { plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}}, maintainAspectRatio:false }
                });
                const typeEl = document.getElementById('rc-type');
                if (typeEl && !S.type) S.type = new Chart(typeEl, {
                    type: 'doughnut',
                    data: { labels: [], datasets:[{ data:[], backgroundColor:palette }] },
                    options: { plugins:{legend:{position:'bottom',labels:{boxWidth:12,font:{size:11}}}}, maintainAspectRatio:false }
                });
                const funnelEl = document.getElementById('rc-funnel');
                if (funnelEl && !S.funnel) S.funnel = new Chart(funnelEl, {
                    type: 'bar',
                    data: { labels: [], datasets:[{ label:'Số KH', data:[], backgroundColor:'#3b82f6' }] },
                    options: { indexAxis:'y', plugins:{legend:{display:false}, tooltip:{callbacks:{label:(c)=>{ const p = (S._funnelMeta||[])[c.dataIndex]?.pct; return ` ${c.parsed.x} khách${p!=null?' ('+p+'%)':''}`; }}}}, scales:{x:{beginAtZero:true}}, maintainAspectRatio:false }
                });
                const srcEl = document.getElementById('rc-source');
                if (srcEl && !S.source) S.source = new Chart(srcEl, {
                    type: 'bar',
                    data: { labels: [], datasets: [{ label:'Số khách', data:[], backgroundColor:[] }] },
                    options: { plugins: { legend: { display:false }, tooltip: { callbacks: { title:(items)=>(S._sourceMeta||[])[items[0].dataIndex]?.label, label:(c)=>` ${c.parsed.y} khách` } } }, scales: { y: { beginAtZero:true, ticks:{ precision:0 } } }, maintainAspectRatio: false }
                });
                update(d);
            }

            function update(d){
                if (S.trend) { S.trend.data.labels = d.trend.map(x=>x.label); S.trend.data.datasets[0].data = d.trend.map(x=>x.count); S.trend.update(); }
                if (S.type)  { S.type.data.labels = d.type_data.map(x=>x.label); S.type.data.datasets[0].data = d.type_data.map(x=>x.count); S.type.update(); }
                if (S.funnel){ S.funnel.data.labels = d.funnel.map(x=>x.label); S.funnel.data.datasets[0].data = d.funnel.map(x=>x.count); S._funnelMeta = d.funnel; S.funnel.update(); }
                if (S.source){
                    const hasData = d.source_data && d.source_data.some(x => x.count > 0);
                    const emptyEl = document.getElementById('rc-source-empty');
                    const srcCvs = document.getElementById('rc-source');
                    if (hasData){
                        if (emptyEl) emptyEl.style.display = 'none';
                        if (srcCvs) srcCvs.style.display = '';
                        S.source.data.labels = d.source_data.map(x=>x.code);
                        S.source.data.datasets[0].data = d.source_data.map(x=>x.count);
                        S.source.data.datasets[0].backgroundColor = d.source_data.map((_,i)=>sourcePalette[i % sourcePalette.length]);
                        S._sourceMeta = d.source_data;
                        S.source.update();
                    } else {
                        if (emptyEl) emptyEl.style.display = '';
                        if (srcCvs) srcCvs.style.display = 'none';
                    }
                }
            }

            // Initial load: read embedded script tag.
            function kick(){
                const el = document.getElementById('rc-chart-data');
                if (!el) { setTimeout(kick, 50); return; }
                try { buildAll(JSON.parse(el.textContent)); } catch(e){ console.error('rc-chart-data parse fail', e); }
            }
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', kick); else kick();

            // Livewire event listener — payload từ server dispatch khi filter đổi.
            document.addEventListener('livewire:init', () => {
                Livewire.on('rc-chart-data', (payload) => {
                    // Livewire v3+ wraps payload: event args = [{data: {...}}].
                    const d = Array.isArray(payload) ? payload[0]?.data : payload?.data;
                    if (d) update(d);
                });
            });
        })();
        </script>
    @endonce
</div>
