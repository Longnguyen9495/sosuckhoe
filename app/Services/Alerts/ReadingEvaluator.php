<?php

namespace App\Services\Alerts;

use App\Models\PatientThreshold;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReadingEvaluator
{
    /**
     * @return array{evaluation: string, alert_level: string|null, alert_content: string|null}
     */
    public function evaluate(string $patientId, string $metric, string $context, array $values, ?string $tenantId = null): array
    {
        $tenantId ??= app(TenantContext::class)->requireId();
        $threshold = PatientThreshold::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('patient_id', $patientId)
            ->where('metric', $metric)
            ->where('context', $context)
            ->first();

        if ($threshold === null) {
            return ['evaluation' => 'unknown', 'alert_level' => null, 'alert_content' => null];
        }

        $ranges = is_string($threshold->ranges) ? json_decode($threshold->ranges, true) : $threshold->ranges;

        if ($metric === 'blood_pressure') {
            return $this->evaluateBloodPressure($values, $ranges);
        }

        return $this->evaluateNumeric($values['value'] ?? null, $ranges, $metric);
    }

    public function saveAlertIfRed(string $patientId, string $metric, string $context, array $evaluated, ?string $tenantId = null, ?string $readingId = null): void
    {
        if ($evaluated['alert_level'] !== 'red') {
            return;
        }

        $tenantId ??= app(TenantContext::class)->requireId();

        DB::table('alerts')->insert([
            'id' => \Illuminate\Support\Str::ulid(),
            'tenant_id' => $tenantId,
            'patient_id' => $patientId,
            'reading_id' => $readingId,
            'level' => 'red',
            'content' => $evaluated['alert_content'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function evaluateNumeric(?float $value, array $ranges, string $metric): array
    {
        if ($value === null) {
            return ['evaluation' => 'incomplete', 'alert_level' => null, 'alert_content' => null];
        }

        $evaluation = 'unknown';
        $alertLevel = null;
        $alertContent = null;

        if (isset($ranges['target']) && is_array($ranges['target']) && count($ranges['target']) === 2) {
            [$min, $max] = $ranges['target'];
            if ($value >= $min && $value <= $max) {
                $evaluation = 'good';
            }
        }

        if ($evaluation !== 'good' && isset($ranges['target_below']) && is_numeric($ranges['target_below'])) {
            if ($value < $ranges['target_below']) {
                $evaluation = 'good';
            }
        }

        if (isset($ranges['attention']) && is_array($ranges['attention'])) {
            foreach ($ranges['attention'] as $attentionRange) {
                if (is_array($attentionRange) && count($attentionRange) === 2) {
                    [$aMin, $aMax] = $attentionRange;
                    if ($value >= $aMin && $value <= $aMax) {
                        $evaluation = 'attention';
                        break;
                    }
                } elseif (is_numeric($attentionRange) && $value >= $attentionRange && $value < ($ranges['red']['above'] ?? INF)) {
                    $evaluation = 'attention';
                    break;
                }
            }
        }

        $red = $ranges['red'] ?? null;
        if ($red !== null) {
            if (isset($red['below']) && $value < $red['below']) {
                $evaluation = 'red';
                $alertLevel = 'red';
                $alertContent = $this->buildAlertContent($metric, 'dưới', $red['below'], $value, $ranges['critical_above'] ?? null);
            } elseif (isset($red['above']) && $value > $red['above']) {
                $evaluation = 'red';
                $alertLevel = 'red';
                $alertContent = $this->buildAlertContent($metric, 'trên', $red['above'], $value, $red['critical_above'] ?? null);
            }
        }

        if ($evaluation === 'unknown') {
            $evaluation = 'attention';
        }

        return ['evaluation' => $evaluation, 'alert_level' => $alertLevel, 'alert_content' => $alertContent];
    }

    private function evaluateBloodPressure(array $values, array $ranges): array
    {
        $systolic = $values['systolic'] ?? null;
        $diastolic = $values['diastolic'] ?? null;

        if ($systolic === null || $diastolic === null) {
            return ['evaluation' => 'incomplete', 'alert_level' => null, 'alert_content' => null];
        }

        $evaluation = 'unknown';
        $alertLevel = null;
        $alertContent = null;

        $targetBelow = $ranges['target_below'] ?? null;
        if (is_array($targetBelow)) {
            $sysTarget = $targetBelow['systolic'] ?? INF;
            $diaTarget = $targetBelow['diastolic'] ?? INF;
            if ($systolic < $sysTarget && $diastolic < $diaTarget) {
                $evaluation = 'good';
            }
        }

        $redAbove = $ranges['red_at_or_above'] ?? null;
        if (is_array($redAbove)) {
            $sysRed = $redAbove['systolic'] ?? INF;
            $diaRed = $redAbove['diastolic'] ?? INF;
            if ($systolic >= $sysRed || $diastolic >= $diaRed) {
                $evaluation = 'red';
                $alertLevel = 'red';
                $alertContent = sprintf(
                    '[NHÁP — CẦN DUYỆT] Huyết áp nguy hiểm: %d/%d mmHg. Ngưỡng đỏ từ %d/%d. Gọi 115 nếu có triệu chứng nghiêm trọng.',
                    $systolic, $diastolic, $sysRed, $diaRed
                );
            }
        }

        $redBelow = $ranges['red_below'] ?? null;
        if (is_array($redBelow) && $alertLevel === null) {
            $sysLow = $redBelow['systolic'] ?? -INF;
            $diaLow = $redBelow['diastolic'] ?? -INF;
            if ($systolic <= $sysLow || $diastolic <= $diaLow) {
                $evaluation = 'red';
                $alertLevel = 'red';
                $alertContent = sprintf(
                    '[NHÁP — CẦN DUYỆT] Huyết áp quá thấp: %d/%d mmHg. Ngưỡng đỏ dưới %d/%d. Đi khám ngay.',
                    $systolic, $diastolic, $sysLow, $diaLow
                );
            }
        }

        if ($evaluation === 'unknown') {
            $evaluation = 'attention';
        }

        return ['evaluation' => $evaluation, 'alert_level' => $alertLevel, 'alert_content' => $alertContent];
    }

    private function buildAlertContent(string $metric, string $direction, float $threshold, float $value, ?float $critical): string
    {
        [$metricName, $unit] = match ($metric) {
            'blood_glucose' => ['Đường huyết', 'mmol/L'],
            'heart_rate' => ['Mạch', 'lần/phút'],
            default => [$metric, ''],
        };
        $fmt = fn (float $n) => $metric === 'heart_rate' ? (string) (int) $n : str_replace('.', ',', rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.'));

        $message = sprintf('[NHÁP — CẦN DUYỆT] %s %s %s: %s %s.', $metricName, $fmt($value), $unit, $direction, $fmt($threshold).' '.$unit);
        if ($metric === 'blood_glucose' && $direction === 'dưới') {
            $message .= ' Nghi hạ đường huyết: ăn ngay 15 g đường nhanh, đo lại sau 15 phút. Lơ mơ, không tỉnh: gọi 115.';
        } elseif ($critical !== null && $value > $critical) {
            $message .= sprintf(' Rất cao (trên %s %s): gọi hotline hoặc đi khám ngay.', $fmt($critical), $unit);
        } else {
            $message .= ' Ghi lại và báo bác sĩ điều trị.';
        }

        return $message;
    }
}
