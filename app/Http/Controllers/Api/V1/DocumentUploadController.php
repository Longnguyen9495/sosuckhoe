<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\LabResult;
use App\Models\Patient;
use App\Services\Document\DocumentEncryptionService;
use App\Services\Document\DocumentIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DocumentUploadController extends Controller
{
    /** Loại phiếu: đơn thuốc, xét nghiệm, chẩn đoán hình ảnh, kết quả khám, hướng dẫn, vỏ thuốc, khác. */
    public const TYPES = DocumentIngestService::TYPES;

    /** Tên cũ còn gặp trong dữ liệu / client cũ. */
    private const LEGACY_TYPES = ['prescription' => 'don', 'lab' => 'xn', 'discharge' => 'kham', 'other' => 'khac'];

    public function index(Patient $patient): JsonResponse
    {
        Gate::authorize('view', $patient);

        $documents = Document::query()
            ->where('tenant_id', $patient->tenant_id)
            ->where('patient_id', $patient->getKey())
            ->orderByDesc('document_date')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $documents->map(fn (Document $document) => $this->present($patient, $document))->values(),
        ]);
    }

    /**
     * Tải một ảnh / PDF; AI đọc ngay (mặc định). Giao diện gửi lần lượt từng ảnh để báo tiến độ.
     */
    public function store(Request $request, Patient $patient, DocumentIngestService $ingest): JsonResponse
    {
        Gate::authorize('updateFamilyLog', $patient);

        $request->merge(['type' => self::LEGACY_TYPES[$request->input('type')] ?? $request->input('type')]);
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:15360'],
            'type' => ['nullable', 'in:'.implode(',', self::TYPES)],
            'title' => ['nullable', 'string', 'max:255'],
            'document_date' => ['nullable', 'date'],
            'department' => ['nullable', 'string', 'max:255'],
            'doctor_name' => ['nullable', 'string', 'max:255'],
            'analyze' => ['nullable', 'boolean'],
        ]);

        $result = $ingest->ingest($patient, $request->file('file'), $validated, $request->boolean('analyze', true));

        if ($result['document'] === null) {
            return response()->json(['status' => $result['status'], 'message' => $result['message'], 'data' => null], 200);
        }

        return response()->json([
            'status' => $result['status'],
            'message' => $result['status'] === 'stored_ai_failed' ? 'Đã lưu ảnh nhưng AI chưa đọc được. Có thể thử đọc lại sau.' : null,
            'data' => $this->present($patient, $result['document']),
        ], 201);
    }

    /** Xoá phiếu: xoá file mã hoá, kết quả xét nghiệm tách từ phiếu. */
    public function destroy(Patient $patient, Document $document, DocumentEncryptionService $storage): JsonResponse
    {
        Gate::authorize('updateFamilyLog', $patient);
        abort_unless($document->patient_id === $patient->id, 404);

        DB::transaction(function () use ($document): void {
            LabResult::where('document_id', $document->id)->delete();
            $document->delete();
        });
        $storage->delete($document->encrypted_path);

        return response()->json(['message' => 'Đã xoá phiếu và ảnh.']);
    }

    /** Thuốc AI đọc được từ các phiếu chưa đưa vào lịch. */
    public function pendingMedications(Patient $patient): JsonResponse
    {
        Gate::authorize('view', $patient);

        $documents = Document::query()
            ->where('patient_id', $patient->id)
            ->whereNull('prescription_id')
            ->where('ai_status', 'done')
            ->orderByDesc('document_date')
            ->get()
            ->filter(fn (Document $d) => ! empty($d->analysis['medications']) && empty($d->analysis['medications_dismissed']));

        return response()->json(['data' => $documents->map(fn (Document $d) => [
            'document_id' => $d->id,
            'type' => $d->type,
            'title' => $d->analysis['title'] ?? $d->department ?? 'Phiếu khám',
            'document_date' => $d->document_date?->toDateString(),
            'doctor_name' => $d->doctor_name,
            'medications' => $d->analysis['medications'],
        ])->values()]);
    }

    /** Bỏ qua thuốc của một phiếu (VD ảnh vỏ hộp, đơn cũ đã hết). */
    public function dismissMedications(Patient $patient, Document $document): JsonResponse
    {
        Gate::authorize('updateFamilyLog', $patient);
        abort_unless($document->patient_id === $patient->id, 404);

        $analysis = $document->analysis ?? [];
        $analysis['medications_dismissed'] = true;
        $document->update(['analysis' => $analysis]);

        return response()->json(['message' => 'Đã bỏ qua.']);
    }

    private function present(Patient $patient, Document $document): array
    {
        $analysis = is_string($document->analysis) ? json_decode($document->analysis, true) : ($document->analysis ?? []);

        return [
            'id' => $document->getKey(),
            'type' => $document->type,
            'title' => $analysis['title'] ?? $document->department,
            'findings' => array_values($analysis['findings'] ?? []),
            'diagnoses' => array_values($analysis['diagnoses'] ?? []),
            'advice' => array_values($analysis['advice'] ?? []),
            'medications_count' => count($analysis['medications'] ?? []),
            'follow_up_date' => $analysis['follow_up_date'] ?? null,
            'masked_count' => (int) ($analysis['masked_count'] ?? 0),
            'ai_status' => $document->ai_status,
            'imported' => $document->prescription_id !== null,
            'document_date' => $document->document_date?->toDateString(),
            'department' => $document->department,
            'doctor_name' => $document->doctor_name,
            'duplicate_of_id' => $document->duplicate_of_id,
            'file_url' => route('patients.documents.file', ['patient' => $patient->getKey(), 'document' => $document->getKey()]),
        ];
    }
}
