<?php

namespace App\Services\CarePlan;

use App\Contracts\MedicalAiClient;
use App\Models\CarePlan;
use App\Models\Document;
use App\Models\LabResult;
use App\Models\Patient;
use App\Models\PatientCondition;
use App\Models\PrescriptionItem;
use App\Models\Reading;
use App\Services\Privacy\SensitiveDataScrubber;
use Carbon\CarbonImmutable;

/**
 * Lập kế hoạch chăm sóc (chế độ ăn, sinh hoạt, theo dõi, dấu hiệu nguy hiểm) bám theo
 * thuốc đang dùng và kết quả xét nghiệm. Chỉ gửi cho AI dữ liệu y khoa đã ẩn danh:
 * không tên, không SĐT, không CCCD / BHYT — chỉ tuổi, giới, dị ứng.
 */
final class CarePlanService
{
    public const DISCLAIMER = 'Kế hoạch do AI lập từ đơn thuốc và kết quả xét nghiệm để tham khảo; không thay thế chỉ định của bác sĩ. Không tự ý đổi liều hay ngừng thuốc — hãy hỏi bác sĩ điều trị.';

    public function __construct(
        private readonly MedicalAiClient $ai,
        private readonly SensitiveDataScrubber $scrubber,
    ) {}

    public function latest(Patient $patient): ?CarePlan
    {
        return CarePlan::where('patient_id', $patient->id)->latest('created_at')->latest('id')->first();
    }

    public function generate(Patient $patient, ?string $userId = null): CarePlan
    {
        $context = $this->context($patient);
        @set_time_limit(max(60, (int) config('services.ai.timeout', 150) + 30));
        [$content] = $this->scrubber->scrub($this->normalize($this->ai->generateCarePlan($context + ['exercise_library' => ExerciseLibrary::forPrompt()])));
        if ($content['exercises'] === []) {
            $content['exercises'] = array_map(fn (array $e) => ['id' => $e['id'], 'why' => null, 'frequency' => null], ExerciseLibrary::suggest($context));
        }

        return CarePlan::create([
            'patient_id' => $patient->id,
            'content' => $content,
            'sources' => [
                'medications' => count($context['medications']),
                'lab_results' => count($context['lab_results']),
                'diagnoses' => count($context['diagnoses']),
                'readings' => count($context['recent_readings']),
            ],
            'model' => $this->ai->model(),
            'generated_by' => $userId,
        ]);
    }

    /**
     * Bài tập của kế hoạch, kèm video. Kế hoạch lập trước khi có mục bài tập thì gợi ý theo quy tắc
     * từ dữ liệu hiện tại, để người bệnh không phải lập lại.
     *
     * @return list<array>
     */
    public function exercises(CarePlan $plan, Patient $patient): array
    {
        $picked = $plan->content['exercises'] ?? null;
        if (! is_array($picked)) {
            return ExerciseLibrary::suggest($this->context($patient));
        }

        return array_values(array_map(
            fn (array $e) => ExerciseLibrary::present($e['id'], $e['why'] ?? null, $e['frequency'] ?? null),
            array_filter($picked, fn ($e) => is_array($e) && is_string($e['id'] ?? null) && ExerciseLibrary::exists($e['id'])),
        ));
    }

    /**
     * Thực đơn của một ngày: theo thứ trong tuần nếu kế hoạch có thực đơn 7 ngày, không thì thực đơn mẫu.
     *
     * @return array{breakfast: ?string, lunch: ?string, dinner: ?string, snacks: ?string}
     */
    public static function menuFor(?array $content, CarbonImmutable $day): array
    {
        $diet = $content['diet'] ?? [];
        $week = $diet['weekly_menu'] ?? [];
        $menu = count($week) === 7 ? $week[$day->dayOfWeekIso - 1] : ($diet['sample_day'] ?? []);

        return [
            'breakfast' => $menu['breakfast'] ?? null,
            'lunch' => $menu['lunch'] ?? null,
            'dinner' => $menu['dinner'] ?? null,
            'snacks' => $menu['snacks'] ?? null,
        ];
    }

