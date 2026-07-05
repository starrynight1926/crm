@extends('demo.layout')
@section('title', 'Quy tắc trường — Demo')

@section('content')
@if (session('flash_rule'))
    <div class="mb-4 rounded-lg bg-green-50 border border-green-200 text-green-700 px-4 py-2 text-sm">{{ session('flash_rule') }}</div>
@endif

<div class="grid lg:grid-cols-2 gap-6">
    {{-- Tạo quy tắc --}}
    <div class="bg-white rounded-xl shadow-sm border border-gold-400/20 p-5"
         x-data="{
            rows: [{label:'Tên khách', role:'name', req:true}, {label:'Số điện thoại', role:'phone', req:true}],
            add(){ this.rows.push({label:'', role:'', req:false}) },
            del(i){ this.rows.splice(i,1) }
         }">
        <h1 class="text-lg font-bold text-gold-700 mb-1">Bước 1 — Tạo quy tắc trường</h1>
        <p class="text-xs text-ink/60 mb-4">Đặt tên quy tắc và khai báo các trường trong file. Cột "Khớp cột chuẩn" cho biết mỗi trường đổ vào cột nào của DB (Họ tên/SĐT/Nguồn/Ngày) hay chỉ lưu thô.</p>

        <form method="POST" action="{{ route('demo.ruleStore') }}">
            @csrf
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1">Tên quy tắc</label>
                <input name="name" value="{{ old('name') }}" required placeholder="VD: Mẫu lead Facebook"
                       class="w-full rounded-lg border border-gold-400/40 px-3 py-2 text-sm">
                @error('name')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="mb-2 flex items-center justify-between">
                <label class="text-sm font-medium">Các trường</label>
                <button type="button" @click="add()" class="text-xs font-semibold text-gold-700 hover:underline">+ thêm trường</button>
            </div>
            @error('labels')<p class="text-xs text-red-600 mb-2">{{ $message }}</p>@enderror

            {{-- Tiêu đề cột --}}
            <div class="flex items-center gap-2 mb-1 px-0.5">
                <span class="flex-1 text-[11px] font-semibold uppercase tracking-wider text-ink/40">Tên trường (trong file)</span>
                <span class="w-[130px] text-[11px] font-semibold uppercase tracking-wider text-ink/40">Khớp cột chuẩn</span>
                <span class="w-[64px] text-[11px] font-semibold uppercase tracking-wider text-ink/40">Bắt buộc</span>
                <span class="w-4"></span>
            </div>

            <div class="space-y-2">
                <template x-for="(row, i) in rows" :key="i">
                    <div class="flex items-center gap-2">
                        <input :name="'labels['+i+']'" x-model="row.label" placeholder="VD: Họ tên, Số ĐT..."
                               class="flex-1 rounded-lg border border-gold-400/40 px-2.5 py-1.5 text-sm">
                        <select :name="'roles['+i+']'" x-model="row.role"
                                class="w-[130px] rounded-lg border border-gold-400/40 px-2 py-1.5 text-sm bg-white">
                            @foreach ($roles as $rk => $rlabel)
                                <option value="{{ $rk }}">{{ $rlabel }}</option>
                            @endforeach
                        </select>
                        <label class="w-[64px] flex items-center justify-center">
                            <input type="checkbox" :name="'required['+i+']'" x-model="row.req">
                        </label>
                        <button type="button" @click="del(i)" class="w-4 text-red-400 hover:text-red-600 text-lg leading-none">&times;</button>
                    </div>
                </template>
            </div>
            <p class="text-[11px] text-ink/40 mt-2">
                <b>Khớp cột chuẩn</b>: cột file này đổ vào cột chuẩn nào trong DB — <b>Họ tên / SĐT</b> (có validate + chuẩn hóa số), <b>Nguồn / Ngày</b> (để lọc & báo cáo), hoặc <b>chỉ lưu thô</b> vào payload.
            </p>

            <button class="mt-5 w-full bg-gold-600 hover:bg-gold-700 text-white font-medium rounded-lg py-2.5 text-sm">Lưu quy tắc</button>
        </form>
    </div>

    {{-- Danh sách quy tắc đã có --}}
    <div class="bg-white rounded-xl shadow-sm border border-gold-400/20 p-5">
        <h2 class="text-sm font-bold text-ink/70 mb-3">Quy tắc đã tạo ({{ $rules->count() }})</h2>
        @forelse ($rules as $rule)
            <div class="border border-gold-400/15 rounded-lg p-3 mb-2">
                <div class="flex items-center justify-between">
                    <div class="font-semibold text-sm">{{ $rule->name }}</div>
                    <form method="POST" action="{{ route('demo.ruleDelete', $rule->id) }}" onsubmit="return confirm('Xóa quy tắc này?')">
                        @csrf @method('DELETE')
                        <button class="text-xs text-red-600 hover:underline">Xóa</button>
                    </form>
                </div>
                <div class="flex flex-wrap gap-1.5 mt-2">
                    @foreach ($rule->fields as $f)
                        <span class="text-xs px-2 py-0.5 rounded-full bg-gold-400/10 text-gold-700 border border-gold-400/20">
                            {{ $f['label'] }}
                            @if (($f['role'] ?? '') !== '')<b class="text-[10px]">·{{ \App\Models\DemoFieldRule::ROLES[$f['role']] ?? $f['role'] }}</b>@endif
                            @if ($f['required'] ?? false)<span class="text-red-500">*</span>@endif
                        </span>
                    @endforeach
                </div>
            </div>
        @empty
            <p class="text-sm text-ink/40 py-6 text-center">Chưa có quy tắc nào. Tạo bên trái rồi sang bước 2 nhập file.</p>
        @endforelse

        @if ($rules->count())
            <a href="{{ route('demo.upload') }}" class="mt-3 inline-block text-sm font-semibold text-gold-700 hover:underline">Sang bước 2 — Nhập file →</a>
        @endif
    </div>
</div>
@endsection
