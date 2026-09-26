<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Log extends TenantModel
{
    protected function casts(): array
    {
        return [
            'log_date' => 'date',
            'completed' => 'boolean',
            'completed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
