<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'label', 'group', 'position'])]
class Permission extends Model
{
    public $timestamps = false;
}
