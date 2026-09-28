<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\LabResult;
use App\Models\Log as ScheduleLog;
use App\Models\Patient;
use App\Models\PatientCondition;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\ScheduleItem;
use App\Models\Tenant;
use App\Services\Dedup\DuplicateMatcher;
use App\Services\Document\DocumentEncryptionService;
use App\Services\Document\DocumentIngestService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Dọn dữ liệu trùng đã lỡ lưu trước khi có kiểm tra trùng:
 *  - phiếu: ảnh y hệt / bản chụp lại → đánh dấu duplicate_of_id (KHÔNG xoá ảnh);
 *  - xét nghiệm: cùng tên + ngày + giá trị → giữ bản đầu, xoá bản thừa;
 *  - chẩn đoán: trùng sau chuẩn hoá / cùng mã ICD → giữ bản đầu;
 *  - thuốc: cùng thuốc ở hai đơn đang dùng → xoá dòng sau (lịch nhắc của dòng đó tự xoá theo);
 *    đơn không còn thuốc nào thì xoá, phiếu gắn với đơn đó chuyển sang đơn còn giữ.
 * Mặc định chỉ liệt kê (chạy thử); thêm --apply để thực hiện.
 */
final class DedupeHealthData extends Command
{
    protected $signature = 'health:dedupe {--apply : Thực hiện thay đổi (mặc định chỉ liệt kê)} {--patient= : Chỉ xử lý một người bệnh (id)}';

    protected $description = 'Tìm và dọn dữ liệu trùng: phiếu khám, xét nghiệm, chẩn đoán, thuốc';

    public function handle(TenantContext $context, DocumentEncryptionService $files, DocumentIngestService $ingest): int
    {
        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'Chế độ THỰC HIỆN — dữ liệu sẽ được sửa.' : 'Chế độ CHẠY THỬ — không sửa gì. Thêm --apply để thực hiện.');

        $patients = Patient::withoutGlobalScopes()->when($this->option('patient'), fn ($q, $id) => $q->whereKey($id))->get();
        foreach ($patients as $patient) {
            $context->set(Tenant::findOrFail($patient->tenant_id));
            $this->newLine();
            $this->line("<options=bold>Người bệnh {$patient->id}</>");

            $run = function () use ($patient, $files, $ingest, $apply) {
                $this->documents($patient, $files, $ingest, $apply);
                $this->labs($patient, $apply);
                $this->conditions($patient, $apply);
                $this->medications($patient, $apply);
            };
            $apply ? DB::transaction($run) : $run();
        }

