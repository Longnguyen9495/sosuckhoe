<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\TenantMember;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetTenantContext
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->header('X-Tenant-ID');
        $user = $request->user();

        abort_if(! is_string($tenantId) || $tenantId === '' || $user === null, 404);

        $isMember = TenantMember::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->getKey())
            ->exists();

        abort_unless($isMember, 404);

        $tenant = Tenant::query()->findOrFail($tenantId);
        $this->context->set($tenant);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
