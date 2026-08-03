<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hướng dẫn sử dụng — Longevity Data Source</title>
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
            <span class="text-xs md:text-sm text-ink/50 hidden sm:inline">Hướng dẫn sử dụng</span>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('qa') }}" class="text-xs md:text-sm px-3 md:px-4 py-2 rounded-lg border border-gold-300 text-gold-700 hover:bg-gold-50 font-semibold whitespace-nowrap">📋 QA Checklist</a>
            <a href="{{ route('login') }}" class="text-xs md:text-sm px-3 md:px-4 py-2 rounded-lg bg-gold-600 text-white hover:bg-gold-700 font-semibold whitespace-nowrap">Đăng nhập</a>
        </div>
    </div>
</header>

@php
    // 2026-08-04 update: 8 role đầy đủ theo file HUONG_DAN_SU_DUNG.md + quy tắc PKD.
    $roles = [
        'truc_page' => [
            'icon' => '📝',
            'name' => 'Trực Page',
            'summary' => 'Up lead MKT',
            'intro' => 'Nhận data khách marketing từ nhiều nguồn (post, ads, page…) và up lên hệ thống để phòng Booking gọi.',
            'steps' => [
                ['Up 1 lead mới', 'Bấm <strong>+ Thêm mới lead</strong> góc phải. Điền: <strong>Họ tên, SĐT, Ngày nhập</strong> (tự điền hôm nay), <strong>Nhóm nguồn</strong> (Marketing / Marketing BR / BDM), <strong>Link nguồn</strong>, <strong>Insight</strong> (khách nói gì).'],
                ['Chọn cơ sở đích', 'Mục <strong>Chia số</strong>: KHÔNG tick "Kho chung công ty". Chọn cascade Địa điểm → Cơ sở → (không cần chọn Phòng ban). Hệ thống tự chia lead cho 1 sale trong MKT List UPS của cơ sở đó.'],
                ['Xác nhận', 'Bấm <strong>Lưu</strong>. Flash xanh: "MKT List: Đã chia lead cho [tên sale]."'],
                ['Nếu bị chặn "UPS chưa chốt"', 'BO/Lễ Tân của cơ sở đó chưa chốt UPS đầu ngày. Liên hệ BO chốt trước.'],
                ['Xem lại lead đã up', 'Menu <strong>Khách hàng → Danh sách</strong> → filter "Người nhập" = bạn. Bạn có thể sửa thông tin lead do CHÍNH BẠN up.'],
            ],
        ],
        'bo' => [
            'icon' => '⚡',
            'name' => 'BO (Lễ Tân)',
            'summary' => 'Chốt UPS đầu ngày',
            'intro' => 'Quản UPS check-in đầu ngày. Sale không thể nhận lead cho tới khi bạn chốt UPS.',
            'steps' => [
                ['Vào UPS', 'Menu <strong>UPS SYSTEM → Check-in (BO)</strong>.'],
                ['Check-in từng sale', 'Chọn tên sale → chọn <strong>Vị trí</strong> (Tiếp đón / Nhận số MKT) → chọn <strong>Tier</strong> (Tự động / A / B / C / OFF) → bấm <strong>+ Check in</strong>.'],
                ['Chốt UPS', 'Khi đủ nhân sự, bấm nút vàng <strong>Chốt UPS hôm nay</strong> ở cơ sở → mở khoá chia số cho cả ngày.'],
                ['Sửa sau khi check-in', 'Dùng dropdown "↔" chuyển bucket, hoặc ✕ đỏ để xoá. Sau khi đã chốt UPS, muốn sửa: bấm <strong>Hủy chốt UPS</strong> → chỉnh → chốt lại.'],
                ['Ý nghĩa bucket', '<strong>A</strong>: khách BOD/Hotline/MKT/AFF/WI/BR (sale có doanh thu >20TR hôm trước). <strong>B</strong>: khách APPT/PNS/VOUCHER (sale có 2 show/thu tiền). <strong>C</strong>: backup khi B bận. <strong>OFF</strong>: không nhận số hôm nay (KHÔNG phải nghỉ làm — sale vẫn ở clinic). <strong>MKT</strong>: TM team HC nhận lead MKT theo thứ tự.'],
            ],
        ],
        'sale' => [
            'icon' => '💰',
            'name' => 'Team Sale',
            'summary' => 'Nhận lead & chăm khách',
            'intro' => 'Nhận lead từ hệ thống UPS → gọi khách → book lịch → khách đến clinic → tư vấn → chốt.',
            'steps' => [
                ['Đầu ngày', 'Đến clinic, BO check-in cho bạn vào UPS. Bạn không cần thao tác gì với UPS.'],
                ['Nhận lead mới', 'Toast <strong>🔔 Lead mới</strong> nháy góc phải → bấm để mở lead. Xem 5 cột: PAGE, Camp, Phân loại, Kết quả, S.I.C.'],
                ['Gọi khách', 'Bấm <strong>Gọi điện</strong> → chọn trạng thái (Thành công/Thất bại/Không nghe máy) → ghi note.'],
                ['⚠️ Quy tắc PKD BẮT BUỘC', '<span class="text-red-700 font-semibold">Trong 1 ngày phải update 3 cột đầu (PAGE, Camp, Phân loại). Không → hệ thống tự thu hồi lead. Trong 3 ngày phải update đủ 5 cột (thêm Kết quả, S.I.C). Không → thu hồi.</span> Áp dụng khi CM/Admin đã tick "Áp dụng luật thu hồi tự động" khi chia lead.'],
                ['Đặt booking', 'Phase Booking → <strong>+ Thêm booking</strong>. Chọn Loại → Cơ sở → Phòng → Dịch vụ → Bác sĩ → Khung giờ. Dropdown BS chỉ hiện BS phù hợp với dịch vụ. Chọn CV tư vấn (người đầu tiên = Sale phụ trách khi booking duyệt).'],
                ['Khi khách tới clinic', 'Lễ tân/Admin sbooking bấm "Khách đã tới" → hệ thống tự gán bạn (theo Sale Tiếp Đón A→B→C→OFF). Bạn vào Booking bấm <strong>Đang tiếp đón</strong> (đánh dấu bạn bận) → tư vấn xong bấm <strong>Hoàn tất</strong> (quay về sẵn sàng).'],
                ['Xem lead của bạn', 'Menu <strong>Khách hàng → Kho khách → tab Cá nhân</strong>.'],
            ],
        ],
        'booking' => [
            'icon' => '📅',
            'name' => 'Team Tele / Booking',
            'summary' => 'Gọi lead MKT & book',
            'intro' => 'Gọi lead nguồn MKT/BDM sau khi trực page up. Xác định khách có tiềm năng → book lịch → chuyển sang phòng Sale.',
            'steps' => [
                ['Nhận lead', 'Lead nhóm 1 (MKT/MKT BR/BDM) sau khi trực page up nằm trong <strong>Kho Booking</strong>. CM Booking chia cho bạn qua UPS List. Bạn thấy lead ở <strong>Khách hàng → Kho khách → tab Cá nhân</strong>.'],
                ['Gọi & cập nhật', 'Bấm <strong>Gọi điện</strong> → ghi trạng thái + note. Sửa được thông tin cá nhân khách (khác Sale — Sale ở phase sau không sửa được info khách).'],
                ['Đặt booking', 'Giống Team Sale.'],
                ['Chuyển sang Sale', 'Khi booking được duyệt và khách đã tới, hệ thống tự chuyển sang phòng Sale.'],
            ],
        ],
        'cm_sale' => [
            'icon' => '⬧',
            'name' => 'CM Sale',
            'summary' => 'Chia lead & duyệt',
            'intro' => 'Chia lead từ kho team xuống Sale. Duyệt thay đổi quan trọng. Xem báo cáo team.',
            'steps' => [
                ['Chia lead thủ công', 'Menu <strong>Khách hàng → Kho khách → tab Team</strong>. Filter cascade: Địa điểm → Cơ sở → Phòng ban. Tick chọn lead → <strong>Chia thủ công hàng loạt</strong> → chọn sale → OK.'],
                ['Chia tự động', 'Bấm <strong>Chia tự động</strong> (theo rule chia đã cấu hình).'],
                ['Áp dụng luật thu hồi PKD', 'Khi chia, có thể tick <strong>"Áp dụng luật thu hồi tự động"</strong> → sau 1 ngày sale không update 3 cột đầu → tự thu hồi.'],
                ['Duyệt lead', 'Menu <strong>Khách hàng → Duyệt lead</strong> → xem danh sách → OK / Từ chối.'],
                ['Rút lead về kho team', 'Menu <strong>Khách hàng → Kho khách → tab Cá nhân</strong> → tìm lead → bấm <strong>Thu hồi</strong>.'],
                ['Cấu hình rule chia', 'Menu <strong>Chia số → Rule</strong>. Tạo rule cascade → chọn Địa điểm/Cơ sở/Phòng ban → chọn chiến lược (round-robin / weighted).'],
            ],
        ],
        'cm_booking' => [
            'icon' => '📋',
            'name' => 'CM Booking / Tele',
            'summary' => 'Duyệt MKT & chia team',
            'intro' => 'Duyệt lead nhóm 1 (MKT/MKT BR/BDM) sau khi trực page up. Chia lead trong kho Booking cho Team Tele.',
            'steps' => [
                ['Duyệt lead MKT', 'Menu <strong>Khách hàng → Duyệt lead</strong> → tab Nguồn. Duyệt OK → lead vào kho Booking để chia tiếp.'],
                ['Chia thủ công / auto', 'Giống CM Sale nhưng phạm vi là Team Booking/Tele.'],
                ['Xem báo cáo phòng', 'Menu <strong>Báo cáo</strong> → chọn khoảng thời gian → xem funnel + top nhân viên.'],
            ],
        ],
        'admin' => [
            'icon' => '⚙️',
            'name' => 'DM & Admin',
            'summary' => 'Cấu hình toàn hệ thống',
            'intro' => 'Cấu hình toàn hệ thống. Xem báo cáo toàn khu vực/toàn công ty. Quản nhân sự, phân quyền, cây phòng ban, cây Kho số.',
            'steps' => [
                ['Cài đặt', 'Menu <strong>Cài đặt</strong>. Tab: Nhân viên · Vai trò &amp; Quyền · Cây phòng ban · Cây Kho số · Trường tùy chỉnh · Dịch vụ &amp; BS · Kết nối Booking · Rule chia số · SLA Policy.'],
                ['Kết nối 2 hệ (sbooking)', 'Cài đặt → <strong>Kết nối Booking</strong>. Bấm <strong>Sync Users</strong> (kéo users sbooking + auto-map). Bấm <strong>Sync Services / Sync Phòng / Sync BS</strong> để form booking có dropdown chuẩn.'],
                ['Reconcile drift', 'Nếu booking lệch dữ liệu: mở terminal chạy <code class="bg-slate-100 px-2 py-0.5 rounded text-xs">php artisan sb:reconcile-bookings --dry-run</code> để xem, rồi bỏ <code>--dry-run</code> để apply.'],
                ['Xuất danh sách khách', 'Menu <strong>Khách hàng → Danh sách</strong> → bấm <strong>⬇ Export</strong>. Mặc định CHỈ tick core columns (6 phase). Custom fields tick manual nếu cần.'],
                ['Observer / Trợ lý', 'Chỉ xem dashboard + báo cáo, không thêm/sửa/xóa.'],
            ],
        ],
    ];
