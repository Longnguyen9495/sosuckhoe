<?php

namespace App\Services\Schedule;

use Carbon\CarbonImmutable;

final class ScheduleGenerator
{
    public function __construct(private readonly UsageRuleParser $parser)
    {
    }

    public function generate(array $prescriptionItems, array $routine, CarbonImmutable $effectiveFrom): array
    {
        $scheduleItems = [];
        $manualTimeItems = [];

        foreach ($prescriptionItems as $item) {
            try {
                $rule = $this->parser->parse($item['usage_rule'] ?? null);
            } catch (UsageRuleException $exception) {
                $manualTimeItems[] = [
                    'id' => $item['id'],
                    'drug_name_snapshot' => $item['drug_name_snapshot'],
                    'dose_text' => $item['dose_text'],
                    'reason' => $exception->getMessage(),
                ];
                continue;
            }

            foreach ($rule['doses'] as $dose) {
                $time = isset($dose['fixed_time'])
                    ? $dose['fixed_time']
                    : $this->timeFromAnchor($routine, $dose['anchor'], $dose['offset_min'] ?? 0);

                $scheduleItems[] = [
                    'prescription_item_id' => $item['id'],
                    'scheduled_time' => $time,
                    'type' => $rule['type'],
                    'title' => $item['drug_name_snapshot'],
                    'dose_text' => $item['dose_text'],
                    'amount_text' => $dose['amount_text'],
                    'day_rule' => $rule['days'],
                    'starts_at' => $item['starts_at'] ?? $effectiveFrom->toDateString(),
                    'ends_at' => $item['ends_at'] ?? null,
                ];
            }
        }

        usort($scheduleItems, static fn (array $left, array $right): int => [$left['scheduled_time'], $left['title']] <=> [$right['scheduled_time'], $right['title']]);

        return [
            'schedule_items' => $scheduleItems,
            'groups' => $this->groupByTime($scheduleItems),
            'manual_time_items' => $manualTimeItems,
        ];
    }

    private function timeFromAnchor(array $routine, string $anchor, int $offsetMinutes): string
    {
        if (! isset($routine[$anchor]) || ! is_string($routine[$anchor]) || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $routine[$anchor])) {
            throw new UsageRuleException('Thiếu giờ sinh hoạt cho mốc '.$anchor.'.');
        }

        return CarbonImmutable::createFromFormat('H:i', $routine[$anchor])
            ->addMinutes($offsetMinutes)
            ->format('H:i');
    }

    private function groupByTime(array $scheduleItems): array
    {
        $groups = [];
        foreach ($scheduleItems as $item) {
            $groups[$item['scheduled_time']] ??= [
                'scheduled_time' => $item['scheduled_time'],
                'items' => [],
            ];
            $groups[$item['scheduled_time']]['items'][] = $item;
        }

        return array_values($groups);
    }
}
