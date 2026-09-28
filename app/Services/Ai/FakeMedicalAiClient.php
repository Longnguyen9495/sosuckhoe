<?php

namespace App\Services\Ai;

use App\Contracts\MedicalAiClient;

/**
 * AI giả lập cho phát triển và kiểm thử — không gọi mạng.
 * Cố ý trả kèm số CCCD / BHYT giả để kiểm chứng bộ lọc dữ liệu định danh.
 */
final class FakeMedicalAiClient implements MedicalAiClient
{
    public function model(): string
    {
        return 'fake';
    }

    public function analyzeDocument(string $binary, string $mime): array
    {
        return [
            'document_type' => 'don',
            'title' => 'Đơn thuốc Nội tiết (mẫu)',
            'document_date' => now()->toDateString(),
            'facility' => 'Bệnh viện mẫu',
            'department' => 'Khoa Nội tiết',
            'doctor_name' => 'BS Nguyễn Văn Mẫu',
            'cccd' => '001234567890',
            'diagnoses' => [['name' => 'Đái tháo đường típ 2', 'icd_code' => 'E11']],
            'medications' => [
                [
                    'drug_name' => 'Metformin 500mg', 'active_ingredient' => 'Metformin', 'type' => 'medication',
                    'dose_text' => 'Sáng 1 viên, tối 1 viên, uống sau ăn', 'quantity' => 60, 'unit' => 'viên', 'duration_days' => 30,
                    'schedule' => [
                        ['slot' => 'breakfast', 'relation' => 'after', 'amount_text' => '1 viên'],
                        ['slot' => 'dinner', 'relation' => 'after', 'amount_text' => '1 viên'],
                    ],
                    'fixed_times' => [],
                ],
            ],
            'lab_results' => [
                ['group' => 'Sinh hóa máu', 'name' => 'HbA1c', 'value' => '8,5', 'unit' => '%', 'reference_range' => '4,8–5,9', 'flag' => 'H'],
            ],
            'findings' => ['Người bệnh đái tháo đường, số thẻ BHYT: DN4797931234567', 'CCCD 001 234 567 890'],
            'advice' => ['Tái khám sau 1 tháng'],
            'follow_up_date' => now()->addMonth()->toDateString(),
            'follow_up_note' => 'Tái khám Nội tiết, mang theo đơn cũ',
        ];
    }

    public function generateDailyMenu(array $context): array
    {
        $n = count($context['recent_menus'] ?? []);

        return [
            'breakfast' => 'Thứ Hai — Bún gạo lứt với thịt gà xé, nhiều rau (ngày '.($n + 1).')',
            'lunch' => 'Nửa bát cơm, cá thu sốt cà chua nhạt, rau muống luộc',
            'dinner' => 'Canh cải nấu tôm, đậu phụ luộc, nửa bát cơm',
            'snacks' => 'Nửa quả táo',
            'tip' => 'Ăn rau trước, cơm sau để đường huyết lên chậm hơn.',
        ];
    }

    public function generateGlucoseNote(array $context): array
    {
        $n = $context['today']['count'] ?? 0;

        return [
            'summary' => "Hôm nay đã đo {$n} lần (nhận xét mẫu).",
            'points' => [
                ['tone' => 'good', 'text' => 'Đo đều đặn giúp bác sĩ đánh giá chính xác hơn.'],
                ['tone' => 'warn', 'text' => 'Bữa trưa nên bớt tinh bột, ăn rau trước.'],
                // Lời khuyên đổi liều phải bị bộ lọc an toàn loại bỏ.
                ['tone' => 'info', 'text' => 'Có thể tăng liều insulin thêm 2 đơn vị.'],
            ],
            'ask_doctor' => 'Mục tiêu đường huyết sau ăn của tôi là bao nhiêu?',
        ];
    }

    public function generateCarePlan(array $context): array
    {
        return [
            'summary' => 'Kế hoạch mẫu dựa trên dữ liệu hiện có.',
            'key_issues' => [['title' => 'Đường huyết cao', 'detail' => 'HbA1c trên mức tham chiếu.', 'priority' => 'high', 'based_on' => 'HbA1c']],
            'diet' => [
                'principles' => ['Ăn đúng giờ, chia nhỏ bữa'],
                'eat_more' => ['Rau xanh'],
                'limit' => ['Cơm trắng, bánh mì trắng'],
                'avoid' => ['Nước ngọt có đường'],
                'drug_food_notes' => ['Metformin uống sau ăn để giảm khó chịu dạ dày'],
                'sample_day' => ['breakfast' => 'Cháo yến mạch + 1 quả trứng', 'lunch' => 'Nửa bát cơm gạo lứt, cá hấp, rau luộc', 'dinner' => 'Canh bí, thịt nạc luộc, rau cải', 'snacks' => '1 hộp sữa chua không đường'],
                'weekly_menu' => array_map(fn (string $b) => ['breakfast' => $b, 'lunch' => 'Nửa bát cơm gạo lứt, cá hấp, rau luộc', 'dinner' => 'Canh bí, thịt nạc luộc, rau cải', 'snacks' => '1 hộp sữa chua không đường'], [
                    'Cháo yến mạch + 1 quả trứng', 'Bánh mì nguyên cám + trứng ốp', 'Phở gà ít bánh', 'Xôi gấc nửa phần + sữa không đường',
                    'Bún cá nhiều rau', 'Cháo đậu xanh + trứng luộc', 'Miến gà',
                ]),
            ],
            'lifestyle' => ['Đi bộ 30 phút mỗi ngày sau ăn'],
            'exercises' => [
                ['id' => 'walk_after_meal', 'why' => 'Giúp đường huyết sau ăn tăng ít hơn (HbA1c cao).', 'frequency' => '15 phút sau ăn tối'],
                ['id' => 'khong_co_trong_thu_vien', 'why' => 'Bài AI tự bịa — phải bị loại.'],
            ],
            'monitoring' => [['what' => 'Đường huyết lúc đói', 'how_often' => 'Mỗi sáng', 'target' => '4,4–7,2 mmol/L (tham khảo)']],
            'medication_notes' => ['Uống Metformin sau ăn sáng và tối theo đơn'],
            'warning_signs' => ['Vã mồ hôi, run tay, lú lẫn — đo đường huyết ngay'],
            'follow_up' => ['Xét nghiệm lại HbA1c sau 3 tháng'],
            'questions_for_doctor' => ['Mục tiêu HbA1c của tôi là bao nhiêu?'],
        ];
    }
}
