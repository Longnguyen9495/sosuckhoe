<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrescriptionItem extends TenantModel
{
    public function drug(): BelongsTo
    {
        return $this->belongsTo(Drug::class);
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    protected function casts(): array
    {
        return [
            'usage_rule' => 'array',
            'requires_manual_time' => 'boolean',
        ];
    }
}
