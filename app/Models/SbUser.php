<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'sbooking_id',
    'ten',
    'chuc_danh',
    'username',
    'email',
    'sbooking_co_so_id',
    'sbooking_phong_ban_id',
    'sbooking_vai_tro_id',
    'sbooking_vai_tro_ma',
    'sbooking_vai_tro_ten',
    'synced_at',
])]
class SbUser extends Model
{
    protected $table = 'sb_users';

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }

    public function displayName(): string
    {
        return trim(($this->chuc_danh ? $this->chuc_danh . ' ' : '') . $this->ten);
    }
}
