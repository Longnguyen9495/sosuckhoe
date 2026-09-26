<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\Document\DocumentEncryptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentDownloadController extends Controller
{
    /**
     * GET /patients/{patient}/documents/{document}/file
     * Nằm trong nhóm route có tenant + scopeBindings nên phiếu của bệnh nhân / tài khoản khác trả 404.
     */
    public function showForPatient(Request $request, \App\Models\Patient $patient, Document $document): JsonResponse|StreamedResponse
    {
        $this->authorize('view', $patient);

        return $this->show($request, $document);
    }

    public function show(Request $request, Document $document): JsonResponse|StreamedResponse
    {
        $this->authorize('view', $document);

        $service = app(DocumentEncryptionService::class);

        try {
            $content = $service->decryptContent($document->encrypted_path);
        } catch (\RuntimeException) {
            return response()->json(['message' => 'File không tồn tại.'], 404);
        }

        $mime = $this->detectMime($document->encrypted_path, $content);
        $filename = $document->id . '.' . $this->detectExtension($mime);

        return response()->streamDownload(
            function () use ($content): void {
                echo $content;
            },
            $filename,
            [
                'Content-Type' => $mime,
                'Cache-Control' => 'private, no-store',
            ]
        );
    }

    private function detectMime(string $path, string $content): string
    {
        if (str_ends_with($path, '.pdf.enc')) {
            return 'application/pdf';
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($content);

        return in_array($detected, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)
            ? $detected
            : 'application/octet-stream';
    }

    private function detectExtension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }
}
