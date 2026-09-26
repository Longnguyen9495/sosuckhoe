<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\OtpSender;
use App\Http\Controllers\Controller;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OtpAuthController extends Controller
{
    public function request(Request $request, OtpSender $sender): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:/^(?:\+84|0)[0-9]{9}$/'],
        ]);
        $phone = $this->normalizePhone($validated['phone']);
        $code = config('services.otp.driver') === 'fake'
            ? (string) config('services.otp.fake_code')
            : (string) random_int(100000, 999999);

        DB::transaction(function () use ($phone, $code, $sender): void {
            OtpCode::query()
                ->where('recipient', $phone)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            OtpCode::query()->create([
                'recipient' => $phone,
                'code_hash' => $this->hashCode($phone, $code),
                'expires_at' => now()->addMinutes((int) config('services.otp.expires_minutes')),
            ]);

            $sender->send($phone, $code);
        });

        return response()->json(['message' => 'Nếu số điện thoại hợp lệ, mã xác thực đã được gửi.']);
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:/^(?:\+84|0)[0-9]{9}$/'],
            'code' => ['required', 'digits:6'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);
        $phone = $this->normalizePhone($validated['phone']);

        $user = DB::transaction(function () use ($phone, $validated): ?User {
            $otp = OtpCode::query()
                ->where('recipient', $phone)
                ->whereNull('used_at')
                ->latest('created_at')
                ->lockForUpdate()
                ->first();

            if ($otp === null || $otp->expires_at->isPast() || $otp->attempts >= (int) config('services.otp.max_attempts')) {
                return null;
            }

            if (! hash_equals($otp->code_hash, $this->hashCode($phone, $validated['code']))) {
                $otp->increment('attempts');

                return null;
            }

            $otp->update(['used_at' => now()]);

            return User::query()->firstOrCreate(
                ['phone' => $phone],
                ['name' => 'Người dùng '.Str::mask($phone, '*', 3, 5), 'phone_verified_at' => now()],
            );
        });

        if ($user === null) {
            throw ValidationException::withMessages(['code' => 'Mã xác thực không hợp lệ hoặc đã hết hạn.']);
        }

        $token = $user->createToken($validated['device_name'] ?? 'web')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user_id' => $user->getKey(),
                'user' => $user->only(['id', 'name', 'phone']),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Đã đăng xuất.']);
    }

    private function normalizePhone(string $phone): string
    {
        return str_starts_with($phone, '+84') ? '0'.substr($phone, 3) : $phone;
    }

    private function hashCode(string $phone, string $code): string
    {
        return hash_hmac('sha256', $phone.'|'.$code, (string) config('app.key'));
    }
}
