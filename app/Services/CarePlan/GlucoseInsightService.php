<?php

namespace App\Services\CarePlan;

use App\Contracts\MedicalAiClient;
use App\Models\GlucoseNote;
use App\Models\LabResult;
use App\Models\Patient;
use App\Models\PatientThreshold;
use App\Models\Reading;
use App\Services\Dedup\DuplicateMatcher;
use App\Services\Privacy\SensitiveDataScrubber;
use App\Services\Schedule\DayPlanBuilder;
use App\Services\Schedule\MeasurementPoints;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Nhận xét đường huyết của một ngày.
 * Code tính mọi con số và điều phát hiện được (so mục tiêu, so 7 ngày trước, đối chiếu HbA1c, thuốc chưa đánh dấu);
 * AI chỉ viết lời nhận xét từ các số đó. AI lỗi / trả lời không an toàn → nhận xét viết sẵn theo quy tắc.
 */
final class GlucoseInsightService
{
    public const DISCLAIMER = 'Nhận xét tự động từ số đo tại nhà — chỉ để tham khảo, không thay lời bác sĩ.';

    private const TZ = 'Asia/Ho_Chi_Minh';

    /** Dưới ngưỡng này là hạ đường huyết, bất kể ngưỡng riêng. */
    private const HYPO = 3.9;

    /** AI lỗi lần trước: sau bao nhiêu phút mới thử gọi lại với cùng dữ liệu. */
    private const RETRY_AI_MINUTES = 10;

    /**
     * Câu khuyên tự đổi thuốc / liều: loại bỏ dù AI đã được dặn không viết.
     * Dùng (?<!\pL) / (?!\pL) thay \b vì \b không nhận chữ có dấu ("đổi", "đơn vị").
     * Không chặn "viên": lời dặn hạ đường huyết có "3–4 viên đường".
     */
    private const UNSAFE = [
        '/(?<!\pL)(tăng|giảm|bớt|thêm|ngừng|dừng|bỏ|đổi|chỉnh|gấp đôi)(?!\pL)[^.;!?]{0,25}(?<!\pL)(liều|thuốc|insulin)(?!\pL)/iu',
        '/(?<![\pL\d])\d+([.,]\d+)?\s*(đơn vị|ui|iu)(?!\pL)/iu',
    ];

    public function __construct(
        private readonly MedicalAiClient $ai,
        private readonly SensitiveDataScrubber $scrubber,
        private readonly CarePlanService $carePlans,
        private readonly DailyPlanService $dailyPlans,
        private readonly DayPlanBuilder $dayPlan,
    ) {}

    /** Số liệu + nhận xét đã lưu (nếu còn khớp dữ liệu). Không gọi AI. */
    public function show(Patient $patient, CarbonImmutable $day): array
    {
        $stats = $this->stats($patient, $day);
        $row = $this->row($patient, $day);
        $fresh = $row !== null && $row->input_hash === $stats['hash'];

        return $this->present($stats, $fresh ? $row : null, $stats['today']['count'] > 0 && (! $fresh || $this->canRetry($row)));
    }

    /** Viết (hoặc viết lại khi có số đo mới) nhận xét bằng AI; AI lỗi thì lưu nhận xét theo quy tắc. */
    public function generate(Patient $patient, CarbonImmutable $day): array
    {
        $stats = $this->stats($patient, $day);
        if ($stats['today']['count'] === 0) {
            return $this->present($stats, null, false);
        }
        $row = $this->row($patient, $day);
        if ($row !== null && $row->input_hash === $stats['hash'] && ! $this->canRetry($row)) {
            return $this->present($stats, $row, false);
        }

        $content = null;
        try {
            [$raw] = $this->scrubber->scrub($this->ai->generateGlucoseNote($this->aiContext($patient, $day, $stats)));
            $content = $this->clean($raw);
            if ($content === null) {
                throw new RuntimeException('AI trả nhận xét rỗng hoặc không an toàn.');
            }
        } catch (Throwable $e) {
            Log::warning('Glucose note generation failed', ['patient' => $patient->id, 'error' => class_basename($e)]);
        }

        $row ??= new GlucoseNote(['patient_id' => $patient->id, 'note_date' => $day->toDateString()]);
        $row->fill([
            'input_hash' => $stats['hash'],
            'content' => $content ?? $this->ruleNote($stats),
            'source' => $content !== null ? 'ai' : 'rule',
            'model' => $content !== null ? $this->ai->model() : null,
        ])->save();

        return $this->present($stats, $row, false);
    }