    /** Dữ liệu gửi AI — đã ẩn danh. */
    public function context(Patient $patient): array
    {
        $today = CarbonImmutable::today();

        $medications = PrescriptionItem::query()
            ->join('prescriptions', 'prescriptions.id', '=', 'prescription_items.prescription_id')
            ->where('prescription_items.patient_id', $patient->id)
            ->where('prescriptions.status', 'active')
            ->where(fn ($q) => $q->whereNull('prescriptions.ends_at')->orWhere('prescriptions.ends_at', '>=', $today->toDateString()))
            ->get(['prescription_items.drug_name_snapshot', 'prescription_items.dose_text', 'prescription_items.usage_rule', 'prescriptions.doctor_name'])
            ->map(fn ($i) => [
                'drug' => $i->drug_name_snapshot,
                'how_to_use' => $i->dose_text,
                'type' => (is_array($i->usage_rule) ? $i->usage_rule : json_decode((string) $i->usage_rule, true))['type'] ?? 'medication',
                'prescriber' => $i->doctor_name,
            ])->values()->all();

        // Kết quả gần nhất của mỗi chỉ số, trong 12 tháng.
        $labs = LabResult::where('patient_id', $patient->id)
            ->where('measured_at', '>=', $today->subYear()->toDateString())
            ->orderByDesc('measured_at')
            ->get()
            ->unique('metric')
            ->take(80)
            ->map(fn (LabResult $r) => [
                'test' => $r->metric,
                'value' => $r->value,
                'unit' => $r->unit,
                'reference' => $r->reference_range,
                'flag' => $r->flag,
                'date' => (string) $r->measured_at,
            ])->values()->all();

        $diagnoses = PatientCondition::where('patient_id', $patient->id)->pluck('notes')
            ->map(fn ($n) => trim(explode("\n", (string) $n)[0]))->filter()->unique()->values()->all();

        $advice = Document::where('patient_id', $patient->id)
            ->where('ai_status', 'done')
            ->where('document_date', '>=', $today->subYear()->toDateString())
            ->get()
            ->flatMap(fn (Document $d) => $d->analysis['advice'] ?? [])
            ->unique()->take(20)->values()->all();

        $readings = Reading::where('patient_id', $patient->id)
            ->where('measured_at', '>=', $today->subDays(14))
            ->orderByDesc('measured_at')
            ->limit(30)
            ->get()
            ->map(fn (Reading $r) => ['type' => $r->type, 'context' => $r->context, 'values' => $r->values, 'at' => (string) $r->measured_at])
            ->values()->all();

        $birth = $patient->birth_date ?? ($patient->birth_year ? CarbonImmutable::create($patient->birth_year) : null);

        return [
            'age' => $birth ? (int) $birth->diffInYears($today) : null,
            'gender' => $patient->gender,
            'allergies' => $patient->allergies,
            'diagnoses' => $diagnoses,
            'medications' => $medications,
            'lab_results' => $labs,
            'doctor_advice' => $advice,
            'recent_readings' => $readings,
            'today' => $today->toDateString(),
        ];
    }

    private function normalize(array $a): array
    {
        $str = fn ($v, int $max = 600) => is_scalar($v) && trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, $max) : null;
        $list = fn ($v, int $max = 12) => array_values(array_slice(array_filter(array_map(fn ($x) => $str($x), is_array($v) ? $v : [])), 0, $max));
        $diet = is_array($a['diet'] ?? null) ? $a['diet'] : [];
        $sample = is_array($diet['sample_day'] ?? null) ? $diet['sample_day'] : [];
        // AI hay ghi kèm tên thứ vào món ("Thứ Hai — …"): bỏ đi, màn hình đã có tên thứ.
        $dish = fn ($v) => $str(is_string($v) ? preg_replace('/^\s*(Thứ\s+\S+|Chủ\s+nhật|T[2-7]|CN)\s*[—–:\-]\s*/iu', '', $v) : $v);
        $meals = fn (array $d) => [
            'breakfast' => $dish($d['breakfast'] ?? null),
            'lunch' => $dish($d['lunch'] ?? null),
            'dinner' => $dish($d['dinner'] ?? null),
            'snacks' => $dish($d['snacks'] ?? null),
        ];
        // Thực đơn 7 ngày (Thứ Hai → Chủ nhật); bỏ ngày trống, thiếu ngày thì menuFor() dùng thực đơn mẫu.
        $weekly = array_map($meals, array_slice(array_values(array_filter($diet['weekly_menu'] ?? [], 'is_array')), 0, 7));
        $weekly = array_values(array_filter($weekly, fn ($d) => array_filter($d) !== []));

        return [
            'summary' => $str($a['summary'] ?? null, 1200),
            'key_issues' => array_values(array_filter(array_map(fn ($i) => is_array($i) && $str($i['title'] ?? null) ? [
                'title' => $str($i['title']),
                'detail' => $str($i['detail'] ?? null),
                'priority' => ($i['priority'] ?? null) === 'high' ? 'high' : 'normal',
                'based_on' => $str($i['based_on'] ?? null),
            ] : null, array_slice($a['key_issues'] ?? [], 0, 10)))),
            'diet' => [
                'principles' => $list($diet['principles'] ?? []),
                'eat_more' => $list($diet['eat_more'] ?? []),
                'limit' => $list($diet['limit'] ?? []),
                'avoid' => $list($diet['avoid'] ?? []),
                'drug_food_notes' => $list($diet['drug_food_notes'] ?? []),
                'sample_day' => $meals($sample),
                'weekly_menu' => count($weekly) === 7 ? $weekly : [],
            ],
            'lifestyle' => $list($a['lifestyle'] ?? []),
            // Chỉ nhận bài có trong thư viện; bỏ trùng, tối đa 5 bài.
            'exercises' => array_values(array_slice(array_column(array_filter(array_map(fn ($e) => is_array($e) && is_string($e['id'] ?? null) && ExerciseLibrary::exists($e['id']) ? [
                'id' => $e['id'],
                'why' => $str($e['why'] ?? null, 300),
                'frequency' => $str($e['frequency'] ?? null, 120),
            ] : null, is_array($a['exercises'] ?? null) ? $a['exercises'] : [])), null, 'id'), 0, 5)),
            'monitoring' => array_values(array_filter(array_map(fn ($m) => is_array($m) && $str($m['what'] ?? null) ? [
                'what' => $str($m['what']),
                'how_often' => $str($m['how_often'] ?? null),
                'target' => $str($m['target'] ?? null),
            ] : null, array_slice($a['monitoring'] ?? [], 0, 10)))),
            'medication_notes' => $list($a['medication_notes'] ?? []),
            'warning_signs' => $list($a['warning_signs'] ?? []),
            'follow_up' => $list($a['follow_up'] ?? []),
            'questions_for_doctor' => $list($a['questions_for_doctor'] ?? []),
        ];
    }
}
