<?php

namespace App\Contracts;

interface AiOcrClient
{
    /**
     * Gửi ảnh đơn thuốc (base64 hoặc URL) và nhận về danh sách dòng thuốc.
     *
     * @return array<string, mixed> Mảng các dòng, mỗi dòng có:
     *   - drug_name: string
     *   - strength: string|null
     *   - quantity: string|null
     *   - dosage_instructions: string|null
     */
    public function recognize(string $imageBase64): array;
}
