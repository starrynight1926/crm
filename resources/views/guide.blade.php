<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hướng dẫn sử dụng — Longevity CRM</title>
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
    <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <span class="font-bold text-gold-700 text-lg tracking-tight">Longevity CRM</span>
            <span class="text-sm text-ink/50">Hướng dẫn sử dụng</span>
        </div>
        <a href="{{ route('login') }}" class="text-sm px-4 py-2 rounded-lg bg-gold-600 text-white hover:bg-gold-700 font-semibold">Đăng nhập hệ thống</a>
    </div>
</header>

@php
    $roles = [
        'cm' => [
            'icon' => '⬧',
            'name' => 'CM',
            'summary' => 'Clinic Manager — điều phối team',
            'intro' => 'Người quản lý một team hoặc phòng — chia lead cho nhân viên booking/sale trong team, duyệt lead khách tự đến, thu hồi lead khi cần điều phối lại. Gồm 2 vai chính: <strong>CM booking</strong> (điều phối kho booking, up lead Data lạnh/BDM) và <strong>CM sale</strong> (chia lead khách đã đồng ý sang sale, kiêm chia nguồn CTV theo khu vực).',
            'steps' => [
                ['Xem kho lead của team', 'Vào <strong>Chia số &gt; Kho lead</strong> — chọn tab <strong>Kho team</strong>. Filter theo nguồn (Marketing/Data lạnh/BDM) hoặc phân loại để tìm lead cần chia.'],
                ['Chia lead cho nhân viên', 'Tick chọn lead → bấm <strong>Chia thủ công</strong> chọn người nhận, hoặc <strong>Chia tự động</strong> chạy rule (round-robin, doanh thu, close rate).'],
                ['Đặt "Thu hồi sau XX ngày"', 'Khi chia, chọn dropdown: <strong>Mặc định</strong> (theo policy) / <strong>Tùy chọn N ngày</strong> / <strong>Chia vĩnh viễn</strong>. Hết hạn → lead tự về kho team để chia lại.'],
                ['Duyệt lead "Khách tự đến"', 'Vào <strong>Duyệt lead</strong> — xem lead nguồn walk_in nhân viên up lên. Bấm <strong>Duyệt</strong> để chuyển sang kho chia; <strong>Từ chối</strong> phải nhập lý do.'],
                ['Up lead Data lạnh / BDM (CM booking)', 'Vào <strong>Khách hàng &gt; Thêm mới</strong>, chọn nhóm nguồn Data lạnh/BDM. Có thể import file Excel qua <strong>Import</strong> cho batch lớn.'],
                ['Chia nguồn CTV (CM sale khu vực)', 'Ở form thêm lead, chọn nhóm nguồn <strong>Cộng tác viên</strong> → chia thẳng cho sale khu vực mình phụ trách (không qua kho booking).'],
                ['Thu hồi & chuyển người', 'Chi tiết lead → <strong>Thu hồi</strong> để đưa về kho team, hoặc <strong>Chuyển người phụ trách</strong> khi cần điều phối lại (VD sale nghỉ / quá SLA).'],
                ['Xem báo cáo team', 'Vào <strong>Báo cáo</strong> — xem funnel team, hiệu suất từng nhân viên, doanh thu, xếp hạng.'],
            ],
        ],
        'booking' => [
            'icon' => '📅',
            'name' => 'Booking',
            'summary' => 'Team booking & trực page',
            'intro' => 'Nhân viên tuyến đầu — up lead từ page marketing HOẶC gọi khách trong kho booking để chốt lịch gặp. Không đụng khâu chăm sóc dài hạn (sale làm). Gồm 2 vai: <strong>Team trực page</strong> (up lead Marketing từ inbox/comment) và <strong>Team booking</strong> (gọi khách trong kho booking, đặt lịch).',
            'steps' => [
                ['Up lead từ page (Team trực page)', 'Vào <strong>Khách hàng &gt; Thêm mới</strong>, chọn nhóm nguồn <strong>Marketing</strong>. Điền Tên/SĐT/PAGE/Camp/Insight/Link inbox. Sau khi lưu, lead tự vào kho booking cho CM booking chia.'],
                ['Import batch từ file', 'Nếu có danh sách tổng hợp từ inbox trong ngày, dùng <strong>Import</strong> Excel/CSV để bulk up thay vì nhập từng lead.'],
                ['Nhận lead & gọi khách (Team booking)', 'Chuông navbar báo khi có lead mới. Vào <strong>Khách hàng</strong> để xem list. Mở chi tiết → gọi số → cập nhật phân loại: <strong>Follow / Quan tâm / Missed / KLLD</strong>.'],
                ['Ghi nội dung cuộc gọi', 'Trong chi tiết lead → phần <strong>Ghi chú</strong> — viết tường minh: khách nói gì, hẹn giờ nào, cần chuẩn bị gì. Timeline lưu lại đầy đủ.'],
                ['Đặt lịch booking', 'Khách đồng ý gặp → bấm <strong>Đặt lịch booking</strong> → chọn <strong>Đã đặt</strong> (đúng lịch) hoặc <strong>Hẹn lại</strong> (khách xin dời). Lead chuyển sang trạng thái sẵn sàng cho CM sale chia.'],
                ['Từ chối / để lại kho', 'Khách không đồng ý gặp → không tick "Đã đặt", để lead ở kho booking. Quá X ngày hệ thống đánh dấu overdue (không auto-xóa).'],
                ['Xem lead đã xử lý', 'Vào <strong>Khách hàng</strong> → filter theo "người phụ trách = mình" để review công việc trong ngày/tuần.'],
            ],
        ],
        'sale' => [
            'icon' => '📞',
            'name' => 'Sale',
            'summary' => 'Chăm khách & chốt deal',
            'intro' => 'Nhân viên sale — nhận lead khách đã đồng ý gặp, chăm sóc qua funnel Booking → Show → Close. Ghi lịch sử phase dịch vụ, thu tiền, đóng deal. Chỉ thấy lead của chính mình. Team Leader là sale cấp trên có thêm quyền xem cả team.',
            'steps' => [
                ['Nhận lead qua thông báo', 'Chuông navbar báo <strong>"Bạn nhận N lead mới"</strong> — bấm để mở danh sách lead vừa được CM chia.'],
                ['Gọi khách & cập nhật phân loại', 'Chi tiết lead → đổi <strong>Phân loại kết quả</strong> theo funnel: Follow → Nét → Booking → Show → Close, hoặc rẽ nhánh KLLD/Missed/Gọi lại sau.'],
                ['Ghi note & lịch sử chăm', 'Bấm <strong>Ghi chú</strong> để thêm nội dung cuộc gọi. Mỗi lần đổi phân loại đều ghi timeline.'],
                ['Up lead "Bạn giới thiệu"', 'Có khách quen giới thiệu → <strong>Khách hàng &gt; Thêm mới</strong>, chọn nhóm nguồn <strong>Bạn giới thiệu</strong> → chọn chính mình làm sale nhận. Lead vào thẳng kho cá nhân.'],
                ['Up lead "Khách tự đến"', 'Khách tự đến clinic → chọn nhóm <strong>Khách tự đến</strong>. Lead vào trạng thái <strong>chờ CM duyệt</strong>, được duyệt thì CM chia lại.'],
                ['Gắn dịch vụ & theo dõi phase', 'Trong chi tiết KH, khối <strong>Dịch vụ & Tiến độ</strong> — chọn dịch vụ, chốt giá, tick từng phase khi làm xong, ghi note bàn giao cho người kế tiếp.'],
                ['Thu tiền & công nợ', 'Bấm <strong>Thu tiền</strong> ở khối dịch vụ → nhập số tiền, phương thức. Công nợ tự tính = giá chốt − đã thu.'],
                ['Đóng deal & % đóng góp', 'Đổi phân loại sang <strong>Close</strong> → popup % đóng góp tự mở. Chốt tỉ lệ giữa những người tham gia (thu thập / care 1 / care 2 / làm phase — tổng 100%).'],
                ['Xem báo cáo cá nhân', 'Vào <strong>Báo cáo</strong> — thấy số nhận, tỉ lệ close, doanh thu thực thu, xếp hạng của mình trong team.'],
            ],
        ],
        'observer' => [
            'icon' => '👁',
            'name' => 'Observer',
            'summary' => 'Xem — không chỉnh sửa',
            'intro' => 'Vai trò quan sát — xem toàn bộ dữ liệu và báo cáo trong phạm vi được cấp, không thêm/sửa/xóa/chia/thu hồi. Dùng cho ban giám đốc (CEO/COO), kế toán, kiểm soát, trợ lý theo dõi số liệu.',
            'steps' => [
                ['Xem danh sách khách', 'Vào <strong>Khách hàng</strong> — xem list lead trong phạm vi. Có filter theo ngày, nguồn, nhân viên, phân loại. SĐT hiện đầy đủ nếu có quyền <code>lead.view_phone</code>.'],
                ['Xem chi tiết & lịch sử', 'Mở chi tiết lead — đọc timeline chăm sóc, ghi chú, tình trạng dịch vụ, thu tiền, % đóng góp. Không có nút Sửa/Xóa/Chia.'],
                ['Xem dashboard tổng quan', 'Vào <strong>Dashboard</strong> — lead hôm nay, funnel tháng, top sale, lead chưa chăm/quá SLA. Cập nhật 1-3 phút/lần.'],
                ['Xem báo cáo chi tiết', 'Vào <strong>Báo cáo</strong> — 4 tab: <strong>Funnel</strong> (tỉ lệ chuyển đổi từng bước), <strong>Marketing</strong> (theo camp/nguồn/PAGE), <strong>Hiệu suất sale</strong> (xếp hạng, doanh thu), <strong>Chia số</strong> (log phân bổ, tồn kho).'],
                ['Lọc theo kỳ và phạm vi', 'Mỗi báo cáo có bộ lọc: khoảng thời gian, phòng ban, nhân viên, phân loại. Có quyền <code>report.view_all</code> thì thấy toàn scope; ngược lại chỉ thấy scope của mình.'],
                ['Export Excel (nếu được cấp quyền)', 'Bấm <strong>Xuất Excel</strong> ở báo cáo → file .xlsx. Mỗi lần export ghi audit log kèm loại báo cáo và khoảng ngày.'],
                ['Không có nút chỉnh sửa', 'Nav không hiện "Chia số", "Import", "Duyệt lead". Nếu truy cập trực tiếp URL sẽ bị 403. Cần thêm quyền tạm → nhờ Admin gán permission phù hợp.'],
            ],
        ],
    ];
