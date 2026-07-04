<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'receiving', 'off_reason', 'off_until'])]
class UserLeadSetting extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'receiving' => 'boolean',
            'off_until' => 'date',
        ];
    }

    /** User có đang nhận số không: không có row = có nhận; tắt có hạn thì qua ngày tự bật lại. */
    public static function isReceiving(int $userId): bool
    {
        $setting = static::find($userId);

        if (! $setting || $setting->receiving) {
            return true;
        }

        return $setting->off_until !== null && $setting->off_until->isPast();
    }
}
