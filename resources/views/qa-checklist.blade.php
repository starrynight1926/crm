<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QA Checklist — Longevity Data Source</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: {
                fontFamily: { sans: ['"Be Vietnam Pro"', 'sans-serif'] },
                colors: {
                    gold: { 50:'#FBF8F1', 100:'#F5EDD8', 200:'#E8D5A8', 400:'#C0A467', 500:'#A8863C', 600:'#8B5E14', 700:'#75510F' },
                    cream: '#FAF7F2', ink: '#2D2A24',
                },
            }},
        };
    </script>
    <style>[x-cloak]{display:none!important}</style>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-cream text-ink font-sans antialiased min-h-screen">

<header class="bg-white border-b border-gold-200 shadow-sm sticky top-0 z-40">
    <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between gap-3">
        <div class="flex items-center gap-3 min-w-0">
            <span class="font-bold text-gold-700 text-base md:text-lg tracking-tight">Longevity Data Source</span>
            <span class="text-xs md:text-sm text-ink/50 hidden sm:inline">QA Checklist</span>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('guide') }}" class="text-xs md:text-sm px-3 md:px-4 py-2 rounded-lg border border-gold-300 text-gold-700 hover:bg-gold-50 font-semibold whitespace-nowrap">📖 Hướng dẫn</a>
            <a href="{{ route('login') }}" class="text-xs md:text-sm px-3 md:px-4 py-2 rounded-lg bg-gold-600 text-white hover:bg-gold-700 font-semibold whitespace-nowrap">Đăng nhập</a>
        </div>
    </div>
</header>

