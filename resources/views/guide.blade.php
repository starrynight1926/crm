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
    // 2026-08-09 update — 7 role rút gọn theo scope thực tế.
    $roles = [
        'truc_page' => [
            'icon' => '📝',
            'name' => 'Trực Page',
            'summary' => 'Up lead MKT / MKT BR',
            'intro' => 'Nhập lead nguồn Marketing / Marketing BR. Hệ thống tự chia Tele sale theo UPS list.',
            'steps' => [
                ['Thêm mới', 'Bấm <strong>+ Thêm mới lead</strong>. Nhập <strong>Họ tên · SĐT · Nhóm nguồn · Phân loại · Kết quả</strong>. Bấm <strong>Lưu</strong>.'],
                ['Hệ thống tự chia', 'Sau khi lưu, hệ thống tự chia lead cho 1 Tele sale theo <strong>UPS list hôm nay</strong> (bucket MKT của cơ sở đã chọn). Không cần thao tác gì thêm.'],
            ],
        ],
        'bo' => [
            'icon' => '⚡',
            'name' => 'BO (Lễ Tân)',
            'summary' => 'Chốt UPS list đầu ngày',
            'intro' => 'Điểm danh sale hàng ngày để tạo UPS list — nguồn dữ liệu chia Tele sale và Sale tiếp đón.',
            'steps' => [
                ['Điểm danh sale', 'Menu <strong>Chia số → UPS check-in (BO)</strong>. Check-in từng sale, chọn <strong>vị trí</strong> (Tiếp đón / Tele) + <strong>bucket</strong> (A / B / C / OFF hoặc MKT).'],
                ['Chốt UPS list', 'Phải bấm <strong>"Chốt UPS list"</strong> ở cơ sở → hệ thống mới có dữ liệu để chia Tele sale và Sale tiếp đón. Chưa chốt → không sale nào nhận được khách.'],
            ],
        ],
        'cm' => [
            'icon' => '🎯',
            'name' => 'CM / Team Leader',
            'summary' => 'Sale + up nguồn admin',
            'intro' => 'Vai trò như một sale bình thường: up được nguồn <strong>MKT BR, SA</strong>. Ngoài ra được up thêm các nguồn: <strong>BDM · BOD · WI</strong>.',
            'steps' => [
                ['Nghiệp vụ sale', 'Chăm khách như sale thường: up nguồn <strong>MKT BR, SA</strong>, nhận lead qua UPS, đặt booking, tiếp đón, thu tiền.'],
                ['Up nguồn admin', 'Ngoài MKT BR + SA, được up thêm <strong>BDM · BOD · WI</strong>. Dropdown nguồn hiện đủ.'],
                ['Nhân viên Tele sale chia theo UPS', 'Nhân viên <strong>Tele sale</strong> được chia theo <strong>list UPS ngày hôm đó</strong>.'],
                ['Thu hồi khả nghi', 'Sale up lead SA/BA khả nghi → vào <strong>Chia số → Kho lead</strong> → bấm <strong>Thu hồi</strong> đưa lead về kho team.'],
            ],
        ],
        'tele' => [
            'icon' => '📞',
            'name' => 'Tele sale',
            'summary' => 'Gọi khách + book lịch',
            'intro' => 'Sale đang ở <strong>bucket Tele (MKT)</strong> theo UPS list hôm nay.',
            'steps' => [
                ['Được up khách', 'Up nguồn <strong>MKT BR, SA</strong>.'],
                ['Nhận khách', 'Nhận khách các nguồn <strong>MKT</strong> (từ Trực Page) và <strong>BA</strong> (do Sale tiếp đón up).'],
                ['Thu thập insight', 'Gọi khách, ghi note, cập nhật đầy đủ thông tin + trường bổ sung.'],
                ['Tạo booking', 'Bấm <strong>+ Thêm booking</strong>. Chọn Cơ sở · Phòng · Dịch vụ · Bác sĩ · Khung giờ. <strong>Nhân viên sale tiếp đón được phân tự động</strong>.'],
            ],
        ],
        'sale' => [
            'icon' => '🤝',
            'name' => 'Sale tiếp đón (Booking)',
            'summary' => 'Đón khách tại cơ sở',
            'intro' => 'Sale đang ở <strong>bucket Tiếp đón (A/B/C/OFF)</strong> theo UPS list hôm nay.',
            'steps' => [
                ['Được up khách', 'Up nguồn <strong>MKT BR, BA</strong>.'],
                ['Nhận khách', 'Nhận khách từ <strong>tất cả các nguồn</strong> — được phân tự động khi Tele tạo booking hoặc khi khách đến check-in.'],
                ['Khách tới → bấm "Khách đã tới"', 'Khi khách đến cơ sở, bấm <strong>"Khách đã tới"</strong> trên booking để bắt đầu tiếp đón.'],
                ['Tư vấn khách', 'Tiếp đón, tư vấn dịch vụ.'],
                ['Hoàn tất', 'Khi khách sử dụng xong dịch vụ, bấm <strong>"Đã hoàn thành"</strong>.'],
                ['⚠️ Lưu ý', '<span class="text-red-700 font-semibold">Không bấm "Đã hoàn thành" sẽ chỉ nhận khách xoay tua (không được ưu tiên).</span>'],
            ],
        ],
        'admin' => [
            'icon' => '⚙️',
            'name' => 'DM / Admin',
            'summary' => 'Toàn quyền',
            'intro' => 'Toàn quyền cấu hình hệ thống, quản nhân sự, phân quyền, dịch vụ, rule chia số, sync sbooking, báo cáo.',
            'steps' => [
                ['Toàn quyền', 'Truy cập mọi menu, mọi thao tác. Không có giới hạn scope.'],
            ],
        ],
        'observer' => [
            'icon' => '👁️',
            'name' => 'Observer / Moderator',
            'summary' => 'Chỉ xem',
            'intro' => 'Chỉ xem danh sách khách + xem báo cáo. Không tạo / sửa / xóa / chia số / thu tiền.',
            'steps' => [
                ['Xem danh sách khách', 'Menu <strong>Khách hàng → Danh sách</strong> — chỉ xem, không có nút hành động.'],
                ['Xem báo cáo', 'Menu <strong>Báo cáo</strong> — funnel, top nhân viên, doanh số.'],
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
            <p><strong>Đăng nhập:</strong> vào <em>{{ url('/login') }}</em>. Nhập email công ty + mật khẩu (IT cấp cho bạn).</p>
            <p><strong>Trang chủ:</strong> Dashboard có 3 ô hôm nay: <strong>Số lead hôm nay</strong> · <strong>Lead Tele sale</strong> · <strong>Lead Booking</strong>. Hiển thị theo vai trò của bạn. Bấm ô để nhảy trang chi tiết.</p>
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
            <p>Mặc định lead chia cho Tele sale sẽ <strong>auto thu hồi</strong> nếu không update đúng SLA:</p>
            <ul class="list-disc list-inside ml-2 space-y-1">
                <li>Sau <strong>1 ngày</strong>: Tele sale phải cập nhật đủ <strong>3 cột đầu</strong> (<em>PAGE, Camp, Phân loại</em>). Không → hệ thống tự thu hồi lead về kho team.</li>
                <li>Sau <strong>3 ngày</strong>: Tele sale phải cập nhật đủ <strong>5 cột</strong> (thêm <em>Kết quả, S.I.C</em>). Không → thu hồi.</li>
            </ul>
            <p class="text-xs text-ink/60 mt-2">Nếu <strong>CM/Team Leader tick "Không thu hồi"</strong> khi chia → lead đó không áp dụng quy định trên.</p>
        </div>
    </section>

    {{-- Luồng đặt booking --}}
    <section class="mb-8">
        <h2 class="text-lg font-bold text-gold-700 mb-3 flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3.75 8.25h16.5M4.5 6h15a.75.75 0 01.75.75v12a.75.75 0 01-.75.75h-15a.75.75 0 01-.75-.75v-12A.75.75 0 014.5 6z"/></svg>
            Luồng "Đang tiếp đón / Hoàn tất"
        </h2>
        <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-5 text-sm text-ink/85 space-y-2">
            <p>Khi khách đến cơ sở → lễ tân truy cập phần mềm Booking bấm <strong>"Khách đã tới"</strong> (hoặc <em>Khách tới trễ</em>, <em>Khách hủy</em>…):</p>
            <ol class="list-decimal list-inside ml-2 space-y-1">
                <li>Nếu UPS đã chốt → hệ thống <strong>tự động</strong> gán 1 sale từ Sale Tiếp Đón (theo thứ tự A→B→C→OFF).</li>
                <li>Sale tiếp đón vào hệ thống Booking, bấm <strong>Đang tiếp đón</strong> → hệ thống đánh dấu sale bận, khách tiếp theo chuyển cho sale kế tiếp.</li>
                <li>Sale tiếp đón tư vấn xong bấm <strong>"Hoàn tất"</strong> để tiếp tục đón khách tiếp theo.</li>
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
            <p>Riêng cơ sở Đà Nẵng, mọi sale sẽ đảm nhiệm full 4 khâu <strong>"Tạo – Tele – Book – Checkin"</strong>.</p>
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
