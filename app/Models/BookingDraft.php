<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'facility_id', 'ngay_dat_lich', 'gio', 'nguon', 'ho_ten', 'sdt',
    'sale', 'lieu_phap', 'so_lo', 'dieu_duong', 'bac_si', 'ghi_chu',
    'created_by', 'updated_by', 'promoted_booking_log_id',
])]
class BookingDraft extends Model
{
    protected $casts = [
        'ngay_dat_lich' => 'date',
    ];

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Trạng thái chấm 3 màu:
     *   red    — thiếu field bắt buộc tối thiểu (ho_ten, sdt, ngay_dat_lich).
     *   yellow — đủ tối thiểu nhưng còn thiếu (gio, sale, lieu_phap).
     *   green  — đủ hết mảng cốt lõi.
     * KHÔNG chặn nhập — chỉ để nhìn nhanh row nào cần dọn.
     */
    public function statusColor(): string
    {
        $missing = $this->missingFields();
        if (array_intersect(['ho_ten', 'sdt', 'ngay_dat_lich'], $missing)) return 'red';
        if ($missing) return 'yellow';
        return 'green';
    }

    /** @return string[] */
    public function missingFields(): array
    {
        $required = ['ho_ten', 'sdt', 'ngay_dat_lich', 'gio', 'sale', 'lieu_phap'];
        $miss = [];
        foreach ($required as $f) {
            $v = $this->{$f};
            if ($v === null || $v === '') $miss[] = $f;
        }
        return $miss;
    }

    public function warningReasons(): array
    {
        $labels = [
            'ho_ten' => 'Thiếu họ tên',
            'sdt' => 'Thiếu số điện thoại',
            'ngay_dat_lich' => 'Thiếu ngày đặt lịch',
            'gio' => 'Thiếu giờ',
            'sale' => 'Thiếu sale phụ trách',
            'lieu_phap' => 'Thiếu liệu pháp',
        ];
        return array_values(array_map(fn ($k) => $labels[$k], $this->missingFields()));
    }
}
