<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Question extends TenantModel
{
    protected $fillable = [
        'tenant_id',
        'patient_id',
        'doctor_id',
        'asked_by',
        'asked_at',
        'specialty',
        'doctor_name',
        'due_date',
        'question',
        'answer',
        'answered_by',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'asked_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
