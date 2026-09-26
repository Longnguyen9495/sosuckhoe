<?php

namespace App\Http\Middleware;

use App\Models\PatientAccess;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireDoctorTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $isDoctor = PatientAccess::query()
            ->where('user_id', $user->getKey())
            ->where('role', 'doctor')
            ->exists();

        if (! $isDoctor) {
            return $next($request);
        }

        // Nếu chưa cài 2FA → từ chối
        if ($user->two_factor_confirmed_at === null) {
            return new JsonResponse([
                'message' => 'Bác sĩ phải xác nhận xác thực hai yếu tố trước khi truy cập dữ liệu bệnh nhân.',
                'code' => 'DOCTOR_TWO_FACTOR_REQUIRED',
            ], 403);
        }

        // Nếu token không có ability '2fa-passed' → từ chối
        if (! $request->bearerToken()) {
            return new JsonResponse([
                'message' => 'Phiên làm việc yêu cầu xác thực hai yếu tố.',
                'code' => 'DOCTOR_TWO_FACTOR_CHALLENGE_REQUIRED',
            ], 403);
        }

        $token = $user->currentAccessToken();
        if ($token === null || ! in_array('2fa-passed', $token->abilities ?? [], true)) {
            return new JsonResponse([
                'message' => 'Phiên làm việc yêu cầu xác thực hai yếu tố.',
                'code' => 'DOCTOR_TWO_FACTOR_CHALLENGE_REQUIRED',
            ], 403);
        }

        return $next($request);
    }
}
