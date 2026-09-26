<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\Reading;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PatientChartController extends Controller
{
    /**
     * GET /patients/{patient}/chart-data
     */
    public function chartData(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $validated = $request->validate([
            'metric' => ['required', 'string'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        $metric = $validated['metric'];
        $from = CarbonImmutable::parse($validated['from'])->startOfDay();
        $to = CarbonImmutable::parse($validated['to'])->endOfDay();

        $query = Reading::where('patient_id', $patient->id)
            ->where('type', $metric)
            ->whereBetween('measured_at', [$from, $to])
            ->orderBy('measured_at');

        $rows = $query->get();

        $data = $rows->map(function (Reading $r) use ($metric) {
            $values = $r->values;
            $point = [
                'at' => $r->measured_at->toDateTimeString(),
                'context' => $r->context,
                'evaluation' => $r->evaluation,
            ];

            if ($metric === 'blood_pressure') {
                $point['systolic'] = $values['systolic'] ?? null;
                $point['diastolic'] = $values['diastolic'] ?? null;
                $point['pulse'] = $values['pulse'] ?? null;
            } else {
                $point['value'] = $values['value'] ?? null;
            }

            return $point;
        })->values();

        return response()->json(['data' => $data]);
    }
}
