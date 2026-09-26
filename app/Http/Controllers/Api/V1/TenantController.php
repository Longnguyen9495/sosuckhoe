<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TenantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantIds = $request->user()
            ->tenantMemberships()
            ->withoutGlobalScope('tenant')
            ->pluck('tenant_id');

        $tenants = Tenant::query()
            ->whereKey($tenantIds)
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'plan', 'status']);

        return response()->json(['data' => $tenants]);
    }
}
