<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class ConditionTemplate extends UlidModel
{
    public function thresholds(): HasMany
    {
        return $this->hasMany(TemplateThreshold::class);
    }

    public function monitoringPlans(): HasMany
    {
        return $this->hasMany(TemplateMonitoring::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(ContentArticle::class);
    }
}
