<?php

namespace App\Services\Schedule;

use App\Models\CarePlan;
use App\Models\Event;
use App\Models\Log;
use App\Models\MonitoringPlan;
use App\Models\Patient;
use App\Models\Reading;
use App\Models\ScheduleItem;
use Carbon\CarbonImmutable;

/**
 * Ghép toàn bộ việc của một ngày cho một bệnh nhân:
 * thuốc / insulin / bôi (schedule_items theo quy tắc ngày), lượt đo (monitoring_plans),
 * mốc bữa ăn (patient_routines), trạng thái đã làm (logs, readings) và mốc lịch (events).
 */
final class DayPlanBuilder
{
    private const MEALS = [
        'breakfast' => 'Ăn sáng',
        'lunch' => 'Ăn trưa',
        'dinner' => 'Ăn tối',
    ];

    public function __construct(private readonly PatientScheduleOrchestrator $orchestrator)
    {
    }

    public function build(Patient $patient, CarbonImmutable $day): array
    {
        $date = $day->toDateString();
        $routine = $this->orchestrator->activeRoutine($patient->id, $patient->tenant_id, $day);

        $logs = Log::where('patient_id', $patient->id)->whereDate('log_date', $date)->get();
        $logsByItem = $logs->whereNotNull('schedule_item_id')->keyBy('schedule_item_id');

        $readings = Reading::where('patient_id', $patient->id)
            ->whereDate('measured_at', $date)
            ->orderBy('measured_at')
            ->get();

        $items = [];

        foreach ($this->scheduleItemsOn($patient, $day) as $scheduleItem) {
            $log = $logsByItem->get($scheduleItem->id);
            $items[] = [
                'key' => 'si:'.$scheduleItem->id,
                'time' => substr((string) $scheduleItem->scheduled_time, 0, 5),
                'type' => $scheduleItem->type,
                'title' => $scheduleItem->title,
                'amount_text' => $scheduleItem->amount_text,
                'dose_text' => $scheduleItem->dose_text,
                'schedule_item_id' => $scheduleItem->id,
                'prescription_item_id' => $scheduleItem->prescription_item_id,
                'countable' => true,
                'done' => (bool) ($log?->completed),
                'completed_at' => $log?->completed_at?->toIso8601String(),
            ];
        }

        $plans = $this->monitoringPlansOn($patient, $day);
        if ($routine !== null) {
            foreach ($this->measurementPointsOn($plans, $day) as $point) {
                $definition = MeasurementPoints::POINTS[$point];
                $reading = $readings->first(fn (Reading $r) => $r->context === $point);
                $items[] = [
                    'key' => 'm:'.$point,
                    'time' => PatientScheduleOrchestrator::timeFromRoutine($routine, $definition['anchor'], $definition['offset']),
                    'type' => 'measurement',
                    'title' => $definition['label'],
                    'point' => $point,
                    'metric' => $definition['metric'],
                    'threshold_context' => $definition['threshold_context'],
                    'countable' => true,
                    'done' => $reading !== null,
                    'reading' => $reading === null ? null : $this->presentReading($reading),
                ];
            }

            // Gợi ý món từ kế hoạch chăm sóc gần nhất (nếu có).
            $sampleDay = CarePlan::where('patient_id', $patient->id)->latest('created_at')->first()?->content['diet']['sample_day'] ?? [];

            foreach (self::MEALS as $anchor => $label) {
                $items[] = [
                    'key' => 'meal:'.$anchor,
                    'time' => $routine[$anchor],
                    'type' => 'meal',
                    'title' => $label,
                    'diet_note' => $sampleDay[$anchor] ?? null,
                    'countable' => false,
                    'done' => false,
                ];
            }
        }

        usort($items, static function (array $a, array $b): int {
            $order = ['measurement' => 0, 'insulin' => 1, 'meal' => 2, 'medication' => 3, 'topical' => 4, 'activity' => 5];

            return [$a['time'], $order[$a['type']] ?? 9, $a['title']] <=> [$b['time'], $order[$b['type']] ?? 9, $b['title']];
        });

        $countable = array_filter($items, fn (array $item) => $item['countable']);
        $done = count(array_filter($countable, fn (array $item) => $item['done']));
        $total = count($countable);

        return [
            'date' => $date,
            'weekday' => $day->isoWeekday(),
            'routine' => $routine,
            'monitoring_phase' => $this->currentPhase($plans),
            'items' => array_values($items),
            'summary' => [
                'done' => $done,
                'total' => $total,
                // Không có việc nào thì không tính % (tránh hiện "100%" khi lịch trống).
                'percent' => $total === 0 ? null : (int) round($done * 100 / $total),
            ],
            'readings' => $readings->map(fn (Reading $r) => $this->presentReading($r))->values(),
            'day_log' => $this->dayLogMeta($logs),
            'events' => $this->eventsOn($patient, $day),
        ];
    }