    /* ================= Số liệu ================= */

    /** @return array<string, mixed> */
    public function stats(Patient $patient, CarbonImmutable $day): array
    {
        $date = $day->toDateString();
        $from = $day->subDays(7)->toDateString();
        $all = Reading::where('patient_id', $patient->id)
            ->where('type', 'blood_glucose')
            ->where('measured_at', '>=', $from.' 00:00:00')
            ->where('measured_at', '<=', $date.' 23:59:59')
            ->orderBy('measured_at')
            ->get();
        $targets = $this->targets($patient);
        $row = fn (Reading $r) => $this->presentReading($r, $targets);
        $today = $all->filter(fn (Reading $r) => $r->measured_at->toDateString() === $date)->map($row)->values();
        $before = $all->filter(fn (Reading $r) => $r->measured_at->toDateString() < $date)->map($row)->values();

        // Cùng thời điểm đo: trung bình 7 ngày trước (cần ít nhất 2 lần) so với lần đo hôm nay.
        $compare = $today->groupBy('point')->map(function (Collection $list, string $point) use ($before) {
            $prev = $before->where('point', $point)->pluck('value');

            return $prev->count() < 2 ? null : [
                'point' => $point,
                'label' => $list->last()['label'],
                'today' => $list->last()['value'],
                'avg_7_days' => round($prev->avg(), 1),
                'diff' => round($list->last()['value'] - $prev->avg(), 1),
            ];
        })->filter()->values();

        // Lúc đói vượt mục tiêu bao nhiêu ngày liền, tính lùi từ hôm nay (hôm nay chưa đo thì từ hôm qua).
        $fastingByDay = $today->concat($before)->where('point', 'fasting')->groupBy('date')->map(fn (Collection $l) => $l->last());
        $streak = 0;
        for ($d = $fastingByDay->has($date) ? $day : $day->subDay(); $d->toDateString() >= $from; $d = $d->subDay()) {
            if (($fastingByDay[$d->toDateString()]['status'] ?? null) !== 'high') {
                break;
            }
            $streak++;
        }

        $window = $today->concat($before);
        $hba1c = $this->hba1c($patient, $day);
        $meds = $this->medsToday($patient, $day);

        $stats = [
            'date' => $date,
            'targets' => $targets,
            'today' => [
                'count' => $today->count(),
                'in_target' => $today->where('status', 'ok')->count(),
                'high' => $today->where('status', 'high')->count(),
                'low' => $today->whereIn('status', ['low', 'hypo'])->count(),
                'hypo' => $today->where('status', 'hypo')->count(),
                'min' => $today->min('value'),
                'max' => $today->max('value'),
                'readings' => $today->all(),
            ],
            'last_7_days' => [
                'count' => $before->count(),
                'fasting_avg' => $before->where('point', 'fasting')->count() >= 2 ? round($before->where('point', 'fasting')->avg('value'), 1) : null,
                'same_point' => $compare->all(),
                'fasting_high_streak_days' => $streak,
                'hypo_count' => $window->where('status', 'hypo')->count(),
                'home_avg_8_days' => $window->count() >= 5 ? round($window->avg('value'), 1) : null,
            ],
            'hba1c' => $hba1c,
            'meds_today' => $meds,
        ];
        $stats['findings'] = $this->findings($stats);
        // Dấu dữ liệu đầu vào: số đo mới / sửa, xét nghiệm mới, đánh dấu thuốc → viết lại nhận xét.
        $stats['hash'] = sha1(json_encode([
            $window->map(fn ($r) => [$r['id'], $r['value'], $r['point'], $r['time']])->all(),
            $hba1c,
            $meds['done_keys'],
        ]));

        return $stats;
    }

    /** Mục tiêu theo ngưỡng riêng của người bệnh (mặc định theo mẫu bệnh). */
    private function targets(Patient $patient): array
    {
        $ranges = PatientThreshold::where('patient_id', $patient->id)->where('metric', 'blood_glucose')->get()->keyBy('context')
            ->map(fn (PatientThreshold $t) => is_array($t->ranges) ? $t->ranges : (json_decode((string) $t->ranges, true) ?: []));
        $pre = $ranges['pre_meal'] ?? [];
        $post = $ranges['post_meal_2h'] ?? [];

        return [
            'pre_meal' => ['min' => (float) ($pre['target'][0] ?? 4.4), 'max' => (float) ($pre['target'][1] ?? 7.2)],
            'post_meal_2h' => ['min' => self::HYPO, 'max' => (float) ($post['target_below'] ?? $post['target'][1] ?? 10)],
        ];
    }

