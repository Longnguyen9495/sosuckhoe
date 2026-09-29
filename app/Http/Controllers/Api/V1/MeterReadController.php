<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\MeterAiClient;
use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Services\Ai\MeterReadNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Chụp màn hình máy đo đường huyết / huyết áp → AI đọc số → trả về để người bệnh xác nhận.
 * Không lưu ảnh, không lưu chỉ số: bấm “Đúng, lưu lại” thì màn hình mới gửi lên /readings như gõ tay.
 */
final class MeterReadController extends Controller
{
    public function __invoke(Request $request, Patient $patient, MeterAiClient $client): JsonResponse
    {
        $this->authorize('updateFamilyLog', $patient);

        $validated = $request->validate([
            'image' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:8192'],
        ]);
        $file = $validated['image'];

        try {
            $raw = $client->readMeter((string) file_get_contents($file->getRealPath()), $file->getMimeType() ?: 'image/jpeg');
        } catch (RuntimeException) {
            return response()->json(['message' => 'Chưa đọc được ảnh lúc này. Bạn gõ số giúp nhé.'], 503);
        }

        return response()->json(['data' => MeterReadNormalizer::normalize($raw)]);
    }
}
