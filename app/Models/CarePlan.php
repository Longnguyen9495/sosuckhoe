<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kế hoạch chăm sóc do AI lập từ thuốc đang dùng và kết quả xét nghiệm (chỉ để tham khảo). */
class CarePlan extends TenantModel
{
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'sources' => 'array',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
