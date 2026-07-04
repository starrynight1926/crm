<?php

use Illuminate\Support\Facades\DB;
use Livewire\Component;

new class extends Component
{
    public function endSession(string $sessionId): void
    {
        if ($sessionId === session()->getId()) {
            return; // không tự kết thúc phiên hiện tại ở đây, dùng nút Đăng xuất
        }

        DB::table('sessions')
            ->where('id', $sessionId)
            ->where('user_id', auth()->id())
            ->delete();
    }

    public function endAllOtherSessions(): void
    {
        DB::table('sessions')
            ->where('user_id', auth()->id())
            ->where('id', '!=', session()->getId())
            ->delete();
    }

    public function revokeToken(int $tokenId): void
    {
        auth()->user()->tokens()->where('id', $tokenId)->delete();
    }

    public function with(): array
    {
        $sessions = DB::table('sessions')
            ->where('user_id', auth()->id())
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn ($s) => (object) [
                'id' => $s->id,
                'ip' => $s->ip_address,
                'device' => $this->deviceLabel($s->user_agent ?? ''),
                'is_mobile' => $this->isMobile($s->user_agent ?? ''),
                'last_activity' => \Carbon\Carbon::createFromTimestamp($s->last_activity),
                'is_current' => $s->id === session()->getId(),
            ]);

        return [
            'sessions' => $sessions,
            'tokens' => auth()->user()->tokens()->orderByDesc('last_used_at')->get(),
        ];
    }

    private function deviceLabel(string $ua): string
    {
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Thiết bị không rõ',
        };

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => null,
        };

        return $browser ? "$os — $browser" : $os;
    }

    private function isMobile(string $ua): bool
    {
        return str_contains($ua, 'iPhone') || str_contains($ua, 'Android') || str_contains($ua, 'Mobile');
    }
};
?>

<div class="max-w-5xl">
    <div class="flex flex-wrap items-start justify-between gap-4 mb-8">
        <div>
            <div class="text-xs font-semibold tracking-[0.2em] text-gold-600 uppercase mb-2">An ninh tài khoản</div>
            <h1 class="text-3xl font-bold mb-3">Quản lý phiên đăng nhập</h1>
            <p class="text-sm text-ink/60 max-w-xl">
                Kiểm soát và quản lý các thiết bị đang truy cập vào tài khoản của bạn. Đảm bảo tính bảo mật bằng cách
                kết thúc các phiên làm việc không nhận diện được hoặc đã lâu không sử dụng.
            </p>
        </div>
        <button wire:click="endAllOtherSessions"
                wire:confirm="Kết thúc tất cả phiên trên các thiết bị khác?"
                class="bg-gold-600 hover:bg-gold-700 text-white text-xs font-semibold tracking-widest uppercase px-5 py-3 rounded-md flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-7.5A2.25 2.25 0 003.75 5.25v13.5A2.25 2.25 0 006 21h7.5a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9"/>
            </svg>
            Đăng xuất khỏi tất cả thiết bị
        </button>
    </div>

    @php
        $current = $sessions->firstWhere('is_current', true);
        $others = $sessions->where('is_current', false);
    @endphp

    {{-- Phiên hiện tại --}}
    @if ($current)
        <div class="bg-gold-50 border border-gold-300 rounded-xl p-6 mb-6 flex items-center gap-5">
            <div class="w-14 h-14 rounded-lg bg-gold-100 border border-gold-200 flex items-center justify-center text-gold-700">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25"/>
                </svg>
            </div>
            <div class="flex-1">
                <div class="font-bold text-lg">{{ $current->device }}</div>
                <div class="text-sm text-ink/60">IP: {{ $current->ip }}</div>
                <div class="flex items-center gap-1.5 text-xs font-semibold tracking-widest text-gold-700 uppercase mt-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
                    </svg>
                    Đang hoạt động
                </div>
            </div>
            <span class="text-xs font-semibold tracking-widest uppercase bg-gold-100 border border-gold-300 text-gold-700 px-4 py-1.5 rounded-full">
                Phiên hiện tại
            </span>
        </div>
    @endif

    {{-- Các thiết bị khác --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card mb-6">
        <div class="px-6 py-5 border-b border-gold-100">
            <h2 class="text-lg font-bold">Các thiết bị khác</h2>
        </div>
        @forelse ($others as $s)
            <div class="px-6 py-5 flex items-center gap-5 {{ !$loop->last ? 'border-b border-gold-100' : '' }}">
                <div class="w-12 h-12 rounded-lg bg-cream border border-gold-100 flex items-center justify-center text-ink/60">
                    @if ($s->is_mobile)
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3"/>
                        </svg>
                    @else
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25"/>
                        </svg>
                    @endif
                </div>
                <div class="flex-1">
                    <div class="font-bold">{{ $s->device }}</div>
                    <div class="text-sm text-ink/60">{{ $s->last_activity->diffForHumans() }}</div>
                    <div class="text-sm text-ink/50 mt-0.5">IP: {{ $s->ip }}</div>
                </div>
                <button wire:click="endSession('{{ $s->id }}')"
                        wire:confirm="Kết thúc phiên trên thiết bị này?"
                        class="text-xs font-semibold tracking-widest uppercase text-gold-700 border border-gold-300 hover:bg-gold-50 px-5 py-2.5 rounded-md">
                    Kết thúc phiên
                </button>
            </div>
        @empty
            <div class="px-6 py-8 text-sm text-ink/50 text-center">Không có thiết bị nào khác đang đăng nhập.</div>
        @endforelse
    </div>

    {{-- API tokens (Sanctum) --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card">
        <div class="px-6 py-5 border-b border-gold-100">
            <h2 class="text-lg font-bold">Thiết bị API (token)</h2>
            <p class="text-sm text-ink/50 mt-1">Token Sanctum cấp cho ứng dụng ngoài. Thu hồi token sẽ chặn truy cập ngay lập tức.</p>
        </div>
        @forelse ($tokens as $token)
            <div class="px-6 py-5 flex items-center gap-5 {{ !$loop->last ? 'border-b border-gold-100' : '' }}">
                <div class="flex-1">
                    <div class="font-bold">{{ $token->device_name ?? $token->name }}</div>
                    <div class="text-sm text-ink/60">
                        {{ $token->last_used_at ? 'Dùng lần cuối ' . $token->last_used_at->diffForHumans() : 'Chưa sử dụng' }}
                        @if ($token->ip) · IP: {{ $token->ip }} @endif
                    </div>
                </div>
                <button wire:click="revokeToken({{ $token->id }})"
                        wire:confirm="Thu hồi token này?"
                        class="text-xs font-semibold tracking-widest uppercase text-red-700 border border-red-200 hover:bg-red-50 px-5 py-2.5 rounded-md">
                    Thu hồi
                </button>
            </div>
        @empty
            <div class="px-6 py-8 text-sm text-ink/50 text-center">Chưa có token API nào được cấp.</div>
        @endforelse
    </div>
</div>
