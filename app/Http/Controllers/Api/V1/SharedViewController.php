<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\Document\DocumentEncryptionService;
use App\Services\Share\ShareLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Trang xem hồ sơ qua link chia sẻ — KHÔNG cần đăng nhập. Link sai / hết hạn / đã thu hồi đều trả cùng một
 * thông báo 404 (không cho biết link từng tồn tại). Mọi phản hồi: no-store, không cho công cụ tìm kiếm lập chỉ mục.
 */
final class SharedViewController extends Controller
{
    private const GONE = 'Link không còn hiệu lực. Link có thể đã hết hạn hoặc bị người bệnh thu hồi.';

    public function __construct(private readonly ShareLinkService $service) {}

    /** Thông tin trước khi mở: có cần PIN không, còn hạn tới khi nào. */
    public function show(string $token): JsonResponse
    {
        $link = $this->service->resolve($token);
        if ($link === null) {
            return $this->json(['code' => 'SHARE_GONE', 'message' => self::GONE], 404);
        }
        $locked = $link->locked_until !== null && $link->locked_until->isFuture();

        return $this->json(['data' => [
            'requires_pin' => $link->pin_hash !== null,
            'label' => $link->label,
            'expires_at' => $link->expires_at->toIso8601String(),
            'locked_minutes' => $locked ? (int) ceil(now()->diffInSeconds($link->locked_until) / 60) : null,
        ]]);
    }

    /** Mở hồ sơ (kèm PIN nếu link có PIN). Trả nội dung + phiên xem 30 phút để tải ảnh phiếu. */
    public function open(Request $request, string $token): JsonResponse
    {
        $link = $this->service->resolve($token);
        if ($link === null) {
            return $this->json(['code' => 'SHARE_GONE', 'message' => self::GONE], 404);
        }
        $pin = $request->input('pin');
        $result = $this->service->open($link, is_string($pin) ? trim($pin) : null, $request);

        if (! $result['ok']) {
            return match ($result['error']) {
                'pin_required' => $this->json(['code' => 'PIN_REQUIRED', 'message' => 'Nhập mã PIN người bệnh gửi cho bạn.'], 401),
                'pin_wrong' => $this->json(['code' => 'PIN_WRONG', 'message' => 'Mã PIN chưa đúng. Còn '.$result['attempts_left'].' lần thử.', 'attempts_left' => $result['attempts_left']], 401),
                default => $this->json(['code' => 'LOCKED', 'message' => 'Nhập sai PIN nhiều lần. Thử lại sau '.$result['retry_after'].' phút.', 'retry_after' => $result['retry_after']], 423),
            };
        }

        return $this->json(['data' => $this->service->summary($link), 'session' => $result['session']]);
    }

    /** Ảnh một phiếu khám — chỉ khi link cho phép xem ảnh; cần phiên xem (header X-Share-Session). */
    public function document(Request $request, string $document, DocumentEncryptionService $files): Response
    {
        $link = $this->service->fromSession($request->header('X-Share-Session'));
        if ($link === null || ! $link->include_documents) {
            return $this->json(['code' => 'SHARE_GONE', 'message' => self::GONE], 404);
        }
        $doc = Document::where('patient_id', $link->patient_id)->find($document);
        if ($doc === null) {
            return $this->json(['message' => 'Không tìm thấy phiếu.'], 404);
        }
        try {
            $content = $files->decryptContent($doc->encrypted_path);
        } catch (Throwable) {
            return $this->json(['message' => 'Không mở được ảnh.'], 404);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: 'application/octet-stream';
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
            $mime = 'application/octet-stream';
        }

        return response($content, 200, $this->headers() + ['Content-Type' => $mime, 'Content-Disposition' => 'inline']);
    }

    private function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, $this->headers());
    }

    private function headers(): array
    {
        return ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer'];
    }
}
