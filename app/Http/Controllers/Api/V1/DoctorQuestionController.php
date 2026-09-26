<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class DoctorQuestionController extends Controller
{
    /**
     * POST /api/v1/patients/{patient}/questions/{question}/answer
     */
    public function answer(Request $request, Patient $patient, Question $question): JsonResponse
    {
        $this->authorize('view', $patient);

        if ($question->patient_id !== $patient->id) {
            return response()->json(['message' => 'Câu hỏi không thuộc bệnh nhân này.'], 403);
        }

        $validated = $request->validate([
            'answer' => ['required', 'string', 'max:5000'],
        ]);

        $question->update([
            'answer' => $validated['answer'],
            'answered_by' => Auth::user()->id,
            'answered_at' => now(),
        ]);

        return response()->json($question);
    }
}
