@extends('layouts.app')
@section('content')
<div class="max-w-6xl mx-auto">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-ink">🚀 Quick Login (dev)</h1>
            <p class="text-sm text-ink/60 mt-1">Click "Giả lập" để login nhanh vào user đó, không cần password. Chỉ hiện ở APP_ENV=local.</p>
        </div>
        @if (session('impersonate_original_id'))
            <form method="POST" action="{{ route('impersonate.leave') }}">
                @csrf
                <button class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700 text-sm font-semibold">← Về Admin gốc</button>
            </form>
        @endif
    </div>

    @if (session('status'))
        <div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-800 rounded text-sm">{{ session('status') }}</div>
    @endif

    @foreach ($groups as $branch => $users)
        <div class="mb-6">
            <h2 class="text-lg font-semibold text-ink mb-3 flex items-center gap-2">
                <span class="w-8 h-8 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center text-sm font-bold">{{ $branch }}</span>
                <span>Chi nhánh {{ $branch }}</span>
                <span class="text-xs text-ink/50">({{ count($users) }} user)</span>
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                @foreach ($users as $u)
                    <div class="border border-gold-100 rounded-lg p-3 flex items-center justify-between gap-2 hover:bg-gold-50/50">
                        <div class="min-w-0 flex-1">
                            <div class="font-semibold text-sm text-ink truncate">{{ $u['name'] }}</div>
                            <div class="text-xs text-ink/60 truncate">{{ $u['email'] }}</div>
                            <div class="text-xs text-gold-700 mt-0.5">{{ $u['role'] }} @if($u['org']) · {{ $u['org'] }} @endif</div>
                        </div>
                        @if ($u['id'] !== auth()->id())
                            <form method="POST" action="{{ route('impersonate.start', $u['id']) }}">
                                @csrf
                                <button class="px-3 py-1.5 bg-gold-600 text-white rounded text-xs font-semibold hover:bg-gold-700 whitespace-nowrap">
                                    Giả lập →
                                </button>
                            </form>
                        @else
                            <span class="px-3 py-1.5 text-xs text-ink/40">(đang là bạn)</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
@endsection
