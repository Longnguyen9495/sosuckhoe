<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends UlidModel
{
    public function members(): HasMany
    {
        return $this->hasMany(TenantMember::class);
    }

    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }
}
