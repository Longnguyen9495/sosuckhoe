<?php

namespace App\Support;

use App\Models\Tenant;
use LogicException;

final class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function setFromId(string $tenantId): void
    {
        $this->tenant = new Tenant(['id' => $tenantId]);
    }

    public function clear(): void
    {
        $this->tenant = null;
    }

    public function current(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?string
    {
        return $this->tenant?->getKey();
    }

    public function requireId(): string
    {
        return $this->id() ?? throw new LogicException('Tenant context has not been established.');
    }
}
