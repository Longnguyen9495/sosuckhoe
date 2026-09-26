<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Mật khẩu mặc định khi đăng ký nhanh = tên (bỏ dấu, bỏ khoảng trắng, chữ thường) + 4 số cuối SĐT.
 * VD: "Nguyễn Văn An" + 0912345678 → "nguyenvanan5678".
 * Phải cho cùng kết quả với defaultPassword() ở resources/js/views/auth.js.
 */
final class DefaultPassword
{
    /** Bảng bỏ dấu tiếng Việt đầy đủ — Str::ascii làm rơi một số chữ hai dấu (ể, ử, ẩ…). */
    private const VIETNAMESE = [
        'a' => 'àáạảãâầấậẩẫăằắặẳẵ', 'e' => 'èéẹẻẽêềếệểễ', 'i' => 'ìíịỉĩ', 'o' => 'òóọỏõôồốộổỗơờớợởỡ',
        'u' => 'ùúụủũưừứựửữ', 'y' => 'ỳýỵỷỹ', 'd' => 'đ',
        'A' => 'ÀÁẠẢÃÂẦẤẬẨẪĂẰẮẶẲẴ', 'E' => 'ÈÉẸẺẼÊỀẾỆỂỄ', 'I' => 'ÌÍỊỈĨ', 'O' => 'ÒÓỌỎÕÔỒỐỘỔỖƠỜỚỢỞỠ',
        'U' => 'ÙÚỤỦŨƯỪỨỰỬỮ', 'Y' => 'ỲÝỴỶỸ', 'D' => 'Đ',
    ];

    public static function for(string $name, string $phone): string
    {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]/', '', self::ascii($name)) ?? '');
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return $slug.substr($digits, -4);
    }

    public static function ascii(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
        }
        $map = [];
        foreach (self::VIETNAMESE as $base => $chars) {
            foreach (mb_str_split($chars) as $char) {
                $map[$char] = $base;
            }
        }
        $text = strtr($text, $map);
        // Dấu tổ hợp còn sót (chuỗi dạng NFD) → bỏ.
        $text = preg_replace('/\p{M}+/u', '', $text) ?? $text;

        return Str::ascii($text);
    }
}
