@extends('demo.layout')
@section('title', 'Ghép cột — Demo Staging')

@section('content')
<div class="mb-4">
    <a href="{{ route('demo.upload') }}" class="text-sm text-ink/50 hover:text-gold-700">← Chọn file khác</a>
    <h1 class="text-lg font-bold text-gold-700 mt-1">Ghép cột file → trường nhập</h1>
    <p class="text-xs text-ink/60">Nguồn: <b>{{ $source['name'] }}</b>. Hệ thống đã đoán sẵn, kiểm tra lại rồi bấm lưu.</p>
</div>

{{-- Các cột tìm thấy trong file --}}
<div class="bg-white rounded-xl shadow-sm border border-gold-400/20 p-4 mb-4">
    <div class="text-sm font-semibold text-ink/70 mb-2">Các trường hiện có trong file của bạn ({{ count($columns) }} cột)</div>
    <div class="flex flex-wrap gap-2">
        @foreach ($columns as $name)
            <span class="text-xs px-2.5 py-1 rounded-full bg-gold-400/10 text-gold-700 border border-gold-400/20">{{ $name }}</span>
        @endforeach
    </div>
</div>

<form method="POST" action="{{ route('demo.import') }}">
    @csrf
    <input type="hidden" name="rule_id" value="{{ $ruleId }}">
    <input type="hidden" name="token" value="{{ $token }}">
    <input type="hidden" name="header_row" value="{{ $headerRow }}">

    @if ($errors->has('mapping'))
        <div class="mb-3 rounded-lg bg-red-50 border border-red-200 text-red-700 px-4 py-2 text-sm">{{ $errors->first('mapping') }}</div>
    @endif

    <div class="grid lg:grid-cols-2 gap-6">
        {{-- Ghép cột --}}
        <div class="bg-white rounded-xl shadow-sm border border-gold-400/20 p-5">
            <div class="text-sm font-semibold text-ink/70 mb-1">Ghép cột file vào trường của quy tắc</div>
            <p class="text-xs text-ink/50 mb-3">Mỗi <b>trường theo quy tắc "{{ $source['name'] }}"</b> lấy dữ liệu từ <b>cột nào trong file</b>.</p>

            {{-- Tiêu đề cột --}}
            <div class="grid grid-cols-[1.1fr_14px_1fr_0.9fr] gap-2 mb-1 px-0.5">
                <span class="text-[11px] font-semibold uppercase tracking-wider text-ink/40">Trường theo quy tắc</span>
                <span></span>
                <span class="text-[11px] font-semibold uppercase tracking-wider text-ink/40">Cột trong file</span>
                <span class="text-[11px] font-semibold uppercase tracking-wider text-ink/40">Mặc định nếu trống</span>
            </div>

            <div class="space-y-2.5">
                @foreach ($source['fields'] as $fi => $field)
                    @php $role = $field['role'] ?? null; $req = in_array($role, ['name','phone'], true); @endphp
                    <div class="grid grid-cols-[1.1fr_14px_1fr_0.9fr] gap-2 items-center">
                        <div class="text-sm">
                            <span class="font-medium">{{ $field['label'] }}</span>
                            @if ($req)<span class="text-red-500">*</span>@endif
                            @if ($role)<span class="block text-[10px] text-gold-700">→ cột chuẩn: {{ \App\Models\DemoFieldRule::ROLES[$role] ?? $role }}</span>@endif
                        </div>
                        <span class="text-ink/30 text-center">←</span>
                        <select name="mapping[{{ $fi }}]" class="w-full rounded-lg border border-gold-400/40 px-2 py-1.5 text-sm bg-white">
                            <option value="">— bỏ qua —</option>
                            @foreach ($columns as $ci => $cname)
                                <option value="{{ $ci }}" @selected((string)($guess[$fi] ?? '') === (string)$ci)>{{ $cname }}</option>
                            @endforeach
                        </select>
                        <input type="text" name="defaults[{{ $fi }}]" value="{{ old('defaults.'.$fi) }}"
                               placeholder="VD: Tự do"
                               class="w-full rounded-lg border border-gold-400/40 px-2 py-1.5 text-sm">
                    </div>
                @endforeach
            </div>
            <button class="mt-5 w-full bg-gold-600 hover:bg-gold-700 text-white font-medium rounded-lg py-2.5 text-sm">Kiểm tra & Lưu vào staging</button>
        </div>

        {{-- Preview 5 dòng --}}
        <div class="bg-white rounded-xl shadow-sm border border-gold-400/20 p-5 overflow-x-auto">
            <div class="text-sm font-semibold text-ink/70 mb-3">Xem trước 5 dòng đầu</div>
            <table class="w-full text-xs whitespace-nowrap">
                <thead>
                    <tr class="text-left text-ink/50 border-b border-gold-400/20">
                        @foreach ($columns as $cname)
                            <th class="px-2 py-1.5 font-semibold">{{ $cname }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gold-400/10">
                    @forelse ($preview as $row)
                        <tr>
                            @foreach ($columns as $ci => $cname)
                                <td class="px-2 py-1.5">{{ $row[$ci] ?? '' }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td class="px-2 py-4 text-ink/40">Không có dòng dữ liệu.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</form>
@endsection
