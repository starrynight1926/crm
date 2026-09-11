<?php

use App\Models\Lead;
use App\Models\LeadStatusLog;
use App\Models\RecallEntry;
use App\Services\Ups\UpsDispatcher;
use App\Support\AdminScope;
use App\Support\SpreadsheetReader;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public $file = null;
    public array $selected = [];
    public string $viewDate = '';
    /** @var array<int,array{name:string,phone:string,reason:string}> */
    public array $skipped = [];

    public function mount(): void
    {
        $this->viewDate = now()->toDateString();
    }

    public function canImport(): bool
    {
        return auth()->user()->hasPermission('recall.import');
    }

    public function canView(): bool
    {
        return auth()->user()->hasPermission('recall.view');
    }

    public function canAssign(): bool
    {
        return auth()->user()->hasPermission('recall.assign');
    }

    public function upload(): void
    {
        abort_unless($this->canImport(), 403);
        $this->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:10240']);

        $tmpPath = $this->file->getRealPath();
        $ext = $this->file->getClientOriginalExtension();
        $data = SpreadsheetReader::read($tmpPath, $ext);

        $headers = array_map('mb_strtolower', $data['headers']);
        $iName = $this->findCol($headers, ['tên', 'ten', 'name', 'họ tên', 'ho ten']);
        $iPhone = $this->findCol($headers, ['sdt', 'sđt', 'phone', 'số điện thoại', 'so dien thoai']);

        if ($iName === null || $iPhone === null) {
            $this->addError('file', 'File xlsx phải có 2 cột: Họ tên + SĐT (header nhận diện linh hoạt).');
            return;
        }

        $today = now()->toDateString();
        $matched = 0;
        $dup = 0;
        $this->skipped = [];

        foreach ($data['rows'] as $row) {
            $name = trim((string) ($row[$iName] ?? ''));
            $phoneRaw = trim((string) ($row[$iPhone] ?? ''));
            if ($name === '' && $phoneRaw === '') continue;

            $phone = Lead::normalizePhone($phoneRaw);
            if (! $phone) {
                $this->skipped[] = ['name' => $name, 'phone' => $phoneRaw, 'reason' => 'SĐT không hợp lệ'];
                continue;
            }

            $lead = Lead::where('phone', $phone)->orderByDesc('id')->first();
            if (! $lead) {
                $this->skipped[] = ['name' => $name, 'phone' => $phoneRaw, 'reason' => 'Không tìm thấy khách trong DB'];
                continue;
            }

            $created = RecallEntry::firstOrCreate(
                ['batch_date' => $today, 'lead_id' => $lead->id],
                [
                    'imported_by' => auth()->id(),
                    'imported_name' => $name,
                    'imported_phone' => $phoneRaw,
                ]
            );
            $created->wasRecentlyCreated ? $matched++ : $dup++;
        }

        $this->file = null;
        $msg = "Import xong: {$matched} match, {$dup} trùng ngày (bỏ qua), " . count($this->skipped) . ' skip.';
        session()->flash('recall_ok', $msg);
    }

    private function findCol(array $headers, array $keys): ?int
    {
        foreach ($headers as $i => $h) {
            $h = trim($h);
            foreach ($keys as $k) if (str_contains($h, $k)) return $i;
        }
        return null;
    }

    public function toggleAll(bool $checked): void
    {
        if ($checked) {
            $this->selected = RecallEntry::whereDate('batch_date', $this->viewDate)
                ->whereNull('assigned_to_user_id')->pluck('id')->all();
        } else {
            $this->selected = [];
        }
    }

    public function assignBulk(): void
    {
        abort_unless($this->canAssign(), 403);
        if (empty($this->selected)) {
            session()->flash('recall_error', 'Chọn ít nhất 1 lead để chia.');
            return;
        }

        $entries = RecallEntry::whereIn('id', $this->selected)
            ->whereNull('assigned_to_user_id')
            ->with('lead')->get();

        $facilityId = $this->resolveFacilityId();
        if (! $facilityId) {
            session()->flash('recall_error', 'Không xác định được cơ sở UPS. Nếu là admin — chọn cơ sở ở topmenu.');
            return;
        }

        $dispatcher = app(UpsDispatcher::class);
        $ok = 0;
        $fail = 0;

        DB::transaction(function () use ($entries, $facilityId, $dispatcher, &$ok, &$fail) {
            foreach ($entries as $entry) {
                $picked = $dispatcher->pickMkt($facilityId);
                if (! $picked) { $fail++; continue; }

                $lead = $entry->lead;
                if (! $lead) { $fail++; continue; }

                $before = $lead->owner_id;
                $lead->update([
                    'owner_id'        => $picked->id,
                    'assigned_at'     => now(),
                    'pool_level'      => Lead::POOL_PERSONAL,
                    'pipeline_phase'  => Lead::PHASE_BOOKING,
                    'pipeline_status' => Lead::PSTATUS_IN_CARE,
                    'phase'           => max((int) $lead->phase, Lead::CF_PHASE_CALL),
                    'source_group'    => Lead::SOURCE_MKT_BR, // khách quay lại
                ]);
                LeadStatusLog::record($lead, 'owner_id', $before, $picked->id, auth()->id());
                LeadStatusLog::record($lead, 'note', null, 'Re-call: chia lại cho ' . $picked->name . ' (batch ' . $entry->batch_date->toDateString() . ')', auth()->id());

                $entry->update([
                    'assigned_to_user_id' => $picked->id,
                    'assigned_by' => auth()->id(),
                    'assigned_at' => now(),
                    'facility_pool_unit_id' => $facilityId,
                ]);
                $ok++;
            }
        });

        $this->selected = [];
        session()->flash('recall_ok', "Chia xong: {$ok} lead → sale UPS MKT, {$fail} fail (UPS list rỗng?).");
    }

    private function resolveFacilityId(): ?int
    {
        $user = auth()->user();

        // Admin đã chọn scope branch → tìm facility đầu tiên trong branch đó.
        if (AdminScope::isSuperAdmin()) {
            $branchId = AdminScope::currentBranchId();
            if ($branchId) {
                $facilityIds = DB::table('org_pool_map')
                    ->join('org_units', 'org_units.id', '=', 'org_pool_map.org_unit_id')
                    ->where('org_units.path', 'like', \App\Models\OrgUnit::find($branchId)?->path . '%')
                    ->pluck('org_pool_map.pool_unit_id');
                return \App\Models\PoolUnit::whereIn('id', $facilityIds)->where('kind', 'facility')->value('id');
            }
        }

        // User thường / Trực Page: cơ sở subtree assignment.
        $ancestors = [];
        foreach ($user->effectiveAssignments() as $a) {
            foreach (array_filter(explode('/', trim((string) $a->orgUnit->path, '/'))) as $s) {
                $ancestors[(int) $s] = true;
            }
        }
        if (! $ancestors) return null;

        $facilities = \App\Models\PoolUnit::where('kind', 'facility')->where('is_active', true)
            ->whereIn('id', function ($q) use ($ancestors) {
                $q->select('pool_unit_id')->from('org_pool_map')->whereIn('org_unit_id', array_keys($ancestors));
            })->pluck('id');

        return $facilities->count() === 1 ? $facilities->first() : ($facilities->first() ?? null);
    }

    public function with(): array
    {
        $entries = RecallEntry::whereDate('batch_date', $this->viewDate)
            ->with(['lead.owner', 'importer', 'assignee'])
            ->orderByDesc('id')->get();

        return [
            'entries' => $entries,
            'availableDates' => RecallEntry::selectRaw('DATE(batch_date) as d')
                ->groupBy('d')->orderByDesc('d')->limit(30)->pluck('d'),
        ];
    }
}; ?>

