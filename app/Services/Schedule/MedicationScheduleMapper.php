<?php

namespace App\Services\Schedule;

/**
 * Chuyển lịch dùng AI đọc từ đơn (slot + trước/sau ăn) thành usage_rule mà UsageRuleParser hiểu.
 * Chỉ quyết định GIỜ nhắc; lượng mỗi lần giữ nguyên chữ trên đơn.
 */
final class MedicationScheduleMapper
{
    private const TYPES = ['medication', 'insulin', 'topical', 'supply'];

    private const SLOTS = ['wake', 'breakfast', 'lunch', 'dinner', 'sleep'];

    /** Lệch giờ so với mốc bữa ăn (phút). */
    private const MEAL_OFFSETS = ['before' => -30, 'with' => -5, 'after' => 30, 'none' => 0];

    public static function type(?string $type): string
    {
        return in_array($type, self::TYPES, true) ? $type : 'medication';
    }

    /** @return array<string, mixed>|null null khi không suy ra được giờ dùng nào */
    public static function toUsageRule(array $medication): ?array
    {
        $type = self::type($medication['type'] ?? null);
        if ($type === 'supply') {
            return ['type' => 'supply', 'days' => ['type' => 'daily'], 'doses' => []];
        }

        $doses = [];
        $defaultAmount = null;
        foreach (array_filter($medication['schedule'] ?? [], 'is_array') as $entry) {
            $slot = $entry['slot'] ?? null;
            if (! in_array($slot, self::SLOTS, true)) {
                continue;
            }
            $amount = self::amount($entry['amount_text'] ?? null, $type);
            $defaultAmount ??= $amount;
            $offset = match ($slot) {
                'wake' => 0,
                'sleep' => -30,
                default => self::MEAL_OFFSETS[$entry['relation'] ?? 'none'] ?? 0,
            };
            $key = $slot.':'.$offset;
            $doses[$key] = ['anchor' => $slot, 'offset_min' => $offset, 'amount_text' => $amount];
        }

        foreach (array_filter($medication['fixed_times'] ?? [], 'is_string') as $time) {
            if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
                $doses['fixed:'.$time] = ['fixed_time' => $time, 'amount_text' => $defaultAmount ?? self::amount(null, $type)];
            }
        }

        if ($doses === []) {
            return null;
        }

        return ['type' => $type, 'days' => ['type' => 'daily'], 'doses' => array_values($doses)];
    }

    private static function amount(mixed $text, string $type): string
    {
        $text = is_string($text) ? trim($text) : '';
        if ($text !== '') {
            return mb_substr($text, 0, 120);
        }

        return match ($type) {
            'topical' => 'Bôi theo đơn',
            'insulin' => 'Theo đơn',
            default => '1 lần',
        };
    }
}
