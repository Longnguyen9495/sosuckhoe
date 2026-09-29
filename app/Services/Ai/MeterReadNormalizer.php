<?php

namespace App\Services\Ai;

/**
 * Kiểm tra số AI đọc từ ảnh máy đo trước khi đưa lên màn xác nhận: đổi mg/dL → mmol/L,
 * loại số ngoài dải máy đo được, nhận ra LO / HI và màn hình đang xem số cũ trong bộ nhớ.
 * Không lưu gì — người bệnh xác nhận rồi mới gửi lên /readings như gõ tay.
 */
final class MeterReadNormalizer
{
    /** Dải máy đường huyết cầm tay đo được (mmol/L); ngoài dải máy báo LO / HI. */
    private const GLUCOSE_MIN = 1.1;

    private const GLUCOSE_MAX = 33.3;

    private const MG_PER_MMOL = 18.0;

    /**
     * @param  array<string, mixed>  $raw
     * @return array{device: string|null, glucose: array{value: float, seen: string, converted: bool}|null, blood_pressure: array{systolic: int, diastolic: int, heart_rate: int|null}|null, flag: string|null, warnings: list<string>, message: string|null}
     */
    public static function normalize(array $raw): array
    {
        $out = ['device' => null, 'glucose' => null, 'blood_pressure' => null, 'flag' => null, 'warnings' => [], 'message' => null];
        $device = $raw['device'] ?? null;

        if ($device === 'blood_glucose') {
            $g = is_array($raw['glucose'] ?? null) ? $raw['glucose'] : [];
            $flag = strtoupper((string) ($g['flag'] ?? ''));
            if (in_array($flag, ['LO', 'HI'], true)) {
                $out['device'] = 'blood_glucose';
                $out['flag'] = $flag;
            } elseif (($glucose = self::glucose($g)) !== null) {
                $out['device'] = 'blood_glucose';
                $out['glucose'] = $glucose;
            }
        } elseif ($device === 'blood_pressure' && ($bp = self::bloodPressure(is_array($raw['blood_pressure'] ?? null) ? $raw['blood_pressure'] : [])) !== null) {
            $out['device'] = 'blood_pressure';
            $out['blood_pressure'] = $bp;
        }

        if ($out['device'] === null) {
            $out['message'] = in_array($device, ['blood_glucose', 'blood_pressure'], true)
                ? 'Chưa đọc rõ số trên máy. Chụp lại gần hơn, không bị lóa đèn, hoặc gõ số.'
                : 'Không thấy màn hình máy đo trong ảnh. Chụp thẳng vào màn hình số của máy, hoặc gõ số.';

            return $out;
        }

        if (! empty($raw['memory_view'])) {
            $out['warnings'][] = 'Máy có vẻ đang hiện số cũ trong bộ nhớ (MEM / trung bình). Nếu không phải số vừa đo, bấm “Chụp lại”.';
        }
        if ($out['glucose']['converted'] ?? false) {
            $out['warnings'][] = 'Máy đo theo mg/dL ('.$out['glucose']['seen'].'), app đã đổi sang mmol/L.';
        }

        return $out;
    }

    /** @param  array<string, mixed>  $g */
    private static function glucose(array $g): ?array
    {
        $value = self::number($g['value'] ?? null);
        if ($value === null || $value <= 0) {
            return null;
        }
        $unit = strtolower(str_replace(' ', '', (string) ($g['unit'] ?? '')));
        // mmol/L không bao giờ quá 33,3 — số lớn hơn là mg/dL dù AI ghi đơn vị gì.
        $mg = $unit === 'mg/dl' || $value > self::GLUCOSE_MAX;
        $mmol = $mg ? round($value / self::MG_PER_MMOL, 1) : round($value, 1);
        if ($mmol < self::GLUCOSE_MIN || $mmol > self::GLUCOSE_MAX) {
            return null;
        }
        $seen = $mg ? ((string) (int) round($value)).' mg/dL' : str_replace('.', ',', (string) $mmol).' mmol/L';

        return ['value' => $mmol, 'seen' => $seen, 'converted' => $mg];
    }

    /** @param  array<string, mixed>  $bp */
    private static function bloodPressure(array $bp): ?array
    {
        $sys = self::number($bp['systolic'] ?? null);
        $dia = self::number($bp['diastolic'] ?? null);
        if ($sys === null || $dia === null) {
            return null;
        }
        if ($dia > $sys) {
            [$sys, $dia] = [$dia, $sys];
        }
        $sys = (int) round($sys);
        $dia = (int) round($dia);
        if ($sys < 60 || $sys > 260 || $dia < 30 || $dia > 160 || $sys - $dia < 10) {
            return null;
        }
        $pulse = self::number($bp['pulse'] ?? null);
        $pulse = $pulse !== null && $pulse >= 30 && $pulse <= 220 ? (int) round($pulse) : null;

        return ['systolic' => $sys, 'diastolic' => $dia, 'heart_rate' => $pulse];
    }

    private static function number(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_string($v) && preg_match('/^\s*\d+([.,]\d+)?\s*$/', $v) === 1) {
            return (float) str_replace(',', '.', trim($v));
        }

        return null;
    }
}
