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
        return match ($this->target_type) {
            'user'      => User::find($this->target_id)?->name ?? "user#{$this->target_id}",
            'pool_unit' => PoolUnit::find($this->target_id)?->name ?? "pool#{$this->target_id}",
            'org_unit'  => OrgUnit::find($this->target_id)?->name ?? "org#{$this->target_id}",
            default     => "{$this->target_type}#{$this->target_id}",
        };
    }
}
