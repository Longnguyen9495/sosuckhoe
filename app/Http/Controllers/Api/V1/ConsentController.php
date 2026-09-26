<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Consent;
use App\Models\ConsentVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class ConsentController extends Controller
{
    public function currentVersion(): JsonResponse
    {
        // Môi trường thật chỉ dùng bản đã duyệt; môi trường phát triển cho phép bản nháp (hiển thị nhãn NHÁP).
        $version = ConsentVersion::query()
            ->when(app()->isProduction(), fn ($q) => $q->where('is_draft', false))
            ->where('effective_date', '<=', now()->toDateString())
            ->orderBy('is_draft')
            ->orderByDesc('effective_date')
            ->first();

        return response()->json([
            'id' => $version?->id,
            'is_draft' => (bool) $version?->is_draft,
            'version' => $version?->version,
            'content' => $version?->content,
            'effective_date' => $version?->effective_date,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'consent_version_id' => ['required', 'string', 'exists:consent_versions,id'],
            'consented_at' => ['required', 'date'],
        ]);

        $user = Auth::user();

        $consent = Consent::create([
            'user_id' => $user->id,
            'consent_version_id' => $validated['consent_version_id'],
            'consented_at' => $validated['consented_at'],
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['id' => $consent->id], 201);
    }

    public function revoke(string $consentId): JsonResponse
    {
        $user = Auth::user();
        $consent = Consent::where('id', $consentId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $consent->update(['withdrawn_at' => now()]);

        return response()->json(['status' => 'withdrawn']);
    }
}
