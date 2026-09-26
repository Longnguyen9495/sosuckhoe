<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Onboarding\QuickProfileService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Đăng ký nhanh và đăng nhập bằng số điện thoại + mật khẩu.
 * OTP vẫn giữ ở OtpAuthController để bật lại sau.
 */
final class PasswordAuthController extends Controller
{
    private const PHONE_RULE = ['required', 'string', 'regex:/^(?:\+84|0)[0-9]{9}$/'];

    public function register(Request $request, QuickProfileService $profiles): JsonResponse
    {
        $request->merge(['phone' => $this->normalizePhone((string) $request->input('phone'))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120', 'regex:/\p{L}/u'],
            'phone' => self::PHONE_RULE,
            'birth_date' => ['required', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
            'accepted' => ['required', 'accepted'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ], [
            'name.regex' => 'Họ tên cần có chữ cái.',
            'accepted.accepted' => 'Cần đồng ý điều khoản xử lý dữ liệu sức khỏe để tạo hồ sơ.',
            'birth_date.before' => 'Ngày sinh phải trước hôm nay.',
        ]);

        if (User::query()->where('phone', $validated['phone'])->exists()) {
            return response()->json([
                'code' => 'PHONE_EXISTS',
                'message' => 'Số điện thoại này đã có hồ sơ. Vui lòng đăng nhập.',
            ], 409);
        }

        $result = $profiles->register(
            trim(preg_replace('/\s+/u', ' ', $validated['name'])),
            $validated['phone'],
            CarbonImmutable::parse($validated['birth_date']),
            $request->ip(),
        );

        $user = $result['user'];
        $token = $user->createToken($validated['device_name'] ?? 'web')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user_id' => $user->id,
                'user' => $this->presentUser($user),
                // Chỉ trả về đúng một lần để người dùng ghi lại.
                'default_password' => $result['default_password'],
                'tenant' => $result['tenant']->only(['id', 'name', 'type']),
                'patient' => [
                    'id' => $result['patient']->id,
                    'full_name' => $result['patient']->full_name,
                    'birth_year' => $result['patient']->birth_year,
                    'gender' => $result['patient']->gender,
                    'access_role' => 'patient',
                ],
            ],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->merge(['phone' => $this->normalizePhone((string) $request->input('phone'))]);
        $validated = $request->validate([
            'phone' => self::PHONE_RULE,
            'password' => ['required', 'string', 'max:200'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::query()->where('phone', $validated['phone'])->first();

        // Số không tồn tại vẫn so với một mã băm giả để thời gian phản hồi như nhau (không dò được SĐT).
        $hash = $user?->password ?? '$2y$12$C6UzMDM.H6dfI/f/IKcEeO5ZzXvY3aYQnFXmjPiVQ1bTiS2Qp1u1m';
        $ok = Hash::check($validated['password'], $hash) && $user?->password !== null;

        if (! $ok) {
            throw ValidationException::withMessages(['password' => 'Số điện thoại hoặc mật khẩu không đúng.']);
        }

        $token = $user->createToken($validated['device_name'] ?? 'web')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user_id' => $user->id,
                'user' => $this->presentUser($user),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presentUser($request->user())]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:200', 'confirmed', 'different:current_password'],
        ], [
            'password.min' => 'Mật khẩu mới cần ít nhất 8 ký tự.',
            'password.confirmed' => 'Nhập lại mật khẩu mới chưa khớp.',
            'password.different' => 'Mật khẩu mới phải khác mật khẩu hiện tại.',
        ]);

        $user = $request->user();
        if ($user->password === null || ! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Mật khẩu hiện tại không đúng.']);
        }

        $user->forceFill(['password' => $validated['password'], 'password_changed_at' => now()])->save();

        // Đăng xuất các thiết bị khác, giữ phiên hiện tại.
        $currentId = $user->currentAccessToken()?->id;
        $user->tokens()->when($currentId, fn ($q) => $q->where('id', '!=', $currentId))->delete();

        return response()->json(['message' => 'Đã đổi mật khẩu.']);
    }

    private function presentUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'birth_date' => $user->birth_date?->toDateString(),
            'uses_default_password' => $user->password !== null && $user->password_changed_at === null,
        ];
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[\s.\-]/', '', $phone) ?? '';

        return str_starts_with($phone, '+84') ? '0'.substr($phone, 3) : $phone;
    }
}
