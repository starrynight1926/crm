@extends('layouts.app')

@section('title', 'Thiết lập hệ thống')

@php
    $u = auth()->user();

    // 2026-08-04 (T6.2): Tab hóa /settings — gom 15+ module thành 4 nhóm rõ ràng.
    // Nguyên tắc: 1 tính năng chỉ 1 chỗ — module chỉ hiện ở /settings, KHÔNG duplicate lên nav top.
    // Ngoại lệ: những gì cần dùng hằng ngày (Báo cáo, Chia số, Dịch vụ, Danh sách KH) đã ở nav top thì
    // không lặp lại đây.

    $icons = [
        'users'   => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
        'shield'  => 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
        'tree'    => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5',
        'tag'     => 'M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z M6 6h.008v.008H6V6z',
        'check'   => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'box'     => 'M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9',
        'plug'    => 'M14.25 6.087c0-.355.186-.676.401-.959.221-.29.349-.634.349-1.003 0-1.036-1.007-1.875-2.25-1.875s-2.25.84-2.25 1.875c0 .369.128.713.349 1.003.215.283.401.604.401.959v0a.64.64 0 01-.657.643 48.39 48.39 0 01-4.163-.3c.186 1.613.293 3.25.315 4.907a.656.656 0 01-.658.663v0c-.355 0-.676-.186-.959-.401a1.647 1.647 0 00-1.003-.349c-1.036 0-1.875 1.007-1.875 2.25s.84 2.25 1.875 2.25c.369 0 .713-.128 1.003-.349.283-.215.604-.401.959-.401v0c.31 0 .555.26.532.57a48.039 48.039 0 01-.642 5.056c1.518.19 3.058.309 4.616.354a.64.64 0 00.657-.643v0c0-.355-.186-.676-.401-.959a1.647 1.647 0 01-.349-1.003c0-1.035 1.008-1.875 2.25-1.875 1.243 0 2.25.84 2.25 1.875 0 .369-.128.713-.349 1.003-.215.283-.4.604-.4.959v0c0 .333.277.599.61.58a48.1 48.1 0 005.427-.63 48.05 48.05 0 00.582-4.717.532.532 0 00-.533-.57v0c-.355 0-.676.186-.959.401-.29.221-.634.349-1.003.349-1.035 0-1.875-1.007-1.875-2.25s.84-2.25 1.875-2.25c.37 0 .713.128 1.003.349.283.215.604.401.96.401v0a.656.656 0 00.658-.663 48.422 48.422 0 00-.37-5.36c-1.886.342-3.81.574-5.766.689a.578.578 0 01-.61-.58v0z',
        'chart'   => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
        'device'  => 'M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25',
        'lock'    => 'M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z',
    ];

    // 4 tab: Tổ chức / Danh mục dữ liệu / Hệ thống / Cá nhân
    $tabs = [
        'org' => [
            'label' => 'Tổ chức',
            'desc' => 'Cấu trúc phòng ban, người dùng, vai trò & phân quyền.',
            'modules' => [
                ['label'=>'Sơ đồ tổ chức','desc'=>'Cây phòng ban/đội nhóm, sâu tùy ý.','route'=>'org.chart','perm'=>'org.manage','icon'=>'tree'],
                ['label'=>'Người dùng','desc'=>'Thêm/sửa/xóa nhân sự & gán phân quyền theo phòng.','route'=>'org.users','perm'=>'user.manage','icon'=>'users'],
                ['label'=>'Vai trò','desc'=>'Định nghĩa vai trò & tích quyền chức năng (RBAC).','route'=>'org.roles','perm'=>'role.manage','icon'=>'shield'],
                ['label'=>'Bác sĩ & Cơ sở','desc'=>'Danh mục nhân sự chuyên môn theo cơ sở; import/export Excel.','route'=>'settings.staff','perm'=>'staff.manage','icon'=>'users'],
            ],
        ],
        'data' => [
            'label' => 'Danh mục dữ liệu',
            'desc' => 'Trường info, danh mục hệ thống, dịch vụ — nhập/xuất dữ liệu core.',
            'modules' => [
                ['label'=>'Danh mục hệ thống','desc'=>'Xem – Nhập – Xuất 5 loại data core (Org / Nhân sự / Dịch vụ / Khách hàng / Trường info).','route'=>'admin.catalog','perm'=>'user.manage','icon'=>'box'],
                ['label'=>'Trường tùy biến','desc'=>'Trường dữ liệu riêng từng cấp; kiểu mã phân loại nối vào mã KH.','route'=>'settings.fields','perm'=>'field.manage','icon'=>'tag'],
                ['label'=>'Duyệt trường','desc'=>'Duyệt trường bắt buộc do cấp dưới đề xuất.','route'=>'settings.field-approvals','perm'=>'field.approve','icon'=>'check'],
                ['label'=>'Dịch vụ','desc'=>'Danh mục dịch vụ, phase & mẫu % đóng góp.','route'=>'services.catalog','perm'=>'service.manage','icon'=>'box'],
            ],
        ],
        'ops' => [
            'label' => 'Vận hành',
            'desc' => 'Quy tắc vận hành, rule chia số & các kết nối bên ngoài (Booking, nguồn Ads).',
            'modules' => [
                ['label'=>'Quy tắc vận hành','desc'=>'Cấu hình rule vận hành hằng ngày (UPS, khóa chia số, ngưỡng cảnh báo…).','route'=>'ops.rules','perm'=>'ops.manage','icon'=>'shield'],
                ['label'=>'Rule chia số','desc'=>'Cấu hình logic chia số theo cơ sở/nguồn/loại lead.','route'=>'distribution.rules','perm'=>'rule.manage','icon'=>'shield'],
                ['label'=>'Kết nối Booking','desc'=>'Cấu hình endpoint/API kết nối hệ thống Booking.','route'=>'settings.booking-connection','perm'=>'connection.manage','icon'=>'plug'],
                ['label'=>'Kết nối nguồn Ads','desc'=>'Kết nối các nguồn quảng cáo để tự động kéo lead về.','route'=>'sources.index','perm'=>'connection.manage','icon'=>'plug'],
            ],
        ],
        'system' => [
            'label' => 'Hệ thống',
            'desc' => 'Thông báo, sao lưu, log hệ thống.',
            'modules' => [
                ['label'=>'Thiết lập thông báo','desc'=>'Tick từng vai trò → sẽ nhận loại thông báo nào (lead mới, chuyển lead, booking, ghi chú, thu hồi...).','route'=>'settings.notifications','perm'=>'role.manage','icon'=>'shield'],
                ['label'=>'Nhật ký thông báo','desc'=>'Xem toàn bộ thông báo hệ thống đã gửi — kể cả những cái user đã ẩn.','route'=>'settings.notification-log','perm'=>'role.manage','icon'=>'chart'],
                ['label'=>'Sao lưu & khôi phục','desc'=>'Xuất cấu hình ra JSON để backup, nhập lại để rollback; xuất toàn bộ dữ liệu ra file ZIP kèm Excel.','route'=>'settings.backup','perm'=>'system.backup','icon'=>'box'],
            ],
        ],
        'me' => [
            'label' => 'Cá nhân',
            'desc' => 'Quản lý phiên đăng nhập và đổi mật khẩu của chính bạn.',
            'modules' => [
                ['label'=>'Quản lý phiên','desc'=>'Thiết bị đăng nhập & thu hồi phiên từ xa.','route'=>'sessions.index','perm'=>null,'icon'=>'device'],
                ['label'=>'Đổi mật khẩu','desc'=>'Đổi password cho tài khoản của bạn.','route'=>'settings.password','perm'=>null,'icon'=>'lock'],
            ],
        ],
    ];

    // Lọc module theo perm; ẩn tab nếu không còn module nào.
    foreach ($tabs as $key => &$tab) {
        $tab['modules'] = array_values(array_filter($tab['modules'],
            fn ($m) => $m['perm'] === null || $u->hasAnyPermission((array) $m['perm'])
        ));
    }
    unset($tab);
    $tabs = array_filter($tabs, fn ($t) => ! empty($t['modules']));
