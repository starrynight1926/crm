<?php

use App\Models\BookingLateLog;
use App\Models\BookingLog;
use App\Models\Facility;
use App\Models\Lead;
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

    public function updatedFacilityId(): void
    {
        // trigger re-render + JS re-init charts
        $this->dispatch('charts-refreshed');
    }

    private function scopedLeads()
    {
        $q = Lead::query()->when($this->facilityId, function ($qq) {
            $ids = $this->facilitySubtreeIds($this->facilityId);
            $qq->whereIn('facility_id', $ids);
        });
        return $q;
    }

    private function scopedBookingLogs()
    {
        $q = BookingLog::query()->when($this->facilityId, function ($qq) {
            $ids = $this->facilitySubtreeIds($this->facilityId);
            $qq->whereIn('facility_id', $ids);
        });
        return $q;
    }

    private function facilitySubtreeIds(int $rootId): array
    {
        $ids = [$rootId];
        $children = Facility::where('parent_id', $rootId)->pluck('id')->all();
        return array_merge($ids, $children);
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
            'facilities'      => Facility::whereNull('parent_id')->orderBy('name')->get(),
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
        ];
    }
}; ?>

<div class="mb-6" wire:key="report-charts"
     x-data="reportCharts(@js([
        'trend' => $trend,
        'type_data' => $type_data,
        'funnel' => $funnel,
     ]))"
     x-init="init()"
     @charts-refreshed.window="refresh(@js(['trend' => $trend, 'type_data' => $type_data, 'funnel' => $funnel]))">

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

    {{-- Charts --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mb-6">
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
            <div class="text-xs text-ink/50 mt-1">Tổng: {{ number_format($funnel_total) }} khách</div>
        </div>
    </div>

    @once
        <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
        <script>
        window.reportCharts = function (data) {
            let charts = {};
            const palette = ['#059669', '#f59e0b', '#3b82f6', '#a3a3a3'];
            const build = (d) => {
                if (charts.trend)  charts.trend.destroy();
                if (charts.type)   charts.type.destroy();
                if (charts.funnel) charts.funnel.destroy();
                const wait = () => (window.Chart ? go() : setTimeout(wait, 50));
                const go = () => {
                    charts.trend = new Chart(document.getElementById('rc-trend'), {
                        type: 'line',
                        data: { labels: d.trend.map(x=>x.label), datasets: [{ label:'KH mới', data:d.trend.map(x=>x.count), borderColor:'#b58b3a', backgroundColor:'rgba(181,139,58,.15)', tension:.3, fill:true }] },
                        options: { plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}}, maintainAspectRatio:false }
                    });
                    charts.type = new Chart(document.getElementById('rc-type'), {
                        type: 'doughnut',
                        data: { labels: d.type_data.map(x=>x.label), datasets:[{ data:d.type_data.map(x=>x.count), backgroundColor:palette }] },
                        options: { plugins:{legend:{position:'bottom',labels:{boxWidth:12,font:{size:11}}}}, maintainAspectRatio:false }
                    });
                    charts.funnel = new Chart(document.getElementById('rc-funnel'), {
                        type: 'bar',
                        data: { labels: d.funnel.map(x=>x.label), datasets:[{ label:'Số KH', data:d.funnel.map(x=>x.count), backgroundColor:'#3b82f6' }] },
                        options: { indexAxis:'y', plugins:{legend:{display:false}, tooltip:{callbacks:{label:(c)=>` ${c.parsed.x} khách (${d.funnel[c.dataIndex].pct}%)`}}}, scales:{x:{beginAtZero:true}}, maintainAspectRatio:false }
                    });
                };
                wait();
            };
            return { init(){ build(data); }, refresh(nd){ build(nd); } };
        };
        </script>
    @endonce
</div>
