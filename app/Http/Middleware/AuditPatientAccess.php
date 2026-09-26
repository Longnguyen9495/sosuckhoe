<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Models\Patient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuditPatientAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $patient = $request->route('patient');

        AuditLog::query()->create([
            'user_id' => $request->user()?->getKey(),
            'action' => strtolower($request->method()).':'.$request->route()->getName(),
            'subject_type' => $patient instanceof Patient ? Patient::class : 'patient_collection',
            'subject_id' => $patient instanceof Patient ? $patient->getKey() : null,
            'ip_address' => $request->ip(),
            'metadata' => [
                'route' => $request->route()->uri(),
                'status' => $response->getStatusCode(),
            ],
        ]);

        return $response;
    }
}
