<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['rule_id', 'target_type', 'target_id', 'weight', 'position'])]
class RuleTarget extends Model
{
    public $timestamps = false;

    public function targetLabel(): string
    {
        return $this->target_type === 'user'
            ? (User::find($this->target_id)?->name ?? "user#{$this->target_id}")
            : (OrgUnit::find($this->target_id)?->name ?? "org#{$this->target_id}");
    }
}
