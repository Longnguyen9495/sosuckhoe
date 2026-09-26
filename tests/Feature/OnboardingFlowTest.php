<?php

namespace Tests\Feature;

use App\Models\ConditionTemplate;
use App\Models\ConsentVersion;
use App\Models\Drug;
use App\Models\OnboardingDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OnboardingFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['phone' => '0900000009']);
        $this->artisan('db:seed', ['--class' => 'DatabaseSeeder']);
    }

    private function consentStep(): void
    {
        $this->postJson('/api/v1/onboarding/step/2', [
            'data' => ['accepted' => true, 'consent_version_id' => ConsentVersion::query()->value('id')],
        ])->assertOk()->assertJson(['current_step' => 2]);
    }

    private function profileStep(array $extra = []): void
    {
        $this->postJson('/api/v1/onboarding/step/3', [
            'data' => ['full_name' => 'Bà C.', 'birth_year' => 1960, 'gender' => 'female', ...$extra],
        ])->assertOk()->assertJson(['current_step' => 3]);
    }

    private function routineStep(): void
    {
        $this->postJson('/api/v1/onboarding/step/4', [
            'data' => ['wake_time' => '06:00', 'breakfast_time' => '07:00', 'lunch_time' => '12:00', 'dinner_time' => '18:30', 'sleep_time' => '22:00'],
        ])->assertOk()->assertJson(['current_step' => 4]);
    }

    public function test_user_can_start_onboarding_and_get_current_step(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/onboarding')
            ->assertOk()
            ->assertJson(['current_step' => 1, 'step_name' => 'account']);

        $this->assertDatabaseHas('onboarding_drafts', ['user_id' => $this->user->id, 'completed_at' => null]);
    }

    public function test_consent_step_requires_real_consent_version_and_acceptance(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/onboarding/step/2', ['data' => ['agreed' => true]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['accepted', 'consent_version_id']);

        $this->postJson('/api/v1/onboarding/step/2', ['data' => ['accepted' => false, 'consent_version_id' => ConsentVersion::query()->value('id')]])
            ->assertUnprocessable();
    }

    public function test_user_can_save_each_step_and_progress(): void
    {
        Sanctum::actingAs($this->user);

        $this->consentStep();
        $this->profileStep(['allergies' => 'Không']);
        $this->routineStep();
        $this->postJson('/api/v1/onboarding/step/5', ['data' => ['prescriptions' => []]])
            ->assertOk()
            ->assertJson(['current_step' => 5]);
    }

    public function test_profile_step_validates_required_fields(): void
    {
        Sanctum::actingAs($this->user);
        $this->consentStep();

        $this->postJson('/api/v1/onboarding/step/3', ['data' => ['full_name' => 'A']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['birth_year']);
    }

    public function test_user_can_go_back_to_previous_step(): void
    {
        Sanctum::actingAs($this->user);

        $this->consentStep();
        $this->profileStep();

        $this->postJson('/api/v1/onboarding/back')
            ->assertOk()
            ->assertJson(['current_step' => 2]);
    }

    public function test_complete_creates_tenant_patient_routines_and_consent(): void
    {
        Sanctum::actingAs($this->user);

        $this->consentStep();
        $this->profileStep();
        $this->routineStep();
        $this->postJson('/api/v1/onboarding/step/5', ['data' => ['prescriptions' => []]])->assertOk();

        $response = $this->postJson('/api/v1/onboarding/complete')
            ->assertOk()
            ->assertJsonStructure(['tenant_id', 'patient_id']);

        $tenantId = $response->json('tenant_id');
        $patientId = $response->json('patient_id');

        $this->assertDatabaseHas('tenants', ['id' => $tenantId, 'type' => 'family']);
        $this->assertDatabaseHas('tenant_members', ['tenant_id' => $tenantId, 'user_id' => $this->user->id, 'role' => 'owner']);
        $this->assertDatabaseHas('patients', ['id' => $patientId, 'full_name' => 'Bà C.']);
        $this->assertDatabaseHas('patient_routines', ['patient_id' => $patientId, 'wake_time' => '06:00']);
        $this->assertDatabaseHas('consents', ['tenant_id' => $tenantId, 'user_id' => $this->user->id]);
        $this->assertNotNull(OnboardingDraft::where('user_id', $this->user->id)->value('completed_at'));
    }

    public function test_complete_without_consent_is_rejected(): void
    {
        Sanctum::actingAs($this->user);

        // Đi tắt qua bước 2 bằng dữ liệu cũ không có đồng ý.
        OnboardingDraft::create([
            'user_id' => $this->user->id,
            'current_step' => 5,
            'data' => ['patient_profile' => ['full_name' => 'X', 'birth_year' => 1950], 'routines' => []],
        ]);

        $this->postJson('/api/v1/onboarding/complete')->assertUnprocessable()->assertJsonValidationErrors(['consent']);
        $this->assertDatabaseMissing('patients', ['full_name' => 'X']);
    }

    public function test_complete_requires_minimum_step_5(): void
    {
        Sanctum::actingAs($this->user);
        $this->consentStep();

        $this->postJson('/api/v1/onboarding/complete')
            ->assertStatus(400)
            ->assertJson(['error' => 'ONBOARDING_INCOMPLETE']);
    }

    public function test_invalid_step_returns_error(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/onboarding/step/99', ['data' => []])
            ->assertStatus(400)
            ->assertJson(['error' => 'INVALID_STEP']);
    }

    public function test_step_out_of_range_is_rejected(): void
    {
        Sanctum::actingAs($this->user);

        // Đang ở bước 1, không thể lưu bước 5 (bước +1 tối đa)
        $this->postJson('/api/v1/onboarding/step/5', ['data' => []])
            ->assertStatus(400)
            ->assertJson(['error' => 'STEP_OUT_OF_RANGE']);
    }

    public function test_draft_is_unique_per_user_until_completed(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/onboarding');
        $this->getJson('/api/v1/onboarding');

        $this->assertEquals(1, OnboardingDraft::where('user_id', $this->user->id)->whereNull('completed_at')->count());
    }

    public function test_complete_with_prescription_and_template_generates_schedule_thresholds_and_monitoring(): void
    {
        Sanctum::actingAs($this->user);

        $template = ConditionTemplate::where('code', 'diabetes_insulin')->firstOrFail();
        $drug = Drug::where('brand_name', 'Janumet')->firstOrFail();

        $this->consentStep();
        $this->profileStep(['condition_template_ids' => [$template->id]]);
        $this->routineStep();
        $this->postJson('/api/v1/onboarding/step/5', [
            'data' => [
                'prescriptions' => [[
                    'doctor_name' => 'BS. A',
                    'items' => [[
                        'drug_id' => $drug->id,
                        'drug_name' => 'Janumet 50/850 mg',
                        'dose_text' => 'Sáng 1 viên, tối 1 viên — sau ăn',
                        'prescribed_quantity' => 60,
                        'quantity_unit' => 'viên',
                        'usage_rule' => [
                            'type' => 'medication',
                            'days' => ['type' => 'daily'],
                            'units_per_day' => 2,
                            'doses' => [
                                ['anchor' => 'breakfast', 'offset_min' => 30, 'amount_text' => '1 viên'],
                                ['anchor' => 'dinner', 'offset_min' => 30, 'amount_text' => '1 viên'],
                            ],
                        ],
                    ]],
                ]],
            ],
        ])->assertOk();

        $response = $this->postJson('/api/v1/onboarding/complete')->assertOk();
        $patientId = $response->json('patient_id');

        $this->assertDatabaseHas('prescription_items', ['patient_id' => $patientId, 'prescribed_quantity' => 60, 'quantity_unit' => 'viên']);
        $times = DB::table('schedule_items')->where('patient_id', $patientId)->orderBy('scheduled_time')->pluck('scheduled_time')->map(fn ($t) => substr($t, 0, 5))->all();
        $this->assertSame(['07:30', '19:00'], $times);

        // Ngưỡng chép từ mẫu, chưa được xác nhận.
        $this->assertDatabaseHas('patient_thresholds', ['patient_id' => $patientId, 'metric' => 'blood_glucose', 'context' => 'pre_meal', 'source' => 'template', 'confirmed_at' => null]);
        // Lịch đo 3 giai đoạn nối tiếp nhau.
        $this->assertSame(3, DB::table('monitoring_plans')->where('patient_id', $patientId)->where('metric', 'blood_glucose')->count());
        $this->assertSame(1, DB::table('monitoring_plans')->where('patient_id', $patientId)->whereNull('ends_at')->count());
    }
}