    private function presentReading(Reading $r, array $targets): array
    {
        $value = (float) ($r->values['value'] ?? 0);
        $context = MeasurementPoints::thresholdContext('blood_glucose', $r->context);
        $target = $targets[$context] ?? $targets['pre_meal'];
        $status = match (true) {
            $value < self::HYPO => 'hypo',
            $value < $target['min'] => 'low',
            $value > $target['max'] => 'high',
            default => 'ok',
        };

        return [
            'id' => $r->id,
            'date' => $r->measured_at->toDateString(),
            'time' => $r->measured_at->format('H:i'),
            'point' => (string) $r->context,
            'label' => MeasurementPoints::POINTS[$r->context]['short'] ?? 'Đường huyết',
            'value' => round($value, 1),
            'status' => $status,
            'target' => $target,
        ];
    }

    /** HbA1c gần nhất (đến hết ngày đang xem), quy ra đường huyết trung bình ước tính (eAG, công thức ADAG). */
    private function hba1c(Patient $patient, CarbonImmutable $day): ?array
    {
        $lab = LabResult::where('patient_id', $patient->id)
            ->where('measured_at', '<=', $day->toDateString().' 23:59:59')
            ->orderByDesc('measured_at')
            ->get()
            ->first(fn (LabResult $l) => str_contains(DuplicateMatcher::labName($l->metric), 'a1c'));
        if ($lab === null || ! preg_match('/\d+(?:[.,]\d+)?/', (string) $lab->value, $m)) {
            return null;
        }
        $value = (float) str_replace(',', '.', $m[0]);
        if ($value < 3 || $value > 20) {
            return null;
        }
        $measured = CarbonImmutable::parse($lab->measured_at);

        return [
            'value' => $value,
            'date' => $measured->toDateString(),
            'days_ago' => (int) $measured->startOfDay()->diffInDays($day->startOfDay()),
            'estimated_avg_glucose' => round((28.7 * $value - 46.7) / 18, 1),
        ];
    }

    /** Thuốc / insulin trong ngày: đã đánh dấu bao nhiêu, liều nào đã qua giờ mà chưa đánh dấu. */
    private function medsToday(Patient $patient, CarbonImmutable $day): array
    {
        $now = CarbonImmutable::now(self::TZ);
        $past = $day->toDateString() < $now->toDateString();
        $items = collect($this->dayPlan->build($patient, $day)['items'] ?? [])
            ->filter(fn (array $i) => in_array($i['type'], ['medication', 'insulin'], true));
        $due = $items->filter(fn (array $i) => $past || ($day->toDateString() === $now->toDateString() && $i['time'] <= $now->format('H:i')));

        return [
            'total' => $items->count(),
            'done' => $items->where('done', true)->count(),
            'missed' => $due->where('done', false)->map(fn (array $i) => $i['time'].' '.$i['title'])->values()->all(),
            'done_keys' => $items->where('done', true)->pluck('key')->values()->all(),
        ];
    }

