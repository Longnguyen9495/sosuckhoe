<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringPlan extends TenantModel
{
    protected $fillable = [
        'tenant_id',
        'patient_id',
        'metric',
        'phase',
        'starts_at',
        'ends_at',
        'schedule',
    ];

    protected function casts(): array
    {
        return [
            'schedule' => 'array',
            'starts_at' => 'date',
            'ends_at' => 'date',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
