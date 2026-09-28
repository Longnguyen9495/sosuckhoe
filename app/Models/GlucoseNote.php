<?php

namespace App\Models;

/** Nhận xét đường huyết của một ngày (xem GlucoseInsightService). */
class GlucoseNote extends TenantModel
{
    protected function casts(): array
    {
        return [
            'note_date' => 'date',
            'content' => 'array',
        ];
    }
}
