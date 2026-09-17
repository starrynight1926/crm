@extends('layouts.base')

@section('body')
    @php
        $u = auth()->user();
        // 2026-08-04 (T6.2): Nav tái tổ chức 3 khu.
        //   KHU 1 (vận hành hằng ngày) — nav flat cho user thường: Dashboard | Khách hàng | Chia số | Dịch vụ | Báo cáo
        //   KHU 2 (quản trị) — dropdown "Quản trị" cho ops.manage / rule.manage / connection.manage
        //   KHU 3 (setup) — dropdown "Thiết lập" chỉ user.manage, dẫn về /settings tab-hóa

        // KHU 1 — Khách hàng: danh sách + thêm + duyệt + kho lead (2026-08-08: move Kho lead từ Chia số về Khách hàng)
        $customerChildren = array_values(array_filter([
            ['label' => 'Danh sách khách hàng', 'route' => $u->hasAnyPermission(['lead.view', 'lead.import']) ? 'leads.index' : null, 'match' => 'leads.index'],
            ['label' => 'Thêm khách hàng', 'route' => $u->hasPermission('lead.create') ? 'leads.create' : null, 'match' => 'leads.create'],
            ['label' => 'Kho lead', 'route' => $u->hasPermission('lead.view') ? 'distribution.pools' : null, 'match' => 'distribution.pools'],
            ['label' => 'Duyệt Lead', 'route' => $u->hasPermission('lead.approve_source') ? 'leads.approvals' : null, 'match' => 'leads.approvals'],
        ], fn ($i) => $i['route']));

        // KHU 1 — UPS list (2026-08-08: đổi tên từ "Chia số" — kho lead đã move về Khách hàng)
        $distChildren = array_values(array_filter([
            ['label' => 'UPS hôm nay',   'route' => 'ups.today', 'match' => 'ups.today'],
            ['label' => 'UPS check-in (BO)', 'route' => $u->hasPermission('ups.view') ? 'ups.list' : null, 'match' => 'ups.list'],
        ], fn ($i) => $i['route']));

        // KHU 1 — Báo cáo & Kinh doanh (2026-08-14: gộp "Kinh doanh" vào "Báo cáo" cho gọn)
        $reportChildren = array_values(array_filter([
            ['label' => 'Trung tâm báo cáo', 'route' => $u->hasAnyPermission(['report.view', 'report.view_all']) ? 'reports.index' : null, 'match' => 'reports.index'],
            ['label' => 'Dịch vụ', 'route' => $u->hasPermission('service.manage') ? 'services.catalog' : null, 'match' => 'services.*'],
            ['label' => 'Thu tiền', 'route' => $u->hasPermission('payment.record') ? 'payments.index' : null, 'match' => 'payments.*'],
        ], fn ($i) => $i['route']));

        // 2026-08-14: "Quản trị" đã chuyển vào /settings (tab Vận hành) — bỏ khỏi nav top.

        // KHU 3 — Thiết lập (Admin) — link vào /settings tab-hóa
        $setupChildren = array_values(array_filter([
            ['label' => 'Trang thiết lập', 'route' => 'settings.index', 'match' => 'settings.index'],
            ['label' => 'Tổ chức & User', 'route' => $u->hasPermission('user.manage') ? 'org.users' : null, 'match' => 'org.*'],
            ['label' => 'Danh mục hệ thống', 'route' => $u->hasPermission('user.manage') ? 'admin.catalog' : null, 'match' => 'admin.catalog*'],
        ], fn ($i) => $i['route']));

        $navItems = array_values(array_filter([
            ['label' => 'Dashboard', 'route' => 'dashboard', 'match' => 'dashboard'],
            !empty($customerChildren) ? ['label' => 'Khách hàng', 'match' => 'leads.*', 'children' => $customerChildren] : null,
            !empty($distChildren) ? ['label' => 'UPS list', 'match' => 'ups.*', 'children' => $distChildren] : null,
            !empty($reportChildren) ? ['label' => 'Báo cáo', 'match' => 'reports.*|services.*|payments.*', 'children' => $reportChildren] : null,
            // 2026-08-08: "Thiết lập" đã có trong avatar dropdown → bỏ khỏi nav để tránh trùng.
        ]));

        $isActive = function ($match) {
            foreach (explode('|', $match) as $m) {
                if (request()->routeIs(trim($m))) return true;
            }
            return false;
        };
    @endphp
    <div class="min-h-screen flex flex-col" x-data="{ mobileMenu: false }">
        {{-- Top navbar --}}
        <header class="bg-white border-b border-gold-200 sticky top-0 z-40">
            <div class="max-w-screen-2xl mx-auto px-4 md:px-6 h-16 flex items-center gap-4 lg:gap-8">
                {{-- Hamburger (mobile) --}}
                <button @click="mobileMenu = !mobileMenu" class="md:hidden p-2 -ml-2 rounded-md text-ink/70 hover:bg-gold-50 shrink-0" aria-label="Menu">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path x-show="!mobileMenu" stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                        <path x-show="mobileMenu" x-cloak stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>

                <a href="{{ route('dashboard') }}" class="flex items-center gap-2 shrink-0">
                    <svg class="w-7 h-7 text-gold-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l9 5.25v1.5H3v-1.5L12 3zM4.5 11.25h2v7.5h-2v-7.5zm6.5 0h2v7.5h-2v-7.5zm6.5 0h2v7.5h-2v-7.5zM3 20.25h18v1.5H3v-1.5z"/>
                    </svg>
                    <span class="text-lg md:text-xl font-bold text-gold-700 tracking-tight">Longevity Data Source</span>
                </a>

                <nav class="hidden md:flex items-center gap-0.5 lg:gap-1 text-sm font-medium">
                    @foreach ($navItems as $item)
                        @if (!empty($item['children']))
                            <div class="relative" x-data="{ dd: false }" @mouseenter="dd = true" @mouseleave="dd = false">
                                <button @click="dd = !dd"
                                        class="px-2.5 lg:px-3 py-2 rounded-md whitespace-nowrap inline-flex items-center gap-1 {{ $isActive($item['match']) ? 'text-gold-700 font-semibold' : 'text-ink/70 hover:text-gold-700' }}">
                                    {{ $item['label'] }}
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                </button>
                                <div x-show="dd" x-cloak
                                     class="absolute left-0 top-full pt-1 w-52 z-50">
                                    <div class="bg-white border border-gold-200 rounded-lg shadow-card py-1">
                                        @foreach ($item['children'] as $child)
                                            <a href="{{ route($child['route']) }}"
                                               class="block px-4 py-2 text-sm whitespace-nowrap {{ $isActive($child['match']) ? 'text-gold-700 font-semibold bg-gold-50' : 'text-ink/80 hover:bg-gold-50 hover:text-gold-700' }}">
                                                {{ $child['label'] }}
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @elseif (!empty($item['route']))
                            <a href="{{ route($item['route']) }}"
                               class="px-2.5 lg:px-3 py-2 rounded-md whitespace-nowrap {{ $isActive($item['match']) ? 'text-gold-700 font-semibold' : 'text-ink/70 hover:text-gold-700' }}">
                                {{ $item['label'] }}
                            </a>
                        @else
                            <span class="px-2.5 lg:px-3 py-2 text-ink/30 cursor-not-allowed whitespace-nowrap" title="Sắp có">{{ $item['label'] }}</span>
                        @endif
                    @endforeach
                </nav>

                <div class="flex-1"></div>

                @php
                    $upsTarget = $u->hasPermission('ups.view') ? 'ups.list' : 'ups.today';
                    $upsBlockedGlobal = app(\App\Services\Ups\UpsGate::class)->isBlockedFor($u);
                @endphp
                {{-- 2026-08-11 — Super admin: chọn "Cơ sở đang xem" tạm thời để scope widget dashboard. --}}
                @if (\App\Support\AdminScope::isSuperAdmin())
                    @php $__adminScopeId = \App\Support\AdminScope::currentBranchId(); @endphp
                    <form method="POST" action="{{ route('admin.scope') }}" class="hidden md:flex items-center gap-1 mr-2">
                        @csrf
                        <label class="text-[11px] font-semibold text-ink/50 uppercase tracking-wide">Cơ sở:</label>
                        <select name="org_unit_id" onchange="this.form.submit()"
                                class="text-xs font-semibold px-2 py-1.5 rounded border {{ $__adminScopeId ? 'border-amber-400 bg-amber-50 text-amber-800' : 'border-gold-200 bg-white text-ink/70' }}">
                            <option value="">— Toàn công ty —</option>
                            @foreach (\App\Support\AdminScope::branchOptions() as $__b)
                                <option value="{{ $__b->id }}" @selected($__adminScopeId === $__b->id)>{{ $__b->short_name }}</option>
                            @endforeach
                        </select>
                    </form>
                @endif

                <a href="{{ route($upsTarget) }}" target="_blank" rel="noopener"
                   class="hidden md:inline-flex items-center gap-1.5 text-sm font-bold px-3 py-2 rounded-md whitespace-nowrap {{ $upsBlockedGlobal ? 'bg-red-600 hover:bg-red-700 text-white animate-pulse' : 'bg-gold-600 hover:bg-gold-700 text-white' }}"
                   title="{{ $upsBlockedGlobal ? 'Chưa chốt UPS hôm nay — chia số đang bị khóa' : 'UPS hôm nay (mở tab mới)' }}">
                    <span>⚡ UPS SYSTEM</span>
                    @if ($upsBlockedGlobal)
                        <span class="text-[10px] bg-white/20 px-1 rounded">!</span>
                    @endif
                </a>

                <livewire:notification-bell />

                <div class="flex items-center gap-3 md:gap-4" x-data="{ open: false }">
                    <div class="text-right hidden lg:block">
                        <div class="text-sm font-semibold">{{ auth()->user()->name }}</div>
                        <div class="text-xs text-ink/50">{{ auth()->user()->email }}</div>
                    </div>
                    <button @click="open = !open" class="relative w-10 h-10 rounded-full bg-gold-600 text-white font-semibold flex items-center justify-center shrink-0">
                        {{ auth()->user()->initials() }}
                    </button>
                    <div x-show="open" @click.outside="open = false" x-cloak
                         class="absolute right-4 md:right-6 top-14 w-64 bg-white border border-gold-200 rounded-lg shadow-card py-1 text-sm">
                        <div class="px-4 py-2 border-b border-gold-100">
                            <div class="font-semibold truncate lg:hidden">{{ auth()->user()->name }}</div>
                            <div class="text-xs text-ink/50 truncate lg:hidden mb-1">{{ auth()->user()->email }}</div>
                            @php
                                // B3 (2026-08-14) — Trạng thái 2 chiều:
                                //   Chiều 1 (auto): is_busy → 'Đang tiếp đón' | else 'Đang chờ'
                                //   Chiều 2 (manual): dung_nhan_lead → '· Không nhận lead'
                                $__att = \App\Models\DailyAttendance::where('user_id', auth()->id())
                                    ->whereDate('work_date', now()->toDateString())->first();
                                $__hasAtt = (bool) $__att;
                                $__busy = $__hasAtt && $__att->is_busy;
                                $__paused = $__hasAtt && $__att->dung_nhan_lead;
                                $__base = $__busy ? 'Đang tiếp đón' : 'Đang chờ';
                                $__label = $__base . ($__paused ? ' · Không nhận lead' : '');
                                $__borderCls = $__paused
                                    ? 'border-red-400 bg-red-50 text-red-800'
                                    : ($__busy ? 'border-amber-400 bg-amber-50 text-amber-800' : 'border-emerald-400 bg-emerald-50 text-emerald-800');
                            @endphp
                            @if ($__hasAtt)
                                <div class="text-[11px] font-semibold px-2 py-1 rounded border {{ $__borderCls }}">{{ $__label }}</div>
                                <form method="POST" action="{{ route('me.receive-toggle') }}" class="mt-1.5"
                                      x-data @submit="if(!confirm('{{ $__paused ? 'Tiếp tục nhận lead từ UPS?' : 'Tạm ngừng nhận lead? Admin/UPS sẽ SKIP bạn khi chia số.' }}')) $event.preventDefault()">
                                    @csrf
                                    <button type="submit"
                                            class="w-full text-[11px] font-semibold px-2 py-1 rounded border {{ $__paused ? 'border-emerald-300 bg-white text-emerald-700 hover:bg-emerald-50' : 'border-red-300 bg-white text-red-700 hover:bg-red-50' }}">
                                        {{ $__paused ? 'Tiếp tục nhận' : 'Không tiếp nhận' }}
                                    </button>
                                </form>
                            @else
                                <div class="text-[11px] text-ink/40 italic">Chưa check-in UPS hôm nay</div>
                            @endif
                        </div>
                        @php
                            // Ẩn "Cài đặt" nếu user không có bất kỳ quyền quản trị nào
                            $adminPerms = ['user.manage', 'role.manage', 'org.manage', 'field.manage', 'field.approve', 'rule.manage', 'service.manage', 'connection.manage', 'ops.manage', 'lead.import'];
                        @endphp
                        @if (auth()->user()->hasAnyPermission($adminPerms))
                            <a href="{{ route('settings.index') }}" class="block px-4 py-2 hover:bg-gold-50">Cài đặt</a>
                        @endif
                        @if (app()->environment('local') && auth()->user()->hasPermission('user.manage'))
                            <a href="{{ route('dev.quick-login') }}" class="block px-4 py-2 hover:bg-red-50 text-red-700 font-semibold">🚀 Quick Login (dev)</a>
                        @endif
                        <a href="{{ route('me.activity') }}" class="block px-4 py-2 hover:bg-gold-50">Lịch sử hoạt động</a>
                        @php $bookingUrl = rtrim(config('services.booking.url') ?: '', '/'); @endphp
                        @if ($bookingUrl)
                            <a href="{{ $bookingUrl }}" target="_blank" rel="noopener"
                               class="block px-4 py-2 hover:bg-emerald-50 text-emerald-800 border-t border-gold-100">
                                🔀 Chuyển sang Booking
                                <span class="text-[10px] text-ink/40 block">{{ parse_url($bookingUrl, PHP_URL_HOST) }}</span>
                            </a>
                        @endif
                        <a href="{{ route('settings.password') }}" class="block px-4 py-2 hover:bg-gold-50">Đổi mật khẩu</a>
                        <a href="{{ route('sessions.index') }}" class="block px-4 py-2 hover:bg-gold-50">Quản lý phiên đăng nhập</a>
                        @if (auth()->user()->hasPermission('connection.manage'))
                            <a href="{{ route('sources.index') }}" class="block px-4 py-2 hover:bg-gold-50">Kết nối nguồn lead</a>
                        @endif
                        <a href="{{ route('support.index') }}" class="block px-4 py-2 hover:bg-gold-50">💬 Hỗ trợ / Phản hồi</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="w-full text-left px-4 py-2 text-red-700 hover:bg-red-50">Đăng xuất</button>
                        </form>
                    </div>
                </div>
            </div>

            {{-- Drawer menu (mobile) --}}
            <div x-show="mobileMenu" x-cloak @click.outside="mobileMenu = false"
                 x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                 class="md:hidden border-t border-gold-100 bg-white px-4 py-3 space-y-1">
                @foreach ($navItems as $item)
                    @if (!empty($item['children']))
                        <div class="pt-1">
                            <div class="px-3 py-1.5 text-xs uppercase tracking-wide text-ink/40">{{ $item['label'] }}</div>
                            @foreach ($item['children'] as $child)
                                <a href="{{ route($child['route']) }}"
                                   class="block px-6 py-2 rounded-md text-sm {{ $isActive($child['match']) ? 'bg-gold-50 text-gold-700 font-semibold' : 'text-ink/70 hover:bg-gold-50' }}">
                                    {{ $child['label'] }}
                                </a>
                            @endforeach
                        </div>
                    @elseif (!empty($item['route']))
                        <a href="{{ route($item['route']) }}"
                           class="block px-3 py-2.5 rounded-md text-sm font-medium {{ $isActive($item['match']) ? 'bg-gold-50 text-gold-700 font-semibold' : 'text-ink/70 hover:bg-gold-50' }}">
                            {{ $item['label'] }}
                        </a>
                    @else
                        <span class="block px-3 py-2.5 text-sm text-ink/30">{{ $item['label'] }} <span class="text-xs">(sắp có)</span></span>
                    @endif
                @endforeach
            </div>
        </header>

        @if (session('impersonate_original_id'))
            <div class="bg-red-600 text-white text-sm px-4 py-2 flex items-center justify-between gap-3">
                <span>🎭 Đang giả lập <strong>{{ auth()->user()->name }}</strong> ({{ auth()->user()->email }}) — original: {{ session('impersonate_original_name') }}</span>
                <form method="POST" action="{{ route('impersonate.leave') }}" class="inline">
                    @csrf
                    <button class="px-3 py-1 rounded bg-white text-red-700 hover:bg-red-50 text-xs font-semibold">← Về Admin</button>
                </form>
            </div>
        @endif

        <main class="flex-1 max-w-screen-2xl w-full mx-auto px-4 md:px-6 py-6 md:py-8">
            @yield('content')
            {{ $slot ?? '' }}
        </main>

        <footer class="py-6 flex items-center justify-center gap-3 text-xs tracking-widest text-gold-400 uppercase border-t border-gold-100">
            <span>Longevity Data Source · Quản lý dữ liệu khách hàng</span>
            @php $__latestVer = \App\Support\Changelog::latest(); @endphp
            @if ($__latestVer)
                <a href="{{ route('changelog') }}" class="px-2 py-0.5 rounded-md bg-gold-50 border border-gold-200 text-gold-700 hover:bg-gold-100 normal-case tracking-normal font-semibold" title="Xem changelog">
                    {{ $__latestVer['version'] }}
                </a>
            @endif
        </footer>

        {{-- Toast realtime (Reverb) --}}
        <div id="toast-container" class="fixed bottom-6 right-6 z-[60] space-y-2"></div>
    </div>

    {{-- SortableJS — drag & drop mượt cho picker cột báo cáo, kanban… --}}
    <script src="{{ asset('vendor/sortable/Sortable.min.js') }}"></script>

    {{-- SweetAlert2 — popup xác nhận đẹp (dùng cho: booking tạo xong, close phase, v.v.) --}}
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        window.addEventListener('scrm-swal', (e) => {
            if (typeof Swal === 'undefined') return;
            const { title, text, icon, timer } = e.detail || {};
            Swal.fire({
                title: title || 'Xong',
                text: text || '',
                icon: icon || 'success',
                timer: timer ?? 2500,
                showConfirmButton: false,
                timerProgressBar: true,
            });
        });
    </script>

    {{-- Column resizer — kéo mép phải header table để đổi chiều rộng cột. --}}
    <script>
        window.enableColumnResize = function (table) {
            const ths = table.querySelectorAll('thead th');
            ths.forEach((th) => {
                if (! th.style.width) th.style.width = th.offsetWidth + 'px';
                const handle = th.querySelector('[data-col-resizer]');
                if (! handle) return;
                let startX = 0, startWidth = 0;
                const onMove = (e) => {
                    const dx = (e.clientX || e.touches?.[0]?.clientX) - startX;
                    const newW = Math.max(60, startWidth + dx);
                    th.style.width = newW + 'px';
                };
                const onUp = () => {
                    document.removeEventListener('mousemove', onMove);
                    document.removeEventListener('mouseup', onUp);
                    document.body.style.cursor = '';
                    document.body.style.userSelect = '';
                };
                handle.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    startX = e.clientX;
                    startWidth = th.offsetWidth;
                    document.body.style.cursor = 'col-resize';
                    document.body.style.userSelect = 'none';
                    document.addEventListener('mousemove', onMove);
                    document.addEventListener('mouseup', onUp);
                });
            });
        };
    </script>

    {{-- Echo + Reverb: thông báo lead mới realtime; chuông vẫn poll 10s làm phương án phụ --}}
    <script src="https://cdn.jsdelivr.net/npm/pusher-js@8.4.0/dist/web/pusher.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.16.1/dist/echo.iife.js"></script>
    <script>
        (function () {
            if (typeof Echo === 'undefined' || typeof Pusher === 'undefined') return;

            window.EchoClient = window.Echo = new Echo({
                broadcaster: 'reverb',
                key: '{{ config('broadcasting.connections.reverb.key') }}',
                wsHost: '{{ config('broadcasting.connections.reverb.options.host') }}',
                wsPort: {{ (int) config('broadcasting.connections.reverb.options.port', 8080) }},
                wssPort: {{ (int) config('broadcasting.connections.reverb.options.port', 8080) }},
                forceTLS: {{ config('broadcasting.connections.reverb.options.scheme') === 'https' ? 'true' : 'false' }},
                enabledTransports: ['ws', 'wss'],
                authEndpoint: '/broadcasting/auth',
                auth: { headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } },
            });

            window.EchoClient
                .private('App.Models.User.{{ auth()->id() }}')
                .notification(function (notification) {
                    const toast = document.createElement('div');
                    toast.className = 'bg-white border-l-4 border-gold-600 border-y border-r border-gold-200 rounded-lg shadow-lg px-4 py-3 text-sm max-w-sm cursor-pointer';
                    toast.innerHTML = '<div class="font-bold text-gold-700 mb-0.5">🔔 Lead mới</div><div>' + (notification.message || '') + '</div>';
                    if (notification.lead_id) {
                        toast.onclick = () => window.location = '/leads/' + notification.lead_id;
                    }
                    document.getElementById('toast-container').appendChild(toast);
                    setTimeout(() => toast.remove(), 8000);
                });
        })();
    </script>
@endsection
