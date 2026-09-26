<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingDraft extends UlidModel
{
    protected $fillable = ['user_id', 'tenant_id', 'current_step', 'data', 'completed_at'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
