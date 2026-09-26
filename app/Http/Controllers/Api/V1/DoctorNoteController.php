<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Note;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class DoctorNoteController extends Controller
{
    /**
     * GET /api/v1/patients/{patient}/notes
     */
    public function index(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $type = $request->validate(['type' => ['nullable', 'in:family,doctor']])['type'] ?? null;

        $query = Note::where('patient_id', $patient->id)
            ->orderByDesc('created_at');

        if ($type) {
            $query->where('type', $type);
        }

        return response()->json([
            'data' => $query->limit(100)->get(),
        ]);
    }

    /**
     * POST /api/v1/patients/{patient}/notes
     */
    public function store(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
            'type' => ['required', 'in:family,doctor'],
        ]);

        $note = Note::create([
            'tenant_id' => $patient->tenant_id,
            'patient_id' => $patient->id,
            'author_id' => Auth::user()->id,
            'type' => $validated['type'],
            'content' => $validated['content'],
        ]);

        return response()->json($note, 201);
    }
}
