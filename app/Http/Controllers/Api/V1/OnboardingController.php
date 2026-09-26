<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OnboardingDraft;
use App\Services\Onboarding\OnboardingFlow;
use App\Services\Schedule\PatientScheduleOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class OnboardingController extends Controller
{
    public function __construct(
        private readonly OnboardingFlow $flow,
        private readonly PatientScheduleOrchestrator $orchestrator,
    ) {}

    public function show(): JsonResponse
    {
        $user = Auth::user();
        $draft = $this->flow->getOrCreateDraft($user->id);
        return response()->json([
            'current_step' => $draft->current_step,
            'step_name' => OnboardingFlow::STEPS[$draft->current_step] ?? null,
            'data' => $draft->data,
        ]);
    }

    public function saveStep(Request $request, int $step): JsonResponse
    {
        if (!isset(OnboardingFlow::STEPS[$step])) {
            return response()->json(['error' => 'INVALID_STEP'], 400);
        }

        $user = Auth::user();
        $draft = $this->flow->getOrCreateDraft($user->id);

        if ($step < $draft->current_step - 1 || $step > $draft->current_step + 1) {
            return response()->json(['error' => 'STEP_OUT_OF_RANGE'], 400);
        }

        $validated = $request->validate([
            'data' => ['required', 'array'],
        ]);

        $draft = $this->flow->saveStep($draft, $step, $validated['data']);

        return response()->json([
            'current_step' => $draft->current_step,
            'step_name' => OnboardingFlow::STEPS[$draft->current_step] ?? null,
            'data' => $draft->data,
        ]);
    }

    public function goBack(): JsonResponse
    {
        $user = Auth::user();
        $draft = $this->flow->getOrCreateDraft($user->id);
        $draft = $this->flow->goBack($draft);

        return response()->json([
            'current_step' => $draft->current_step,
            'step_name' => OnboardingFlow::STEPS[$draft->current_step] ?? null,
        ]);
    }

    public function complete(Request $request): JsonResponse
    {
        $user = Auth::user();
        $draft = $this->flow->getOrCreateDraft($user->id);

        if ($draft->current_step < 5) {
            return response()->json(['error' => 'ONBOARDING_INCOMPLETE', 'message' => 'Chưa hoàn tất các bước bắt buộc.'], 400);
        }

        $result = $this->flow->complete($draft, $this->orchestrator, $request->ip());

        return response()->json([
            'tenant_id' => $result['tenant_id'],
            'patient_id' => $result['patient_id'],
            'manual_items' => $result['manual_items'],
        ]);
    }
}
