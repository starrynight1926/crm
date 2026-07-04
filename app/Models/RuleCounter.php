<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['rule_id', 'target_id', 'period_key', 'delivered_count'])]
class RuleCounter extends Model
{
    public $timestamps = false;
}
