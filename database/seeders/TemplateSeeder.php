<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TemplateSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $templates = [
                'diabetes_insulin' => ['Tiểu đường có insulin', ['blood_glucose'], ['phase_1' => ['daily_points' => 4], 'phase_2' => ['daily_points' => 2, 'weekly_full_day_points' => 6], 'phase_3' => ['fasting_daily' => true, 'pre_dinner_weekdays' => [1, 3, 5]]]],
                'diabetes_oral' => ['Tiểu đường chỉ uống thuốc', ['blood_glucose'], ['fasting_daily' => true, 'weekly_full_day_points' => 4]],
                'hypertension' => ['Tăng huyết áp', ['blood_pressure', 'heart_rate'], ['times' => ['morning', 'evening']]],
                'hepatitis_b_treatment' => ['Viêm gan B đang điều trị', [], ['fixed_daily_medication_reminder' => true, 'lab_interval_months' => [3, 6]]],
                'lipid_uric_acid' => ['Rối loạn mỡ máu, tăng acid uric', [], ['lab_reminder' => true]],
            ];

            foreach ($templates as $code => [$name, $metrics, $schedule]) {
                $templateId = $this->stableId('condition_templates', ['code' => $code], [
                    'icd_code' => null, 'name' => $name, 'metrics' => json_encode($metrics, JSON_UNESCAPED_UNICODE), 'version' => 1,
                ]);
                // Lịch đo dạng tường minh (điểm đo theo ngày / thứ) — xem App\Services\Schedule\MeasurementPoints.
                DB::table('template_monitoring')->where('condition_template_id', $templateId)->where('phase', 'default')->delete();
                foreach (self::monitoringPhases()[$code] ?? [] as $phase => [$duration, $phaseSchedule]) {
                    $this->stableId('template_monitoring', ['condition_template_id' => $templateId, 'phase' => $phase], [
                        'duration_days' => $duration, 'schedule' => json_encode($phaseSchedule, JSON_UNESCAPED_UNICODE),
                    ]);
                }
            }

            $this->seedThreshold('diabetes_insulin', 'blood_glucose', 'pre_meal', ['target' => [4.4, 7.2], 'attention' => [[3.9, 4.4], [7.2, 10]], 'red' => ['below' => 3.9, 'above' => 10, 'critical_above' => 16.7]]);
            $this->seedThreshold('diabetes_insulin', 'blood_glucose', 'post_meal_2h', ['target_below' => 10, 'attention' => [10, 16.7], 'red' => ['below' => 3.9, 'above' => 16.7]]);
            $this->seedThreshold('diabetes_oral', 'blood_glucose', 'pre_meal', ['target' => [4.4, 7.2], 'attention' => [[3.9, 4.4], [7.2, 10]], 'red' => ['below' => 3.9, 'above' => 10, 'critical_above' => 16.7]]);
            $this->seedThreshold('diabetes_oral', 'blood_glucose', 'post_meal_2h', ['target_below' => 10, 'attention' => [10, 16.7], 'red' => ['below' => 3.9, 'above' => 16.7]]);
            $this->seedThreshold('hypertension', 'blood_pressure', 'general', ['target_below' => ['systolic' => 130, 'diastolic' => 80], 'red_at_or_above' => ['systolic' => 160, 'diastolic' => 100], 'red_below' => ['systolic' => 90, 'diastolic' => 60]]);
            $this->seedThreshold('hypertension', 'heart_rate', 'resting', ['target' => [60, 100], 'attention' => [100, 120], 'red' => ['below' => 50, 'above' => 120]]);

            $articles = [
                ['hospital_diabetes_diet_1', '[NHÁP — CẦN DUYỆT] Hướng dẫn chế độ ăn — Đái tháo đường (1)', 'Tài liệu nguồn bệnh viện: hạn chế đường, bánh kẹo, nước ngọt; chia bữa và cố định giờ ăn. Nội dung phải được bác sĩ/pháp chế duyệt trước khi phát hành.', 'diabetes_insulin'],
                ['hospital_diabetes_diet_2', '[NHÁP — CẦN DUYỆT] Hướng dẫn chế độ ăn — Đái tháo đường (2)', 'Tài liệu nguồn bệnh viện: thông tin tham khảo về trái cây, tinh bột, chất béo, đạm và bữa ăn. Không dùng làm chỉ định cá nhân khi chưa được bác sĩ duyệt.', 'diabetes_oral'],
                ['hospital_uric_diet', '[NHÁP — CẦN DUYỆT] Hướng dẫn chế độ ăn giảm acid uric / gút', 'Tài liệu nguồn bệnh viện: thông tin tham khảo về nước uống và thực phẩm cần hạn chế. Lượng nước và chế độ ăn phải được bác sĩ cá nhân hóa.', 'lipid_uric_acid'],
            ];
            foreach ($articles as [$type, $title, $content, $templateCode]) {
                $templateId = DB::table('condition_templates')->where('code', $templateCode)->value('id');
                $this->stableId('content_articles', ['type' => $type, 'title' => $title], [
                    'condition_template_id' => $templateId, 'content' => '[NHÁP — CẦN DUYỆT] '.$content, 'is_draft' => true,
                ]);
            }
        });
    }

    /** Giai đoạn đo mặc định theo mẫu bệnh: [số ngày (null = để ngỏ), lịch]. */
    public static function monitoringPhases(): array
    {
        return [
            'diabetes_insulin' => [
                'phase_1' => [14, ['metric' => 'blood_glucose', 'label' => 'Giai đoạn 1 · Tăng cường', 'description' => 'Đo 4 lần/ngày: lúc đói, trước trưa, trước tối, trước ngủ.', 'daily' => ['fasting', 'pre_lunch', 'pre_dinner', 'bedtime']]],
                'phase_2' => [16, ['metric' => 'blood_glucose', 'label' => 'Giai đoạn 2 · Ổn định', 'description' => 'Lúc đói và trước tối mỗi ngày; Chủ nhật đo đủ 6 điểm.', 'daily' => ['fasting', 'pre_dinner'], 'weekdays' => ['7' => ['post_breakfast', 'pre_lunch', 'post_lunch', 'post_dinner']]]],
                'phase_3' => [null, ['metric' => 'blood_glucose', 'label' => 'Giai đoạn 3 · Duy trì', 'description' => 'Lúc đói mỗi ngày, trước tối thứ 2/4/6.', 'daily' => ['fasting'], 'weekdays' => ['1' => ['pre_dinner'], '3' => ['pre_dinner'], '5' => ['pre_dinner']]]],
            ],
            'diabetes_oral' => [
                'phase_1' => [null, ['metric' => 'blood_glucose', 'label' => 'Theo dõi đường huyết', 'description' => 'Lúc đói mỗi ngày; Chủ nhật đo thêm 3 điểm.', 'daily' => ['fasting'], 'weekdays' => ['7' => ['post_breakfast', 'pre_dinner', 'post_dinner']]]],
            ],
            'hypertension' => [
                'phase_1' => [null, ['metric' => 'blood_pressure', 'label' => 'Huyết áp sáng và tối', 'description' => 'Đo huyết áp + mạch buổi sáng và buổi tối.', 'daily' => ['bp_morning', 'bp_evening']]],
            ],
        ];
    }

    private function seedThreshold(string $templateCode, string $metric, string $context, array $ranges): void
    {
        $templateId = DB::table('condition_templates')->where('code', $templateCode)->value('id');
        $this->stableId('template_thresholds', ['condition_template_id' => $templateId, 'metric' => $metric, 'context' => $context], [
            'ranges' => json_encode($ranges, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function stableId(string $table, array $identity, array $values): string
    {
        $id = DB::table($table)->where($identity)->value('id');
        if ($id !== null) {
            DB::table($table)->where('id', $id)->update([...$values, 'updated_at' => now()]);
            return $id;
        }

        $id = (string) Str::ulid();
        DB::table($table)->insert([...$identity, ...$values, 'id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }
}
