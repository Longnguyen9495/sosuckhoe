<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantMember extends TenantModel
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