@php
    // Cập nhật 2026-08-04 — Phase 6.25.C + Quy tắc PKD.
    $sections = [
        '1. Authentication & Phân quyền' => [
            'Login với tài khoản <code>admin@…</code> mật khẩu <code>59ntn</code>',
            'Login user <code>hn.sale04@longevity.com.vn</code> mật khẩu <code>59@ntn</code>',
            'Sale không có <code>lead.distribute_sale</code> mở lead đã có owner khác → không thấy nút chia/chuyển kho',
            'User chỉ là CV (gán vào <code>booking_log_consultants</code>), không có role cao → mở lead thấy banner amber, mọi form khách readonly, chỉ mở phần bình luận',
        ],
        '2. Tạo mới & Chia số (Phase 1)' => [
            'Lead nguồn MKT → chọn kho Cơ sở/Phòng KD → tự động <strong>pickMkt</strong> từ MKT List UPS + flash "Đã chia lead cho {sale}"',
            'Chưa chốt UPS hôm nay → chọn cơ sở → thấy cảnh báo đỏ "UPS chưa được chốt hôm nay"',
            'Nguồn MKT + kho công ty/chi nhánh → validate error "bắt buộc chọn Cơ sở hoặc Phòng KD"',
            'Cascade Kho: Công ty (readonly Longevity) → Địa điểm → Cơ sở → Phòng ban — dừng bất kỳ cấp nào từ Địa điểm',
        ],
        '3. Cấu hình Chia số (rule)' => [
            'Vào <code>/distribution/rules</code> → tạo rule L2 (team_to_user) → chọn cascade 4 select → save không lỗi',
            'Rule L2 cascade: chỉ chọn Địa điểm → save OK (kho về địa điểm)',
            'Rule L2 cascade: đủ 4 cấp → poolUnitId = Phòng ban id',
        ],
        '4. Booking (Phase 3)' => [
            'Form booking: chọn Cơ sở → BS dropdown filter theo <code>sbooking_co_so_id</code>',
            'Chọn dịch vụ loại tư vấn → BS dropdown chỉ hiện BS có <code>nhan_tu_van=true</code>',
            'Chọn dịch vụ loại khám LS → BS dropdown chỉ hiện BS có <code>nhan_kham_ls=true</code>',
            'Dropdown dịch vụ KHÔNG bị lặp x3-x4 (đã dedupe + filter theo cơ sở)',
            'Chọn slot khung giờ (VD 09:00-09:30) → save booking → <code>sb_khung_gio_id</code> + <code>scheduled_end_at</code> được lưu',
            'Push sang sbooking → mở sbooking <code>/xem-dat-phong/{id}</code> → thấy đủ <code>sale_id</code>, <code>khung_gio_id</code>, <code>dich_vu_id</code>, <code>phong_id</code>',
            'Booking có CV1 gán → sang sbooking thấy nút "Đang tiếp đón" hiển thị cho sale đó',
        ],
        '5. Callback từ sbooking (real-time)' => [
            'Bên sbooking Admin bấm Duyệt booking → thấy toast xanh "Đã duyệt" + flash xanh',
            'Nếu BS không đủ capability (VD BS không nhận tư vấn cho dịch vụ tư vấn) → sbooking hiện <strong>flash đỏ error</strong> giải thích rõ (KHÔNG silent)',
            'Sau khi Admin duyệt → mở scrm <code>/leads/{id}/edit</code> → <strong>auto refresh Livewire</strong> trong <5s (thấy toast xanh "Cập nhật từ sbooking đã đồng bộ"), không cần F5',
            'Admin đánh dấu khách <code>da_toi</code> bên sbooking → scrm auto pickGreet sale từ Sale Tiếp Đón, gán <code>owner_id</code>',
        ],
        '6. UPS System' => [
            'Vào <code>/ups/list</code> (BO) → thấy bảng theo Chi nhánh > Cơ sở',
            'Check-in sale: chọn sale → chọn vị trí (Tiếp đón / Nhận số) → chọn tier → bấm Check in → thêm vào bảng',
            'Bấm "Chốt UPS hôm nay" cơ sở → flash "Đã chốt" + badge xanh',
            'Sau khi chốt → mở tab khác lead-form → nút Check UPS System <strong>không</strong> còn màu đỏ animate-pulse',
            'Bấm nút "⚡ Check UPS System" trên lead-form → popup hiện danh sách sale check-in hôm nay + tình trạng (Đang tiếp đón / Đang chờ / Offlist)',
            'Sale bấm "Đang tiếp đón" bên sbooking → sale bên scrm UPS chuyển sang trạng thái busy (<code>is_busy=true</code>)',
            'Sale bấm "Hoàn tất" → sale rảnh lại, có thể chia số tiếp',
        ],
        '7. Quy tắc thu hồi PKD (Phase 6.26)' => [
            'Chia lead cho sale + tick "Áp dụng luật thu hồi tự động"',
            'Backdate <code>leads.assigned_at</code> về 2 ngày trước, không điền page/camp/phan_loai',
            'Chạy <code>php artisan leads:recall-by-columns --dry-run</code> → thấy <code>[DRY] KH-XXX: quá 1 ngày chưa cập nhật đủ 3 cột đầu</code>',
            'Bỏ <code>--dry-run</code> → lead recall về kho team (pool_level chuyển POOL_TEAM, sẽ tự loại khỏi query lần sau)',
            'Schedule hourly ở <code>routes/console.php</code> đang chạy',
        ],
        '8. Audit UPS Distribution (fix 4 bug)' => [
            'Race U1: 2 request đồng thời gọi <code>POST /api/leads/{code}/booking-event</code> type=status trang_thai_khach=da_toi → 1 sale nhận 1 lead (không double)',
            'Wrap-around U4: 3 sale bucket A hết busy → gọi pickGreet → phải trả sale đầu (fallback wrap-around)',
            'Cutoff U6: sale check-in đúng cutoff (VD 08:36) → phải vào bucket A, không phải OFF',
            'Guard U7: xoá <code>UpsDailyConfirm</code> hôm nay → callback da_toi → KHÔNG auto-assign (log "chưa chốt UPS")',
        ],
        '9. Reconcile drift' => [
            'Chạy <code>php artisan sb:reconcile-bookings --dry-run</code> → in stats (seen, matched, backfilled, status_changed, cv_synced)',
            'Chạy <code>php artisan sb:reconcile-bookings</code> → booking cũ có <code>sb_khung_gio_id</code>, <code>sb_dich_vu_id</code>, <code>scheduled_end_at</code> được backfill (nếu sbooking có data)',
            '<code>sync_status</code> cập nhật khớp trang_thai sbooking (<code>cho_duyet</code> → <code>synced</code>, <code>da_duyet</code> → <code>approved</code>, <code>da_toi</code> → <code>checkedin</code>, <code>da_xong</code> → <code>done</code>)',
        ],
        '10. Auto-map users.sbooking_user_id' => [
            'Chạy <code>php artisan sb:sync-users</code> → in "Auto-map users↔sbooking: N mapped"',
            '<code>SELECT id, name, email, sbooking_user_id FROM users WHERE email LIKE \'hn.sale04%\'</code> → có <code>sbooking_user_id</code> ≠ NULL',
        ],
        '11. Cascade UI' => [
            '<code>⚡rule-config.blade.php</code>: 4 select cascade (Công ty readonly + Địa điểm + Cơ sở + Phòng ban)',
            '<code>⚡lead-form.blade.php</code> "Chia số": checkbox "Kho chung công ty" + 4 select cascade — check "Kho chung" → cascade tự khóa, poolTarget=\'company\'',
            '<code>⚡lead-pools.blade.php</code> filter Team tab: 3 select cascade Địa điểm → Cơ sở → Phòng ban',
            '<code>⚡lead-pools.blade.php</code> bulk/inline chuyển kho: <code>&lt;optgroup&gt;</code> group theo Địa điểm',
        ],
        '12. Real-time (Reverb)' => [
            '<code>php artisan reverb:start</code> chạy → mở 2 tab lead cùng 1 lead → thao tác 1 tab → tab kia nhận toast "Cập nhật từ sbooking"',
            'Notification "Lead mới" toast → click → nhảy đúng lead',
        ],
        '13. Dashboard & Export' => [
            'Login Admin → <code>/dashboard</code> → thấy 5 widget hôm nay (UPS · Khách mới · Được nhận · Chờ duyệt · Chờ chia)',
            'Login BO/Sale → chỉ thấy widget có perm tương ứng',
            '<code>/leads</code> → bấm ⬇ Export → modal hiện — mặc định CHỈ tick core columns (Mã KH, Họ tên, SĐT, nguồn, phân loại, booking…). Custom fields KHÔNG tick sẵn',
            'Export → CSV mở Excel tiếng Việt đúng',
        ],
        '14. Mobile iPhone 11+ (390-430px)' => [
            'Mở Chrome DevTools mobile 390px width',
            '<code>/dashboard</code> → 5 widget fit 2 col',
            'Lead detail → nút "Check UPS System" popup không tràn mép phải',
            '<code>/leads</code> → header title co gọn, button không đè lên nhau',
            'Hamburger menu (md:hidden) mở → drawer nav hiển thị đủ item',
        ],
    ];
