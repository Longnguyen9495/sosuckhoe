<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Patient;
use App\Models\PatientAccess;
use App\Models\TenantMember;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class InvitationController extends Controller
{
    public function store(Request $request, Patient $patient): JsonResponse
    {
        // Chỉ người chăm sóc / người bệnh được mời thêm người; bác sĩ và người xem không được.
        $this->authorize('updateFamilyLog', $patient);

        $validated = $request->validate([
            'recipient' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in(['caregiver', 'patient', 'doctor', 'viewer'])],
        ]);
        $token = Str::random(64);
        $invitation = Invitation::query()->create([
            'invited_by' => $request->user()->getKey(),
            'patient_id' => $patient->getKey(),
            'recipient' => $validated['recipient'],
            'role' => $validated['role'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
        ]);

        return response()->json([
            'data' => [
                'id' => $invitation->getKey(),
                'token' => $token,
                'expires_at' => $invitation->expires_at,
            ],
        ], 201);
    }

    public function accept(Request $request, TenantContext $context): JsonResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $tokenHash = hash('sha256', $validated['token']);

        $invitation = Invitation::withoutGlobalScope('tenant')
            ->where('token_hash', $tokenHash)
            ->first();

        if ($invitation === null || $invitation->accepted_at !== null || $invitation->expires_at->isPast()) {
            throw ValidationException::withMessages(['token' => 'Lời mời không hợp lệ hoặc đã hết hạn.']);
        }

        $recipient = mb_strtolower(trim($invitation->recipient));
        $userRecipients = array_filter([
            $request->user()->phone !== null ? mb_strtolower(trim($request->user()->phone)) : null,
            $request->user()->email !== null ? mb_strtolower(trim($request->user()->email)) : null,
        ]);
        if (! in_array($recipient, $userRecipients, true)) {
            throw ValidationException::withMessages(['token' => 'Lời mời không dành cho tài khoản này.']);
        }

        try {
            DB::transaction(function () use ($invitation, $request, $context): void {
                $locked = Invitation::withoutGlobalScope('tenant')->lockForUpdate()->findOrFail($invitation->getKey());
                if ($locked->accepted_at !== null || $locked->expires_at->isPast()) {
                    throw ValidationException::withMessages(['token' => 'Lời mời không hợp lệ hoặc đã hết hạn.']);
                }

                $context->set($locked->tenant()->withoutGlobalScopes()->firstOrFail());
                TenantMember::query()->firstOrCreate(
                    ['user_id' => $request->user()->getKey()],
                    ['role' => 'member'],
                );
                PatientAccess::query()->firstOrCreate(
                    ['patient_id' => $locked->patient_id, 'user_id' => $request->user()->getKey()],
                    ['role' => $locked->role],
                );
                $locked->update(['accepted_at' => now()]);
            });
        } finally {
            $context->clear();
        }

        return response()->json(['message' => 'Đã chấp nhận lời mời.']);
    }
}
