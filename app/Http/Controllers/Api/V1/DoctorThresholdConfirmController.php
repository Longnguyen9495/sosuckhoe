<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientThreshold;
use App\Models\ThresholdHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class DoctorThresholdConfirmController extends Controller
{
    /**
     * POST /api/v1/patients/{patient}/thresholds/{threshold}/confirm
     */
    public function confirm(Request $request, Patient $patient, PatientThreshold $threshold): JsonResponse
    {
        $this->authorize('view', $patient);

        if ($threshold->patient_id !== $patient->id) {
            return response()->json(['message' => 'Ngưỡng không thuộc bệnh nhân này.'], 403);
        }

        $oldRanges = $threshold->ranges;

        $threshold->update([
            'confirmed_by' => Auth::user()->id,
            'confirmed_at' => now(),
            'source' => 'doctor',
        ]);

        ThresholdHistory::create([
            'tenant_id' => $patient->tenant_id,
            'patient_id' => $patient->id,
            'patient_threshold_id' => $threshold->id,
            'changed_by' => Auth::user()->id,
            'old_ranges' => $oldRanges,
            'new_ranges' => $threshold->ranges,
        ]);

        return response()->json([
            'message' => 'Ngưỡng đã được xác nhận.',
            'threshold' => $threshold->fresh(),
        ]);
    }
}
