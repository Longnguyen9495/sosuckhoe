<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TenantMemberController extends Controller
{
    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        $members = TenantMember::where('tenant_id', $tenant->getKey())
            ->with('user')
            ->get();

        return response()->json([
            'data' => $members->map(fn (TenantMember $m) => [
                'id' => $m->getKey(),
                'role' => $m->role,
                'user' => $m->user ? [
                    'id' => $m->user->getKey(),
                    'name' => $m->user->name,
                    'phone' => $m->user->phone,
                ] : null,
            ]),
        ]);
    }
}
