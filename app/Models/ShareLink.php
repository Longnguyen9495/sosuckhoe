<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Link chia sẻ hồ sơ chỉ xem (xem ShareLinkService). */
class ShareLink extends TenantModel
{
    protected $hidden = ['token_hash', 'pin_hash'];

    protected function casts(): array
    {
        return [
            'show_full_name' => 'boolean',
            'include_documents' => 'boolean',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_lockout_at' => 'datetime',
            'consented_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(ShareLinkView::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function status(): string
    {
        return $this->revoked_at !== null ? 'revoked' : ($this->expires_at->isPast() ? 'expired' : 'active');
    }
}