@endphp

@section('content')
<div class="max-w-6xl mx-auto" x-data="{ tab: '{{ array_key_first($tabs) ?? 'org' }}' }">
    <div class="flex items-center gap-3 mb-1">
        <svg class="w-7 h-7 text-gold-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281z M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        <h1 class="text-3xl font-bold">Thiết lập hệ thống</h1>
    </div>
    <p class="text-sm text-ink/60 mb-6">Cấu hình phòng ban, nhân sự, dịch vụ, phân quyền và báo cáo cho hệ thống.</p>

    @if (empty($tabs))
        <p class="text-sm text-ink/50 bg-white border border-gold-200 rounded-xl p-8 text-center">Bạn chưa có quyền truy cập mục thiết lập nào.</p>
    @else
        {{-- Tab bar --}}
        <div class="flex flex-wrap gap-1 border-b border-gold-200 mb-5">
            @foreach ($tabs as $key => $tab)
                <button @click="tab = '{{ $key }}'" type="button"
                        :class="tab === '{{ $key }}' ? 'border-gold-600 text-gold-700' : 'border-transparent text-ink/60 hover:text-ink/90'"
                        class="text-sm font-semibold px-4 py-2.5 border-b-2 -mb-px transition">
                    {{ $tab['label'] }}
                    <span class="ml-1.5 text-xs bg-slate-100 text-ink/60 px-1.5 py-0.5 rounded">{{ count($tab['modules']) }}</span>
                </button>
            @endforeach
        </div>

        @foreach ($tabs as $key => $tab)
            <div x-show="tab === '{{ $key }}'" x-cloak>
                <p class="text-sm text-ink/50 mb-4">{{ $tab['desc'] }}</p>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                    @foreach ($tab['modules'] as $m)
                        <a href="{{ route($m['route']) }}"
                           class="group bg-white border border-gold-200 rounded-xl p-5 flex gap-4 shadow-card hover:border-gold-500 hover:shadow-md transition">
                            <div class="shrink-0 w-12 h-12 rounded-lg bg-gold-50 border border-gold-100 flex items-center justify-center group-hover:bg-gold-100 transition">
                                <svg class="w-6 h-6 text-gold-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons[$m['icon']] }}"/></svg>
                            </div>
                            <div class="min-w-0">
                                <div class="font-bold text-ink">{{ $m['label'] }}</div>
                                <p class="text-sm text-ink/55 mt-1 leading-snug">{!! $m['desc'] !!}</p>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    @endif
</div>
@endsection