@endphp

<main class="max-w-5xl mx-auto px-4 py-8" x-data="{
    stats() {
        const all = document.querySelectorAll('input[type=checkbox][data-qa]');
        const done = document.querySelectorAll('input[type=checkbox][data-qa]:checked').length;
        return { all: all.length, done: done };
    }
}">

    <div class="text-center mb-8">
        <h1 class="text-2xl md:text-3xl font-extrabold text-gold-700 mb-2">QA Checklist — Test tay trước release</h1>
        <p class="text-sm md:text-base text-ink/60 max-w-2xl mx-auto">Đánh dấu ✅ khi PASS, để trống nếu FAIL. Cập nhật khi merge phase lớn. Cập nhật 2026-08-04.</p>
    </div>

    <div class="mb-6 p-4 bg-white border border-gold-200 rounded-lg shadow-sm flex items-center justify-between flex-wrap gap-3">
        <div class="text-sm text-ink/70">
            <span class="font-bold text-gold-700 text-lg" x-text="stats().done"></span>
            <span> / </span>
            <span class="font-bold" x-text="stats().all"></span>
            <span class="ml-1">mục đã pass</span>
        </div>
        <div class="flex gap-2">
            <button type="button" onclick="document.querySelectorAll('input[type=checkbox][data-qa]').forEach(c=>c.checked=false); this.dispatchEvent(new Event('reset',{bubbles:true}))"
                    class="text-xs px-3 py-1.5 border border-gold-300 text-gold-700 rounded hover:bg-gold-50">Reset all</button>
            <button type="button" onclick="window.print()"
                    class="text-xs px-3 py-1.5 bg-gold-600 text-white rounded hover:bg-gold-700">🖨 Print</button>
        </div>
    </div>

    @foreach ($sections as $title => $items)
        <section class="mb-6">
            <h2 class="text-lg font-bold text-gold-700 mb-3 flex items-center gap-2">
                <span class="w-1.5 h-6 bg-gold-600 rounded"></span>
                {{ $title }}
            </h2>
            <div class="bg-white border border-gold-200 rounded-xl shadow-sm divide-y divide-gold-100">
                @foreach ($items as $i => $item)
                    @php $id = 'qa-' . md5($title . $i); @endphp
                    <label for="{{ $id }}" class="flex items-start gap-3 px-5 py-3 hover:bg-gold-50/30 cursor-pointer">
                        <input type="checkbox" id="{{ $id }}" data-qa
                               @change="$dispatch('qa-changed')"
                               class="mt-1 rounded border-gold-300 text-gold-600 focus:ring-gold-500 w-4 h-4 flex-shrink-0">
                        <span class="text-sm text-ink/85 leading-relaxed">{!! $item !!}</span>
                    </label>
                @endforeach
            </div>
        </section>
    @endforeach

    <section class="mb-6">
        <h2 class="text-lg font-bold text-gold-700 mb-3 flex items-center gap-2">
            <span class="w-1.5 h-6 bg-gold-600 rounded"></span>
            Bug tracker (điền khi test)
        </h2>
        <div class="bg-white border border-gold-200 rounded-xl shadow-sm overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gold-50/50 text-xs uppercase tracking-wider text-ink/60">
                    <tr>
                        <th class="px-4 py-3 text-left w-12">#</th>
                        <th class="px-4 py-3 text-left w-24">Ngày</th>
                        <th class="px-4 py-3 text-left w-32">Người test</th>
                        <th class="px-4 py-3 text-left">Bug</th>
                        <th class="px-4 py-3 text-left w-28">Trạng thái</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gold-100" x-data="{ rows: [{},{},{},{}] }">
                    <template x-for="(row, i) in rows" :key="i">
                        <tr>
                            <td class="px-4 py-3 text-ink/50" x-text="i + 1"></td>
                            <td class="px-4 py-3"><input type="date" class="w-full text-xs border border-gold-200 rounded px-2 py-1"></td>
                            <td class="px-4 py-3"><input type="text" placeholder="Tên" class="w-full text-xs border border-gold-200 rounded px-2 py-1"></td>
                            <td class="px-4 py-3"><input type="text" placeholder="Mô tả bug ngắn gọn" class="w-full text-xs border border-gold-200 rounded px-2 py-1"></td>
                            <td class="px-4 py-3">
                                <select class="w-full text-xs border border-gold-200 rounded px-2 py-1">
                                    <option value="">—</option>
                                    <option>Open</option>
                                    <option>In progress</option>
                                    <option>Fixed</option>
                                    <option>Wontfix</option>
                                </select>
                            </td>
                        </tr>
                    </template>
                    <tr><td colspan="5" class="px-4 py-2 text-center"><button @click="rows.push({})" type="button" class="text-xs text-gold-700 hover:underline">+ thêm dòng</button></td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <div class="text-center text-xs text-ink/40 mt-8">
        <p><a href="{{ route('guide') }}" class="text-gold-700 hover:underline">← Về Hướng dẫn sử dụng</a></p>
    </div>
</main>

</body>
</html>
