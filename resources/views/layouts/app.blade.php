@extends('layouts.base')

@section('body')
    @php
        $navItems = [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'match' => 'dashboard'],
            ['label' => 'Khách hàng', 'route' => auth()->user()->hasPermission('lead.view') ? 'leads.index' : null, 'match' => 'leads.*'],
            ['label' => 'Tổ chức', 'route' => auth()->user()->hasPermission('user.manage') ? 'org.users' : null, 'match' => 'org.*'],
            ['label' => 'Chia số', 'route' => auth()->user()->hasPermission('rule.manage') ? 'distribution.rules' : (auth()->user()->hasPermission('lead.view') ? 'distribution.pools' : null), 'match' => 'distribution.*'],
            ['label' => 'Dịch vụ', 'route' => auth()->user()->hasPermission('service.manage') ? 'services.catalog' : null, 'match' => 'services.*'],
            ['label' => 'Thu tiền', 'route' => auth()->user()->hasPermission('payment.record') ? 'payments.index' : null, 'match' => 'payments.*'],
            ['label' => 'Báo cáo', 'route' => auth()->user()->hasPermission('report.view') ? 'reports.index' : null, 'match' => 'reports.*'],
            ['label' => 'Cài đặt', 'route' => 'sessions.index', 'match' => 'sessions.*'],
        ];
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
                    <span class="text-lg md:text-xl font-bold text-gold-700 tracking-tight">Aureum CRM</span>
                </a>

                <nav class="hidden md:flex items-center gap-0.5 lg:gap-1 text-sm font-medium">
                    @foreach ($navItems as $item)
                        @if ($item['route'])
                            <a href="{{ route($item['route']) }}"
                               class="px-2.5 lg:px-3 py-2 rounded-md whitespace-nowrap {{ request()->routeIs($item['match']) ? 'text-gold-700 font-semibold' : 'text-ink/70 hover:text-gold-700' }}">
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
                        <a href="{{ route('sessions.index') }}" class="block px-4 py-2 hover:bg-gold-50">Quản lý phiên đăng nhập</a>
                        @if (auth()->user()->hasPermission('field.manage') || auth()->user()->hasPermission('field.approve'))
                            <a href="{{ route('settings.index') }}" class="block px-4 py-2 hover:bg-gold-50">Thiết lập</a>
                        @endif
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
                    @if ($item['route'])
                        <a href="{{ route($item['route']) }}"
                           class="block px-3 py-2.5 rounded-md text-sm font-medium {{ request()->routeIs($item['match']) ? 'bg-gold-50 text-gold-700 font-semibold' : 'text-ink/70 hover:bg-gold-50' }}">
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
            Aureum CRM · Quản lý quan hệ khách hàng
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

            window.EchoClient = new Echo({
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
