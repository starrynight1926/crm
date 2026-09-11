<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase C1.b rev10 (2026-08-02) — seed default notification_prefs.
 *
 * Bug: bảng `notification_prefs` được tạo (migration 07-27) nhưng chưa seed →
 * NotificationDispatcher::enabledPrefs() luôn rỗng → mọi notify skip → chuông chết.
 *
 * Chốt: mặc định BẬT toàn bộ role × 9 event với scope 'all'. Admin có thể trim
 * sau trong /settings/notification-prefs (nếu có).
 */
return new class extends Migration
{
    public function up(): void
    {
        $events = [
            'lead.created', 'lead.assigned', 'lead.transferred', 'lead.booked',
            'lead.note_added', 'lead.recalled',
            'booking.status_changed', 'booking.note_added', 'booking.rescheduled',
        ];

        $roleIds = DB::table('roles')->pluck('id');
        $now = now();

        foreach ($roleIds as $rid) {
            foreach ($events as $ev) {
                DB::table('notification_prefs')->updateOrInsert(
                    ['role_id' => $rid, 'event_key' => $ev],
                    ['scope' => 'all', 'created_at' => $now, 'updated_at' => $now]
                );
            }
        }
    }

    public function down(): void
    {
        // Không rollback — chỉ là seed default, không phá schema.
    }
};
