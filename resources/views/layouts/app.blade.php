@extends('layouts.base')

@section('body')
    @php
        $u = auth()->user();
        $customerChildren = array_values(array_filter([
            ['label' => 'Danh sách khách hàng', 'route' => $u->hasPermission('lead.view') ? 'leads.index' : null, 'match' => 'leads.index'],
            ['label' => 'Thêm khách hàng', 'route' => (!$u->hasPermission('lead.view') && $u->hasPermission('lead.create')) ? 'leads.create' : null, 'match' => 'leads.create'],
            ['label' => 'Chia số', 'route' => $u->hasPermission('rule.manage') ? 'distribution.rules' : ($u->hasPermission('lead.view') ? 'distribution.pools' : null), 'match' => 'distribution.*'],
            ['label' => 'Duyệt Lead', 'route' => $u->hasPermission('lead.approve_source') ? 'leads.approvals' : null, 'match' => 'leads.approvals'],
        ], fn ($i) => $i['route']));
        $bizChildren = array_values(array_filter([
            ['label' => 'Dịch vụ', 'route' => $u->hasPermission('service.manage') ? 'services.catalog' : null, 'match' => 'services.*'],
            ['label' => 'Thu tiền', 'route' => $u->hasPermission('payment.record') ? 'payments.index' : null, 'match' => 'payments.*'],
        ], fn ($i) => $i['route']));
        $opsChildren = array_values(array_filter([
            ['label' => 'Tổ chức', 'route' => $u->hasPermission('user.manage') ? 'org.users' : null, 'match' => 'org.*'],
            ['label' => 'Báo cáo', 'route' => $u->hasAnyPermission(['report.view', 'report.view_all']) ? 'reports.index' : null, 'match' => 'reports.*'],
            ['label' => 'Quy tắc vận hành', 'route' => $u->hasPermission('ops.manage') ? 'ops.rules' : null, 'match' => 'ops.*'],
        ], fn ($i) => $i['route']));

        $navItems = array_values(array_filter([
            ['label' => 'Dashboard', 'route' => 'dashboard', 'match' => 'dashboard'],
            !empty($customerChildren) ? ['label' => 'Khách hàng', 'match' => 'leads.*|distribution.*', 'children' => $customerChildren] : null,
            !empty($bizChildren) ? ['label' => 'Kinh doanh', 'match' => 'services.*|payments.*', 'children' => $bizChildren] : null,
            !empty($opsChildren) ? ['label' => 'Vận hành', 'match' => 'org.*|reports.*|ops.*', 'children' => $opsChildren] : null,
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
                    <span class="text-lg md:text-xl font-bold text-gold-700 tracking-tight">Longevity CRM</span>
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
                         class="absolute right-4 md:right-6 top-14 w-56 bg-white border border-gold-200 rounded-lg shadow-card py-1 text-sm">
                        <div class="px-4 py-2 border-b border-gold-100 lg:hidden">
                            <div class="font-semibold truncate">{{ auth()->user()->name }}</div>
                            <div class="text-xs text-ink/50 truncate">{{ auth()->user()->email }}</div>
                        </div>
                        @php
                            // Ẩn "Cài đặt" nếu user không có bất kỳ quyền quản trị nào
                            $adminPerms = ['user.manage', 'role.manage', 'org.manage', 'field.manage', 'field.approve', 'rule.manage', 'service.manage', 'connection.manage', 'ops.manage', 'lead.import'];
                        @endphp
                        @if (auth()->user()->hasAnyPermission($adminPerms))
                            <a href="{{ route('settings.index') }}" class="block px-4 py-2 hover:bg-gold-50">Cài đặt</a>
                        @endif
                        <a href="{{ route('sessions.index') }}" class="block px-4 py-2 hover:bg-gold-50">Quản lý phiên đăng nhập</a>
                        @if (auth()->user()->hasPermission('connection.manage'))
                            <a href="{{ route('sources.index') }}" class="block px-4 py-2 hover:bg-gold-50">Kết nối nguồn lead</a>
                        @endif
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

        <main class="flex-1 max-w-screen-2xl w-full mx-auto px-4 md:px-6 py-6 md:py-8">
            @yield('content')
            {{ $slot ?? '' }}
        </main>

        <footer class="py-6 text-center text-xs tracking-widest text-gold-400 uppercase border-t border-gold-100">
            Longevity CRM · Quản lý quan hệ khách hàng
        </footer>

        {{-- Toast realtime (Reverb) --}}
        <div id="toast-container" class="fixed bottom-6 right-6 z-[60] space-y-2"></div>
    </div>

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
