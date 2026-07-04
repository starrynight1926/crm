<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login demo — chọn nhân vật</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: {
            fontFamily: { sans: ['"Be Vietnam Pro"', 'sans-serif'] },
            colors: { gold: { 400:'#C0A467',500:'#A8863C',600:'#8B5E14',700:'#75510F' }, cream: '#FAF7F2', ink: '#2D2A24' },
        }}};
    </script>
</head>
<body class="bg-cream text-ink font-sans antialiased min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-lg">
        <div class="text-center mb-8">
            <h1 class="text-2xl font-bold text-gold-700">Login demo</h1>
            <p class="text-sm text-ink/60 mt-1">Chọn một nhân vật để xem dữ liệu theo phân quyền.</p>
        </div>

        <div class="grid gap-3">
            @foreach ($personas as $key => $p)
                <a href="{{ route('demo.loginAs', $key) }}"
                   class="flex items-center gap-4 bg-white border border-gold-400/30 rounded-xl px-5 py-4 shadow-sm hover:border-gold-600 hover:shadow-md transition">
                    <div class="w-11 h-11 rounded-full flex items-center justify-center text-white font-bold text-lg
                                {{ $p['manager'] ? 'bg-gold-700' : 'bg-gold-500' }}">
                        {{ $p['manager'] ? 'QL' : mb_strtoupper(mb_substr($p['name'], -1)) }}
                    </div>
                    <div class="flex-1">
                        <div class="font-semibold">{{ $p['name'] }}</div>
                        <div class="text-xs text-ink/50">
                            {{ $p['manager'] ? 'Thấy dữ liệu của tất cả nhân viên' : 'Chỉ thấy dữ liệu mình upload' }}
                        </div>
                    </div>
                    <svg class="w-5 h-5 text-gold-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/>
                    </svg>
                </a>
            @endforeach
        </div>

        <div class="text-center mt-6">
            <a href="{{ route('login') }}" class="text-sm text-ink/50 hover:text-gold-700">← Về trang đăng nhập</a>
        </div>
    </div>
</body>
</html>