<div class="pt-4">
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
        <h1 class="text-lg font-bold">Kho Số Re-call — Khách cũ</h1>
        @if ($this->canImport())
            <form wire:submit="upload" enctype="multipart/form-data" class="flex items-center gap-2">
                <input type="file" wire:model="file" accept=".xlsx,.xls,.csv"
                       class="text-xs border border-gold-200 rounded px-2 py-1.5 bg-white">
                <button type="submit" class="text-xs font-semibold bg-gold-600 text-white px-3 py-1.5 rounded-md">Import xlsx</button>
                <span class="text-[11px] text-ink/50 hidden md:inline">Cột: Họ tên + SĐT — chỉ nhận số đã có trong DB.</span>
            </form>
        @endif
    </div>

    @error('file')<div class="mb-3 text-xs text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">{{ $message }}</div>@enderror
    @if (session('recall_ok'))<div class="mb-3 text-xs text-emerald-700 bg-emerald-50 border border-emerald-200 rounded px-3 py-2">{{ session('recall_ok') }}</div>@endif
    @if (session('recall_error'))<div class="mb-3 text-xs text-red-700 bg-red-50 border border-red-200 rounded px-3 py-2">{{ session('recall_error') }}</div>@endif

    @if (! empty($skipped))
        <div class="mb-4 border border-amber-200 bg-amber-50 rounded p-3">
            <div class="text-sm font-semibold text-amber-800 mb-2">Bỏ qua {{ count($skipped) }} dòng:</div>
            <table class="w-full text-xs">
                <thead class="text-amber-700">
                    <tr><th class="text-left pb-1">Tên</th><th class="text-left pb-1">SĐT</th><th class="text-left pb-1">Lý do</th></tr>
                </thead>
                <tbody>
                    @foreach (array_slice($skipped, 0, 30) as $s)
                        <tr class="border-t border-amber-100"><td class="py-0.5">{{ $s['name'] }}</td><td>{{ $s['phone'] }}</td><td class="text-amber-800">{{ $s['reason'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
            @if (count($skipped) > 30)<div class="text-[11px] text-amber-700 mt-1">... còn {{ count($skipped)-30 }} dòng nữa.</div>@endif
        </div>
    @endif

    <div class="flex items-center gap-3 mb-3 flex-wrap">
        <label class="text-xs font-semibold text-ink/60">Ngày batch:</label>
        <select wire:model.live="viewDate" class="text-xs border border-gold-200 rounded px-2 py-1.5 bg-white">
            <option value="{{ now()->toDateString() }}">Hôm nay ({{ now()->format('d/m') }})</option>
            @foreach ($availableDates as $d)
                @if ($d !== now()->toDateString())
                    <option value="{{ $d }}">{{ \Carbon\Carbon::parse($d)->format('d/m/Y') }}</option>
                @endif
            @endforeach
        </select>

        @if ($this->canAssign())
            <button wire:click="toggleAll(true)" class="text-xs text-gold-700 border border-gold-300 hover:bg-gold-50 px-3 py-1.5 rounded">Chọn tất chưa chia</button>
            <button wire:click="toggleAll(false)" class="text-xs text-ink/50 hover:text-ink/80 px-2 py-1.5">Bỏ chọn</button>
            <button wire:click="assignBulk"
                    wire:confirm="Chia {{ count($selected) }} lead đã chọn cho Sale UPS MKT hôm nay?"
                    class="text-xs font-semibold bg-emerald-600 text-white px-3 py-1.5 rounded-md {{ empty($selected) ? 'opacity-40 pointer-events-none' : '' }}">
                Chia hàng loạt ({{ count($selected) }}) → UPS MKT
            </button>
        @endif
    </div>

    <div class="bg-white border border-gold-200 rounded-lg overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gold-50 text-ink/70 text-xs uppercase">
                <tr>
                    <th class="px-3 py-2 w-8"></th>
                    <th class="px-3 py-2 text-left">Mã KH</th>
                    <th class="px-3 py-2 text-left">Tên</th>
                    <th class="px-3 py-2 text-left">SĐT</th>
                    <th class="px-3 py-2 text-left">Trạng thái</th>
                    <th class="px-3 py-2 text-left">Người import</th>
                    <th class="px-3 py-2 text-left">Chia cho</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($entries as $e)
                    <tr class="border-t border-gold-100 {{ $e->assigned_to_user_id ? 'bg-emerald-50/30' : '' }}">
                        <td class="px-3 py-2">
                            @if (! $e->assigned_to_user_id && $this->canAssign())
                                <input type="checkbox" wire:model.live="selected" value="{{ $e->id }}">
                            @endif
                        </td>
                        <td class="px-3 py-2 font-mono text-xs text-gold-700">{{ $e->lead?->code }}</td>
                        <td class="px-3 py-2">{{ $e->lead?->name }}</td>
                        <td class="px-3 py-2 tabular-nums">{{ $e->lead?->phone }}</td>
                        <td class="px-3 py-2">
                            @if ($e->assigned_to_user_id)
                                <span class="text-xs px-2 py-0.5 rounded bg-emerald-100 text-emerald-700 font-semibold">Đã chia</span>
                            @else
                                <span class="text-xs px-2 py-0.5 rounded bg-slate-100 text-slate-600">Chờ chia</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs text-ink/60">{{ $e->importer?->name }}</td>
                        <td class="px-3 py-2 text-xs">
                            @if ($e->assignee)
                                <b class="text-emerald-700">{{ $e->assignee->name }}</b>
                                <div class="text-[10px] text-ink/50">{{ $e->assigned_at?->format('H:i d/m') }}</div>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-3 py-8 text-center text-ink/40 text-sm">Chưa có số re-call cho ngày này.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
