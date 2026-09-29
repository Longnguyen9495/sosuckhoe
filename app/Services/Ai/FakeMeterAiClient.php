<?php

namespace App\Services\Ai;

use App\Contracts\MeterAiClient;

/** Máy đo giả lập cho phát triển và kiểm thử — luôn đọc ra đường huyết 7,2 mmol/L. */
final class FakeMeterAiClient implements MeterAiClient
{
    public function readMeter(string $binary, string $mime): array
    {
        return [
            'device' => 'blood_glucose',
            'readable' => true,
            'glucose' => ['value' => 7.2, 'unit' => 'mmol/L', 'flag' => null],
            'blood_pressure' => null,
            'memory_view' => false,
        ];
    }
}
