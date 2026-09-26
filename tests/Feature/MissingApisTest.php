<?php

namespace Tests\Feature;

use App\Models\ContentArticle;
use App\Models\Document;
use App\Models\Event;
use App\Models\Log;
use App\Models\Patient;
use App\Models\PatientAccess;
use App\Models\PatientRoutine;
use App\Models\Reading;
use App\Models\ScheduleItem;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MissingApisTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Patient $patient;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['phone' => '0900000001', 'name' => 'User']);
        $this->tenant = Tenant::create(['name' => 'Test Family', 'type' => 'family']);
        app(TenantContext::class)->set($this->tenant);
        TenantMember::create(['tenant_id' => $this->tenant->getKey(), 'user_id' => $this->user->getKey(), 'role' => 'owner']);
        $this->patient = Patient::create([
            'tenant_id' => $this->tenant->getKey(),
            'full_name' => 'Bà A',
            'birth_year' => 1955,
        ]);
        PatientAccess::create([
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $this->patient->getKey(),
            'user_id' => $this->user->getKey(),
            'role' => 'caregiver',
        ]);
        PatientRoutine::create([
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $this->patient->getKey(),
            'wake_time' => '06:00',
            'breakfast_time' => '07:00',
            'lunch_time' => '12:00',
            'dinner_time' => '18:00',
            'sleep_time' => '22:00',
            'effective_from' => now()->subDays(30),
        ]);
    }

    private function withAuth(): self
    {
        $token = $this->user->createToken('test', ['basic'])->plainTextToken;
        Sanctum::actingAs($this->user, ['basic']);
        app(TenantContext::class)->set($this->tenant);
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Tenant-ID' => $this->tenant->getKey()]);
    }

    public function test_overview_endpoint_returns_patient_summary(): void
    {
        Reading::create([
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $this->patient->getKey(),
            'type' => 'blood_glucose',
            'context' => 'fasting',
            'measured_at' => now()->subHour(),
            'values' => ['value' => 5.2],
        ]);

        $this->withAuth()
            ->getJson("/api/v1/patients/{$this->patient->getKey()}/overview")
            ->assertOk()
            ->assertJsonPath('data.patient.full_name', 'Bà A')
            // Không có lịch nào: tuân thủ là null, không phải 100%.
            ->assertJsonPath('data.adherence_7d', null)
            ->assertJsonPath('data.today.percent', null)
            ->assertJsonPath('data.latest_readings.blood_glucose.type', 'blood_glucose');
    }

    public function test_prescriptions_index_returns_active_prescriptions(): void
    {
        $this->withAuth()
            ->getJson("/api/v1/patients/{$this->patient->getKey()}/prescriptions")
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_documents_index_returns_only_safe_metadata(): void
    {
        Document::create([
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $this->patient->getKey(),
            'encrypted_path' => 'documents/encrypted-secret.bin',
            'type' => 'lab',
            'document_date' => '2026-09-20',
            'department' => 'Nội tiết',
            'doctor_name' => 'BS Test',
        ]);

        $this->withAuth()
            ->getJson("/api/v1/patients/{$this->patient->getKey()}/documents")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'lab')
            ->assertJsonPath('data.0.document_date', '2026-09-20')
            ->assertJsonMissingPath('data.0.encrypted_path');
    }

    public function test_calendar_endpoint_returns_month_data(): void
    {
        $today = now()->format('Y-m-d');
        ScheduleItem::create([
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $this->patient->getKey(),
            'scheduled_time' => '08:00',
            'type' => 'medication',
            'title' => 'Thuốc A',
            'starts_at' => $today,
        ]);
        Event::create([
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $this->patient->getKey(),
            'event_date' => $today,
            'type' => 'appointment',
            'title' => 'Tái khám',
        ]);

        $this->withAuth()
            ->getJson("/api/v1/patients/{$this->patient->getKey()}/calendar?month=" . now()->format('Y-m'))
            ->assertOk()
            ->assertJsonPath('data.month', now()->format('Y-m'))
            ->assertJsonCount(1, 'data.events')
            ->assertJsonCount(now()->daysInMonth, 'data.days')
            ->assertJsonPath('data.days.'.(now()->day - 1).'.total', 1)
            ->assertJsonPath('data.days.'.(now()->day - 1).'.percent', 0)
            ->assertJsonPath('data.days.'.(now()->day - 1).'.events.0.title', 'Tái khám');
    }

    public function test_articles_index_returns_published_articles(): void
    {
        ContentArticle::create([
            'condition_template_id' => null,
            'type' => 'general',
            'title' => 'Chăm sóc sức khỏe',
            'content' => 'Nội dung bài viết.',
            'is_draft' => false,
        ]);

        $this->withAuth()
            ->getJson('/api/v1/articles')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Chăm sóc sức khỏe');
    }

    public function test_tenant_members_index_returns_members(): void
    {
        $this->withAuth()
            ->getJson("/api/v1/tenants/{$this->tenant->getKey()}/members")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.role', 'owner');
    }

    public function test_push_subscribe_and_unsubscribe(): void
    {
        $this->withAuth()
            ->postJson('/api/v1/push/subscribe', [
                'endpoint' => 'https://fcm.example.com/token1',
                'public_key' => 'pk1',
                'auth_token' => 'auth1',
                'device' => 'test-device',
            ])
            ->assertOk()
            ->assertJsonPath('data.subscribed', true);

        $this->withAuth()
            ->postJson('/api/v1/push/unsubscribe', [
                'endpoint' => 'https://fcm.example.com/token1',
            ])
            ->assertOk()
            ->assertJsonPath('data.deleted', true);
    }

    public function test_day_reading_create_and_list(): void
    {
        $this->withAuth()
            ->postJson("/api/v1/patients/{$this->patient->getKey()}/readings", [
                'type' => 'blood_glucose',
                'context' => 'fasting',
                'measured_at' => now()->toIso8601String(),
                'values' => ['value' => 5.6],
            ])
            ->assertCreated();

        $this->withAuth()
            ->getJson("/api/v1/patients/{$this->patient->getKey()}/readings?type=blood_glucose")
            ->assertOk()
            ->assertJsonPath('data.0.type', 'blood_glucose');
    }

    public function test_settings_show_and_update_routines(): void
    {
        $this->withAuth()
            ->getJson("/api/v1/patients/{$this->patient->getKey()}/settings")
            ->assertOk();

        $this->withAuth()
            ->patchJson("/api/v1/patients/{$this->patient->getKey()}/settings/routines", [
                'wake_time' => '05:30',
                'breakfast_time' => '06:30',
                'lunch_time' => '12:00',
                'dinner_time' => '18:00',
                'sleep_time' => '22:00',
            ])
            ->assertOk();
    }

    public function test_thresholds_index(): void
    {
        $this->withAuth()
            ->getJson("/api/v1/patients/{$this->patient->getKey()}/thresholds")
            ->assertOk();
    }

    public function test_questions_index_and_store(): void
    {
        $this->withAuth()
            ->postJson("/api/v1/patients/{$this->patient->getKey()}/questions", [
                'question' => 'Thuốc A uống khi nào?',
            ])
            ->assertCreated();

        $this->withAuth()
            ->getJson("/api/v1/patients/{$this->patient->getKey()}/questions")
            ->assertOk()
            ->assertJsonPath('data.0.question', 'Thuốc A uống khi nào?');
    }

    public function test_onboarding_show_and_save_step(): void
    {
        $this->withAuth()
            ->getJson('/api/v1/onboarding')
            ->assertOk();

        $this->withAuth()
            ->postJson('/api/v1/onboarding/step/1', [
                'data' => ['full_name' => 'Bà Test', 'birth_year' => 1955, 'gender' => 'female'],
            ])
            ->assertOk();
    }

    public function test_ai_prescription_draft_caregiver_can_create_and_list(): void
    {
        $this->withAuth()
            ->postJson("/api/v1/patients/{$this->patient->getKey()}/ai-prescription-drafts", [
                'image_base64' => base64_encode('fake image'),
            ])
            ->assertCreated()
            ->assertJsonPath('suggestions', [
                [
                    'drug_name' => 'Metformin 500mg',
                    'strength' => '500mg',
                    'quantity' => '30 viên',
                    'dosage_instructions' => 'Uống 1 viên sau ăn sáng và tối',
                ],
                [
                    'drug_name' => 'Glimepiride 2mg',
                    'strength' => '2mg',
                    'quantity' => '15 viên',
                    'dosage_instructions' => 'Uống 1 viên trước bữa sáng 30 phút',
                ],
            ]);

        $this->withAuth()
            ->getJson("/api/v1/patients/{$this->patient->getKey()}/ai-prescription-drafts")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
