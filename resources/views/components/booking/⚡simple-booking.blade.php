<?php

use App\Models\BookingDraft;
use App\Models\Facility;
use App\Models\Lead;
use App\Models\SbBacSi;
use App\Models\SbRoom;
use App\Models\SbService;
use App\Services\SbookingClient;
use App\Support\AdminScope;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    /** @var array<string,mixed> Row đang nhập inline */
    public array $draft = [
        'facility_id'    => null,
        'ngay_dat_lich'  => null,
        'gio'            => null,
        'nguon'          => null,
        'ho_ten'         => null,
        'sdt'            => null,
        'sale'           => null,
        'lieu_phap'      => null,
        'sb_dich_vu_id'  => null,
        'sb_phong_id'    => null,
        'so_lo'          => null,
        'dieu_duong'     => null,
        'bac_si'         => null,
        'sb_bac_si_id'   => null,
        'ghi_chu'        => null,
    ];

    /** Filter facility (null = tất cả trong scope) */
    public ?int $filterFacilityId = null;
    public bool $onlyWarning = false;

    /** 2026-10-05: khoảng ngày lọc theo cột `ngay_dat_lich`. Default = hôm nay. */
    public string $fromDate = '';
    public string $toDate = '';
    /** Tick → bỏ qua khoảng ngày, hiện tất cả mới → cũ. */
    public bool $allDates = false;

    /** Mã rút gọn hiển thị trên tab theo slug sbooking (đồng bộ với navbar). */
    public const SHORT_NAMES = [
        '59ntn'     => 'CS1: 59NTN',
        '207nvt'    => 'CS2: 207NVT',
        '11-15tdn'  => 'CS3: 11&15TDN',
    ];

    /** Row đang được edit inline (id => field array) */
    public array $editing = [];

    /** 2026-10-09: cảnh báo preflight phòng/bác sĩ theo key ('draft' hoặc 'edit-<id>'). */
    public array $warnings = [];
    /** Lần "Vẫn lưu" — bỏ qua preflight cho key đó 1 lần. */
    public array $overrides = [];

    public function mount(): void
    {
        // 2026-09-30: tạm ẩn feature — chỉ super admin thấy.
        abort_unless(AdminScope::isSuperAdmin(), 403);
        $facilities = $this->visibleFacilities();
        if ($facilities->count() >= 1) {
            $this->draft['facility_id'] = $facilities->first()->id;
        }
        $today = now()->toDateString();
        $this->fromDate = $today;
        $this->toDate = $today;
    }

    public function visibleFacilities()
    {
        $q = Facility::whereNull('parent_id')
            ->where('active', true)
            ->whereNotNull('booking_co_so_slug');
        if (AdminScope::isSuperAdmin()) {
            return $q->orderBy('booking_co_so_slug')->get();
        }
        $u = auth()->user();
        $leafIds = Lead::visibleTo($u)->whereNotNull('facility_id')->distinct()->pluck('facility_id')->all();
        if (! $leafIds) return collect();
        $rootIds = [];
        foreach (Facility::whereIn('id', $leafIds)->get() as $f) {
            $n = $f;
            while ($n && $n->parent_id) $n = $n->parent;
            if ($n) $rootIds[$n->id] = true;
        }
        if (! $rootIds) return collect();
        return $q->whereIn('id', array_keys($rootIds))->orderBy('booking_co_so_slug')->get();
    }

    public function facilityShortLabel(Facility $f): string
    {
        return self::SHORT_NAMES[$f->booking_co_so_slug] ?? $f->name;
    }

    protected function visibleFacilityIds(): array
    {
        return $this->visibleFacilities()->pluck('id')->all();
    }

    /** 2026-10-09: catalog dịch vụ scope theo sbooking_co_so_id (null = dùng chung). */
    protected function servicesFor(?int $facilityId): \Illuminate\Support\Collection
    {
        if (! $facilityId) return collect();
        $cs = (int) (Facility::find($facilityId)?->sbooking_co_so_id ?? 0);
        if (! $cs) return collect();
        return SbService::query()->where('active', true)
            ->where(fn ($q) => $q->whereNull('sbooking_co_so_id')->orWhere('sbooking_co_so_id', $cs))
            ->orderBy('ten')->get();
    }

    protected function roomsFor(?int $facilityId): \Illuminate\Support\Collection
    {
        if (! $facilityId) return collect();
        $cs = (int) (Facility::find($facilityId)?->sbooking_co_so_id ?? 0);
        if (! $cs) return collect();
        return SbRoom::query()->where('trang_thai', 'hoat_dong')
            ->where('sbooking_co_so_id', $cs)->orderBy('ten')->get();
    }

    protected function doctorsFor(?int $facilityId): \Illuminate\Support\Collection
    {
        if (! $facilityId) return collect();
        $cs = (int) (Facility::find($facilityId)?->sbooking_co_so_id ?? 0);
        if (! $cs) return collect();
        return SbBacSi::query()->where('active', true)
            ->where(fn ($q) => $q->where('xuat_hien_moi_co_so', true)->orWhere('sbooking_co_so_id', $cs))
            ->orderBy('ten')->get();
    }

    /** Phòng được map cho 1 dịch vụ (gợi ý highlight ★). */
    protected function suggestedRoomIds(?int $sbDvId): array
    {
        if (! $sbDvId) return [];
        return DB::table('sb_dich_vu_phong')
            ->where('sbooking_dich_vu_id', $sbDvId)
            ->pluck('sbooking_phong_id')->map(fn ($v) => (int) $v)->all();
    }

    /** Tính giờ kết thúc từ gio + thoi_gian_phut của dịch vụ. */
    protected function computeEndTime(?string $gio, ?int $sbDvId): ?string
    {
        if (! $gio || ! $sbDvId) return null;
        $phut = (int) (SbService::where('sbooking_id', $sbDvId)->value('thoi_gian_phut') ?? 0);
        if ($phut <= 0) return null;
        try {
            return \Carbon\Carbon::parse($gio)->addMinutes($phut)->format('H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Preflight phòng + bác sĩ bên sbooking. Trả null nếu cả 2 ok, hoặc reason nếu 1 trong 2 bận.
     * Phòng chỉ check khi đủ facility+ngay+gio+dv+phong. Bác sĩ chỉ check khi đủ bac_si+ngay+gio+dv (để tính end time).
     */
    protected function preflightOrReason(array $data): ?string
    {
        $facilityId = (int) ($data['facility_id'] ?? 0);
        $ngay = $data['ngay_dat_lich'] ?? null;
        $gio = $data['gio'] ?? null;
        $dvId = (int) ($data['sb_dich_vu_id'] ?? 0);
        $phongId = (int) ($data['sb_phong_id'] ?? 0);
        $bacSiId = (int) ($data['sb_bac_si_id'] ?? 0);
        if (! $facilityId || ! $ngay || ! $gio || ! $dvId) return null;

        $cs = (int) (Facility::find($facilityId)?->sbooking_co_so_id ?? 0);
        if (! $cs) return null;

        $end = $this->computeEndTime($gio, $dvId);
        if (! $end) return null;
        $start = strlen($gio) === 5 ? ($gio . ':00') : $gio;

        $client = app(SbookingClient::class);

        if ($phongId) {
            $pf = $client->preflightRoom([
                'co_so_id' => $cs, 'ngay_dat' => (string) $ngay,
                'gio_thuc_hien' => $start, 'gio_ket_thuc' => $end,
                'dich_vu_id' => $dvId, 'phong_id' => $phongId,
            ]);
            if (! $pf['ok']) return 'Phòng bận: ' . ($pf['reason'] ?? 'không rõ');
        }

        if ($bacSiId) {
            $pf = $client->preflightDoctor([
                'bac_si_id' => $bacSiId, 'ngay_dat' => (string) $ngay,
                'gio_thuc_hien' => $start, 'gio_ket_thuc' => $end,
            ]);
            if (! $pf['ok']) return 'Bác sĩ bận: ' . ($pf['reason'] ?? 'không rõ');
        }

        return null;
    }

    public function save(bool $force = false): void
    {
        if (! $this->draft['facility_id']) {
            if ($this->filterFacilityId) {
                $this->draft['facility_id'] = (int) $this->filterFacilityId;
            } else {
                $facilities = $this->visibleFacilities();
                if ($facilities->count() === 1) {
                    $this->draft['facility_id'] = $facilities->first()->id;
                } else {
                    $this->addError('draft.facility_id', 'Chọn tab cơ sở ở dưới trước khi lưu.');
                    return;
                }
            }
        }

        $facilityIds = $this->visibleFacilityIds();
        $fid = (int) ($this->draft['facility_id'] ?? 0);
        if (! in_array($fid, $facilityIds, true) && ! AdminScope::isSuperAdmin()) {
            $this->addError('draft.facility_id', 'Cơ sở không thuộc phạm vi của bạn.');
            return;
        }

        $data = $this->normalize($this->draft);
        // Auto tính giờ kết thúc.
        $data['gio_ket_thuc'] = $this->computeEndTime($data['gio'] ?? null, $data['sb_dich_vu_id'] ?? null);

        if (! $force) {
            $reason = $this->preflightOrReason($data);
            if ($reason) {
                $this->warnings['draft'] = $reason;
                return;
            }
        }

        BookingDraft::create(array_merge($data, [
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]));

        $keepFacility = $this->draft['facility_id'];
        $keepNguon    = $this->draft['nguon'];
        $this->draft = array_fill_keys(array_keys($this->draft), null);
        $this->draft['facility_id'] = $keepFacility;
        $this->draft['nguon']       = $keepNguon;
        unset($this->warnings['draft']);

        $this->dispatch('draft-saved');
    }

    public function saveForce(): void { $this->save(true); }

    public function startEdit(int $id): void
    {
        $row = BookingDraft::find($id);
        if (! $row || ! $this->canTouch($row)) return;
        $this->editing[$id] = $row->only(array_keys($this->draft));
    }

    public function cancelEdit(int $id): void
    {
        unset($this->editing[$id], $this->warnings['edit-' . $id]);
    }

    public function saveEdit(int $id, bool $force = false): void
    {
        $row = BookingDraft::find($id);
        if (! $row || ! $this->canTouch($row)) return;
        $data = $this->normalize($this->editing[$id] ?? []);
        $data['gio_ket_thuc'] = $this->computeEndTime($data['gio'] ?? null, $data['sb_dich_vu_id'] ?? null);

        if (! $force) {
            $reason = $this->preflightOrReason($data);
            if ($reason) {
                $this->warnings['edit-' . $id] = $reason;
                return;
            }
        }

        $data['updated_by'] = auth()->id();
        $row->update($data);
        unset($this->editing[$id], $this->warnings['edit-' . $id]);
    }

    public function saveEditForce(int $id): void { $this->saveEdit($id, true); }

    public function deleteRow(int $id): void
    {
        $row = BookingDraft::find($id);
        if (! $row || ! $this->canTouch($row)) return;
        $row->delete();
    }

    protected function normalize(array $data): array
    {
        return array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v, $data);
    }

    protected function canTouch(BookingDraft $row): bool
    {
        if (AdminScope::isSuperAdmin()) return true;
        return in_array((int) $row->facility_id, $this->visibleFacilityIds(), true);
    }

    public function updatedFilterFacilityId(): void
    {
        if ($this->filterFacilityId) $this->draft['facility_id'] = (int) $this->filterFacilityId;
        $this->resetPage();
    }
    public function updatedOnlyWarning(): void { $this->resetPage(); }
    public function updatedFromDate(): void { $this->resetPage(); }
    public function updatedToDate(): void { $this->resetPage(); }
    public function updatedAllDates(): void { $this->resetPage(); }

    public function with(): array
    {
        $facilities = $this->visibleFacilities();
        $facilityIds = $facilities->pluck('id')->all();

        $q = BookingDraft::query()
            ->with(['facility', 'creator'])
            ->latest('created_at');

        if (! AdminScope::isSuperAdmin()) {
            $q->whereIn('facility_id', $facilityIds ?: [0]);
        }
        if ($this->filterFacilityId) {
            $q->where('facility_id', $this->filterFacilityId);
        }

        if (! $this->allDates) {
            if ($this->fromDate !== '') $q->whereDate('ngay_dat_lich', '>=', $this->fromDate);
            if ($this->toDate !== '')   $q->whereDate('ngay_dat_lich', '<=', $this->toDate);
        }

        $rows = $q->paginate(30);

        if ($this->onlyWarning) {
            $items = $rows->getCollection()->filter(fn ($r) => $r->statusColor() !== 'green')->values();
            $rows->setCollection($items);
        }

        // Catalog cho facility của tab đang chọn (hoặc facility đầu tiên nếu "Tất cả").
        $catalogFid = $this->filterFacilityId ?: ($facilities->first()->id ?? null);
        $services = $this->servicesFor($catalogFid);
        $rooms = $this->roomsFor($catalogFid);
        $doctors = $this->doctorsFor($catalogFid);

        $draftSuggestedRooms = $this->suggestedRoomIds($this->draft['sb_dich_vu_id'] ?? null);
        $editingSuggestedRooms = [];
        foreach ($this->editing as $rid => $ed) {
            $editingSuggestedRooms[$rid] = $this->suggestedRoomIds($ed['sb_dich_vu_id'] ?? null);
        }

        return [
            'rows' => $rows,
            'facilities' => $facilities,
            'sourceOptions' => Lead::SOURCE_GROUPS,
            'sourceCodes'   => Lead::SOURCE_GROUP_CODES,
            'services' => $services,
            'rooms' => $rooms,
            'doctors' => $doctors,
            'draftSuggestedRooms' => $draftSuggestedRooms,
            'editingSuggestedRooms' => $editingSuggestedRooms,
        ];
    }
}; ?>

{{-- 2026-10-05: UI giống Google Sheets. 2026-10-09: thêm cột Phòng + preflight. --}}
<div class="-mx-4 md:-mx-6 -my-6 md:-my-8 bg-[#f8f9fa] min-h-[calc(100vh-5rem)] flex flex-col" x-data style="font-family: Arial, Roboto, 'Helvetica Neue', sans-serif;">

    <div class="flex items-center justify-between gap-3 px-3 py-1.5 border-b border-gray-300 bg-white">
        <div class="flex items-center gap-3">
            <span class="text-sm font-semibold text-gray-800">⚡ Simple Booking</span>
            <span class="text-[11px] text-gray-500">Nháp lịch — Enter là chuyển ô, dòng xanh dương trên cùng để nhập mới.</span>
        </div>
        <div class="flex items-center gap-3 text-[12px]">
            <label class="flex items-center gap-1.5 text-gray-700">
                <span>Từ</span>
                <input type="date" wire:model.live="fromDate" @disabled($allDates)
                       class="border border-gray-300 rounded px-1.5 py-0.5 text-[12px] disabled:bg-gray-100 disabled:text-gray-400">
            </label>
            <label class="flex items-center gap-1.5 text-gray-700">
                <span>đến</span>
                <input type="date" wire:model.live="toDate" @disabled($allDates)
                       class="border border-gray-300 rounded px-1.5 py-0.5 text-[12px] disabled:bg-gray-100 disabled:text-gray-400">
            </label>
            <label class="flex items-center gap-1.5 text-gray-700">
                <input type="checkbox" wire:model.live="allDates" class="w-3.5 h-3.5">
                Tất cả (mới → cũ)
            </label>
            <span class="text-gray-300">│</span>
            <label class="flex items-center gap-1.5 text-gray-700">
                <input type="checkbox" wire:model.live="onlyWarning" class="w-3.5 h-3.5">
                Chỉ hiện dòng thiếu
            </label>
            <a href="{{ route('leads.index') }}" class="text-gray-600 hover:text-gray-900 underline">← Danh sách KH</a>
        </div>
    </div>

    <div class="flex-1 overflow-auto bg-white">
        <table class="w-full border-collapse" style="font-size: 12px;">
            <thead class="sticky top-0 z-10">
                <tr class="bg-[#f1f3f4] text-gray-700">
                    @php
                        $thCls = 'border border-gray-300 px-2 py-1 font-semibold text-left whitespace-nowrap';
                    @endphp
                    <th class="{{ $thCls }} w-10 text-center">●</th>
                    <th class="{{ $thCls }}">Dấu thời gian</th>
                    <th class="{{ $thCls }}">Ngày</th>
                    <th class="{{ $thCls }}">Giờ</th>
                    <th class="{{ $thCls }}">Nguồn</th>
                    <th class="{{ $thCls }}">Họ tên KH</th>
                    <th class="{{ $thCls }}">SĐT</th>
                    <th class="{{ $thCls }}">Sale</th>
                    <th class="{{ $thCls }}">Liệu pháp</th>
                    <th class="{{ $thCls }}">Phòng</th>
                    <th class="{{ $thCls }} w-14 text-center">Số lọ</th>
                    <th class="{{ $thCls }}">Điều dưỡng</th>
                    <th class="{{ $thCls }}">Bác sĩ</th>
                    <th class="{{ $thCls }}">Khách tặng & ghi chú</th>
                    <th class="{{ $thCls }} w-28 text-center">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $tdCls = 'border border-gray-300 px-2 py-0.5 whitespace-nowrap';
                    $inpCls = 'w-full px-1 py-0 border-0 bg-transparent focus:outline-none focus:ring-1 focus:ring-blue-500 focus:bg-white';
                @endphp

                {{-- Row nhập mới --}}
                <tr class="bg-[#e8f0fe]" wire:key="draft-input">
                    <td class="{{ $tdCls }} text-center text-blue-600">+</td>
                    <td class="{{ $tdCls }} text-gray-400 italic">(tự động)</td>
                    <td class="{{ $tdCls }}"><input type="date" wire:model="draft.ngay_dat_lich" class="{{ $inpCls }}"></td>
                    <td class="{{ $tdCls }}"><input type="time" wire:model="draft.gio" class="{{ $inpCls }}"></td>
                    <td class="{{ $tdCls }}">
                        <select wire:model="draft.nguon" class="{{ $inpCls }}">
                            <option value="">—</option>
                            @foreach ($sourceOptions as $k => $v)
                                <option value="{{ $k }}">{{ $sourceCodes[$k] ?? strtoupper($k) }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td class="{{ $tdCls }}"><input type="text" wire:model="draft.ho_ten" class="{{ $inpCls }}"></td>
                    <td class="{{ $tdCls }}"><input type="text" wire:model="draft.sdt" class="{{ $inpCls }}"></td>
                    <td class="{{ $tdCls }}"><input type="text" wire:model="draft.sale" class="{{ $inpCls }}"></td>
                    <td class="{{ $tdCls }}">
                        <select wire:model.live="draft.sb_dich_vu_id" class="{{ $inpCls }}">
                            <option value="">—</option>
                            @foreach ($services as $sv)
                                <option value="{{ $sv->sbooking_id }}">{{ $sv->ten }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td class="{{ $tdCls }}">
                        <select wire:model="draft.sb_phong_id" class="{{ $inpCls }}">
                            <option value="">—</option>
                            @foreach ($rooms as $rm)
                                @php $sg = in_array((int) $rm->sbooking_id, $draftSuggestedRooms, true); @endphp
                                <option value="{{ $rm->sbooking_id }}" {{ $sg ? 'style=background:#fff2cc' : '' }}>
                                    {{ $sg ? '★ ' : '' }}{{ $rm->ten }}
                                </option>
                            @endforeach
                        </select>
                    </td>
                    <td class="{{ $tdCls }}"><input type="text" wire:model="draft.so_lo" class="{{ $inpCls }} text-center"></td>
                    <td class="{{ $tdCls }}"><input type="text" wire:model="draft.dieu_duong" class="{{ $inpCls }}"></td>
                    <td class="{{ $tdCls }}">
                        <select wire:model="draft.sb_bac_si_id" class="{{ $inpCls }}">
                            <option value="">—</option>
                            @foreach ($doctors as $d)
                                <option value="{{ $d->sbooking_id }}">{{ $d->ten }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td class="{{ $tdCls }}"><input type="text" wire:model="draft.ghi_chu" class="{{ $inpCls }}"></td>
                    <td class="{{ $tdCls }} text-center">
                        <button wire:click="save" class="text-[11px] font-semibold text-white bg-blue-600 hover:bg-blue-700 px-2 py-0.5 rounded">+ Lưu</button>
                    </td>
                </tr>

                {{-- Warning banner cho row draft --}}
                @if (!empty($warnings['draft']))
                    <tr wire:key="warn-draft" class="bg-[#fff2cc]">
                        <td colspan="15" class="border border-amber-400 px-3 py-1.5 text-[12px] text-amber-900">
                            ⚠️ {{ $warnings['draft'] }} —
                            <button wire:click="saveForce" class="font-semibold underline text-amber-900 hover:text-amber-700">Vẫn lưu</button>
                            <button wire:click="$set('warnings.draft', null)" class="ml-2 underline text-gray-700">Sửa lại</button>
                        </td>
                    </tr>
                @endif

                {{-- Rows đã lưu --}}
                @forelse ($rows as $r)
                    @php
                        $color = $r->statusColor();
                        $rowBg = match ($color) {
                            'red'    => 'bg-[#f4c7c3]',
                            'yellow' => 'bg-[#fff2cc]',
                            default  => 'bg-[#b7e1cd]',
                        };
                        $reasons = $r->warningReasons();
                        $isEdit = isset($editing[$r->id]);
                        $editKey = 'edit-' . $r->id;
                        $sugRoomsEdit = $editingSuggestedRooms[$r->id] ?? [];
                    @endphp
                    <tr wire:key="row-{{ $r->id }}" class="{{ $rowBg }}" title="{{ implode(' · ', $reasons) ?: 'Đủ điều kiện' }}">
                        <td class="{{ $tdCls }} text-center">{{ ['red'=>'🔴','yellow'=>'🟡','green'=>'🟢'][$color] }}</td>
                        <td class="{{ $tdCls }} text-[11px] text-gray-700">
                            {{ $r->created_at?->format('d/m/Y H:i:s') }}
                            @if ($r->creator?->name) <span class="text-gray-500">· {{ $r->creator->name }}</span>@endif
                        </td>
                        @if ($isEdit)
                            <td class="{{ $tdCls }}"><input type="date" wire:model="editing.{{ $r->id }}.ngay_dat_lich" class="{{ $inpCls }}"></td>
                            <td class="{{ $tdCls }}"><input type="time" wire:model="editing.{{ $r->id }}.gio" class="{{ $inpCls }}"></td>
                            <td class="{{ $tdCls }}">
                                <select wire:model="editing.{{ $r->id }}.nguon" class="{{ $inpCls }}">
                                    <option value="">—</option>
                                    @foreach ($sourceOptions as $k => $v)
                                        <option value="{{ $k }}">{{ $sourceCodes[$k] ?? strtoupper($k) }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="{{ $tdCls }}"><input type="text" wire:model="editing.{{ $r->id }}.ho_ten" class="{{ $inpCls }}"></td>
                            <td class="{{ $tdCls }}"><input type="text" wire:model="editing.{{ $r->id }}.sdt" class="{{ $inpCls }}"></td>
                            <td class="{{ $tdCls }}"><input type="text" wire:model="editing.{{ $r->id }}.sale" class="{{ $inpCls }}"></td>
                            <td class="{{ $tdCls }}">
                                <select wire:model.live="editing.{{ $r->id }}.sb_dich_vu_id" class="{{ $inpCls }}">
                                    <option value="">—</option>
                                    @foreach ($services as $sv)
                                        <option value="{{ $sv->sbooking_id }}">{{ $sv->ten }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="{{ $tdCls }}">
                                <select wire:model="editing.{{ $r->id }}.sb_phong_id" class="{{ $inpCls }}">
                                    <option value="">—</option>
                                    @foreach ($rooms as $rm)
                                        @php $sg = in_array((int) $rm->sbooking_id, $sugRoomsEdit, true); @endphp
                                        <option value="{{ $rm->sbooking_id }}" {{ $sg ? 'style=background:#fff2cc' : '' }}>
                                            {{ $sg ? '★ ' : '' }}{{ $rm->ten }}
                                        </option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="{{ $tdCls }}"><input type="text" wire:model="editing.{{ $r->id }}.so_lo" class="{{ $inpCls }} text-center"></td>
                            <td class="{{ $tdCls }}"><input type="text" wire:model="editing.{{ $r->id }}.dieu_duong" class="{{ $inpCls }}"></td>
                            <td class="{{ $tdCls }}">
                                <select wire:model="editing.{{ $r->id }}.sb_bac_si_id" class="{{ $inpCls }}">
                                    <option value="">—</option>
                                    @foreach ($doctors as $d)
                                        <option value="{{ $d->sbooking_id }}">{{ $d->ten }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="{{ $tdCls }}"><input type="text" wire:model="editing.{{ $r->id }}.ghi_chu" class="{{ $inpCls }}"></td>
                            <td class="{{ $tdCls }} text-center">
                                <button wire:click="saveEdit({{ $r->id }})" class="text-[11px] text-green-700 hover:underline">💾 Lưu</button>
                                <button wire:click="cancelEdit({{ $r->id }})" class="text-[11px] text-gray-600 hover:underline ml-1">↩ Thoát sửa</button>
                            </td>
                        @else
                            <td class="{{ $tdCls }} text-center">{{ $r->ngay_dat_lich?->format('d/m/Y') }}</td>
                            <td class="{{ $tdCls }} text-center">{{ $r->gio ? \Illuminate\Support\Str::of($r->gio)->before(':') . ':' . substr($r->gio, 3, 2) : '' }}</td>
                            <td class="{{ $tdCls }} text-center uppercase">{{ $sourceCodes[$r->nguon] ?? $r->nguon }}</td>
                            <td class="{{ $tdCls }}">{{ $r->ho_ten }}</td>
                            <td class="{{ $tdCls }}">{{ $r->sdt }}</td>
                            <td class="{{ $tdCls }}">{{ $r->sale }}</td>
                            <td class="{{ $tdCls }}">
                                @php $svName = $r->sb_dich_vu_id ? ($services->firstWhere('sbooking_id', $r->sb_dich_vu_id)?->ten) : null; @endphp
                                {{ $svName ?: $r->lieu_phap }}
                            </td>
                            <td class="{{ $tdCls }}">
                                @php $rmName = $r->sb_phong_id ? ($rooms->firstWhere('sbooking_id', $r->sb_phong_id)?->ten) : null; @endphp
                                {{ $rmName ?: '—' }}
                            </td>
                            <td class="{{ $tdCls }} text-center">{{ $r->so_lo }}</td>
                            <td class="{{ $tdCls }}">{{ $r->dieu_duong }}</td>
                            <td class="{{ $tdCls }}">
                                @php $bsName = $r->sb_bac_si_id ? ($doctors->firstWhere('sbooking_id', $r->sb_bac_si_id)?->ten) : null; @endphp
                                {{ $bsName ?: $r->bac_si }}
                            </td>
                            <td class="{{ $tdCls }}">{{ $r->ghi_chu }}</td>
                            <td class="{{ $tdCls }} text-center">
                                <button wire:click="startEdit({{ $r->id }})" class="text-[11px] text-blue-700 hover:underline">Sửa</button>
                                <button wire:click="deleteRow({{ $r->id }})" wire:confirm="Xoá row này?" class="text-[11px] text-red-700 hover:underline ml-1">Xoá</button>
                            </td>
                        @endif
                    </tr>
                    @if (!empty($warnings[$editKey]))
                        <tr wire:key="warn-{{ $r->id }}" class="bg-[#fff2cc]">
                            <td colspan="15" class="border border-amber-400 px-3 py-1.5 text-[12px] text-amber-900">
                                ⚠️ {{ $warnings[$editKey] }} —
                                <button wire:click="saveEditForce({{ $r->id }})" class="font-semibold underline text-amber-900 hover:text-amber-700">Vẫn lưu</button>
                                <button wire:click="$set('warnings.{{ $editKey }}', null)" class="ml-2 underline text-gray-700">Sửa lại</button>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="15" class="border border-gray-300 p-6 text-center text-gray-400 italic">Chưa có row nào — nhập ở dòng xanh dương phía trên.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="px-3 py-2 bg-white border-t border-gray-200">{{ $rows->links() }}</div>
    </div>

    {{-- Tab footer theo cơ sở --}}
    <div class="flex items-center gap-0.5 border-t border-gray-300 bg-[#f8f9fa] px-2 py-1 overflow-x-auto">
        <span class="text-[11px] text-gray-500 mr-2 shrink-0">Cơ sở:</span>
        @if ($facilities->count() > 1)
            <button type="button" wire:click="$set('filterFacilityId', null)"
                    class="text-[12px] px-3 py-1 rounded-t border-x border-t border-gray-300 shrink-0
                           {{ $filterFacilityId === null ? 'bg-white text-gray-900 font-semibold border-b-white -mb-px' : 'bg-[#e8eaed] text-gray-600 hover:bg-gray-200' }}">
                📋 Tất cả
            </button>
        @endif
        @foreach ($facilities as $f)
            <button type="button" wire:click="$set('filterFacilityId', {{ $f->id }})"
                    class="text-[12px] px-3 py-1 rounded-t border-x border-t border-gray-300 shrink-0
                           {{ (int) $filterFacilityId === $f->id ? 'bg-white text-gray-900 font-semibold border-b-white -mb-px' : 'bg-[#e8eaed] text-gray-600 hover:bg-gray-200' }}"
                    title="{{ $f->name }}">
                {{ $this->facilityShortLabel($f) }}
            </button>
        @endforeach
        <span class="ml-auto text-[11px] text-gray-500 shrink-0">
            {{ $rows->total() ?? $rows->count() }} dòng · 🔴 thiếu KH/SĐT/ngày · 🟡 thiếu giờ/sale/liệu pháp · 🟢 đủ · ★ phòng gợi ý theo dịch vụ
        </span>
    </div>
</div>
