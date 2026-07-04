<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['service_id', 'position', 'name', 'phase_price'])]
class ServicePhase extends Model
{
    public $timestamps = false;
}
