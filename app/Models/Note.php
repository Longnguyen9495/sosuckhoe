<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Note extends TenantModel
{
    protected $fillable = [
        'tenant_id',
        'patient_id',
        'author_id',
        'type',
        'content',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
