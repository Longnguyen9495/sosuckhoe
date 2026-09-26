<?php

namespace App\Services\Template;

use App\Models\ConditionTemplate;
use App\Models\MonitoringPlan;
use App\Models\PatientCondition;
use App\Models\PatientThreshold;
use App\Models\Question;

class TemplateCloner
{
    /**
     * Gán bệnh nền từ mẫu → chép ngưỡng, lịch đo, câu hỏi gợi ý cho bệnh nhân.
     */
    public function cloneForPatient(string $tenantId, string $patientId, string $templateId, ?string $userId = null): void
    {
        $template = ConditionTemplate::findOrFail($templateId);

        // Gán bệnh nền
        PatientCondition::create([
            'tenant_id' => $tenantId,
            'patient_id' => $patientId,
            'condition_template_id' => $template->id,
        ]);

        // Chép ngưỡng (source=template, chưa xác nhận)
        foreach ($template->thresholds as $tt) {
            PatientThreshold::create([
                'tenant_id' => $tenantId,
                'patient_id' => $patientId,
                'metric' => $tt->metric,
                'context' => $tt->context,
                'ranges' => $tt->ranges,
                'source' => 'template',
                'confirmed_by' => null,
                'confirmed_at' => null,
            ]);
        }

        // Chép lịch đo: các giai đoạn nối tiếp nhau theo duration_days (giai đoạn cuối để ngỏ).
        $templateMetrics = json_decode((string) $template->metrics, true) ?: [];
        $startsAt = now()->startOfDay();
        foreach ($template->monitoringPlans()->orderBy('phase')->get() as $mp) {
            $schedule = is_array($mp->schedule) ? $mp->schedule : (json_decode((string) $mp->schedule, true) ?? []);
            $endsAt = $mp->duration_days ? $startsAt->copy()->addDays($mp->duration_days - 1) : null;
            MonitoringPlan::create([
                'tenant_id' => $tenantId,
                'patient_id' => $patientId,
                'metric' => $schedule['metric'] ?? $templateMetrics[0] ?? 'blood_glucose',
                'phase' => $mp->phase,
                'starts_at' => $startsAt->toDateString(),
                'ends_at' => $endsAt?->toDateString(),
                'schedule' => $schedule,
            ]);
            if ($endsAt === null) {
                break;
            }
            $startsAt = $endsAt->copy()->addDay();
        }

        // Câu hỏi gợi ý (nếu có bài viết hướng dẫn)
        foreach ($template->articles()->where('type', 'faq')->get() as $article) {
            Question::create([
                'tenant_id' => $tenantId,
                'patient_id' => $patientId,
                'asked_by' => $userId,
                'question' => $article->title,
                'asked_at' => null, // chưa hỏi thật, chỉ là gợi ý
            ]);
        }
    }
}