    /** Điều phát hiện được từ số liệu — nền cho lời nhận xét (AI) và nhận xét dự phòng. */
    private function findings(array $s): array
    {
        $f = [];
        $t = $s['today'];
        $readings = collect($t['readings']);
        $v = fn (?float $x) => $x === null ? '—' : number_format($x, 1, ',', '');

        if ($t['hypo'] > 0) {
            $f[] = ['tone' => 'bad', 'text' => "Có {$t['hypo']} lần dưới 3,9 (thấp nhất {$v($t['min'])}): hạ đường huyết — ăn ngay 15 g đường nhanh, đo lại sau 15 phút và báo bác sĩ."];
        }
        foreach ($readings->where('status', 'high') as $r) {
            $f[] = ['tone' => 'warn', 'text' => "{$r['label']} lúc {$r['time']}: {$v($r['value'])} — cao hơn mục tiêu (tối đa {$v($r['target']['max'])})."];
        }
        foreach ($readings->where('status', 'low') as $r) {
            $f[] = ['tone' => 'warn', 'text' => "{$r['label']} lúc {$r['time']}: {$v($r['value'])} — hơi thấp so với mục tiêu (từ {$v($r['target']['min'])})."];
        }
        if ($t['count'] > 0 && $t['in_target'] === $t['count']) {
            $f[] = ['tone' => 'good', 'text' => "Cả {$t['count']} lần đo hôm nay đều trong mục tiêu."];
        }
        foreach ($s['last_7_days']['same_point'] as $c) {
            if (abs($c['diff']) >= 0.5) {
                $f[] = $c['diff'] < 0
                    ? ['tone' => 'good', 'text' => "{$c['label']} hôm nay {$v($c['today'])}, thấp hơn trung bình 7 ngày trước ({$v($c['avg_7_days'])})."]
                    : ['tone' => 'warn', 'text' => "{$c['label']} hôm nay {$v($c['today'])}, cao hơn trung bình 7 ngày trước ({$v($c['avg_7_days'])})."];
            }
        }
        $streak = $s['last_7_days']['fasting_high_streak_days'];
        if ($streak >= 3) {
            $f[] = ['tone' => 'bad', 'text' => "Đường huyết lúc đói vượt mục tiêu {$streak} ngày liền — nên báo bác sĩ điều trị."];
        }
        if ($s['last_7_days']['hypo_count'] >= 2) {
            $f[] = ['tone' => 'bad', 'text' => "{$s['last_7_days']['hypo_count']} lần hạ đường huyết (dưới 3,9) trong 8 ngày qua — cần báo bác sĩ sớm."];
        }
        if ($h = $s['hba1c']) {
            $avg = $s['last_7_days']['home_avg_8_days'];
            $a1c = "HbA1c {$v($h['value'])}% (ngày ".CarbonImmutable::parse($h['date'])->format('d/m/Y').", tương đương trung bình khoảng {$v($h['estimated_avg_glucose'])} mmol/L)";
            if ($avg !== null && $avg < $h['estimated_avg_glucose'] - 1.5) {
                $f[] = ['tone' => 'info', 'text' => "{$a1c} cao hơn trung bình số đo tại nhà ({$v($avg)}): có thể đang đo thiếu lúc 2 giờ sau ăn — nên đo thêm thời điểm này."];
            } elseif ($avg !== null && $avg > $h['estimated_avg_glucose'] + 1.5) {
                $f[] = ['tone' => 'warn', 'text' => "Trung bình số đo tại nhà ({$v($avg)}) cao hơn mức {$a1c} — đường huyết có thể đang tăng, nên báo bác sĩ."];
            } elseif ($avg !== null) {
                $f[] = ['tone' => 'info', 'text' => "Số đo tại nhà (trung bình {$v($avg)}) phù hợp với {$a1c}."];
            }
            if ($h['days_ago'] > 100) {
                $f[] = ['tone' => 'info', 'text' => 'HbA1c gần nhất đã hơn 3 tháng — hỏi bác sĩ lịch xét nghiệm lại.'];
            }
        }
        if ($s['meds_today']['missed'] !== []) {
            $f[] = ['tone' => 'warn', 'text' => 'Chưa đánh dấu đã dùng: '.implode(', ', array_slice($s['meds_today']['missed'], 0, 3)).'.'];
        }

        return $f;
    }

    /* ================= Lời nhận xét ================= */

    /** Dữ liệu gửi AI: số đã tính + bệnh, thuốc, chế độ ăn, thực đơn hôm nay. Không có tên, ngày sinh hay định danh. */
    private function aiContext(Patient $patient, CarbonImmutable $day, array $stats): array
    {
        $ctx = $this->carePlans->context($patient);
        $diet = $this->carePlans->latest($patient)?->content['diet'] ?? [];
        $menu = $this->dailyPlans->forDay($patient, $day)['menu'] ?? null;

        return [
            'date' => $stats['date'],
            'age' => $ctx['age'],
            'diagnoses' => $ctx['diagnoses'],
            'medications' => array_map(fn (array $m) => ['drug' => $m['drug'], 'type' => $m['type'], 'how_to_use' => $m['how_to_use']], $ctx['medications']),
            'targets_mmol_l' => $stats['targets'],
            'today' => array_merge($stats['today'], ['readings' => array_map(fn (array $r) => array_intersect_key($r, array_flip(['time', 'label', 'value', 'status'])), $stats['today']['readings'])]),
            'last_7_days' => $stats['last_7_days'],
            'hba1c' => $stats['hba1c'],
            'meds_today' => array_diff_key($stats['meds_today'], ['done_keys' => true]),
            'findings' => array_column($stats['findings'], 'text'),
            'diet' => array_intersect_key($diet, array_flip(['eat_more', 'limit', 'avoid', 'drug_food_notes'])),
            'menu_today' => $menu,
            'doctor_advice' => $ctx['doctor_advice'],
        ];
    }

