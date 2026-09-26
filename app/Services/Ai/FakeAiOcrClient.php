<?php

namespace App\Services\Ai;

use App\Contracts\AiOcrClient;

/**
 * Client giả lập AI OCR — trả về dữ liệu mẫu cho mục đích phát triển.
 * Thay thế bằng client thật (OpenAI Vision, Google Vision, v.v.) trong production.
 */
final class FakeAiOcrClient implements AiOcrClient
{
    public function recognize(string $imageBase64): array
    {
        // Giả lập: trả về 2 dòng thuốc mẫu
        return [
            [
                'drug_name' => 'Metformin 500mg',
                'strength' => '500mg',
                'quantity' => '30 viên',
                'dosage_instructions' => 'Uống 1 viên sau ăn sáng và tối',
            ],
            [
                'drug_name' => 'Glimepiride 2mg',
                'strength' => '2mg',
                'quantity' => '15 viên',
                'dosage_instructions' => 'Uống 1 viên trước bữa sáng 30 phút',
            ],
        ];
    }
}
