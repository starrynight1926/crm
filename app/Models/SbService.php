<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'sbooking_id',
    'sbooking_co_so_id',
    'ten',
    'thoi_gian_phut',
    'thuoc_nhom',
    'la_dich_vu',
    'active',
    'synced_at',
])]
class SbService extends Model
{
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'la_dich_vu' => 'boolean',
            'thoi_gian_phut' => 'integer',
            'synced_at' => 'datetime',
        ];
    }
}
