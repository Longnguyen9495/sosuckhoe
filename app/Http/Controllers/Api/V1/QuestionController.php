<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class QuestionController extends Controller
{
    public function index(Patient $patient): JsonResponse
    {
        Gate::authorize('view', $patient);

        $questions = Question::where('patient_id', $patient->id)
            ->orderBy('due_date')
            ->orderBy('specialty')
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => $questions->map(fn (Question $q) => [
            'id' => $q->id,
            'specialty' => $q->specialty ?? 'Khác',
            'doctor_name' => $q->doctor_name,
            'due_date' => $q->due_date === null ? null : substr((string) $q->due_date, 0, 10),
            'question' => $q->question,
            'asked' => $q->asked_at !== null,
            'asked_at' => $q->asked_at?->toIso8601String(),
            'answer' => $q->answer,
            'answered_at' => $q->answered_at?->toIso8601String(),
        ])->values()]);
    }

    public function store(Request $request, Patient $patient): JsonResponse
    {
        Gate::authorize('updateFamilyLog', $patient);

        $validated = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'specialty' => ['nullable', 'string', 'max:100'],
            'doctor_name' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
        ]);

        // asked_at = thời điểm đã hỏi bác sĩ; câu hỏi mới tạo là "chưa hỏi".
        $q = Question::create([
            'patient_id' => $patient->id,
            'asked_by' => Auth::id(),
            'question' => $validated['question'],
            'specialty' => $validated['specialty'] ?? null,
            'doctor_name' => $validated['doctor_name'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'asked_at' => null,
        ]);

        return response()->json(['id' => $q->id], 201);
    }

    public function markAsked(Patient $patient, Question $question): JsonResponse
    {
        Gate::authorize('updateFamilyLog', $patient);

        $asked = request()->has('asked') ? request()->boolean('asked') : true;
        $question->update(['asked_at' => $asked ? now() : null]);
        return response()->json(['status' => 'marked']);
    }
}
