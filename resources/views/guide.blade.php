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

<main class="max-w-6xl mx-auto px-4 py-8" x-data="{ role: 'qlvh' }">

    {{-- Intro --}}
    <div class="text-center mb-10">
        <h1 class="text-3xl font-extrabold text-gold-700 mb-2">Hướng dẫn sử dụng hệ thống CRM</h1>
        <p class="text-ink/60 max-w-2xl mx-auto">Chọn vai trò của bạn để xem hướng dẫn chi tiết về quyền hạn và cách thao tác trong hệ thống.</p>
    </div>

    {{-- Role selector --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-10">
        <button @click="role = 'qlvh'"
                :class="role === 'qlvh' ? 'bg-gold-600 text-white shadow-lg scale-[1.02]' : 'bg-white text-ink hover:border-gold-400'"
                class="border border-gold-200 rounded-xl px-4 py-4 text-left transition-all">
            <div class="text-2xl mb-1">&#9881;</div>
            <div class="font-bold text-sm">Quản lý vận hành</div>
            <div class="text-xs mt-1 opacity-70">Thiết lập & điều phối hệ thống</div>
        </button>
        <button @click="role = 'nvpt'"
                :class="role === 'nvpt' ? 'bg-gold-600 text-white shadow-lg scale-[1.02]' : 'bg-white text-ink hover:border-gold-400'"
                class="border border-gold-200 rounded-xl px-4 py-4 text-left transition-all">
            <div class="text-2xl mb-1">&#128100;</div>
            <div class="font-bold text-sm">Nhân viên phụ trách</div>
            <div class="text-xs mt-1 opacity-70">Chăm sóc & khai thác khách hàng</div>
        </button>
        <button @click="role = 'cvtv'"
                :class="role === 'cvtv' ? 'bg-gold-600 text-white shadow-lg scale-[1.02]' : 'bg-white text-ink hover:border-gold-400'"
                class="border border-gold-200 rounded-xl px-4 py-4 text-left transition-all">
            <div class="text-2xl mb-1">&#129658;</div>
            <div class="font-bold text-sm">Chuyên viên tư vấn</div>
            <div class="text-xs mt-1 opacity-70">Hiển thị trong danh sách chọn</div>
        </button>
        <button @click="role = 'nvql'"
                :class="role === 'nvql' ? 'bg-gold-600 text-white shadow-lg scale-[1.02]' : 'bg-white text-ink hover:border-gold-400'"
                class="border border-gold-200 rounded-xl px-4 py-4 text-left transition-all">
            <div class="text-2xl mb-1">&#128202;</div>
            <div class="font-bold text-sm">Nhân viên quản lý</div>
            <div class="text-xs mt-1 opacity-70">Xem báo cáo & thống kê</div>
        </button>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    {{-- QUẢN LÝ VẬN HÀNH --}}
    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    <div x-show="role === 'qlvh'" x-cloak>
        <div class="bg-white border border-gold-200 rounded-2xl shadow-sm p-8 mb-6">
            <h2 class="text-xl font-bold text-gold-700 mb-1">Quản lý vận hành</h2>
            <p class="text-sm text-ink/60 mb-6">Người thiết lập toàn bộ hệ thống: cơ cấu tổ chức, phân quyền, cấu hình trường dữ liệu, quy tắc chia số, và quản lý cơ sở/nhân sự tư vấn.</p>

            <div class="space-y-8">
                {{-- Bước 1 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">1</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Xây dựng sơ đồ tổ chức</h3>
                        <p class="text-sm text-ink/70 mb-2">Vào <strong>Tổ chức &gt; Sơ đồ tổ chức</strong> — tạo cây phòng ban từ gốc Công ty xuống các phòng, rồi đến team bên dưới.</p>
                        <div class="bg-gold-50 border border-gold-100 rounded-lg p-3 text-sm">
                            <div class="font-semibold text-gold-700 mb-1">Ví dụ cây tổ chức:</div>
                            <div class="text-ink/70 font-mono text-xs leading-relaxed">
                                Công ty<br>
                                ├── Phòng Kinh doanh<br>
                                │&nbsp;&nbsp;&nbsp;├── Team Sale A<br>
                                │&nbsp;&nbsp;&nbsp;└── Team Sale B<br>
                                ├── Phòng Marketing<br>
                                │&nbsp;&nbsp;&nbsp;├── Telesales Marketing<br>
                                │&nbsp;&nbsp;&nbsp;└── BDM<br>
                                └── ...
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Bước 2 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">2</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Tạo vai trò & phân quyền</h3>
                        <p class="text-sm text-ink/70 mb-2">Vào <strong>Tổ chức &gt; Vai trò</strong> — tạo các vai trò (VD: Admin, Manager, Sale) và tích chọn quyền cho từng vai trò.</p>
                        <div class="bg-gold-50 border border-gold-100 rounded-lg p-3 text-sm">
                            <div class="font-semibold text-gold-700 mb-1">Nhóm quyền chính:</div>
                            <ul class="text-ink/70 text-xs space-y-0.5 list-disc list-inside">
                                <li><strong>Lead:</strong> xem, tạo, sửa, xóa, import, export</li>
                                <li><strong>Chia số:</strong> chia thủ công, thu hồi, kéo từ kho, cấu hình quy tắc</li>
                                <li><strong>Tổ chức:</strong> quản lý nhân viên, vai trò, sơ đồ, trường tùy biến</li>
                                <li><strong>Dịch vụ:</strong> danh mục DV, ghi nhận thu tiền, đánh % đóng góp</li>
                                <li><strong>Báo cáo:</strong> xem cá nhân/phòng ban, xem toàn hệ thống</li>
                            </ul>
                        </div>
                    </div>
                </div>

                {{-- Bước 3 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">3</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Thêm nhân viên & gán quyền</h3>
                        <p class="text-sm text-ink/70">Vào <strong>Tổ chức &gt; Nhân sự</strong> — thêm tài khoản nhân viên, chọn vai trò và phòng ban. Mỗi nhân viên cần ít nhất 1 assignment (vai trò + phòng ban + phạm vi dữ liệu).</p>
                        <div class="bg-gold-50 border border-gold-100 rounded-lg p-3 text-sm mt-2">
                            <div class="font-semibold text-gold-700 mb-1">Phạm vi dữ liệu (data scope):</div>
                            <ul class="text-ink/70 text-xs space-y-0.5 list-disc list-inside">
                                <li><strong>Cá nhân (self):</strong> chỉ thấy lead mình sở hữu</li>
                                <li><strong>Team:</strong> thấy toàn bộ lead trong phòng/team được gán</li>
                                <li><strong>Tùy chọn (custom):</strong> tích chọn cụ thể các nhánh phòng ban được thấy</li>
                            </ul>
                        </div>
                    </div>
                </div>

                {{-- Bước 4 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">4</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Cấu hình trường tùy biến</h3>
                        <p class="text-sm text-ink/70">Vào <strong>Tổ chức &gt; Trường tùy biến</strong> — tạo thêm các trường thông tin cần thu thập. Có 2 mức:</p>
                        <ul class="text-sm text-ink/70 mt-1 list-disc list-inside">
                            <li><strong>Mức công ty:</strong> áp dụng cho tất cả (VD: Ngày sinh, Địa chỉ, Phân loại khách).</li>
                            <li><strong>Mức phòng ban:</strong> chỉ áp dụng cho phòng/team cụ thể.</li>
                        </ul>
                    </div>
                </div>

                {{-- Bước 5 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">5</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Quản lý cơ sở & nhân sự tư vấn</h3>
                        <p class="text-sm text-ink/70 mb-2">Thêm danh sách cơ sở (chi nhánh), phòng ban trong cơ sở, và bác sĩ/chuyên viên tư vấn. Đây là danh mục dùng để chọn khi tạo/sửa khách hàng — <strong>không phải tài khoản hệ thống</strong>.</p>
                        <div class="bg-gold-50 border border-gold-100 rounded-lg p-3 text-sm">
                            <div class="font-semibold text-gold-700 mb-1">Cấu trúc:</div>
                            <div class="text-ink/70 font-mono text-xs leading-relaxed">
                                Cơ sở 1 — Quận 1<br>
                                ├── Phòng Da liễu<br>
                                │&nbsp;&nbsp;&nbsp;├── BS. Nguyễn Văn A (bác sĩ)<br>
                                │&nbsp;&nbsp;&nbsp;└── CV. Lê Văn C (chuyên viên)<br>
                                └── Phòng Thẩm mỹ<br>
                                &nbsp;&nbsp;&nbsp;&nbsp;├── BS. Trần Thị B (bác sĩ)<br>
                                &nbsp;&nbsp;&nbsp;&nbsp;└── CV. Phạm Thị D (chuyên viên)
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Bước 6 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">6</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Cấu hình quy tắc chia số</h3>
                        <p class="text-sm text-ink/70">Vào <strong>Chia số &gt; Quy tắc</strong> — thiết lập cách hệ thống tự động phân bổ lead cho nhân viên. Có thể chia đều, chia theo nguồn, hoặc theo tỉ lệ.</p>
                    </div>
                </div>

                {{-- Bước 7 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">7</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Quản lý dịch vụ & thu tiền</h3>
                        <p class="text-sm text-ink/70">Vào <strong>Dịch vụ</strong> — tạo danh mục dịch vụ (tên, giá, mô tả). Vào <strong>Thu tiền</strong> — ghi nhận thanh toán của khách hàng khi sử dụng dịch vụ.</p>
                    </div>
                </div>

                {{-- Bước 8 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">8</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Import lead & kết nối nguồn</h3>
                        <p class="text-sm text-ink/70">
                            <strong>Khách hàng &gt; Import:</strong> tải file Excel/CSV chứa danh sách lead, ghép cột với trường hệ thống, hệ thống tự validate và nhập.<br>
                            <strong>Cài đặt &gt; Kết nối nguồn:</strong> cấu hình webhook để tự động nhận lead từ landing page, Facebook Ads, Google Ads...
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    {{-- NHÂN VIÊN PHỤ TRÁCH --}}
    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    <div x-show="role === 'nvpt'" x-cloak>
        <div class="bg-white border border-gold-200 rounded-2xl shadow-sm p-8 mb-6">
            <h2 class="text-xl font-bold text-gold-700 mb-1">Nhân viên phụ trách</h2>
            <p class="text-sm text-ink/60 mb-6">Người trực tiếp chăm sóc khách hàng: nhận lead, liên hệ, cập nhật trạng thái, ghi chú tư vấn, và chuyển đổi khách qua các giai đoạn pipeline.</p>

            <div class="space-y-8">
                {{-- Bước 1 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">1</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Xem danh sách khách hàng</h3>
                        <p class="text-sm text-ink/70">Vào <strong>Khách hàng</strong> — xem danh sách lead được chia cho mình hoặc trong kho chung team. Dùng bộ lọc để tìm theo tên, SĐT, trạng thái, nguồn, hoặc phân loại.</p>
                    </div>
                </div>

                {{-- Bước 2 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">2</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Kéo lead từ kho chung</h3>
                        <p class="text-sm text-ink/70">Nếu có quyền <em>"Kéo lead từ kho"</em>, bạn có thể lấy lead từ kho chung công ty hoặc kho chung phòng/team về kho cá nhân để bắt đầu chăm sóc.</p>
                    </div>
                </div>

                {{-- Bước 3 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">3</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Tạo lead mới</h3>
                        <p class="text-sm text-ink/70 mb-2">Vào <strong>Khách hàng &gt; Thêm mới</strong> — nhập đầy đủ thông tin:</p>
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

                {{-- Bước 4 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">4</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Chọn bác sĩ / chuyên viên tư vấn</h3>
                        <p class="text-sm text-ink/70 mb-2">Khi tạo hoặc sửa lead, mục <strong>"Cơ sở & Nhân sự tư vấn"</strong> cho phép chọn từ dropdown dạng cây:</p>
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
                        <h3 class="font-bold mb-1">Cập nhật trạng thái chăm sóc</h3>
                        <p class="text-sm text-ink/70 mb-2">Mở chi tiết khách hàng, cập nhật <strong>Phân loại kết quả</strong> theo tiến trình:</p>
                        <div class="flex flex-wrap gap-1.5 text-xs">
                            @foreach (['Mới' => 'bg-gray-100', 'Lead' => 'bg-blue-100 text-blue-800', 'Follow' => 'bg-cyan-100 text-cyan-800', 'Quan tâm' => 'bg-yellow-100 text-yellow-800', 'Booking' => 'bg-orange-100 text-orange-800', 'Show' => 'bg-purple-100 text-purple-800', 'Close' => 'bg-green-100 text-green-800'] as $label => $cls)
                                <span class="px-2.5 py-1 rounded-full font-semibold {{ $cls }}">{{ $label }}</span>
                                @if ($label !== 'Close') <span class="text-ink/30 self-center">&rarr;</span> @endif
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Bước 6 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">6</div>
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

                {{-- Bước 7 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">7</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Điền trường bổ sung</h3>
                        <p class="text-sm text-ink/70">Khi tạo/sửa lead, phần <strong>"Trường bổ sung"</strong> hiện các trường do quản lý cấu hình. Ví dụ ở mức công ty: Ngày sinh, Địa chỉ, Khai thác tiền sử, Nghề nghiệp, Phân loại khách (VIP / Tiềm năng / Mới / Cũ). Trường có dấu <span class="text-red-500">*</span> là bắt buộc.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    {{-- CHUYÊN VIÊN TƯ VẤN --}}
    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    <div x-show="role === 'cvtv'" x-cloak>
        <div class="bg-white border border-gold-200 rounded-2xl shadow-sm p-8 mb-6">
            <h2 class="text-xl font-bold text-gold-700 mb-1">Chuyên viên tư vấn</h2>
            <p class="text-sm text-ink/60 mb-6">Bác sĩ và chuyên viên tư vấn <strong>không có tài khoản đăng nhập</strong> trong hệ thống. Tên của họ được quản lý vận hành thêm vào danh mục để nhân viên phụ trách chọn khi xử lý khách hàng.</p>

            <div class="space-y-8">
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">&#8505;</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Vai trò trong hệ thống</h3>
                        <p class="text-sm text-ink/70">Chuyên viên tư vấn (bác sĩ, CVTV) xuất hiện dưới dạng <strong>danh sách chọn</strong> trong form tạo/sửa khách hàng. Mục đích: ghi nhận ai là người tư vấn cho khách — phục vụ báo cáo và theo dõi.</p>
                    </div>
                </div>

                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">1</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Cách thêm bác sĩ / chuyên viên vào danh mục</h3>
                        <p class="text-sm text-ink/70 mb-2">Quản lý vận hành thực hiện:</p>
                        <ol class="text-sm text-ink/70 list-decimal list-inside space-y-1">
                            <li>Tạo <strong>Cơ sở</strong> (VD: Cơ sở 1 — Quận 1)</li>
                            <li>Tạo <strong>Phòng ban</strong> trong cơ sở (VD: Phòng Da liễu, Phòng Thẩm mỹ)</li>
                            <li>Thêm <strong>Bác sĩ</strong> hoặc <strong>Chuyên viên</strong> vào phòng ban, chỉ cần nhập tên</li>
                        </ol>
                    </div>
                </div>

                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">2</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Cách chuyên viên hiển thị khi chọn</h3>
                        <p class="text-sm text-ink/70 mb-2">Khi nhân viên phụ trách tạo/sửa khách hàng, dropdown bác sĩ/CVTV hiện theo cây:</p>
                        <div class="bg-gold-50 border border-gold-100 rounded-lg p-4 text-sm max-w-sm">
                            <div class="border border-gold-200 rounded-lg bg-white overflow-hidden">
                                <div class="px-3 py-2 border-b border-gold-100">
                                    <div class="text-xs text-ink/40 border border-gold-200 rounded px-2 py-1.5">Nhập tên để tìm...</div>
                                </div>
                                <div class="text-xs">
                                    <div class="px-3 py-1.5 font-bold text-gold-700 bg-gold-50 uppercase tracking-wider">Cơ sở 1 — Quận 1</div>
                                    <div class="px-5 py-1 font-semibold text-ink/50">Phòng Da liễu</div>
                                    <div class="pl-8 pr-3 py-1.5 hover:bg-gold-50 cursor-pointer">BS. Nguyễn Văn A</div>
                                    <div class="px-5 py-1 font-semibold text-ink/50">Phòng Thẩm mỹ</div>
                                    <div class="pl-8 pr-3 py-1.5 hover:bg-gold-50 cursor-pointer">BS. Trần Thị B</div>
                                    <div class="px-3 py-1.5 font-bold text-gold-700 bg-gold-50 uppercase tracking-wider">Cơ sở 2 — Quận 7</div>
                                    <div class="px-5 py-1 font-semibold text-ink/50">Phòng Nha khoa</div>
                                    <div class="pl-8 pr-3 py-1.5 hover:bg-gold-50 cursor-pointer">BS. Hoàng Văn E</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">3</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Hiển thị trên trang chi tiết khách</h3>
                        <p class="text-sm text-ink/70">Sau khi chọn, thông tin bác sĩ/chuyên viên hiện trong phần <strong>Thông tin chi tiết</strong> của khách hàng, dạng: <code class="text-xs bg-gold-100 px-1.5 py-0.5 rounded font-mono">Cơ sở 1 › Phòng Da liễu — BS. Nguyễn Văn A</code>.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    {{-- NHÂN VIÊN QUẢN LÝ --}}
    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    <div x-show="role === 'nvql'" x-cloak>
        <div class="bg-white border border-gold-200 rounded-2xl shadow-sm p-8 mb-6">
            <h2 class="text-xl font-bold text-gold-700 mb-1">Nhân viên quản lý</h2>
            <p class="text-sm text-ink/60 mb-6">Người có quyền xem báo cáo và thống kê hoạt động của hệ thống. Không trực tiếp thao tác trên lead, nhưng giám sát hiệu quả hoạt động.</p>

            <div class="space-y-8">
                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">1</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Xem báo cáo</h3>
                        <p class="text-sm text-ink/70 mb-2">Vào <strong>Báo cáo</strong> — xem tổng hợp hoạt động CRM. Tùy quyền được gán:</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div class="bg-gold-50 border border-gold-100 rounded-lg p-3 text-sm">
                                <div class="font-semibold text-gold-700 mb-1">Báo cáo cá nhân / phòng ban</div>
                                <p class="text-ink/70 text-xs">Quyền <code class="bg-gold-100 px-1 rounded text-[10px]">report.view</code> — thấy số liệu trong phạm vi phòng ban mình được gán.</p>
                            </div>
                            <div class="bg-gold-50 border border-gold-100 rounded-lg p-3 text-sm">
                                <div class="font-semibold text-gold-700 mb-1">Báo cáo toàn hệ thống</div>
                                <p class="text-ink/70 text-xs">Quyền <code class="bg-gold-100 px-1 rounded text-[10px]">report.view_all</code> — thấy số liệu toàn bộ công ty, tất cả phòng ban.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">2</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Sử dụng bộ lọc</h3>
                        <p class="text-sm text-ink/70">Trang báo cáo cho phép lọc dữ liệu theo:</p>
                        <ul class="text-sm text-ink/70 list-disc list-inside mt-1 space-y-0.5">
                            <li><strong>Khoảng thời gian:</strong> từ ngày — đến ngày</li>
                            <li><strong>Phòng ban / team:</strong> xem riêng từng nhánh tổ chức</li>
                            <li><strong>Nhân viên:</strong> xem số liệu của từng người</li>
                            <li><strong>Phân loại / trạng thái:</strong> lọc theo giai đoạn pipeline</li>
                        </ul>
                    </div>
                </div>

                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">3</div>
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

                <div class="flex gap-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gold-100 text-gold-700 flex items-center justify-center font-bold text-sm">4</div>
                    <div class="flex-1">
                        <h3 class="font-bold mb-1">Xuất dữ liệu</h3>
                        <p class="text-sm text-ink/70">Nếu có quyền <em>"Export lead"</em>, có thể xuất danh sách khách hàng ra file Excel. Hệ thống ghi audit log mỗi lần xuất để đảm bảo an toàn dữ liệu.</p>
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
