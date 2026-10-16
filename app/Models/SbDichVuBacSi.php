<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 2026-10-09 — Mirror pivot sbooking.dich_vu_bac_si. Sync 1 chiều qua
 * `sb:sync-dich-vu-bac-si`. Pivot không có id nghiệp vụ — key là
 * (sbooking_dich_vu_id, sbooking_bac_si_id).
 */
#[Fillable([
    'sbooking_dich_vu_id',
    'sbooking_bac_si_id',
    'synced_at',
])]
class SbDichVuBacSi extends Model
{
    protected $table = 'sb_dich_vu_bac_si';

    protected $casts = [
        'synced_at' => 'datetime',
    ];
}