    /** Tỷ lệ tuân thủ nhiều ngày (dùng cho tổng quan và cổng bác sĩ); null khi không có việc nào. */
    public function adherence(Patient $patient, CarbonImmutable $from, CarbonImmutable $to): ?int
    {
        $done = 0;
        $total = 0;
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $summary = $this->build($patient, $day)['summary'];
            $done += $summary['done'];
            $total += $summary['total'];
        }

        return $total === 0 ? null : (int) round($done * 100 / $total);
    }

    public function presentReading(Reading $reading): array
    {
        $evaluation = $reading->evaluation;
        if (is_string($evaluation)) {
            $evaluation = json_decode($evaluation, true) ?? $evaluation;
        }

        return [
            'id' => $reading->id,
            'type' => $reading->type,
            'context' => $reading->context,
            'measured_at' => $reading->measured_at?->toIso8601String(),
            'values' => $reading->values,
            'evaluation' => $evaluation,
        ];
    }

    /** @return \Illuminate\Support\Collection<int, ScheduleItem> */
    private function scheduleItemsOn(Patient $patient, CarbonImmutable $day)
    {
        $date = $day->toDateString();

        return ScheduleItem::where('patient_id', $patient->id)
            ->whereDate('starts_at', '<=', $date)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', $date))
            ->orderBy('scheduled_time')
            ->get()
            ->filter(fn (ScheduleItem $item) => $this->appliesOn($item, $day))
            ->values();
    }

    private function appliesOn(ScheduleItem $item, CarbonImmutable $day): bool
    {
        $rule = is_string($item->day_rule) ? json_decode($item->day_rule, true) : $item->day_rule;
        $type = $rule['type'] ?? 'daily';

        return match ($type) {
            'weekdays' => in_array($day->isoWeekday(), $rule['weekdays'] ?? [], true),
            'interval' => CarbonImmutable::parse($item->starts_at)->startOfDay()->diffInDays($day->startOfDay()) % max(1, (int) ($rule['every_days'] ?? 1)) === 0,
            default => true,
        };
    }

    /** @return \Illuminate\Support\Collection<int, MonitoringPlan> */
    private function monitoringPlansOn(Patient $patient, CarbonImmutable $day)
    {
        $date = $day->toDateString();

        return MonitoringPlan::where('patient_id', $patient->id)
            ->whereDate('starts_at', '<=', $date)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', $date))
            ->orderBy('starts_at')
            ->get();
    }

    private function measurementPointsOn($plans, CarbonImmutable $day): array
    {
        $points = [];
        foreach ($plans as $plan) {
            $schedule = is_array($plan->schedule) ? $plan->schedule : (json_decode((string) $plan->schedule, true) ?? []);
            $points = array_merge($points, MeasurementPoints::pointsForWeekday($schedule, $day->isoWeekday()));
        }

        return array_values(array_unique($points));
    }

    private function currentPhase($plans): ?array
    {
        $plan = $plans->firstWhere('metric', 'blood_glucose');
        if ($plan === null) {
            return null;
        }
        $schedule = is_array($plan->schedule) ? $plan->schedule : (json_decode((string) $plan->schedule, true) ?? []);

        return [
            'id' => $plan->id,
            'phase' => $plan->phase,
            'label' => $schedule['label'] ?? $plan->phase,
            'description' => $schedule['description'] ?? null,
            'starts_at' => $plan->starts_at?->toDateString(),
            'ends_at' => $plan->ends_at?->toDateString(),
        ];
    }

    private function dayLogMeta($logs): array
    {
        $dayLog = $logs->firstWhere('schedule_item_id', null);
        $meta = $dayLog?->meta ?? [];

        return [
            'water_cups' => (int) ($meta['water_cups'] ?? 0),
            'symptoms' => array_values($meta['symptoms'] ?? []),
            'note' => (string) ($meta['note'] ?? ''),
        ];
    }

    private function eventsOn(Patient $patient, CarbonImmutable $day): array
    {
        $date = $day->toDateString();

        // Nhóm điều kiện ngày trong một where() để không lọt sự kiện của bệnh nhân khác.
        return Event::where('patient_id', $patient->id)
            ->where(function ($q) use ($date): void {
                $q->whereDate('event_date', $date)
                    ->orWhere(fn ($q2) => $q2->whereDate('event_date', '<=', $date)->whereDate('due_date', '>=', $date));
            })
            ->orderBy('event_date')
            ->get(['id', 'event_date', 'due_date', 'type', 'title', 'description', 'status'])
            ->map(fn (Event $e) => [
                'id' => $e->id,
                'date' => substr((string) $e->event_date, 0, 10),
                'due_date' => $e->due_date === null ? null : substr((string) $e->due_date, 0, 10),
                'type' => $e->type,
                'title' => $e->title,
                'description' => $e->description,
                'status' => $e->status,
            ])
            ->all();
    }
}
