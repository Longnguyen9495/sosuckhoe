<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $tenantId = app(TenantContext::class)->id();

            if ($tenantId === null) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where($builder->qualifyColumn('tenant_id'), $tenantId);
        });

        static::creating(function (Model $model): void {
            $contextTenantId = app(TenantContext::class)->id();

            if ($contextTenantId === null) {
                throw new LogicException('Cannot create tenant-owned data without tenant context.');
            }

            if ($model->getAttribute('tenant_id') !== null && $model->getAttribute('tenant_id') !== $contextTenantId) {
                throw new LogicException('The supplied tenant does not match the active tenant context.');
            }

            $model->setAttribute('tenant_id', $contextTenantId);
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
