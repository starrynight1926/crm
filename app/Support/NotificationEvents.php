<?php

namespace App\Support;

/**
 * Danh sách sự kiện notification hệ thống — dùng làm key trong bảng notification_prefs.
 * Nhóm/nhãn dùng để render trang thiết lập (ma trận role × event).
 */
class NotificationEvents
{
    public const LEAD_CREATED     = 'lead.created';
    public const LEAD_ASSIGNED    = 'lead.assigned';
    public const LEAD_TRANSFERRED = 'lead.transferred';
    public const LEAD_BOOKED      = 'lead.booked';
    public const LEAD_NOTE_ADDED  = 'lead.note_added';
    public const LEAD_RECALLED    = 'lead.recalled';
    public const BOOKING_STATUS_CHANGED = 'booking.status_changed';
    public const BOOKING_NOTE_ADDED     = 'booking.note_added';
    public const BOOKING_RESCHEDULED    = 'booking.rescheduled';

    /**
     * [event_key => [label, description, group]]
     */
    public static function catalog(): array
    {
        return [
            self::LEAD_CREATED => [
                'label' => 'Lead mới được nhập',
                'desc'  => 'Khi có lead mới nhập vào hệ thống (chưa chia).',
                'group' => 'Lead',
            ],
            self::LEAD_ASSIGNED => [
                'label' => 'Được chia lead',
                'desc'  => 'Sale nhận lead mới sau khi được chia.',
                'group' => 'Lead',
            ],
            self::LEAD_TRANSFERRED => [
                'label' => 'Lead được chuyển',
                'desc'  => 'Lead bị chuyển từ người này sang người khác.',
                'group' => 'Lead',
            ],
            self::LEAD_BOOKED => [
                'label' => 'Lead có booking mới',
                'desc'  => 'Khách hàng đã được đặt lịch tại sbooking.',
                'group' => 'Booking',
            ],
            self::LEAD_NOTE_ADDED => [
                'label' => 'Lead có ghi chú mới',
                'desc'  => 'Ai đó vừa thêm/cập nhật ghi chú trên lead.',
                'group' => 'Lead',
            ],
            self::LEAD_RECALLED => [
                'label' => 'Thu hồi lead',
                'desc'  => 'Lead bị thu hồi về kho do quá hạn chăm sóc.',
                'group' => 'Lead',
            ],
            self::BOOKING_STATUS_CHANGED => [
                'label' => 'Booking đổi trạng thái',
                'desc'  => 'Khách đến / đến trễ / hoàn thành / hủy / no-show bên hệ thống Booking.',
                'group' => 'Booking',
            ],
            self::BOOKING_NOTE_ADDED => [
                'label' => 'Booking có ghi chú mới',
                'desc'  => 'Ai đó vừa thêm bình luận trên booking bên hệ thống Booking.',
                'group' => 'Booking',
            ],
            self::BOOKING_RESCHEDULED => [
                'label' => 'Booking đổi lịch',
                'desc'  => 'Booking bị đổi ngày/giờ bên hệ thống Booking.',
                'group' => 'Booking',
            ],
        ];
    }

    public static function keys(): array
    {
        return array_keys(self::catalog());
    }

    public static function label(string $key): string
    {
        return self::catalog()[$key]['label'] ?? $key;
    }

    public static function groups(): array
    {
        $out = [];
        foreach (self::catalog() as $key => $meta) {
            $out[$meta['group']][$key] = $meta;
        }
        return $out;
    }
}
