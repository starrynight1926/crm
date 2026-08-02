<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'sbooking_id',
    'sbooking_co_so_id',
    'ten',
    'chuc_danh',
    'active',
    'xuat_hien_moi_co_so',
    'nhan_tu_van',
    'phut_tu_van',
    'nhan_kham_ls',
    'phut_kham_ls',
    'gio_bat_dau',
    'gio_ket_thuc',
    'synced_at',
])]
class SbBacSi extends Model
{
    protected $table = 'sb_bac_si';

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'xuat_hien_moi_co_so' => 'boolean',
            'nhan_tu_van' => 'boolean',
            'nhan_kham_ls' => 'boolean',
            'phut_tu_van' => 'integer',
            'phut_kham_ls' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function displayName(): string
    {
        return trim(($this->chuc_danh ? $this->chuc_danh . ' ' : '') . $this->ten);
    }
}
