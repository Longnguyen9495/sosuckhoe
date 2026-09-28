<?php

namespace App\Services\CarePlan;

use App\Contracts\MedicalAiClient;
use App\Models\CarePlan;
use App\Models\DailyPlan;
use App\Models\Patient;
use App\Services\Privacy\SensitiveDataScrubber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thực đơn + bài tập thay đổi mỗi ngày (lệnh `careplan:daily` chạy mỗi sáng).
 *  - Thực đơn: AI lên món cho đúng ngày đó, bám nguyên tắc ăn uống của kế hoạch chăm sóc, tránh lặp món 7 ngày gần nhất.
 *    AI lỗi → dùng thực đơn 7 ngày của kế hoạch.
 *  - Bài tập: luân phiên 2–3 bài mỗi ngày trong nhóm bài hợp với người bệnh (kế hoạch + thư viện), không cần AI.
 * Ngày chưa có dòng nào (cron chưa chạy) thì forDay() tự tính theo thực đơn 7 ngày + luân phiên, không gọi AI.
 */
final class DailyPlanService
{
    /** Bài kiến thức, không phải bài tập — hiện riêng, không đưa vào vòng luân phiên. */
    public const TIPS_ID = 'insulin_exercise_tips';

    public function __construct(
        private readonly CarePlanService $carePlans,
        private readonly MedicalAiClient $ai,
        private readonly SensitiveDataScrubber $scrubber,
    ) {}

    /**
     * Kế hoạch của một ngày để hiển thị.
     *
     * @return array{menu: array, exercises: list<array>, insulin_tips: ?array, tip: ?string, source: string}|null  null khi chưa có kế hoạch chăm sóc
     */
    public function forDay(Patient $patient, CarbonImmutable $day, ?CarePlan $plan = null): ?array
    {
        $plan ??= $this->carePlans->latest($patient);
        if ($plan === null) {
            return null;
        }
        $row = DailyPlan::where('patient_id', $patient->id)->whereDate('plan_date', $day->toDateString())->first();
        $ids = $row?->exercise_ids ?? $this->rotation($plan, $patient, $day);
        $withReasons = collect($this->carePlans->exercises($plan, $patient))->keyBy('id');

        return [
            'menu' => $row?->menu ?? CarePlanService::menuFor($plan->content, $day),
            // Giữ lý do / tần suất AI ghi cho người bệnh này nếu bài có trong kế hoạch.
            'exercises' => array_values(array_map(fn (string $id) => $withReasons[$id] ?? ExerciseLibrary::present($id), array_filter($ids, [ExerciseLibrary::class, 'exists']))),
            'insulin_tips' => $withReasons[self::TIPS_ID] ?? null,
            'tip' => $row?->tip,
            'source' => $row?->source ?? 'weekly',
        ];
    }

    /** Lên kế hoạch cho một ngày (ghi đè nếu đã có). $useAi = false: chỉ luân phiên bài tập + thực đơn 7 ngày. */
    public function generate(Patient $patient, CarbonImmutable $day, bool $useAi = true): ?DailyPlan
    {
        $plan = $this->carePlans->latest($patient);
        if ($plan === null) {
            return null;
        }

        $menu = null;
        $tip = null;
        if ($useAi) {
            try {
                [$raw] = $this->scrubber->scrub($this->ai->generateDailyMenu($this->menuContext($patient, $plan, $day)));
                $menu = $this->cleanMenu($raw);
                $tip = is_string($raw['tip'] ?? null) ? mb_substr(trim($raw['tip']), 0, 300) : null;
                if (array_filter($menu) === []) {
                    $menu = null;
                }
            } catch (Throwable $e) {
                Log::warning('Daily menu generation failed', ['patient' => $patient->id, 'error' => class_basename($e)]);
            }
        }

        return DailyPlan::updateOrCreate(
            ['patient_id' => $patient->id, 'plan_date' => $day->toDateString()],
            [
                'care_plan_id' => $plan->id,
                'menu' => $menu ?? CarePlanService::menuFor($plan->content, $day),
                'exercise_ids' => $this->rotation($plan, $patient, $day),
                'tip' => $tip,
                'source' => $menu !== null ? 'ai' : 'weekly',
            ],
        );
    }

    /**
     * Luân phiên bài tập: nhóm bài = bài AI chọn trong kế hoạch, bổ sung bài hợp bệnh từ thư viện cho đủ đa dạng.
     * Mỗi ngày lấy 3 bài liên tiếp (2 nếu nhóm nhỏ), dịch theo số thứ tự ngày trong năm — hôm sau khác hôm trước.
     *
     * @return list<string>
     */
    public function rotation(CarePlan $plan, Patient $patient, CarbonImmutable $day): array
    {
        $pool = array_column($this->carePlans->exercises($plan, $patient), 'id');
        if (count(array_diff($pool, [self::TIPS_ID])) < 5) {
            $pool = array_merge($pool, array_column(ExerciseLibrary::suggest($this->carePlans->context($patient), 8), 'id'));
        }
        $pool = array_values(array_unique(array_diff($pool, [self::TIPS_ID])));
        if ($pool === []) {
            return [];
        }
        $take = min(count($pool) >= 5 ? 3 : 2, count($pool));
        $start = ($day->dayOfYear * $take) % count($pool);

        return array_map(fn (int $i) => $pool[($start + $i) % count($pool)], range(0, $take - 1));
    }

    /** Dữ liệu gửi AI để lên thực đơn một ngày — không tên, không SĐT. */
    public function menuContext(Patient $patient, CarePlan $plan, CarbonImmutable $day): array
    {
        $content = $plan->content ?? [];
        $diet = $content['diet'] ?? [];
        $recent = DailyPlan::where('patient_id', $patient->id)
            ->whereDate('plan_date', '<', $day->toDateString())
            ->whereDate('plan_date', '>=', $day->subDays(7)->toDateString())
            ->orderBy('plan_date')
            ->get()
            ->map(fn (DailyPlan $d) => ['date' => $d->plan_date->toDateString()] + $d->menu)
            ->values()->all();
        $ctx = $this->carePlans->context($patient);

        return [
            'date' => $day->toDateString(),
            'weekday' => ['', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy', 'Chủ nhật'][$day->dayOfWeekIso],
            'age' => $ctx['age'],
            'allergies' => $ctx['allergies'],
            'diagnoses' => $ctx['diagnoses'],
            'medications' => array_values(array_unique(array_map(fn ($m) => $m['drug'], $ctx['medications']))),
            'diet_principles' => $diet['principles'] ?? [],
            'eat_more' => $diet['eat_more'] ?? [],
            'limit' => $diet['limit'] ?? [],
            'avoid' => $diet['avoid'] ?? [],
            'drug_food_notes' => $diet['drug_food_notes'] ?? [],
            'recent_menus' => $recent,
        ];
    }

    /** @return array{breakfast: ?string, lunch: ?string, dinner: ?string, snacks: ?string} */
    private function cleanMenu(array $raw): array
    {
        $dish = fn ($v) => is_string($v) && trim($v) !== ''
            ? mb_substr(trim(preg_replace('/^\s*(Thứ\s+\S+|Chủ\s+nhật|T[2-7]|CN)\s*[—–:\-]\s*/iu', '', $v)), 0, 255)
            : null;

        return ['breakfast' => $dish($raw['breakfast'] ?? null), 'lunch' => $dish($raw['lunch'] ?? null), 'dinner' => $dish($raw['dinner'] ?? null), 'snacks' => $dish($raw['snacks'] ?? null)];
    }
}
