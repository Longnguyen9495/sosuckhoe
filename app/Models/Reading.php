<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reading extends TenantModel
{
    protected function casts(): array
    {
        return [
            'measured_at' => 'datetime',
            'values' => 'array',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
