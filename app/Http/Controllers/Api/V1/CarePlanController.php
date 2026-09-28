<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CarePlan;
use App\Models\DailyPlan;
use App\Models\Patient;
use App\Services\CarePlan\CarePlanService;
use App\Services\CarePlan\ExerciseLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Kế hoạch chăm sóc (chế độ ăn uống, sinh hoạt, theo dõi) do AI lập — để tham khảo. */
final class CarePlanController extends Controller
{
    public function __construct(private readonly CarePlanService $service) {}

    public function show(Patient $patient): JsonResponse
    {
        Gate::authorize('view', $patient);

        return response()->json(['data' => $this->present($this->service->latest($patient), $patient)]);
    }

    public function store(Request $request, Patient $patient): JsonResponse
    {
        Gate::authorize('updateFamilyLog', $patient);

        try {
            $plan = $this->service->generate($patient, $request->user()->id);
            // Kế hoạch mới (nguyên tắc ăn, bài tập có thể khác): bỏ thực đơn / bài tập ngày đã lên theo kế hoạch cũ từ hôm nay.
            DailyPlan::where('patient_id', $patient->id)->whereDate('plan_date', '>=', now()->toDateString())->delete();
        } catch (Throwable $e) {
            Log::warning('Care plan generation failed', ['patient' => $patient->id, 'error' => class_basename($e)]);

            return response()->json(['code' => 'AI_FAILED', 'message' => 'AI chưa lập được kế hoạch. Vui lòng thử lại sau ít phút.'], 502);
        }

        return response()->json(['data' => $this->present($plan, $patient)], 201);
    }

    private function present(?CarePlan $plan, Patient $patient): ?array
    {
        if ($plan === null) {
            return null;
        }

        return [
            'id' => $plan->id,
            'content' => $plan->content,
            'exercises' => $this->service->exercises($plan, $patient),
            'exercise_safety' => ExerciseLibrary::SAFETY,
            'sources' => $plan->sources,
            'model' => $plan->model,
            'created_at' => $plan->created_at?->toIso8601String(),
            'disclaimer' => CarePlanService::DISCLAIMER,
        ];
    }
}
