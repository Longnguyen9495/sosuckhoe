<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleItem extends TenantModel
{
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
