@extends('layouts.guest')

@section('title', 'Đăng nhập hệ thống')

@section('content')
    <div class="flex flex-col items-center mb-8">
        <div class="w-14 h-14 rounded-lg border border-gold-300 bg-gold-50 flex items-center justify-center mb-4">
            <svg class="w-7 h-7 text-gold-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l9 5.25v1.5H3v-1.5L12 3zM4.5 11.25h2v7.5h-2v-7.5zm6.5 0h2v7.5h-2v-7.5zm6.5 0h2v7.5h-2v-7.5zM3 20.25h18v1.5H3v-1.5z"/>
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-gold-700 tracking-wide uppercase">Aureum CRM</h1>
        <p class="text-xs tracking-[0.2em] text-ink/50 uppercase mt-1">Executive Wealth Management Portal</p>
    </div>

    <div class="w-full max-w-md bg-white border border-gold-200 rounded-xl shadow-card px-10 py-9">
        <h2 class="text-xl font-bold mb-1">Chào mừng trở lại</h2>
        <p class="text-sm text-ink/60 mb-7">Vui lòng nhập thông tin để truy cập hệ thống.</p>

        <form method="POST" action="{{ route('login') }}" x-data="{ showPassword: false }">
            @csrf

            <label class="block text-xs font-semibold tracking-widest text-ink/60 uppercase mb-2">Địa chỉ email</label>
            <div class="flex items-center gap-2 border-b {{ $errors->has('email') ? 'border-red-400' : 'border-gold-200' }} focus-within:border-gold-600 pb-2 mb-1">
                <svg class="w-5 h-5 text-gold-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/>
                </svg>
                <input type="email" name="email" value="{{ old('email') }}" required autofocus
                       placeholder="name@enterprise.com"
                       class="w-full text-sm bg-transparent focus:outline-none placeholder:text-ink/30">
            </div>
            @error('email')
                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
            @enderror

            <div class="flex items-end justify-between mt-6 mb-2">
                <label class="block text-xs font-semibold tracking-widest text-ink/60 uppercase">Mật khẩu</label>
                <span class="text-xs font-semibold text-gold-600 cursor-not-allowed" title="Sắp có">Quên mật khẩu?</span>
            </div>
            <div class="flex items-center gap-2 border-b border-gold-200 focus-within:border-gold-600 pb-2 mb-6">
                <svg class="w-5 h-5 text-gold-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
                </svg>
                <input :type="showPassword ? 'text' : 'password'" name="password" required
                       placeholder="••••••••"
                       class="w-full text-sm bg-transparent focus:outline-none placeholder:text-ink/30">
                <button type="button" @click="showPassword = !showPassword" class="text-gold-400 hover:text-gold-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                </button>
            </div>

            <label class="flex items-center gap-2 text-sm text-ink/70 mb-7 cursor-pointer">
                <input type="checkbox" name="remember" class="rounded border-gold-300 text-gold-600 focus:ring-gold-500">
                Ghi nhớ đăng nhập
            </label>

            <button type="submit"
                    class="w-full bg-gold-600 hover:bg-gold-700 text-white font-semibold py-3.5 rounded-md flex items-center justify-center gap-2 transition-colors">
                Đăng nhập
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/>
                </svg>
            </button>
        </form>

        <div class="border-t border-gold-100 mt-8 pt-5 text-center text-sm text-ink/60">
            Bạn gặp sự cố khi đăng nhập? <span class="text-gold-600 font-medium">Liên hệ quản trị viên</span>
        </div>
    </div>
@endsection
