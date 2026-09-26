<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\Reading;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\StreamedResponse;
use Symfony\Component\HttpFoundation\StreamedResponse as SymfonyStreamedResponse;

final class PatientCsvExportController extends Controller
{
    /**
     * GET /patients/{patient}/csv-export
     * Xuất readings dưới dạng CSV (server-side)
     */
    public function csvExport(Request $request, Patient $patient): SymfonyStreamedResponse
    {
        $this->authorize('view', $patient);

        $validated = $request->validate([
            'metric' => ['required', 'string'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        $metric = $validated['metric'];
        $from = CarbonImmutable::parse($validated['from'])->startOfDay();
        $to = CarbonImmutable::parse($validated['to'])->endOfDay();

        $filename = sprintf('export_%s_%s_%s.csv', $metric, $validated['from'], $validated['to']);

        return response()->stream(function () use ($patient, $metric, $from, $to) {
            $handle = fopen('php://output', 'w');

            if ($metric === 'blood_pressure') {
                fputcsv($handle, ['Thời gian', 'Ngữ cảnh', 'Tâm thu', 'Tâm trương', 'Mạch', 'Đánh giá']);
            } else {
                fputcsv($handle, ['Thời gian', 'Ngữ cảnh', 'Giá trị', 'Đánh giá']);
            }

            Reading::where('patient_id', $patient->id)
                ->where('type', $metric)
                ->whereBetween('measured_at', [$from, $to])
                ->orderBy('measured_at')
                ->chunk(200, function ($rows) use ($handle, $metric) {
                    foreach ($rows as $r) {
                        $values = $r->values;
                        if ($metric === 'blood_pressure') {
                            fputcsv($handle, [
                                $r->measured_at->toDateTimeString(),
                                $r->context,
                                $values['systolic'] ?? '',
                                $values['diastolic'] ?? '',
                                $values['pulse'] ?? '',
                                $r->evaluation ?? '',
                            ]);
                        } else {
                            fputcsv($handle, [
                                $r->measured_at->toDateTimeString(),
                                $r->context,
                                $values['value'] ?? '',
                                $r->evaluation ?? '',
                            ]);
                        }
                    }
                });

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
