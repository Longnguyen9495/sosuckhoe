<?php

namespace App\Services\Schedule;

use JsonException;

final class UsageRuleParser
{
    private const ANCHORS = ['wake', 'breakfast', 'lunch', 'dinner', 'sleep'];
    private const TYPES = ['medication', 'insulin', 'topical', 'measurement', 'activity', 'meal'];
    private const DAY_TYPES = ['daily', 'weekdays', 'interval'];

    public function parse(array|string|null $rule): array
    {
        if ($rule === null || $rule === []) {
            throw new UsageRuleException('Thiếu quy tắc cách dùng có cấu trúc.');
        }

        if (is_string($rule)) {
            try {
                $rule = json_decode($rule, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new UsageRuleException('Quy tắc cách dùng phải là JSON có cấu trúc.');
            }
            if (! is_array($rule)) {
                throw new UsageRuleException('Quy tắc cách dùng phải là JSON có cấu trúc.');
            }
        }

        if (! isset($rule['doses']) || ! is_array($rule['doses']) || $rule['doses'] === []) {
            throw new UsageRuleException('Thiếu danh sách doses trong quy tắc cách dùng.');
        }
        if (! isset($rule['type']) || ! in_array($rule['type'], self::TYPES, true)) {
            throw new UsageRuleException('Loại công việc không hợp lệ.');
        }
        if (! isset($rule['days']['type']) || ! in_array($rule['days']['type'], self::DAY_TYPES, true)) {
            throw new UsageRuleException('Quy tắc ngày không hợp lệ.');
        }

        foreach ($rule['doses'] as $index => $dose) {
            if (! is_array($dose)) {
                throw new UsageRuleException('Mỗi phần tử doses phải là một object JSON.');
            }
            $hasAnchor = isset($dose['anchor']) && is_string($dose['anchor']);
            $hasFixedTime = isset($dose['fixed_time']) && is_string($dose['fixed_time']);
            if ($hasAnchor === $hasFixedTime) {
                throw new UsageRuleException('Mỗi lần dùng phải có anchor hoặc fixed_time.');
            }
            if ($hasAnchor && ! in_array($dose['anchor'], self::ANCHORS, true)) {
                throw new UsageRuleException('Mốc sinh hoạt không hợp lệ tại doses.'.($index + 1).'.');
            }
            if ($hasFixedTime && ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $dose['fixed_time'])) {
                throw new UsageRuleException('Giờ cố định phải có dạng HH:MM tại doses.'.($index + 1).'.');
            }
            if (isset($dose['offset_min']) && ! is_int($dose['offset_min'])) {
                throw new UsageRuleException('offset_min phải là số nguyên tại doses.'.($index + 1).'.');
            }
            if (! isset($dose['amount_text']) || ! is_string($dose['amount_text']) || trim($dose['amount_text']) === '') {
                throw new UsageRuleException('Thiếu amount_text nguyên văn từ đơn.');
            }
        }

        if ($rule['days']['type'] === 'weekdays') {
            $weekdays = $rule['days']['weekdays'] ?? null;
            if (! is_array($weekdays) || $weekdays === [] || array_diff($weekdays, [1, 2, 3, 4, 5, 6, 7])) {
                throw new UsageRuleException('Danh sách thứ trong tuần không hợp lệ.');
            }
        }
        if ($rule['days']['type'] === 'interval' && (! isset($rule['days']['every_days']) || ! is_int($rule['days']['every_days']) || $rule['days']['every_days'] < 1)) {
            throw new UsageRuleException('Khoảng cách ngày không hợp lệ.');
        }

        return $rule;
    }
}