@endphp

<main class="max-w-5xl mx-auto px-4 py-8" x-data="{ role: 'cm' }">

    <div class="text-center mb-8">
        <h1 class="text-3xl font-extrabold text-gold-700 mb-2">Hướng dẫn sử dụng hệ thống CRM</h1>
        <p class="text-ink/60 max-w-2xl mx-auto">Chọn vai trò của bạn để xem hướng dẫn chi tiết về quyền hạn và luồng thao tác thường ngày.</p>
    </div>

    {{-- Sơ đồ luồng tổng quan --}}
    <section class="mb-10" x-data="{ zoom: false }">
        <h2 class="text-lg font-bold text-gold-700 mb-3 flex items-center gap-2">
            <span class="w-1.5 h-6 bg-gold-600 rounded"></span>
            Sơ đồ luồng tổng quan
        </h2>
        <div class="bg-white border border-gold-200 rounded-xl shadow-card p-3">
            <button type="button" @click="zoom = true" class="block w-full">
                <img src="{{ asset('images/flow.jpg') }}" alt="Sơ đồ luồng hệ thống CRM"
                     class="w-full h-auto rounded-lg cursor-zoom-in hover:opacity-95 transition">
            </button>
            <p class="text-xs text-ink/50 mt-2 text-center">Nhấn vào ảnh để xem full-size</p>
        </div>

        {{-- Lightbox --}}
        <div x-show="zoom" x-cloak @click="zoom = false" @keydown.escape.window="zoom = false"
             class="fixed inset-0 bg-black/80 z-50 flex items-center justify-center p-4 cursor-zoom-out">
            <img src="{{ asset('images/flow.jpg') }}" alt="Sơ đồ luồng"
                 class="max-w-full max-h-full rounded-lg shadow-2xl">
        </div>
    </section>

    {{-- 4 role tabs --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-8">
        @foreach ($roles as $key => $r)
            <button @click="role = '{{ $key }}'"
                    :class="role === '{{ $key }}' ? 'bg-gold-600 text-white shadow-lg scale-[1.02]' : 'bg-white text-ink hover:border-gold-400'"
                    class="border border-gold-200 rounded-xl px-4 py-4 text-left transition-all">
                <div class="text-2xl mb-1">{!! $r['icon'] !!}</div>
                <div class="font-bold text-sm">{{ $r['name'] }}</div>
                <div class="text-xs mt-1 opacity-70">{{ $r['summary'] }}</div>
            </button>
        @endforeach
    </div>

    @foreach ($roles as $key => $r)
        <div x-show="role === '{{ $key }}'" x-cloak>
            <div class="bg-white border border-gold-200 rounded-2xl shadow-sm p-8 mb-6">
                <div class="flex items-center gap-3 mb-2">
                    <span class="text-2xl">{!! $r['icon'] !!}</span>
                    <h2 class="text-xl font-bold text-gold-700">{{ $r['name'] }}</h2>
                </div>
                <p class="text-sm text-ink/60 mb-6 leading-relaxed">{!! $r['intro'] !!}</p>

                <div class="space-y-6">
                    @foreach ($r['steps'] as $i => [$title, $desc])
                        <div class="flex gap-4">
                            <div class="flex-shrink-0 w-9 h-9 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">{{ $i + 1 }}</div>
                            <div class="flex-1">
                                <h3 class="font-bold mb-1 text-sm">{{ $title }}</h3>
                                <p class="text-sm text-ink/70 leading-relaxed">{!! $desc !!}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endforeach

    <div class="text-center text-xs text-ink/40 mt-8">
        Cần hỗ trợ thêm? Liên hệ quản trị viên hệ thống.
    </div>
</main>

</body>
</html>
