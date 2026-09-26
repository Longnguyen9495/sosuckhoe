<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TwoFactor\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TwoFactorAuthController extends Controller
{
    public function __construct(private TotpService $totp)
    {
    }

    /**
     * Bước 1: Tạo secret mới và trả về QR code SVG.
     * Chỉ dành cho người dùng chưa có 2FA.
     */
    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->two_factor_secret !== null) {
            return response()->json(['message' => '2FA đã được thiết lập.'], 422);
        }

        $secret = $this->totp->generateSecret();
        $this->totp->storeSecret($user, $secret);

        $svg = $this->totp->qrCodeSvg(
            $secret,
            $user->email ?? $user->phone ?? 'user'
        );

        return response()->json([
            'secret' => $secret,
            'qr_svg' => $svg,
        ]);
    }

    /**
     * Bước 2: Xác nhận mã TOTP để kích hoạt 2FA.
     * Trả về danh sách recovery codes (chỉ một lần).
     */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $user = $request->user();

        if ($user->two_factor_secret === null) {
            return response()->json(['message' => 'Chưa bắt đầu thiết lập 2FA.'], 422);
        }

        if ($user->two_factor_confirmed_at !== null) {
            return response()->json(['message' => '2FA đã được kích hoạt.'], 422);
        }

        if (! $this->totp->confirm($user, (string) $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => 'Mã xác thực không hợp lệ.',
            ]);
        }

        $codes = $this->totp->decryptRecoveryCodes($user->two_factor_recovery_codes);

        return response()->json([
            'message' => '2FA đã được kích hoạt.',
            'recovery_codes' => $codes,
        ]);
    }

    /**
     * Challenge: Kiểm tra mã TOTP và cấp token có ability `2fa-passed`.
     */
    public function challenge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        if ($user->two_factor_confirmed_at === null) {
            return response()->json(['message' => '2FA chưa được kích hoạt.'], 422);
        }

        $code = (string) $validated['code'];

        // Thử mã TOTP trước
        if ($this->totp->validateChallenge($user, $code)) {
            $token = $user->createToken(
                $request->input('device_name', 'web'),
                ['2fa-passed']
            )->plainTextToken;

            return response()->json([
                'message' => 'Xác thực hai yếu tố thành công.',
                'token' => $token,
            ]);
        }

        // Thử recovery code
        if ($this->totp->validateRecoveryCode($user, $code)) {
            $token = $user->createToken(
                $request->input('device_name', 'web'),
                ['2fa-passed']
            )->plainTextToken;

            return response()->json([
                'message' => 'Xác thực bằng mã khôi phục thành công.',
                'token' => $token,
            ]);
        }

        throw ValidationException::withMessages([
            'code' => 'Mã xác thực không hợp lệ.',
        ]);
    }

    /**
     * Tạo lại recovery codes (yêu cầu xác nhận lại bằng TOTP).
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $user = $request->user();

        if ($user->two_factor_confirmed_at === null) {
            return response()->json(['message' => '2FA chưa được kích hoạt.'], 422);
        }

        if (! $this->totp->validateChallenge($user, (string) $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => 'Mã xác thực không hợp lệ.',
            ]);
        }

        $codes = $this->totp->generateRecoveryCodes();
        $user->forceFill([
            'two_factor_recovery_codes' => $this->totp->encryptRecoveryCodes($codes),
        ])->save();

        return response()->json([
            'recovery_codes' => $codes,
        ]);
    }
}
