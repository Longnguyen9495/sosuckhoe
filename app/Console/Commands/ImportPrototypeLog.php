<?php

namespace App\Console\Commands;

use App\Models\Log;
use App\Models\Patient;
use App\Models\Reading;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use JsonException;

final class ImportPrototypeLog extends Command
{
    protected $signature = 'prototype:import-log
        {tenant : ULID tenant đích}
        {patient : ULID bệnh nhân đích}
        {--path= : Đường dẫn log.json; mặc định là prototype/data/log.json}';

    protected $description = 'Nhập nhật ký từ prototype cũ mà không nhập lại đánh giá ngưỡng';

    public function handle(TenantContext $context): int
    {
        $path = $this->option('path') ?: base_path('archive/prototype/data/log.json');
        if (! is_file($path)) {
            $this->error("Không tìm thấy file prototype: {$path}");

            return self::FAILURE;
        }

        try {
            $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('File JSON không hợp lệ: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! is_array($payload)) {
            $this->error('Dữ liệu gốc phải là một object JSON theo khóa ngày.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->find($this->argument('tenant'));
        $patient = Patient::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant?->getKey())
            ->find($this->argument('patient'));
        if ($tenant === null || $patient === null) {
            $this->error('Tenant hoặc bệnh nhân đích không tồn tại/không khớp nhau.');

            return self::FAILURE;
        }

        $context->set($tenant);
        $days = 0;
        $readings = 0;

        try {
            DB::transaction(function () use ($payload, $patient, &$days, &$readings): void {
                foreach ($payload as $date => $day) {
                    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) || ! is_array($day)) {
                        continue;
                    }

                    $measuredDate = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
                    $checks = is_array($day['chk'] ?? null) ? $day['chk'] : [];
                    $meta = [
                        'source' => 'prototype_log',
                        'checks' => array_keys(array_filter($checks)),
                        'water_cups' => max(0, (int) ($day['water'] ?? 0)),
                        'symptoms' => array_values(array_filter(is_array($day['sym'] ?? null) ? $day['sym'] : [], 'is_string')),
                    ];
                    if (is_string($day['note'] ?? null) && trim($day['note']) !== '') {
                        $meta['note'] = trim($day['note']);
                    }

                    $log = Log::query()
                        ->where('patient_id', $patient->getKey())
                        ->whereDate('log_date', $date)
                        ->whereNull('schedule_item_id')
                        ->first();
                    if ($log === null) {
                        Log::query()->create([
                            'patient_id' => $patient->getKey(),
                            'log_date' => $date,
                            'schedule_item_id' => null,
                            'completed' => $meta['checks'] !== [],
                            'meta' => $meta,
                        ]);
                    } else {
                        $log->update(['completed' => $meta['checks'] !== [], 'meta' => $meta]);
                    }
                    $days++;

                    foreach ((array) ($day['glu'] ?? []) as $context => $value) {
                        if (! is_numeric(str_replace(',', '.', (string) $value))) {
                            continue;
                        }
                        $this->upsertReading($patient, 'glucose', (string) $context, $measuredDate, [
                            'value' => (float) str_replace(',', '.', (string) $value),
                            'unit' => 'mmol/L',
                        ]);
                        $readings++;
                    }

                    foreach ((array) ($day['bp'] ?? []) as $context => $values) {
                        if (! is_array($values)) {
                            continue;
                        }
                        $normalized = array_filter([
                            'systolic' => $this->numeric($values['sys'] ?? null),
                            'diastolic' => $this->numeric($values['dia'] ?? null),
                            'heart_rate' => $this->numeric($values['hr'] ?? null),
                        ], static fn (mixed $value): bool => $value !== null);
                        if ($normalized === []) {
                            continue;
                        }
                        $normalized['unit'] = 'mmHg';
                        $this->upsertReading($patient, 'blood_pressure', (string) $context, $measuredDate, $normalized);
                        $readings++;
                    }
                }
            });
        } finally {
            $context->clear();
        }

        $this->info("Đã nhập {$days} ngày và {$readings} chỉ số; không nhập đánh giá ngưỡng từ prototype.");

        return self::SUCCESS;
    }

    private function upsertReading(Patient $patient, string $type, string $readingContext, Carbon $measuredAt, array $values): void
    {
        Reading::query()->updateOrCreate(
            [
                'patient_id' => $patient->getKey(),
                'type' => $type,
                'context' => $readingContext,
                'measured_at' => $measuredAt,
            ],
            ['values' => $values, 'evaluation' => null],
        );
    }

    private function numeric(mixed $value): int|float|null
    {
        $normalized = str_replace(',', '.', (string) $value);

        return is_numeric($normalized) ? $normalized + 0 : null;
    }
}