        return self::SUCCESS;
    }

    private function documents(Patient $patient, DocumentEncryptionService $files, DocumentIngestService $ingest, bool $apply): void
    {
        $docs = Document::where('patient_id', $patient->id)->orderBy('created_at')->orderBy('id')->get();
        $marked = 0;
        foreach ($docs as $i => $doc) {
            if ($doc->content_hash === null) {
                try {
                    $doc->content_hash = hash('sha256', $files->decryptContent($doc->encrypted_path));
                    if ($apply) {
                        $doc->saveQuietly();
                    }
                } catch (Throwable $e) {
                    $this->warn("  ! Không đọc được ảnh {$doc->id}: ".class_basename($e));
                }
            }
            if ($doc->duplicate_of_id !== null) {
                continue;
            }
            $original = null;
            foreach ($docs->slice(0, $i) as $earlier) {
                if ($earlier->duplicate_of_id !== null) {
                    continue;
                }
                $sameFile = $doc->content_hash !== null && $doc->content_hash === $earlier->content_hash;
                if ($sameFile || ($doc->ai_status === 'done' && $earlier->ai_status === 'done' && DuplicateMatcher::sameDocument($ingest->comparable($doc), $ingest->comparable($earlier)))) {
                    $original = $earlier;
                    break;
                }
            }
            if ($original !== null) {
                $marked++;
                $doc->duplicate_of_id = $original->id; // để các phiếu sau không so với bản trùng
                $this->line('  Phiếu trùng: '.$this->docLabel($doc).'  →  bản gốc '.$this->docLabel($original));
                if ($apply) {
                    $doc->saveQuietly();
                }
            }
        }
        $this->line("  = {$marked} phiếu được đánh dấu là bản chụp trùng (ảnh vẫn giữ, xoá được ở màn Hồ sơ).");
    }

    private function labs(Patient $patient, bool $apply): void
    {
        $seen = [];
        $remove = [];
        foreach (LabResult::where('patient_id', $patient->id)->orderBy('created_at')->orderBy('id')->get() as $r) {
            $key = DuplicateMatcher::labKey($r->metric, (string) $r->measured_at, $r->value);
            if (isset($seen[$key])) {
                $remove[] = $r->id;
                $this->line("  Xét nghiệm trùng: ".substr((string) $r->measured_at, 0, 10)." {$r->metric} = {$r->value}");
            } else {
                $seen[$key] = true;
            }
        }
        if ($apply && $remove) {
            LabResult::whereKey($remove)->delete();
        }
        $this->line('  = '.count($remove).' dòng xét nghiệm thừa.');
    }

    private function conditions(Patient $patient, bool $apply): void
    {
        $kept = [];
        $remove = [];
        foreach (PatientCondition::where('patient_id', $patient->id)->orderBy('created_at')->orderBy('id')->get() as $c) {
            $title = trim(explode("\n", (string) $c->notes)[0]);
            $same = collect($kept)->first(fn ($k) => DuplicateMatcher::sameDiagnosis($title, $k));
            if ($same !== null) {
                $remove[] = $c->id;
                $this->line("  Chẩn đoán trùng: {$title}  ≈  {$same}");
            } else {
                $kept[] = $title;
            }
        }
        if ($apply && $remove) {
            PatientCondition::whereKey($remove)->delete();
        }
        $this->line('  = '.count($remove).' chẩn đoán trùng.');
    }

    private function medications(Patient $patient, bool $apply): void
    {
        $items = PrescriptionItem::query()
            ->join('prescriptions', 'prescriptions.id', '=', 'prescription_items.prescription_id')
            ->where('prescription_items.patient_id', $patient->id)
            ->where('prescriptions.status', 'active')
            ->orderBy('prescriptions.prescribed_at')->orderBy('prescriptions.created_at')->orderBy('prescription_items.created_at')->orderBy('prescription_items.id')
            ->get(['prescription_items.*']);

        $kept = []; // [item, prescription_id]
        $remove = [];
        $moveTo = []; // đơn bị xoá hết thuốc → đơn giữ thuốc đó
        foreach ($items as $item) {
            // Chỉ tính trùng giữa HAI đơn khác nhau: nhiều dòng cùng thuốc trong một đơn (VD các mũi insulin) là hợp lệ.
            $same = collect($kept)->first(fn ($k) => $k->prescription_id !== $item->prescription_id && DuplicateMatcher::sameDrug($k->drug_name_snapshot, $item->drug_name_snapshot));
            if ($same !== null) {
                $remove[] = $item->id;
                $moveTo[$item->prescription_id] = $same->prescription_id;
                $this->line("  Thuốc trùng: {$item->drug_name_snapshot}  ≈  {$same->drug_name_snapshot} (đơn đang giữ)");
            } else {
                $kept[] = $item;
            }
        }

        $orphanLogs = $remove ? ScheduleLog::whereIn('schedule_item_id', ScheduleItem::whereIn('prescription_item_id', $remove)->select('id'))->count() : 0;
        $emptied = collect(array_keys($moveTo))->filter(fn ($rx) => PrescriptionItem::where('prescription_id', $rx)->whereNotIn('id', $remove)->doesntExist())->values();

        if ($apply && $remove) {
            PrescriptionItem::whereKey($remove)->delete(); // lịch nhắc (schedule_items) của các dòng này xoá theo
            foreach ($emptied as $rx) {
                Document::where('prescription_id', $rx)->update(['prescription_id' => $moveTo[$rx]]);
                Prescription::whereKey($rx)->delete();
            }
        }
        $this->line('  = '.count($remove).' dòng thuốc trùng'.($emptied->count() ? ", {$emptied->count()} đơn không còn thuốc sẽ bị xoá" : '').($orphanLogs ? ", {$orphanLogs} lượt đánh dấu đã uống thuộc lịch trùng" : '').'.');
    }

    private function docLabel(Document $d): string
    {
        return '“'.($d->analysis['title'] ?? $d->department ?? $d->type).'” '.($d->document_date?->format('d/m') ?? '').' ['.substr($d->id, -6).']';
    }
}
