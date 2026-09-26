<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LabResult;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class LabResultController extends Controller
{
    public function index(Patient $patient): JsonResponse
    {
        Gate::authorize('view', $patient);

        $results = LabResult::where('patient_id', $patient->id)
            ->orderByDesc('measured_at')
            ->get();

        return response()->json(['data' => $results]);
    }

    public function store(Request $request, Patient $patient): JsonResponse
    {
        Gate::authorize('view', $patient);

        $validated = $request->validate([
            'metric' => ['required', 'string', 'max:100'],
            'value' => ['required', 'string', 'max:100'],
            'unit' => ['nullable', 'string', 'max:50'],
            'reference_range' => ['nullable', 'string', 'max:100'],
            'measured_at' => ['required', 'date'],
        ]);

        // Tự gắn cờ theo khoảng tham chiếu (đơn giản)
        $flag = $this->evaluateFlag($validated['value'], $validated['reference_range'] ?? null);

        $result = LabResult::create([
            'tenant_id' => $patient->tenant_id,
            'patient_id' => $patient->id,
            'document_id' => $validated['document_id'] ?? null,
            'metric' => $validated['metric'],
            'value' => $validated['value'],
            'unit' => $validated['unit'],
            'reference_range' => $validated['reference_range'],
            'flag' => $flag,
            'measured_at' => $validated['measured_at'],
        ]);

        return response()->json(['id' => $result->id], 201);
    }

    private function evaluateFlag(string $value, ?string $range): ?string
    {
        if ($range === null) {
            return null;
        }

        // Xử lý khoảng đơn giản dạng "X-Y" hoặc "< X" hoặc "> Y"
        $num = (float) $value;
        if (preg_match('/^([\d.]+)\s*-\s*([\d.]+)$/', trim($range), $m)) {
            if ($num < (float) $m[1]) {
                return 'low';
            }
            if ($num > (float) $m[2]) {
                return 'high';
            }
            return 'normal';
        }
        if (preg_match('/^<\s*([\d.]+)$/', trim($range), $m)) {
            return $num >= (float) $m[1] ? 'high' : 'normal';
        }
        if (preg_match('/^>\s*([\d.]+)$/', trim($range), $m)) {
            return $num <= (float) $m[1] ? 'low' : 'normal';
        }

        return null;
    }
}
