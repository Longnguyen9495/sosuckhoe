<?php

namespace App\Models;

/** Thực đơn + bài tập của một ngày (xem DailyPlanService). */
class DailyPlan extends TenantModel
{
    protected function casts(): array
    {
        return [
            'plan_date' => 'date',
            'menu' => 'array',
            'exercise_ids' => 'array',
        ];
    }
}
