<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientThreshold extends TenantModel
{
    protected $fillable = [
        'tenant_id',
        'patient_id',
        'metric',
        'context',
        'ranges',
        'source',
        'confirmed_by',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'ranges' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
