<?php

use App\Models\BookingDraft;
use App\Models\Facility;
use App\Models\Lead;
use App\Support\AdminScope;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    /** @var array<string,mixed> Row đang nhập inline */
    public array $draft = [
        'facility_id'   => null,
        'ngay_dat_lich' => null,
        'gio'           => null,
        'nguon'         => null,
        'ho_ten'        => null,
        'sdt'           => null,
        'sale'          => null,
        'lieu_phap'     => null,
        'so_lo'         => null,
        'dieu_duong'    => null,
        'bac_si'        => null,
        'ghi_chu'       => null,
    ];

    /** Filter facility (null = tất cả trong scope) */
    public ?int $filterFacilityId = null;
    public bool $onlyWarning = false;

    /** Row đang được edit inline (id => field array) */
    public array $editing = [];

    public function mount(): void
    {
        // 2026-09-30: tạm ẩn feature — chỉ super admin thấy.
        abort_unless(AdminScope::isSuperAdmin(), 403);
        $facilities = $this->visibleFacilities();
        // Mặc định facility_id draft = facility đầu tiên user thấy (1 cơ sở → chọn luôn).
        if ($facilities->count() >= 1) {
            $this->draft['facility_id'] = $facilities->first()->id;
        }
    }

    /** Facilities user được thấy. Super admin: all; user thường: các facility_id đã từng có lead visible. */
    public function visibleFacilities()
    {
        // Chỉ hiển thị các cơ sở gốc (parent_id=null) — không lôi phòng con / khối chuyên môn.
        $q = Facility::whereNull('parent_id');
        if (AdminScope::isSuperAdmin()) {
            return $q->orderBy('name')->get();
        }
        $u = auth()->user();
        $leafIds = Lead::visibleTo($u)->whereNotNull('facility_id')->distinct()->pluck('facility_id')->all();
        if (! $leafIds) return collect();
        // Đi lên tới root facility cho mỗi facility user thấy được.
        $rootIds = [];
        foreach (Facility::whereIn('id', $leafIds)->get() as $f) {
            $n = $f;
            while ($n && $n->parent_id) $n = $n->parent;
            if ($n) $rootIds[$n->id] = true;
        }
        if (! $rootIds) return collect();
        return $q->whereIn('id', array_keys($rootIds))->orderBy('name')->get();
    }

    protected function visibleFacilityIds(): array
    {
        return $this->visibleFacilities()->pluck('id')->all();
    }

    public function save(): void
    {
        // 2026-10-05: UI mới dùng tab footer thay cho dropdown cơ sở trong draft row.
        //   Tab đang chọn → auto gán facility_id cho draft. "Tất cả" (null) + >1 facility → bắt chọn tab trước.
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

        BookingDraft::create(array_merge($this->normalize($this->draft), [
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]));

        // Reset input, giữ facility_id + nguồn cho lần nhập kế tiếp (giống Excel nhập liên tục).
        $keepFacility = $this->draft['facility_id'];
        $keepNguon    = $this->draft['nguon'];
        $this->draft = array_fill_keys(array_keys($this->draft), null);
        $this->draft['facility_id'] = $keepFacility;
        $this->draft['nguon']       = $keepNguon;

        $this->dispatch('draft-saved');
    }

    public function startEdit(int $id): void
    {
        $row = BookingDraft::find($id);
        if (! $row || ! $this->canTouch($row)) return;
        $this->editing[$id] = $row->only(array_keys($this->draft));
    }

    public function cancelEdit(int $id): void
    {
        unset($this->editing[$id]);
    }

    public function saveEdit(int $id): void
    {
        $row = BookingDraft::find($id);
        if (! $row || ! $this->canTouch($row)) return;
        $data = $this->normalize($this->editing[$id] ?? []);
        $data['updated_by'] = auth()->id();
        $row->update($data);
        unset($this->editing[$id]);
    }

    public function deleteRow(int $id): void
    {
        $row = BookingDraft::find($id);
        if (! $row || ! $this->canTouch($row)) return;
        $row->delete();
    }

    /** Đổi '' → null để cột DATE/TIME không bị lưu 0000-00-00 / 00:00:00. */
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
        // 2026-10-05: tab footer đổi → draft row gắn luôn vào tab đó (không bắt user chọn 2 lần).
        if ($this->filterFacilityId) $this->draft['facility_id'] = (int) $this->filterFacilityId;
        $this->resetPage();
    }
    public function updatedOnlyWarning(): void { $this->resetPage(); }

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

        $rows = $q->paginate(30);

        if ($this->onlyWarning) {
            // Filter sau paginate: chỉ giữ row có warning (đỏ/vàng). Đơn giản, ổn với 30 rows/page.
            $items = $rows->getCollection()->filter(fn ($r) => $r->statusColor() !== 'green')->values();
            $rows->setCollection($items);
        }

        return [
            'rows' => $rows,
            'facilities' => $facilities,
        ];
    }
}; ?>