    /** Kiểm lại câu trả lời AI: cắt độ dài, bỏ ý khuyên đổi thuốc / liều; phần chính không an toàn → null (dùng nhận xét quy tắc). */
    private function clean(array $raw): ?array
    {
        $text = fn ($v, int $max) => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $max) : null;
        $unsafe = fn (string $s) => collect(self::UNSAFE)->contains(fn (string $re) => preg_match($re, $s) === 1);

        $summary = $text($raw['summary'] ?? null, 400);
        if ($summary !== null && $unsafe($summary)) {
            return null;
        }
        $points = collect(is_array($raw['points'] ?? null) ? $raw['points'] : [])
            ->map(fn ($p) => is_array($p) ? ['tone' => in_array($p['tone'] ?? null, ['good', 'warn', 'bad', 'info'], true) ? $p['tone'] : 'info', 'text' => $text($p['text'] ?? null, 240)] : null)
            ->filter(fn ($p) => $p !== null && $p['text'] !== null && ! $unsafe($p['text']))
            ->take(4)->values()->all();
        if ($summary === null && $points === []) {
            return null;
        }

        // Câu hỏi bác sĩ được phép nhắc tới liều ("có cần chỉnh liều không?") — đó là hỏi, không phải khuyên.
        return ['summary' => $summary, 'points' => $points, 'ask_doctor' => $text($raw['ask_doctor'] ?? null, 200)];
    }

    /** Nhận xét viết sẵn khi không có AI. */
    public function ruleNote(array $s): array
    {
        $t = $s['today'];
        if ($t['count'] === 0) {
            return ['summary' => 'Hôm nay chưa đo đường huyết.', 'points' => array_slice($s['findings'], 0, 4), 'ask_doctor' => null];
        }
        $parts = array_filter([
            "{$t['in_target']} lần trong mục tiêu",
            $t['high'] ? "{$t['high']} lần cao" : null,
            $t['low'] ? "{$t['low']} lần thấp" : null,
        ]);
        $serious = $t['hypo'] > 0 || $s['last_7_days']['fasting_high_streak_days'] >= 3 || $s['last_7_days']['hypo_count'] >= 2;

        return [
            'summary' => "Hôm nay đo {$t['count']} lần: ".implode(', ', $parts).'.',
            'points' => array_slice($s['findings'], 0, 4),
            'ask_doctor' => $serious ? 'Đường huyết gần đây như vậy có cần điều chỉnh điều trị không?' : null,
        ];
    }

    /* ================= Lưu / trả về ================= */

    private function row(Patient $patient, CarbonImmutable $day): ?GlucoseNote
    {
        // whereDate: SQLite lưu cột date dạng "YYYY-MM-DD 00:00:00" (xem DailyPlanService::generate).
        return GlucoseNote::where('patient_id', $patient->id)->whereDate('note_date', $day->toDateString())->first();
    }

    private function canRetry(?GlucoseNote $row): bool
    {
        return $row !== null && $row->source === 'rule' && (bool) $row->updated_at?->lt(now()->subMinutes(self::RETRY_AI_MINUTES));
    }

    private function present(array $stats, ?GlucoseNote $row, bool $needsAi): array
    {
        return [
            'date' => $stats['date'],
            'stats' => array_diff_key($stats, ['hash' => true, 'findings' => true]),
            'note' => $row === null ? null : $row->content + [
                'source' => $row->source,
                'model' => $row->model,
                'updated_at' => $row->updated_at?->toIso8601String(),
            ],
            // Hiện ngay trong lúc chờ AI (hoặc khi AI lỗi): viết theo quy tắc từ đúng số liệu hiện tại.
            'fallback' => $this->ruleNote($stats),
            'needs_ai' => $needsAi,
            'disclaimer' => self::DISCLAIMER,
        ];
    }
}
