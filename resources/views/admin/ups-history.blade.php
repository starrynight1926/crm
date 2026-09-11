@extends('layouts.app')

@section('title', 'Lịch sử UPS')

@section('content')
<div class="max-w-7xl mx-auto">
    <div class="flex items-center gap-3 mb-1">
        <svg class="w-7 h-7 text-gold-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
        <h1 class="text-3xl font-bold">Lịch sử UPS</h1>
    </div>
    <p class="text-sm text-ink/60 mb-5">DailyAttendance — check-in / bucket / MKT list. Import/export CSV để backup, restore hoặc chỉnh bulk.</p>

    @if (session('status'))
        <div class="mb-4 bg-emerald-50 border border-emerald-200 rounded-lg px-4 py-2.5 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 bg-rose-50 border border-rose-200 rounded-lg px-4 py-2.5 text-sm text-rose-800">{{ session('error') }}</div>
    @endif

    {{-- Filter --}}
    <form method="get" class="bg-white border border-gold-200 rounded-xl p-4 mb-4 grid grid-cols-1 md:grid-cols-6 gap-3 text-sm">
        <div>
            <label class="text-xs text-ink/60">Từ ngày</label>
            <input type="date" name="from" value="{{ request('from') }}" class="w-full border border-slate-300 rounded px-2 py-1.5">
        </div>
        <div>
            <label class="text-xs text-ink/60">Đến ngày</label>
            <input type="date" name="to" value="{{ request('to') }}" class="w-full border border-slate-300 rounded px-2 py-1.5">
        </div>
        <div>
            <label class="text-xs text-ink/60">Cơ sở</label>
            <select name="facility_pool_unit_id" class="w-full border border-slate-300 rounded px-2 py-1.5">
                <option value="">— Tất cả —</option>
                @foreach ($facilities as $f)
                    <option value="{{ $f->id }}" @selected(request('facility_pool_unit_id') == $f->id)>{{ $f->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs text-ink/60">Bucket</label>
            <select name="list_bucket" class="w-full border border-slate-300 rounded px-2 py-1.5">
                <option value="">— Tất cả —</option>
                @foreach (['A', 'B', 'C', 'OFF'] as $b)
                    <option value="{{ $b }}" @selected(request('list_bucket') === $b)>{{ $b }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end gap-2">
            <label class="inline-flex items-center gap-1 text-xs">
                <input type="checkbox" name="is_mkt" value="1" @checked(request('is_mkt'))> MKT
            </label>
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="bg-gold-600 hover:bg-gold-700 text-white text-xs font-semibold px-3 py-1.5 rounded">Lọc</button>
            <a href="{{ route('admin.ups-history.export', request()->query()) }}"
               class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold px-3 py-1.5 rounded">⬇ Export CSV</a>
        </div>
    </form>

    {{-- Import --}}
    <form method="post" action="{{ route('admin.ups-history.import') }}" enctype="multipart/form-data"
          class="bg-white border border-gold-200 rounded-xl p-4 mb-4 flex items-center gap-3 text-sm">
        @csrf
        <div class="font-semibold text-ink">Import CSV:</div>
        <input type="file" name="file" accept=".csv,.txt" required class="text-xs">
        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold px-3 py-1.5 rounded">⬆ Upload</button>
        <span class="text-xs text-ink/50 ml-auto">Cột bắt buộc: <code>work_date</code>, <code>user_id</code>, <code>facility_pool_unit_id</code>. Idempotent theo (user_id, work_date).</span>
    </form>

    {{-- Table --}}
    <div class="bg-white border border-gold-200 rounded-xl overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gold-50 text-ink/70 text-xs uppercase tracking-wider">
                <tr>
                    <th class="text-left px-3 py-2">ID</th>
                    <th class="text-left px-3 py-2">Ngày</th>
                    <th class="text-left px-3 py-2">Sale</th>
                    <th class="text-left px-3 py-2">Cơ sở</th>
                    <th class="text-left px-3 py-2">Bucket</th>
                    <th class="text-center px-3 py-2">MKT</th>
                    <th class="text-center px-3 py-2">OFF</th>
                    <th class="text-left px-3 py-2">Check-in</th>
                    <th class="text-center px-3 py-2">Dừng nhận</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gold-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-gold-50/40">
                        <td class="px-3 py-2 text-ink/50">{{ $r->id }}</td>
                        <td class="px-3 py-2">{{ $r->work_date?->format('d/m/Y') }}</td>
                        <td class="px-3 py-2">
                            <div class="font-medium">{{ $r->user?->name ?? '—' }}</div>
                            <div class="text-xs text-ink/50">{{ $r->user?->email }}</div>
                        </td>
                        <td class="px-3 py-2">{{ $r->facility?->name ?? '—' }}</td>
                        <td class="px-3 py-2">
                            @if ($r->list_bucket)
                                <span class="text-xs font-bold px-1.5 py-0.5 rounded {{ ['A'=>'bg-blue-100 text-blue-800','B'=>'bg-teal-100 text-teal-800','C'=>'bg-slate-200 text-slate-800','OFF'=>'bg-rose-100 text-rose-800'][$r->list_bucket] ?? 'bg-gold-100 text-gold-800' }}">{{ $r->list_bucket }}</span>
                            @else
                                <span class="text-xs text-ink/40">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-center">{{ $r->is_mkt ? '✓' : '' }}</td>
                        <td class="px-3 py-2 text-center">{{ $r->is_off ? '✓' : '' }}</td>
                        <td class="px-3 py-2 text-xs">{{ $r->checkin_at?->setTimezone('Asia/Ho_Chi_Minh')?->format('H:i d/m') ?? '—' }}</td>
                        <td class="px-3 py-2 text-center">{{ $r->dung_nhan_lead ? '⏸' : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center px-3 py-6 text-ink/50 italic">Không có dữ liệu khớp filter.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3">{{ $rows->links() }}</div>
</div>
@endsection
