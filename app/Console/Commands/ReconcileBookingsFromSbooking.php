<?php

namespace App\Console\Commands;

use App\Models\BookingLog;
use App\Models\Lead;
use App\Models\SbService;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 2026-08-04 — pull bookings từ sbooking → reconcile scrm.booking_logs.
 *
 * Fix bug: booking cũ trên scrm có sb_khung_gio_id/sb_dich_vu_id/sync_status lệch với sbooking
 * (do các phase sync trước không đẩy đủ field, hoặc user sửa bên sbooking mà callback fail).
 *
 * Logic per booking (match theo sbooking_booking_id):
 *   - Backfill missing: sb_khung_gio_id, sb_dich_vu_id, sb_phong_id, sb_bac_si_id, scheduled_end_at.
 *   - Update sync_status theo trang_thai + trang_thai_khach.
 *   - Sync consultants pivot theo sale_id (map qua users.sbooking_user_id).
 *   - Update leads.booking_status per booking mới nhất.
 *
 * Idempotent — chạy lại không side-effect.
 * Usage: php artisan sb:reconcile-bookings [--dry-run] [--since=2026-08-01]
 */
class ReconcileBookingsFromSbooking extends Command
{
    protected $signature = 'sb:reconcile-bookings {--dry-run} {--since=}';

    protected $description = 'Pull bookings từ sbooking → backfill sb_khung_gio_id/sb_dich_vu_id + sync_status cho booking_logs cũ';

    public function handle(): int
    {
        $baseUrl = rtrim(config('services.booking.api_url') ?: '', '/');
        $token = config('services.booking.api_token');
        if (! $baseUrl || ! $token) {
            $this->error('Thiếu BOOKING_API_URL / BOOKING_API_TOKEN trong .env.');
            return self::FAILURE;
        }

        $stats = ['seen' => 0, 'matched' => 0, 'backfilled' => 0, 'status_changed' => 0, 'cv_synced' => 0, 'missing_local' => 0, 'lead_status_synced' => 0];
        $page = 1;
        do {
            $query = ['per_page' => 200, 'page' => $page];
            if ($since = $this->option('since')) $query['updated_since'] = $since;

            try {
                $r = Http::withToken($token)->timeout(30)->acceptJson()->get($baseUrl . '/bookings', $query);
            } catch (Throwable $e) {
                $this->error('HTTP fail: ' . $e->getMessage());
                return self::FAILURE;
            }
            if (! $r->successful()) {
                $this->error("HTTP {$r->status()}: " . substr($r->body(), 0, 300));
                return self::FAILURE;
            }
            $rows = $r->json('data') ?? [];
            $meta = $r->json('meta') ?? [];
            $stats['seen'] += count($rows);

            foreach ($rows as $row) {
                $this->reconcileOne($row, $stats);
            }

            $page++;
        } while ($page <= (int) ($meta['last_page'] ?? 1));

        $leadsToSync = BookingLog::whereNotNull('sbooking_booking_id')->distinct()->pluck('lead_id');
        foreach ($leadsToSync as $lid) {
            if ($this->option('dry-run')) continue;
            BookingLog::syncLeadBookingStatus($lid);
            $stats['lead_status_synced']++;
        }

        $this->info('Reconcile xong.');
        foreach ($stats as $k => $v) $this->line("  {$k}: {$v}");
        Log::info('sb:reconcile-bookings', $stats);
        return self::SUCCESS;
    }

    private function reconcileOne(array $row, array &$stats): void
    {
        $sbId = (int) ($row['id'] ?? 0);
        if (! $sbId) return;

        $bl = BookingLog::where('sbooking_booking_id', $sbId)->first();
        if (! $bl) {
            $stats['missing_local']++;
            return;
        }
        $stats['matched']++;

        // Backfill fields còn NULL.
        $updates = [];
        $mapping = [
            'khung_gio_id' => 'sb_khung_gio_id',
            'dich_vu_id'   => 'sb_dich_vu_id',
            'phong_id'     => 'sb_phong_id',
            'bac_si_id'    => 'sb_bac_si_id',
        ];
        foreach ($mapping as $sbField => $blField) {
            if (empty($bl->$blField) && ! empty($row[$sbField])) {
                $updates[$blField] = (int) $row[$sbField];
            }
        }
        // scheduled_end_at (time cột) — nếu NULL thì lấy gio_ket_thuc.
        if (empty($bl->scheduled_end_at) && ! empty($row['gio_ket_thuc'])) {
            $updates['scheduled_end_at'] = substr((string) $row['gio_ket_thuc'], 0, 8);
        }
        // Resolve services.id (scrm) nếu chưa có, dùng sb_services mirror match theo sbooking_id.
        if (empty($bl->service_id) && ! empty($row['dich_vu_id'])) {
            $sbSvc = SbService::where('sbooking_id', (int) $row['dich_vu_id'])->first();
            if ($sbSvc) {
                $match = \App\Models\Service::where('name', $sbSvc->ten)->first();
                if ($match) $updates['service_id'] = $match->id;
            }
        }

        // Map trang_thai + trang_thai_khach → sync_status (giống BookingEventController).
        $tt = $row['trang_thai'] ?? null;
        $ttk = $row['trang_thai_khach'] ?? null;
        $newSyncStatus = match (true) {
            $ttk === 'da_toi', $ttk === 'toi_tre' => 'checkedin',
            $tt === 'da_xong' => 'done',
            $tt === 'da_duyet' => 'approved',
            $tt === 'cho_duyet' => 'synced',
            $tt === 'tu_choi' => 'rejected',
            $ttk === 'huy' => 'canceled',
            default => $bl->sync_status,
        };
        if ($newSyncStatus !== $bl->sync_status) {
            $updates['sync_status'] = $newSyncStatus;
            if ($tt === 'tu_choi' && empty($bl->sync_error)) {
                $updates['sync_error'] = 'Sbooking từ chối (reconcile)';
            }
            $stats['status_changed']++;
        }

        if ($updates && ! $this->option('dry-run')) {
            $bl->update($updates);
            $stats['backfilled']++;
        } elseif ($updates) {
            $stats['backfilled']++;
        }

        // Sync consultants pivot (CV1) theo sale_id sbooking → scrm.users.sbooking_user_id.
        if (! empty($row['sale_id'])) {
            $localUser = User::where('sbooking_user_id', (int) $row['sale_id'])->first();
            if ($localUser) {
                $current = $bl->consultants()->wherePivot('position', 1)->pluck('users.id')->first();
                if ($current !== $localUser->id) {
                    if (! $this->option('dry-run')) {
                        $bl->consultants()->sync([$localUser->id => ['position' => 1]]);
                    }
                    $stats['cv_synced']++;
                }
            }
        }
    }
}
