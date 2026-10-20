@extends('layouts.app')
@section('title', 'Danh mục dịch vụ (mirror sbooking)')

@section('content')
@php
    $nhomLabel = ['tu_van' => 'Tư vấn', 'kham_ls' => 'Khám lâm sàng', 'khac' => 'Dịch vụ'];
    $nhomBadge = [
        'tu_van'  => 'bg-amber-100 text-amber-800 ring-1 ring-amber-200',
        'kham_ls' => 'bg-blue-100 text-blue-800 ring-1 ring-blue-200',
        'khac'    => 'bg-emerald-100 text-emerald-800 ring-1 ring-emerald-200',
    ];
@endphp

<div class="max-w-[1400px] mx-auto px-4 py-6">
<div class="flex items-center gap-2 text-sm text-ink/50 mb-4">
<a href="{{ route('settings.index') }}" class="hover:text-gold-700">Thiết lập</a>
<span>›</span>
<span class="text-ink font-semibold">Danh mục dịch vụ (mirror sbooking)</span>
</div>

<div class="flex items-start gap-3 mb-4">
<div class="w-12 h-12 rounded-xl bg-gold-100 text-gold-700 flex items-center justify-center text-2xl">📋</div>
<div>
<h1 class="text-2xl font-bold">Danh mục dịch vụ (mirror sbooking)</h1>
<p class="text-sm text-ink/60">Đọc từ 4 bảng mirror <code class="text-xs bg-ink/10 px-1 rounded">sb_services</code>, <code class="text-xs bg-ink/10 px-1 rounded">sb_rooms</code>, <code class="text-xs bg-ink/10 px-1 rounded">sb_bac_si</code>, <code class="text-xs bg-ink/10 px-1 rounded">sb_dich_vu_phong</code>. Nếu cột nào trống → sync chưa chạy đầy đủ.</p>
</div>
</div>

{{-- Meta panel --}}
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
<div class="bg-white border border-ink/10 rounded-lg p-3">
<div class="text-xs uppercase text-ink/50">Dịch vụ mirror</div>
<div class="text-xl font-bold">{{ number_format($meta['total']) }}</div>
</div>
<div class="bg-white border border-ink/10 rounded-lg p-3">
<div class="text-xs uppercase text-ink/50">Đang hoạt động</div>
<div class="text-xl font-bold">{{ number_format($meta['active']) }}</div>
</div>
<div class="bg-white border border-ink/10 rounded-lg p-3">
<div class="text-xs uppercase text-ink/50">Pivot DV↔Phòng</div>
<div class="text-xl font-bold {{ $meta['has_pivot_dv_phong'] ? 'text-emerald-700' : 'text-red-600' }}">{{ $meta['has_pivot_dv_phong'] ? '✓ có' : '✗ trống' }}</div>
</div>
<div class="bg-white border border-ink/10 rounded-lg p-3">
<div class="text-xs uppercase text-ink/50">Sync gần nhất</div>
<div class="text-sm font-mono">{{ $meta['last_sync']?->format('d/m H:i') ?? '—' }}</div>
</div>
</div>

@if (! $meta['has_pivot_dv_phong'])
<div class="mb-4 p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm">
⚠ Bảng <code class="bg-amber-100 px-1 rounded">sb_dich_vu_phong</code> đang rỗng — chạy sync để cột "Phòng thực hiện" có data:
<code class="block mt-1 bg-white px-2 py-1 rounded text-xs">php artisan sb:sync-dich-vu-phong</code>
</div>
@endif

<div class="mb-3 p-3 rounded-lg bg-blue-50 border border-blue-200 text-blue-800 text-sm">
ℹ Cột "Nhân sự thực hiện" luôn "—" vì SCRM <strong>chưa có bảng mirror</strong> <code class="bg-blue-100 px-1 rounded">sb_dich_vu_bac_si</code> (pivot DV↔BS bên sbooking đã có). Nếu cần → mở nhánh mirror thêm.
</div>

<div class="bg-white border border-ink/10 rounded-xl overflow-hidden">
<div class="overflow-x-auto">
<table class="w-full min-w-[1100px] text-sm">
<thead>
<tr class="text-left text-xs uppercase text-ink/50 bg-ink/5 border-b border-ink/10">
<th class="px-3 py-3">ID</th>
<th class="px-3 py-3">Cơ sở</th>
<th class="px-3 py-3">Tên</th>
<th class="px-3 py-3 whitespace-nowrap">Thời gian</th>
<th class="px-3 py-3">Nhóm</th>
<th class="px-3 py-3 text-center whitespace-nowrap">Là dịch vụ?</th>
<th class="px-3 py-3">Phòng thực hiện</th>
<th class="px-3 py-3">Nhân sự thực hiện</th>
</tr>
</thead>
<tbody class="divide-y divide-ink/5">
@forelse ($rows as $d)
<tr class="hover:bg-ink/5 {{ ! $d->active ? 'opacity-50' : '' }}">
<td class="px-3 py-2.5 font-mono">{{ $d->id }}</td>
<td class="px-3 py-2.5 font-semibold uppercase">{{ $d->co_so }}</td>
<td class="px-3 py-2.5 font-semibold">{{ $d->ten }}</td>
<td class="px-3 py-2.5 whitespace-nowrap">{{ $d->thoi_gian }}'</td>
<td class="px-3 py-2.5">
<span class="px-2 py-0.5 rounded text-xs {{ $nhomBadge[$d->nhom] ?? 'bg-slate-100 text-slate-700' }}">{{ $nhomLabel[$d->nhom] ?? $d->nhom }}</span>
</td>
<td class="px-3 py-2.5 text-center">{{ $d->la_dv ? '1' : '' }}</td>
<td class="px-3 py-2.5 text-ink/60">{{ implode(', ', $d->phongs) ?: '—' }}</td>
<td class="px-3 py-2.5 text-ink/40 italic">— (chưa có mirror)</td>
</tr>
@empty
<tr><td colspan="8" class="px-4 py-10 text-center text-ink/50">Chưa có dịch vụ mirror. Chạy: <code class="bg-ink/10 px-1 rounded">php artisan sb:sync-catalog</code></td></tr>
@endforelse
</tbody>
</table>
</div>
</div>
</div>
@endsection
