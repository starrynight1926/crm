@extends('layouts.base')

@section('body')
    <div class="min-h-screen flex flex-col">
        <div class="h-1.5 bg-gold-700"></div>
        <main class="flex-1 flex flex-col items-center justify-center px-4 py-10">
            @yield('content')
        </main>
        <footer class="py-6 flex items-center justify-center gap-3 text-xs tracking-widest text-gold-400 uppercase">
            <span>© {{ date('Y') }} Longevity Data Source Enterprise. All rights reserved.</span>
            @php $__latestVer = \App\Support\Changelog::latest(); @endphp
            @if ($__latestVer)
                <a href="{{ route('changelog') }}" class="px-2 py-0.5 rounded-md bg-gold-50 border border-gold-200 text-gold-700 hover:bg-gold-100 normal-case tracking-normal font-semibold" title="Xem changelog">{{ $__latestVer['version'] }}</a>
            @endif
        </footer>
    </div>
@endsection
