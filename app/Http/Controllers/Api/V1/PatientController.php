<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PatientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->getKey();
        $patients = Patient::query()
            ->whereHas('access', fn ($query) => $query->where('user_id', $userId))
            ->with(['access' => fn ($query) => $query->where('user_id', $userId)])
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'birth_year', 'gender', 'timezone']);

        // access_role: vai trò của người đang đăng nhập với từng bệnh nhân (caregiver / patient / doctor / viewer).
        return response()->json(['data' => $patients->map(fn (Patient $p) => [
            'id' => $p->id,
            'full_name' => $p->full_name,
            'birth_year' => $p->birth_year,
            'gender' => $p->gender,
            'timezone' => $p->timezone,
            'access_role' => $p->access->first()?->role,
        ])->values()]);
    }

    public function show(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        return response()->json([
            'data' => $patient->only(['id', 'full_name', 'birth_year', 'gender', 'allergies', 'timezone']),
        ]);
    }
}
