<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'active', 'priority', 'level', 'org_unit_id', 'conditions', 'strategy', 'strategy_config'])]
class DistributionRule extends Model
{
    public const LEVEL_POOL_TO_TEAM = 'pool_to_team';
    public const LEVEL_TEAM_TO_USER = 'team_to_user';

    public const STRATEGIES = [
        'round_robin' => 'Chia lần lượt (round-robin)',
        'weighted' => 'Chia theo tỉ trọng',
        'top_revenue' => 'Ưu tiên doanh thu cao nhất',
        'top_close_rate' => 'Ưu tiên tỉ lệ close cao nhất',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'conditions' => 'array',
            'strategy_config' => 'array',
        ];
    }

    public function targets(): HasMany
    {
        return $this->hasMany(RuleTarget::class, 'rule_id')->orderBy('position');
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    /** Lead có khớp điều kiện lọc của rule không. Điều kiện rỗng = khớp tất. */
    public function matches(Lead $lead): bool
    {
        foreach ($this->conditions ?? [] as $field => $values) {
            $values = array_filter((array) $values);
            if ($values === []) {
                continue;
            }
            if (! in_array($lead->{$field}, $values, true)) {
                return false;
            }
        }

        return true;
    }
}
