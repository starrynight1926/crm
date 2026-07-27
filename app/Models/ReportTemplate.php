<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mẫu báo cáo của một team — xem cấu trúc config ở migration create_report_templates_table.
 */
#[Fillable(['org_unit_id', 'name', 'config', 'created_by'])]
class ReportTemplate extends Model
{
    protected $casts = [
        'config' => 'array',
    ];

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Danh sách cột (mỗi phần tử của config.columns), fallback rỗng. */
    public function columns(): array
    {
        return $this->config['columns'] ?? [];
    }

    /** Bảng tổng (đếm theo funnel) có hiện không. Mặc định bật nếu chưa cấu hình. */
    public function showTotals(): bool
    {
        return (bool) ($this->config['views']['totals'] ?? true);
    }

    /** Bảng theo người phụ trách có hiện không. */
    public function showByOwner(): bool
    {
        return (bool) ($this->config['views']['by_owner'] ?? false);
    }

    /** Kiểu báo cáo: 'aggregate' (đếm option, mặc định) hoặc 'list' (bảng từng khách). */
    public function mode(): string
    {
        return $this->config['mode'] ?? 'aggregate';
    }

    public function isList(): bool
    {
        return $this->mode() === 'list';
    }

    public function filters(): array
    {
        return $this->config['filters'] ?? [];
    }

    /** Cột lead hỗ trợ trong list mode. */
    public const LIST_COLUMNS = [
        'stt'            => 'STT',
        'code'           => 'Mã KH',
        'received_date'  => 'Ngày thu thập',
        'facility'       => 'Cơ sở',
        'name'           => 'Họ tên',
        'phone'          => 'SĐT',
        'birthday'       => 'DOB',
        'address'        => 'Địa chỉ',
        'occupation'     => 'Nghề nghiệp',
        'owner'          => 'Sale Book (owner)',
        'receiver'       => 'Sale Care (receiver)',
        'source_group'   => 'Nguồn',
        'classification' => 'Phân loại',
        'booking_status' => 'Trạng thái đặt lịch',
        'booking_ma'     => 'Mã booking',
        'booked_at'      => 'Ngày đặt lịch',
        'note'           => 'Note',
    ];

    public const DATE_FIELDS = [
        'received_date' => 'Ngày thu thập',
        'booked_at'     => 'Ngày đặt lịch',
        'last_care_at'  => 'Ngày chăm sóc gần nhất',
    ];

    public const DATE_RANGES = [
        'today'      => 'Hôm nay',
        'yesterday'  => 'Hôm qua',
        'this_week'  => 'Tuần này',
        'this_month' => 'Tháng này',
        'last_month' => 'Tháng trước',
        'custom'     => 'Tuỳ chỉnh',
    ];
}
