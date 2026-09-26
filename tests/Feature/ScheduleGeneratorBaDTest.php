<?php

namespace Tests\Feature;

use App\Services\Schedule\ScheduleGenerator;
use App\Services\Schedule\UsageRuleException;
use App\Services\Schedule\UsageRuleParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScheduleGeneratorBaDTest extends TestCase
{
    private array $routine = [
        'wake' => '06:00',
        'breakfast' => '07:00',
        'lunch' => '12:00',
        'dinner' => '18:30',
        'sleep' => '22:00',
    ];

    public function test_three_prescriptions_generate_every_medication_row_in_section_4_1(): void
    {
        $items = [
            $this->item('janumet', 'Janumet 50/850 mg', 'Sáng 1 viên, tối 1 viên — SAU ăn', [
                'doses' => [
                    ['anchor' => 'breakfast', 'offset_min' => 30, 'amount_text' => '1 viên'],
                    ['anchor' => 'dinner', 'offset_min' => 30, 'amount_text' => '1 viên'],
                ],
                'with_food' => 'after',
                'days' => ['type' => 'daily'],
                'type' => 'medication',
            ]),
            $this->item('novomix', 'NovoMix 30 FlexPen', 'Sáng 16 UI, tối 14 UI — tiêm dưới da NGAY TRƯỚC ăn 5 phút', [
                'doses' => [
                    ['anchor' => 'breakfast', 'offset_min' => -5, 'amount_text' => '16 UI'],
                    ['anchor' => 'dinner', 'offset_min' => -5, 'amount_text' => '14 UI'],
                ],
                'with_food' => 'before',
                'days' => ['type' => 'daily'],
                'type' => 'insulin',
            ]),
            $this->item('needle', 'Kim NovoFine 31G · 6 mm', 'Thay kim sau 1–2 lần tiêm', null),
            $this->item('esserose', 'Esserose 450 mg', 'Sáng 1 viên, tối 1 viên — sau ăn', $this->morningEveningRule()),
            $this->item('hepazid', 'Hepazid 25 mg', '1 viên/ngày sau ăn — ĐÚNG GIỜ, hằng ngày, KHÔNG tự bỏ thuốc', [
                'doses' => [['fixed_time' => '12:30', 'amount_text' => '1 viên']],
                'with_food' => 'after',
                'days' => ['type' => 'daily'],
                'type' => 'medication',
            ]),
            $this->item('livosil', 'Livosil 140 mg', 'Sáng 1 viên, tối 1 viên — sau ăn', $this->morningEveningRule()),
            $this->item('etiheso', 'Etiheso 40 mg', '1 viên buổi sáng, TRƯỚC ăn 1 giờ', [
                'doses' => [['anchor' => 'breakfast', 'offset_min' => -60, 'amount_text' => '1 viên']],
                'with_food' => 'before',
                'days' => ['type' => 'daily'],
                'type' => 'medication',
            ]),
            $this->item('celebrex', 'Celebrex 200 mg', '1 viên/ngày sau ăn sáng no', [
                'doses' => [['anchor' => 'breakfast', 'offset_min' => 30, 'amount_text' => '1 viên']],
                'with_food' => 'after',
                'days' => ['type' => 'daily'],
                'type' => 'medication',
            ]),
            $this->item('abricotis', 'Abricotis', 'Sáng 1 viên, trưa 1 viên — sau ăn', [
                'doses' => [
                    ['anchor' => 'breakfast', 'offset_min' => 30, 'amount_text' => '1 viên'],
                    ['anchor' => 'lunch', 'offset_min' => 30, 'amount_text' => '1 viên'],
                ],
                'with_food' => 'after',
                'days' => ['type' => 'daily'],
                'type' => 'medication',
            ]),
            $this->item('oztis', 'Oztis', 'Sáng 1 viên, tối 1 viên — sau ăn', $this->morningEveningRule()),
            $this->item('myopain', 'Myopain 50 mg', 'Sáng 1 viên, tối 1 viên — sau ăn', $this->morningEveningRule()),
            $this->item('gel', 'Gel Nociceptol 120 ml', 'Bôi vùng đau 3 lần/ngày', [
                'doses' => [
                    ['anchor' => 'breakfast', 'offset_min' => 30, 'amount_text' => 'Bôi theo đơn'],
                    ['anchor' => 'lunch', 'offset_min' => 30, 'amount_text' => 'Bôi theo đơn'],
                    ['anchor' => 'dinner', 'offset_min' => 30, 'amount_text' => 'Bôi theo đơn'],
                ],
                'days' => ['type' => 'daily'],
                'type' => 'topical',
            ]),
        ];

        $result = (new ScheduleGenerator(new UsageRuleParser()))->generate(
            $items,
            $this->routine,
            CarbonImmutable::parse('2026-09-27'),
        );

        $this->assertSame([
            '06:00' => ['Etiheso 40 mg'],
            '06:55' => ['NovoMix 30 FlexPen'],
            '07:30' => ['Abricotis', 'Celebrex 200 mg', 'Esserose 450 mg', 'Gel Nociceptol 120 ml', 'Janumet 50/850 mg', 'Livosil 140 mg', 'Myopain 50 mg', 'Oztis'],
            '12:30' => ['Abricotis', 'Gel Nociceptol 120 ml', 'Hepazid 25 mg'],
            '18:25' => ['NovoMix 30 FlexPen'],
            '19:00' => ['Esserose 450 mg', 'Gel Nociceptol 120 ml', 'Janumet 50/850 mg', 'Livosil 140 mg', 'Myopain 50 mg', 'Oztis'],
        ], $this->namesGroupedByTime($result['schedule_items']));

        $this->assertSame(['needle'], array_column($result['manual_time_items'], 'id'));
        foreach ($result['schedule_items'] as $generated) {
            $source = collect($items)->firstWhere('id', $generated['prescription_item_id']);
            $this->assertSame($source['dose_text'], $generated['dose_text']);
        }
    }

    #[DataProvider('invalidRuleProvider')]
    public function test_parser_rejects_missing_or_invalid_structured_json(array|string|null $rule, string $message): void
    {
        $this->expectException(UsageRuleException::class);
        $this->expectExceptionMessage($message);

        (new UsageRuleParser())->parse($rule);
    }

    public static function invalidRuleProvider(): array
    {
        return [
            'missing rule' => [null, 'Thiếu quy tắc cách dùng có cấu trúc.'],
            'plain text is forbidden' => ['Sáng 1 viên, tối 1 viên', 'Quy tắc cách dùng phải là JSON có cấu trúc.'],
            'missing anchor and fixed time' => [[
                'doses' => [['offset_min' => 30, 'amount_text' => '1 viên']],
                'days' => ['type' => 'daily'],
                'type' => 'medication',
            ], 'Mỗi lần dùng phải có anchor hoặc fixed_time.'],
            'invalid amount unit' => [[
                'doses' => [['anchor' => 'breakfast', 'offset_min' => 30, 'amount' => 1]],
                'days' => ['type' => 'daily'],
                'type' => 'medication',
            ], 'Thiếu amount_text nguyên văn từ đơn.'],
        ];
    }

    private function item(string $id, string $name, string $doseText, ?array $usageRule): array
    {
        return [
            'id' => $id,
            'drug_name_snapshot' => $name,
            'dose_text' => $doseText,
            'usage_rule' => $usageRule,
            'starts_at' => '2026-09-27',
            'ends_at' => null,
        ];
    }

    private function morningEveningRule(): array
    {
        return [
            'doses' => [
                ['anchor' => 'breakfast', 'offset_min' => 30, 'amount_text' => '1 viên'],
                ['anchor' => 'dinner', 'offset_min' => 30, 'amount_text' => '1 viên'],
            ],
            'with_food' => 'after',
            'days' => ['type' => 'daily'],
            'type' => 'medication',
        ];
    }

    private function namesGroupedByTime(array $scheduleItems): array
    {
        $grouped = [];
        foreach ($scheduleItems as $item) {
            $grouped[$item['scheduled_time']][] = $item['title'];
        }
        ksort($grouped);
        foreach ($grouped as &$names) {
            sort($names);
        }

        return $grouped;
    }
}
