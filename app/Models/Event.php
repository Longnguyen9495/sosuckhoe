<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends TenantModel
{
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
