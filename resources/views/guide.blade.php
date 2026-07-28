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
    <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <span class="font-bold text-gold-700 text-lg tracking-tight">Longevity Data Source</span>
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
            'intro' => 'Người quản lý một team hoặc phòng — chia lead cho nhân viên booking/sale trong team, duyệt lead walk-in, thu hồi lead khi cần điều phối lại. Gồm 2 vai chính: <strong>CM booking</strong> (điều phối kho booking, up lead nhóm 1 MKT / MKT BR / BDM) và <strong>CM sale</strong> (chia lead khách đã đồng ý sang sale, nhận trực tiếp các nguồn BOD / SA / BA / WI).',
            'steps' => [
                ['Xem kho lead của team', 'Vào <strong>Chia số &gt; Kho lead</strong> — chọn tab <strong>Kho team</strong>. Filter theo nguồn (MKT / MKT BR / BDM …) hoặc phân loại để tìm lead cần chia.'],
                ['Chia lead cho nhân viên', 'Tick chọn lead → bấm <strong>Chia thủ công</strong> chọn người nhận, hoặc <strong>Chia tự động</strong> chạy rule (round-robin, doanh thu, close rate).'],
                ['Đặt "Thu hồi sau XX ngày"', 'Khi chia, chọn dropdown: <strong>Mặc định</strong> (theo policy) / <strong>Tùy chọn N ngày</strong> / <strong>Chia vĩnh viễn</strong>. Hết hạn → lead tự về kho team để chia lại.'],
                ['Duyệt lead Walk-in', 'Vào <strong>Duyệt lead</strong> — xem lead nguồn WI nhân viên up lên. Bấm <strong>Duyệt</strong> để chuyển sang kho chia; <strong>Từ chối</strong> phải nhập lý do.'],
                ['Up lead nhóm 1 (CM booking)', 'Vào <strong>Khách hàng &gt; Thêm mới</strong>, chọn nhóm nguồn MKT / MKT BR / BDM. Có thể import file Excel qua <strong>Import</strong> cho batch lớn.'],
                ['Thu hồi & chuyển người', 'Chi tiết lead → <strong>Thu hồi</strong> để đưa về kho team, hoặc <strong>Chuyển người phụ trách</strong> khi cần điều phối lại (VD sale nghỉ / quá SLA).'],
                ['Xem báo cáo team', 'Vào <strong>Báo cáo</strong> — xem funnel team, hiệu suất từng nhân viên, doanh thu, xếp hạng.'],
            ],
        ],
        'booking' => [
            'icon' => '📅',
            'name' => 'Booking',
            'summary' => 'Team booking & trực page',
            'intro' => 'Nhân viên tuyến đầu — up lead từ page marketing HOẶC gọi khách trong kho booking để chốt lịch gặp. Không đụng khâu chăm sóc dài hạn (sale làm). Gồm 2 vai: <strong>Team nhập lead</strong> (up lead nguồn MKT / MKT BR / BDM từ inbox/comment) và <strong>Team booking</strong> (gọi khách trong kho booking, đặt lịch).',
            'steps' => [
                ['Up lead từ page (Team nhập lead)', 'Vào <strong>Khách hàng &gt; Thêm mới</strong>, chọn nhóm nguồn <strong>MKT</strong> (hoặc MKT BR / BDM). Điền Tên/SĐT/PAGE/Camp/Insight/Link inbox. Sau khi lưu, lead tự vào kho booking cho CM booking chia.'],
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
                ['Up lead nhóm 2 (BOD / SA / BA)', 'Có khách do Ban lãnh đạo giới thiệu (BOD), cuộc hẹn sale (SA) hoặc cuộc hẹn booking (BA) → <strong>Khách hàng &gt; Thêm mới</strong>, chọn đúng mã nguồn → chọn chính mình làm sale nhận. Lead vào thẳng kho cá nhân.'],
                ['Up lead Walk-in (WI)', 'Khách tự đến clinic → chọn nhóm <strong>Walk-in</strong>. Lead vào trạng thái <strong>chờ CM duyệt</strong>, được duyệt thì CM chia lại.'],
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
        <h1 class="text-3xl font-extrabold text-gold-700 mb-2">Hướng dẫn sử dụng hệ thống Data Source</h1>
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
                <img src="{{ asset('images/flow.jpg') }}" alt="Sơ đồ luồng hệ thống Data Source"
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

    {{-- Ghi chú Thu hồi lead / Escalate — đồng bộ với "Vận hành › Quy tắc vận hành" --}}
    <section class="mb-10">
        <h2 class="text-lg font-bold text-gold-700 mb-3 flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Thu hồi lead & Escalate
        </h2>
        <p class="text-xs text-ink/50 mb-3">Nội dung dưới đây trùng khớp với hộp hướng dẫn ở <em>Vận hành › Quy tắc vận hành › Thời gian recall/escalate</em>. Sửa 1 chỗ nhớ sửa cả 2.</p>
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-5 space-y-4 text-sm">
            <div>
                <h3 class="font-bold text-amber-900 mb-1">🔁 Điều kiện kích hoạt "Thu hồi lead"</h3>
                <ul class="list-disc list-inside space-y-1 text-ink/80">
                    <li>Sau khoảng thời gian <strong>X ngày</strong> (do trưởng bộ phận quy định trong tab "Thời gian thu hồi/escalate") mà lead <strong>không đáp ứng yêu cầu</strong> → hệ thống tự thu hồi về kho team vào <strong>00:30 sáng</strong> hôm sau, để CM hoặc người có quyền phân bổ (<code>lead.distribute</code>) chia lại cho sale khác.</li>
                    <li><strong>Điều kiện bổ sung</strong> (tick khi Sửa policy): chỉ thu hồi nếu THỎA HẾT các điều kiện đã tick:
                        <ul class="list-disc list-inside ml-4 mt-1">
                            <li><em>Không cập nhật trường nào</em>: sale chưa động vào lead từ lúc chia.</li>
                            <li><em>Chưa đặt lịch booking</em>: <code>booking_status</code> không phải booked/khách_đã_tới/tới_trễ/đã_xong.</li>
                            <li><em>Chưa tiến triển phân loại</em>: vẫn ở Mới / Lead / Missed / Gọi lại sau / KLLD.</li>
                        </ul>
                    </li>
                    <li>Không tick điều kiện nào → chỉ dùng deadline. Có tick → deadline hết vẫn có thể bỏ qua nếu sale đang cày.</li>
                    <li>CM tick <strong>"Chia vĩnh viễn"</strong> ở form chia → hoàn toàn miễn thu hồi.</li>
                    <li>Team con không cấu hình → thừa hưởng từ ancestor gần nhất (ancestor cấp cao có cấu hình sẽ thắng).</li>
                </ul>
            </div>
            <div>
                <h3 class="font-bold text-amber-900 mb-1">⬆️ Escalate</h3>
                <ul class="list-disc list-inside space-y-1 text-ink/80">
                    <li>Lead bị thu hồi về kho team mà không ai nhận tiếp trong <strong>M ngày</strong> → cron <code>leads:process-escalates</code> (chạy 02:00 hằng ngày) chuyển sang <strong>org cha trực tiếp</strong> (ancestor).</li>
                    <li>Không phải "kho trước đó theo lịch sử" — mà là <strong>đi lên 1 tầng</strong> trong cây tổ chức (team → phòng → cơ sở → công ty).</li>
                    <li>Ví dụ: <code>team-ashley-sale</code> → <code>team-ashley</code> → <code>marketing-hcm</code> → <code>branch-hcm</code> → <code>company</code>.</li>
                    <li>Root (Công ty) → dừng, không escalate được nữa.</li>
                </ul>
            </div>
            <div>
                <h3 class="font-bold text-amber-900 mb-1">🌊 Job cron riêng: Thu hồi lead booking idle (miền Nam)</h3>
                <ul class="list-disc list-inside space-y-1 text-ink/80">
                    <li>Áp dụng cho lead phase Booking đã có owner. Sau N ngày (mặc định 1) mà <strong>không update</strong> + <strong>không có lịch đặt</strong> → tự thu hồi về kho team booking.</li>
                    <li>Khác với <code>recall_at</code> ở trên: cơ chế này <strong>không phụ thuộc</strong> CM tick gì, chạy dựa trên activity + booking_status.</li>
                    <li>Bật/tắt + đổi số ngày ở tab <em>Vận hành › Quy tắc vận hành › Job cron</em>.</li>
                </ul>
            </div>
        </div>
    </section>

    {{-- Luồng đặt booking (CRM → sbooking → callback) --}}
    <section class="mb-10">
        <h2 class="text-lg font-bold text-gold-700 mb-3 flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3.75 8.25h16.5M4.5 6h15a.75.75 0 01.75.75v12a.75.75 0 01-.75.75h-15a.75.75 0 01-.75-.75v-12A.75.75 0 014.5 6z"/></svg>
            Luồng đặt booking (CRM ↔ sbooking)
        </h2>
        <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-5 text-sm space-y-4">
            <p>Nút <strong>"Đặt booking"</strong> ở chi tiết lead <em>không</em> tạo booking bên CRM — nó nhảy sang hệ thống <strong>lara-sbooking</strong> để sale tạo lịch, sau đó sbooking tự đẩy kết quả về CRM. Chi tiết:</p>

            <ol class="list-decimal list-inside space-y-2 text-ink/85">
                <li>
                    <strong>Trong CRM</strong> — vào chi tiết lead → bấm <strong>Đặt booking</strong> → dropdown chọn:
                    <ul class="list-disc list-inside ml-6 mt-1 text-ink/70">
                        <li>🏥 <em>Đặt phòng khám</em> (khám lâm sàng / tư vấn)</li>
                        <li>💆 <em>Đặt dịch vụ</em> (xông hơi, YHCT, các dịch vụ khác)</li>
                    </ul>
                </li>
                <li>
                    <strong>Tab mới mở sang sbooking</strong> — URL đã đính kèm sẵn <code>ho_ten</code>, <code>so_dien_thoai</code>, <code>khach_ma</code>, <code>return_url</code>. Sale <strong>không phải gõ lại</strong> tên/SĐT.
                    <ul class="list-disc list-inside ml-6 mt-1 text-ink/70">
                        <li>Nếu chưa login sbooking → nhập tài khoản (mỗi cơ sở dùng username riêng, pass mặc định <code>123456</code>).</li>
                        <li>Cơ sở nào <strong>chưa map slug</strong> sang sbooking → nút hiện xám "Đặt booking (chưa map cơ sở)" — admin vào <em>Cài đặt › Kết nối Booking</em> nhập slug.</li>
                    </ul>
                </li>
                <li>
                    <strong>Sale bên sbooking</strong> — chọn ngày/giờ, bác sĩ (form phòng khám) hoặc kỹ thuật viên/phòng (form dịch vụ), điền ghi chú nếu cần → bấm <strong>Xác nhận</strong>. Sbooking tạo booking, sinh <code>booking_ma</code> (vd <code>HN-2607-001</code>).
                </li>
                <li>
                    <strong>Sbooking redirect về CRM</strong> — bằng <code>return_url</code> đã đính, kèm query <code>booking_ma</code> và <code>booking_id</code>. Endpoint CRM (<code>BookingCallbackController</code>) tự động cập nhật lead:
                    <ul class="list-disc list-inside ml-6 mt-1 text-ink/70">
                        <li><code>booking_status = booked</code>, <code>booked_at = now()</code>, lưu <code>booking_ma</code>.</li>
                        <li><code>classification = booking</code> (nếu trước đó chưa phải).</li>
                        <li>Ghi Lịch sử tương tác: "Đã đặt booking XXX bên hệ thống Booking.".</li>
                        <li>Bắn thông báo <em>lead.booked</em> tới owner + role được cấu hình trong <em>Cài đặt › Thiết lập thông báo</em>.</li>
                    </ul>
                </li>
                <li>
                    <strong>Đồng bộ ngược khi lỡ đặt trực tiếp trên sbooking</strong> — nếu sale tạo booking bên sbooking mà không qua nút CRM (vd. tạo cho khách khác), bấm nút <strong>Đồng bộ từ Booking</strong> ở chi tiết lead → CRM gọi API sbooking bằng SĐT, lấy booking mới nhất và cập nhật giống flow callback ở bước 4.
                </li>
            </ol>

            <p class="text-xs text-ink/60 italic">Lưu ý: quyền bấm nút này gắn với <code>lead.book_action</code>. Team nhập lead không có quyền này — chỉ team booking / team ĐN có.</p>
        </div>
    </section>

    {{-- Đặc thù cơ sở Đà Nẵng --}}
    <section class="mb-10">
        <h2 class="text-lg font-bold text-gold-700 mb-3 flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 2C8 6 8 10 12 14c4-4 4-8 0-12zM12 22c-4-4-4-8 0-12"/></svg>
            Đặc thù cơ sở Đà Nẵng
        </h2>
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-5 text-sm space-y-3">
            <p><strong>Cơ sở Đà Nẵng chưa có team booking riêng</strong> — cả đội sale (role <code>Team sale ĐN</code>) làm <strong>xuyên suốt cả 3 giai đoạn</strong>:</p>
            <ol class="list-decimal list-inside space-y-1.5 text-ink/80">
                <li><strong>Tự up số</strong>: sale nhập lead mới (nguồn BOD / SA / BA / Walk-in / các nguồn khác nếu có quyền), lead auto-owner = chính sale đó.</li>
                <li><strong>Tự book</strong>: bấm nút "Đặt booking" bên chi tiết khách → mở form lara-sbooking (login username=<code>ltkhi</code>/<code>ntb</code>/…, pass <code>123456</code>) → tạo lịch. Callback tự cập nhật <code>booking_status</code> + phân loại "Booking".</li>
                <li><strong>Tự điền quá trình sale</strong>: sau khi khách đã tới → ghi chú Lịch sử tương tác, đổi phân loại (Show/Close), gắn dịch vụ, ghi doanh thu.</li>
            </ol>
            <p>Kim Phấn (CM) kiêm cả CM Sale + CM Booking để duyệt/phân bổ khi cần. Team này có full quyền (<code>lead.read_booking</code> + <code>lead.update_booking</code> + <code>lead.book_action</code> + <code>lead.update_sale</code>) — không cần override phân quyền.</p>
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
