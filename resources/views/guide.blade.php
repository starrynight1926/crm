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

<main class="max-w-6xl mx-auto px-4 py-8" x-data="{ role: 'admin_vh' }">

    {{-- Intro --}}
    <div class="text-center mb-10">
        <h1 class="text-3xl font-extrabold text-gold-700 mb-2">Hướng dẫn sử dụng hệ thống CRM</h1>
        <p class="text-ink/60 max-w-2xl mx-auto">Chọn vai trò của bạn để xem hướng dẫn chi tiết về quyền hạn và cách thao tác trong hệ thống.</p>
    </div>

    {{-- Role selector --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-10">
        <button @click="role = 'admin_vh'"
                :class="role === 'admin_vh' ? 'bg-gold-600 text-white shadow-lg scale-[1.02]' : 'bg-white text-ink hover:border-gold-400'"
                class="border border-gold-200 rounded-xl px-4 py-4 text-left transition-all">
            <div class="text-2xl mb-1">&#9881;</div>
            <div class="font-bold text-sm">Admin vận hành</div>
            <div class="text-xs mt-1 opacity-70">Chia số & điều phối trong phòng ban</div>
        </button>
        <button @click="role = 'nv_tele'"
                :class="role === 'nv_tele' ? 'bg-gold-600 text-white shadow-lg scale-[1.02]' : 'bg-white text-ink hover:border-gold-400'"
                class="border border-gold-200 rounded-xl px-4 py-4 text-left transition-all">
            <div class="text-2xl mb-1">&#128222;</div>
            <div class="font-bold text-sm">Nhân viên telesale</div>
            <div class="text-xs mt-1 opacity-70">Nhập tay & cập nhật tình trạng khách</div>
        </button>
        <button @click="role = 'nv_dl'"
                :class="role === 'nv_dl' ? 'bg-gold-600 text-white shadow-lg scale-[1.02]' : 'bg-white text-ink hover:border-gold-400'"
                class="border border-gold-200 rounded-xl px-4 py-4 text-left transition-all">
            <div class="text-2xl mb-1">&#128196;</div>
            <div class="font-bold text-sm">NV sale data lạnh</div>
            <div class="text-xs mt-1 opacity-70">Upload dữ liệu & cập nhật khách</div>
        </button>
        <button @click="role = 'gs_vh'"
                :class="role === 'gs_vh' ? 'bg-gold-600 text-white shadow-lg scale-[1.02]' : 'bg-white text-ink hover:border-gold-400'"
                class="border border-gold-200 rounded-xl px-4 py-4 text-left transition-all">
            <div class="text-2xl mb-1">&#128202;</div>
            <div class="font-bold text-sm">Giám sát & vận hành</div>
            <div class="text-xs mt-1 opacity-70">Xem thông tin & báo cáo cơ sở</div>
        </button>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    {{-- ADMIN VẬN HÀNH --}}
    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    <div x-show="role === 'admin_vh'" x-cloak>
        <div class="bg-white border border-gold-200 rounded-2xl shadow-sm p-8 mb-6">
            <h2 class="text-xl font-bold text-gold-700 mb-1">Admin vận hành</h2>
            <p class="text-sm text-ink/60 mb-6">Chia số, chuyển số cho các cá nhân <strong>trong khuôn khổ phòng ban mình phụ trách</strong>. Admin phòng marketing không được chia cho phòng khác — chỉ thấy dữ liệu đơn vị mình quản lý.</p>

            <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-8">
                <div class="flex items-start gap-2">
                    <span class="text-red-500 text-lg">&#9888;</span>
                    <div class="text-sm text-red-800">
                        <strong>Giới hạn phạm vi:</strong> Bạn chỉ thao tác được trên dữ liệu và nhân sự thuộc phòng ban mình phụ trách. Dữ liệu các đơn vị khác sẽ không hiển thị.
                    </div>
                </div>
            </div>

            <div class="space-y-8">
                {{-- Bước 1 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">1</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Chia số cho nhân viên</h3>
                        <p class="text-sm text-ink/70 mb-2">Vào <strong>Chia số</strong> — xem danh sách lead trong kho phòng ban mình, chọn lead và gán cho nhân viên cụ thể.</p>
                        <div class="bg-gold-50 border border-gold-100 rounded-lg p-3 text-sm">
                            <div class="font-semibold text-gold-700 mb-1">Các cách chia:</div>
                            <ul class="text-ink/70 text-xs space-y-0.5 list-disc list-inside">
                                <li><strong>Chia thủ công:</strong> chọn lead → chọn nhân viên → xác nhận</li>
                                <li><strong>Chia tự động theo quy tắc:</strong> hệ thống tự phân bổ theo round-robin, tỉ trọng, hoặc doanh thu</li>
                            </ul>
                        </div>
                    </div>
                </div>

                {{-- Bước 2 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">2</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Chuyển số giữa các cá nhân</h3>
                        <p class="text-sm text-ink/70">Chuyển lead từ nhân viên A sang nhân viên B trong phòng ban mình. Vào chi tiết lead → bấm <strong>"Chuyển người phụ trách"</strong> → chọn nhân viên mới. Chỉ hiển thị nhân viên thuộc phòng ban bạn quản lý.</p>
                    </div>
                </div>

                {{-- Bước 3 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">3</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Thu hồi lead</h3>
                        <p class="text-sm text-ink/70">Nếu nhân viên không chăm sóc kịp SLA hoặc cần điều phối lại, bạn có thể <strong>thu hồi lead</strong> về kho phòng ban để chia lại cho người khác.</p>
                    </div>
                </div>

                {{-- Bước 4 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">4</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Xem kho lead phòng ban</h3>
                        <p class="text-sm text-ink/70 mb-2">Vào <strong>Kho lead</strong> — xem lead đang ở các cấp kho trong phòng ban mình:</p>
                        <div class="bg-gold-50 border border-gold-100 rounded-lg p-3 text-sm">
                            <ul class="text-ink/70 text-xs space-y-0.5 list-disc list-inside">
                                <li><strong>Kho team:</strong> lead đã được chia về team nhưng chưa gán cá nhân</li>
                                <li><strong>Kho cá nhân:</strong> lead đã gán cho từng nhân viên</li>
                            </ul>
                        </div>
                    </div>
                </div>

                {{-- Bước 5 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">5</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Cấu hình quy tắc chia số</h3>
                        <p class="text-sm text-ink/70">Vào <strong>Chia số &gt; Quy tắc</strong> — thiết lập cách hệ thống tự động phân bổ lead cho nhân viên trong phòng ban mình. Có thể chia đều, chia theo nguồn, hoặc theo tỉ lệ.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    {{-- NHÂN VIÊN TELESALE --}}
    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    <div x-show="role === 'nv_tele'" x-cloak>
        <div class="bg-white border border-gold-200 rounded-2xl shadow-sm p-8 mb-6">
            <h2 class="text-xl font-bold text-gold-700 mb-1">Nhân viên telesale</h2>
            <p class="text-sm text-ink/60 mb-6">Nhập dữ liệu khách hàng bằng tay vào hệ thống, cập nhật tình trạng khách cùng chuyên viên tư vấn.</p>

            <div class="space-y-8">
                {{-- Bước 1 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">1</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Nhập khách hàng mới bằng tay</h3>
                        <p class="text-sm text-ink/70 mb-2">Vào <strong>Khách hàng &gt; Thêm mới</strong> — nhập đầy đủ thông tin khách từ cuộc gọi:</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div class="bg-gold-50 border border-gold-100 rounded-lg p-3 text-sm">
                                <div class="font-semibold text-gold-700 mb-1">Thông tin cá nhân</div>
                                <ul class="text-ink/70 text-xs space-y-0.5 list-disc list-inside">
                                    <li>Tên khách hàng, SĐT (bắt buộc)</li>
                                    <li>Ngày, Page, Campaign, Insight</li>
                                    <li>Link profile, Ghi chú</li>
                                </ul>
                            </div>
                            <div class="bg-gold-50 border border-gold-100 rounded-lg p-3 text-sm">
                                <div class="font-semibold text-gold-700 mb-1">Phân phối & Cơ sở</div>
                                <ul class="text-ink/70 text-xs space-y-0.5 list-disc list-inside">
                                    <li>Chia vào kho hoặc gán sale phụ trách</li>
                                    <li>Chọn cơ sở (chi nhánh + phòng ban)</li>
                                    <li>Chọn bác sĩ tư vấn, chuyên viên 1/2/3</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Bước 2 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">2</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Cập nhật tình trạng khách hàng</h3>
                        <p class="text-sm text-ink/70 mb-2">Mở chi tiết khách hàng, cập nhật <strong>Phân loại kết quả</strong> theo tiến trình chăm sóc:</p>
                        <div class="flex flex-wrap gap-1.5 text-xs">
                            @foreach (['Mới' => 'bg-gray-100', 'Lead' => 'bg-blue-100 text-blue-800', 'Follow' => 'bg-cyan-100 text-cyan-800', 'Quan tâm' => 'bg-yellow-100 text-yellow-800', 'Booking' => 'bg-orange-100 text-orange-800', 'Show' => 'bg-purple-100 text-purple-800', 'Close' => 'bg-green-100 text-green-800'] as $label => $cls)
                                <span class="px-2.5 py-1 rounded-full font-semibold {{ $cls }}">{{ $label }}</span>
                                @if ($label !== 'Close') <span class="text-ink/30 self-center">&rarr;</span> @endif
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Bước 3 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">3</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Chọn bác sĩ / chuyên viên tư vấn</h3>
                        <p class="text-sm text-ink/70 mb-2">Khi tạo hoặc sửa lead, mục <strong>"Cơ sở & Nhân sự tư vấn"</strong> cho phép chọn chuyên viên phối hợp:</p>
                        <div class="bg-gold-50 border border-gold-100 rounded-lg p-3 text-sm">
                            <div class="text-ink/70 text-xs space-y-1">
                                <div>1. Bấm <strong>"— Chọn bác sĩ —"</strong> để mở dropdown</div>
                                <div>2. Dropdown hiện theo nhóm: <span class="font-mono">Cơ sở &gt; Phòng ban &gt; Tên bác sĩ</span></div>
                                <div>3. Dùng <strong>ô tìm kiếm</strong> phía trên để lọc nhanh theo tên</div>
                                <div>4. Bấm tên để chọn — bấm <strong>✕</strong> để bỏ chọn</div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Bước 4 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">4</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Ghi chú & đánh dấu khách quay lại</h3>
                        <p class="text-sm text-ink/70 mb-2">Trong trang chi tiết khách, mục <strong>Bình luận</strong> cho phép:</p>
                        <ul class="text-sm text-ink/70 list-disc list-inside space-y-0.5">
                            <li>Viết ghi chú tự do (nội dung tư vấn, yêu cầu, phản hồi...)</li>
                            <li>Tick <strong>"Khách tới lần đầu"</strong> hoặc <strong>"Khách quay trở lại"</strong> (2 ô exclusive)</li>
                            <li>Nếu tick "Khách quay trở lại" — bắt buộc nhập <strong>mã tiếp đón</strong></li>
                            <li>Hệ thống tự đếm <strong>tần suất quay lại</strong> = số lần tick "Khách quay trở lại"</li>
                        </ul>
                    </div>
                </div>

                {{-- Bước 5 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">5</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Điền trường bổ sung</h3>
                        <p class="text-sm text-ink/70">Khi tạo/sửa lead, phần <strong>"Trường bổ sung"</strong> hiện các trường do quản lý cấu hình. Ví dụ: Ngày sinh, Địa chỉ, Khai thác tiền sử, Nghề nghiệp, Phân loại khách (VIP / Tiềm năng / Mới / Cũ). Trường có dấu <span class="text-red-500">*</span> là bắt buộc.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    {{-- NHÂN VIÊN SALE DATA LẠNH --}}
    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    <div x-show="role === 'nv_dl'" x-cloak>
        <div class="bg-white border border-gold-200 rounded-2xl shadow-sm p-8 mb-6">
            <h2 class="text-xl font-bold text-gold-700 mb-1">Nhân viên sale data lạnh</h2>
            <p class="text-sm text-ink/60 mb-6">Tải mẫu upload của phòng ban mình, upload dữ liệu khách hàng lên hệ thống. Cập nhật dữ liệu khách cùng chuyên viên tư vấn.</p>

            <div class="space-y-8">
                {{-- Bước 1 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">1</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Tải mẫu upload phòng ban</h3>
                        <p class="text-sm text-ink/70 mb-2">Vào <strong>Khách hàng &gt; Import</strong> — bấm <strong>"Tải mẫu"</strong> để lấy file Excel/CSV mẫu tương ứng với phòng ban của bạn.</p>
                        <div class="bg-gold-50 border border-gold-100 rounded-lg p-3 text-sm">
                            <div class="font-semibold text-gold-700 mb-1">Lưu ý:</div>
                            <ul class="text-ink/70 text-xs space-y-0.5 list-disc list-inside">
                                <li>Mẫu upload mỗi phòng ban có thể <strong>khác nhau</strong> (tùy trường tùy biến đã cấu hình)</li>
                                <li>Luôn dùng mẫu mới nhất — tải lại nếu quản lý vừa thay đổi trường</li>
                            </ul>
                        </div>
                    </div>
                </div>

                {{-- Bước 2 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">2</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Upload dữ liệu lên hệ thống</h3>
                        <p class="text-sm text-ink/70 mb-2">Sau khi điền xong file mẫu, quay lại <strong>Khách hàng &gt; Import</strong>:</p>
                        <div class="bg-gold-50 border border-gold-100 rounded-lg p-3 text-sm">
                            <div class="text-ink/70 text-xs space-y-1">
                                <div>1. Bấm <strong>"Chọn file"</strong> → chọn file Excel/CSV đã điền</div>
                                <div>2. Hệ thống đọc file → hiện bảng <strong>ghép cột</strong> (cột file ↔ trường hệ thống)</div>
                                <div>3. Kiểm tra ghép cột, sửa nếu cần → bấm <strong>"Import"</strong></div>
                                <div>4. Hệ thống validate: SĐT trùng thì gộp, lỗi thì báo dòng nào sai</div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Bước 3 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">3</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Cập nhật tình trạng khách hàng</h3>
                        <p class="text-sm text-ink/70 mb-2">Mở chi tiết khách hàng, cập nhật <strong>Phân loại kết quả</strong> theo tiến trình:</p>
                        <div class="flex flex-wrap gap-1.5 text-xs">
                            @foreach (['Mới' => 'bg-gray-100', 'Lead' => 'bg-blue-100 text-blue-800', 'Follow' => 'bg-cyan-100 text-cyan-800', 'Quan tâm' => 'bg-yellow-100 text-yellow-800', 'Booking' => 'bg-orange-100 text-orange-800', 'Show' => 'bg-purple-100 text-purple-800', 'Close' => 'bg-green-100 text-green-800'] as $label => $cls)
                                <span class="px-2.5 py-1 rounded-full font-semibold {{ $cls }}">{{ $label }}</span>
                                @if ($label !== 'Close') <span class="text-ink/30 self-center">&rarr;</span> @endif
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Bước 4 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">4</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Chọn chuyên viên tư vấn phối hợp</h3>
                        <p class="text-sm text-ink/70 mb-2">Khi cập nhật khách, mục <strong>"Cơ sở & Nhân sự tư vấn"</strong> cho phép chọn chuyên viên:</p>
                        <div class="bg-gold-50 border border-gold-100 rounded-lg p-3 text-sm">
                            <div class="text-ink/70 text-xs space-y-1">
                                <div>1. Bấm <strong>"— Chọn bác sĩ —"</strong> để mở dropdown</div>
                                <div>2. Dropdown hiện theo nhóm: <span class="font-mono">Cơ sở &gt; Phòng ban &gt; Tên bác sĩ</span></div>
                                <div>3. Dùng <strong>ô tìm kiếm</strong> phía trên để lọc nhanh theo tên</div>
                                <div>4. Bấm tên để chọn — bấm <strong>✕</strong> để bỏ chọn</div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Bước 5 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">5</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Ghi chú & đánh dấu khách quay lại</h3>
                        <p class="text-sm text-ink/70 mb-2">Trong trang chi tiết khách, mục <strong>Bình luận</strong> cho phép:</p>
                        <ul class="text-sm text-ink/70 list-disc list-inside space-y-0.5">
                            <li>Viết ghi chú tự do (nội dung tư vấn, yêu cầu, phản hồi...)</li>
                            <li>Tick <strong>"Khách tới lần đầu"</strong> hoặc <strong>"Khách quay trở lại"</strong></li>
                            <li>Nếu tick "Khách quay trở lại" — bắt buộc nhập <strong>mã tiếp đón</strong></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    {{-- GIÁM SÁT & VẬN HÀNH --}}
    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    <div x-show="role === 'gs_vh'" x-cloak>
        <div class="bg-white border border-gold-200 rounded-2xl shadow-sm p-8 mb-6">
            <h2 class="text-xl font-bold text-gold-700 mb-1">Giám sát & vận hành</h2>
            <p class="text-sm text-ink/60 mb-6">Xem thông tin khách hàng theo mức <strong>cơ sở mình phụ trách</strong>. Xem báo cáo theo từng mẫu báo cáo được cấp quyền.</p>

            <div class="space-y-8">
                {{-- Bước 1 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">1</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Xem thông tin khách hàng theo cơ sở</h3>
                        <p class="text-sm text-ink/70 mb-2">Vào <strong>Khách hàng</strong> — xem danh sách khách hàng thuộc cơ sở mình phụ trách. Dùng bộ lọc để tìm nhanh:</p>
                        <div class="bg-gold-50 border border-gold-100 rounded-lg p-3 text-sm">
                            <ul class="text-ink/70 text-xs space-y-0.5 list-disc list-inside">
                                <li><strong>Tên / SĐT:</strong> tìm theo thông tin cá nhân</li>
                                <li><strong>Trạng thái:</strong> Mới, Lead, Follow, Booking, Show, Close...</li>
                                <li><strong>Nguồn:</strong> Facebook, Google, Telesale...</li>
                                <li><strong>Nhân viên phụ trách:</strong> xem lead của từng người</li>
                            </ul>
                        </div>
                        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 text-sm mt-2">
                            <div class="text-xs text-yellow-800"><strong>Lưu ý:</strong> Bạn chỉ thấy dữ liệu khách hàng thuộc cơ sở mình — không thấy dữ liệu cơ sở khác.</div>
                        </div>
                    </div>
                </div>

                {{-- Bước 2 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">2</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Xem báo cáo theo mẫu</h3>
                        <p class="text-sm text-ink/70 mb-2">Vào <strong>Báo cáo</strong> — chọn mẫu báo cáo phù hợp:</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div class="bg-gold-50 border border-gold-100 rounded-lg p-3 text-sm">
                                <div class="font-semibold text-gold-700 mb-1">Dashboard tổng quan</div>
                                <p class="text-ink/70 text-xs">Lead hôm nay, funnel tháng, top sale, lead chưa chăm / quá SLA.</p>
                            </div>
                            <div class="bg-gold-50 border border-gold-100 rounded-lg p-3 text-sm">
                                <div class="font-semibold text-gold-700 mb-1">Funnel theo kỳ</div>
                                <p class="text-ink/70 text-xs">Total → Lead → Follow → Booking → Show → Close + tỉ lệ chuyển đổi.</p>
                            </div>
                            <div class="bg-gold-50 border border-gold-100 rounded-lg p-3 text-sm">
                                <div class="font-semibold text-gold-700 mb-1">Hiệu suất sale / team</div>
                                <p class="text-ink/70 text-xs">Số nhận, tỉ lệ close, doanh thu, xếp hạng.</p>
                            </div>
                            <div class="bg-gold-50 border border-gold-100 rounded-lg p-3 text-sm">
                                <div class="font-semibold text-gold-700 mb-1">Hiệu quả marketing</div>
                                <p class="text-ink/70 text-xs">Theo campaign / nguồn quảng cáo / PAGE — lead về, tỉ lệ close.</p>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Bước 3 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">3</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Sử dụng bộ lọc báo cáo</h3>
                        <p class="text-sm text-ink/70">Mỗi mẫu báo cáo cho phép lọc theo:</p>
                        <ul class="text-sm text-ink/70 list-disc list-inside mt-1 space-y-0.5">
                            <li><strong>Khoảng thời gian:</strong> từ ngày — đến ngày</li>
                            <li><strong>Phòng ban / team:</strong> xem riêng từng nhánh (trong phạm vi cơ sở mình)</li>
                            <li><strong>Nhân viên:</strong> xem số liệu của từng người</li>
                            <li><strong>Phân loại / trạng thái:</strong> lọc theo giai đoạn pipeline</li>
                        </ul>
                    </div>
                </div>

                {{-- Bước 4 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">4</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Các chỉ số theo dõi</h3>
                        <p class="text-sm text-ink/70 mb-2">Hệ thống cung cấp các chỉ số chính:</p>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-center">
                            @foreach ([
                                ['Tổng lead', 'Số lượng khách hàng trong kỳ'],
                                ['Tỉ lệ chuyển đổi', 'Show / Close trên tổng lead'],
                                ['Lead theo nguồn', 'Phân bổ theo Facebook, Google...'],
                                ['Doanh thu', 'Tổng thu tiền trong kỳ'],
                            ] as [$t, $d])
                                <div class="bg-gold-50 border border-gold-100 rounded-lg p-3">
                                    <div class="text-xs font-bold text-gold-700">{{ $t }}</div>
                                    <div class="text-[10px] text-ink/50 mt-0.5">{{ $d }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Footer note --}}
    <div class="text-center text-xs text-ink/40 mt-8">
        Cần hỗ trợ thêm? Liên hệ quản trị viên hệ thống để được hướng dẫn chi tiết.
    </div>
</main>

</body>
</html>
