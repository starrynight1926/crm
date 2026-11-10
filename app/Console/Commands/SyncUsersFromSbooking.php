<?php

namespace App\Console\Commands;

use App\Models\SbUser;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase C1.e (2026-08-02) — Kéo users từ sbooking về sb_users.
 * Sync 1 chiều, upsert theo sbooking_id. Idempotent.
 *
 * Usage: php artisan sb:sync-users
 */
class SyncUsersFromSbooking extends Command
{
    protected $signature = 'sb:sync-users {--dry-run}';

    protected $description = 'Kéo danh sách users từ lara-sbooking về sb_users (để map với scrm.users)';

    public function handle(): int
    {
        $url = rtrim(config('services.booking.api_url') ?: '', '/') . '/sync/users';
        $token = config('services.booking.api_token');

        if (! $token) {
            $this->error('Thiếu BOOKING_API_TOKEN trong .env.');
            return self::FAILURE;
        }

        $this->info("Gọi: {$url}");

        try {
            $response = Http::withToken($token)->timeout(30)->acceptJson()->get($url);
        } catch (Throwable $e) {
            $this->error('HTTP fail: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error("HTTP {$response->status()}: " . $response->body());
            return self::FAILURE;
        }

        $rows = $response->json('data') ?? [];
        $this->info('Nhận ' . count($rows) . ' users từ sbooking.');

        if ($this->option('dry-run')) return self::SUCCESS;

        $created = 0; $updated = 0;
        foreach ($rows as $r) {
            $attrs = [
                'ten' => $r['name'] ?? '',
                'chuc_danh' => $r['chuc_danh'] ?? null,
                'username' => $r['username'] ?? null,
                'email' => $r['email'] ?? null,
                'sbooking_co_so_id' => $r['co_so_id'] ?? null,
                'sbooking_phong_ban_id' => $r['phong_ban_id'] ?? null,
                'sbooking_vai_tro_id' => $r['vai_tro_id'] ?? null,
                'sbooking_vai_tro_ma' => $r['vai_tro_ma'] ?? null,
                'sbooking_vai_tro_ten' => $r['vai_tro_ten'] ?? null,
                'synced_at' => now(),
            ];

            $existing = SbUser::where('sbooking_id', $r['id'])->first();
            if ($existing) { $existing->update($attrs); $updated++; }
            else { SbUser::create(array_merge(['sbooking_id' => $r['id']], $attrs)); $created++; }
        }

        $this->info("Xong. Tạo mới: {$created}, cập nhật: {$updated}, tổng: " . count($rows));

        // 2026-08-03 fix bug #1+#4: auto-map scrm.users.sbooking_user_id theo local-part email
        //   scrm.users.email  = "hn.sale04@longevity.com.vn" → local-part = "hn.sale04"
        //   sb_users.username = "hn.sale04" → khớp → set users.sbooking_user_id = sb_users.sbooking_id
        // Không đè record đã map thủ công (chỉ set khi đang NULL) để tôn trọng UI map trong Settings.
        $mapped = 0; $conflict = 0;
        $scrmUsers = User::whereNull('sbooking_user_id')->whereNotNull('email')->get(['id', 'email', 'name']);
        foreach ($scrmUsers as $u) {
            $local = strstr(strtolower($u->email), '@', true) ?: strtolower($u->email);
            $matches = SbUser::where('username', $local)->get();
            if ($matches->isEmpty()) continue;
            if ($matches->count() > 1) { $conflict++; continue; }
            $u->update(['sbooking_user_id' => (int) $matches->first()->sbooking_id]);
            $mapped++;
        }
        $this->info("Auto-map users↔sbooking: {$mapped} mapped, {$conflict} bỏ qua (nhiều match).");

        Log::info('sb:sync-users', ['created' => $created, 'updated' => $updated, 'total' => count($rows), 'auto_mapped' => $mapped, 'conflict' => $conflict]);
        return self::SUCCESS;
    }
}
