<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Aureum CRM') — {{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['"Be Vietnam Pro"', 'sans-serif'] },
                    colors: {
                        gold: {
                            50: '#FBF8F2', 100: '#F5EEDF', 200: '#E8DCC3', 300: '#D6C29A',
                            400: '#C0A467', 500: '#A8863C', 600: '#8B5E14', 700: '#75510F',
                            800: '#5C400C', 900: '#453008',
                        },
                        cream: '#FAF7F2',
                        ink: '#2D2A24',
                    },
                    boxShadow: { card: '0 1px 3px rgba(69, 48, 8, 0.08)' },
                },
            },
        };
    </script>
    <style>
        [x-cloak] { display: none !important; }
        /* Checkbox/radio theo màu vàng đồng thay vì xanh mặc định của trình duyệt */
        input[type=checkbox], input[type=radio] { accent-color: #8B5E14; }
        /* Cuộn ngang bảng/tab mượt, ẩn thanh cuộn thô trên mobile */
        .overflow-x-auto { -webkit-overflow-scrolling: touch; }
    </style>
    {{-- Alpine.js KHÔNG nạp riêng: Livewire đã bundle sẵn Alpine, nạp 2 instance sẽ làm wire:click chập chờn --}}
    @livewireStyles
</head>
<body class="bg-cream text-ink font-sans antialiased min-h-screen">
    @yield('body')
    @livewireScripts
</body>
</html>
