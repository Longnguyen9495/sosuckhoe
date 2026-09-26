<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConditionTemplate;
use App\Models\Patient;
use App\Services\Template\TemplateCloner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class TemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $templates = ConditionTemplate::select('id', 'code', 'icd_code', 'name', 'metrics')->get();
        return response()->json($templates);
    }

    public function assign(Request $request, Patient $patient): JsonResponse
    {
        Gate::authorize('view', $patient);

        $validated = $request->validate([
            'template_id' => ['required', 'string', 'exists:condition_templates,id'],
        ]);

        $cloner = app(TemplateCloner::class);
        $cloner->cloneForPatient($patient->tenant_id, $patient->id, $validated['template_id'], Auth::id());

        return response()->json(['status' => 'assigned'], 201);
    }
}
