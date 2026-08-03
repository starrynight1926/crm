@extends('layouts.app')

@section('title', 'Thêm mới lead')

@section('content')
    @php $upsBlocked = app(\App\Services\Ups\UpsGate::class)->isBlockedFor(auth()->user()); @endphp
    <div class="max-w-7xl mx-auto px-4 pt-4">
        @if (auth()->user()->hasPermission('ups.view'))
            <div class="flex justify-end mb-2">
                <a href="{{ route('ups.today') }}" class="text-sm font-semibold bg-white border border-gold-300 text-gold-700 px-4 py-2 rounded hover:bg-gold-50">Check UPS System</a>
            </div>
        @endif
        @if ($upsBlocked)
            <div class="bg-red-50 border border-red-300 text-red-800 text-sm px-4 py-3 rounded mb-3">
                <strong>UPS chưa được chốt</strong> — liên hệ bộ phận BO để xác nhận. Việc chia số hôm nay tạm khóa cho tới khi BO chốt UPS.
            </div>
        @endif
    </div>
    <livewire:leads.lead-form />
@endsection
