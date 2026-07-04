@extends('demo.layout')
@section('title', 'Danh sách — Demo Staging')

@section('content')
@if ($f = session('flash'))
    <div class="mb-4 rounded-lg bg-gold-400/10 border border-gold-400/30 px-4 py-3 text-sm">
        Đã import <b>{{ $f['source'] }}</b>: tổng <b>{{ $f['total'] }}</b> dòng —
        <span class="text-green-700">{{ $f['ok'] }} hợp lệ</span>,
        <span class="text-red-600">{{ $f['bad'] }} lỗi</span>.
    </div>
@endif

<div class="flex items-center justify-between mb-4 flex-wrap gap-2">
    <h1 class="text-lg font-bold text-gold-700">Danh sách khách đã upload</h1>
    <span class="text-sm text-ink/50">{{ $rows->total() }} dòng</span>
</div>

{{-- Bộ lọc --}}
<form method="GET" class="bg-white rounded-xl shadow-sm border border-gold-400/20 p-4 mb-4">
    <div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-3">
        <div>
            <label class="block text-xs text-ink/50 mb-1">Nguồn (giá trị)</label>
            <select name="nguon" class="w-full rounded-lg border border-gold-400/40 px-2 py-1.5 text-sm">
                <option value="">Tất cả</option>
                @foreach ($nguonList as $n)
                    <option value="{{ $n }}" @selected(($filters['nguon']??'')===$n)>{{ $n }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-ink/50 mb-1">Nguồn (mẫu)</label>
            <select name="source_key" class="w-full rounded-lg border border-gold-400/40 px-2 py-1.5 text-sm">
                <option value="">Tất cả</option>
                @foreach ($sources as $key => $src)
                    <option value="{{ $key }}" @selected(($filters['source_key']??'')===$key)>{{ $src['name'] }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-ink/50 mb-1">Trạng thái</label>
            <select name="status" class="w-full rounded-lg border border-gold-400/40 px-2 py-1.5 text-sm">
                <option value="">Tất cả</option>
                <option value="valid" @selected(($filters['status']??'')==='valid')>Hợp lệ</option>
                <option value="invalid" @selected(($filters['status']??'')==='invalid')>Lỗi</option>
            </select>
        </div>
        <div>
            <label class="block text-xs text-ink/50 mb-1">Tìm tên / SĐT</label>
            <input name="q" value="{{ $filters['q'] ?? '' }}" class="w-full rounded-lg border border-gold-400/40 px-2 py-1.5 text-sm">
        </div>
        <div class="flex items-end gap-2">
            <button class="bg-gold-600 hover:bg-gold-700 text-white rounded-lg px-4 py-1.5 text-sm">Lọc</button>
            <a href="{{ route('demo.leads') }}" class="text-sm text-ink/50 hover:text-ink px-2 py-1.5">Xóa lọc</a>
        </div>
    </div>
</form>

<div class="bg-white rounded-xl shadow-sm border border-gold-400/20 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[820px]">
            <thead class="bg-gold-400/10 text-ink/60 text-left">
                <tr>
                    <th class="px-3 py-2 font-medium">#</th>
                    <th class="px-3 py-2 font-medium">Họ tên</th>
                    <th class="px-3 py-2 font-medium">SĐT</th>
                    <th class="px-3 py-2 font-medium">Nguồn</th>
                    <th class="px-3 py-2 font-medium">Ngày</th>
                    <th class="px-3 py-2 font-medium">Mẫu</th>
                    @if ($isManager)<th class="px-3 py-2 font-medium">Người up</th>@endif
                    <th class="px-3 py-2 font-medium">Trạng thái</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr class="border-t border-gold-400/10 hover:bg-cream/60 align-top">
                        <td class="px-3 py-2 text-ink/40">{{ $row->id }}</td>
                        <td class="px-3 py-2 font-medium">{{ $row->ho_ten ?: '—' }}</td>
                        <td class="px-3 py-2">{{ $row->so_dien_thoai ?: '—' }}</td>
                        <td class="px-3 py-2">{{ $row->nguon ?: '—' }}</td>
                        <td class="px-3 py-2">{{ $row->ngay ? $row->ngay->format('d/m/Y') : '—' }}</td>
                        <td class="px-3 py-2 text-xs text-ink/50">{{ $row->source_name }}</td>
                        @if ($isManager)
                            <td class="px-3 py-2 text-xs">
                                {{ \App\Http\Controllers\DemoStagingController::PERSONAS[$row->uploaded_by]['name'] ?? $row->uploaded_by ?? '—' }}
                            </td>
                        @endif
                        <td class="px-3 py-2">
                            @if ($row->status === 'valid')
                                <span class="px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-700">Hợp lệ</span>
                            @else
                                <span class="px-2 py-0.5 rounded-full text-xs bg-red-100 text-red-700" title="{{ $row->error_reason }}">Lỗi</span>
                                <div class="text-xs text-red-500 mt-0.5">{{ $row->error_reason }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $isManager ? 8 : 7 }}" class="px-3 py-10 text-center text-ink/40">Chưa có dữ liệu. Vào <a href="{{ route('demo.upload') }}" class="text-gold-700 underline">Upload</a> để thêm.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">{{ $rows->links() }}</div>
@endsection
