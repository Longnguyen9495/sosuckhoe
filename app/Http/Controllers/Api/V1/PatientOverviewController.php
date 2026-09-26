<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Note;
use App\Models\Patient;
use App\Models\PatientCondition;
use App\Models\Reading;
use App\Services\Schedule\DayPlanBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PatientOverviewController extends Controller
{
    public function __construct(private readonly DayPlanBuilder $dayPlan)
    {
    }

    /**
     * GET /patients/{patient}/overview?date=YYYY-MM-DD
     * Hồ sơ tóm tắt: bệnh nền, tuân thủ 7 ngày, chỉ số gần nhất, cảnh báo 7 ngày.
     */
    public function show(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $validated = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $today = CarbonImmutable::parse($validated['date'] ?? now()->toDateString());

        $conditions = PatientCondition::where('patient_id', $patient->getKey())
            ->with('template')
            ->orderByRaw("CASE priority WHEN 'high' THEN 0 ELSE 1 END")
            ->get();

        $latestGlucose = Reading::where('patient_id', $patient->getKey())->where('type', 'blood_glucose')->latest('measured_at')->first();
        $latestPressure = Reading::where('patient_id', $patient->getKey())->where('type', 'blood_pressure')->latest('measured_at')->first();

        $alerts = Alert::where('patient_id', $patient->getKey())
            ->where('created_at', '>=', now()->subDays(7))
            ->latest()
            ->limit(5)
            ->get();

        $todaySummary = $this->dayPlan->build($patient, $today)['summary'];

        return response()->json([
            'data' => [
                'patient' => [
                    'id' => $patient->getKey(),
                    'full_name' => $patient->full_name,
                    'birth_year' => $patient->birth_year,
                    'gender' => $patient->gender,
                    'allergies' => $patient->allergies,
                ],
                'conditions' => $conditions->map(function (PatientCondition $c) {
                    [$title, $detail] = array_pad(explode("\n", (string) $c->notes, 2), 2, null);

                    return [
                        'id' => $c->getKey(),
                        'title' => $title ?: $c->template?->name,
                        'detail' => $detail,
                        'priority' => $c->priority,
                        'template_code' => $c->template?->code,
                    ];
                })->values(),
                'today' => $todaySummary,
                // null khi 7 ngày qua không có việc nào (không hiển thị "100%").
                'adherence_7d' => $this->dayPlan->adherence($patient, $today->subDays(6), $today),
                'latest_readings' => [
                    'blood_glucose' => $latestGlucose ? $this->dayPlan->presentReading($latestGlucose) : null,
                    'blood_pressure' => $latestPressure ? $this->dayPlan->presentReading($latestPressure) : null,
                ],
                'alerts' => $alerts->map(fn (Alert $a) => [
                    'id' => $a->getKey(),
                    'level' => $a->level,
                    'content' => $a->content,
                    'seen_at' => $a->seen_at ?? null,
                    'created_at' => $a->created_at?->toIso8601String(),
                ])->values(),
                'doctor_notes_count' => Note::where('patient_id', $patient->getKey())->count(),
            ],
        ]);
    }
}