{{-- 2026-10-05: redesign UI giống Google Sheets — full width, cell sát nhau, font Arial-ish,
     dropdown "Cơ sở" đổi thành tab ở footer. --}}
<div class="-mx-4 md:-mx-6 -my-6 md:-my-8 bg-[#f8f9fa] min-h-[calc(100vh-5rem)] flex flex-col" x-data style="font-family: Arial, Roboto, 'Helvetica Neue', sans-serif;">

    {{-- Header gọn — kiểu toolbar sheet --}}
    <div class="flex items-center justify-between gap-3 px-3 py-1.5 border-b border-gray-300 bg-white">
        <div class="flex items-center gap-3">
            <span class="text-sm font-semibold text-gray-800">⚡ Simple Booking</span>
            <span class="text-[11px] text-gray-500">Nháp lịch — Enter là chuyển ô, dòng xanh dương trên cùng để nhập mới.</span>
        </div>
        <div class="flex items-center gap-3 text-[12px]">
            <label class="flex items-center gap-1.5 text-gray-700">
                <input type="checkbox" wire:model.live="onlyWarning" class="w-3.5 h-3.5">
                Chỉ hiện dòng thiếu
            </label>
            <a href="{{ route('leads.index') }}" class="text-gray-600 hover:text-gray-900 underline">← Danh sách KH</a>
        </div>
    </div>

    {{-- Khối sheet (scrollable). Chiếm hết chiều cao còn lại, tabs cơ sở nằm dưới. --}}
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
                    <th class="{{ $thCls }} w-14 text-center">Số lọ</th>
                    <th class="{{ $thCls }}">Điều dưỡng</th>
                    <th class="{{ $thCls }}">Bác sĩ</th>
                    <th class="{{ $thCls }}">Khách tặng & ghi chú</th>
                    <th class="{{ $thCls }} w-24 text-center">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $tdCls = 'border border-gray-300 px-2 py-0.5 whitespace-nowrap';
                    $inpCls = 'w-full px-1 py-0 border-0 bg-transparent focus:outline-none focus:ring-1 focus:ring-blue-500 focus:bg-white';
                @endphp

                {{-- Row nhập mới — xanh dương nhạt --}}
                <tr class="bg-[#e8f0fe]" wire:key="draft-input">
                    <td class="{{ $tdCls }} text-center text-blue-600">+</td>
                    <td class="{{ $tdCls }} text-gray-400 italic">(nháp)</td>
                    <td class="{{ $tdCls }}"><input type="date" wire:model="draft.ngay_dat_lich" class="{{ $inpCls }}"></td>
                    <td class="{{ $tdCls }}"><input type="time" wire:model="draft.gio" class="{{ $inpCls }}"></td>
                    <td class="{{ $tdCls }}"><input type="text" wire:model="draft.nguon" placeholder="MKT/SR…" class="{{ $inpCls }}"></td>
                    <td class="{{ $tdCls }}"><input type="text" wire:model="draft.ho_ten" class="{{ $inpCls }}"></td>
                    <td class="{{ $tdCls }}"><input type="text" wire:model="draft.sdt" class="{{ $inpCls }}"></td>
                    <td class="{{ $tdCls }}"><input type="text" wire:model="draft.sale" class="{{ $inpCls }}"></td>
                    <td class="{{ $tdCls }}"><input type="text" wire:model="draft.lieu_phap" class="{{ $inpCls }}"></td>
                    <td class="{{ $tdCls }}"><input type="text" wire:model="draft.so_lo" class="{{ $inpCls }} text-center"></td>
                    <td class="{{ $tdCls }}"><input type="text" wire:model="draft.dieu_duong" class="{{ $inpCls }}"></td>
                    <td class="{{ $tdCls }}"><input type="text" wire:model="draft.bac_si" class="{{ $inpCls }}"></td>
                    <td class="{{ $tdCls }}"><input type="text" wire:model="draft.ghi_chu" class="{{ $inpCls }}"></td>
                    <td class="{{ $tdCls }} text-center">
                        <button wire:click="save" class="text-[11px] font-semibold text-white bg-blue-600 hover:bg-blue-700 px-2 py-0.5 rounded">+ Lưu</button>
                    </td>
                </tr>

                {{-- Rows đã lưu --}}
                @forelse ($rows as $r)
                    @php
                        $color = $r->statusColor();
                        // Row bg theo trạng thái — khớp screenshot sheet Google.
                        $rowBg = match ($color) {
                            'red'    => 'bg-[#f4c7c3]',   // đỏ nhạt — thiếu bắt buộc (ho_ten/sdt/ngay)
                            'yellow' => 'bg-[#fff2cc]',   // vàng — thiếu phụ (gio/sale/liệu pháp)
                            default  => 'bg-[#b7e1cd]',   // xanh — đủ
                        };
                        $reasons = $r->warningReasons();
                        $isEdit = isset($editing[$r->id]);
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
                            <td class="{{ $tdCls }}"><input type="text" wire:model="editing.{{ $r->id }}.nguon" class="{{ $inpCls }}"></td>
                            <td class="{{ $tdCls }}"><input type="text" wire:model="editing.{{ $r->id }}.ho_ten" class="{{ $inpCls }}"></td>
                            <td class="{{ $tdCls }}"><input type="text" wire:model="editing.{{ $r->id }}.sdt" class="{{ $inpCls }}"></td>
                            <td class="{{ $tdCls }}"><input type="text" wire:model="editing.{{ $r->id }}.sale" class="{{ $inpCls }}"></td>
                            <td class="{{ $tdCls }}"><input type="text" wire:model="editing.{{ $r->id }}.lieu_phap" class="{{ $inpCls }}"></td>
                            <td class="{{ $tdCls }}"><input type="text" wire:model="editing.{{ $r->id }}.so_lo" class="{{ $inpCls }} text-center"></td>
                            <td class="{{ $tdCls }}"><input type="text" wire:model="editing.{{ $r->id }}.dieu_duong" class="{{ $inpCls }}"></td>
                            <td class="{{ $tdCls }}"><input type="text" wire:model="editing.{{ $r->id }}.bac_si" class="{{ $inpCls }}"></td>
                            <td class="{{ $tdCls }}"><input type="text" wire:model="editing.{{ $r->id }}.ghi_chu" class="{{ $inpCls }}"></td>
                            <td class="{{ $tdCls }} text-center">
                                <button wire:click="saveEdit({{ $r->id }})" class="text-[11px] text-green-700 hover:underline">💾 Lưu</button>
                                <button wire:click="cancelEdit({{ $r->id }})" class="text-[11px] text-gray-600 hover:underline ml-1">✕</button>
                            </td>
                        @else
                            <td class="{{ $tdCls }} text-center">{{ $r->ngay_dat_lich?->format('d/m/Y') }}</td>
                            <td class="{{ $tdCls }} text-center">{{ $r->gio ? \Illuminate\Support\Str::of($r->gio)->before(':') . ':' . substr($r->gio, 3, 2) : '' }}</td>
                            <td class="{{ $tdCls }} text-center">{{ $r->nguon }}</td>
                            <td class="{{ $tdCls }}">{{ $r->ho_ten }}</td>
                            <td class="{{ $tdCls }}">{{ $r->sdt }}</td>
                            <td class="{{ $tdCls }}">{{ $r->sale }}</td>
                            <td class="{{ $tdCls }}">{{ $r->lieu_phap }}</td>
                            <td class="{{ $tdCls }} text-center">{{ $r->so_lo }}</td>
                            <td class="{{ $tdCls }}">{{ $r->dieu_duong }}</td>
                            <td class="{{ $tdCls }}">{{ $r->bac_si }}</td>
                            <td class="{{ $tdCls }}">{{ $r->ghi_chu }}</td>
                            <td class="{{ $tdCls }} text-center">
                                <button wire:click="startEdit({{ $r->id }})" class="text-[11px] text-blue-700 hover:underline">Sửa</button>
                                <button wire:click="deleteRow({{ $r->id }})" wire:confirm="Xoá row này?" class="text-[11px] text-red-700 hover:underline ml-1">Xoá</button>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="14" class="border border-gray-300 p-6 text-center text-gray-400 italic">Chưa có row nào — nhập ở dòng xanh dương phía trên.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="px-3 py-2 bg-white border-t border-gray-200">{{ $rows->links() }}</div>
    </div>

    {{-- Tab footer theo cơ sở — kiểu Google Sheets --}}
    <div class="flex items-center gap-0.5 border-t border-gray-300 bg-[#f8f9fa] px-2 py-1 overflow-x-auto">
        <span class="text-[11px] text-gray-500 mr-2 shrink-0">Cơ sở:</span>
        {{-- Tab "Tất cả" khi super admin có nhiều hơn 1 cơ sở --}}
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
                           {{ (int) $filterFacilityId === $f->id ? 'bg-white text-gray-900 font-semibold border-b-white -mb-px' : 'bg-[#e8eaed] text-gray-600 hover:bg-gray-200' }}">
                {{ $f->name }}
            </button>
        @endforeach
        <span class="ml-auto text-[11px] text-gray-500 shrink-0">
            {{ $rows->total() ?? $rows->count() }} dòng · 🔴 thiếu KH/SĐT/ngày · 🟡 thiếu giờ/sale/liệu pháp · 🟢 đủ
        </span>
    </div>
</div>
