<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'facility_id', 'role', 'active'])]
class StaffMember extends Model
{
    public const ROLE_DOCTOR = 'doctor';
    public const ROLE_CONSULTANT = 'consultant';

    public const ROLE_LABELS = [
        self::ROLE_DOCTOR => 'Bác sĩ',
        self::ROLE_CONSULTANT => 'Chuyên viên tư vấn',
    ];

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeDoctors($query)
    {
        return $query->where('role', self::ROLE_DOCTOR);
    }

    public function scopeConsultants($query)
    {
        return $query->where('role', self::ROLE_CONSULTANT);
    }

    public function displayLabel(): string
    {
        $facility = $this->facility;
        $parent = $facility?->parent;

        if ($parent) {
            return $parent->name . ' › ' . $facility->name . ' — ' . $this->name;
        }

        return $facility->name . ' — ' . $this->name;
    }
}
