<?php

namespace App\Services\Document;

use App\Contracts\MedicalAiClient;
use App\Models\Document;
use App\Models\Event;
use App\Models\LabResult;
use App\Models\Patient;
use App\Models\PatientCondition;
use App\Services\Dedup\DuplicateMatcher;
use App\Services\Privacy\ImageMetadataStripper;
use App\Services\Privacy\SensitiveDataScrubber;
use App\Services\Schedule\MedicationScheduleMapper;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Nhận một ảnh phiếu khám / đơn thuốc:
 *  1. bỏ metadata ảnh (GPS…), mã hoá và lưu ảnh gốc ngoài thư mục public;
 *  2. AI đọc ảnh → lọc CCCD / BHYT (SensitiveDataScrubber) → lưu phần thông tin y khoa;
 *  3. tách kết quả xét nghiệm, chẩn đoán, lịch tái khám thành dữ liệu có cấu trúc.
 * Ảnh chỉ là thẻ CCCD / BHYT thì không lưu gì cả.
 */
final class DocumentIngestService
{
    public const TYPES = ['don', 'xn', 'cdha', 'kham', 'hd', 'thuoc', 'khac'];

    private const IDENTITY_TYPES = ['id_card', 'insurance_card'];

    private const ANALYZABLE = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly DocumentEncryptionService $storage,
        private readonly SensitiveDataScrubber $scrubber,
        private readonly ImageMetadataStripper $stripper,
        private readonly MedicalAiClient $ai,
    ) {}

    /**
     * @param  array{type?: ?string, title?: ?string, document_date?: ?string, department?: ?string, doctor_name?: ?string}  $hints
     * @return array{status: string, document: ?Document, message: ?string}
     */
    public function ingest(Patient $patient, UploadedFile $file, array $hints = [], bool $analyze = true): array
    {
        $binary = (string) $file->get();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary) ?: 'application/octet-stream';
        $binary = $this->stripper->strip($binary, $mime);

        // Ảnh tải lại y hệt: trả phiếu đã có, không lưu thêm, không gọi AI (đỡ tốn tiền).
        $hash = hash('sha256', $binary);
        $same = Document::where('patient_id', $patient->id)->where('content_hash', $hash)->first();
        if ($same !== null) {
            return [
                'status' => 'duplicate',
                'document' => $same,
                'message' => 'Ảnh này đã có trong hồ sơ'.($same->document_date ? ' (phiếu ngày '.$same->document_date->format('d/m/Y').')' : '').' nên không lưu lại.',
            ];
        }

        $analysis = null;
        $aiStatus = 'skipped';
        $aiError = null;

        if ($analyze && in_array($mime, self::ANALYZABLE, true)) {
            try {
                @set_time_limit(max(60, (int) config('services.ai.timeout', 150) + 30));
                [$analysis, $masked] = $this->scrubber->scrub($this->ai->analyzeDocument($binary, $mime));
                $aiStatus = 'done';
                if ($masked > 0) {
                    $analysis['_masked'] = $masked;
                }
            } catch (Throwable $e) {
                Log::warning('Document AI analysis failed', ['patient' => $patient->id, 'error' => class_basename($e)]);
                $aiStatus = 'failed';
                $aiError = mb_substr($e->getMessage(), 0, 250);
            }
        }

        if (in_array($analysis['document_type'] ?? null, self::IDENTITY_TYPES, true)) {
            return [
                'status' => 'rejected_identity',
                'document' => null,
                'message' => $analysis['document_type'] === 'id_card'
                    ? 'Ảnh là thẻ căn cước / CMND nên không được lưu.'
                    : 'Ảnh là thẻ BHYT nên không được lưu.',
            ];
        }

        $path = $this->storage->storeEncryptedContent($binary, $this->extension($mime));

        try {
            $document = DB::transaction(function () use ($patient, $path, $hash, $hints, $analysis, $aiStatus, $aiError) {
                $normalized = $analysis !== null ? $this->normalize($analysis) : null;
                $type = $normalized['document_type'] ?? null;
                if (! in_array($type, self::TYPES, true)) {
                    $type = in_array($hints['type'] ?? null, self::TYPES, true) ? $hints['type'] : 'khac';
                }
                $documentDate = $normalized['document_date'] ?? $hints['document_date'] ?? now()->toDateString();
                $original = $normalized !== null ? $this->findRetake($patient, $type, $documentDate, $normalized) : null;

                $document = Document::create([
                    'patient_id' => $patient->id,
                    'encrypted_path' => $path,
                    'content_hash' => $hash,
                    'duplicate_of_id' => $original?->id,
                    'type' => $type,
                    'document_date' => $documentDate,
                    'department' => $normalized['department'] ?? $normalized['facility'] ?? $hints['department'] ?? $hints['title'] ?? null,
                    'doctor_name' => $normalized['doctor_name'] ?? $hints['doctor_name'] ?? null,
                    'analysis' => [
                        'title' => $normalized['title'] ?? $hints['title'] ?? null,
                        'facility' => $normalized['facility'] ?? null,
                        'findings' => $normalized['findings'] ?? [],
                        'diagnoses' => $normalized['diagnoses'] ?? [],
                        'medications' => $normalized['medications'] ?? [],
                        'advice' => $normalized['advice'] ?? [],
                        'follow_up_date' => $normalized['follow_up_date'] ?? null,
                        'follow_up_note' => $normalized['follow_up_note'] ?? null,
                        'masked_count' => (int) ($analysis['_masked'] ?? 0),
                        'source' => $analysis !== null ? 'ai' : 'upload',
                        'model' => $analysis !== null ? $this->ai->model() : null,
                    ],
                    'ai_status' => $aiStatus,
                    'ai_error' => $aiError,
                ]);

                // Phiếu chụp lại: dữ liệu đã nhập từ phiếu gốc, không tách xét nghiệm / chẩn đoán lần nữa.
                if ($normalized !== null && $original === null) {
                    $this->storeStructured($patient, $document, $normalized);
                }

                return $document;
            });
        } catch (Throwable $e) {
            $this->storage->delete($path);
            throw $e;
        }

        if ($document->duplicate_of_id !== null) {
            return ['status' => 'stored_retake', 'document' => $document, 'message' => 'Phiếu này giống một phiếu đã có (cùng ngày, cùng nội dung) nên không nhập lại thuốc, xét nghiệm, chẩn đoán.'];
        }

        return ['status' => $aiStatus === 'failed' ? 'stored_ai_failed' : 'stored', 'document' => $document, 'message' => $aiError];
    }

    /** Phiếu gốc mà ảnh mới là bản chụp lại (cùng loại, cùng ngày, nội dung gần như giống hệt). */
    public function findRetake(Patient $patient, string $type, string $date, array $normalized, ?string $exceptId = null): ?Document
    {
        $candidates = Document::where('patient_id', $patient->id)
            ->where('type', $type)
            ->whereDate('document_date', $date)
            ->where('ai_status', 'done')
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->orderBy('created_at')->orderBy('id')
            ->get();
        $new = ['type' => $type, 'date' => $date] + $normalized;

        foreach ($candidates as $doc) {
            if (DuplicateMatcher::sameDocument($new, $this->comparable($doc))) {
                return $doc->duplicate_of_id !== null ? (Document::find($doc->duplicate_of_id) ?? $doc) : $doc;
            }
        }

        return null;
    }

    /** Dữ liệu của một phiếu đã lưu, cùng dạng với kết quả normalize() để so khớp. */
    public function comparable(Document $doc): array
    {
        $a = $doc->analysis ?? [];

        return [
            'type' => $doc->type,
            'date' => $doc->document_date?->toDateString(),
            'title' => $a['title'] ?? null,
            'department' => $doc->department,
            'medications' => $a['medications'] ?? [],
            'diagnoses' => $a['diagnoses'] ?? [],
            'findings' => $a['findings'] ?? [],
            'lab_results' => LabResult::where('document_id', $doc->id)->get(['metric', 'value'])
                ->map(fn (LabResult $r) => ['name' => $r->metric, 'value' => $r->value])->all(),
        ];
    }

    /** Làm sạch cấu trúc AI trả về: đúng kiểu, cắt độ dài, ngày hợp lệ. */
    public function normalize(array $a): array
    {
        $str = fn ($v, int $max = 255) => is_scalar($v) && trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, $max) : null;
        $list = fn ($v, int $max = 12) => array_values(array_slice(array_filter(array_map(fn ($x) => $str($x, 500), is_array($v) ? $v : [])), 0, $max));
        $date = function ($v) {
            if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                return null;
            }
            try {
                $d = CarbonImmutable::createFromFormat('!Y-m-d', $v);
            } catch (Throwable) {
                return null;
            }

            return $d && $d->year >= 1990 && $d->year <= now()->year + 2 ? $d->toDateString() : null;
        };

        $medications = [];
        foreach (array_slice(array_filter($a['medications'] ?? [], 'is_array'), 0, 40) as $m) {
            $name = $str($m['drug_name'] ?? null);
            if ($name === null) {
                continue;
            }
            $med = [
                'drug_name' => $name,
                'active_ingredient' => $str($m['active_ingredient'] ?? null),
                'type' => MedicationScheduleMapper::type($m['type'] ?? null),
                'dose_text' => $str($m['dose_text'] ?? null, 500),
                'quantity' => is_numeric($m['quantity'] ?? null) ? (float) $m['quantity'] : null,
                'unit' => $str($m['unit'] ?? null, 60),
                'duration_days' => is_numeric($m['duration_days'] ?? null) && $m['duration_days'] > 0 ? min(365, (int) $m['duration_days']) : null,
                'schedule' => array_values(array_filter($m['schedule'] ?? [], 'is_array')),
                'fixed_times' => array_values(array_filter($m['fixed_times'] ?? [], 'is_string')),
            ];
            $med['usage_rule'] = MedicationScheduleMapper::toUsageRule($med);
            $medications[] = $med;
        }

        $labs = [];
        foreach (array_slice(array_filter($a['lab_results'] ?? [], 'is_array'), 0, 120) as $r) {
            $name = $str($r['name'] ?? null, 120);
            $value = $str($r['value'] ?? null, 100);
            if ($name === null || $value === null) {
                continue;
            }
            $labs[] = [
                'group' => $str($r['group'] ?? null, 80),
                'name' => $name,
                'value' => $value,
                'unit' => $str($r['unit'] ?? null, 50),
                'reference_range' => $str($r['reference_range'] ?? null, 100),
                'flag' => in_array($r['flag'] ?? null, ['H', 'L', 'N', 'W'], true) ? $r['flag'] : null,
            ];
        }

        $diagnoses = [];
        foreach (array_slice(array_filter($a['diagnoses'] ?? [], 'is_array'), 0, 15) as $d) {
            if ($name = $str($d['name'] ?? null)) {
                $diagnoses[] = ['name' => $name, 'icd_code' => $str($d['icd_code'] ?? null, 20)];
            }
        }

        return [
            'document_type' => $str($a['document_type'] ?? null, 20),
            'title' => $str($a['title'] ?? null),
            'document_date' => $date($a['document_date'] ?? null),
            'facility' => $str($a['facility'] ?? null),
            'department' => $str($a['department'] ?? null),
            'doctor_name' => $str($a['doctor_name'] ?? null),
            'diagnoses' => $diagnoses,
            'medications' => $medications,
            'lab_results' => $labs,
            'findings' => $list($a['findings'] ?? []),
            'advice' => $list($a['advice'] ?? []),
            'follow_up_date' => $date($a['follow_up_date'] ?? null),
            'follow_up_note' => $str($a['follow_up_note'] ?? null, 500),
        ];
    }

    private function storeStructured(Patient $patient, Document $document, array $n): void
    {
        $measuredAt = $document->document_date?->toDateString() ?? now()->toDateString();

        // Xét nghiệm đã có (cùng tên, cùng ngày, cùng giá trị — từ phiếu khác hoặc bản chụp khác) thì bỏ qua.
        // Cùng tên, cùng ngày nhưng khác giá trị vẫn lưu cả hai để người dùng tự đối chiếu với phiếu gốc.
        $seenLabs = LabResult::where('patient_id', $patient->id)->whereDate('measured_at', $measuredAt)->get(['metric', 'value', 'measured_at'])
            ->mapWithKeys(fn (LabResult $r) => [DuplicateMatcher::labKey($r->metric, $measuredAt, $r->value) => true])->all();
        foreach ($n['lab_results'] as $r) {
            $metric = ($r['group'] ? $r['group'].' — ' : '').$r['name'];
            $key = DuplicateMatcher::labKey($metric, $measuredAt, $r['value']);
            if (isset($seenLabs[$key])) {
                continue;
            }
            $seenLabs[$key] = true;
            LabResult::create([
                'patient_id' => $patient->id,
                'document_id' => $document->id,
                'metric' => $metric,
                'value' => $r['value'],
                'unit' => $r['unit'],
                'reference_range' => $r['reference_range'],
                'flag' => $r['flag'],
                'measured_at' => $measuredAt,
            ]);
        }

        // Chẩn đoán → bệnh nền, bỏ qua chẩn đoán đã có (cùng tên sau chuẩn hoá, hoặc cùng mã ICD và tên gần giống).
        $existing = PatientCondition::where('patient_id', $patient->id)->pluck('notes')
            ->map(fn ($notes) => trim(explode("\n", (string) $notes)[0]))->all();
        foreach ($n['diagnoses'] as $d) {
            $title = $d['name'].($d['icd_code'] ? ' ('.$d['icd_code'].')' : '');
            foreach ($existing as $old) {
                if (DuplicateMatcher::sameDiagnosis($title, $old)) {
                    continue 2;
                }
            }
            PatientCondition::create([
                'patient_id' => $patient->id,
                'diagnosed_at' => $measuredAt,
                'priority' => 'normal',
                'notes' => $title."\nTheo ".($n['title'] ?? 'phiếu khám').' ngày '.CarbonImmutable::parse($measuredAt)->format('d/m/Y'),
            ]);
            $existing[] = $title;
        }

        // Hẹn tái khám → mốc lịch.
        if ($n['follow_up_date'] !== null && $n['follow_up_date'] >= now()->toDateString()) {
            $title = 'Tái khám'.($document->department ? ' — '.$document->department : '');
            $exists = Event::where('patient_id', $patient->id)->whereDate('event_date', $n['follow_up_date'])->where('title', $title)->exists();
            if (! $exists) {
                Event::create([
                    'patient_id' => $patient->id,
                    'event_date' => $n['follow_up_date'],
                    'type' => 'appointment',
                    'title' => $title,
                    'description' => $n['follow_up_note'],
                    'status' => 'pending',
                ]);
            }
        }
    }

    private function extension(string $mime): string
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
