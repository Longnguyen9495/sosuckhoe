<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientThreshold;
use App\Models\ThresholdHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class ThresholdController extends Controller
{
    public function index(Patient $patient): JsonResponse
    {
        Gate::authorize('view', $patient);

        $thresholds = PatientThreshold::where('patient_id', $patient->id)
            ->orderBy('metric')
            ->get(['id', 'metric', 'context', 'ranges', 'source', 'confirmed_by', 'confirmed_at']);

        return response()->json($thresholds);
    }

    public function update(Request $request, Patient $patient, PatientThreshold $threshold): JsonResponse
    {
        $user = $request->user();
        $isDoctor = Gate::forUser($user)->allows('manageClinicalPlan', $patient);
        abort_unless($isDoctor || Gate::forUser($user)->allows('updateFamilyLog', $patient), 403);

        $validated = $request->validate([
            'ranges' => ['required', 'array'],
        ]);

        // Lưu lịch sử
        ThresholdHistory::create([
            'tenant_id' => $patient->tenant_id,
            'patient_id' => $patient->id,
            'patient_threshold_id' => $threshold->id,
            'changed_by' => Auth::id(),
            'old_ranges' => $threshold->ranges,
            'new_ranges' => $validated['ranges'],
        ]);

        // Chỉ bác sĩ sửa mới tính là "đã xác nhận"; người nhà sửa thì vẫn "chưa được bác sĩ xác nhận".
        $threshold->update([
            'ranges' => $validated['ranges'],
            'source' => $isDoctor ? 'doctor' : 'owner',
            'confirmed_by' => $isDoctor ? Auth::id() : null,
            'confirmed_at' => $isDoctor ? now() : null,
        ]);

        return response()->json(['id' => $threshold->id]);
    }

    public function history(Patient $patient): JsonResponse
    {
        Gate::authorize('view', $patient);

        $history = ThresholdHistory::where('patient_id', $patient->id)
            ->with('changedBy:id,name')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json($history);
    }
}
