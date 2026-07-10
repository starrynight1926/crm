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
}
