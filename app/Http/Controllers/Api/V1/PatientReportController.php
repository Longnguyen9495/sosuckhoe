<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Patient;
use App\Models\Reading;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class PatientReportController extends Controller
{
    /**
     * GET /api/v1/patients/{patient}/report
     *
     * Báo cáo tóm tắt một trang cho buổi tái khám (JSON hoặc HTML/PDF cơ bản).
     */
    public function show(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        $from = CarbonImmutable::parse($validated['from'])->startOfDay();
        $to = CarbonImmutable::parse($validated['to'])->endOfDay();

        // Chỉ số gần nhất
        $latestReading = Reading::where('patient_id', $patient->id)
            ->orderByDesc('measured_at')
            ->first();

        // Cảnh báo đỏ trong khoảng
        $redAlerts = Alert::where('patient_id', $patient->id)
            ->where('level', 'red')
            ->whereBetween('created_at', [$from, $to])
            ->count();

        // Tuân thủ
        $totalLogs = DB::table('logs')
            ->where('tenant_id', $patient->tenant_id)
            ->where('patient_id', $patient->id)
            ->whereBetween('log_date', [$from->toDateString(), $to->toDateString()])
            ->count();

        $completedLogs = DB::table('logs')
            ->where('tenant_id', $patient->tenant_id)
            ->where('patient_id', $patient->id)
            ->whereBetween('log_date', [$from->toDateString(), $to->toDateString()])
            ->where('completed', true)
            ->count();

        $adherence = $totalLogs > 0 ? round(($completedLogs / $totalLogs) * 100, 2) : 0;

        // Nhận xét bác sĩ
        $doctorNotes = DB::table('notes')
            ->where('tenant_id', $patient->tenant_id)
            ->where('patient_id', $patient->id)
            ->where('type', 'doctor')
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        // Đơn thuốc đang dùng
        $activePrescriptions = DB::table('prescriptions')
            ->where('tenant_id', $patient->tenant_id)
            ->where('patient_id', $patient->id)
            ->whereIn('status', ['active', 'draft'])
            ->get();

        // Biểu đồ đường huyết nếu có
        $glucose = Reading::where('patient_id', $patient->id)
            ->where('type', 'blood_glucose')
            ->whereBetween('measured_at', [$from, $to])
            ->orderBy('measured_at')
            ->get(['measured_at', 'values', 'context']);

        $report = [
            'patient' => [
                'id' => $patient->id,
                'full_name' => $patient->full_name,
                'birth_year' => $patient->birth_year,
                'gender' => $patient->gender,
            ],
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'latest_reading' => $latestReading ? [
                'type' => $latestReading->type,
                'values' => $latestReading->values,
                'measured_at' => $latestReading->measured_at->toDateTimeString(),
                'evaluation' => $latestReading->evaluation,
            ] : null,
            'red_alerts_count' => $redAlerts,
            'adherence_percent' => $adherence,
            'doctor_notes' => $doctorNotes,
            'active_prescriptions' => $activePrescriptions,
            'blood_glucose_chart' => $glucose->map(fn ($r) => [
                'at' => $r->measured_at->toDateTimeString(),
                'value' => $r->values['value'] ?? null,
                'context' => $r->context,
            ]),
        ];

        return response()->json($report);
    }

    /**
     * GET /api/v1/patients/{patient}/report/pdf
     *
     * Trả về HTML đơn giản để in/PDF. Không dùng thư viện nặng.
     */
    public function pdf(Request $request, Patient $patient): Response
    {
        $this->authorize('view', $patient);

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        $from = CarbonImmutable::parse($validated['from'])->startOfDay();
        $to = CarbonImmutable::parse($validated['to'])->endOfDay();

        $latestReading = Reading::where('patient_id', $patient->id)
            ->orderByDesc('measured_at')
            ->first();

        $redAlerts = Alert::where('patient_id', $patient->id)
            ->where('level', 'red')
            ->whereBetween('created_at', [$from, $to])
            ->get();

        $totalLogs = DB::table('logs')
            ->where('tenant_id', $patient->tenant_id)
            ->where('patient_id', $patient->id)
            ->whereBetween('log_date', [$from->toDateString(), $to->toDateString()])
            ->count();

        $completedLogs = DB::table('logs')
            ->where('tenant_id', $patient->tenant_id)
            ->where('patient_id', $patient->id)
            ->whereBetween('log_date', [$from->toDateString(), $to->toDateString()])
            ->where('completed', true)
            ->count();

        $adherence = $totalLogs > 0 ? round(($completedLogs / $totalLogs) * 100, 2) : 0;

        $doctorNotes = DB::table('notes')
            ->where('tenant_id', $patient->tenant_id)
            ->where('patient_id', $patient->id)
            ->where('type', 'doctor')
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $html = view('reports.patient_one_page', [
            'patient' => $patient,
            'from' => $from,
            'to' => $to,
            'latestReading' => $latestReading,
            'redAlerts' => $redAlerts,
            'adherence' => $adherence,
            'doctorNotes' => $doctorNotes,
        ])->render();

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }
}
