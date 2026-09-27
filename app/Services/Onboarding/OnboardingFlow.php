<?php

namespace App\Services\Onboarding;

use App\Models\Consent;
use App\Models\ConsentVersion;
use App\Models\OnboardingDraft;
use App\Models\Patient;
use App\Models\PatientAccess;
use App\Models\PatientRoutine;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Services\Schedule\PatientScheduleOrchestrator;
use App\Services\Template\TemplateCloner;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OnboardingFlow
{
    public const STEPS = [
        1 => 'account',
        2 => 'consent',
        3 => 'patient_profile',
        4 => 'routines',
        5 => 'prescriptions',
        6 => 'preview_schedule',
        7 => 'invite',
    ];

    public function getOrCreateDraft(string $userId): OnboardingDraft
    {
        $draft = OnboardingDraft::where('user_id', $userId)
            ->whereNull('completed_at')
            ->first();

        if ($draft) {
            return $draft;
        }

        return OnboardingDraft::create([
            'user_id' => $userId,
            'current_step' => 1,
            'data' => [],
        ]);
    }

    /** Luật kiểm tra dữ liệu từng bước (bước không có luật thì chỉ cần là mảng). */
    public static function rules(int $step): array
    {
        return match (self::STEPS[$step] ?? null) {
            'consent' => [
                'accepted' => ['required', 'accepted'],
                'consent_version_id' => ['required', 'string', 'exists:consent_versions,id'],
            ],
            'patient_profile' => [
                'full_name' => ['required', 'string', 'max:120'],
                'birth_year' => ['required', 'integer', 'min:1900', 'max:'.now()->year],
                'gender' => ['nullable', 'in:female,male,other,unknown'],
                'allergies' => ['nullable', 'string', 'max:500'],
                'condition_template_ids' => ['nullable', 'array'],
                'condition_template_ids.*' => ['string', 'exists:condition_templates,id'],
            ],
            'routines' => [
                'wake_time' => ['required', 'date_format:H:i'],
                'breakfast_time' => ['required', 'date_format:H:i'],
                'lunch_time' => ['required', 'date_format:H:i'],
                'dinner_time' => ['required', 'date_format:H:i'],
                'sleep_time' => ['required', 'date_format:H:i'],
            ],
            'prescriptions' => [
                '*.doctor_name' => ['nullable', 'string', 'max:255'],
                '*.prescribed_at' => ['nullable', 'date'],
                '*.starts_at' => ['nullable', 'date'],
                '*.items' => ['required', 'array', 'min:1'],
                '*.items.*.drug_id' => ['nullable', 'string', 'exists:drugs,id'],
                '*.items.*.drug_name' => ['required', 'string', 'max:255'],
                '*.items.*.dose_text' => ['required', 'string', 'max:500'],
                '*.items.*.usage_rule' => ['nullable', 'array'],
                '*.items.*.prescribed_quantity' => ['nullable', 'numeric', 'min:0'],
                '*.items.*.purchased_quantity' => ['nullable', 'numeric', 'min:0'],
                '*.items.*.quantity_unit' => ['nullable', 'string', 'max:60'],
                '*.items.*.is_long_term' => ['nullable', 'boolean'],
            ],
            default => [],
        };
    }

    public function saveStep(OnboardingDraft $draft, int $step, array $data): OnboardingDraft
    {
        $stepName = self::STEPS[$step];

        if (isset($data[$stepName]) && count($data) === 1) {
            $data = $data[$stepName];
        }

        $rules = self::rules($step);
        if ($rules !== []) {
            validator($data, $rules)->validate();
        }
        if ($stepName === 'consent') {
            $data['accepted_at'] = now()->toIso8601String();
        }

        $existing = $draft->data ?? [];
        $existing[$stepName] = $data;
        $draft->update(['current_step' => $step, 'data' => $existing]);

        return $draft->fresh();
    }

    public function goBack(OnboardingDraft $draft): OnboardingDraft
    {
        $prev = max(1, $draft->current_step - 1);
        $draft->update(['current_step' => $prev]);

        return $draft->fresh();
    }

    public function complete(OnboardingDraft $draft, PatientScheduleOrchestrator $orchestrator, ?string $ip = null): array
    {
        $data = $draft->data ?? [];

        // Không có đồng ý điều khoản thì không tạo hồ sơ sức khỏe.
        $consentVersionId = $data['consent']['consent_version_id'] ?? null;
        if (($data['consent']['accepted'] ?? false) !== true || $consentVersionId === null || ! ConsentVersion::whereKey($consentVersionId)->exists()) {
            throw ValidationException::withMessages(['consent' => 'Cần đồng ý điều khoản xử lý dữ liệu trước khi tạo hồ sơ.']);
        }
        if (empty($data['patient_profile']['full_name'])) {
            throw ValidationException::withMessages(['patient_profile' => 'Thiếu thông tin người bệnh.']);
        }

        return DB::transaction(function () use ($draft, $data, $orchestrator, $consentVersionId, $ip) {
            $profile = $data['patient_profile'];
            $today = CarbonImmutable::today();

            $tenant = Tenant::create([
                'name' => 'Gia đình '.$profile['full_name'],
                'type' => 'family',
            ]);

            $context = app(TenantContext::class);
            $context->set($tenant);

            TenantMember::create(['user_id' => $draft->user_id, 'role' => 'owner']);

            Consent::create([
                'user_id' => $draft->user_id,
                'consent_version_id' => $consentVersionId,
                'consented_at' => $data['consent']['accepted_at'] ?? now()->toDateTimeString(),
                'ip_address' => $ip,
            ]);

            $patient = Patient::create([
                'full_name' => $profile['full_name'],
                'birth_year' => $profile['birth_year'] ?? null,
                'gender' => $profile['gender'] ?? 'unknown',
                'allergies' => $profile['allergies'] ?? null,
            ]);

            PatientAccess::create([
                'patient_id' => $patient->id,
                'user_id' => $draft->user_id,
                'role' => 'caregiver',
            ]);

            $routines = $data['routines'] ?? [];
            PatientRoutine::create([
                'patient_id' => $patient->id,
                'wake_time' => $routines['wake_time'] ?? '06:00',
                'breakfast_time' => $routines['breakfast_time'] ?? '07:00',
                'lunch_time' => $routines['lunch_time'] ?? '12:00',
                'dinner_time' => $routines['dinner_time'] ?? '18:30',
                'sleep_time' => $routines['sleep_time'] ?? '22:00',
                'effective_from' => $today->toDateString(),
            ]);

            // Bệnh nền chọn từ mẫu → chép ngưỡng (chưa xác nhận) và lịch đo.
            foreach ($profile['condition_template_ids'] ?? [] as $templateId) {
                app(TemplateCloner::class)->cloneForPatient($tenant->id, $patient->id, $templateId, $draft->user_id);
            }

            foreach ($data['prescriptions'] ?? [] as $prescriptionData) {
                $this->storePrescriptionFromDraft($patient->id, $prescriptionData, $today);
            }

            $result = $orchestrator->regenerateSchedule($patient->id, $today, $tenant->id);

            $draft->update([
                'tenant_id' => $tenant->id,
                'completed_at' => now(),
            ]);

            return [
                'tenant_id' => $tenant->id,
                'patient_id' => $patient->id,
                'manual_items' => $result['manual_items'],
            ];
        });
    }

    private function storePrescriptionFromDraft(string $patientId, array $data, CarbonImmutable $today): void
    {
        $prescription = Prescription::create([
            'patient_id' => $patientId,
            'doctor_name' => $data['doctor_name'] ?? null,
            'prescribed_at' => $data['prescribed_at'] ?? $today->toDateString(),
            'starts_at' => $data['starts_at'] ?? $today->toDateString(),
            'status' => 'active',
        ]);

        foreach ($data['items'] ?? [] as $item) {
            PrescriptionItem::create([
                'patient_id' => $patientId,
                'prescription_id' => $prescription->id,
                'drug_id' => $item['drug_id'] ?? null,
                'drug_name_snapshot' => $item['drug_name'] ?? '',
                'dose_text' => $item['dose_text'] ?? '',
                'usage_rule' => $item['usage_rule'] ?? null,
                'prescribed_quantity' => $item['prescribed_quantity'] ?? null,
                'purchased_quantity' => $item['purchased_quantity'] ?? null,
                'quantity_unit' => $item['quantity_unit'] ?? null,
                'is_long_term' => (bool) ($item['is_long_term'] ?? false),
                'requires_manual_time' => empty($item['usage_rule']),
            ]);
        }
    }
}
