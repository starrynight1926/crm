<?php

namespace App\Console\Commands;

use App\Models\SbRoom;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase C1.d (2026-08-02) — Kéo phong từ sbooking về sb_rooms.
 * Sync 1 chiều, upsert theo sbooking_id. Idempotent.
 *
 * Usage: php artisan sb:sync-rooms [--co-so-id=X]
 */
class SyncRoomsFromSbooking extends Command
{
    protected $signature = 'sb:sync-rooms {--co-so-id= : chỉ sync 1 cơ sở} {--dry-run}';

    protected $description = 'Kéo danh sách phòng (phong) từ lara-sbooking về sb_rooms';

    public function handle(): int
    {
        $baseUrl = rtrim(config('services.booking.api_url') ?: '', '/');
        $token = config('services.booking.api_token');

        if (! $token) {
            $this->error('Thiếu BOOKING_API_TOKEN trong .env.');
            return self::FAILURE;
        }

        // Sbooking endpoint yêu cầu co_so_id. Nếu không truyền → gọi lần lượt tất cả facility có sbooking_co_so_id.
        $coSoIds = $this->option('co-so-id')
            ? [(int) $this->option('co-so-id')]
            : \App\Models\Facility::whereNotNull('sbooking_co_so_id')->pluck('sbooking_co_so_id')->unique()->all();

        if (empty($coSoIds)) {
            $this->error('Chưa có Facility nào map sbooking_co_so_id — vào Thiết lập → Kết nối Booking để map.');
            return self::FAILURE;
        }

        $created = 0; $updated = 0; $deleted = 0; $total = 0;
        foreach ($coSoIds as $coSoId) {
            $receivedIds = [];
            $url = $baseUrl . '/sync/phong?co_so_id=' . $coSoId;
            $this->info("Gọi: {$url}");

            try {
                $response = Http::withToken($token)->timeout(30)->acceptJson()->get($url);
            } catch (Throwable $e) {
                $this->error('HTTP fail: ' . $e->getMessage());
                continue;
            }

            if (! $response->successful()) {
                $this->error("HTTP {$response->status()}: " . $response->body());
                continue;
            }

            $rows = $response->json('data') ?? [];
            $total += count($rows);
            $this->info('  Nhận ' . count($rows) . ' phòng cho co_so_id=' . $coSoId);

            if ($this->option('dry-run')) continue;

            foreach ($rows as $r) {
                $receivedIds[] = (int) $r['id'];
                $attrs = [
                    'sbooking_co_so_id' => $r['co_so_id'],
                    'ten' => $r['ten'] ?? '',
                    'loai' => $r['loai'] ?? null,
                    'kieu_phong' => $r['kieu_phong'] ?? null,
                    'duoc_dat_tu_van' => (bool) ($r['duoc_dat_tu_van'] ?? true),
                    'so_slot_toi_da' => (int) ($r['so_slot_toi_da'] ?? 1),
                    'phut_moi_khach' => (int) ($r['phut_moi_khach'] ?? 0),
                    'trang_thai' => $r['trang_thai'] ?? 'hoat_dong',
                    'synced_at' => now(),
                ];

                $existing = SbRoom::where('sbooking_id', $r['id'])->first();
                if ($existing) { $existing->update($attrs); $updated++; }
                else { SbRoom::create(array_merge(['sbooking_id' => $r['id']], $attrs)); $created++; }
            }

            // Đợt C.3 (2026-08-25): cleanup phòng đã bị xoá bên sbooking (scope co_so).
            if (! $this->option('dry-run')) {
                $deleted += SbRoom::where('sbooking_co_so_id', $coSoId)
                    ->whereNotIn('sbooking_id', $receivedIds ?: [0])
                    ->delete();
            }
        }

        $this->info("Xong. Tạo mới: {$created}, cập nhật: {$updated}, xoá: {$deleted}, tổng nhận: {$total}");
        Log::info('sb:sync-rooms', ['created' => $created, 'updated' => $updated, 'deleted' => $deleted, 'total' => $total]);
        return self::SUCCESS;
    }
}
