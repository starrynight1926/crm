<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BookingLateLog;
use App\Models\BookingLog;
use App\Models\Lead;
use App\Models\LeadPhaseClosure;
use App\Models\LeadStatusLog;
use App\Services\NotificationDispatcher;
use App\Support\NotificationEvents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Endpoint nhận push từ lara-sbooking khi:
 *   - status: nhân viên bấm 1 trong 4 nút Khách đã tới / tới trễ / hủy / đã xong.
 *   - comment: bình luận mới trong booking.
 *   - edit: đổi giờ/phòng/dịch vụ… của booking.
 *
 * Auth: Bearer token = user.api_token (chung 2 hệ).
 * Route: POST /api/leads/{code}/booking-event  (khớp cột leads.code)
 */
class BookingEventController extends Controller
{
    private ?array $upsAssignment = null;

    public function __invoke(Request $request, string $code)
    {
        $data = $request->validate([
            'type'         => ['required', 'in:status,comment,edit,delete'],
            'booking_ma'   => ['nullable', 'string', 'max:40'],
            'sbooking_booking_id' => ['nullable', 'integer'],
            'trang_thai_khach' => ['nullable', 'string', 'max:20'],
            'trang_thai'   => ['nullable', 'string', 'max:20'],
            'comment'      => ['nullable', 'string', 'max:2000'],
            'summary'      => ['nullable', 'string', 'max:500'], // mô tả edit (VD "Đổi giờ 09:00 → 10:30")
            'ly_do_tu_choi' => ['nullable', 'string', 'max:1000'],
        ]);

        $lead = Lead::where('code', $code)->firstOrFail();
        $bookingMa = $data['booking_ma'] ?? null;
        $actorId = auth()->id();

        // Phase C1.b rev10 2026-08-02: guard status/delete — chỉ chấp nhận callback nếu
        // sbooking_booking_id KHỚP với 1 BookingLog của lead. Tránh case sbooking booking
        // link nhầm crm_khach_ma → overwrite state của lead khác.
        if (in_array($data['type'], ['status', 'delete'], true) && ! empty($data['sbooking_booking_id'])) {
            $exists = BookingLog::where('sbooking_booking_id', $data['sbooking_booking_id'])
                ->where('lead_id', $lead->id)->exists();
            if (! $exists) {
                return response()->json([
                    'ok' => false,
                    'reason' => 'sbooking_booking_id không thuộc lead ' . $code,
                ], 409);
            }
        }

        DB::transaction(function () use ($lead, $data, $bookingMa, $actorId) {
            switch ($data['type']) {
                case 'status':
                    // Ưu tiên: Đã xong > Khách hủy > Tới trễ > Đã tới > Booked.
                    $newStatus = match (true) {
                        ($data['trang_thai'] ?? null) === 'da_xong'       => Lead::BOOKING_DA_XONG,
                        ($data['trang_thai_khach'] ?? null) === 'huy'     => Lead::BOOKING_KHACH_HUY,
                        ($data['trang_thai_khach'] ?? null) === 'toi_tre' => Lead::BOOKING_KHACH_TOI_TRE,
                        ($data['trang_thai_khach'] ?? null) === 'da_toi'  => Lead::BOOKING_KHACH_DA_TOI,
                        default                                            => Lead::BOOKING_BOOKED,
                    };
                    $before = $lead->booking_status;
                    $update = [
                        'booking_status' => $newStatus,
                        'booking_ma'     => $bookingMa ?: $lead->booking_ma,
                        'last_care_at'   => now(),
                    ];
                    // 2026-08-05: booking đã hoàn thành (da_xong) → auto set is_first_visit=false ("Khách quay lại"),
                    //   không cho user tick tay. Không set true trở lại (rollback booking rất hiếm).
                    if ($newStatus === Lead::BOOKING_DA_XONG && $lead->is_first_visit) {
                        $update['is_first_visit'] = false;
                    }
                    $lead->update($update);
                    LeadStatusLog::record($lead, 'booking_status', $before, $newStatus, $actorId);
                    LeadStatusLog::record($lead, 'note', null,
                        'Booking ' . ($bookingMa ?: '?') . ' — ' . (Lead::BOOKING_STATUSES[$newStatus] ?? $newStatus),
                        $actorId);
                    AuditLog::record('booking_status_push', $lead, [
                        'booking_ma' => $bookingMa,
                        'before' => $before,
                        'after' => $newStatus,
                    ]);

                    // Phase C1.b rev12 2026-08-02: khi callback đổi từ toi_tre → da_toi (user bấm lại)
                    // → xóa BookingLateLog cũ để không hiển thị "tới trễ" nữa.
                    if ($newStatus === Lead::BOOKING_KHACH_DA_TOI && ! empty($data['sbooking_booking_id'])) {
                        BookingLateLog::where('lead_id', $lead->id)
                            ->where('sbooking_booking_id', $data['sbooking_booking_id'])
                            ->delete();
                    }

                    // Phase 6.25.C — Khách checkin (da_toi) → auto-chia sale từ Sale Tiếp Đón (A→B→C→OFF)
                    // + mark sale busy + broadcast realtime để sbooking không phải F5.
                    // 2026-08-04 fix Bug U7: BỎ QUA auto-chia nếu chưa chốt UPS hôm nay ở cơ sở đó
                    // (trước đây pickGreet không check UpsDailyConfirm → chia dù chưa chốt).
                    if ($newStatus === Lead::BOOKING_KHACH_DA_TOI && $lead->pool_unit_id) {
                        $poolNode = \App\Models\PoolUnit::find($lead->pool_unit_id);
                        $facility = $poolNode;
                        while ($facility && $facility->kind !== 'facility') {
                            $facility = $facility->parent;
                        }
                        if ($facility && \App\Models\UpsDailyConfirm::isConfirmed($facility->id, now()->toDateString())) {
                            $picked = app(\App\Services\Ups\UpsDispatcher::class)->pickGreet($facility->id);
                            if ($picked) {
                                app(\App\Services\Ups\UpsDispatcher::class)->markBusy($picked->id);
                                $lead->update(['owner_id' => $picked->id, 'pool_level' => Lead::POOL_PERSONAL, 'assigned_at' => now()]);
                                LeadStatusLog::record($lead, 'note', null,
                                    'UPS: Tự động chia cho ' . $picked->name . ' (Sale Tiếp Đón)', $actorId);
                                event(new \App\Events\UpsSaleAssigned($lead->id, $picked->id, $picked->name, $data['sbooking_booking_id'] ?? null));
                                $this->upsAssignment = [
                                    'sale_user_id' => $picked->id,
                                    'sale_name' => $picked->name,
                                    'sale_email' => $picked->email,
                                    'sbooking_user_id' => $picked->sbooking_user_id,
                                ];
                            }
                        }
                    }

                    // 2026-08-05: da_xong (khách hoàn thành dịch vụ) → auto-close phase 5 (Bán hàng) + advance phase.
                    //   Bỏ da_xong (rollback) → xóa closure phase 5 + hạ phase về 4 (Check-in).
                    if ($newStatus === Lead::BOOKING_DA_XONG) {
                        // Đảm bảo phase 4 (Check-in) đã close (khách đã tới trước khi hoàn thành).
                        LeadPhaseClosure::updateOrCreate(
                            ['lead_id' => $lead->id, 'phase' => Lead::CF_PHASE_CHECKIN],
                            ['closed_by' => $actorId ?: 1, 'closed_at' => now(), 'note' => 'Auto: sbooking mark khách đã tới (tiền đề đã xong)']
                        );
                        LeadPhaseClosure::updateOrCreate(
                            ['lead_id' => $lead->id, 'phase' => Lead::CF_PHASE_SALES],
                            ['closed_by' => $actorId ?: 1, 'closed_at' => now(), 'note' => 'Auto: sbooking mark Đã hoàn thành']
                        );
                        if ((int) $lead->phase < Lead::CF_PHASE_SALES) {
                            $lead->update(['phase' => Lead::CF_PHASE_SALES]);
                        }
                    } else {
                        // Nếu trạng thái mới không phải da_xong nhưng đã từng close phase 5 → rollback.
                        //   Chỉ rollback nếu closure trước đó là "Auto: sbooking mark Đã hoàn thành"
                        //   (không đá closure manual của user).
                        $sale = LeadPhaseClosure::where('lead_id', $lead->id)->where('phase', Lead::CF_PHASE_SALES)->first();
                        if ($sale && str_starts_with((string) $sale->note, 'Auto: sbooking mark Đã hoàn thành')) {
                            $sale->delete();
                            if ((int) $lead->phase >= Lead::CF_PHASE_SALES) {
                                $lead->update(['phase' => Lead::CF_PHASE_CHECKIN]);
                            }
                        }
                    }

                    // Phase C1.b rev5 2026-08-01: khách tới (da_toi/toi_tre) → auto-close phase 4 + đưa lead lên phase 5.
                    if (in_array($newStatus, [Lead::BOOKING_KHACH_DA_TOI, Lead::BOOKING_KHACH_TOI_TRE], true)) {
                        // Đóng phase 4 (Booking) nếu chưa.
                        LeadPhaseClosure::updateOrCreate(
                            ['lead_id' => $lead->id, 'phase' => Lead::CF_PHASE_BOOKING],
                            ['closed_by' => $actorId ?: 1, 'closed_at' => now(), 'note' => 'Auto-close khi khách tới (sbooking push)']
                        );
                        // Đóng phase 5 (Check-in) — sbooking là nguồn sự thật, khách đã tới thì check-in luôn.
                        // updateOrCreate với UPDATE khi tồn tại → note reflect trạng thái mới nhất (đúng khi user đổi da_toi ↔ toi_tre).
                        LeadPhaseClosure::updateOrCreate(
                            ['lead_id' => $lead->id, 'phase' => Lead::CF_PHASE_CHECKIN],
                            ['closed_by' => $actorId ?: 1, 'closed_at' => now(),
                             'note' => $newStatus === Lead::BOOKING_KHACH_TOI_TRE ? 'Auto: sbooking mark khách tới trễ' : 'Auto: sbooking mark khách đã tới']
                        );
                        if ((int) $lead->phase < Lead::CF_PHASE_CHECKIN) {
                            $lead->update(['phase' => Lead::CF_PHASE_CHECKIN]);
                        }

                        // Ghi log tới trễ nếu toi_tre.
                        if ($newStatus === Lead::BOOKING_KHACH_TOI_TRE && ! empty($data['sbooking_booking_id'])) {
                            $bl = BookingLog::where('sbooking_booking_id', $data['sbooking_booking_id'])
                                ->where('lead_id', $lead->id)->first();
                            if ($bl) {
                                $expected = $bl->scheduled_at;
                                $arrived = now();
                                $lateMin = $expected ? max(0, (int) $expected->diffInMinutes($arrived, false)) : null;
                                BookingLateLog::create([
                                    'booking_log_id' => $bl->id,
                                    'lead_id' => $lead->id,
                                    'sbooking_booking_id' => $bl->sbooking_booking_id,
                                    'sbooking_booking_ma' => $bl->sbooking_booking_ma,
                                    'expected_at' => $expected,
                                    'arrived_at' => $arrived,
                                    'late_minutes' => $lateMin,
                                    // Phase C1.b rev8: callback từ sbooking → nhãn "Admin vận hành (sbooking)" thay vì tên user push (chỉ là kênh truyền).
                                    'marked_by' => 'Admin vận hành (sbooking)',
                                    'note' => 'Sbooking mark khách tới trễ' . ($lateMin !== null ? " ({$lateMin} phút)" : ''),
                                ]);
                            }
                        }
                    }

                    // Phase C1.b 2026-08-01 + rev11 2026-08-02: cập nhật BookingLog theo trang_thai (Admin duyệt)
                    // hoặc trang_thai_khach (Check-in). Ưu tiên trang_thai_khach nếu có (đến/trễ = đã duyệt xong).
                    if (! empty($data['sbooking_booking_id'])) {
                        $bl = BookingLog::where('sbooking_booking_id', $data['sbooking_booking_id'])
                            ->where('lead_id', $lead->id)->first();
                        if ($bl) {
                            $trangThai = $data['trang_thai'] ?? null;
                            $trangThaiKhach = $data['trang_thai_khach'] ?? null;
                            $newSyncStatus = match (true) {
                                $trangThaiKhach === 'da_toi', $trangThaiKhach === 'toi_tre' => 'checkedin',
                                $trangThai === 'da_xong' => 'done',
                                $trangThai === 'da_duyet' => 'approved',
                                $trangThai === 'cho_duyet' => 'synced',
                                $trangThai === 'tu_choi' => 'rejected',
                                $trangThaiKhach === 'huy' => 'canceled',
                                default => $bl->sync_status,
                            };
                            $bl->update([
                                'sync_status' => $newSyncStatus,
                                'sync_error' => $trangThai === 'tu_choi' ? ($data['ly_do_tu_choi'] ?? 'Sbooking từ chối') : null,
                                'synced_at' => now(),
                            ]);
                        }
                    }
                    break;

                case 'comment':
                    $content = trim((string) ($data['comment'] ?? ''));
                    // Phase C1.f 2026-08-02: lưu vào booking_log_comments (source=sbooking) để thread 2 chiều.
                    if ($content !== '' && ! empty($data['sbooking_booking_id'])) {
                        $bl = BookingLog::where('sbooking_booking_id', $data['sbooking_booking_id'])
                            ->where('lead_id', $lead->id)->first();
                        if ($bl) {
                            $sbookingUserName = null;
                            $sbookingUserId = $data['sbooking_user_id'] ?? null;
                            if ($sbookingUserId) {
                                $sbookingUserName = \App\Models\SbUser::where('sbooking_id', $sbookingUserId)->value('ten');
                            }
                            \App\Models\BookingLogComment::create([
                                'booking_log_id' => $bl->id,
                                'lead_id' => $lead->id,
                                'source' => 'sbooking',
                                'user_id' => null,
                                'sbooking_user_id' => $sbookingUserId,
                                'user_name' => $sbookingUserName ?? 'Admin sbooking',
                                'content' => $content,
                            ]);
                        }
                    }
                    $note = 'Bình luận Booking ' . ($bookingMa ?: '?') . ': ' . $content;
                    LeadStatusLog::record($lead, 'note', null, $note, $actorId);
                    $lead->update(['last_care_at' => now()]);
                    AuditLog::record('booking_comment_push', $lead, ['booking_ma' => $bookingMa]);
                    break;

                case 'edit':
                    // Phase C1.e.2 (2026-08-02) — apply structured changes vào booking_log.
                    if (! empty($data['sbooking_booking_id']) && ! empty($data['changes']) && is_array($data['changes'])) {
                        $bl = BookingLog::where('sbooking_booking_id', $data['sbooking_booking_id'])
                            ->where('lead_id', $lead->id)->first();
                        if ($bl) {
                            $ch = $data['changes'];
                            $update = [];
                            if (array_key_exists('ghi_chu', $ch)) $update['note'] = $ch['ghi_chu'];
                            if (array_key_exists('phong_id', $ch)) $update['sb_phong_id'] = $ch['phong_id'];
                            if (array_key_exists('bac_si_id', $ch)) $update['sb_bac_si_id'] = $ch['bac_si_id'];
                            if (array_key_exists('so_lieu_trinh', $ch)) $update['so_lieu_trinh'] = $ch['so_lieu_trinh'];
                            if (array_key_exists('so_luong_lo', $ch)) $update['so_luong_lo'] = $ch['so_luong_lo'];
                            if (array_key_exists('dung_tich_lo', $ch)) $update['dung_tich_lo'] = $ch['dung_tich_lo'];
                            if (array_key_exists('ket_hop_medical', $ch)) $update['ket_hop_medical'] = (bool) $ch['ket_hop_medical'];
                            if (array_key_exists('co_tu_van', $ch)) $update['co_tu_van'] = (bool) $ch['co_tu_van'];
                            if (array_key_exists('co_kham_cls', $ch)) $update['co_kham_cls'] = (bool) $ch['co_kham_cls'];
                            // Combine ngay + gio → scheduled_at.
                            if (! empty($ch['ngay_dat']) && ! empty($ch['gio_thuc_hien'])) {
                                $update['scheduled_at'] = $ch['ngay_dat'] . ' ' . substr($ch['gio_thuc_hien'], 0, 8);
                            }
                            // Resolve sbooking dich_vu_id → scrm.services.id qua sb_services.ten → services.name (best-effort).
                            if (array_key_exists('dich_vu_id', $ch) && $ch['dich_vu_id']) {
                                $sbSvc = \App\Models\SbService::where('sbooking_id', $ch['dich_vu_id'])->first();
                                if ($sbSvc) {
                                    $scrmSvc = \App\Models\Service::where('name', $sbSvc->ten)->first();
                                    if ($scrmSvc) $update['service_id'] = $scrmSvc->id;
                                }
                            }
                            $bl->update($update);
                            // Resolve sale_id sbooking → CV#1 scrm.user qua users.sbooking_user_id, sync pivot.
                            if (array_key_exists('sale_id', $ch) && $ch['sale_id']) {
                                $scrmUser = \App\Models\User::where('sbooking_user_id', $ch['sale_id'])->first();
                                if ($scrmUser) {
                                    $bl->syncConsultantsTracked([$scrmUser->id => ['position' => 1]]);
                                }
                            }
                        }
                    }
                    $note = 'Booking ' . ($bookingMa ?: '?') . ' đã đổi: ' . ($data['summary'] ?? 'không mô tả');
                    LeadStatusLog::record($lead, 'note', null, $note, $actorId);
                    $lead->update(['last_care_at' => now()]);
                    AuditLog::record('booking_edit_push', $lead, ['booking_ma' => $bookingMa, 'summary' => $data['summary'] ?? null]);
                    break;

                case 'delete':
                    // Phase C1.b rev4 2026-08-01: Admin sbooking xóa booking → mark log deleted.
                    if (! empty($data['sbooking_booking_id'])) {
                        $bl = BookingLog::where('sbooking_booking_id', $data['sbooking_booking_id'])
                            ->where('lead_id', $lead->id)->first();
                        if ($bl) {
                            $bl->update([
                                'sync_status' => 'deleted',
                                'sync_error' => 'Admin sbooking đã xóa booking',
                                'synced_at' => now(),
                            ]);
                        }
                    }
                    $note = 'Booking ' . ($bookingMa ?: '?') . ' đã bị Admin sbooking xóa.';
                    LeadStatusLog::record($lead, 'note', null, $note, $actorId);
                    AuditLog::record('booking_delete_push', $lead, ['booking_ma' => $bookingMa]);
                    break;
            }
        });

        $this->dispatchNotification($lead, $data, $bookingMa, $actorId);

        // 2026-08-04 fix delay: broadcast để lead-form Livewire auto-refresh, không cần F5.
        event(new \App\Events\BookingStatusSynced(
            $lead->id,
            $data['sbooking_booking_id'] ?? null,
            $data['type'] ?? null,
            $lead->fresh()->booking_status,
        ));

        return response()->json([
            'ok' => true,
            'lead_code' => $lead->code,
            'ups_assignment' => $this->upsAssignment,
        ]);
    }

