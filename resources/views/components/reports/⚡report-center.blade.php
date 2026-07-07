<?php

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadDistributionLog;
use App\Models\OrgUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

new class extends Component
{
    public string $tab = 'funnel'; // funnel / marketing / performance / distribution

    public string $from = '';

    public string $to = '';

    public string $groupBy = 'camp'; // cho tab marketing: camp / ad_source / page

    /** Kiểu hiển thị mã KH ở tab Chi tiết lead. */
    public string $codeMode = 'full'; // full | required | simple

    /** Id các trường tùy biến người dùng chọn hiện làm cột (lưu theo user). */
    public array $selectedFieldIds = [];

    public const CODE_MODES = [
        'full' => 'Hiển thị full mã',
        'required' => 'Hiển thị mã bắt buộc',
        'simple' => 'Hiển thị đơn giản',
    ];

    /** Trường tùy biến ứng viên làm cột: active + thuộc phạm vi (công ty + phòng thấy được). */
    private function availableReportFields()
    {
        $orgIds = auth()->user()->visibleOrgUnitIds();

        return \App\Models\CustomField::query()
            ->where('active', true)
            ->where('status', \App\Models\CustomField::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('org_unit_id')
                ->when($orgIds !== [], fn ($qq) => $qq->orWhereIn('org_unit_id', $orgIds)))
            ->orderBy('org_unit_id')
            ->orderBy('position')
            ->get();
    }

    /** Trường thực sự render (giao của lựa chọn user và ứng viên hợp lệ). */
    private function reportCustomFields()
    {
        return $this->availableReportFields()
            ->whereIn('id', $this->selectedFieldIds)
            ->values();
    }

    /** Mã KH theo kiểu hiển thị: full = mã đầy đủ, required = chỉ đoạn bắt buộc, simple = mã cấp công ty. */
    public function leadCode(Lead $lead): string
    {
        $core = 'KH-' . str_pad((string) $lead->id, 3, '0', STR_PAD_LEFT);
        if ($this->codeMode === 'simple') {
            return $core;
        }
        if ($this->codeMode === 'required') {
            $segs = \App\Models\CustomField::codeSegmentsFor($lead, true);
            return $segs ? $core . '-' . implode('-', $segs) : $core;
        }

        return $lead->code ?: $core; // full
    }

    /** Danh sách lead trong phạm vi + kỳ, kèm giá trị trường tùy biến + người liên quan. */
    /** Quyền xem báo cáo toàn hệ thống → bỏ qua giới hạn phạm vi dữ liệu. */
    private function seesAllReports(): bool
    {
        return auth()->user()->hasPermission('report.view_all');
    }

    /** Query lead cho báo cáo: toàn hệ thống nếu có report.view_all, ngược lại theo phạm vi. */
    private function reportLeadQuery()
    {
        return $this->seesAllReports() ? Lead::query() : Lead::visibleTo(auth()->user());
    }

    private function leadDetailData()
    {
        return $this->reportLeadQuery()
            ->whereBetween('received_date', [$this->from, $this->to])
            ->with(['customValues', 'owner', 'receiver'])
            ->orderByDesc('received_date')
            ->limit(500)
            ->get();
    }

    public function mount(): void
    {
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();

        $prefs = auth()->user()->report_prefs ?? [];
        $this->codeMode = in_array($prefs['code_mode'] ?? null, array_keys(self::CODE_MODES), true)
            ? $prefs['code_mode'] : 'full';
        // Mặc định lần đầu: hiện mọi trường ứng viên
        $this->selectedFieldIds = array_map('intval', $prefs['lead_fields']
            ?? $this->availableReportFields()->pluck('id')->all());
    }

    private function persistReportPrefs(): void
    {
        $user = auth()->user();
        $user->update(['report_prefs' => array_merge($user->report_prefs ?? [], [
            'code_mode' => $this->codeMode,
            'lead_fields' => array_values(array_map('intval', $this->selectedFieldIds)),
        ])]);
    }

    public function updatedCodeMode(): void
    {
        $this->persistReportPrefs();
    }

    public function updatedSelectedFieldIds(): void
    {
        $this->persistReportPrefs();
    }

    private function scopedStats()
    {
        $user = auth()->user();
        $orgIds = $user->visibleOrgUnitIds();

        return DB::table('stats_daily')
            ->whereBetween('date', [$this->from, $this->to])
            ->when(! $this->seesAllReports(), fn ($query) => $query->where(function ($q) use ($orgIds, $user) {
                if ($orgIds !== []) {
                    $q->orWhereIn('org_unit_id', $orgIds);
                }
                $q->orWhere('user_id', $user->id);
            }));
    }

    private function funnelData(): object
    {
        return $this->scopedStats()
            ->selectRaw('sum(total) total, sum(`lead`) `lead`, sum(`follow`) `follow`, sum(net) net, sum(booking) booking, sum(`show`) `show`, sum(`close`) `close`, sum(revenue_collected) revenue')
            ->first();
    }

    /** Tab marketing: group theo camp/nguồn/page — page không có trong stats_daily nên query leads trực tiếp. */
    private function marketingData()
    {
        return $this->reportLeadQuery()
            ->whereBetween('received_date', [$this->from, $this->to])
            ->selectRaw("COALESCE({$this->groupBy}, '(trống)') as dim, count(*) total, sum(classification = 'close') closes, sum(classification = 'booking') bookings")
            ->groupBy('dim')
            ->orderByDesc('total')
            ->limit(50)
            ->get();
    }

    private function performanceData()
    {
        return $this->scopedStats()
            ->whereNotNull('user_id')
            ->selectRaw('user_id, sum(total) total, sum(`close`) closes, sum(booking) bookings, sum(revenue_collected) revenue')
            ->groupBy('user_id')
            ->orderByDesc('revenue')
            ->orderByDesc('closes')
            ->get();
    }

    private function distributionData()
    {
        $user = auth()->user();
        $orgIds = $user->visibleOrgUnitIds();
        $all = $this->seesAllReports();

        $logs = LeadDistributionLog::query()
            ->whereBetween('created_at', [$this->from . ' 00:00:00', $this->to . ' 23:59:59'])
            ->when(! $all && $orgIds !== [], fn ($q) => $q->where(fn ($qq) => $qq->whereIn('org_unit_id', $orgIds)->orWhereNull('org_unit_id')))
            ->selectRaw('action, count(*) c')
            ->groupBy('action')
            ->pluck('c', 'action');

        return [
            'logs' => $logs,
            'pools' => [
                'common' => Lead::where('pool_level', 'common')->count(),
                'team' => $this->reportLeadQuery()->where('pool_level', 'team')->count(),
                'personal' => $this->reportLeadQuery()->where('pool_level', 'personal')->count(),
            ],
        ];
    }

    /** Export Excel — quyền lead.export, mọi lần export ghi audit log. */
    public function export()
    {
        abort_unless(auth()->user()->hasPermission('lead.export'), 403);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        if ($this->tab === 'funnel') {
            $f = $this->funnelData();
            $sheet->fromArray([['Chỉ số', 'Giá trị'],
                ['Total', (int) $f->total], ['Lead', (int) $f->lead], ['Follow', (int) $f->follow],
                ['Nét', (int) $f->net], ['Booking', (int) $f->booking], ['Show', (int) $f->show],
                ['Close', (int) $f->close], ['Doanh thu thực thu', (int) $f->revenue],
            ]);
        } elseif ($this->tab === 'marketing') {
            $rows = $this->marketingData()->map(fn ($r) => [$r->dim, (int) $r->total, (int) $r->bookings, (int) $r->closes,
                $r->total > 0 ? round($r->closes / $r->total * 100, 1) . '%' : '0%'])->all();
            $sheet->fromArray([[ucfirst($this->groupBy), 'Lead về', 'Booking', 'Close', 'Tỉ lệ close'], ...$rows]);
        } elseif ($this->tab === 'performance') {
            $users = User::pluck('name', 'id');
            $rows = $this->performanceData()->map(fn ($r) => [$users[$r->user_id] ?? $r->user_id, (int) $r->total, (int) $r->bookings, (int) $r->closes,
                $r->total > 0 ? round($r->closes / $r->total * 100, 1) . '%' : '0%', (int) $r->revenue])->all();
            $sheet->fromArray([['Sale', 'Số nhận', 'Booking', 'Close', 'Tỉ lệ close', 'Doanh thu'], ...$rows]);
        } elseif ($this->tab === 'leads') {
            $cfs = $this->reportCustomFields();
            $cfLabels = \App\Models\CustomField::labelMap($cfs);
            $header = array_merge(
                ['Mã KH', 'Họ tên khách', 'Nguồn', 'Người thu thập', 'Người phụ trách', 'Ngày thu thập'],
                $cfs->map(fn ($f) => $cfLabels[$f->id] ?? $f->label)->all()
            );
            $rows = $this->leadDetailData()->map(function ($lead) use ($cfs) {
                $vals = $lead->customValues->pluck('value', 'custom_field_id');
                return array_merge(
                    [
                        $this->leadCode($lead), $lead->name, (string) $lead->ad_source,
                        (string) $lead->receiver?->name, (string) $lead->owner?->name,
                        (string) $lead->received_date?->toDateString(),
                    ],
                    $cfs->map(fn ($f) => $f->field_type === 'select'
                        ? $f->optionLabel((string) ($vals[$f->id] ?? ''))
                        : (string) ($vals[$f->id] ?? ''))->all()
                );
            })->all();
            $sheet->fromArray([$header, ...$rows]);
        } else {
            $d = $this->distributionData();
            $sheet->fromArray([['Hành động', 'Số lượt'],
                ['Chia tự động', (int) ($d['logs']['distribute'] ?? 0)],
                ['Chia tay', (int) ($d['logs']['manual_assign'] ?? 0)],
                ['Thu hồi', (int) ($d['logs']['recall'] ?? 0)],
                ['Kéo từ kho', (int) ($d['logs']['pull'] ?? 0)],
                [''], ['Tồn kho', 'Số lead'],
                ['Kho chung', $d['pools']['common']], ['Kho team', $d['pools']['team']], ['Kho cá nhân', $d['pools']['personal']],
            ]);
        }

        AuditLog::record('export', null, ['report' => $this->tab, 'from' => $this->from, 'to' => $this->to]);

        $filename = "bao-cao-{$this->tab}-{$this->from}-{$this->to}.xlsx";

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename);
    }

    public function with(): array
    {
        return [
            'funnel' => $this->tab === 'funnel' ? $this->funnelData() : null,
            'marketing' => $this->tab === 'marketing' ? $this->marketingData() : collect(),
            'performance' => $this->tab === 'performance' ? $this->performanceData() : collect(),
            'userNames' => User::pluck('name', 'id'),
            'distribution' => $this->tab === 'distribution' ? $this->distributionData() : null,
            'leadRows' => $this->tab === 'leads' ? $this->leadDetailData() : collect(),
            'leadCustomFields' => $this->tab === 'leads' ? $this->reportCustomFields() : collect(),
            'availableFields' => $this->tab === 'leads' ? $this->availableReportFields() : collect(),
            'canExport' => auth()->user()->hasPermission('lead.export'),
        ];
    }
};
?>

