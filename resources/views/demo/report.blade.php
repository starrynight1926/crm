@extends('demo.layout')
@section('title', 'Báo cáo — Demo Staging')

@section('content')
<h1 class="text-lg font-bold text-gold-700 mb-4">Báo cáo dữ liệu staging</h1>

{{-- Thẻ tổng --}}
<div class="grid grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-gold-400/20 p-4">
        <div class="text-xs text-ink/50">Tổng dòng</div>
        <div class="text-2xl font-bold text-ink">{{ number_format($total) }}</div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-green-200 p-4">
        <div class="text-xs text-ink/50">Hợp lệ</div>
        <div class="text-2xl font-bold text-green-700">{{ number_format($valid) }}</div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-red-200 p-4">
        <div class="text-xs text-ink/50">Lỗi</div>
        <div class="text-2xl font-bold text-red-600">{{ number_format($invalid) }}</div>
    </div>
</div>

@php
    $bar = function ($c, $max) { return $max > 0 ? max(3, round($c / $max * 100)) : 0; };
@endphp

<div class="grid md:grid-cols-2 gap-6">
    {{-- Theo nguồn (giá trị cột Nguồn) --}}
    <div class="bg-white rounded-xl shadow-sm border border-gold-400/20 p-5">
        <h2 class="text-sm font-bold text-ink/70 mb-3">Theo Nguồn (giá trị)</h2>
        @php $maxN = $byNguon->max('c') ?? 0; @endphp
        @forelse ($byNguon as $r)
            <div class="mb-2.5">
                <div class="flex justify-between text-sm mb-0.5">
                    <span>{{ $r->k }}</span>
                    <span class="text-ink/50">{{ $r->c }} <span class="text-green-600">({{ $r->ok }}✓</span> <span class="text-red-500">{{ $r->bad }}✗)</span></span>
                </div>
                <div class="h-2 rounded bg-gold-400/10 overflow-hidden"><div class="h-full bg-gold-500" style="width: {{ $bar($r->c, $maxN) }}%"></div></div>
            </div>
        @empty
            <p class="text-sm text-ink/40 py-4">Chưa có dữ liệu.</p>
        @endforelse
    </div>

    {{-- Theo mẫu nguồn --}}
    <div class="bg-white rounded-xl shadow-sm border border-gold-400/20 p-5">
        <h2 class="text-sm font-bold text-ink/70 mb-3">Theo Mẫu nguồn</h2>
        @php $maxS = $bySource->max('c') ?? 0; @endphp
        @forelse ($bySource as $r)
            <div class="mb-2.5">
                <div class="flex justify-between text-sm mb-0.5">
                    <span class="truncate pr-2">{{ $r->k }}</span>
                    <span class="text-ink/50 whitespace-nowrap">{{ $r->c }} <span class="text-green-600">({{ $r->ok }}✓</span> <span class="text-red-500">{{ $r->bad }}✗)</span></span>
                </div>
                <div class="h-2 rounded bg-gold-400/10 overflow-hidden"><div class="h-full bg-gold-500" style="width: {{ $bar($r->c, $maxS) }}%"></div></div>
            </div>
        @empty
            <p class="text-sm text-ink/40 py-4">Chưa có dữ liệu.</p>
        @endforelse
    </div>

    {{-- Lý do lỗi --}}
    <div class="bg-white rounded-xl shadow-sm border border-gold-400/20 p-5">
        <h2 class="text-sm font-bold text-ink/70 mb-3">Lý do lỗi</h2>
        @forelse ($errorReasons as $r)
            <div class="flex justify-between text-sm py-1 border-b border-gold-400/10">
                <span class="text-red-600">{{ $r->k }}</span>
                <span class="text-ink/50">{{ $r->c }}</span>
            </div>
        @empty
            <p class="text-sm text-ink/40 py-4">Không có dòng lỗi. 🎉</p>
        @endforelse
    </div>

    {{-- Theo ngày --}}
    <div class="bg-white rounded-xl shadow-sm border border-gold-400/20 p-5">
        <h2 class="text-sm font-bold text-ink/70 mb-3">Theo Ngày (30 gần nhất)</h2>
        @php $maxD = $byDay->max('c') ?? 0; @endphp
        @forelse ($byDay as $r)
            <div class="mb-2">
                <div class="flex justify-between text-sm mb-0.5">
                    <span>{{ \Carbon\Carbon::parse($r->d)->format('d/m/Y') }}</span>
                    <span class="text-ink/50">{{ $r->c }}</span>
                </div>
                <div class="h-2 rounded bg-gold-400/10 overflow-hidden"><div class="h-full bg-gold-500" style="width: {{ $bar($r->c, $maxD) }}%"></div></div>
            </div>
        @empty
            <p class="text-sm text-ink/40 py-4">Chưa có dòng nào parse được ngày.</p>
        @endforelse
    </div>
</div>
@endsection
