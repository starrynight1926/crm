<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Template import: map cột tái dùng + giá trị mặc định. Dùng chung toàn công ty.
 *
 * config = [
 *   ['target' => 'name', 'header' => 'Họ tên', 'default' => ''],
 *   ['target' => 'cf_5', 'header' => 'Nhu cầu', 'default' => 'Chưa rõ'],
 *   ...
 * ]
 * target: field lead chuẩn (xem LeadImport::TARGETS) hoặc 'cf_<custom_field_id>'.
 */
#[Fillable(['name', 'config', 'created_by'])]
class ImportTemplate extends Model
{
    protected $casts = [
        'config' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