    protected function dispatchNotification(Lead $lead, array $data, ?string $bookingMa, ?int $actorId): void
    {
        [$eventKey, $tieuDe, $noiDung] = match ($data['type']) {
            'status'  => [
                NotificationEvents::BOOKING_STATUS_CHANGED,
                'Booking đổi trạng thái',
                $lead->name.' — '.(Lead::BOOKING_STATUSES[$lead->fresh()->booking_status] ?? $lead->booking_status).($bookingMa ? " ($bookingMa)" : ''),
            ],
            'comment' => [
                NotificationEvents::BOOKING_NOTE_ADDED,
                'Booking có ghi chú mới',
                $lead->name.' — '.\Illuminate\Support\Str::limit(trim((string) ($data['comment'] ?? '')), 100),
            ],
            'edit'    => [
                NotificationEvents::BOOKING_RESCHEDULED,
                'Booking đổi lịch',
                $lead->name.' — '.($data['summary'] ?? 'không mô tả'),
            ],
            'delete'  => [
                NotificationEvents::BOOKING_STATUS_CHANGED,
                'Booking bị xóa',
                $lead->name.' — Admin sbooking đã xóa booking'.($bookingMa ? " ($bookingMa)" : ''),
            ],
        };

        $payload = [
            'tieu_de'    => $tieuDe,
            'noi_dung'   => $noiDung,
            'link'       => '/leads/'.$lead->id,
            'lead_id'    => $lead->id,
            'booking_ma' => $bookingMa,
        ];

        $dispatcher = app(NotificationDispatcher::class);
        if ($lead->owner_id && $lead->owner_id !== $actorId) {
            $dispatcher->send($eventKey, [$lead->owner_id], $payload);
        }
        $dispatcher->sendToRoles($eventKey, $payload, [
            'owner_id'    => $lead->owner_id,
            'org_unit_id' => $lead->org_unit_id,
        ]);
    }
}
