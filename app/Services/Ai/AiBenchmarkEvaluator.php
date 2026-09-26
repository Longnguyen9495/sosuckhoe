<?php

namespace App\Services\Ai;

use App\Contracts\AiOcrClient;

/**
 * Đánh giá độ chính xác AI OCR dựa trên bộ dữ liệu mẫu.
 * Mục tiêu: ≥ 95% dòng thuốc đúng.
 */
final class AiBenchmarkEvaluator
{
    public function __construct(private AiOcrClient $client)
    {
    }

    /**
     * @return array{total_lines: int, correct_lines: int, accuracy_percent: float, details: array}
     */
    public function evaluate(array $dataset): array
    {
        $totalLines = 0;
        $correctLines = 0;
        $details = [];

        foreach ($dataset as $prescription) {
            $expectedLines = $prescription['expected_lines'];
            $imagePath = $prescription['source_image'] ?? null;

            if ($imagePath && file_exists($imagePath)) {
                $imageBase64 = base64_encode(file_get_contents($imagePath));
            } else {
                // Giả lập: dùng text rỗng, FakeAiOcrClient sẽ trả về mẫu
                $imageBase64 = '';
            }

            $result = $this->client->recognize($imageBase64);

            $prescriptionCorrect = 0;
            foreach ($expectedLines as $index => $expected) {
                $totalLines++;
                $actual = $result[$index] ?? null;

                $isCorrect = $this->isLineCorrect($expected, $actual);
                if ($isCorrect) {
                    $correctLines++;
                    $prescriptionCorrect++;
                }

                $details[] = [
                    'prescription_id' => $prescription['id'],
                    'line_index' => $index,
                    'expected' => $expected,
                    'actual' => $actual,
                    'correct' => $isCorrect,
                ];
            }
        }

        $accuracy = $totalLines > 0 ? round(($correctLines / $totalLines) * 100, 2) : 0;

        return [
            'total_lines' => $totalLines,
            'correct_lines' => $correctLines,
            'accuracy_percent' => $accuracy,
            'details' => $details,
        ];
    }

    private function isLineCorrect(array $expected, ?array $actual): bool
    {
        if (! $actual) {
            return false;
        }

        // So sánh lỏng lẻo: tên thuốc phải khớp (không phân biệt hoa thường)
        $expectedName = mb_strtolower($expected['drug_name'], 'UTF-8');
        $actualName = mb_strtolower($actual['drug_name'] ?? '', 'UTF-8');

        // Khớp chính xác hoặc chứa
        if ($expectedName !== $actualName && ! str_contains($actualName, $expectedName) && ! str_contains($expectedName, $actualName)) {
            return false;
        }

        // Strength có thể khác định dạng, nên chỉ kiểm tra nếu có
        $expectedStrength = $expected['strength'] ?? null;
        $actualStrength = $actual['strength'] ?? null;
        if ($expectedStrength && $actualStrength) {
            if (! str_contains(mb_strtolower($actualStrength), mb_strtolower($expectedStrength))) {
                return false;
            }
        }

        return true;
    }
}
