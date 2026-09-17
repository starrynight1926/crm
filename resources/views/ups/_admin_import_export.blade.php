{{-- 2026-08-26: Khối Import/Export cho admin@longevity.com.vn.
     Include ở cả /ups-today và /ups-list để admin thao tác được ở đâu cũng như nhau. --}}
@if (auth()->user()?->email === 'admin@longevity.com.vn')
    <div class="mb-4 p-3 bg-gold-50/40 border border-gold-100 rounded-lg flex flex-wrap items-end gap-3">
        <div class="text-sm font-semibold text-gold-700 mr-2">Admin · Import / Export</div>

        <form method="GET" action="{{ route('ups.today.export') }}" class="flex items-end gap-2">
            <label class="text-xs text-ink/60 flex flex-col">Từ ngày
                <input type="date" name="from" required value="{{ now()->toDateString() }}" class="px-2 py-1 border border-gold-200 rounded text-sm">
            </label>
            <label class="text-xs text-ink/60 flex flex-col">Đến ngày
                <input type="date" name="to" required value="{{ now()->toDateString() }}" class="px-2 py-1 border border-gold-200 rounded text-sm">
            </label>
            <button class="px-3 py-1.5 bg-gold-600 hover:bg-gold-700 text-white text-sm font-semibold rounded">↓ Export</button>
        </form>

        <form method="POST" action="{{ route('ups.today.import') }}" enctype="multipart/form-data"
              class="flex items-end gap-2"
              onsubmit="return confirm('Import sẽ XOÁ TOÀN BỘ check-in hôm nay rồi insert lại từ file. Tiếp tục?');">
            @csrf
            <label class="text-xs text-ink/60 flex flex-col">File xlsx
                <input type="file" name="file" accept=".xlsx,.xls" required class="text-sm">
            </label>
            <button class="px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded">↑ Import (replace hôm nay)</button>
        </form>

        <div class="text-xs text-ink/50 basis-full">
            Cột import bắt buộc: <code>facility_code, sale_email, bucket, is_mkt</code>. Bucket = A/B/C/OFF hoặc trống.
        </div>
    </div>

    @if (session('ups_msg'))
        <div class="mb-3 p-2 bg-green-50 border border-green-200 text-green-800 rounded text-sm">{{ session('ups_msg') }}</div>
    @endif
@endif
