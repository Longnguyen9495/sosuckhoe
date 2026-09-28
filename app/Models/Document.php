<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends TenantModel
{
    protected $fillable = [
        'tenant_id',
        'patient_id',
        'encrypted_path',
        'type',
        'document_date',
        'department',
        'doctor_name',
        'analysis',
        'duplicate_of_id',
        'content_hash',
        'ai_status',
        'ai_error',
        'prescription_id',
    ];

    protected function casts(): array
    {
        return [
            'analysis' => 'array',
            'document_date' => 'date',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
