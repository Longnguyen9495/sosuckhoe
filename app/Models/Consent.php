<?php

namespace App\Models;

class Consent extends TenantModel
{
    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime:Y-m-d H:i:s',
            'withdrawn_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    public function setConsentedAtAttribute($value): void
    {
        $this->attributes['consented_at'] = $value instanceof \Carbon\CarbonInterface
            ? $value->format('Y-m-d H:i:s')
            : (is_string($value) ? \Carbon\Carbon::parse($value)->format('Y-m-d H:i:s') : $value);
    }
}
