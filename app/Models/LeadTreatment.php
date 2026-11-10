<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['lead_id', 'sequence', 'performed_at', 'performing_doctor_id', 'quality_rating'])]
class LeadTreatment extends Model
{
    protected function casts(): array
    {
        return ['performed_at' => 'date'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function performingDoctor(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'performing_doctor_id');
    }
}
