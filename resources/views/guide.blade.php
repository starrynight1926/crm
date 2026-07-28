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
            'summary' => 'Điều phối team',
            'intro' => 'Chia lead cho nhân viên trong team và duyệt lead walk-in.',
            'steps' => [
                ['Chia lead cho nhân viên', 'Menu <strong>Chia số → Kho lead</strong> → tick chọn lead → bấm <strong>Chia thủ công</strong> hoặc <strong>Chia tự động</strong>.'],
                ['Duyệt lead Walk-in', 'Menu <strong>Duyệt lead</strong> → bấm <strong>Duyệt</strong> hoặc <strong>Từ chối</strong>.'],
                ['Xem thông báo mới', 'Chuông ở góc phải trên navbar — báo lead mới cần chia hoặc lead chờ duyệt.'],
                ['Xem báo cáo team', 'Menu <strong>Báo cáo</strong> — chọn mẫu <strong>Hiệu suất sale</strong> hoặc <strong>Funnel</strong>.'],
            ],
        ],
        'booking' => [
            'icon' => '📅',
            'name' => 'Booking',
            'summary' => 'Nhập lead & đặt lịch',
            'intro' => 'Nhập lead từ page marketing, gọi khách và đặt lịch hẹn.',
            'steps' => [
                ['Nhập lead mới', 'Menu <strong>Khách hàng → Thêm mới</strong>. Điền: <strong>Tên khách, SĐT, Nguồn</strong> (MKT / MKT BR / BDM), <strong>Page, Camp, Link inbox</strong>. Bấm <strong>Lưu</strong>.'],
                ['Xem thông báo mới', 'Chuông ở góc phải trên navbar — báo có lead mới CM vừa chia cho mình.'],
                ['Cập nhật khi gọi khách', 'Mở chi tiết lead → điền <strong>Phân loại</strong> (Follow / Quan tâm / Missed / KLLD) → ghi <strong>Ghi chú cuộc gọi</strong>.'],
                ['Đặt lịch hẹn', 'Chi tiết lead → bấm <strong>Đặt booking</strong> → chọn ngày giờ, bác sĩ → <strong>Xác nhận</strong>. Trạng thái tự đổi thành "Đã đặt".'],
            ],
        ],
        'sale' => [
            'icon' => '📞',
            'name' => 'Sale',
            'summary' => 'Chăm khách & chốt deal',
            'intro' => 'Nhận lead khách đã đồng ý gặp, chăm sóc đến khi chốt hợp đồng.',
            'steps' => [
                ['Xem thông báo mới', 'Chuông ở góc phải trên navbar — báo "Bạn nhận N lead mới" khi CM vừa chia.'],
                ['Cập nhật khi chăm khách', 'Chi tiết lead → đổi <strong>Phân loại</strong> (Follow → Nét → Booking → Show → Close) → ghi <strong>Ghi chú</strong>.'],
                ['Gắn dịch vụ khách mua', 'Chi tiết lead → khối <strong>Dịch vụ</strong> → chọn dịch vụ, nhập giá chốt.'],
                ['Thu tiền', 'Khối Dịch vụ → bấm <strong>Thu tiền</strong> → nhập số tiền, phương thức.'],
                ['Chốt Close & chia % đóng góp', 'Đổi phân loại sang <strong>Close</strong> → popup % đóng góp mở → nhập tỉ lệ giữa những người tham gia (tổng 100%).'],
            ],
        ],
        'observer' => [
            'icon' => '👁',
            'name' => 'Observer',
            'summary' => 'Chỉ xem báo cáo',
            'intro' => 'Xem dữ liệu và báo cáo, không thêm/sửa/xóa.',
            'steps' => [
                ['Xem dashboard', 'Vào <a href="/dashboard" class="text-gold-700 underline">/dashboard</a> — tổng quan lead, funnel, top sale.'],
                ['Xem báo cáo', 'Vào <a href="/reports" class="text-gold-700 underline">/reports</a> — chọn 1 trong các mẫu:
                    <ul class="list-disc list-inside ml-4 mt-1 text-ink/70">
                        <li><strong>Funnel</strong> — tỉ lệ chuyển đổi</li>
                        <li><strong>Hiệu quả marketing</strong> — theo camp / nguồn / Page</li>
                        <li><strong>Hiệu suất sale</strong> — xếp hạng, doanh thu</li>
                        <li><strong>Chia số & tồn kho</strong></li>
                        <li><strong>Chi tiết lead</strong></li>
                    </ul>'],
                ['Xuất Excel', 'Ở mỗi báo cáo, bấm <strong>Xuất Excel</strong> góc trên phải.'],
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

    {{-- Ghi chú Thu hồi lead — giải thích ngắn gọn cho người dùng cuối --}}
    <section class="mb-10">
        <h2 class="text-lg font-bold text-gold-700 mb-3 flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Thu hồi lead
        </h2>
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-5 text-sm text-ink/85">
            <p>Sau khoảng thời gian <strong>X ngày</strong>, nếu lead <strong>không chuyển thành booking</strong> hoặc <strong>không có tiến triển</strong> thì hệ thống sẽ tự <strong>thu hồi về kho</strong> — gỡ khỏi sale đang được chia hiện tại — để CM chia lại cho người khác.</p>
            <p class="text-xs text-ink/50 mt-2">Thời gian X và điều kiện cụ thể do CM cấu hình tại <em>Vận hành › Quy tắc vận hành</em>.</p>
        </div>
    </section>

    {{-- Luồng đặt booking (CRM → sbooking → callback) --}}
    <section class="mb-10">
        <h2 class="text-lg font-bold text-gold-700 mb-3 flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3.75 8.25h16.5M4.5 6h15a.75.75 0 01.75.75v12a.75.75 0 01-.75.75h-15a.75.75 0 01-.75-.75v-12A.75.75 0 014.5 6z"/></svg>
            Đặt lịch booking
        </h2>
        <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-5 text-sm text-ink/85">
            <p>Trong chi tiết khách → bấm <strong>Đặt booking</strong> → chọn ngày giờ, bác sĩ hoặc kỹ thuật viên → <strong>Xác nhận</strong>. Trạng thái khách tự đổi sang <strong>"Đã đặt"</strong>.</p>
        </div>
    </section>

    {{-- Đặc thù cơ sở Đà Nẵng --}}
    <section class="mb-10">
        <h2 class="text-lg font-bold text-gold-700 mb-3 flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 2C8 6 8 10 12 14c4-4 4-8 0-12zM12 22c-4-4-4-8 0-12"/></svg>
            Đặc thù cơ sở Đà Nẵng
        </h2>
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-5 text-sm text-ink/85">
            <p>Riêng sale khu vực Đà Nẵng đang được cấp quyền <strong>nhập lead – booking – tư vấn</strong>.</p>
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
