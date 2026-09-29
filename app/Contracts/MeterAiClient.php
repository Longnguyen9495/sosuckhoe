<?php

namespace App\Contracts;

interface MeterAiClient
{
    /**
     * Đọc ảnh màn hình máy đo đường huyết hoặc máy đo huyết áp — AI tự nhận ra loại máy.
     * Kết quả thô, CHƯA kiểm tra; bên gọi phải cho qua App\Services\Readings\MeterReadNormalizer.
     *
     * @return array<string, mixed> Cấu trúc mô tả trong App\Services\Ai\MedicalPrompts::METER_SCHEMA
     */
    public function readMeter(string $binary, string $mime): array;
}