<div>
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-3xl font-bold mb-1">Báo cáo Funnel & Hiệu suất</h1>
            <p class="text-sm text-ink/60">Số liệu từ bảng aggregate tính sẵn, cắt theo khoảng thời gian tùy chọn.</p>
        </div>
        @if ($canExport)
            <button wire:click="export" class="flex items-center gap-2 bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-5 py-2.5 rounded-md">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                Xuất Excel
            </button>
        @endif
    </div>

    {{-- Bộ lọc kỳ --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card px-5 py-4 mb-5 flex flex-wrap items-center gap-3">
        <label class="text-xs font-semibold text-ink/50">Từ</label>
        <input type="date" wire:model.live="from" class="border border-gold-200 rounded-md px-2.5 py-1.5 text-sm focus:outline-none focus:border-gold-500">
        <label class="text-xs font-semibold text-ink/50">Đến</label>
        <input type="date" wire:model.live="to" class="border border-gold-200 rounded-md px-2.5 py-1.5 text-sm focus:outline-none focus:border-gold-500">
        <div class="flex-1"></div>
        @foreach (['funnel' => 'Funnel theo kỳ', 'marketing' => 'Hiệu quả marketing', 'performance' => 'Hiệu suất sale', 'distribution' => 'Chia số & tồn kho', 'leads' => 'Chi tiết lead'] as $key => $label)
            <button wire:click="$set('tab', '{{ $key }}')"
                    class="text-sm font-semibold px-4 py-2 rounded-md {{ $tab === $key ? 'bg-gold-600 text-white' : 'text-ink/60 border border-gold-200 hover:bg-gold-50' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($tab === 'funnel' && $funnel)
        @php
            $steps = [
                ['label' => 'Total', 'value' => (int) $funnel->total],
                ['label' => 'Lead', 'value' => (int) $funnel->lead],
                ['label' => 'Follow', 'value' => (int) $funnel->follow],
                ['label' => 'Nét', 'value' => (int) $funnel->net],
                ['label' => 'Booking', 'value' => (int) $funnel->booking],
                ['label' => 'Show', 'value' => (int) $funnel->show],
                ['label' => 'Close', 'value' => (int) $funnel->close],
            ];
            $max = max(1, $steps[0]['value']);
        @endphp
        <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
            <div class="space-y-3">
                @foreach ($steps as $index => $step)
                    <div class="flex items-center gap-4">
                        <span class="w-20 text-sm font-semibold text-right">{{ $step['label'] }}</span>
                        <div class="flex-1 bg-gold-50 rounded-full h-8 relative overflow-hidden">
                            <div class="absolute inset-y-0 left-0 bg-gold-600/80 rounded-full flex items-center px-3"
                                 style="width: {{ max(4, round($step['value'] / $max * 100)) }}%">
                                <span class="text-xs font-bold text-white">{{ number_format($step['value']) }}</span>
                            </div>
                        </div>
                        <span class="w-24 text-xs text-ink/50">
                            @if ($index > 0 && $steps[$index - 1]['value'] > 0)
                                ↓ {{ round($step['value'] / $steps[$index - 1]['value'] * 100, 1) }}%
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
            <div class="border-t border-gold-100 mt-6 pt-4 flex items-center justify-between text-sm">
                <span class="text-ink/60">Tỉ lệ chuyển đổi tổng (Close/Total):
                    <strong class="text-gold-700">{{ $funnel->total > 0 ? round($funnel->close / $funnel->total * 100, 1) : 0 }}%</strong>
                </span>
                <span class="text-ink/60">Doanh thu thực thu kỳ này:
                    <strong class="font-mono text-green-700">{{ number_format((int) $funnel->revenue) }}₫</strong>
                </span>
            </div>
        </div>
    @endif

    @if ($tab === 'marketing')
        <div class="bg-white border border-gold-200 rounded-xl shadow-card">
            <div class="px-5 py-3 border-b border-gold-100 flex items-center gap-2">
                <span class="text-xs font-semibold text-ink/50">Cắt theo:</span>
                @foreach (['camp' => 'Camp', 'ad_source' => 'Nguồn quảng cáo', 'page' => 'PAGE'] as $key => $label)
                    <button wire:click="$set('groupBy', '{{ $key }}')"
                            class="text-xs font-semibold px-3 py-1.5 rounded-md {{ $groupBy === $key ? 'bg-gold-100 text-gold-800 border border-gold-300' : 'text-ink/50 hover:bg-gold-50' }}">{{ $label }}</button>
                @endforeach
            </div>
            <div class="overflow-x-auto">
            <table class="w-full text-sm min-w-[560px]">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-ink/50 bg-gold-50/60">
                        <th class="px-5 py-3 font-semibold">{{ ['camp' => 'Camp', 'ad_source' => 'Nguồn', 'page' => 'Page'][$groupBy] }}</th>
                        <th class="px-5 py-3 font-semibold text-right">Lead về</th>
                        <th class="px-5 py-3 font-semibold text-right">Booking</th>
                        <th class="px-5 py-3 font-semibold text-right">Close</th>
                        <th class="px-5 py-3 font-semibold text-right">Tỉ lệ close</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gold-100">
                    @forelse ($marketing as $row)
                        <tr class="hover:bg-gold-50/40">
                            <td class="px-5 py-3 font-semibold">{{ $row->dim }}</td>
                            <td class="px-5 py-3 text-right">{{ number_format($row->total) }}</td>
                            <td class="px-5 py-3 text-right">{{ number_format($row->bookings) }}</td>
                            <td class="px-5 py-3 text-right font-semibold text-green-700">{{ number_format($row->closes) }}</td>
                            <td class="px-5 py-3 text-right font-mono">{{ $row->total > 0 ? round($row->closes / $row->total * 100, 1) : 0 }}%</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-8 text-center text-ink/40">Không có dữ liệu trong kỳ.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
    @endif

    @if ($tab === 'performance')
        <div class="bg-white border border-gold-200 rounded-xl shadow-card overflow-x-auto">
            <table class="w-full text-sm min-w-[720px]">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-ink/50 bg-gold-50/60">
                        <th class="px-5 py-3 font-semibold w-14">#</th>
                        <th class="px-5 py-3 font-semibold">Sale</th>
                        <th class="px-5 py-3 font-semibold text-right">Số nhận</th>
                        <th class="px-5 py-3 font-semibold text-right">Booking</th>
                        <th class="px-5 py-3 font-semibold text-right">Close</th>
                        <th class="px-5 py-3 font-semibold text-right">Tỉ lệ close</th>
                        <th class="px-5 py-3 font-semibold text-right">Doanh thu thực thu</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gold-100">
                    @forelse ($performance as $index => $row)
                        <tr class="hover:bg-gold-50/40">
                            <td class="px-5 py-3">
                                <span class="w-6 h-6 rounded-full {{ $index === 0 ? 'bg-gold-600 text-white' : 'bg-gold-100 text-gold-700' }} inline-flex items-center justify-center text-xs font-bold">{{ $index + 1 }}</span>
                            </td>
                            <td class="px-5 py-3 font-semibold">{{ $userNames[$row->user_id] ?? '—' }}</td>
                            <td class="px-5 py-3 text-right">{{ number_format($row->total) }}</td>
                            <td class="px-5 py-3 text-right">{{ number_format($row->bookings) }}</td>
                            <td class="px-5 py-3 text-right font-semibold text-green-700">{{ number_format($row->closes) }}</td>
                            <td class="px-5 py-3 text-right font-mono">{{ $row->total > 0 ? round($row->closes / $row->total * 100, 1) : 0 }}%</td>
                            <td class="px-5 py-3 text-right font-mono font-bold text-green-700">{{ number_format((int) $row->revenue) }}₫</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-5 py-8 text-center text-ink/40">Không có dữ liệu trong kỳ.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($tab === 'distribution' && $distribution)
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold mb-4">Log phân bổ trong kỳ</h2>
                <div class="space-y-3">
                    @foreach (['distribute' => 'Chia tự động (engine)', 'manual_assign' => 'Chia tay', 'recall' => 'Thu hồi', 'pull' => 'Kéo từ kho'] as $action => $label)
                        <div class="flex items-center justify-between text-sm border-b border-gold-50 pb-2">
                            <span>{{ $label }}</span>
                            <span class="font-mono font-bold text-lg">{{ number_format((int) ($distribution['logs'][$action] ?? 0)) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold mb-4">Tồn kho hiện tại (3 cấp)</h2>
                <div class="space-y-3">
                    @foreach (['common' => 'Kho chung', 'team' => 'Kho team', 'personal' => 'Kho cá nhân'] as $key => $label)
                        <div class="flex items-center justify-between text-sm border-b border-gold-50 pb-2">
                            <span>{{ $label }}</span>
                            <span class="font-mono font-bold text-lg">{{ number_format($distribution['pools'][$key]) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    @if ($tab === 'leads')
        <div class="bg-white border border-gold-200 rounded-xl shadow-card">
            <div class="px-5 py-4 border-b border-gold-100 space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="font-bold">Chi tiết lead <span class="text-xs font-normal text-ink/40">(tối đa 500 dòng trong kỳ)</span></h2>
                    {{-- Kiểu hiển thị mã KH --}}
                    <div class="flex items-center gap-1">
                        @foreach (['full' => 'Hiển thị full mã', 'required' => 'Hiển thị mã bắt buộc', 'simple' => 'Hiển thị đơn giản'] as $mode => $mlabel)
                            <button wire:click="$set('codeMode', '{{ $mode }}')"
                                    class="text-xs font-semibold px-3 py-1.5 rounded-md {{ $codeMode === $mode ? 'bg-gold-600 text-white' : 'text-ink/60 border border-gold-200 hover:bg-gold-50' }}">{{ $mlabel }}</button>
                        @endforeach
                    </div>
                </div>
                {{-- Bộ tick chọn cột trường tùy biến --}}
                <div x-data="{ open: false }" class="relative">
                    <button @click="open = !open" class="text-sm font-semibold text-gold-700 border border-gold-200 rounded-md px-3 py-1.5 hover:bg-gold-50">
                        Cột hiển thị ({{ $leadCustomFields->count() }}/{{ $availableFields->count() }}) ▾
                    </button>
                    <div x-show="open" @click.outside="open = false" x-cloak
                         class="absolute z-20 mt-2 w-72 max-h-72 overflow-y-auto bg-white border border-gold-200 rounded-lg shadow-card p-3 space-y-1.5">
                        @forelse ($availableFields as $cf)
                            <label class="flex items-center gap-2 text-sm cursor-pointer hover:bg-gold-50 rounded px-2 py-1">
                                <input type="checkbox" wire:model.live="selectedFieldIds" value="{{ $cf->id }}" class="rounded border-gold-300 text-gold-600 focus:ring-gold-500 w-4 h-4">
                                {{ $cf->label }}
                                <span class="text-[10px] text-ink/40">{{ $cf->org_unit_id === null ? '(cty)' : ($cf->orgUnit?->name) }}</span>
                            </label>
                        @empty
                            <p class="text-sm text-ink/40 px-2 py-1">Chưa có trường tùy biến nào.</p>
                        @endforelse
                    </div>
                </div>
            </div>
            @php $cfLabels = \App\Models\CustomField::labelMap($leadCustomFields); @endphp
            <div class="overflow-x-auto">
                <table class="w-full text-sm min-w-[800px]">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wider text-ink/50 bg-gold-50/60">
                            <th class="px-4 py-3 font-semibold">Mã KH</th>
                            <th class="px-4 py-3 font-semibold">Họ tên khách</th>
                            <th class="px-4 py-3 font-semibold">Nguồn</th>
                            <th class="px-4 py-3 font-semibold">Người thu thập</th>
                            <th class="px-4 py-3 font-semibold">Người phụ trách</th>
                            <th class="px-4 py-3 font-semibold">Ngày thu thập</th>
                            @foreach ($leadCustomFields as $cf)
                                <th class="px-4 py-3 font-semibold whitespace-nowrap">
                                    {{ $cfLabels[$cf->id] ?? $cf->label }}
                                    @if ($cf->org_unit_id === null && ($cfLabels[$cf->id] ?? $cf->label) === $cf->label)<span class="text-[10px] text-ink/30">(cty)</span>@endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gold-100">
                        @forelse ($leadRows as $lead)
                            @php $vals = $lead->customValues->pluck('value', 'custom_field_id'); @endphp
                            <tr class="hover:bg-gold-50/40">
                                <td class="px-4 py-2.5 font-mono text-xs text-gold-700">{{ $this->leadCode($lead) }}</td>
                                <td class="px-4 py-2.5 font-medium">{{ $lead->name }}</td>
                                <td class="px-4 py-2.5">{{ $lead->ad_source ?: '—' }}</td>
                                <td class="px-4 py-2.5">{{ $lead->receiver?->name ?? '—' }}</td>
                                <td class="px-4 py-2.5">{{ $lead->owner?->name ?? '—' }}</td>
                                <td class="px-4 py-2.5">{{ $lead->received_date?->format('d/m/Y') }}</td>
                                @foreach ($leadCustomFields as $cf)
                                    <td class="px-4 py-2.5">
                                        {{ $cf->field_type === 'select' ? ($cf->optionLabel($vals[$cf->id] ?? '') ?: '—') : ($vals[$cf->id] ?? '—') }}
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ 6 + $leadCustomFields->count() }}" class="px-5 py-8 text-center text-ink/40">Không có lead trong kỳ.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
