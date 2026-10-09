<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Changelog — Longevity Data Source</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: {
            fontFamily: { sans: ['"Be Vietnam Pro"', 'sans-serif'] },
            colors: {
                gold: { 50:'#FBF8F1', 100:'#F5EDD8', 200:'#E8D5A8', 400:'#C0A467', 500:'#A8863C', 600:'#8B5E14', 700:'#75510F' },
                cream: '#FAF7F2', ink: '#2D2A24',
                emerald: { 600: '#059669' },
            },
        }}};
    </script>
</head>
<body class="bg-cream min-h-screen">
    <div class="max-w-3xl mx-auto px-4 py-10">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gold-700">Changelog</h1>
                <p class="text-sm text-ink/60 mt-1">Lịch sử phát hành hệ thống Longevity Data Source.</p>
            </div>
            <a href="{{ url('/') }}" class="text-sm text-gold-600 hover:text-gold-700">← Về trang chính</a>
        </div>

        @php $versions = \App\Support\Changelog::all(); @endphp

        @if (empty($versions))
            <div class="text-center py-16 text-ink/50">Chưa có bản phát hành nào.</div>
        @else
            <div class="space-y-6">
                @foreach ($versions as $i => $v)
                    <div class="bg-white border border-gold-200 rounded-xl px-6 py-5 shadow">
                        <div class="flex items-baseline gap-3 mb-3 flex-wrap">
                            <span class="px-2.5 py-1 rounded-md text-sm font-bold {{ $i === 0 ? 'bg-gold-600 text-white' : 'bg-gold-50 text-gold-700 border border-gold-200' }}">
                                {{ $v['version'] }}
                            </span>
                            <span class="text-xs text-ink/50">{{ $v['date'] }}</span>
                            @if ($i === 0)
                                <span class="text-xs font-semibold text-emerald-600 uppercase tracking-widest">Mới nhất</span>
                            @endif
                        </div>
                        <ul class="space-y-1.5 text-sm text-ink/80">
                            @foreach ($v['items'] as $item)
                                <li class="flex gap-2">
                                    <span class="text-gold-500 mt-1">•</span>
                                    <span>{{ $item }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</body>
</html>
