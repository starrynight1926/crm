<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['role_id', 'event_key', 'scope'])]
class NotificationPref extends Model
{
    public const SCOPE_OFF      = 'off';
    public const SCOPE_OWN      = 'own';
    public const SCOPE_TEAM     = 'team';
    public const SCOPE_FACILITY = 'facility';
    public const SCOPE_ALL      = 'all';

    public const SCOPES = [self::SCOPE_OFF, self::SCOPE_OWN, self::SCOPE_TEAM, self::SCOPE_FACILITY, self::SCOPE_ALL];
}
