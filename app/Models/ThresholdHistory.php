<?php

namespace App\Models;

class ThresholdHistory extends TenantModel
{
    protected $fillable = [
        'tenant_id',
        'patient_id',
        'patient_threshold_id',
        'changed_by',
        'old_ranges',
        'new_ranges',
    ];

    protected function casts(): array
    {
        return [
            'old_ranges' => 'array',
            'new_ranges' => 'array',
        ];
    }
}
