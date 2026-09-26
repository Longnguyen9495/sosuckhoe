<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Patient;
use App\Models\PatientAccess;
use App\Models\Reading;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class DoctorPatientListController extends Controller
{
    /**
     * GET /api/v1/clinic/patients
     *
     * Danh sách bệnh nhân mà bác sĩ được giao, sắp theo cờ đỏ 7 ngày.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $tenantId = app(TenantContext::class)->requireId();

        $patientIds = PatientAccess::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('role', 'doctor')
            ->pluck('patient_id');

        $patients = Patient::whereIn('id', $patientIds)
            ->with(['access'])
            ->get();

        $sevenDaysAgo = now()->subDays(7)->toDateTimeString();

        $result = $patients->map(function (Patient $patient) use ($sevenDaysAgo, $tenantId) {
            $redCount = Alert::where('patient_id', $patient->id)
                ->where('level', 'red')
                ->where('created_at', '>=', $sevenDaysAgo)
                ->count();

            $latestReading = Reading::where('patient_id', $patient->id)
                ->orderByDesc('measured_at')
                ->first();

            // Tuân thủ 7 ngày tính theo lịch thật (việc đã làm / việc phải làm); null khi không có việc.
            $today = \Carbon\CarbonImmutable::today();
            $adherence = app(\App\Services\Schedule\DayPlanBuilder::class)->adherence($patient, $today->subDays(6), $today);

            $nextAppointment = DB::table('events')
                ->where('tenant_id', $tenantId)
                ->where('patient_id', $patient->id)
                ->where('type', 'appointment')
                ->where('event_date', '>=', now()->toDateString())
                ->orderBy('event_date')
                ->first();

            return [
                'id' => $patient->id,
                'full_name' => $patient->full_name,
                'birth_year' => $patient->birth_year,
                'gender' => $patient->gender,
                'red_flag_count_7d' => $redCount,
                'latest_reading' => $latestReading ? [
                    'type' => $latestReading->type,
                    'values' => $latestReading->values,
                    'measured_at' => $latestReading->measured_at->toDateTimeString(),
                ] : null,
                'adherence_percent' => $adherence,
                'next_appointment' => $nextAppointment ? [
                    'date' => $nextAppointment->event_date,
                    'title' => $nextAppointment->title,
                ] : null,
            ];
        });

        // Sắp xếp: cờ đỏ nhiều lên đầu, rồi tuân thủ thấp lên trước, rồi tên.
        $sorted = $result->sortBy([
            ['red_flag_count_7d', 'desc'],
            fn ($a, $b) => ($a['adherence_percent'] ?? 101) <=> ($b['adherence_percent'] ?? 101),
            ['full_name', 'asc'],
        ])->values();

        return response()->json(['data' => $sorted]);
    }

    private function adherenceRate(string $patientId, string $tenantId): float
    {
        $total = DB::table('logs')
            ->where('tenant_id', $tenantId)
            ->where('patient_id', $patientId)
            ->where('log_date', '>=', now()->subDays(30)->toDateString())
            ->count();

        if ($total === 0) {
            return 0.0;
        }

        $completed = DB::table('logs')
            ->where('tenant_id', $tenantId)
            ->where('patient_id', $patientId)
            ->where('log_date', '>=', now()->subDays(30)->toDateString())
            ->where('completed', true)
            ->count();

        return round(($completed / $total) * 100, 2);
    }
}
