<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Demo Staging')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: {
                fontFamily: { sans: ['"Be Vietnam Pro"', 'sans-serif'] },
                colors: {
                    gold: { 400:'#C0A467',500:'#A8863C',600:'#8B5E14',700:'#75510F' },
                    cream: '#FAF7F2', ink: '#2D2A24',
                },
            }},
        };
    </script>
    <style>input[type=checkbox],input[type=radio]{accent-color:#8B5E14}[x-cloak]{display:none!important}</style>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-cream text-ink font-sans antialiased min-h-screen">
    <header class="bg-white border-b border-gold-400/30 shadow-sm">
        <div class="max-w-6xl mx-auto px-4 py-3 flex items-center gap-4 flex-wrap">
            <span class="font-bold text-gold-700 text-lg">Demo Staging</span>
            <nav class="flex items-center gap-1 text-sm">
                @php $r = request()->route()->getName(); @endphp
                <a href="{{ route('demo.rules') }}" class="px-3 py-1.5 rounded-lg {{ $r==='demo.rules'?'bg-gold-600 text-white':'hover:bg-gold-400/10' }}">1. Quy tắc trường</a>
                <a href="{{ route('demo.upload') }}" class="px-3 py-1.5 rounded-lg {{ in_array($r,['demo.upload','demo.map'])?'bg-gold-600 text-white':'hover:bg-gold-400/10' }}">2. Nhập file</a>
                <a href="{{ route('demo.leads') }}"  class="px-3 py-1.5 rounded-lg {{ $r==='demo.leads'?'bg-gold-600 text-white':'hover:bg-gold-400/10' }}">Danh sách</a>
                <a href="{{ route('demo.report') }}" class="px-3 py-1.5 rounded-lg {{ $r==='demo.report'?'bg-gold-600 text-white':'hover:bg-gold-400/10' }}">Báo cáo</a>
            </nav>
            <div class="ml-auto flex items-center gap-2">
                @php $dw = session('demo_user'); $dp = $dw ? (\App\Http\Controllers\DemoStagingController::PERSONAS[$dw] ?? null) : null; @endphp
                @if ($dp)
                    <span class="text-xs px-2.5 py-1 rounded-full {{ $dp['manager'] ? 'bg-gold-700 text-white' : 'bg-gold-400/20 text-gold-700' }}">
                        {{ $dp['name'] }}{{ $dp['manager'] ? ' • thấy tất cả' : '' }}
                    </span>
                    <a href="{{ route('demo.login') }}" class="text-xs px-3 py-1.5 rounded-lg border border-gold-400/40 hover:bg-gold-400/10">Đổi nhân vật</a>
                @endif
                <form method="POST" action="{{ route('demo.reset') }}"
                      onsubmit="return confirm('Xóa TOÀN BỘ dữ liệu demo staging?')">
                    @csrf
                    <button class="text-xs px-3 py-1.5 rounded-lg border border-red-300 text-red-600 hover:bg-red-50">Reset dữ liệu</button>
                </form>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-6">
        @if (session('reset'))
            <div class="mb-4 rounded-lg bg-green-50 border border-green-200 text-green-700 px-4 py-2 text-sm">Đã reset toàn bộ dữ liệu demo.</div>
        @endif
        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-700 px-4 py-2 text-sm">
                @foreach ($errors->all() as $e) <div>• {{ $e }}</div> @endforeach
            </div>
        @endif
        @yield('content')
    </main>
</body>
</html>
