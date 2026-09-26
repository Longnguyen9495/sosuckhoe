<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MonitoringPlan;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DoctorMonitoringPlanController extends Controller
{
    /**
     * PUT /api/v1/patients/{patient}/monitoring-plans/{plan}
     */
    public function update(Request $request, Patient $patient, MonitoringPlan $monitoringPlan): JsonResponse
    {
        $this->authorize('view', $patient);

        if ($monitoringPlan->patient_id !== $patient->id) {
            return response()->json(['message' => 'Kế hoạch không thuộc bệnh nhân này.'], 403);
        }

        $validated = $request->validate([
            'schedule' => ['required', 'array'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);

        $monitoringPlan->update([
            'schedule' => $validated['schedule'],
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'] ?? null,
        ]);

        return response()->json($monitoringPlan->fresh());
    }
}
