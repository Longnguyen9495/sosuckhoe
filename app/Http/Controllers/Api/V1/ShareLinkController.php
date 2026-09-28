<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\ShareLink;
use App\Services\Share\ShareLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Người bệnh tạo / xem / thu hồi link chia sẻ hồ sơ chỉ xem. */
final class ShareLinkController extends Controller
{
    private const MAX_ACTIVE = 10;

    public function __construct(private readonly ShareLinkService $service) {}

    public function index(Patient $patient): JsonResponse
    {
        Gate::authorize('view', $patient);

        $links = ShareLink::where('patient_id', $patient->id)->with(['views' => fn ($q) => $q->orderByDesc('viewed_at')->limit(10)])
            ->orderByDesc('created_at')->limit(30)->get();

        return response()->json(['data' => $links->map(fn (ShareLink $l) => $this->present($l))->values()]);
    }

    /** PIN ngẫu nhiên gợi ý sẵn (người bệnh có thể sửa). */
    public function suggestPin(Request $request, Patient $patient): JsonResponse
    {
        Gate::authorize('updateFamilyLog', $patient);

        return response()->json(['pin' => ShareLinkService::suggestPin($patient, $request->user())]);
    }

    public function store(Request $request, Patient $patient): JsonResponse
    {
        Gate::authorize('updateFamilyLog', $patient);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'expires_in_days' => ['required', 'integer', Rule::in(ShareLinkService::EXPIRY_DAYS)],
            'pin' => ['nullable', 'string'],
            'show_full_name' => ['boolean'],
            'include_documents' => ['boolean'],
            'consent' => ['accepted'],
        ], [
            'consent.accepted' => 'Cần đồng ý chia sẻ dữ liệu sức khỏe cho người có link.',
        ]);
        if (($validated['pin'] ?? '') !== '' && ($reason = ShareLinkService::weakPinReason($validated['pin'], $patient, $request->user())) !== null) {
            throw ValidationException::withMessages(['pin' => $reason]);
        }
        $active = ShareLink::where('patient_id', $patient->id)->whereNull('revoked_at')->where('expires_at', '>', now())->count();
        if ($active >= self::MAX_ACTIVE) {
            throw ValidationException::withMessages(['label' => 'Đã có '.self::MAX_ACTIVE.' link đang hoạt động. Thu hồi bớt link cũ trước khi tạo link mới.']);
        }

        $created = $this->service->create($patient, $request->user(), $validated);

        // Mã link và PIN chỉ trả về MỘT lần này — máy chủ không lưu bản rõ.
        return response()->json(['data' => $this->present($created['link']) + [
            'url' => $created['url'],
            'qr_svg' => $created['qr_svg'],
            'pin' => ($validated['pin'] ?? '') !== '' ? $validated['pin'] : null,
        ]], 201);
    }

    public function destroy(Patient $patient, ShareLink $shareLink): JsonResponse
    {
        Gate::authorize('updateFamilyLog', $patient);
        if ($shareLink->revoked_at === null) {
            $shareLink->forceFill(['revoked_at' => now()])->save();
        }

        return response()->json(['data' => $this->present($shareLink)]);
    }

    private function present(ShareLink $l): array
    {
        return [
            'id' => $l->id,
            'label' => $l->label,
            'status' => $l->status(),
            'has_pin' => $l->pin_hash !== null,
            'show_full_name' => $l->show_full_name,
            'include_documents' => $l->include_documents,
            'created_at' => $l->created_at?->toIso8601String(),
            'expires_at' => $l->expires_at->toIso8601String(),
            'revoked_at' => $l->revoked_at?->toIso8601String(),
            'view_count' => $l->view_count,
            'last_viewed_at' => $l->last_viewed_at?->toIso8601String(),
            'last_lockout_at' => $l->last_lockout_at?->toIso8601String(),
            'views' => $l->relationLoaded('views') ? $l->views->map(fn ($v) => ['at' => $v->viewed_at->toIso8601String(), 'device' => $v->device, 'ip' => $v->ip_hint])->values() : [],
        ];
    }
}
