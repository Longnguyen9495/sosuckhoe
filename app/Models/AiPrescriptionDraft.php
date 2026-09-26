<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiPrescriptionDraft extends TenantModel
{
    protected $fillable = [
        'tenant_id',
        'patient_id',
        'document_id',
        'suggestions',
        'confirmed_lines',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'suggestions' => 'array',
            'confirmed_lines' => 'array',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
