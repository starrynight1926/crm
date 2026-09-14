<?php

use App\Models\AuditLog;
use App\Models\CustomField;
use App\Models\Lead;
use App\Models\LeadDistributionLog;
use App\Models\OrgUnit;
use App\Models\ReportTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

new class extends Component
{
    /** Tab cấp trên: theo team (báo cáo dạng bảng đếm phân loại/kết quả) / tổng (funnel aggregate cũ). */
    public string $section = 'team'; // team / overall

    /** Team (org_unit) đang xem ở tab "Báo cáo theo team". */
    public ?int $teamId = null;

    /** Mẫu báo cáo đang chọn cho team đó. */
    public ?int $templateId = null;

    /** Filter per-column (list mode) kiểu Excel: [column_key => [value1, value2]]. Empty = all. */
    public array $columnFilters = [];

    public string $tab = 'funnel'; // funnel / marketing / performance / distribution / leads / daily

    /** Ngày báo cáo cho tab "GR Daily Report" (Y-m-d). Mặc định hôm nay. */
    public string $dailyDate = '';

    public string $from = '';

    public string $to = '';

    public string $groupBy = 'camp'; // cho tab marketing: camp / page

    /** Filter phase cho tab funnel/performance. Rỗng = tất cả. Giá trị: '' | 'booking' | 'sale'. */
    public string $fPhase = '';

    /** 2026-08-04 (T4): Filter tab "Chi tiết lead" theo cơ sở + kho số. Null = tất cả. */
    public ?int $fFacilityId = null;
    public ?int $fPoolId = null;

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
        if ($src = $lead->sourceGroupCode()) {
            $core .= '-' . $src;
        }
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
        $q = $this->seesAllReports() ? Lead::query() : Lead::visibleTo(auth()->user());

        // 2026-08-04 (T4): filter cơ sở + kho số — chỉ apply ở tab Chi tiết lead
        // để không lệch số ở funnel/marketing/performance (giữ nguyên semantic report cũ).
        if ($this->tab === 'leads') {
            if ($this->fFacilityId) {
                $q->where('facility_id', $this->fFacilityId);
            }
            if ($this->fPoolId) {
                $pool = \App\Models\PoolUnit::find($this->fPoolId);
                if ($pool) {
                    $poolIds = $pool->subtreeIds();
                    $orgIds = \DB::table('org_pool_map')->whereIn('pool_unit_id', $poolIds)
                        ->pluck('org_unit_id')->all();
                    if ($orgIds) {
                        $subtreeIds = \App\Models\OrgUnit::whereIn('id', $orgIds)->get()
                            ->flatMap(fn ($o) => \App\Models\OrgUnit::where('path', 'like', $o->path.'%')->pluck('id'))
                            ->unique()->all();
                        $q->whereIn('org_unit_id', $subtreeIds);
                    } else {
                        $q->whereRaw('1=0'); // pool chưa map với org nào → 0 lead
                    }
                }
            }
        }

        return $q;
    }

    private function leadDetailData()
    {
        return $this->reportLeadQuery()
            ->whereBetween('received_date', [$this->from, $this->to])
            ->with([
                'customValues', 'owner', 'receiver', 'customerServices.service',
                'statusLogs' => fn ($q) => $q->where('field', 'note')->where('is_return', true)->whereNotNull('reception_code')->select('id', 'lead_id', 'reception_code', 'created_at'),
            ])
            ->withMin('payments', 'paid_at') // Ngày ghi nhận doanh thu = lần thu đầu tiên
            ->withCount(['statusLogs as return_count' => fn ($q) => $q->where('field', 'note')->where('is_return', true)])
            ->orderByDesc('received_date')
            ->limit(500)
            ->get();
    }

    /** Tên các dịch vụ khách đang dùng, gộp bằng dấu phẩy. */
    private function leadServices(Lead $lead): string
    {
        return $lead->customerServices->map(fn ($cs) => $cs->service?->name)->filter()->unique()->join(', ');
    }

    /** Các mã tiếp đón của lead (từ ghi chú "khách trở lại"), gộp bằng dấu phẩy. */
    private function leadReceptionCodes(Lead $lead): string
    {
        return $lead->statusLogs->pluck('reception_code')->filter()->join(', ');
    }

    public function mount(): void
    {
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
        $this->dailyDate = now()->toDateString();

        $prefs = auth()->user()->report_prefs ?? [];
        $this->codeMode = in_array($prefs['code_mode'] ?? null, array_keys(self::CODE_MODES), true)
            ? $prefs['code_mode'] : 'full';
        // Mặc định lần đầu: hiện mọi trường ứng viên
        $this->selectedFieldIds = array_map('intval', $prefs['lead_fields']
            ?? $this->availableReportFields()->pluck('id')->all());

        // Team mặc định: Team Hợi nếu thấy được, ngược lại team đầu tiên CÓ báo cáo trong cây.
        $teams = $this->visibleTeams();
        $this->teamId = $teams->firstWhere('code', 'team-hoi')?->id
            ?? $teams->firstWhere('has_report', true)?->id;
        $this->templateId = $this->teamTemplates()->first()?->id;
    }

    /** Đổi team → chọn lại mẫu đầu tiên của team đó. */
    public function updatedTeamId(): void
    {
        $this->templateId = $this->teamTemplates()->first()?->id;
        $this->applyTemplateDateRange();
    }

    /** Đổi mẫu → auto set Từ/Đến theo date_range của mẫu (list mode); user vẫn override được sau đó. */
    public function updatedTemplateId(): void
    {
        $this->columnFilters = [];
        $this->applyTemplateDateRange();
    }

    public function toggleColumnFilter(string $col, string $val): void
    {
        $curr = $this->columnFilters[$col] ?? [];
        if (in_array($val, $curr, true)) {
            $curr = array_values(array_filter($curr, fn ($v) => $v !== $val));
        } else {
            $curr[] = $val;
        }
        if (empty($curr)) {
            unset($this->columnFilters[$col]);
        } else {
            $this->columnFilters[$col] = array_values($curr);
        }
    }

    public function clearColumnFilter(string $col): void
    {
        unset($this->columnFilters[$col]);
    }

    private function applyTemplateDateRange(): void
    {
        if (! $this->templateId) return;
        $tpl = ReportTemplate::where('org_unit_id', $this->teamId)->find($this->templateId);
        if (! $tpl || ! $tpl->isList()) return;
        $range = $tpl->filters()['date_range'] ?? null;
        if (! $range || $range === 'custom') return;
        [$from, $to] = $this->resolveDateRange($range);
        $this->from = $from->format('Y-m-d');
        $this->to = $to->format('Y-m-d');
    }

    /** Sau khi lưu/xóa mẫu ở modal → làm mới danh sách, giữ mẫu hợp lệ. */
    #[On('templates-updated')]
    public function refreshTemplates(): void
    {
        $ids = $this->teamTemplates()->pluck('id');
        if (! $ids->contains($this->templateId)) {
            $this->templateId = $ids->first();
        }
    }

    /** Mẫu báo cáo của team đang chọn. */
    private function teamTemplates()
    {
        return $this->teamId
            ? ReportTemplate::where('org_unit_id', $this->teamId)->orderBy('name')->get()
            : collect();
    }

    /**
     * Trả cả cây org units (theo path) — mỗi node kèm cờ:
     *   - has_report: có custom field báo cáo riêng ở node đó → chọn được.
     *   - depth: dùng để indent trong <option>.
     * Node không có báo cáo vẫn hiện (dạng tiêu đề, disabled) để user thấy cấu trúc.
     */
    private function visibleTeams()
    {
        // Tập org có field báo cáo trực tiếp gắn vào chính nó.
        $directReportOrgIds = CustomField::query()
            ->where('active', true)
            ->where('status', CustomField::STATUS_ACTIVE)
            ->whereIn('field_type', ['select', 'tick'])
            ->whereNotNull('org_unit_id')
            ->get(['org_unit_id', 'field_type', 'options'])
            ->filter(fn ($f) => $f->field_type === 'tick' || count($f->options ?? []) >= 2)
            ->pluck('org_unit_id')
            ->unique()
            ->all();

        $orgs = OrgUnit::query()
            ->when(! $this->seesAllReports(), function ($q) {
                $ids = auth()->user()->visibleOrgUnitIds();
                $q->whereIn('id', $ids ?: [-1]);
            })
            ->orderBy('path')
            ->get(['id', 'name', 'code', 'depth', 'path']);

        // Node "chọn được" nếu bất kỳ đâu trên nhánh (subtree hoặc ancestor) có field
        // — template-manager sẽ tự pull field theo cùng logic (subtree → fallback ancestor gần nhất).
        $orgPathById = $orgs->keyBy('id')->map(fn ($o) => $o->path);
        $reportableIds = collect();
        foreach ($directReportOrgIds as $fieldOrgId) {
            $fieldPath = $orgPathById[$fieldOrgId] ?? null;
            if (! $fieldPath) continue;
            foreach ($orgs as $o) {
                // Bật nếu: field nằm trong subtree của node ($fieldPath bắt đầu bằng $o->path)
                // HOẶC field nằm trên ancestor của node ($o->path bắt đầu bằng $fieldPath).
                if (str_starts_with($fieldPath, $o->path) || str_starts_with($o->path, $fieldPath)) {
                    $reportableIds[$o->id] = true;
                }
            }
        }

        return $orgs->map(function ($o) use ($reportableIds) {
            $o->has_report = isset($reportableIds[$o->id]);
            $o->display_name = str_repeat('— ', max(0, (int) $o->depth)) . $o->name;
            return $o;
        });
    }

    /**
     * Báo cáo theo team, render theo MẪU đang chọn (report_templates):
     *  - total: tổng lead trong kỳ thuộc team.
     *  - columns: mỗi cột (option của select, hoặc 1 field tick) kèm count + tỉ lệ / total.
     *  - owners: breakdown theo người phụ trách nếu mẫu bật (owner config).
     * Trả 'no_template' = true nếu team chưa có mẫu nào.
     */
    private function teamReport(): ?array
    {
        $team = $this->teamId ? OrgUnit::find($this->teamId) : null;
        if (! $team) {
            return null;
        }

        $template = $this->templateId
            ? ReportTemplate::where('org_unit_id', $team->id)->find($this->templateId)
            : null;
        if (! $template) {
            return ['team' => $team, 'no_template' => true];
        }

        // List mode: render bảng từng khách theo filter riêng của template (không dùng from/to global).
        if ($template->isList()) {
            return $this->buildListReport($team, $template);
        }

        $leadIds = $this->reportLeadQuery()
            ->whereIn('org_unit_id', $team->subtreeIds())
            ->whereBetween('received_date', [$this->from, $this->to])
            ->pluck('id');
        $total = $leadIds->count();

        $fieldIds = collect($template->columns())->pluck('field_id')->filter()->unique();
        $customIds = $fieldIds->filter(fn ($id) => $id > 0);
        $systemIds = $fieldIds->filter(fn ($id) => $id < 0);
        $fields = CustomField::whereIn('id', $customIds)->get()->keyBy('id');
        // Định nghĩa field hệ thống — đồng bộ với template-manager.
        $sysDefs = [
            -1  => ['label' => 'Lần đầu',        'source' => 'first_visit',          'type' => 'tick'],
            -2  => ['label' => 'Trở lại',        'source' => 'return',               'type' => 'tick'],
            -3  => ['label' => 'Đã đặt booking', 'source' => 'booked',               'type' => 'tick'],
            -4  => ['label' => 'Show',           'source' => 'classification_show',  'type' => 'tick'],
            -5  => ['label' => 'Close',          'source' => 'classification_close', 'type' => 'tick'],
            -10 => ['label' => 'Phân loại kết quả', 'source' => 'classification',    'type' => 'select', 'labels' => \App\Models\Lead::CLASSIFICATIONS],
            -11 => ['label' => 'Trạng thái đặt lịch','source' => 'booking_status',    'type' => 'select', 'labels' => \App\Models\Lead::BOOKING_STATUSES],
        ];

        // Metric = 1 cột: select→(field,option) | tick→(field) | system→(field_id âm).
        $metrics = [];
        foreach ($template->columns() as $col) {
            $fid = (int) ($col['field_id'] ?? 0);
            if ($fid < 0) {
                $def = $sysDefs[$fid] ?? null;
                if (! $def) continue;
                if ($def['type'] === 'tick') {
                    $metrics[] = ['label' => $def['label'], 'field_id' => $fid, 'type' => 'system_tick', 'source' => $def['source'], 'value' => null];
                } else {
                    foreach ($col['options'] ?? [] as $opt) {
                        $label = $def['labels'][$opt] ?? $opt;
                        $metrics[] = ['label' => $def['label'] . ': ' . $label, 'field_id' => $fid, 'type' => 'system_select', 'source' => $def['source'], 'value' => $opt];
                    }
                }
                continue;
            }
            $f = $fields->get($fid);
            if (! $f) continue;
            if (($col['type'] ?? '') === 'tick') {
                $metrics[] = ['label' => $f->label, 'field_id' => $f->id, 'type' => 'tick', 'value' => null];
            } else {
                foreach ($col['options'] ?? [] as $opt) {
                    $metrics[] = ['label' => $f->optionLabel($opt), 'field_id' => $f->id, 'type' => 'select', 'value' => $opt];
                }
            }
        }

        // Đếm CustomField theo (owner, field, value).
        $grouped = $leadIds->isEmpty() ? collect() : DB::table('leads')
            ->join('lead_custom_values as v', 'v.lead_id', '=', 'leads.id')
            ->whereIn('leads.id', $leadIds)
            ->whereIn('v.custom_field_id', $customIds->all() ?: [-1])
            ->selectRaw('leads.owner_id, v.custom_field_id, v.value, count(*) c')
            ->groupBy('leads.owner_id', 'v.custom_field_id', 'v.value')
            ->get();

        // Chỉ số hệ thống theo owner — đếm distinct lead_id per (owner, source).
        $sysCounts = collect();
        if ($systemIds->isNotEmpty() && $leadIds->isNotEmpty()) {
            $needFirstVisit = $systemIds->contains(-1);
            $needReturn = $systemIds->contains(-2);
            $needBooked = $systemIds->contains(-3);
            $needShow = $systemIds->contains(-4);
            $needClose = $systemIds->contains(-5);

            if ($needBooked || $needShow || $needClose) {
                $leadRows = DB::table('leads')->whereIn('id', $leadIds)
                    ->selectRaw("owner_id, sum(booking_status = 'booked') booked, sum(classification = 'show') show_c, sum(classification = 'close') close_c")
                    ->groupBy('owner_id')->get();
                foreach ($leadRows as $r) {
                    if ($needBooked && $r->booked > 0) $sysCounts->push(['owner_id' => $r->owner_id, 'source' => 'booked', 'c' => (int) $r->booked]);
                    if ($needShow && $r->show_c > 0) $sysCounts->push(['owner_id' => $r->owner_id, 'source' => 'classification_show', 'c' => (int) $r->show_c]);
                    if ($needClose && $r->close_c > 0) $sysCounts->push(['owner_id' => $r->owner_id, 'source' => 'classification_close', 'c' => (int) $r->close_c]);
                }
            }
            if ($needFirstVisit) {
                $rows = DB::table('lead_status_logs')
                    ->join('leads', 'leads.id', '=', 'lead_status_logs.lead_id')
                    ->whereIn('leads.id', $leadIds)
                    ->where('lead_status_logs.is_first_visit', true)
                    ->selectRaw('leads.owner_id, count(distinct leads.id) c')
                    ->groupBy('leads.owner_id')->get();
                foreach ($rows as $r) $sysCounts->push(['owner_id' => $r->owner_id, 'source' => 'first_visit', 'c' => (int) $r->c]);
            }
            if ($needReturn) {
                $rows = DB::table('lead_status_logs')
                    ->join('leads', 'leads.id', '=', 'lead_status_logs.lead_id')
                    ->whereIn('leads.id', $leadIds)
                    ->where('lead_status_logs.is_return', true)
                    ->selectRaw('leads.owner_id, count(distinct leads.id) c')
                    ->groupBy('leads.owner_id')->get();
                foreach ($rows as $r) $sysCounts->push(['owner_id' => $r->owner_id, 'source' => 'return', 'c' => (int) $r->c]);
            }

            // System select breakdown: classification (-10) và booking_status (-11).
            if ($systemIds->contains(-10)) {
                $rows = DB::table('leads')->whereIn('id', $leadIds)
                    ->selectRaw('owner_id, classification as v, count(*) c')
                    ->groupBy('owner_id', 'classification')->get();
                foreach ($rows as $r) $sysCounts->push(['owner_id' => $r->owner_id, 'source' => 'classification', 'value' => $r->v, 'c' => (int) $r->c]);
            }
            if ($systemIds->contains(-11)) {
                $rows = DB::table('leads')->whereIn('id', $leadIds)
                    ->selectRaw('owner_id, booking_status as v, count(*) c')
                    ->groupBy('owner_id', 'booking_status')->get();
                foreach ($rows as $r) $sysCounts->push(['owner_id' => $r->owner_id, 'source' => 'booking_status', 'value' => $r->v, 'c' => (int) $r->c]);
            }
        }

        // Đếm 1 metric: custom → tính từ $customRows; system → tính từ $sysCounts, filter theo $ownerId nếu truyền.
        $countMetric = function (array $m, $customRows, $ownerId = null) use ($sysCounts): int {
            if ($m['type'] === 'system_tick') {
                $q = $sysCounts->where('source', $m['source']);
                if ($ownerId !== null) $q = $q->where('owner_id', $ownerId);
                return (int) $q->sum('c');
            }
            if ($m['type'] === 'system_select') {
                $q = $sysCounts->where('source', $m['source'])->where('value', $m['value']);
                if ($ownerId !== null) $q = $q->where('owner_id', $ownerId);
                return (int) $q->sum('c');
            }
            if ($m['type'] === 'tick') {
                return (int) $customRows->where('custom_field_id', $m['field_id'])
                    ->reject(fn ($r) => $r->value === null || $r->value === '')->sum('c');
            }
            return (int) $customRows->where('custom_field_id', $m['field_id'])->where('value', $m['value'])->sum('c');
        };

        // Bảng tổng
        $totalsRow = array_map(fn ($m) => [
            'label' => $m['label'],
            'count' => $c = $countMetric($m, $grouped),
            'rate' => $total > 0 ? round($c / $total * 100) : 0,
        ], $metrics);

        // Bảng theo người phụ trách
        $ownerRows = [];
        if ($template->showByOwner()) {
            $ownerTotals = $leadIds->isEmpty() ? collect() : DB::table('leads')
                ->whereIn('id', $leadIds)->selectRaw('owner_id, count(*) c')->groupBy('owner_id')->pluck('c', 'owner_id');
            $names = User::pluck('name', 'id');
            $byOwner = $grouped->groupBy('owner_id');
            foreach ($ownerTotals as $ownerId => $cnt) {
                $rows = $byOwner->get($ownerId, collect());
                $ownerRows[] = [
                    'name' => $ownerId ? ($names[$ownerId] ?? '#' . $ownerId) : 'Chưa chia',
                    'total' => (int) $cnt,
                    'cells' => array_map(fn ($m) => $countMetric($m, $rows, $ownerId), $metrics),
                ];
            }
            usort($ownerRows, fn ($a, $b) => $b['total'] <=> $a['total']);
        }

        return [
            'team' => $team,
            'template' => $template,
            'total' => $total,
            'metrics' => array_map(fn ($m) => $m['label'], $metrics),
            'totalsRow' => $totalsRow,
            'showTotals' => $template->showTotals(),
            'showByOwner' => $template->showByOwner(),
            'ownerRows' => $ownerRows,
        ];
    }

    /**
     * List mode — build danh sách lead theo filter template, resolve các cột hiển thị.
     */
    private function buildListReport(OrgUnit $team, ReportTemplate $template): array
    {
        $filters = $template->filters();
        $dateField = $filters['date_field'] ?? 'received_date';
        // Global Từ/Đến (picker) luôn thắng — template.date_range chỉ là default lúc load mẫu.
        $from = \Carbon\Carbon::parse($this->from)->startOfDay();
        $to = \Carbon\Carbon::parse($this->to)->endOfDay();

        $q = $this->reportLeadQuery()
            ->whereIn('leads.org_unit_id', $team->subtreeIds())
            ->whereBetween('leads.' . $dateField, [$from, $to]);

        if (! empty($filters['classification'])) {
            $q->whereIn('leads.classification', $filters['classification']);
        }
        if (! empty($filters['source_group'])) {
            $q->whereIn('leads.source_group', $filters['source_group']);
        }
        if (! empty($filters['booking_status'])) {
            $q->whereIn('leads.booking_status', $filters['booking_status']);
        }

        $leads = $q->with(['owner:id,name', 'receiver:id,name', 'facility.parent'])
            ->orderBy('leads.' . $dateField)->get();

        // Distinct values cho từng cột filterable — dùng cho dropdown Excel-like.
        $filterable = ['classification', 'source_group', 'booking_status', 'facility', 'owner', 'receiver'];
        $distinctValues = [];
        foreach ($filterable as $col) {
            if (! in_array($col, $template->columns(), true)) continue;
            $vals = $leads->map(fn ($l) => $this->getFilterValue($l, $col))
                ->unique()->filter(fn ($v) => $v !== null && $v !== '')->sort()->values();
            $distinctValues[$col] = $vals->all();
        }

        // Apply column filters — post-query (in-memory).
        $filteredLeads = $leads;
        foreach ($this->columnFilters as $col => $vals) {
            if (empty($vals)) continue;
            $filteredLeads = $filteredLeads->filter(fn ($l) => in_array($this->getFilterValue($l, $col), $vals, true));
        }

        return [
            'team' => $team,
            'template' => $template,
            'list_mode' => true,
            'leads' => $filteredLeads->values(),
            'columns' => $template->columns(),
            'date_from' => $from,
            'date_to' => $to,
            'date_field_label' => ReportTemplate::DATE_FIELDS[$dateField] ?? $dateField,
            'distinct_values' => $distinctValues,
            'filterable_cols' => $filterable,
        ];
    }

    /** Value dùng để filter/hiển thị trong dropdown per column. */
    private function getFilterValue($lead, string $col)
    {
        return match ($col) {
            'classification' => Lead::CLASSIFICATIONS[$lead->classification] ?? $lead->classification,
            'source_group'   => Lead::SOURCE_GROUPS[$lead->source_group] ?? $lead->source_group,
            'booking_status' => Lead::BOOKING_STATUSES[$lead->booking_status] ?? $lead->booking_status,
            'facility'       => $lead->rootFacilityName(),
            'owner'          => $lead->owner?->name,
            'receiver'       => $lead->receiver?->name,
            default          => null,
        };
    }

    private function resolveDateRange(string $range): array
    {
        return match ($range) {
            'today'      => [now()->startOfDay(), now()->endOfDay()],
            'yesterday'  => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
            'this_week'  => [now()->startOfWeek(), now()->endOfWeek()],
            'this_month' => [now()->startOfMonth(), now()->endOfMonth()],
            'last_month' => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
            default      => [$this->from, $this->to], // 'custom' → dùng date picker global
        };
    }

    /** Export list-mode template ra Excel. */
    public function exportListTemplate()
    {
        $team = $this->teamId ? OrgUnit::find($this->teamId) : null;
        $template = $this->templateId ? ReportTemplate::where('org_unit_id', $team?->id)->find($this->templateId) : null;
        abort_unless($template && $template->isList(), 404);

        $report = $this->buildListReport($team, $template);
        $columns = $report['columns'];
        $colLabels = ReportTemplate::LIST_COLUMNS;

        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle(mb_substr($template->name, 0, 31));

        // Header row
        foreach ($columns as $idx => $col) {
            $sheet->setCellValue(chr(65 + $idx) . '1', $colLabels[$col] ?? $col);
        }
        $sheet->getStyle('A1:' . chr(64 + count($columns)) . '1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '8B6F47']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ]);

        $renderCell = function ($lead, $col, $stt) {
            return match ($col) {
                'stt'            => $stt,
                'code'           => $lead->code,
                'received_date'  => optional($lead->received_date)->format('d/m/Y'),
                'facility'       => $lead->rootFacilityName() ?? '',
                'name'           => $lead->name,
                'phone'          => $lead->phone,
                'birthday'       => optional($lead->birthday)->format('d/m/Y'),
                'address'        => $lead->address,
                'occupation'     => $lead->occupation,
                'owner'          => $lead->owner?->name ?? '',
                'receiver'       => $lead->receiver?->name ?? '',
                'source_group'   => Lead::SOURCE_GROUPS[$lead->source_group] ?? '',
                'classification' => Lead::CLASSIFICATIONS[$lead->classification] ?? '',
                'booking_status' => Lead::BOOKING_STATUSES[$lead->booking_status] ?? '',
                'booking_ma'     => $lead->booking_ma,
                'booked_at'      => optional($lead->booked_at)->format('d/m/Y'),
                'note'           => $lead->note,
                default          => '',
            };
        };

        $row = 2;
        foreach ($report['leads'] as $stt => $lead) {
            foreach ($columns as $idx => $col) {
                $sheet->setCellValueExplicit(
                    chr(65 + $idx) . $row,
                    (string) $renderCell($lead, $col, $stt + 1),
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                );
            }
            $row++;
        }
        foreach (range('A', chr(64 + count($columns))) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        $filename = 'baocao_' . preg_replace('/\s+/', '_', $template->name) . '_' . now()->format('YmdHi') . '.xlsx';
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save($tmp);

        return response()->download($tmp, $filename)->deleteFileAfterSend(true);
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
            ->when($this->fPhase !== '', fn ($q) => $q->where('pipeline_phase', $this->fPhase))
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

    /**
     * GR Daily Report — báo cáo hàng ngày theo mẫu công ty.
     * Cột: STT | Date check-in | Location (fix "Medical") | Fullname | DOB | Địa chỉ | Sale Book | Sale Care | Nguồn | Note.
     * Sale Book = receiver_id (người nhận & book). Sale Care = owner_id (sale chăm sóc).
     */
    private function dailyReportData(): array
    {
        $date = $this->dailyDate ?: now()->toDateString();
        $leads = $this->reportLeadQuery()
            ->with(['owner:id,name', 'receiver:id,name'])
            ->whereDate('received_date', $date)
            ->orderBy('id')
            ->get([
                'leads.id', 'leads.code', 'leads.name', 'leads.phone', 'leads.birthday',
                'leads.address', 'leads.region', 'leads.received_date', 'leads.source_group',
                'leads.owner_id', 'leads.receiver_id', 'leads.note',
            ]);

        return [
            'date' => $date,
            'traffic' => $leads->count(),
            'leads' => $leads,
        ];
    }

    /** Tab marketing: group theo camp/nguồn/page. Phase 6.20: camp+page là custom_values. */
    private function marketingData()
    {
        $q = $this->reportLeadQuery()->whereBetween('received_date', [$this->from, $this->to]);

        if (in_array($this->groupBy, ['camp', 'page'], true)) {
            $fieldIds = \App\Models\CustomField::where('key', $this->groupBy)->pluck('id')->all();
            $q->leftJoin('lead_custom_values as gb_cv', function ($join) use ($fieldIds) {
                $join->on('gb_cv.lead_id', '=', 'leads.id')->whereIn('gb_cv.custom_field_id', $fieldIds ?: [0]);
            })->selectRaw("COALESCE(gb_cv.value, '(trống)') as dim, count(*) total, sum(classification = 'close') closes, sum(classification = 'booking') bookings")
              ->groupBy('gb_cv.value');
        } else {
            $q->selectRaw("COALESCE(leads.{$this->groupBy}, '(trống)') as dim, count(*) total, sum(classification = 'close') closes, sum(classification = 'booking') bookings")
              ->groupBy("leads.{$this->groupBy}");
        }

        return $q->orderByDesc('total')->limit(50)->get();
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
                ['STT', 'Mã KH', 'Họ tên khách', 'Người thu thập', 'Người phụ trách', 'Ngày thu thập', 'Ngày ghi nhận DT', 'Tần suất quay lại', 'Mã tiếp đón', 'Dịch vụ', 'Ghi chú'],
                $cfs->map(fn ($f) => $cfLabels[$f->id] ?? $f->label)->all()
            );
            $rows = $this->leadDetailData()->values()->map(function ($lead, $i) use ($cfs) {
                $vals = $lead->customValues->pluck('value', 'custom_field_id');
                return array_merge(
                    [
                        $i + 1, $this->leadCode($lead), $lead->name,
                        (string) $lead->receiver?->name, (string) $lead->owner?->name,
                        (string) $lead->received_date?->toDateString(),
                        $lead->payments_min_paid_at ? \Illuminate\Support\Carbon::parse($lead->payments_min_paid_at)->toDateString() : '',
                        (int) $lead->return_count,
                        $this->leadReceptionCodes($lead),
                        $this->leadServices($lead), (string) $lead->note,
                    ],
                    $cfs->map(fn ($f) => $f->field_type === 'select'
                        ? $f->optionLabel((string) ($vals[$f->id] ?? ''))
                        : (string) ($vals[$f->id] ?? ''))->all()
                );
            })->all();
            $sheet->fromArray([$header, ...$rows]);
        } elseif ($this->tab === 'daily') {
            $data = $this->dailyReportData();
            $sheet->setTitle('T' . (int) date('n', strtotime($data['date'])) . '.' . date('Y', strtotime($data['date'])));
            // Tiêu đề + summary phía trên bảng.
            $sheet->setCellValue('A1', 'GR Daily Report — ngày ' . date('d/m/Y', strtotime($data['date'])));
            $sheet->mergeCells('A1:I1');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $sheet->setCellValue('A2', 'Daily traffic (Số lượng khách mới): ' . $data['traffic']);
            $sheet->mergeCells('A2:I2');
            $sheet->getStyle('A2')->getFont()->setItalic(true);

            $headers = ['STT', 'Date check-in', 'Location', 'Fullname', 'DOB',
                'Địa chỉ ( nếu có )', 'Sale Book', 'Sale Care', 'Nguồn', 'Note'];
            $sheet->fromArray($headers, null, 'A4');
            $sheet->getStyle('A4:J4')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle('A4:J4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('7F6000');

            $r = 5;
            foreach ($data['leads'] as $i => $lead) {
                $addr = trim(($lead->address ?? '') . ($lead->region ? (', ' . $lead->region) : ''), ', ');
                $sheet->fromArray([[
                    $i + 1,
                    $lead->received_date?->format('d/m/Y'),
                    'Medical',
                    $lead->name,
                    $lead->birthday ? \Illuminate\Support\Carbon::parse($lead->birthday)->format('d/m/Y') : '',
                    $addr,
                    (string) $lead->receiver?->name,
                    (string) $lead->owner?->name,
                    (string) $lead->source_group,
                    (string) $lead->note,
                ]], null, "A$r");
                $sheet->getStyle("A$r:J$r")->getBorders()->getAllBorders()
                    ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
                $r++;
            }
            $sheet->getStyle('A4:J4')->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            foreach (['A' => 6, 'B' => 14, 'C' => 12, 'D' => 30, 'E' => 12, 'F' => 40, 'G' => 22, 'H' => 22, 'I' => 14, 'J' => 30] as $c => $w) {
                $sheet->getColumnDimension($c)->setWidth($w);
            }
        } else {
            $d = $this->distributionData();
            $sheet->fromArray([['Hành động', 'Số lượt'],
                ['Chia tự động', (int) ($d['logs']['distribute'] ?? 0)],
                ['Chia thủ công', (int) ($d['logs']['manual_assign'] ?? 0)],
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
            'teamReport' => $this->section === 'team' ? $this->teamReport() : null,
            'teams' => $this->section === 'team' ? $this->visibleTeams() : collect(),
            'teamTemplates' => $this->section === 'team' ? $this->teamTemplates() : collect(),
            'funnel' => $this->tab === 'funnel' ? $this->funnelData() : null,
            'daily' => $this->tab === 'daily' ? $this->dailyReportData() : null,
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
        @if ($canExport && $section === 'overall')
            <button wire:click="export" class="flex items-center gap-2 bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-5 py-2.5 rounded-md">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                Xuất Excel
            </button>
        @endif
    </div>

    {{-- Tab cấp trên --}}
    <div class="flex items-center gap-2 mb-5 border-b border-gold-200">
        @foreach (['team' => 'Báo cáo theo team', 'overall' => 'Báo cáo tổng'] as $key => $label)
            <button wire:click="$set('section', '{{ $key }}')"
                    class="text-sm font-semibold px-4 py-2.5 -mb-px border-b-2 {{ $section === $key ? 'border-gold-600 text-gold-700' : 'border-transparent text-ink/50 hover:text-ink/80' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Bộ lọc kỳ (dùng chung 2 tab) --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card px-5 py-4 mb-5 flex flex-wrap items-center gap-3">
        <label class="text-xs font-semibold text-ink/50">Từ</label>
        <x-date-input field="from" class="px-2.5 py-1.5" />
        <label class="text-xs font-semibold text-ink/50">Đến</label>
        <x-date-input field="to" class="px-2.5 py-1.5" />
        @if ($section === 'team')
            <label class="text-xs font-semibold text-ink/50 ml-2">Team</label>
            <select wire:model.live="teamId" class="border border-gold-200 rounded-md px-2.5 py-1.5 text-sm focus:outline-none focus:border-gold-500">
                @forelse ($teams as $t)
                    <option value="{{ $t->id }}" {{ $t->has_report ? '' : 'disabled' }}>{{ $t->display_name }}{{ $t->has_report ? '' : ' — (không có báo cáo)' }}</option>
                @empty
                    <option value="">Chưa có team nào có trường báo cáo</option>
                @endforelse
            </select>
            <label class="text-xs font-semibold text-ink/50 ml-2">Mẫu</label>
            <select wire:model.live="templateId" class="border border-gold-200 rounded-md px-2.5 py-1.5 text-sm focus:outline-none focus:border-gold-500">
                @forelse ($teamTemplates as $tpl)
                    <option value="{{ $tpl->id }}">{{ $tpl->name }}</option>
                @empty
                    <option value="">— Chưa có mẫu —</option>
                @endforelse
            </select>
            @if ($teamId)
                <button wire:click="$dispatch('open-template-manager', { teamId: {{ $teamId }} })"
                        class="text-sm font-semibold text-gold-700 border border-gold-300 rounded-md px-3 py-1.5 hover:bg-gold-50">Quản lý mẫu</button>
            @endif
        @endif
        @if ($section === 'overall' && in_array($tab, ['funnel', 'performance'], true))
            <label class="text-xs font-semibold text-ink/50 ml-2">Phase</label>
            <select wire:model.live="fPhase" class="border border-gold-200 rounded-md px-2.5 py-1.5 text-sm focus:outline-none focus:border-gold-500">
                <option value="">Tất cả</option>
                <option value="{{ \App\Models\Lead::PHASE_BOOKING }}">Booking (Tele)</option>
                <option value="{{ \App\Models\Lead::PHASE_SALE }}">Sale</option>
            </select>
        @endif
        {{-- 2026-08-04 (T4): Filter tab Chi tiết lead theo cơ sở + kho số. --}}
        @if ($section === 'overall' && $tab === 'leads')
            <label class="text-xs font-semibold text-ink/50 ml-2">Cơ sở</label>
            <select wire:model.live="fFacilityId" class="border border-gold-200 rounded-md px-2.5 py-1.5 text-sm focus:outline-none focus:border-gold-500">
                <option value="">Tất cả</option>
                @foreach (\App\Models\Facility::orderBy('name')->get() as $f)
                    <option value="{{ $f->id }}">{{ ($f->parent?->name ? $f->parent->name.' › ' : '').$f->name }}</option>
                @endforeach
            </select>
            <label class="text-xs font-semibold text-ink/50 ml-2">Kho số</label>
            <select wire:model.live="fPoolId" class="border border-gold-200 rounded-md px-2.5 py-1.5 text-sm focus:outline-none focus:border-gold-500">
                <option value="">Tất cả</option>
                @foreach (\App\Models\PoolUnit::where('is_active', true)->orderBy('path')->get() as $p)
                    <option value="{{ $p->id }}">{{ str_repeat('— ', $p->depth) }}{{ $p->name }}</option>
                @endforeach
            </select>
        @endif
        <div class="flex-1"></div>
        @if ($section === 'overall')
            @foreach (['funnel' => 'Funnel theo kỳ', 'marketing' => 'Hiệu quả marketing', 'performance' => 'Hiệu suất sale', 'distribution' => 'Chia số & tồn kho', 'leads' => 'Chi tiết lead', 'daily' => 'GR Daily Report'] as $key => $label)
                <button wire:click="$set('tab', '{{ $key }}')"
                        class="text-sm font-semibold px-4 py-2 rounded-md {{ $tab === $key ? 'bg-gold-600 text-white' : 'text-ink/60 border border-gold-200 hover:bg-gold-50' }}">
                    {{ $label }}
                </button>
            @endforeach
        @endif
    </div>

    {{-- ============ TAB: BÁO CÁO THEO TEAM ============ --}}
    @if ($section === 'team')
        @if (! $teamReport)
            <div class="bg-white border border-gold-200 rounded-xl shadow-card p-8 text-center text-ink/40">Chưa có team nào có trường báo cáo. Cấu hình trường tùy biến cho team trước.</div>
        @elseif (($teamReport['no_template'] ?? false))
            <div class="bg-white border border-gold-200 rounded-xl shadow-card p-8 text-center">
                <p class="text-ink/50 mb-4">Team <strong>{{ $teamReport['team']->name }}</strong> chưa có mẫu báo cáo nào.</p>
                <button wire:click="$dispatch('open-template-manager', { teamId: {{ $teamReport['team']->id }} })"
                        class="bg-gold-600 hover:bg-gold-700 text-white text-sm font-semibold px-5 py-2.5 rounded-md">+ Tạo mẫu báo cáo</button>
            </div>
        @else
            @php $tr = $teamReport; @endphp
            <div class="flex items-center gap-2 mb-4">
                <h2 class="font-bold">{{ $tr['template']->name }}</h2>
                <span class="text-xs text-ink/40">— {{ $tr['team']->name }}</span>
                @if (! empty($tr['list_mode']))
                    <span class="ml-auto text-xs text-ink/50">{{ $tr['date_field_label'] }}: {{ $tr['date_from']->format('d/m/Y') }} → {{ $tr['date_to']->format('d/m/Y') }}</span>
                    <button wire:click="exportListTemplate" class="bg-green-600 hover:bg-green-700 text-white text-sm font-semibold px-4 py-2 rounded-md">📥 Export Excel</button>
                @endif
            </div>

            {{-- LIST MODE — bảng từng khách --}}
            @if (! empty($tr['list_mode']))
                @php
                    $colLabels = \App\Models\ReportTemplate::LIST_COLUMNS;
                    $renderCell = function ($lead, $col, $stt) {
                        return match ($col) {
                            'stt'            => $stt,
                            'code'           => $lead->code,
                            'received_date'  => optional($lead->received_date)->format('d/m/Y'),
                            'facility'       => $lead->rootFacilityName() ?? '',
                            'name'           => $lead->name,
                            'phone'          => $lead->phone,
                            'birthday'       => optional($lead->birthday)->format('d/m/Y'),
                            'address'        => $lead->address,
                            'occupation'     => $lead->occupation,
                            'owner'          => $lead->owner?->name ?? '',
                            'receiver'       => $lead->receiver?->name ?? '',
                            'source_group'   => \App\Models\Lead::SOURCE_GROUPS[$lead->source_group] ?? '',
                            'classification' => \App\Models\Lead::CLASSIFICATIONS[$lead->classification] ?? '',
                            'booking_status' => \App\Models\Lead::BOOKING_STATUSES[$lead->booking_status] ?? '',
                            'booking_ma'     => $lead->booking_ma,
                            'booked_at'      => optional($lead->booked_at)->format('d/m/Y'),
                            'note'           => $lead->note,
                            default          => '',
                        };
                    };
                @endphp
                <div class="bg-white border border-gold-200 rounded-xl shadow-card p-5 mb-6">
                    <p class="text-sm text-ink/60 mb-3">Tổng số: <strong>{{ $tr['leads']->count() }}</strong> khách hàng. <span class="text-ink/40 text-xs">(Kéo mép phải header để đổi chiều rộng cột)</span></p>
                    <div class="overflow-x-auto">
                        <table x-data x-init="window.enableColumnResize && window.enableColumnResize($el)"
                               class="text-sm border border-gold-200 resizable-table" style="table-layout: fixed;">
                            <thead>
                                <tr class="bg-gold-100 text-xs uppercase tracking-wider">
                                    @foreach ($tr['columns'] as $col)
                                        @php
                                            $isFilterable = in_array($col, $tr['filterable_cols'] ?? [], true);
                                            $activeFilter = $columnFilters[$col] ?? [];
                                            $distinct = $tr['distinct_values'][$col] ?? [];
                                        @endphp
                                        <th class="px-3 py-2 font-semibold border border-gold-200 text-left relative" style="min-width: 80px;">
                                            <div class="flex items-center gap-1.5">
                                                <span class="block truncate flex-1">{{ $colLabels[$col] ?? $col }}</span>
                                                @if ($isFilterable && count($distinct) > 0)
                                                    <div x-data="{ open: false }" @click.outside="open = false" class="relative flex-shrink-0">
                                                        <button type="button" @click.stop="open = !open"
                                                                class="p-0.5 rounded hover:bg-gold-200 {{ $activeFilter ? 'text-blue-600 bg-blue-100' : 'text-ink/40 hover:text-gold-700' }}"
                                                                title="{{ $activeFilter ? 'Đang lọc ' . count($activeFilter) . ' giá trị' : 'Lọc cột này' }}">
                                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4h18M6 12h12M10 20h4"/></svg>
                                                        </button>
                                                        <div x-show="open" x-cloak x-transition
                                                             class="absolute z-30 mt-1 right-0 w-56 bg-white border border-gold-200 rounded-lg shadow-lg normal-case tracking-normal">
                                                            <div class="flex items-center justify-between px-3 py-2 border-b border-gold-100">
                                                                <span class="text-xs font-semibold text-gold-700">Lọc {{ $colLabels[$col] ?? $col }}</span>
                                                                @if ($activeFilter)
                                                                    <button wire:click="clearColumnFilter('{{ $col }}')" @click="open = false" class="text-[10px] text-red-600 hover:underline">Xoá</button>
                                                                @endif
                                                            </div>
                                                            <div class="max-h-64 overflow-y-auto py-1">
                                                                @foreach ($distinct as $val)
                                                                    <label class="flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-gold-50 cursor-pointer">
                                                                        <input type="checkbox"
                                                                               wire:click="toggleColumnFilter('{{ $col }}', @js($val))"
                                                                               @if (in_array($val, $activeFilter, true)) checked @endif
                                                                               class="rounded border-gold-300 text-gold-600 w-3.5 h-3.5">
                                                                        <span class="truncate">{{ $val }}</span>
                                                                    </label>
                                                                @endforeach
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endif
                                            </div>
                                            <span class="col-resizer absolute top-0 right-0 h-full w-1.5 cursor-col-resize hover:bg-gold-500/40 select-none" data-col-resizer></span>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($tr['leads'] as $stt => $lead)
                                    <tr class="hover:bg-gold-50/40">
                                        @foreach ($tr['columns'] as $col)
                                            <td class="px-3 py-2 border border-gold-100 align-top">
                                                <div class="whitespace-pre-wrap break-words">{{ $renderCell($lead, $col, $stt + 1) }}</div>
                                            </td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr><td colspan="{{ count($tr['columns']) }}" class="text-center text-ink/40 py-8">Không có lead nào khớp filter.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @else

            {{-- AGGREGATE — Bảng tổng (thống kê theo funnel) --}}
            @if ($tr['showTotals'])
                <div class="bg-white border border-gold-200 rounded-xl shadow-card p-5 mb-6">
                    <div class="overflow-x-auto">
                        <table class="text-sm border border-gold-200">
                            <thead>
                                <tr class="bg-gold-50/60 text-xs uppercase tracking-wider text-ink/60">
                                    <th class="px-3 py-2 font-semibold border border-gold-200 text-left sticky left-0 bg-gold-50/60">Lead</th>
                                    @foreach ($tr['metrics'] as $label)
                                        <th class="px-3 py-2 font-semibold border border-gold-200 whitespace-nowrap text-right">{{ $label }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="px-3 py-2 border border-gold-200 font-bold text-right sticky left-0 bg-white">{{ number_format($tr['total']) }}</td>
                                    @foreach ($tr['totalsRow'] as $col)
                                        <td class="px-3 py-2 border border-gold-200 text-right">{{ number_format($col['count']) }}</td>
                                    @endforeach
                                </tr>
                                <tr class="bg-gold-50/30 text-ink/60">
                                    <td class="px-3 py-2 border border-gold-200 font-semibold text-xs sticky left-0 bg-gold-50/60">Tỷ lệ chuyển đổi</td>
                                    @foreach ($tr['totalsRow'] as $col)
                                        <td class="px-3 py-2 border border-gold-200 text-right font-mono text-xs">{{ $col['rate'] }}%</td>
                                    @endforeach
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-xs text-ink/40 mt-2">Tỉ lệ = số đếm cột đó / tổng Lead.</p>
                </div>
            @endif

            {{-- Bảng theo người phụ trách --}}
            @if ($tr['showByOwner'])
                <div class="bg-white border border-gold-200 rounded-xl shadow-card p-5">
                    <h3 class="font-bold mb-4">Theo người phụ trách</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm min-w-[560px]">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wider text-ink/50 bg-gold-50/60">
                                    <th class="px-4 py-2.5 font-semibold">Người</th>
                                    <th class="px-4 py-2.5 font-semibold text-right">Total Lead</th>
                                    @foreach ($tr['metrics'] as $label)
                                        <th class="px-4 py-2.5 font-semibold text-right whitespace-nowrap">{{ $label }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gold-100">
                                @forelse ($tr['ownerRows'] as $row)
                                    <tr class="hover:bg-gold-50/40">
                                        <td class="px-4 py-2.5 font-medium">{{ $row['name'] }}</td>
                                        <td class="px-4 py-2.5 text-right font-semibold">{{ number_format($row['total']) }}</td>
                                        @foreach ($row['cells'] as $c)
                                            <td class="px-4 py-2.5 text-right">{{ number_format($c) }}</td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr><td colspan="{{ 2 + count($tr['metrics']) }}" class="px-5 py-8 text-center text-ink/40">Không có lead trong kỳ cho team này.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
            @endif  {{-- end aggregate mode block --}}
        @endif
    @endif

    {{-- ============ TAB: BÁO CÁO TỔNG (giữ nguyên báo cáo cũ) ============ --}}
    @if ($section === 'overall')

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
                    <strong class="font-mono text-green-700">{{ number_format((int) $funnel->revenue, 0, ',', '.') }}₫</strong>
                </span>
            </div>
        </div>
    @endif

    @if ($tab === 'marketing')
        <div class="bg-white border border-gold-200 rounded-xl shadow-card">
            <div class="px-5 py-3 border-b border-gold-100 flex items-center gap-2">
                <span class="text-xs font-semibold text-ink/50">Cắt theo:</span>
                @foreach (['camp' => 'Camp', 'page' => 'PAGE'] as $key => $label)
                    <button wire:click="$set('groupBy', '{{ $key }}')"
                            class="text-xs font-semibold px-3 py-1.5 rounded-md {{ $groupBy === $key ? 'bg-gold-100 text-gold-800 border border-gold-300' : 'text-ink/50 hover:bg-gold-50' }}">{{ $label }}</button>
                @endforeach
            </div>
            <div class="overflow-x-auto">
            <table class="w-full text-sm min-w-[560px]">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-ink/50 bg-gold-50/60">
                        <th class="px-5 py-3 font-semibold">{{ ['camp' => 'Camp', 'page' => 'Page'][$groupBy] }}</th>
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
                            <td class="px-5 py-3 text-right font-mono font-bold text-green-700">{{ number_format((int) $row->revenue, 0, ',', '.') }}₫</td>
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
                    @foreach (['distribute' => 'Chia tự động (engine)', 'manual_assign' => 'Chia thủ công', 'recall' => 'Thu hồi', 'pull' => 'Kéo từ kho'] as $action => $label)
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
                            <th class="px-4 py-3 font-semibold">STT</th>
                            <th class="px-4 py-3 font-semibold">Mã KH</th>
                            <th class="px-4 py-3 font-semibold">Họ tên khách</th>
                            <th class="px-4 py-3 font-semibold">Người thu thập</th>
                            <th class="px-4 py-3 font-semibold">Người phụ trách</th>
                            <th class="px-4 py-3 font-semibold">Ngày thu thập</th>
                            <th class="px-4 py-3 font-semibold whitespace-nowrap">Ngày ghi nhận DT</th>
                            <th class="px-4 py-3 font-semibold whitespace-nowrap text-right">Tần suất quay lại</th>
                            <th class="px-4 py-3 font-semibold whitespace-nowrap">Mã tiếp đón</th>
                            <th class="px-4 py-3 font-semibold">Dịch vụ</th>
                            <th class="px-4 py-3 font-semibold">Ghi chú</th>
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
                                <td class="px-4 py-2.5 text-ink/50">{{ $loop->iteration }}</td>
                                <td class="px-4 py-2.5 font-mono text-xs text-gold-700">{{ $this->leadCode($lead) }}</td>
                                <td class="px-4 py-2.5 font-medium">{{ $lead->name }}</td>
                                <td class="px-4 py-2.5">{{ $lead->receiver?->name ?? '—' }}</td>
                                <td class="px-4 py-2.5">{{ $lead->owner?->name ?? '—' }}</td>
                                <td class="px-4 py-2.5">{{ $lead->received_date?->format('d/m/Y') }}</td>
                                <td class="px-4 py-2.5">{{ $lead->payments_min_paid_at ? \Illuminate\Support\Carbon::parse($lead->payments_min_paid_at)->format('d/m/Y') : '—' }}</td>
                                <td class="px-4 py-2.5 text-right">{{ (int) $lead->return_count }}</td>
                                <td class="px-4 py-2.5 text-xs">{{ $this->leadReceptionCodes($lead) ?: '—' }}</td>
                                <td class="px-4 py-2.5">{{ $this->leadServices($lead) ?: '—' }}</td>
                                <td class="px-4 py-2.5 max-w-[220px] truncate" title="{{ $lead->note }}">{{ $lead->note ?: '—' }}</td>
                                @foreach ($leadCustomFields as $cf)
                                    <td class="px-4 py-2.5">
                                        @if ($cf->field_type === 'tick')
                                            {{ ($vals[$cf->id] ?? '') !== '' ? '✓' : '—' }}
                                        @elseif ($cf->field_type === 'select')
                                            {{ $cf->optionLabel($vals[$cf->id] ?? '') ?: '—' }}
                                        @else
                                            {{ $vals[$cf->id] ?? '—' }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ 11 + $leadCustomFields->count() }}" class="px-5 py-8 text-center text-ink/40">Không có lead trong kỳ.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- ============ TAB: GR DAILY REPORT ============ --}}
    @if ($tab === 'daily' && $daily)
        <div class="bg-white border border-gold-200 rounded-xl shadow-card overflow-hidden">
            <div class="px-6 py-5 border-b border-gold-100 flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-xs font-semibold text-ink/50 uppercase tracking-wider mb-1">Ngày báo cáo</label>
                    <input type="date" wire:model.live="dailyDate"
                           class="border border-gold-300 rounded-md px-3 py-2 text-sm">
                </div>
                <div class="flex-1">
                    <p class="text-2xl font-bold text-gold-700">GR Daily Report — ngày {{ \Illuminate\Support\Carbon::parse($daily['date'])->format('d/m/Y') }}</p>
                    <p class="text-sm text-ink/60">Daily traffic (Số lượng khách mới): <strong class="text-gold-700">{{ $daily['traffic'] }}</strong></p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wider text-white" style="background-color:#7F6000">
                            <th class="px-3 py-2.5 font-semibold">STT</th>
                            <th class="px-3 py-2.5 font-semibold">Date check-in</th>
                            <th class="px-3 py-2.5 font-semibold">Location</th>
                            <th class="px-3 py-2.5 font-semibold">Fullname</th>
                            <th class="px-3 py-2.5 font-semibold">DOB</th>
                            <th class="px-3 py-2.5 font-semibold">Địa chỉ ( nếu có )</th>
                            <th class="px-3 py-2.5 font-semibold">Sale Book</th>
                            <th class="px-3 py-2.5 font-semibold">Sale Care</th>
                            <th class="px-3 py-2.5 font-semibold">Nguồn</th>
                            <th class="px-3 py-2.5 font-semibold">Note</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gold-100">
                        @forelse ($daily['leads'] as $i => $lead)
                            @php
                                $addr = trim(($lead->address ?? '') . ($lead->region ? (', ' . $lead->region) : ''), ', ');
                            @endphp
                            <tr class="hover:bg-gold-50/40">
                                <td class="px-3 py-2 text-ink/50">{{ $i + 1 }}</td>
                                <td class="px-3 py-2 whitespace-nowrap">{{ $lead->received_date?->format('d/m/Y') }}</td>
                                <td class="px-3 py-2 font-semibold">Medical</td>
                                <td class="px-3 py-2 font-medium">{{ $lead->name }}</td>
                                <td class="px-3 py-2 whitespace-nowrap">{{ $lead->birthday ? \Illuminate\Support\Carbon::parse($lead->birthday)->format('d/m/Y') : '' }}</td>
                                <td class="px-3 py-2">{{ $addr }}</td>
                                <td class="px-3 py-2">{{ $lead->receiver?->name }}</td>
                                <td class="px-3 py-2">{{ $lead->owner?->name }}</td>
                                <td class="px-3 py-2 text-xs">{{ $lead->source_group }}</td>
                                <td class="px-3 py-2 text-xs text-ink/60">{{ $lead->note }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="px-3 py-6 text-center text-ink/40">Không có khách mới ngày này.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
    @endif {{-- /section === 'overall' --}}
</div>
