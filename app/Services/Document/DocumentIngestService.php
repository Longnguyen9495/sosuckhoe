<?php

namespace App\Services\Document;

use App\Contracts\MedicalAiClient;
use App\Models\Document;
use App\Models\Event;
use App\Models\LabResult;
use App\Models\Patient;
use App\Models\PatientCondition;
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
            $document = DB::transaction(function () use ($patient, $path, $hints, $analysis, $aiStatus, $aiError) {
                $normalized = $analysis !== null ? $this->normalize($analysis) : null;
                $type = $normalized['document_type'] ?? null;
                if (! in_array($type, self::TYPES, true)) {
                    $type = in_array($hints['type'] ?? null, self::TYPES, true) ? $hints['type'] : 'khac';
                }

                $document = Document::create([
                    'patient_id' => $patient->id,
                    'encrypted_path' => $path,
                    'type' => $type,
                    'document_date' => $normalized['document_date'] ?? $hints['document_date'] ?? now()->toDateString(),
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

                if ($normalized !== null) {
                    $this->storeStructured($patient, $document, $normalized);
                }

                return $document;
            });
        } catch (Throwable $e) {
            $this->storage->delete($path);
            throw $e;
        }

        return ['status' => $aiStatus === 'failed' ? 'stored_ai_failed' : 'stored', 'document' => $document, 'message' => $aiError];
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

        foreach ($n['lab_results'] as $r) {
            LabResult::create([
                'patient_id' => $patient->id,
                'document_id' => $document->id,
                'metric' => ($r['group'] ? $r['group'].' — ' : '').$r['name'],
                'value' => $r['value'],
                'unit' => $r['unit'],
                'reference_range' => $r['reference_range'],
                'flag' => $r['flag'],
                'measured_at' => $measuredAt,
            ]);
        }

        // Chẩn đoán → bệnh nền (không trùng tên đã có).
        $existing = PatientCondition::where('patient_id', $patient->id)->pluck('notes')
            ->map(fn ($notes) => mb_strtolower(trim(explode("\n", (string) $notes)[0])))->all();
        foreach ($n['diagnoses'] as $d) {
            $title = $d['name'].($d['icd_code'] ? ' ('.$d['icd_code'].')' : '');
            if (in_array(mb_strtolower($d['name']), array_map(fn ($t) => preg_replace('/\s*\([^)]*\)$/', '', $t), $existing), true)) {
                continue;
            }
            PatientCondition::create([
                'patient_id' => $patient->id,
                'diagnosed_at' => $measuredAt,
                'priority' => 'normal',
                'notes' => $title."\nTheo ".($n['title'] ?? 'phiếu khám').' ngày '.CarbonImmutable::parse($measuredAt)->format('d/m/Y'),
            ]);
            $existing[] = mb_strtolower($title);
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
