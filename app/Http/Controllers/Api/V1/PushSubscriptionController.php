<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string'],
            'public_key' => ['required', 'string'],
            'auth_token' => ['required', 'string'],
            'device' => ['nullable', 'string', 'max:255'],
        ]);

        $tenantId = $request->header('X-Tenant-ID');

        PushSubscription::updateOrCreate(
            [
                'user_id' => $request->user()->getKey(),
                'endpoint' => $validated['endpoint'],
            ],
            [
                'tenant_id' => $tenantId,
                'public_key' => $validated['public_key'],
                'auth_token' => $validated['auth_token'],
                'device' => $validated['device'] ?? null,
                'enabled' => true,
            ]
        );

        return response()->json(['data' => ['subscribed' => true]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string'],
        ]);

        PushSubscription::where('user_id', $request->user()->getKey())
            ->where('endpoint', $validated['endpoint'])
            ->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }
}
