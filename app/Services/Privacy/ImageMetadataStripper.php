<?php

namespace App\Services\Privacy;

/**
 * Bỏ metadata (EXIF có toạ độ GPS, tên máy, giờ chụp; XMP; IPTC; chú thích) khỏi ảnh JPEG / PNG
 * mà không cần thư viện ảnh. Giao diện web đã vẽ lại ảnh qua canvas (tự bỏ metadata),
 * đây là lớp phòng thủ cho ảnh tải lên trực tiếp qua API.
 */
final class ImageMetadataStripper
{
    public function strip(string $binary, string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => $this->stripJpeg($binary),
            'image/png' => $this->stripPng($binary),
            default => $binary,
        };
    }

    private function stripJpeg(string $data): string
    {
        if (substr($data, 0, 2) !== "\xFF\xD8") {
            return $data;
        }
        $out = "\xFF\xD8";
        $pos = 2;
        $len = strlen($data);

        while ($pos + 4 <= $len && $data[$pos] === "\xFF") {
            $marker = ord($data[$pos + 1]);
            // Bắt đầu dữ liệu ảnh (SOS) — chép phần còn lại nguyên vẹn.
            if ($marker === 0xDA) {
                return $out.substr($data, $pos);
            }
            $segLen = unpack('n', substr($data, $pos + 2, 2))[1];
            if ($segLen < 2 || $pos + 2 + $segLen > $len) {
                return $data;
            }
            // APP1 (EXIF / XMP), APP13 (IPTC), COM (chú thích) → bỏ.
            $drop = $marker === 0xE1 || $marker === 0xED || $marker === 0xFE;
            if (! $drop) {
                $out .= substr($data, $pos, 2 + $segLen);
            }
            $pos += 2 + $segLen;
        }

        return $data;
    }

    private function stripPng(string $data): string
    {
        $signature = "\x89PNG\r\n\x1a\n";
        if (substr($data, 0, 8) !== $signature) {
            return $data;
        }
        $out = $signature;
        $pos = 8;
        $len = strlen($data);

        while ($pos + 12 <= $len) {
            $chunkLen = unpack('N', substr($data, $pos, 4))[1];
            $type = substr($data, $pos + 4, 4);
            $total = 12 + $chunkLen;
            if ($pos + $total > $len) {
                return $data;
            }
            if (! in_array($type, ['tEXt', 'zTXt', 'iTXt', 'eXIf', 'tIME'], true)) {
                $out .= substr($data, $pos, $total);
            }
            $pos += $total;
            if ($type === 'IEND') {
                break;
            }
        }

        return $out;
    }
}
