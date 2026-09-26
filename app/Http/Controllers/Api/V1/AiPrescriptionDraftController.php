<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\AiOcrClient;
use App\Http\Controllers\Controller;
use App\Models\AiPrescriptionDraft;
use App\Models\Document;
use App\Models\Drug;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class AiPrescriptionDraftController extends Controller
{
    /**
     * POST /api/v1/patients/{patient}/ai-prescription-drafts
     *
     * Nhận ảnh đơn thuốc (base64), gọi AI OCR, lưu đề xuất dưới dạng draft.
     */
    public function store(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $validated = $request->validate([
            'image_base64' => ['required', 'string', 'max:10485760'],
            'document_id' => ['nullable', 'string', 'size:26'],
        ]);

        $documentId = $validated['document_id'] ?? null;

        // Nếu không có document_id, tạo một document tạm để giữ ảnh
        if (! $documentId) {
            $document = Document::create([
                'tenant_id' => $patient->tenant_id,
                'patient_id' => $patient->id,
                'encrypted_path' => 'ai://draft/' . Str::ulid(),
                'type' => 'prescription_image',
            ]);
            $documentId = $document->id;
        }

        $client = app(AiOcrClient::class);
        $suggestions = $client->recognize($validated['image_base64']);

        $draft = AiPrescriptionDraft::create([
            'tenant_id' => $patient->tenant_id,
            'patient_id' => $patient->id,
            'document_id' => $documentId,
            'suggestions' => $suggestions,
            'confirmed_lines' => null,
            'status' => 'pending',
        ]);

        return response()->json([
            'draft_id' => $draft->id,
            'suggestions' => $suggestions,
            'document_id' => $documentId,
        ], 201);
    }

    /**
     * GET /api/v1/patients/{patient}/ai-prescription-drafts
     */
    public function index(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $drafts = AiPrescriptionDraft::where('patient_id', $patient->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $drafts]);
    }

    /**
     * POST /api/v1/patients/{patient}/ai-prescription-drafts/{draft}/confirm
     *
     * Xác nhận từng dòng. Người dùng gửi mảng confirmed_lines,
     * mỗi phần tử phải có `index` và `confirmed` (boolean).
     */
    public function confirm(Request $request, Patient $patient, AiPrescriptionDraft $aiPrescriptionDraft): JsonResponse
    {
        $this->authorize('view', $patient);

        if ($aiPrescriptionDraft->patient_id !== $patient->id) {
            return response()->json(['message' => 'Draft không thuộc bệnh nhân này.'], 403);
        }

        $validated = $request->validate([
            'confirmed_lines' => ['required', 'array'],
            'confirmed_lines.*.index' => ['required', 'integer'],
            'confirmed_lines.*.confirmed' => ['required', 'boolean'],
        ]);

        $aiPrescriptionDraft->update([
            'confirmed_lines' => $validated['confirmed_lines'],
            'status' => 'confirmed',
        ]);

        return response()->json([
            'message' => 'Đã xác nhận các dòng.',
            'draft' => $aiPrescriptionDraft->fresh(),
        ]);
    }

    /**
     * POST /api/v1/patients/{patient}/ai-prescription-drafts/{draft}/match-drugs
     *
     * Khớp tên thuốc trong suggestions với danh mục drugs.
     * Trả về suggestions đã được bổ sung `matched_drug_id` và `match_score`.
     */
    public function matchDrugs(Request $request, Patient $patient, AiPrescriptionDraft $aiPrescriptionDraft): JsonResponse
    {
        $this->authorize('view', $patient);

        if ($aiPrescriptionDraft->patient_id !== $patient->id) {
            return response()->json(['message' => 'Draft không thuộc bệnh nhân này.'], 403);
        }

        $suggestions = $aiPrescriptionDraft->suggestions ?? [];
        $drugs = Drug::all(['id', 'brand_name', 'active_ingredient']);

        $enriched = array_map(function (array $line) use ($drugs) {
            $line['matched_drug_id'] = null;
            $line['match_score'] = 0;

            $inputName = mb_strtolower($line['drug_name'] ?? '', 'UTF-8');

            foreach ($drugs as $drug) {
                $brand = mb_strtolower($drug->brand_name, 'UTF-8');
                $active = mb_strtolower($drug->active_ingredient ?? '', 'UTF-8');

                // Đơn giản: khớp chính xác hoặc chứa
                if ($inputName === $brand || str_contains($inputName, $brand) || str_contains($brand, $inputName)) {
                    $line['matched_drug_id'] = $drug->id;
                    $line['match_score'] = 100;
                    break;
                }

                if ($active && ($inputName === $active || str_contains($inputName, $active))) {
                    $line['matched_drug_id'] = $drug->id;
                    $line['match_score'] = 80;
                    break;
                }
            }

            return $line;
        }, $suggestions);

        return response()->json([
            'data' => $enriched,
        ]);
    }
}
