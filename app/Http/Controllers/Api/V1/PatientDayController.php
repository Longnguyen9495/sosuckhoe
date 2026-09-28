<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Log;
use App\Models\Patient;
use App\Models\Reading;
use App\Models\ScheduleItem;
use App\Services\Alerts\ReadingEvaluator;
use App\Services\Schedule\DayPlanBuilder;
use App\Services\Schedule\MeasurementPoints;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PatientDayController extends Controller
{
    public function __construct(
        private readonly ReadingEvaluator $evaluator,
        private readonly DayPlanBuilder $dayPlan,
    ) {}

    /**
     * GET /patients/{patient}/day/{date}
     * Toàn bộ việc trong ngày: thuốc, insulin, bôi, lượt đo, bữa ăn, trạng thái, mốc lịch.
     */
    public function day(Request $request, Patient $patient, string $date): JsonResponse
    {
        $this->authorize('view', $patient);
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1, 422, 'Ngày không hợp lệ.');

        return response()->json(['data' => $this->dayPlan->build($patient, CarbonImmutable::parse($date))]);
    }

    /**
     * POST /patients/{patient}/logs
     * - Có schedule_item_id: tích / bỏ tích một việc.
     * - Không có schedule_item_id: ghi thông tin cả ngày (số cốc nước, dấu hiệu bất thường, ghi chú) vào meta.
     */
    public function storeLog(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('updateFamilyLog', $patient);

        $validated = $request->validate([
            'log_date' => ['required', 'date_format:Y-m-d'],
            // Việc phải thuộc đúng bệnh nhân này (không chấp nhận id của bệnh nhân / tài khoản khác).
            'schedule_item_id' => ['nullable', 'string', Rule::exists('schedule_items', 'id')->where('patient_id', $patient->id)->where('tenant_id', $patient->tenant_id)],
            'completed' => ['required_with:schedule_item_id', 'boolean'],
            'meta' => ['nullable', 'array'],
            'meta.water_cups' => ['nullable', 'integer', 'min:0', 'max:20'],
            'meta.symptoms' => ['nullable', 'array'],
            'meta.symptoms.*' => ['string', 'max:40'],
            'meta.note' => ['nullable', 'string', 'max:2000'],
            // Bài tập (id trong thư viện) đã tập trong ngày.
            'meta.exercises_done' => ['nullable', 'array', 'max:20'],
            'meta.exercises_done.*' => ['string', 'max:40'],
        ]);

        $scheduleItemId = $validated['schedule_item_id'] ?? null;
        $log = Log::where('patient_id', $patient->id)
            ->whereDate('log_date', $validated['log_date'])
            ->where(fn ($q) => $scheduleItemId === null ? $q->whereNull('schedule_item_id') : $q->where('schedule_item_id', $scheduleItemId))
            ->first() ?? new Log([
                'patient_id' => $patient->id,
                'log_date' => $validated['log_date'],
                'schedule_item_id' => $scheduleItemId,
            ]);

        if ($scheduleItemId !== null) {
            $completed = (bool) $validated['completed'];
            $log->completed = $completed;
            $log->completed_at = $completed ? now() : null;
        } else {
            $log->completed = $log->completed ?? false;
            $log->meta = array_merge($log->meta ?? [], array_intersect_key($validated['meta'] ?? [], array_flip(['water_cups', 'symptoms', 'note', 'exercises_done'])));
        }
        $log->recorded_by = $request->user()?->id;
        $log->save();

        return response()->json(['data' => $log], 201);
    }

    /**
     * POST /patients/{patient}/readings
     * context = mã điểm đo (fasting, pre_lunch, bp_morning…) hoặc ngữ cảnh ngưỡng.
     */
    public function storeReading(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('updateFamilyLog', $patient);

        $validated = $request->validate([
            'type' => ['required', 'in:blood_glucose,blood_pressure,heart_rate'],
            'context' => ['nullable', 'string', 'max:40'],
            'measured_at' => ['required', 'date'],
            'values' => ['required', 'array'],
            'values.value' => ['required_if:type,blood_glucose,heart_rate', 'nullable', 'numeric', 'min:0', $request->input('type') === 'heart_rate' ? 'max:250' : 'max:60'],
            'values.systolic' => ['required_if:type,blood_pressure', 'nullable', 'integer', 'min:40', 'max:300'],
            'values.diastolic' => ['required_if:type,blood_pressure', 'nullable', 'integer', 'min:20', 'max:200'],
            'values.heart_rate' => ['nullable', 'integer', 'min:20', 'max:250'],
        ]);

        $context = $validated['context'] ?? 'general';
        $thresholdContext = MeasurementPoints::thresholdContext($validated['type'], $context);

        $evaluated = $this->evaluator->evaluate($patient->id, $validated['type'], $thresholdContext, $validated['values'], $patient->tenant_id);

        // Huyết áp kèm mạch: đánh giá mạch riêng, lấy mức nặng hơn.
        $heartRate = null;
        if ($validated['type'] === 'blood_pressure' && isset($validated['values']['heart_rate'])) {
            $heartRate = $this->evaluator->evaluate($patient->id, 'heart_rate', 'resting', ['value' => $validated['values']['heart_rate']], $patient->tenant_id);
        }

        $reading = Reading::create([
            'patient_id' => $patient->id,
            'type' => $validated['type'],
            'context' => $context,
            'measured_at' => $validated['measured_at'],
            'values' => $validated['values'],
            'evaluation' => json_encode([
                'level' => $evaluated['evaluation'],
                'heart_rate_level' => $heartRate['evaluation'] ?? null,
                'threshold_context' => $thresholdContext,
            ]),
            'recorded_by' => $request->user()?->id,
        ]);

        foreach (array_filter([[$validated['type'], $evaluated], $heartRate === null ? null : ['heart_rate', $heartRate]]) as [$metric, $result]) {
            if (($result['alert_level'] ?? null) === 'red') {
                $this->evaluator->saveAlertIfRed($patient->id, $metric, $thresholdContext, $result, $patient->tenant_id, $reading->id);
            }
        }

        return response()->json([
            'data' => $this->dayPlan->presentReading($reading),
            'alert' => collect([$evaluated, $heartRate])->filter()->firstWhere('alert_level', 'red')['alert_content'] ?? null,
        ], 201);
    }

    /**
     * GET /patients/{patient}/readings
     */
    public function readingsIndex(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $validated = $request->validate([
            'type' => ['nullable', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = Reading::where('patient_id', $patient->id);

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }
        if (! empty($validated['from'])) {
            $query->whereDate('measured_at', '>=', $validated['from']);
        }
        if (! empty($validated['to'])) {
            $query->whereDate('measured_at', '<=', $validated['to']);
        }

        return response()->json(['data' => $query->orderBy('measured_at')->get()->map(fn (Reading $r) => $this->dayPlan->presentReading($r))->values()]);
    }

    /**
     * GET /patients/{patient}/events
     */
    public function eventsIndex(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = Event::where('patient_id', $patient->id);

        if (! empty($validated['from'])) {
            $query->whereDate('event_date', '>=', $validated['from']);
        }
        if (! empty($validated['to'])) {
            $query->whereDate('event_date', '<=', $validated['to']);
        }

        return response()->json(['data' => $query->orderBy('event_date')->get()]);
    }

    /**
     * POST /patients/{patient}/events
     */
    public function storeEvent(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('updateFamilyLog', $patient);

        $validated = $request->validate([
            'event_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'type' => ['required', 'in:appointment,test,purchase,vaccination,other'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:pending,completed,cancelled'],
        ]);

        $event = Event::create([
            'patient_id' => $patient->id,
            'event_date' => $validated['event_date'],
            'due_date' => $validated['due_date'] ?? null,
            'type' => $validated['type'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? 'pending',
        ]);

        return response()->json(['data' => $event], 201);
    }

    /**
     * PATCH /patients/{patient}/events/{event} — đánh dấu mốc đã làm / chưa làm.
     */
    public function updateEvent(Request $request, Patient $patient, Event $event): JsonResponse
    {
        $this->authorize('updateFamilyLog', $patient);

        $validated = $request->validate([
            'status' => ['required', 'in:pending,completed,cancelled'],
        ]);
        $event->update(['status' => $validated['status']]);

        return response()->json(['data' => $event]);
    }
}
