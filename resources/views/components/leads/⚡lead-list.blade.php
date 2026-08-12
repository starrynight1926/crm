<?php

use App\Models\AuditLog;
use App\Models\CustomField;
use App\Models\Lead;
use App\Services\DistributionEngine;
use App\Services\Ups\UpsDispatcher;
use Livewire\Component;
use Livewire\WithPagination;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $fClassification = '';

    public string $fNguon = '';

    public string $fDateFrom = '';

    public string $fDateTo = '';

    /**
     * Filter theo bước trong Customer Flow (Phase 2..5).
     * Rỗng = không lọc. Options:
     *   waiting_tele  — Chờ chia — Tele  (gate: lead.distribute_tele)
     *   waiting_sale  — Chờ chia — Sale  (gate: lead.distribute_sale)
     *   in_care       — Đang care        (pipeline_status=in_care, chưa book)
     *   booked        — Đã đặt booking   (booking_status=booked)
     *   checkin       — Đã check-in      (booking_status in nhóm đã tới)
     */
    public string $fPhase = '';

    public bool $showExportModal = false;

    /** Key các cột được chọn để xuất (core: tên cột; custom: cf_{id}). */
    public array $exportCols = [];

    /** Id các lead đang tick (chọn hàng loạt). */
    public array $selected = [];

    public bool $selectAll = false;

    public bool $showColumnPicker = false;

    /** Các cột đang hiển thị trong bảng. */
    public array $visibleCols = [];

    /** Danh sách cột có thể ẩn/hiện (key => label). */
    // 2026-08-10: bỏ "Chia cho" gộp + "Danh mục", tách thành:
    //   tele    = Tele phụ trách (owner khi pipeline=booking)
    //   sale    = Sale tiếp đón (owner khi pipeline=sale, hoặc CV1 booking)
    //   importer = Người up lead
    public const TABLE_COLUMNS = [
        'code' => 'Mã KH',
        'received_date' => 'Ngày',
        'page' => 'Page',
        'name' => 'Tên khách hàng',
        'phone' => 'SĐT',
        'nguon' => 'Nguồn',
        'classification' => 'Phân loại',
        'region' => 'Khu vực',
        'status' => 'Trạng thái',
        'tele' => 'Tele phụ trách',
        'sale' => 'Sale tiếp đón',
        'importer' => 'Người up',
    ];

    /** Cột mặc định cho Trực Page (up lead nguồn MKT). */
    private const TRUC_PAGE_DEFAULT_COLS = [
        'code', 'received_date', 'name', 'phone', 'nguon', 'status', 'tele', 'sale', 'importer',
    ];

    public function mount(): void
    {
        $user = auth()->user();
        $prefs = $user->report_prefs ?? [];
        $saved = $prefs['lead_list_columns'] ?? null;
        $valid = array_keys(self::TABLE_COLUMNS);

        if ($saved) {
            $this->visibleCols = array_values(array_intersect($saved, $valid));
        } elseif ($user->hasPermission('source.up.trucpage') && ! $user->hasPermission('lead.book_action')) {
            // Trực Page: mặc định gọn — STT, Mã KH, Ngày, Tên, SĐT, Nguồn, Trạng thái, Chia cho.
            $this->visibleCols = self::TRUC_PAGE_DEFAULT_COLS;
        } else {
            $this->visibleCols = $valid;
        }

        // 2026-08-04 (T9): auto-set filter từ URL query (widget dashboard click sang).
        // Chấp nhận ?phase=1..6 + ?source=mkt|mkt_br|... + ?received=today.
        $req = request();
        if ($req->filled('phase')) {
            $p = (int) $req->query('phase');
            if ($p >= 1 && $p <= 6) $this->fPhase = (string) $p;
        }
        if ($req->filled('source')) {
            $s = (string) $req->query('source');
            if (array_key_exists($s, \App\Models\Lead::SOURCE_GROUPS)) $this->fNguon = $s;
        }
        if ($req->query('received') === 'today') {
            $today = now()->toDateString();
            $this->fDateFrom = $today;
            $this->fDateTo = $today;
        }
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'fClassification', 'fNguon', 'fDateFrom', 'fDateTo', 'fPhase'])) {
            $this->resetPage();
            $this->reset('selected', 'selectAll');
        }
    }

    public function updatedVisibleCols(): void
    {
        $this->saveColumnPrefs();
    }

    public function toggleColumn(string $key): void
    {
        if (in_array($key, $this->visibleCols)) {
            $this->visibleCols = array_values(array_diff($this->visibleCols, [$key]));
            // Reset filter khi ẩn cột (không kẹt filter cũ) — Phase 6.19
            $filterMap = [
                'nguon' => 'fNguon',
                'classification' => 'fClassification',
                'received_date' => ['fDateFrom', 'fDateTo'],
            ];
            foreach ((array) ($filterMap[$key] ?? []) as $prop) {
                $this->{$prop} = '';
            }
        } else {
            $this->visibleCols[] = $key;
        }
        $this->saveColumnPrefs();
        $this->resetPage();
    }

    public function showAllColumns(): void
    {
        $this->visibleCols = array_keys(self::TABLE_COLUMNS);
        $this->saveColumnPrefs();
    }

    private function saveColumnPrefs(): void
    {
        $user = auth()->user();
        $prefs = $user->report_prefs ?? [];
        $prefs['lead_list_columns'] = array_values($this->visibleCols);
        $user->update(['report_prefs' => $prefs]);
    }

    public function colVisible(string $key): bool
    {
        return in_array($key, $this->visibleCols);
    }

    /** Tick "chọn tất cả" → chọn mọi lead trên trang hiện tại. */
    public function updatedSelectAll($value): void
    {
        $this->selected = $value
            ? $this->filteredQuery()->paginate(15)->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];
    }

    /** Xóa 1 lead (mềm) trong phạm vi user — quyền lead.delete. */
    public function deleteLead(int $id): void
    {
        abort_unless(auth()->user()->hasPermission('lead.delete'), 403);

        $lead = Lead::visibleTo(auth()->user())->find($id);
        if (! $lead) {
            return;
        }
        $lead->delete();
        AuditLog::record('delete', $lead, ['name' => $lead->name]);
        $this->selected = array_values(array_diff($this->selected, [(string) $id]));
        session()->flash('status', "Đã xóa khách hàng \"{$lead->name}\".");
    }

    /** Xóa hàng loạt các lead đang tick — quyền lead.delete. */
    public function deleteSelected(): void
    {
        abort_unless(auth()->user()->hasPermission('lead.delete'), 403);

        $ids = array_map('intval', $this->selected);
        $leads = Lead::visibleTo(auth()->user())->whereIn('id', $ids)->get();
        foreach ($leads as $lead) {
            $lead->delete();
            AuditLog::record('delete', $lead, ['name' => $lead->name, 'bulk' => true]);
        }
        $count = $leads->count();
        $this->reset('selected', 'selectAll');
        session()->flash('status', "Đã xóa {$count} khách hàng.");
    }

    /**
     * 2026-08-10: Chia tự động hàng loạt các lead đang tick.
     * Chỉ áp cho lead ở kho (chưa có owner) — có owner rồi thì skip.
     * Cần perm lead.distribute.
     *
     * Logic chia:
     *  - MKT / MKT_BR → pick từ MKT List UPS của cơ sở lead (UpsDispatcher::pickMkt).
     *  - Nguồn khác → DistributionEngine (rule-based).
     */
    public function distributeSelected(): void
    {
        abort_unless(auth()->user()->hasPermission('lead.distribute'), 403);

        // 2026-08-10: dropdown "Chia vào cơ sở" là OVERRIDE tùy chọn, không bắt buộc.
        //   Resolver ưu tiên (0) override, (1) lead.pool_unit_id, (2) org_unit_id, (3) importer's org.
        //   Chỉ khi tất cả 4 tầng đều fail → skip lead đó, báo cụ thể.

        $ids = array_map('intval', $this->selected);
        $leads = Lead::visibleTo(auth()->user())
            ->whereIn('id', $ids)
            ->whereNull('owner_id')
            ->get();

        $engine = app(DistributionEngine::class);
        $ups = app(UpsDispatcher::class);
        $assigned = 0;
        $keptInPool = 0;
        $upsEmpty = 0;
        foreach ($leads as $lead) {
            if (in_array($lead->source_group, [Lead::SOURCE_MKT, Lead::SOURCE_MKT_BR], true)) {
                $poolFacId = $this->resolveLeadFacilityPoolId($lead);
                $picked = $poolFacId ? $ups->pickMkt($poolFacId) : null;
                if ($picked) {
                    $lead->update([
                        'owner_id' => $picked->id,
                        'assigned_at' => now(),
                        'pool_level' => Lead::POOL_PERSONAL,
                        'pipeline_status' => Lead::PSTATUS_IN_CARE,
                    ]);
                    $assigned++;
                } else {
                    $upsEmpty++;
                }
            } else {
                $engine->distribute($lead);
                $lead->refresh();
                if ($lead->owner_id) {
                    $assigned++;
                } else {
                    $keptInPool++;
                }
            }
        }
        $skipped = count($ids) - $leads->count();

        $this->reset('selected', 'selectAll');
        $parts = ["Đã chia {$assigned} lead"];
        if ($upsEmpty) $parts[] = "{$upsEmpty} MKT không có sale trong UPS list";
        if ($keptInPool) $parts[] = "{$keptInPool} về kho team (chưa match rule)";
        if ($skipped) $parts[] = "{$skipped} bỏ qua (đã có owner)";
        session()->flash('status', implode(' · ', $parts) . '.');
    }

    /**
     * 2026-08-10: Thu hồi hàng loạt — clear owner_id, đưa lead về kho team.
     * Cần perm lead.recall.
     */
    public function recallSelected(): void
    {
        abort_unless(auth()->user()->hasPermission('lead.recall'), 403);

        $ids = array_map('intval', $this->selected);
        $leads = Lead::visibleTo(auth()->user())
            ->whereIn('id', $ids)
            ->whereNotNull('owner_id')
            ->get();

        $engine = app(DistributionEngine::class);
        $n = 0;
        foreach ($leads as $lead) {
            $engine->recall($lead, Lead::POOL_TEAM, auth()->id());
            $n++;
        }
        $skipped = count($ids) - $leads->count();

        $this->reset('selected', 'selectAll');
        $parts = ["Đã thu hồi {$n} lead về kho team"];
        if ($skipped) $parts[] = "{$skipped} bỏ qua (chưa có owner)";
        session()->flash('status', implode(' · ', $parts) . '.');
    }

    /**
     * Resolve facility_pool_unit_id từ lead — priority:
     *   1. lead.pool_unit_id (nếu là facility).
     *   2. lead.org_unit_id → org_pool_map.
     *   3. importer's org → org_pool_map (khi lead import không set org).
     */
    private function resolveLeadFacilityPoolId(Lead $lead): ?int
    {
        // (1) pool_unit_id — có thể là facility (dùng luôn) hoặc branch/company/department (walk descendant).
        if ($lead->pool_unit_id) {
            $pool = \App\Models\PoolUnit::find($lead->pool_unit_id);
            if ($pool && $pool->is_active) {
                if ($pool->kind === 'facility') {
                    return (int) $pool->id;
                }
                // Branch/company/department → tìm facility descendant. Prefer facility có
                // MKT list chốt hôm nay (có sale bucket MKT).
                $descIds = \App\Models\PoolUnit::where('kind', 'facility')->where('is_active', true)
                    ->where('path', 'like', $pool->path . '%')->pluck('id');
                if ($descIds->isNotEmpty()) {
                    $withMkt = \App\Models\DailyAttendance::whereIn('facility_pool_unit_id', $descIds)
                        ->whereDate('work_date', today())->where('is_mkt', true)
                        ->value('facility_pool_unit_id');
                    if ($withMkt) return (int) $withMkt;
                    return (int) $descIds->first();
                }
            }
        }

        // (2) org_unit_id (+ ancestors) → org_pool_map.
        if ($lead->org_unit_id) {
            $orgIds = collect(explode('/', trim((string) $lead->orgUnit?->path, '/')))
                ->filter()->map(fn ($id) => (int) $id)->all();
            if (! $orgIds) $orgIds = [$lead->org_unit_id];

            $facId = \App\Models\PoolUnit::where('kind', 'facility')->where('is_active', true)
                ->whereIn('id', function ($q) use ($orgIds) {
                    $q->select('pool_unit_id')->from('org_pool_map')->whereIn('org_unit_id', $orgIds);
                })->value('id');
            if ($facId) return (int) $facId;
        }

        // (3) Fallback: importer's org — cho case lead import không set org_unit_id.
        if ($lead->imported_by) {
            $importer = \App\Models\User::find($lead->imported_by);
            if ($importer) {
                $ancestorOrgIds = [];
                foreach ($importer->effectiveAssignments() as $a) {
                    foreach (array_filter(explode('/', trim((string) $a->orgUnit->path, '/'))) as $seg) {
                        $ancestorOrgIds[(int) $seg] = true;
                    }
                }
                if ($ancestorOrgIds) {
                    return (int) (\App\Models\PoolUnit::where('kind', 'facility')->where('is_active', true)
                        ->whereIn('id', function ($q) use ($ancestorOrgIds) {
                            $q->select('pool_unit_id')->from('org_pool_map')->whereIn('org_unit_id', array_keys($ancestorOrgIds));
                        })->value('id')) ?: null;
                }
            }
        }

        return null;
    }

    /**
     * Cột lõi có thể xuất: key => nhãn.
     * Phase C1.f 2026-08-02: header khớp với TARGETS import để round-trip export → import lại được.
     */
    private function coreColumns(): array
    {
        return [
            'code'                => 'Mã KH',
            'name'                => 'Tên khách hàng',
            'phone'               => 'SĐT',
            'received_date'       => 'Ngày nhập',
            'source_group'        => 'Nhóm nguồn',
            'insight'             => 'Ghi chú insight khách',
            'link'                => 'Link',
            'birthday'            => 'Ngày sinh',
            'occupation'          => 'Nghề nghiệp',
            'address'             => 'Địa chỉ',
            'medical_history'     => 'Khai thác tiền sử',
            'booking_owner_email' => 'Email Booking phụ trách',
            'sale_owner_email'    => 'Email Sale phụ trách',
            'status_1'            => 'Ghi nhận tình trạng lần 1',
            'status_2'            => 'Ghi nhận tình trạng lần 2',
            'note'                => 'NOTE',
            'region'              => 'Khu vực',
            'classification'      => 'Phân loại',
            'page'                => 'PAGE',
            'camp'                => 'CAMP',
            'imported_by'         => 'Người nhập lead',
            'receiver'            => 'Người thu thập',
            'owner'               => 'Người phụ trách hiện tại',
            'job_title'           => 'Chức danh',
            'phase'               => 'Phase (1-7)',
            'note_history'        => 'Lịch sử ghi chú',
            'booking_history'     => 'Lịch sử đặt lịch',
        ];
    }

    /**
     * Phase C1.b rev4 2026-08-01 — gộp tất cả booking_logs thành 1 cell multi-line cho export.
     * Format mỗi dòng:
     *   [BKG-XXX] dd/mm/YYYY HH:MM · 🩺 Thăm khám · Cơ sở › Phòng · BS: X · DV: Y · CV: A, B · [Sync status]
     *     Ghi chú: ...
     */
    private function bookingHistoryCell(Lead $lead): string
    {
        $logs = $lead->bookingLogs()
            ->with(['facility.parent', 'doctor', 'service', 'consultants'])
            ->orderBy('created_at')
            ->get();

        if ($logs->isEmpty()) return '';

        $syncLabels = [
            'done' => '🏁 Đã xong',
            'checkedin' => '🚪 Khách đã tới',
            'canceled' => '🚫 Khách hủy',
            'approved' => '✅ Đã duyệt',
            'synced' => '⏳ Chờ duyệt',
            'rejected' => '❌ Bị từ chối',
            'deleted' => '🗑 Đã xóa',
            'failed' => '⚠ Chưa đồng bộ',
            'pending' => '⏳ Đang gửi',
        ];

        return $logs->map(function ($bl) use ($syncLabels) {
            $ma = $bl->sbooking_booking_ma ?: ($bl->sbooking_booking_id ? '#' . $bl->sbooking_booking_id : 'chưa gửi');
            $when = $bl->scheduled_at?->format('d/m/Y H:i') ?? 'chưa đặt';
            $type = $bl->type === 'tham_kham' ? '🩺 Thăm khám' : ($bl->type === 'dich_vu' ? '💆 Dịch vụ' : '-');
            $fac = $bl->facility ? (($bl->facility->parent?->name ? $bl->facility->parent->name . ' › ' : '') . $bl->facility->name) : '-';
            $bs = $bl->doctor?->name ?: '-';
            $dv = $bl->service?->name ?: '-';
            $cv = $bl->consultants->isNotEmpty() ? $bl->consultants->pluck('name')->implode(', ') : '-';
            $status = \App\Models\BookingLog::STATUSES[$bl->status] ?? $bl->status;
            $sync = $syncLabels[$bl->sync_status] ?? '';

            $line = "[{$ma}] {$when} · {$type} · {$fac} · BS: {$bs} · DV: {$dv} · CV: {$cv} · [{$status}]" . ($sync ? " · {$sync}" : '');
            if ($bl->sync_error) $line .= " · lỗi: {$bl->sync_error}";
            if ($bl->note) $line .= "\n  Ghi chú: " . trim($bl->note);
            return $line;
        })->implode("\n");
    }

    /** Trường tùy biến trong phạm vi user, làm cột xuất tùy chọn. */
    private function exportableCustomFields()
    {
        $orgIds = auth()->user()->visibleOrgUnitIds();

        return CustomField::query()
            ->where('active', true)
            ->where('status', CustomField::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('org_unit_id')
                ->when($orgIds !== [], fn ($qq) => $qq->orWhereIn('org_unit_id', $orgIds)))
            ->orderBy('org_unit_id')
            ->orderBy('position')
            ->get();
    }

    /** Tất cả key cột (core + custom) theo thứ tự hiển thị. */
    private function allExportKeys(): array
    {
        return array_merge(
            array_keys($this->coreColumns()),
            $this->exportableCustomFields()->map(fn ($f) => 'cf_' . $f->id)->all()
        );
    }

    public function openExport(): void
    {
        abort_unless(auth()->user()->hasPermission('lead.export'), 403);
        // 2026-08-04 fix "cột thông minh": mặc định chỉ tick core columns theo 6 phase.
        // Custom field (page/camp/phan_loai/…) tick manual nếu cần — tránh xuất trường tùy biến "dở hơi".
        if ($this->exportCols === []) {
            $this->exportCols = array_keys($this->coreColumns());
        }
        $this->showExportModal = true;
    }

    public function selectAllExport(): void
    {
        $this->exportCols = $this->allExportKeys();
    }

    public function clearExport(): void
    {
        $this->exportCols = [];
    }

    /** Query lead theo bộ lọc hiện tại (dùng chung cho bảng + export). */
    /** Phase 6.21 — Options distinct cho filter camp/page (union tất cả field key khớp, đa cấp). */
    private function coreCustomOptions(string $key, \App\Models\User $user)
    {
        $fieldIds = CustomField::where('key', $key)->pluck('id');
        if ($fieldIds->isEmpty()) return collect();

        $visibleIds = Lead::visibleTo($user)->pluck('leads.id');
        return \App\Models\LeadCustomValue::whereIn('lead_id', $visibleIds)
            ->whereIn('custom_field_id', $fieldIds)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->distinct()
            ->orderBy('value')
            ->pluck('value');
    }

    private function filteredQuery()
    {
        $user = auth()->user();

        return Lead::query()
            ->visibleTo($user)
            ->with(['owner', 'receiver', 'orgUnit', 'customValues'])
            ->when($this->search, function ($q) {
                $normalized = Lead::normalizePhone($this->search);
                $needle = trim($this->search);
                $q->where(fn ($qq) => $qq
                    ->where('name', 'like', "%{$needle}%")
                    ->orWhere('code', 'like', "%{$needle}%")
                    ->when($normalized, fn ($qqq) => $qqq->orWhere('phone', $normalized))
                    // Phase C1.b rev4 2026-08-01: search theo mã booking sbooking (BKG-...).
                    ->orWhereHas('bookingLogs', fn ($bl) => $bl->where('sbooking_booking_ma', 'like', "%{$needle}%")));
            })
            ->when($this->fClassification, fn ($q) => $q->where('classification', $this->fClassification))
            ->when($this->fNguon, fn ($q) => $q->where('source_group', $this->fNguon))
            ->when($this->fDateFrom, fn ($q) => $q->where('received_date', '>=', $this->fDateFrom))
            ->when($this->fDateTo, fn ($q) => $q->where('received_date', '<=', $this->fDateTo))
            ->when($this->fPhase !== '' && is_numeric($this->fPhase), fn ($q) => $q->where('phase', (int) $this->fPhase))
            ->orderByDesc('received_date')
            ->orderByDesc('id');
    }

    /**
     * Phase 6.24 — Gộp lịch sử ghi chú của lead thành 1 chuỗi multi-line cho export.
     * Format mỗi log:
     *   [dd/mm/YYYY HH:MM] Tên user: [prefix] nội dung [+N ảnh]
     *     📎 dd/mm/YYYY HH:MM · <url>
     *     ...
     * Chỉ lấy logs field='note'. Ảnh xuất dưới dạng URL absolute (Phase 6.25).
     */
    private function noteHistoryCell(Lead $lead): string
    {
        $logs = \App\Models\LeadStatusLog::with('user')
            ->where('lead_id', $lead->id)
            ->where('field', 'note')
            ->orderBy('created_at')
            ->get();

        if ($logs->isEmpty()) return '';

        return $logs->map(function ($log) {
            $when = $log->created_at?->format('d/m/Y H:i') ?? '';
            $who = $log->user?->name ?: 'Hệ thống';
            $text = trim((string) ($log->new_value ?? ''));
            $images = is_array($log->images) ? $log->images : [];
            $suffix = $images ? ' [+' . count($images) . ' ảnh]' : '';
            $prefix = $log->is_first_visit ? '🆕 ' : ($log->is_return ? '🔁 ' : '');

            $head = "[{$when}] {$who}: {$prefix}{$text}{$suffix}";
            $imgLines = array_map(function ($path) use ($when) {
                $url = asset('storage/' . ltrim($path, '/'));
                return "  📎 {$when} · {$url}";
            }, $images);

            return $imgLines ? $head . "\n" . implode("\n", $imgLines) : $head;
        })->implode("\n");
    }

    private function cellValue(Lead $lead, string $key, $cfs): string
    {
        return match ($key) {
            'code' => (string) $lead->code,
            'name' => (string) $lead->name,
            'phone' => (string) $lead->phoneFor(auth()->user()),
            'received_date' => (string) $lead->received_date?->toDateString(),
            'source_group' => (string) ($lead->source_group ? strtoupper($lead->source_group) : ''),
            'insight' => (string) $lead->insight,
            'link' => (string) $lead->link,
            'birthday' => $lead->birthday ? $lead->birthday->format('d-m-Y') : '',
            'occupation' => (string) $lead->occupation,
            'address' => (string) $lead->address,
            'medical_history' => (string) $lead->medical_history,
            'booking_owner_email' => (string) ($lead->receiver?->email ?? ''),
            'sale_owner_email' => (string) ($lead->owner?->email ?? ''),
            'status_1' => (string) $lead->status_1,
            'status_2' => (string) $lead->status_2,
            'note' => (string) $lead->note,
            'region' => (string) $lead->region,
            'classification' => $lead->classificationLabel(),
            'page' => (string) $lead->page,
            'camp' => (string) $lead->camp,
            'imported_by' => (string) (\App\Models\User::find($lead->imported_by)?->name ?? ''),
            'receiver' => (string) $lead->receiver?->name,
            'owner' => (string) $lead->owner?->name,
            'job_title' => (string) $lead->owner?->job_title,
            'phase' => (string) ($lead->phase ?? ''),
            'note_history' => $this->noteHistoryCell($lead),
            'booking_history' => $this->bookingHistoryCell($lead),
            default => (function () use ($lead, $key, $cfs) {
                $id = (int) str_replace('cf_', '', $key);
                $cf = $cfs->get($id);
                $raw = (string) ($lead->customValues->firstWhere('custom_field_id', $id)?->value ?? '');
                if ($cf && $cf->field_type === 'tick') {
                    return $raw !== '' ? 'Có' : '';
                }
                return $cf && $cf->field_type === 'select' ? $cf->optionLabel($raw) : $raw;
            })(),
        };
    }

    public function export()
    {
        abort_unless(auth()->user()->hasPermission('lead.export'), 403);

        // Giữ đúng thứ tự cột chuẩn, chỉ lấy cột được tick
        $cols = array_values(array_intersect($this->allExportKeys(), $this->exportCols));
        if ($cols === []) {
            $this->addError('exportCols', 'Chọn ít nhất một trường để xuất.');
            return;
        }

        $core = $this->coreColumns();
        $cfs = $this->exportableCustomFields()->keyBy('id');
        $cfLabels = CustomField::labelMap($cfs->values());

        $header = array_map(function ($key) use ($core, $cfs, $cfLabels) {
            if (isset($core[$key])) {
                return $core[$key];
            }
            $id = (int) str_replace('cf_', '', $key);
            return $cfLabels[$id] ?? $cfs->get($id)?->label ?? $key;
        }, $cols);

        $rows = $this->filteredQuery()->limit(10000)->get()
            ->map(fn ($lead) => array_map(fn ($key) => $this->cellValue($lead, $key, $cfs), $cols))
            ->all();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([$header, ...$rows]);

        // Phase 6.24 — cột note_history có multi-line → bật wrap text + rộng cột
        // Phase C1.b rev4 2026-08-01 — booking_history cùng cách xử lý.
        foreach (['note_history' => 60, 'booking_history' => 80] as $wideKey => $width) {
            $idx = array_search($wideKey, $cols, true);
            if ($idx !== false) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
                $sheet->getStyle($colLetter . ':' . $colLetter)->getAlignment()
                    ->setWrapText(true)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
                $sheet->getColumnDimension($colLetter)->setWidth($width);
            }
        }

        AuditLog::record('export', null, ['report' => 'leads', 'count' => count($rows), 'cols' => $cols]);

        $this->showExportModal = false;
        $filename = 'khach-hang-' . now()->format('Ymd-His') . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename);
    }

    public function with(): array
    {
        $user = auth()->user();

        $leads = $this->filteredQuery()->paginate(15);

        return [
            'leads' => $leads,
            'nguonOptions' => \App\Models\CustomField::find(1)?->options ?? [],
            'exportCore' => $this->showExportModal ? $this->coreColumns() : [],
            'exportCustomFields' => $this->showExportModal ? $this->exportableCustomFields() : collect(),
            'canExport' => $user->hasPermission('lead.export'),
            'canUpdate' => $user->hasPermission('lead.update'),
            'canDelete' => $user->hasPermission('lead.delete'),
            'tableColumns' => self::TABLE_COLUMNS,
        ];
    }
};
?>

