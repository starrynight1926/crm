<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 2026-09-23 — Ai được tick vào đây mới hiện trong dropdown check-in UPS của
 * facility đó (⚡ups-board.blade.php saleUsersOfFacility). Quản lý ở /settings/ups-list,
 * chỉ super admin (AdminScope::isSuperAdmin()) được sửa.
 */
#[Fillable(['facility_pool_unit_id', 'user_id', 'added_by'])]
class UpsListMember extends Model
{
    public function facility(): BelongsTo
    {
        return $this->belongsTo(PoolUnit::class, 'facility_pool_unit_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
