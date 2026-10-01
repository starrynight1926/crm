@extends('layouts.app')

@section('title', 'Lịch sử hoạt động')

@section('content')
<div class="max-w-4xl mx-auto p-4">
    <div class="flex items-baseline justify-between mb-4">
        <div>
            <h1 class="text-xl font-semibold">Lịch sử hoạt động</h1>
            <div class="text-sm text-ink/60">
                @if ($isSelf)
                    Của bạn — {{ $target->name }}
                @else
                    Của: <span class="font-medium">{{ $target->name }}</span>
                    <a href="{{ route('me.activity') }}" class="text-blue-600 hover:underline ml-2">← xem của tôi</a>
                @endif
            </div>
        </div>
    </div>

    <form method="GET" class="flex flex-wrap items-end gap-2 mb-4 p-3 bg-gold-50/40 border border-gold-100 rounded">
        @if ($canPickUser)
            <div>
                <label class="block text-xs text-ink/60">User ID</label>
                <input type="number" name="user_id" value="{{ $target->id }}" class="border rounded px-2 py-1 w-28" />
            </div>
        @endif
        <div>
            <label class="block text-xs text-ink/60">Từ ngày</label>
            <input type="date" name="from" value="{{ $from }}" class="border rounded px-2 py-1" />
        </div>
        <div>
            <label class="block text-xs text-ink/60">Đến ngày</label>
            <input type="date" name="to" value="{{ $to }}" class="border rounded px-2 py-1" />
        </div>
        <button class="px-3 py-1.5 rounded bg-ink text-white text-sm">Lọc</button>
        <a href="{{ route('me.activity') }}" class="px-3 py-1.5 rounded border text-sm">Xoá lọc</a>
    </form>

    @if ($groups->isEmpty())
        <div class="p-8 text-center text-ink/50 border rounded">Chưa có hoạt động nào.</div>
    @else
        @foreach ($groups as $date => $items)
            @php
                $d = \Illuminate\Support\Carbon::parse($date);
                $today = now()->toDateString();
                $yest = now()->subDay()->toDateString();
                $head = $date === $today ? 'Hôm nay' : ($date === $yest ? 'Hôm qua' : $d->format('d/m/Y'));
            @endphp
            <div class="mb-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink/50 mb-1">{{ $head }} · {{ $d->format('l') }}</div>
                <ul class="divide-y border rounded bg-white">
                    @foreach ($items as $it)
                        <li class="px-3 py-2 flex gap-3 text-sm">
                            <span class="text-ink/50 tabular-nums w-12 shrink-0">{{ $it->at->format('H:i') }}</span>
                            <span class="flex-1">
                                @if ($it->lead_id)
                                    <a href="{{ url('/leads/'.$it->lead_id) }}" class="hover:underline">{{ $it->text }}</a>
                                @else
                                    {{ $it->text }}
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach

        <div class="mt-4">
            {{ $paginator->links() }}
        </div>
    @endif
</div>
@endsection
