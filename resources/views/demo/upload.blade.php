@extends('demo.layout')
@section('title', 'Upload — Demo Staging')

@section('content')
<div class="grid md:grid-cols-3 gap-6">
    {{-- Form upload --}}
    <div class="md:col-span-1">
        <div class="bg-white rounded-xl shadow-sm border border-gold-400/20 p-5">
            <h1 class="text-lg font-bold text-gold-700 mb-1">Upload dữ liệu</h1>
            <p class="text-xs text-ink/60 mb-4">Chọn nguồn đúng mẫu, tải file CSV/Excel (1 file = 1 mẫu).</p>

            <form method="POST" action="{{ route('demo.store') }}" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium mb-1">Nguồn</label>
                    <select name="source_key" required
                            onchange="document.querySelectorAll('[data-src]').forEach(e=>e.classList.add('hidden'));
                                      var el=document.querySelector('[data-src=\''+this.value+'\']'); if(el) el.classList.remove('hidden');"
                            class="w-full rounded-lg border border-gold-400/40 px-3 py-2 text-sm focus:ring-gold-500 focus:border-gold-500">
                        <option value="">— Chọn nguồn —</option>
                        @foreach ($sources as $key => $src)
                            <option value="{{ $key }}" @selected(old('source_key')===$key)>{{ $src['name'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">File (.csv, .xlsx, .xls)</label>
                    <input type="file" name="file" required accept=".csv,.txt,.xlsx,.xls"
                           class="w-full text-sm file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-gold-600 file:text-white file:cursor-pointer">
                </div>
                <button class="w-full bg-gold-600 hover:bg-gold-700 text-white font-medium rounded-lg py-2.5 text-sm">Kiểm tra & Lưu</button>
            </form>
        </div>
    </div>

    {{-- Xem trường của nguồn đang chọn --}}
    <div class="md:col-span-2">
        <div class="bg-white rounded-xl shadow-sm border border-gold-400/20 p-5">
            <h2 class="text-sm font-bold text-ink/70 mb-3">Trường của từng nguồn <span class="font-normal text-ink/40">(sửa trong <code>config/demo_sources.php</code>)</span></h2>
            @foreach ($sources as $key => $src)
                <div data-src="{{ $key }}" class="hidden">
                    <div class="font-semibold text-gold-700 mb-2">{{ $src['name'] }}</div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs border-collapse">
                            <thead>
                                <tr class="text-left text-ink/50 border-b border-gold-400/20">
                                    <th class="py-1.5 pr-3">Cột</th>
                                    <th class="py-1.5 pr-3">Bắt buộc</th>
                                    <th class="py-1.5">Vai trò</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($src['fields'] as $f)
                                    <tr class="border-b border-gold-400/10">
                                        <td class="py-1.5 pr-3 font-medium">{{ $f['label'] }}</td>
                                        <td class="py-1.5 pr-3">{!! !empty($f['required']) ? '<span class=\'text-red-600\'>bắt buộc</span>' : '<span class=\'text-ink/30\'>—</span>' !!}</td>
                                        <td class="py-1.5">
                                            @php $role = $f['role'] ?? null; @endphp
                                            @if ($role)
                                                <span class="px-1.5 py-0.5 rounded bg-gold-400/15 text-gold-700">{{ ['name'=>'Tên','phone'=>'SĐT','source'=>'Nguồn','date'=>'Ngày'][$role] ?? $role }}</span>
                                            @else <span class="text-ink/30">—</span> @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
            <div data-src="" class="text-sm text-ink/40 py-8 text-center">Chọn 1 nguồn để xem bộ trường & điều kiện.</div>
        </div>
    </div>
</div>
@endsection
