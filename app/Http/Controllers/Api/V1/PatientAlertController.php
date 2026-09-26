<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PatientAlertController extends Controller
{
    /**
     * GET /patients/{patient}/alerts
     */
    public function index(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $validated = $request->validate([
            'level' => ['nullable', 'in:info,warning,red'],
            'unseen_only' => ['nullable', 'boolean'],
        ]);

        $query = Alert::where('patient_id', $patient->id);

        if (! empty($validated['level'])) {
            $query->where('level', $validated['level']);
        }
        if (! empty($validated['unseen_only'])) {
            $query->whereNull('seen_at');
        }

        return response()->json([
            'data' => $query->orderByDesc('created_at')->limit(100)->get(),
        ]);
    }

    /**
     * PATCH /patients/{patient}/alerts/{alert}/seen
     */
    public function markSeen(Request $request, Patient $patient, Alert $alert): JsonResponse
    {
        $this->authorize('view', $patient);

        if ($alert->patient_id !== $patient->id) {
            return response()->json(['message' => 'Alert không thuộc bệnh nhân này.'], 403);
        }

        $alert->update(['seen_at' => now()]);

        return response()->json(['data' => $alert]);
    }
}
