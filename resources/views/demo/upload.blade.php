@extends('demo.layout')
@section('title', 'Nhập file — Demo')

@section('content')
@if ($rules->isEmpty())
    <div class="bg-white rounded-xl shadow-sm border border-gold-400/20 p-8 text-center">
        <p class="text-sm text-ink/60 mb-3">Chưa có quy tắc trường nào. Tạo quy tắc trước khi nhập file.</p>
        <a href="{{ route('demo.rules') }}" class="inline-block bg-gold-600 hover:bg-gold-700 text-white font-medium rounded-lg px-5 py-2.5 text-sm">← Bước 1 — Tạo quy tắc</a>
    </div>
@else
<div class="grid md:grid-cols-3 gap-6" x-data="{ rid: '{{ old('rule_id', $rules->first()->id) }}' }">
    {{-- Form upload --}}
    <div class="md:col-span-1">
        <div class="bg-white rounded-xl shadow-sm border border-gold-400/20 p-5">
            <h1 class="text-lg font-bold text-gold-700 mb-1">Bước 2 — Nhập file</h1>
            <p class="text-xs text-ink/60 mb-4">Chọn quy tắc + tải file CSV/Excel, rồi sang bước ghép cột.</p>

            <form method="POST" action="{{ route('demo.preview') }}" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium mb-1">Quy tắc trường</label>
                    <select name="rule_id" x-model="rid" required
                            class="w-full rounded-lg border border-gold-400/40 px-3 py-2 text-sm">
                        @foreach ($rules as $rule)
                            <option value="{{ $rule->id }}">{{ $rule->name }}</option>
                        @endforeach
                    </select>
                    @error('rule_id')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">File (.csv, .xlsx, .xls)</label>
                    <input type="file" name="file" required accept=".csv,.txt,.xlsx,.xls"
                           class="w-full text-sm file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-gold-600 file:text-white file:cursor-pointer">
                    @error('file')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>
                <button class="w-full bg-gold-600 hover:bg-gold-700 text-white font-medium rounded-lg py-2.5 text-sm">Đọc file & Ghép cột →</button>
            </form>
        </div>
    </div>

    {{-- Xem trường của quy tắc đang chọn --}}
    <div class="md:col-span-2">
        <div class="bg-white rounded-xl shadow-sm border border-gold-400/20 p-5">
            <h2 class="text-sm font-bold text-ink/70 mb-3">Trường của quy tắc</h2>
            @foreach ($rules as $rule)
                <div x-show="rid === '{{ $rule->id }}'" x-cloak>
                    <div class="font-semibold text-gold-700 mb-2">{{ $rule->name }}</div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs border-collapse">
                            <thead>
                                <tr class="text-left text-ink/50 border-b border-gold-400/20">
                                    <th class="py-1.5 pr-3">Cột</th>
                                    <th class="py-1.5 pr-3">Bắt buộc</th>
                                    <th class="py-1.5">Khớp cột chuẩn</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rule->fields as $f)
                                    <tr class="border-b border-gold-400/10">
                                        <td class="py-1.5 pr-3 font-medium">{{ $f['label'] }}</td>
                                        <td class="py-1.5 pr-3">{!! ($f['required'] ?? false) ? '<span class=\'text-red-600\'>bắt buộc</span>' : '<span class=\'text-ink/30\'>—</span>' !!}</td>
                                        <td class="py-1.5">
                                            @php $role = $f['role'] ?? ''; @endphp
                                            @if ($role !== '')
                                                <span class="px-1.5 py-0.5 rounded bg-gold-400/15 text-gold-700">{{ \App\Models\DemoFieldRule::ROLES[$role] ?? $role }}</span>
                                            @else <span class="text-ink/30">—</span> @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endif
@endsection
