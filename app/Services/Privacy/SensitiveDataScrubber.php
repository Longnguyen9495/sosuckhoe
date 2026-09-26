<?php

namespace App\Services\Privacy;

/**
 * Loại bỏ số CCCD / CMND / căn cước, mã thẻ BHYT, mã số BHXH, số hộ chiếu khỏi dữ liệu trước khi lưu CSDL.
 * Chạy trên MỌI kết quả AI (kể cả khi đã dặn AI không ghi) — lớp phòng thủ thứ hai.
 */
final class SensitiveDataScrubber
{
    public const MASK = '[đã ẩn]';

    /** Khoá JSON chứa dữ liệu định danh → bỏ hẳn. */
    private const BLOCKED_KEYS = '/^(cccd|cmnd|cmt|can_?cuoc|citizen.*|national.*id.*|id_?(card|number|no)|identity.*|personal_id.*|passport.*|ho_?chieu|bhyt.*|bhxh.*|insurance.*|health_insurance.*|social_insurance.*|ma_?(the|so)_?(bhyt|bhxh).*|so_?the.*)$/i';

    private const PATTERNS = [
        // Mã thẻ BHYT: 2 chữ + 13 số (có thể cách nhau), VD DN4797931234567 / DN 4 79 79 312 34567.
        '/\b[A-Z]{2}\s?\d(?:[\s.\-]?\d){12}\b/u',
        // Số CCCD / số định danh: 12 chữ số liền hoặc chia nhóm.
        '/(?<![\d])\d{3}[\s.\-]?\d{3}[\s.\-]?\d{3}[\s.\-]?\d{3}(?![\d])/u',
        // Số CMND cũ (9 số), mã BHXH (10 số), hộ chiếu — chỉ khi đứng sau từ khoá.
        '/((?:CCCD|CMND|CMT|căn\s*cước|định\s*danh|chứng\s*minh|hộ\s*chiếu|passport|BHYT|BHXH|bảo\s*hiểm(?:\s*y\s*tế|\s*xã\s*hội)?|mã\s*thẻ|số\s*thẻ|thẻ\s*số|mã\s*số\s*BH\w*)\s*(?:số|No\.?)?\s*[:#.\-]?\s*)[A-Z]{0,3}[\s.\-]?\d(?:[\s.\-]?\d){6,14}/iu',
    ];

    /** @return array{0: mixed, 1: int} dữ liệu đã lọc và số chỗ đã ẩn */
    public function scrub(mixed $data): array
    {
        $count = 0;
        $clean = $this->walk($data, $count);

        return [$clean, $count];
    }

    public function scrubString(string $text, int &$count = 0): string
    {
        foreach (self::PATTERNS as $i => $pattern) {
            $text = preg_replace_callback($pattern, function (array $m) use ($i, &$count) {
                $count++;

                // Mẫu có từ khoá: giữ nguyên từ khoá, chỉ ẩn phần số.
                return $i === 2 ? $m[1].self::MASK : self::MASK;
            }, $text) ?? $text;
        }

        return $text;
    }

    private function walk(mixed $value, int &$count): mixed
    {
        if (is_string($value)) {
            return $this->scrubString($value, $count);
        }
        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match(self::BLOCKED_KEYS, $key)) {
                $count++;

                continue;
            }
            $out[$key] = $this->walk($item, $count);
        }

        return $out;
    }
}
