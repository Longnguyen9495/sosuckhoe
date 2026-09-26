<?php

namespace App\Services\Schedule;

use App\Models\MonitoringPlan;
use App\Models\Patient;
use App\Models\PatientRoutine;
use App\Models\PrescriptionItem;
use App\Models\ScheduleItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sinh lại schedule_items của một bệnh nhân từ các đơn đang dùng và giờ sinh hoạt.
 * Chỉ quyết định GIỜ; lượng dùng lấy nguyên văn từ đơn (amount_text / dose_text).
 */
final class PatientScheduleOrchestrator
{
    public function __construct(private readonly UsageRuleParser $parser)
    {
    }

    /**
     * Đóng các lịch đang hiệu lực từ $fromDate và tạo lịch mới từ $fromDate.
     * Lịch sử trước $fromDate không bị sửa.
     *
     * @return array{created:int, manual_items:array<int, array<string, mixed>>}
     */
    public function regenerateSchedule(
        string $patientId,
        CarbonImmutable $fromDate,
        ?string $tenantId = null,
    ): array {
        $tenantId ??= app(\App\Support\TenantContext::class)->requireId();
        $routine = $this->activeRoutine($patientId, $tenantId, $fromDate);
        if ($routine === null) {
            return ['created' => 0, 'manual_items' => []];
        }

        $rows = [];
        $manualItems = [];

        foreach ($this->activePrescriptionItems($patientId, $tenantId, $fromDate) as $item) {
            try {
                $rule = $this->parser->parse($item['usage_rule']);
            } catch (UsageRuleException $exception) {
                $manualItems[] = [
                    'id' => $item['id'],
                    'drug_name_snapshot' => $item['drug_name_snapshot'],
                    'dose_text' => $item['dose_text'],
                    'reason' => $exception->getMessage(),
                ];

                continue;
            }

            $startsAt = $item['starts_at'] !== null && $item['starts_at'] > $fromDate->toDateString()
                ? $item['starts_at']
                : $fromDate->toDateString();

            foreach ($rule['doses'] as $dose) {
                $rows[] = [
                    'patient_id' => $patientId,
                    'prescription_item_id' => $item['id'],
                    'scheduled_time' => isset($dose['fixed_time'])
                        ? $dose['fixed_time']
                        : self::timeFromRoutine($routine, $dose['anchor'] ?? null, $dose['offset_min'] ?? 0),
                    'type' => $rule['type'],
                    'title' => $item['drug_name_snapshot'],
                    'dose_text' => $item['dose_text'],
                    'amount_text' => $dose['amount_text'],
                    'day_rule' => json_encode($rule['days'], JSON_UNESCAPED_UNICODE),
                    'starts_at' => $startsAt,
                    'ends_at' => $item['ends_at'],
                ];
            }
        }

        DB::transaction(function () use ($patientId, $tenantId, $fromDate, $rows): void {
            // Lịch bắt đầu từ $fromDate trở đi bị thay hoàn toàn.
            ScheduleItem::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('patient_id', $patientId)
                ->whereDate('starts_at', '>=', $fromDate->toDateString())
                ->delete();

            // Lịch đang chạy từ trước thì kết thúc vào hôm trước $fromDate.
            ScheduleItem::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('patient_id', $patientId)
                ->whereDate('starts_at', '<', $fromDate->toDateString())
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhereDate('ends_at', '>=', $fromDate->toDateString()))
                ->update(['ends_at' => $fromDate->subDay()->toDateString()]);

            foreach ($rows as $row) {
                ScheduleItem::create($row);
            }
        });

        return ['created' => count($rows), 'manual_items' => $manualItems];
    }

    public function regenerateFromTomorrow(Patient $patient): array
    {
        return $this->regenerateSchedule($patient->id, CarbonImmutable::tomorrow(), $patient->tenant_id);
    }

    public function seedMonitoringPlans(string $patientId, array $phases, ?string $tenantId = null): void
    {
        $tenantId ??= app(\App\Support\TenantContext::class)->requireId();

        DB::transaction(function () use ($patientId, $phases, $tenantId): void {
            MonitoringPlan::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('patient_id', $patientId)
                ->delete();
            foreach ($phases as $phase) {
                MonitoringPlan::create([
                    'patient_id' => $patientId,
                    'metric' => $phase['metric'],
                    'phase' => $phase['phase'],
                    'starts_at' => $phase['starts_at'],
                    'ends_at' => $phase['ends_at'] ?? null,
                    'schedule' => $phase['schedule'],
                ]);
            }
        });
    }

    /**
     * Giờ sinh hoạt đang hiệu lực, dạng ['breakfast' => 'HH:MM', ...].
     */
    public function activeRoutine(string $patientId, string $tenantId, ?CarbonImmutable $onDate = null): ?array
    {
        $onDate ??= CarbonImmutable::today();
        $query = PatientRoutine::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('patient_id', $patientId);

        $routine = (clone $query)->whereDate('effective_from', '<=', $onDate->toDateString())->orderByDesc('effective_from')->first()
            ?? $query->orderBy('effective_from')->first();

        if ($routine === null) {
            return null;
        }

        return [
            'wake' => self::hhmm($routine->wake_time),
            'breakfast' => self::hhmm($routine->breakfast_time),
            'lunch' => self::hhmm($routine->lunch_time),
            'dinner' => self::hhmm($routine->dinner_time),
            'sleep' => self::hhmm($routine->sleep_time),
        ];
    }

    public static function timeFromRoutine(array $routine, ?string $anchor, int $offsetMinutes): string
    {
        if ($anchor === null || ! isset($routine[$anchor])) {
            throw new UsageRuleException('Thiếu giờ sinh hoạt cho mốc '.($anchor ?? '(không xác định)').'.');
        }

        return CarbonImmutable::createFromFormat('H:i', self::hhmm($routine[$anchor]))
            ->addMinutes($offsetMinutes)
            ->format('H:i');
    }

    /** MariaDB trả TIME dạng "07:00:00"; bộ sinh lịch dùng "07:00". */
    public static function hhmm(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }

    private function activePrescriptionItems(string $patientId, string $tenantId, CarbonImmutable $fromDate): array
    {
        return PrescriptionItem::withoutGlobalScope('tenant')
            ->join('prescriptions', function ($join) use ($tenantId): void {
                $join->on('prescriptions.id', '=', 'prescription_items.prescription_id')
                    ->where('prescriptions.tenant_id', '=', $tenantId);
            })
            ->where('prescription_items.tenant_id', $tenantId)
            ->where('prescription_items.patient_id', $patientId)
            ->where('prescriptions.status', 'active')
            ->where(fn ($query) => $query->whereNull('prescriptions.ends_at')->orWhereDate('prescriptions.ends_at', '>=', $fromDate->toDateString()))
            ->get([
                'prescription_items.id',
                'prescription_items.drug_name_snapshot',
                'prescription_items.dose_text',
                'prescription_items.usage_rule',
                'prescriptions.starts_at',
                'prescriptions.ends_at',
            ])
            ->map(fn ($row) => [
                'id' => $row->id,
                'drug_name_snapshot' => $row->drug_name_snapshot,
                'dose_text' => $row->dose_text,
                'usage_rule' => $row->usage_rule,
                'starts_at' => $row->starts_at === null ? null : substr((string) $row->starts_at, 0, 10),
                'ends_at' => $row->ends_at === null ? null : substr((string) $row->ends_at, 0, 10),
            ])
            ->all();
    }
}
