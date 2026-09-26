<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientCondition extends TenantModel
{
    public function template(): BelongsTo
    {
        return $this->belongsTo(ConditionTemplate::class, 'condition_template_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
