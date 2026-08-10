@extends('layouts.app')

@section('title', 'Quản lý Kho Lead tập trung')

@section('content')
    @php $upsBlocked = app(\App\Services\Ups\UpsGate::class)->isBlockedFor(auth()->user()); @endphp

    <div class="pt-4">
        <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
            <h1 class="text-lg font-bold">Kho Lead — Chia số</h1>
            @if (auth()->user()->hasPermission('ups.view'))
                <a href="{{ route('ups.today') }}" class="text-sm font-semibold bg-white border border-gold-300 text-gold-700 px-4 py-2 rounded hover:bg-gold-50">Check UPS System</a>
            @endif
        </div>

        @if ($upsBlocked)
            <div class="bg-red-50 border border-red-300 text-red-800 text-sm px-4 py-3 rounded mb-4">
                <strong>UPS chưa được chốt</strong> — liên hệ bộ phận BO để xác nhận. Việc chia số hôm nay tạm khóa cho tới khi BO chốt UPS ở ít nhất một cơ sở trong chi nhánh.
            </div>
        @endif
    </div>

    <div @if($upsBlocked) class="pointer-events-none opacity-50" @endif>
        <livewire:distribution.lead-pools />
    </div>
@endsection
