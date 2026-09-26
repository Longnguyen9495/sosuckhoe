<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Mật khẩu mặc định khi đăng ký nhanh = tên (bỏ dấu, bỏ khoảng trắng, chữ thường) + 4 số cuối SĐT.
 * VD: "Nguyễn Văn An" + 0912345678 → "nguyenvanan5678".
 */
final class DefaultPassword
{
    public static function for(string $name, string $phone): string
    {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($name)) ?? '');
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return $slug.substr($digits, -4);
    }
}