<div>
    @if (session('status'))
        <p class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-4 py-2">{{ session('status') }}</p>
    @endif

    {{-- 2026-08-04 mobile: header stack vertical, action button co gọn, ẩn tiêu đề phụ dưới sm. --}}
    <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
        <div class="min-w-0 flex-1">
            <h1 class="text-xl md:text-3xl font-bold mb-1">Danh sách khách hàng</h1>
            <p class="text-xs md:text-sm text-ink/60 hidden sm:block">Quản lý và theo dõi các khách hàng tiềm năng trên tất cả các kênh.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 md:gap-3">
            @if (auth()->user()->hasPermission('lead.import'))
                <a href="{{ route('leads.failed') }}" class="text-sm font-semibold text-ink/60 border border-gold-200 px-3 md:px-4 py-2 md:py-2.5 rounded-md hover:bg-gold-50">Lead lỗi</a>
                <a href="{{ route('leads.import') }}" class="text-sm font-semibold text-gold-700 border border-gold-300 px-3 md:px-4 py-2 md:py-2.5 rounded-md hover:bg-gold-50">⬆ Import</a>
            @endif
            @if (auth()->user()->hasPermission('phase.rollback'))
                <a href="{{ route('leads.trash') }}" class="text-sm font-semibold text-ink/60 border border-gold-200 px-3 md:px-4 py-2 md:py-2.5 rounded-md hover:bg-gold-50">🗑 Thùng rác</a>
            @endif
            @if ($canExport)
                <button wire:click="openExport" class="text-sm font-semibold text-gold-700 border border-gold-300 px-3 md:px-4 py-2 md:py-2.5 rounded-md hover:bg-gold-50">⬇ Export</button>
            @endif
            @if (auth()->user()->hasPermission('lead.create'))
                <a href="{{ route('leads.create') }}"
                   class="bg-gold-600 hover:bg-gold-700 text-white font-semibold px-5 py-2.5 rounded-md text-sm">
                    + Thêm mới lead
                </a>
            @endif
        </div>
    </div>

    {{-- Bộ lọc — chỉ hiện khi cột tương ứng đang bật (Phase 6.19) --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card px-5 py-4 mb-5 flex flex-wrap items-end gap-3">
        @if ($this->colVisible('received_date'))
            <div class="min-w-[130px] flex-1">
                <label class="block text-xs font-semibold text-ink/50 mb-1">Từ ngày</label>
                <x-date-input field="fDateFrom" class="px-2.5 py-2" />
            </div>
            <div class="min-w-[130px] flex-1">
                <label class="block text-xs font-semibold text-ink/50 mb-1">Đến ngày</label>
                <x-date-input field="fDateTo" class="px-2.5 py-2" />
            </div>
        @endif
        @if ($this->colVisible('nguon'))
            <div class="min-w-[140px] flex-1">
                <label class="block text-xs font-semibold text-ink/50 mb-1">Nguồn khách</label>
                <select wire:model.live="fNguon" class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                    <option value="">Tất cả 7 nguồn</option>
                    @foreach (\App\Models\Lead::SOURCE_GROUPS as $key => $label)
                        <option value="{{ $key }}">{{ \App\Models\Lead::SOURCE_GROUP_CODES[$key] ?? $key }} — {{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if ($this->colVisible('classification'))
            <div class="min-w-[160px] flex-1">
                <label class="block text-xs font-semibold text-ink/50 mb-1">Danh mục</label>
                <select wire:model.live="fClassification" class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                    <option value="">Tất cả</option>
                    @foreach (\App\Models\Lead::CLASSIFICATIONS as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="min-w-[180px] flex-1">
            <label class="block text-xs font-semibold text-ink/50 mb-1">Trạng thái (7 phase)</label>
            <select wire:model.live="fPhase" class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                <option value="">Tất cả</option>
                @foreach (\App\Models\Lead::CF_PHASE_LABELS as $key => $label)
                    <option value="{{ $key }}">Phase {{ $key }} · {{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="min-w-[180px] flex-[2]">
            <label class="block text-xs font-semibold text-ink/50 mb-1">Tìm kiếm</label>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Tên / SĐT / mã KH / mã booking (BKG-…)"
                   class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm focus:outline-none focus:border-gold-500">
        </div>
    </div>

    {{-- Thanh thao tác hàng loạt --}}
    @if (count($selected) > 0)
        <div class="bg-gold-50 border border-gold-300 rounded-xl px-5 py-3 mb-3 flex flex-wrap items-center gap-3">
            <span class="text-sm font-semibold text-gold-800">Đã chọn {{ count($selected) }} khách hàng</span>
            @if (auth()->user()->hasPermission('lead.distribute'))
                <button wire:click="distributeSelected" wire:confirm="Chạy chia tự động cho {{ count($selected) }} lead đã chọn (chỉ áp lead chưa có owner)?"
                        class="text-sm font-semibold text-blue-700 border border-blue-300 hover:bg-blue-50 px-4 py-1.5 rounded-md">⚡ Chia tự động</button>
            @endif
            @if (auth()->user()->hasPermission('lead.recall'))
                <button wire:click="recallSelected" wire:confirm="Thu hồi {{ count($selected) }} lead — xoá tên sale, đưa về kho team?"
                        class="text-sm font-semibold text-amber-700 border border-amber-300 hover:bg-amber-50 px-4 py-1.5 rounded-md">↩ Thu hồi</button>
            @endif
            @if ($canDelete)
                <button wire:click="deleteSelected" wire:confirm="Xóa {{ count($selected) }} khách hàng đã chọn?"
                        class="text-sm font-semibold text-red-600 border border-red-300 hover:bg-red-50 px-4 py-1.5 rounded-md">🗑 Xóa đã chọn</button>
            @endif
            <button wire:click="$set('selected', [])" class="text-sm text-ink/50 hover:underline">Bỏ chọn</button>
        </div>
    @endif

    {{-- Bảng --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card overflow-x-auto">
        {{-- Thanh chọn cột --}}
        <div class="px-5 py-2.5 border-b border-gold-100 flex items-center justify-between">
            <span class="text-xs text-ink/40">Hiển thị {{ count($visibleCols) }}/{{ count($tableColumns) }} cột</span>
            <div class="relative" x-data="{
                    open: false,
                    localCols: @js($visibleCols),
                    initialCols: @js($visibleCols),
                    toggle(k) {
                        this.localCols = this.localCols.includes(k)
                            ? this.localCols.filter(x => x !== k)
                            : [...this.localCols, k];
                    },
                    apply() {
                        // Chỉ gọi Livewire khi có thay đổi thật — tránh round-trip vô ích.
                        if (JSON.stringify([...this.localCols].sort()) === JSON.stringify([...this.initialCols].sort())) {
                            this.open = false; return;
                        }
                        $wire.set('visibleCols', this.localCols);
                        this.initialCols = [...this.localCols];
                        this.open = false;
                    }
                 }">
                <button @click="open = !open" class="inline-flex items-center gap-1.5 text-sm font-semibold text-gold-700 border border-gold-300 px-3 py-1.5 rounded-md hover:bg-gold-50">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75"/></svg>
                    Chọn cột
                </button>
                <div x-show="open" @click.outside="apply()" x-cloak x-transition
                     class="absolute right-0 top-full mt-1 w-56 bg-white border border-gold-200 rounded-xl shadow-lg z-30 py-2">
                    <div class="px-3 pb-2 mb-1 border-b border-gold-100 flex items-center justify-between">
                        <span class="text-[11px] font-semibold uppercase tracking-wider text-ink/40">Cột hiển thị</span>
                        <button type="button" @click="localCols = @js(array_keys($tableColumns))" class="text-[11px] font-semibold text-gold-600 hover:underline">Hiện tất cả</button>
                    </div>
                    @foreach ($tableColumns as $key => $label)
                        <label class="flex items-center gap-2.5 px-3 py-1.5 hover:bg-gold-50 cursor-pointer text-sm">
                            <input type="checkbox"
                                   :checked="localCols.includes('{{ $key }}')"
                                   @change="toggle('{{ $key }}')"
                                   class="rounded border-gold-300 text-gold-600 focus:ring-gold-500 w-4 h-4">
                            {{ $label }}
                        </label>
                    @endforeach
                    <div class="px-3 pt-2 mt-1 border-t border-gold-100 flex justify-end gap-2">
                        <button type="button" @click="localCols = [...initialCols]; open = false" class="text-[11px] font-semibold text-ink/50 hover:underline">Hủy</button>
                        <button type="button" @click="apply()" class="text-[11px] font-semibold text-gold-700 bg-gold-100 hover:bg-gold-200 px-2 py-1 rounded">Áp dụng</button>
                    </div>
                </div>
            </div>
        </div>

        <table class="w-full text-sm whitespace-nowrap">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wider text-ink/50 bg-gold-50/60">
                    @if ($canDelete)
                        <th class="px-4 py-3 font-semibold w-10">
                            <input type="checkbox" wire:model.live="selectAll" class="rounded border-gold-300 text-gold-600 w-4 h-4">
                        </th>
                    @endif
                    <th class="px-4 py-3 font-semibold w-12">STT</th>
                    @if ($this->colVisible('code'))        <th class="px-4 py-3 font-semibold">Mã KH</th> @endif
                    @if ($this->colVisible('received_date'))<th class="px-4 py-3 font-semibold">Ngày</th> @endif
                    @if ($this->colVisible('page'))        <th class="px-4 py-3 font-semibold">Page</th> @endif
                    @if ($this->colVisible('name'))        <th class="px-4 py-3 font-semibold">Tên khách hàng</th> @endif
                    @if ($this->colVisible('phone'))       <th class="px-4 py-3 font-semibold">SĐT</th> @endif
                    @if ($this->colVisible('nguon'))       <th class="px-4 py-3 font-semibold">Nguồn</th> @endif
                    @if ($this->colVisible('classification'))<th class="px-4 py-3 font-semibold">Phân loại</th> @endif
                    @if ($this->colVisible('region'))      <th class="px-4 py-3 font-semibold">Khu vực</th> @endif
                    @if ($this->colVisible('status'))      <th class="px-4 py-3 font-semibold">Trạng thái</th> @endif
                    @if ($this->colVisible('tele'))        <th class="px-4 py-3 font-semibold">Tele phụ trách</th> @endif
                    @if ($this->colVisible('sale'))        <th class="px-4 py-3 font-semibold">Sale tiếp đón</th> @endif
                    @if ($this->colVisible('importer'))    <th class="px-4 py-3 font-semibold">Người up</th> @endif
                    @if ($canUpdate || $canDelete)
                        <th class="px-4 py-3 font-semibold text-right">Thao tác</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-gold-100">
                @forelse ($leads as $lead)
                    <tr class="hover:bg-gold-50/40 cursor-pointer" onclick="window.location='{{ $lead->canOpenEditForm(auth()->user()) ? route('leads.edit', $lead) : route('leads.show', $lead) }}'">
                        @if ($canDelete)
                            <td class="px-4 py-3" onclick="event.stopPropagation()">
                                <input type="checkbox" wire:model.live="selected" value="{{ $lead->id }}" class="rounded border-gold-300 text-gold-600 w-4 h-4">
                            </td>
                        @endif
                        <td class="px-4 py-3 text-ink/50">{{ $leads->firstItem() + $loop->index }}</td>
                        @if ($this->colVisible('code'))
                            <td class="px-4 py-3 font-mono text-xs text-gold-700">{{ $lead->code ?: '—' }}</td>
                        @endif
                        @if ($this->colVisible('received_date'))
                            <td class="px-4 py-3">{{ $lead->received_date->format('d/m/Y') }}</td>
                        @endif
                        @if ($this->colVisible('page'))
                            <td class="px-4 py-3 text-ink/60">{{ $lead->page ?: '—' }}</td>
                        @endif
                        @if ($this->colVisible('name'))
                            <td class="px-4 py-3 font-semibold text-gold-700">{{ $lead->name }}</td>
                        @endif
                        @if ($this->colVisible('phone'))
                            <td class="px-4 py-3 font-mono">{{ $lead->phoneFor(auth()->user()) }}</td>
                        @endif
                        @if ($this->colVisible('nguon'))
                            <td class="px-4 py-3">
                                @if ($lead->source_group)
                                    <span class="text-xs bg-blue-50 border border-blue-200 px-2 py-0.5 rounded">
                                        {{ \App\Models\Lead::SOURCE_GROUPS[$lead->source_group] ?? $lead->source_group }}
                                    </span>
                                @else — @endif
                            </td>
                        @endif
                        @if ($this->colVisible('classification'))
                            <td class="px-4 py-3">
                                @if ($lead->classification)
                                    @php
                                        $__clsClass = $lead->classification === 'recall' ? 'bg-amber-50 border-amber-200 text-amber-800' : 'bg-slate-50 border-slate-200 text-slate-700';
                                    @endphp
                                    <span class="text-xs font-medium px-2 py-0.5 rounded border {{ $__clsClass }}">{{ $lead->classificationLabel() }}</span>
                                @else — @endif
                            </td>
                        @endif
                        @if ($this->colVisible('region'))
                            <td class="px-4 py-3 text-ink/60">{{ $lead->region ?: '—' }}</td>
                        @endif
                        @if ($this->colVisible('status'))
                            <td class="px-4 py-3">
                                <span class="text-xs font-medium px-2 py-1 rounded bg-gold-50 border border-gold-200 text-gold-800">
                                    {{ $lead->pipelineLabel() }}
                                </span>
                            </td>
                        @endif
                        @php
                            // 2026-08-10: tách "Chia cho" → Tele phụ trách + Sale tiếp đón.
                            $__trio = ($this->colVisible('tele') || $this->colVisible('sale')) ? $lead->handlerTrio() : null;
                        @endphp
                        @if ($this->colVisible('tele'))
                            <td class="px-4 py-3 text-ink/80">{{ $__trio['booking']?->name ?: '—' }}</td>
                        @endif
                        @if ($this->colVisible('sale'))
                            <td class="px-4 py-3 text-ink/80">{{ $__trio['sale']?->name ?: '—' }}</td>
                        @endif
                        @if ($this->colVisible('importer'))
                            <td class="px-4 py-3 text-ink/70 text-xs">{{ $lead->importer?->name ?: '—' }}</td>
                        @endif
                        @if ($canUpdate || $canDelete)
                            <td class="px-4 py-3 text-right" onclick="event.stopPropagation()">
                                <div class="flex items-center justify-end gap-1.5">
                                    @if ($canUpdate)
                                        <a href="{{ route('leads.edit', $lead) }}" title="Sửa"
                                           class="inline-flex items-center justify-center w-8 h-8 rounded-md border border-gold-200 text-gold-700 hover:bg-gold-50">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                        </a>
                                    @endif
                                    @if ($canDelete)
                                        <button wire:click="deleteLead({{ $lead->id }})" wire:confirm="Xóa khách hàng &quot;{{ $lead->name }}&quot;?" title="Xóa"
                                                class="inline-flex items-center justify-center w-8 h-8 rounded-md border border-red-200 text-red-600 hover:bg-red-50">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ 2 + count($visibleCols) + ($canUpdate || $canDelete ? 1 : 0) + ($canDelete ? 1 : 0) }}" class="px-4 py-10 text-center text-ink/40">Không có khách hàng nào trong phạm vi của bạn.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="px-5 py-4 border-t border-gold-100 flex items-center justify-between text-sm text-ink/60">
            <span>Hiển thị {{ $leads->count() }} trong tổng số {{ number_format($leads->total()) }} khách hàng</span>
            {{ $leads->links() }}
        </div>
    </div>

    {{-- Modal chọn trường để xuất Excel --}}
    @if ($showExportModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/40" wire:click="$set('showExportModal', false)"></div>
            <div class="relative bg-white rounded-xl shadow-xl border border-gold-200 w-full max-w-lg p-7 max-h-[90vh] overflow-y-auto">
                <h3 class="text-xl font-bold mb-1">Xuất Excel khách hàng</h3>
                <p class="text-sm text-ink/50 mb-4">Chọn các trường muốn xuất. Danh sách xuất theo đúng bộ lọc đang áp.</p>

                <div class="flex items-center gap-3 mb-3">
                    <button wire:click="selectAllExport" type="button" class="text-xs font-semibold text-gold-700 border border-gold-300 px-3 py-1.5 rounded-md hover:bg-gold-50">Chọn tất cả trường</button>
                    <button wire:click="clearExport" type="button" class="text-xs font-semibold text-ink/50 border border-gold-200 px-3 py-1.5 rounded-md hover:bg-gold-50">Bỏ chọn</button>
                    <span class="text-xs text-ink/40 ml-auto">Đã chọn {{ count($exportCols) }}</span>
                </div>
                @error('exportCols')<p class="text-xs text-red-600 mb-2">{{ $message }}</p>@enderror

                <div class="border border-gold-100 rounded-lg p-3 max-h-72 overflow-y-auto">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-ink/40 mb-1.5">Trường mặc định</p>
                    <div class="grid grid-cols-2 gap-1.5 mb-3">
                        @foreach ($exportCore as $key => $label)
                            <label class="flex items-center gap-2 text-sm cursor-pointer hover:bg-gold-50 rounded px-2 py-1">
                                <input type="checkbox" wire:model="exportCols" value="{{ $key }}" class="rounded border-gold-300 text-gold-600 focus:ring-gold-500 w-4 h-4">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    @if ($exportCustomFields->isNotEmpty())
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-ink/40 mb-1.5">Trường tùy biến</p>
                        <div class="grid grid-cols-2 gap-1.5">
                            @foreach ($exportCustomFields as $cf)
                                <label class="flex items-center gap-2 text-sm cursor-pointer hover:bg-gold-50 rounded px-2 py-1">
                                    <input type="checkbox" wire:model="exportCols" value="cf_{{ $cf->id }}" class="rounded border-gold-300 text-gold-600 focus:ring-gold-500 w-4 h-4">
                                    {{ $cf->label }}
                                    <span class="text-[10px] text-ink/40">{{ $cf->org_unit_id === null ? '(cty)' : $cf->orgUnit?->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="flex justify-end gap-3 mt-6">
                    <button wire:click="$set('showExportModal', false)" type="button" class="text-sm text-ink/60 px-4 py-2">Hủy</button>
                    <button wire:click="export" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-6 py-2 rounded-md">⬇ Xuất Excel</button>
                </div>
            </div>
        </div>
    @endif
</div>
