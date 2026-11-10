<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'sbooking_id',
    'sbooking_co_so_id',
    'ten',
    'loai',
    'kieu_phong',
    'duoc_dat_tu_van',
    'so_slot_toi_da',
    'phut_moi_khach',
    'trang_thai',
    'synced_at',
])]
class SbRoom extends Model
{
    protected $table = 'sb_rooms';

    protected function casts(): array
    {
        return [
            'duoc_dat_tu_van' => 'boolean',
            'so_slot_toi_da' => 'integer',
            'phut_moi_khach' => 'integer',
            'synced_at' => 'datetime',
        ];
    }
}
