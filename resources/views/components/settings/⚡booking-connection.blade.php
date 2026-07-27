<?php

use App\Models\AppSetting;
use App\Models\Facility;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

new class extends Component
{
    public string $bookingUrl = '';
    public string $bookingApiToken = '';
    /** @var array<int, string> facility_id => slug */
    public array $facilitySlugs = [];
    public ?string $testResult = null;
    public ?string $testStatus = null; // 'ok' | 'err'

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasPermission('connection.manage'), 403);
        $this->bookingUrl = AppSetting::get('booking_url', (string) config('services.booking.url'));
        $this->bookingApiToken = AppSetting::get('booking_api_token', (string) config('services.booking.api_token'));
        $this->facilitySlugs = Facility::roots()->orderBy('name')->pluck('booking_co_so_slug', 'id')
            ->map(fn ($s) => (string) $s)->all();
    }

    public function save(): void
    {
        $this->validate([
            'bookingUrl' => ['required', 'url', 'max:255'],
            'bookingApiToken' => ['nullable', 'string', 'max:255'],
            'facilitySlugs' => ['array'],
            'facilitySlugs.*' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9\-_]*$/i'],
        ], [
            'facilitySlugs.*.regex' => 'Slug chỉ được chứa chữ / số / dấu - _ (VD: 59ntn, 207nvt, dn).',
        ]);

        AppSetting::set('booking_url', rtrim($this->bookingUrl, '/'));
        AppSetting::set('booking_api_token', $this->bookingApiToken);

        foreach ($this->facilitySlugs as $facilityId => $slug) {
            Facility::where('id', $facilityId)->update(['booking_co_so_slug' => trim($slug) ?: null]);
        }

        session()->flash('ok', 'Đã lưu cấu hình kết nối Booking + mapping cơ sở.');
    }

    public function testConnection(): void
    {
        $url = rtrim($this->bookingUrl ?: '', '/') . '/api/bookings?per_page=1';
        try {
            $r = Http::withToken($this->bookingApiToken)->acceptJson()->timeout(6)->get($url);
            if ($r->successful()) {
                $j = $r->json();
                $this->testStatus = 'ok';
                $this->testResult = 'OK · tổng booking = ' . ($j['meta']['total'] ?? '?');
            } else {
                $this->testStatus = 'err';
                $this->testResult = 'HTTP ' . $r->status() . ' · ' . substr((string) $r->body(), 0, 200);
            }
        } catch (\Throwable $e) {
            $this->testStatus = 'err';
            $this->testResult = 'Lỗi mạng: ' . $e->getMessage();
        }
    }
};
?>

<div class="max-w-3xl mx-auto p-6">
    <div class="mb-6">
        <div class="text-sm text-ink/50 mb-1">
            <a href="{{ route('settings.index') }}" class="hover:text-gold-600">Thiết lập</a>
            <span class="mx-1">›</span>
            <span class="text-gold-700 font-medium">Kết nối Booking</span>
        </div>
        <h1 class="text-2xl font-bold">Kết nối Booking</h1>
        <p class="text-sm text-ink/60 mt-1">Cấu hình URL &amp; token của hệ thống lara-sbooking. Dùng cho nút <em>Đặt booking</em> ở chi tiết khách hàng và các đồng bộ API sau này.</p>
    </div>

    @if (session('ok'))
        <div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-800 rounded text-sm">{{ session('ok') }}</div>
    @endif

    <div class="bg-white border border-gold-100 rounded-lg p-6 space-y-5">
        <div>
            <label class="block text-sm font-semibold mb-1">Booking URL</label>
            <input type="url" wire:model="bookingUrl" placeholder="https://booking.longevity.com.vn"
                   class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm font-mono">
            @error('bookingUrl')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            <p class="text-xs text-ink/50 mt-1">Không có dấu <code>/</code> ở cuối. Ghi đè biến env <code>BOOKING_URL</code>.</p>
        </div>

        <div>
            <label class="block text-sm font-semibold mb-1">Booking API Token</label>
            <input type="text" wire:model="bookingApiToken" placeholder="Dán token đã tạo bên Booking..."
                   class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm font-mono">
            @error('bookingApiToken')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            <p class="text-xs text-ink/50 mt-1">Phải trùng với <code>SCRM_API_TOKEN</code> bên lara-sbooking. Chỉ dùng cho API server-to-server; nút "Đặt booking" hiện tại không cần token.</p>
        </div>

        <div class="border-t border-gold-100 pt-5">
            <h2 class="text-sm font-bold text-gold-700 mb-1">Mapping cơ sở SCRM ↔ Booking</h2>
            <p class="text-xs text-ink/50 mb-3">Nhập <strong>slug URL cơ sở bên lara-sbooking</strong> cho từng cơ sở SCRM. Nút "Đặt booking" ở chi tiết khách hàng ghép URL theo: <code>{{ rtrim($bookingUrl ?: 'BOOKING_URL', '/') }}/&lt;slug&gt;/tao-moi</code>. Bỏ trống = chưa map, nút bị disable với khách của cơ sở đó.</p>
            <div class="space-y-2">
                @foreach (\App\Models\Facility::roots()->orderBy('name')->get() as $_fac)
                    <div class="grid grid-cols-[1fr_auto] items-center gap-3">
                        <label class="text-sm">{{ $_fac->name }}</label>
                        <input type="text" wire:model="facilitySlugs.{{ $_fac->id }}"
                               placeholder="VD: 59ntn, 207nvt, dn"
                               class="w-40 border border-gold-200 rounded-md px-3 py-1.5 text-sm font-mono focus:outline-none focus:border-gold-500">
                    </div>
                    @error('facilitySlugs.' . $_fac->id)<p class="text-xs text-red-600 mt-0.5">{{ $message }}</p>@enderror
                @endforeach
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button wire:click="save" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-5 py-2 rounded-md">Lưu</button>
            <button wire:click="testConnection" type="button" class="border border-gold-300 text-ink/70 hover:bg-gold-50 font-semibold text-sm px-5 py-2 rounded-md">Test kết nối</button>
            @if ($testResult)
                <span class="text-sm {{ $testStatus === 'ok' ? 'text-green-700' : 'text-red-700' }}">{{ $testResult }}</span>
            @endif
        </div>
    </div>
</div>