@endphp

<main class="max-w-5xl mx-auto px-4 py-8" x-data="{ role: 'sale' }">

    <div class="text-center mb-8">
        <h1 class="text-2xl md:text-3xl font-extrabold text-gold-700 mb-2">Hướng dẫn sử dụng hệ thống Data Source</h1>
        <p class="text-sm md:text-base text-ink/60 max-w-2xl mx-auto">Chọn vai trò của bạn để xem hướng dẫn chi tiết về quyền hạn và luồng thao tác thường ngày.</p>
    </div>

    {{-- Chung — mọi vai trò --}}
    <section class="mb-8">
        <h2 class="text-lg font-bold text-gold-700 mb-3 flex items-center gap-2">
            <span class="w-1.5 h-6 bg-gold-600 rounded"></span>
            Chung — mọi vai trò
        </h2>
        <div class="bg-white border border-gold-200 rounded-xl shadow-sm p-5 text-sm text-ink/80 space-y-2">
            <p><strong>Đăng nhập:</strong> vào <em>{{ url('/login') }}</em>. Nhập email công ty + mật khẩu (BO cấp cho bạn).</p>
            <p><strong>Trang chủ:</strong> Dashboard có 5 ô hôm nay: UPS hôm nay · Khách mới · Khách bạn được nhận (7 ngày) · Chờ duyệt · Chờ chia. Hiển thị theo vai trò của bạn. Bấm ô để nhảy trang chi tiết.</p>
            <p><strong>Thông báo:</strong> Chuông góc phải nháy khi có lead mới / booking đổi trạng thái / tin nhắn. Bấm chuông xem chi tiết.</p>
            <p><strong>Đổi mật khẩu:</strong> Avatar góc phải → Đổi mật khẩu.</p>
        </div>
    </section>

    {{-- Sơ đồ luồng tổng quan --}}
    <section class="mb-8" x-data="{ zoom: false }">
        <h2 class="text-lg font-bold text-gold-700 mb-3 flex items-center gap-2">
            <span class="w-1.5 h-6 bg-gold-600 rounded"></span>
            Sơ đồ luồng tổng quan
        </h2>
        <div class="bg-white border border-gold-200 rounded-xl shadow-sm p-3">
            <button type="button" @click="zoom = true" class="block w-full">
                <img src="{{ asset('images/flow.jpg') }}" alt="Sơ đồ luồng hệ thống Data Source"
                     class="w-full h-auto rounded-lg cursor-zoom-in hover:opacity-95 transition"
                     onerror="this.style.display='none'; this.nextElementSibling.style.display='block'">
                <div style="display:none" class="text-center text-ink/40 text-sm py-8">(Chưa có sơ đồ luồng — cần tạo ảnh <code>public/images/flow.jpg</code>)</div>
            </button>
            <p class="text-xs text-ink/50 mt-2 text-center">Nhấn vào ảnh để xem full-size</p>
        </div>

        <div x-show="zoom" x-cloak @click="zoom = false" @keydown.escape.window="zoom = false"
             class="fixed inset-0 bg-black/80 z-50 flex items-center justify-center p-4 cursor-zoom-out">
            <img src="{{ asset('images/flow.jpg') }}" alt="Sơ đồ luồng" class="max-w-full max-h-full rounded-lg shadow-2xl">
        </div>
    </section>

    {{-- Ghi chú Quy tắc PKD - Thu hồi lead (mới) --}}
    <section class="mb-8">
        <h2 class="text-lg font-bold text-gold-700 mb-3 flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Quy tắc thu hồi lead (PKD Update 01/04/2026)
        </h2>
        <div class="bg-amber-50 border border-amber-300 rounded-lg p-5 text-sm text-ink/85 space-y-2">
            <p>Khi <strong>CM/Admin tick "Áp dụng luật thu hồi tự động"</strong> lúc chia lead cho sale:</p>
            <ul class="list-disc list-inside ml-2 space-y-1">
                <li>Sau <strong>1 ngày</strong>: sale phải cập nhật đủ <strong>3 cột đầu</strong> (<em>PAGE, Camp, Phân loại</em>). Không → hệ thống tự thu hồi lead về kho team.</li>
                <li>Sau <strong>3 ngày</strong>: sale phải cập nhật đủ <strong>5 cột</strong> (thêm <em>Kết quả, S.I.C</em>). Không → thu hồi.</li>
            </ul>
            <p class="text-xs text-ink/60 mt-2">Nếu CM không tick → lead giữ vĩnh viễn với sale được chia (không auto thu hồi).</p>
        </div>
    </section>

    {{-- Luồng đặt booking --}}
    <section class="mb-8">
        <h2 class="text-lg font-bold text-gold-700 mb-3 flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3.75 8.25h16.5M4.5 6h15a.75.75 0 01.75.75v12a.75.75 0 01-.75.75h-15a.75.75 0 01-.75-.75v-12A.75.75 0 014.5 6z"/></svg>
            Luồng "Đang tiếp đón / Hoàn tất"
        </h2>
        <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-5 text-sm text-ink/85 space-y-2">
            <p>Khi khách đến clinic → lễ tân sbooking bấm <strong>Khách đã tới</strong>:</p>
            <ol class="list-decimal list-inside ml-2 space-y-1">
                <li>Nếu UPS đã chốt → hệ thống <strong>tự động</strong> gán 1 sale từ Sale Tiếp Đón (theo thứ tự A→B→C→OFF).</li>
                <li>Sale vào hệ thống Booking, bấm <strong>Đang tiếp đón</strong> → hệ thống đánh dấu sale bận, khách tiếp theo chuyển cho sale khác.</li>
                <li>Sale tư vấn xong bấm <strong>Hoàn tất</strong> → sale rảnh lại, sẵn sàng nhận khách tiếp theo.</li>
            </ol>
        </div>
    </section>

    {{-- Đặc thù cơ sở Đà Nẵng --}}
    <section class="mb-8">
        <h2 class="text-lg font-bold text-gold-700 mb-3 flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 2C8 6 8 10 12 14c4-4 4-8 0-12zM12 22c-4-4-4-8 0-12"/></svg>
            Đặc thù cơ sở Đà Nẵng
        </h2>
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-5 text-sm text-ink/85">
            <p>Riêng sale khu vực Đà Nẵng được cấp quyền <strong>nhập lead – booking – tư vấn</strong> xuyên suốt (không tách vai trò).</p>
        </div>
    </section>

    {{-- 7 role tabs --}}
    <h2 class="text-lg font-bold text-gold-700 mb-3 flex items-center gap-2">
        <span class="w-1.5 h-6 bg-gold-600 rounded"></span>
        Hướng dẫn theo vai trò
    </h2>
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-2 md:gap-3 mb-6">
        @foreach ($roles as $key => $r)
            <button @click="role = '{{ $key }}'"
                    :class="role === '{{ $key }}' ? 'bg-gold-600 text-white shadow-lg scale-[1.02]' : 'bg-white text-ink hover:border-gold-400'"
                    class="border border-gold-200 rounded-xl px-3 py-3 text-left transition-all">
                <div class="text-xl md:text-2xl mb-1">{!! $r['icon'] !!}</div>
                <div class="font-bold text-xs md:text-sm leading-tight">{{ $r['name'] }}</div>
                <div class="text-[10px] md:text-xs mt-1 opacity-70 leading-tight">{{ $r['summary'] }}</div>
            </button>
        @endforeach
    </div>

    @foreach ($roles as $key => $r)
        <div x-show="role === '{{ $key }}'" x-cloak>
            <div class="bg-white border border-gold-200 rounded-2xl shadow-sm p-6 md:p-8 mb-6">
                <div class="flex items-center gap-3 mb-2">
                    <span class="text-2xl">{!! $r['icon'] !!}</span>
                    <h2 class="text-lg md:text-xl font-bold text-gold-700">{{ $r['name'] }}</h2>
                </div>
                <p class="text-sm text-ink/60 mb-6 leading-relaxed">{!! $r['intro'] !!}</p>

                <div class="space-y-5">
                    @foreach ($r['steps'] as $i => [$title, $desc])
                        <div class="flex gap-3 md:gap-4">
                            <div class="flex-shrink-0 w-8 h-8 md:w-9 md:h-9 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">{{ $i + 1 }}</div>
                            <div class="flex-1 min-w-0">
                                <h3 class="font-bold mb-1 text-sm">{{ $title }}</h3>
                                <p class="text-sm text-ink/70 leading-relaxed">{!! $desc !!}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endforeach

    <div class="text-center text-xs text-ink/40 mt-8 space-y-1">
        <p>Cần hỗ trợ thêm? Liên hệ quản trị viên hệ thống.</p>
        <p><a href="{{ route('qa') }}" class="text-gold-700 hover:underline">📋 Sang QA Checklist →</a></p>
    </div>
</main>

</body>
</html>
