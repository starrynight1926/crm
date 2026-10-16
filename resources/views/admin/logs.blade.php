@extends('layouts.app')

@section('title', 'Nhật ký hệ thống')

@php
    // Parse markdown line: "- `2026-09-01 10:23:45` **user#5 (Tên)** — action · detail · _IP 1.2.3.4_"
    $parse = function (string $line) {
        $out = ['ts' => '', 'who' => '', 'action' => '', 'detail' => '', 'ip' => ''];
        if (preg_match('/^- `([^`]+)`\s+\*\*([^*]+)\*\*\s+—\s+(.+?)(?:\s+·\s+_IP\s+([^_]+)_)?$/u', $line, $m)) {
            $out['ts']  = $m[1];
            $out['who'] = $m[2];
            $parts = explode(' · ', $m[3]);
            $out['action'] = $parts[0];
            $out['detail'] = $parts[1] ?? '';
            $out['ip'] = $m[4] ?? '';
        }
        return $out;
    };
@endphp

@section('content')
<div class="max-w-7xl mx-auto" x-data="{ q: '{{ $q }}' }">
    <div class="flex items-center gap-3 mb-1">
        <svg class="w-7 h-7 text-gold-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625z"/></svg>
        <h1 class="text-3xl font-bold">Nhật ký hệ thống</h1>
    </div>
    <p class="text-sm text-ink/60 mb-5">Log hoạt động (login/logout, tạo/xoá lead) từ <code>public/logs.md</code> — append-only, gated bằng cookie <code>scrm_authed</code>.</p>

    <form method="get" class="bg-white border border-gold-200 rounded-xl p-4 mb-4 flex gap-3 text-sm items-end">
        <div class="flex-1">
            <label class="text-xs text-ink/60">Tìm nhanh (user / action / IP)</label>
            <input type="text" name="q" value="{{ $q }}" placeholder="VD: đăng nhập, tạo lead, hn.sale03…"
                class="w-full border border-slate-300 rounded px-2 py-1.5">
        </div>
        <div>
            <label class="text-xs text-ink/60">Xem N dòng cuối</label>
            <select name="tail" class="border border-slate-300 rounded px-2 py-1.5">
                @foreach ([200, 500, 1000, 2000, 5000] as $n)
                    <option value="{{ $n }}" @selected(($tail ?? 500) == $n)>{{ $n }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="bg-gold-600 hover:bg-gold-700 text-white text-xs font-semibold px-4 py-1.5 rounded">Lọc</button>
    </form>

    <div class="text-xs text-ink/50 mb-2">
        Hiển thị {{ $lines->count() }} / {{ number_format($total) }} dòng (mới nhất trên đầu).
    </div>

    <div class="bg-white border border-gold-200 rounded-xl overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gold-50 text-ink/70 text-xs uppercase tracking-wider">
                <tr>
                    <th class="text-left px-3 py-2 w-40">Thời điểm</th>
                    <th class="text-left px-3 py-2">User</th>
                    <th class="text-left px-3 py-2">Hành động</th>
                    <th class="text-left px-3 py-2">Chi tiết</th>
                    <th class="text-left px-3 py-2 w-32">IP</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gold-100 font-mono text-[12px]">
                @forelse ($lines as $line)
                    @php $e = $parse($line); @endphp
                    <tr class="hover:bg-gold-50/40">
                        <td class="px-3 py-1.5 text-ink/60">{{ $e['ts'] }}</td>
                        <td class="px-3 py-1.5 text-ink">{{ $e['who'] }}</td>
                        <td class="px-3 py-1.5 font-sans">
                            @php $act = $e['action']; $cls = str_contains($act, 'xóa') || str_contains($act, 'đăng xuất') ? 'bg-rose-100 text-rose-800' : (str_contains($act, 'tạo') ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-800'); @endphp
                            <span class="text-[11px] font-semibold px-1.5 py-0.5 rounded {{ $cls }}">{{ $act }}</span>
                        </td>
                        <td class="px-3 py-1.5 text-ink/70">{{ $e['detail'] }}</td>
                        <td class="px-3 py-1.5 text-ink/50">{{ $e['ip'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center px-3 py-6 text-ink/50 italic font-sans">Không có dòng nào khớp filter.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
