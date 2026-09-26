<?php

namespace App\Services\Schedule;

/**
 * Các thời điểm đo tại nhà, gắn với mốc sinh hoạt (PLAN.md mục 4.1–4.2).
 * `threshold_context` là ngữ cảnh dùng để tra patient_thresholds.
 */
final class MeasurementPoints
{
    public const POINTS = [
        'fasting' => ['anchor' => 'breakfast', 'offset' => -15, 'metric' => 'blood_glucose', 'threshold_context' => 'pre_meal', 'label' => 'Đường huyết lúc đói (trước ăn sáng)', 'short' => 'Lúc đói'],
        'post_breakfast' => ['anchor' => 'breakfast', 'offset' => 120, 'metric' => 'blood_glucose', 'threshold_context' => 'post_meal_2h', 'label' => 'Đường huyết 2 giờ sau ăn sáng', 'short' => '2h sau sáng'],
        'pre_lunch' => ['anchor' => 'lunch', 'offset' => -10, 'metric' => 'blood_glucose', 'threshold_context' => 'pre_meal', 'label' => 'Đường huyết trước ăn trưa', 'short' => 'Trước trưa'],
        'post_lunch' => ['anchor' => 'lunch', 'offset' => 120, 'metric' => 'blood_glucose', 'threshold_context' => 'post_meal_2h', 'label' => 'Đường huyết 2 giờ sau ăn trưa', 'short' => '2h sau trưa'],
        'pre_dinner' => ['anchor' => 'dinner', 'offset' => -15, 'metric' => 'blood_glucose', 'threshold_context' => 'pre_meal', 'label' => 'Đường huyết trước ăn tối', 'short' => 'Trước tối'],
        'post_dinner' => ['anchor' => 'dinner', 'offset' => 120, 'metric' => 'blood_glucose', 'threshold_context' => 'post_meal_2h', 'label' => 'Đường huyết 2 giờ sau ăn tối', 'short' => '2h sau tối'],
        'bedtime' => ['anchor' => 'sleep', 'offset' => -30, 'metric' => 'blood_glucose', 'threshold_context' => 'pre_meal', 'label' => 'Đường huyết trước khi ngủ', 'short' => 'Trước ngủ'],
        'bp_morning' => ['anchor' => 'breakfast', 'offset' => -10, 'metric' => 'blood_pressure', 'threshold_context' => 'general', 'label' => 'Huyết áp + mạch buổi sáng', 'short' => 'HA sáng'],
        'bp_evening' => ['anchor' => 'sleep', 'offset' => -60, 'metric' => 'blood_pressure', 'threshold_context' => 'general', 'label' => 'Huyết áp + mạch buổi tối', 'short' => 'HA tối'],
    ];

    public static function exists(string $point): bool
    {
        return isset(self::POINTS[$point]);
    }

    /**
     * Ngữ cảnh ngưỡng cho một lần đo. Chấp nhận cả mã điểm đo ("fasting")
     * lẫn ngữ cảnh ngưỡng trực tiếp ("pre_meal") để tương thích dữ liệu cũ.
     */
    public static function thresholdContext(string $metric, ?string $context): string
    {
        if ($context !== null && isset(self::POINTS[$context])) {
            return self::POINTS[$context]['threshold_context'];
        }
        if ($metric === 'heart_rate') {
            return 'resting';
        }

        return $context ?? 'general';
    }

    /** Danh sách điểm đo áp dụng cho một thứ trong tuần (1 = Thứ hai … 7 = Chủ nhật). */
    public static function pointsForWeekday(array $schedule, int $isoWeekday): array
    {
        $points = array_merge(
            $schedule['daily'] ?? [],
            $schedule['weekdays'][(string) $isoWeekday] ?? $schedule['weekdays'][$isoWeekday] ?? [],
        );

        return array_values(array_unique(array_filter($points, [self::class, 'exists'])));
    }
}
