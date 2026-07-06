@extends('layouts.base')

@section('body')
    <div class="min-h-screen flex flex-col">
        <div class="h-1.5 bg-gold-700"></div>
        <main class="flex-1 flex flex-col items-center justify-center px-4 py-10">
            @yield('content')
        </main>
        <footer class="py-6 text-center text-xs tracking-widest text-gold-400 uppercase">
            © {{ date('Y') }} Longevity CRM Enterprise. All rights reserved.
        </footer>
    </div>
@endsection
