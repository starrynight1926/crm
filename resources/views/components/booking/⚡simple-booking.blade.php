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
        abort_unless(auth()->check(), 403);
        $facilities = $this->visibleFacilities();
        // Mặc định facility_id draft = facility đầu tiên user thấy (1 cơ sở → chọn luôn).
        if ($facilities->count() >= 1) {
            $this->draft['facility_id'] = $facilities->first()->id;
        }
    }

    /** Facilities user được thấy. Super admin: all; user thường: các facility_id đã từng có lead visible. */
    public function visibleFacilities()
    {
        if (AdminScope::isSuperAdmin()) {
            return Facility::orderBy('parent_id')->orderBy('name')->get();
        }
        $u = auth()->user();
        $ids = Lead::visibleTo($u)->whereNotNull('facility_id')->distinct()->pluck('facility_id')->all();
        if (! $ids) return collect();
        return Facility::whereIn('id', $ids)->orderBy('parent_id')->orderBy('name')->get();
    }

    protected function visibleFacilityIds(): array
    {
        return $this->visibleFacilities()->pluck('id')->all();
    }

    public function save(): void
    {
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

    public function updatedFilterFacilityId(): void { $this->resetPage(); }
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

<div class="p-4 max-w-full" x-data>
    <div class="flex items-center justify-between mb-3">
        <div>
            <h1 class="text-xl font-semibold">⚡ Nháp lịch đặt (Simple Booking)</h1>
            <p class="text-sm text-gray-500">
                Sheet nhập nhanh — ai trong scope cơ sở cũng xem/sửa. Row
                <span class="text-red-600 font-medium">🔴 đỏ</span> = thiếu bắt buộc,
                <span class="text-yellow-600 font-medium">🟡 vàng</span> = thiếu phụ,
                <span class="text-green-600 font-medium">🟢 xanh</span> = đủ.
            </p>
        </div>
        <a href="{{ route('leads.index') }}" class="text-sm text-gray-600 hover:text-blue-600">← Về danh sách khách hàng</a>
    </div>

    <div class="flex gap-3 items-center mb-3 text-sm">
        @if ($facilities->count() > 1)
            <label class="flex items-center gap-2">
                <span>Cơ sở:</span>
                <select wire:model.live="filterFacilityId" class="border rounded px-2 py-1">
                    <option value="">— Tất cả —</option>
                    @foreach ($facilities as $f)
                        <option value="{{ $f->id }}">{{ $f->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <label class="flex items-center gap-2">
            <input type="checkbox" wire:model.live="onlyWarning">
            <span>Chỉ hiện row có ⛔</span>
        </label>
    </div>

    <div class="overflow-x-auto border rounded">
        <table class="min-w-[1400px] w-full text-sm">
            <thead class="bg-gray-100 text-left">
                <tr>
                    <th class="p-2 w-12">⛔</th>
                    <th class="p-2">Dấu thời gian</th>
                    <th class="p-2">Cơ sở</th>
                    <th class="p-2">Ngày đặt</th>
                    <th class="p-2">Giờ</th>
                    <th class="p-2">Nguồn</th>
                    <th class="p-2">Họ tên KH</th>
                    <th class="p-2">SĐT</th>
                    <th class="p-2">Sale</th>
                    <th class="p-2">Liệu pháp</th>
                    <th class="p-2">Số lô</th>
                    <th class="p-2">Điều dưỡng</th>
                    <th class="p-2">Bác sĩ</th>
                    <th class="p-2">Ghi chú</th>
                    <th class="p-2 w-32">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                {{-- Row nhập mới --}}
                <tr class="bg-blue-50" wire:key="draft-input">
                    <td class="p-1 text-center">✏️</td>
                    <td class="p-1 text-gray-400 italic">nháp</td>
                    <td class="p-1">
                        <select wire:model="draft.facility_id" class="border rounded px-1 py-0.5 w-full">
                            <option value="">—</option>
                            @foreach ($facilities as $f)
                                <option value="{{ $f->id }}">{{ $f->name }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td class="p-1"><input type="date" wire:model="draft.ngay_dat_lich" class="border rounded px-1 py-0.5 w-32"></td>
                    <td class="p-1"><input type="time" wire:model="draft.gio" class="border rounded px-1 py-0.5 w-24"></td>
                    <td class="p-1"><input type="text" wire:model="draft.nguon" placeholder="MKT/SR/Cali…" class="border rounded px-1 py-0.5 w-24"></td>
                    <td class="p-1"><input type="text" wire:model="draft.ho_ten" class="border rounded px-1 py-0.5 w-40"></td>
                    <td class="p-1"><input type="text" wire:model="draft.sdt" class="border rounded px-1 py-0.5 w-32"></td>
                    <td class="p-1"><input type="text" wire:model="draft.sale" class="border rounded px-1 py-0.5 w-32"></td>
                    <td class="p-1"><input type="text" wire:model="draft.lieu_phap" class="border rounded px-1 py-0.5 w-40"></td>
                    <td class="p-1"><input type="text" wire:model="draft.so_lo" class="border rounded px-1 py-0.5 w-16"></td>
                    <td class="p-1"><input type="text" wire:model="draft.dieu_duong" class="border rounded px-1 py-0.5 w-32"></td>
                    <td class="p-1"><input type="text" wire:model="draft.bac_si" class="border rounded px-1 py-0.5 w-32"></td>
                    <td class="p-1"><input type="text" wire:model="draft.ghi_chu" class="border rounded px-1 py-0.5 w-40"></td>
                    <td class="p-1">
                        <button wire:click="save" class="bg-blue-600 text-white text-xs px-2 py-1 rounded hover:bg-blue-700">+ Lưu</button>
                    </td>
                </tr>

                {{-- Rows đã lưu --}}
                @forelse ($rows as $r)
                    @php
                        $color = $r->statusColor();
                        $badge = ['green' => '🟢', 'yellow' => '🟡', 'red' => '🔴'][$color];
                        $reasons = $r->warningReasons();
                        $isEdit = isset($editing[$r->id]);
                    @endphp
                    <tr wire:key="row-{{ $r->id }}" class="border-t hover:bg-gray-50">
                        <td class="p-1 text-center" title="{{ implode(' · ', $reasons) ?: 'Đủ điều kiện' }}">{{ $badge }}</td>
                        <td class="p-1 text-xs text-gray-500 whitespace-nowrap">
                            {{ $r->created_at?->format('d/m/Y H:i') }}<br>
                            <span class="text-gray-400">{{ $r->creator?->name }}</span>
                        </td>
                        @if ($isEdit)
                            <td class="p-1"><select wire:model="editing.{{ $r->id }}.facility_id" class="border rounded px-1 py-0.5 w-full">
                                @foreach ($facilities as $f)<option value="{{ $f->id }}">{{ $f->name }}</option>@endforeach
                            </select></td>
                            <td class="p-1"><input type="date" wire:model="editing.{{ $r->id }}.ngay_dat_lich" class="border rounded px-1 py-0.5 w-32"></td>
                            <td class="p-1"><input type="time" wire:model="editing.{{ $r->id }}.gio" class="border rounded px-1 py-0.5 w-24"></td>
                            <td class="p-1"><input type="text" wire:model="editing.{{ $r->id }}.nguon" class="border rounded px-1 py-0.5 w-24"></td>
                            <td class="p-1"><input type="text" wire:model="editing.{{ $r->id }}.ho_ten" class="border rounded px-1 py-0.5 w-40"></td>
                            <td class="p-1"><input type="text" wire:model="editing.{{ $r->id }}.sdt" class="border rounded px-1 py-0.5 w-32"></td>
                            <td class="p-1"><input type="text" wire:model="editing.{{ $r->id }}.sale" class="border rounded px-1 py-0.5 w-32"></td>
                            <td class="p-1"><input type="text" wire:model="editing.{{ $r->id }}.lieu_phap" class="border rounded px-1 py-0.5 w-40"></td>
                            <td class="p-1"><input type="text" wire:model="editing.{{ $r->id }}.so_lo" class="border rounded px-1 py-0.5 w-16"></td>
                            <td class="p-1"><input type="text" wire:model="editing.{{ $r->id }}.dieu_duong" class="border rounded px-1 py-0.5 w-32"></td>
                            <td class="p-1"><input type="text" wire:model="editing.{{ $r->id }}.bac_si" class="border rounded px-1 py-0.5 w-32"></td>
                            <td class="p-1"><input type="text" wire:model="editing.{{ $r->id }}.ghi_chu" class="border rounded px-1 py-0.5 w-40"></td>
                            <td class="p-1 flex gap-1">
                                <button wire:click="saveEdit({{ $r->id }})" class="bg-green-600 text-white text-xs px-2 py-1 rounded">💾</button>
                                <button wire:click="cancelEdit({{ $r->id }})" class="bg-gray-300 text-xs px-2 py-1 rounded">✕</button>
                            </td>
                        @else
                            <td class="p-1">{{ $r->facility?->name }}</td>
                            <td class="p-1 whitespace-nowrap">{{ $r->ngay_dat_lich?->format('d/m/Y') }}</td>
                            <td class="p-1">{{ $r->gio }}</td>
                            <td class="p-1">{{ $r->nguon }}</td>
                            <td class="p-1">{{ $r->ho_ten }}</td>
                            <td class="p-1">{{ $r->sdt }}</td>
                            <td class="p-1">{{ $r->sale }}</td>
                            <td class="p-1">{{ $r->lieu_phap }}</td>
                            <td class="p-1">{{ $r->so_lo }}</td>
                            <td class="p-1">{{ $r->dieu_duong }}</td>
                            <td class="p-1">{{ $r->bac_si }}</td>
                            <td class="p-1">{{ $r->ghi_chu }}</td>
                            <td class="p-1 flex gap-1">
                                <button wire:click="startEdit({{ $r->id }})" class="text-xs text-blue-600 hover:underline">Sửa</button>
                                <button wire:click="deleteRow({{ $r->id }})"
                                        wire:confirm="Xoá row này?"
                                        class="text-xs text-red-600 hover:underline">Xoá</button>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="15" class="p-4 text-center text-gray-400">Chưa có row nào. Nhập ở dòng trên.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3">
        {{ $rows->links() }}
    </div>
</div>
