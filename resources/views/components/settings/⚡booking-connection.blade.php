<?php

use App\Models\AppSetting;
use App\Models\Facility;
use App\Models\SbBacSi;
use App\Models\SbRoom;
use App\Models\SbService;
use App\Models\SbUser;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

new class extends Component
{
    public string $bookingUrl = '';
    public string $bookingApiToken = '';
    /** @var array<int, string> facility_id => slug */
    public array $facilitySlugs = [];
    /** @var array<int, ?int> facility_id => sbooking_co_so_id */
    public array $facilitySbCoSoIds = [];
    public ?string $testResult = null;
    public ?string $testStatus = null; // 'ok' | 'err'
    public ?string $syncResult = null;
    public ?string $syncStatus = null; // 'ok' | 'err'

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasPermission('connection.manage'), 403);
        $this->bookingUrl = AppSetting::get('booking_url', (string) config('services.booking.url'));
        $this->bookingApiToken = AppSetting::get('booking_api_token', (string) config('services.booking.api_token'));
        $this->facilitySlugs = Facility::roots()->orderBy('name')->pluck('booking_co_so_slug', 'id')
            ->map(fn ($s) => (string) $s)->all();
        $this->facilitySbCoSoIds = Facility::roots()->orderBy('name')->pluck('sbooking_co_so_id', 'id')
            ->map(fn ($v) => $v ? (int) $v : null)->all();
        $this->userMappings = User::where('status', User::STATUS_ACTIVE)->orderBy('name')
            ->pluck('sbooking_user_id', 'id')
            ->map(fn ($v) => $v ? (int) $v : null)->all();
    }

    public function save(): void
    {
        $this->validate([
            'bookingUrl' => ['required', 'url', 'max:255'],
            'bookingApiToken' => ['nullable', 'string', 'max:255'],
            'facilitySlugs' => ['array'],
            'facilitySlugs.*' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9\-_]*$/i'],
            'facilitySbCoSoIds' => ['array'],
            'facilitySbCoSoIds.*' => ['nullable', 'integer', 'min:1'],
        ], [
            'facilitySlugs.*.regex' => 'Slug chỉ được chứa chữ / số / dấu - _ (VD: 59ntn, 207nvt, dn).',
        ]);

        AppSetting::set('booking_url', rtrim($this->bookingUrl, '/'));
        AppSetting::set('booking_api_token', $this->bookingApiToken);

        foreach ($this->facilitySlugs as $facilityId => $slug) {
            Facility::where('id', $facilityId)->update([
                'booking_co_so_slug' => trim($slug) ?: null,
                'sbooking_co_so_id'  => $this->facilitySbCoSoIds[$facilityId] ?? null,
            ]);
        }

        session()->flash('ok', 'Đã lưu cấu hình kết nối Booking + mapping cơ sở.');
    }

    public function syncServices(): void
    {
        $this->runSyncCommand('sb:sync-services', fn () => SbService::count() . ' dịch vụ');
    }

    public function syncRooms(): void
    {
        $this->runSyncCommand('sb:sync-rooms', fn () => SbRoom::count() . ' phòng');
    }

    public function syncBacSi(): void
    {
        $this->runSyncCommand('sb:sync-bac-si', fn () => SbBacSi::count() . ' bác sĩ');
    }

    public function syncUsers(): void
    {
        $this->runSyncCommand('sb:sync-users', fn () => SbUser::count() . ' users');
    }

    /** @var array<int, ?int> scrm.users.id => sbooking_user_id (từ sb_users.sbooking_id). */
    public array $userMappings = [];

    public function saveUserMappings(): void
    {
        abort_unless(auth()->user()?->hasPermission('connection.manage'), 403);
        foreach ($this->userMappings as $userId => $sbookingUserId) {
            User::where('id', (int) $userId)->update([
                'sbooking_user_id' => $sbookingUserId ? (int) $sbookingUserId : null,
            ]);
        }
        session()->flash('ok', 'Đã lưu mapping user scrm ↔ sbooking.');
    }

    private function runSyncCommand(string $signature, \Closure $countSummary): void
    {
        try {
            $exit = Artisan::call($signature);
            $output = trim(Artisan::output());
            if ($exit === 0) {
                $this->syncStatus = 'ok';
                $this->syncResult = 'OK · ' . $countSummary() . ' trong DB. ' . $output;
            } else {
                $this->syncStatus = 'err';
                $this->syncResult = 'Fail · ' . $output;
            }
        } catch (\Throwable $e) {
            $this->syncStatus = 'err';
            $this->syncResult = 'Exception: ' . $e->getMessage();
        }
    }

    public function getSbServicesCountProperty(): int
    {
        return SbService::count();
    }

    public function getSbServicesLastSyncProperty(): ?string
    {
        $last = SbService::max('synced_at');
        return $last ? \Carbon\Carbon::parse($last)->diffForHumans() : null;
    }

    public function getSbRoomsCountProperty(): int { return SbRoom::count(); }
    public function getSbRoomsLastSyncProperty(): ?string
    {
        $last = SbRoom::max('synced_at');
        return $last ? \Carbon\Carbon::parse($last)->diffForHumans() : null;
    }
    public function getSbBacSiCountProperty(): int { return SbBacSi::count(); }
    public function getSbBacSiLastSyncProperty(): ?string
    {
        $last = SbBacSi::max('synced_at');
        return $last ? \Carbon\Carbon::parse($last)->diffForHumans() : null;
    }
    public function getSbUsersCountProperty(): int { return SbUser::count(); }
    public function getSbUsersLastSyncProperty(): ?string
    {
        $last = SbUser::max('synced_at');
        return $last ? \Carbon\Carbon::parse($last)->diffForHumans() : null;
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
            <p class="text-xs text-ink/50 mb-3">Slug URL cho luồng cũ (chưa dùng nữa nhưng giữ backward compat). <strong>ID cơ sở sbooking</strong> dùng cho luồng mới: form Booking ở lead-form → auto tạo booking bên sbooking. Bỏ trống = chưa map, chỉ ghi log local (có nút "🔄 Thử lại" retry).</p>
            <div class="space-y-2">
                <div class="grid grid-cols-[1fr_auto_auto] items-center gap-3 text-xs font-semibold text-ink/50">
                    <div>Cơ sở SCRM</div>
                    <div class="w-40 text-center">Slug (cũ)</div>
                    <div class="w-32 text-center">Sbooking co_so_id</div>
                </div>
                @foreach (\App\Models\Facility::roots()->orderBy('name')->get() as $_fac)
                    <div class="grid grid-cols-[1fr_auto_auto] items-center gap-3">
                        <label class="text-sm">{{ $_fac->name }}</label>
                        <input type="text" wire:model="facilitySlugs.{{ $_fac->id }}"
                               placeholder="59ntn"
                               class="w-40 border border-gold-200 rounded-md px-3 py-1.5 text-sm font-mono focus:outline-none focus:border-gold-500">
                        <input type="number" min="1" wire:model="facilitySbCoSoIds.{{ $_fac->id }}"
                               placeholder="1"
                               class="w-32 border border-gold-200 rounded-md px-3 py-1.5 text-sm font-mono focus:outline-none focus:border-gold-500">
                    </div>
                    @error('facilitySlugs.' . $_fac->id)<p class="text-xs text-red-600 mt-0.5">{{ $message }}</p>@enderror
                    @error('facilitySbCoSoIds.' . $_fac->id)<p class="text-xs text-red-600 mt-0.5">{{ $message }}</p>@enderror
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

        <div class="border-t border-gold-100 pt-5">
            <h2 class="text-sm font-bold text-gold-700 mb-1">Đồng bộ dữ liệu từ Booking (Phase C1)</h2>
            <p class="text-xs text-ink/50 mb-3">Kéo danh mục dịch vụ, cơ sở, bác sĩ từ lara-sbooking về Data Source để dùng cho phase Booking. Sync 1 chiều, an toàn chạy lại nhiều lần (idempotent).</p>

            <div class="grid grid-cols-[1fr_auto] items-center gap-3 mb-3">
                <div>
                    <div class="text-sm font-semibold">Dịch vụ (dich_vu)</div>
                    <div class="text-xs text-ink/50">
                        Hiện có <strong>{{ $this->sbServicesCount }}</strong> dịch vụ trong Data Source.
                        @if ($this->sbServicesLastSync)
                            · Sync lần cuối {{ $this->sbServicesLastSync }}
                        @else
                            · Chưa từng sync.
                        @endif
                    </div>
                </div>
                <button wire:click="syncServices" type="button"
                        wire:loading.attr="disabled" wire:target="syncServices"
                        class="border border-gold-300 text-ink/70 hover:bg-gold-50 font-semibold text-sm px-4 py-2 rounded-md">
                    <span wire:loading.remove wire:target="syncServices">🔄 Đồng bộ dịch vụ</span>
                    <span wire:loading wire:target="syncServices">Đang đồng bộ…</span>
                </button>
            </div>

            {{-- Phase C1.d 2026-08-02: sync Phòng --}}
            <div class="grid grid-cols-[1fr_auto] items-center gap-3 mb-3">
                <div>
                    <div class="text-sm font-semibold">Phòng (phong)</div>
                    <div class="text-xs text-ink/50">
                        Hiện có <strong>{{ $this->sbRoomsCount }}</strong> phòng trong Data Source.
                        @if ($this->sbRoomsLastSync)
                            · Sync lần cuối {{ $this->sbRoomsLastSync }}
                        @else
                            · Chưa từng sync.
                        @endif
                        <span class="block text-[11px] text-ink/40 mt-0.5">Yêu cầu: đã map "Sbooking co_so_id" cho các Cơ sở ở trên.</span>
                    </div>
                </div>
                <button wire:click="syncRooms" type="button"
                        wire:loading.attr="disabled" wire:target="syncRooms"
                        class="border border-gold-300 text-ink/70 hover:bg-gold-50 font-semibold text-sm px-4 py-2 rounded-md">
                    <span wire:loading.remove wire:target="syncRooms">🔄 Đồng bộ phòng</span>
                    <span wire:loading wire:target="syncRooms">Đang đồng bộ…</span>
                </button>
            </div>

            {{-- Phase C1.d 2026-08-02: sync Bác sĩ --}}
            <div class="grid grid-cols-[1fr_auto] items-center gap-3 mb-3">
                <div>
                    <div class="text-sm font-semibold">Bác sĩ (bac_si)</div>
                    <div class="text-xs text-ink/50">
                        Hiện có <strong>{{ $this->sbBacSiCount }}</strong> bác sĩ trong Data Source.
                        @if ($this->sbBacSiLastSync)
                            · Sync lần cuối {{ $this->sbBacSiLastSync }}
                        @else
                            · Chưa từng sync.
                        @endif
                    </div>
                </div>
                <button wire:click="syncBacSi" type="button"
                        wire:loading.attr="disabled" wire:target="syncBacSi"
                        class="border border-gold-300 text-ink/70 hover:bg-gold-50 font-semibold text-sm px-4 py-2 rounded-md">
                    <span wire:loading.remove wire:target="syncBacSi">🔄 Đồng bộ bác sĩ</span>
                    <span wire:loading wire:target="syncBacSi">Đang đồng bộ…</span>
                </button>
            </div>

            {{-- Phase C1.e 2026-08-02: sync Users --}}
            <div class="grid grid-cols-[1fr_auto] items-center gap-3 mb-3">
                <div>
                    <div class="text-sm font-semibold">Users (nhân viên sbooking)</div>
                    <div class="text-xs text-ink/50">
                        Hiện có <strong>{{ $this->sbUsersCount }}</strong> user sbooking mirror trong Data Source.
                        @if ($this->sbUsersLastSync)
                            · Sync lần cuối {{ $this->sbUsersLastSync }}
                        @else
                            · Chưa từng sync.
                        @endif
                        <span class="block text-[11px] text-ink/40 mt-0.5">Cần sync trước khi map user bên dưới.</span>
                    </div>
                </div>
                <button wire:click="syncUsers" type="button"
                        wire:loading.attr="disabled" wire:target="syncUsers"
                        class="border border-gold-300 text-ink/70 hover:bg-gold-50 font-semibold text-sm px-4 py-2 rounded-md">
                    <span wire:loading.remove wire:target="syncUsers">🔄 Đồng bộ users</span>
                    <span wire:loading wire:target="syncUsers">Đang đồng bộ…</span>
                </button>
            </div>
        </div>

        {{-- Phase C1.e 2026-08-02: mapping user scrm ↔ sbooking --}}
        <div class="border-t border-gold-100 pt-5">
            <h2 class="text-sm font-bold text-gold-700 mb-1">Map user SCRM ↔ Sbooking</h2>
            <p class="text-xs text-ink/50 mb-3">Khi user scrm được chọn làm CV#1 cho 1 booking, hệ thống push CV#1 → sbooking.sale_id qua mapping này. Chưa map → sale_id gửi null, Admin sbooking gán tay.</p>
            @php $sbUsersForPick = SbUser::orderBy('ten')->get(); @endphp
            <div class="border border-gold-100 rounded-lg divide-y divide-gold-100 max-h-96 overflow-y-auto">
                <div class="grid grid-cols-[1fr_1.2fr] gap-3 px-3 py-2 text-xs font-semibold text-ink/50 bg-gold-50/60 sticky top-0">
                    <div>Nhân viên SCRM</div>
                    <div>Map sang sbooking user</div>
                </div>
                @foreach (User::where('status', User::STATUS_ACTIVE)->orderBy('name')->get() as $_u)
                    <div class="grid grid-cols-[1fr_1.2fr] gap-3 items-center px-3 py-2 text-sm">
                        <div>
                            <div class="font-medium">{{ $_u->name }}</div>
                            <div class="text-xs text-ink/40">{{ $_u->email ?: $_u->username }}</div>
                        </div>
                        <select wire:model="userMappings.{{ $_u->id }}"
                                class="w-full border border-gold-200 rounded-md px-2 py-1 text-sm focus:outline-none focus:border-gold-500">
                            <option value="">— Chưa map —</option>
                            @foreach ($sbUsersForPick as $_sbu)
                                <option value="{{ $_sbu->sbooking_id }}">
                                    {{ $_sbu->displayName() }} @if ($_sbu->email) · {{ $_sbu->email }} @endif
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
            </div>
            <button wire:click="saveUserMappings" type="button"
                    class="mt-3 bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-5 py-2 rounded-md">
                Lưu mapping user
            </button>

            @if ($syncResult)
                <div class="text-xs p-2 rounded {{ $syncStatus === 'ok' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800' }}">{{ $syncResult }}</div>
            @endif

            <p class="text-xs text-ink/40 mt-3">CLI: <code>php artisan sb:sync-services</code> (thêm <code>--dry-run</code> để xem không ghi DB).</p>
        </div>
    </div>
</div>
