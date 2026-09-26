<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatientDayApiTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;
    private string $userId;
    private string $patientId;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::ulid();
        $this->userId = (string) Str::ulid();
        $this->patientId = (string) Str::ulid();

        DB::table('users')->insert([
            'id' => $this->userId,
            'phone' => '0900000001',
            'name' => 'Test User',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Test Tenant',
            'type' => 'family',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenant_members')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('patients')->insert([
            'id' => $this->patientId,
            'tenant_id' => $this->tenantId,
            'full_name' => 'Bà D.',
            'gender' => 'female',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('patient_access')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'user_id' => $this->userId,
            'role' => 'caregiver',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tokenPlain = Str::random(40);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => 'App\Models\User',
            'tokenable_id' => $this->userId,
            'name' => 'test',
            'token' => hash('sha256', $tokenPlain),
            'abilities' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->token = $tokenPlain;
    }

    private function withAuth(): self
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenantId,
        ]);
    }

    public function test_day_endpoint_returns_schedule_logs_readings_events(): void
    {
        $today = now()->toDateString();

        DB::table('schedule_items')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'starts_at' => $today,
            'scheduled_time' => '07:00',
            'type' => 'medication',
            'title' => 'Uống thuốc',
            'dose_text' => '1 viên',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('logs')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'log_date' => $today,
            'schedule_item_id' => DB::table('schedule_items')->where('patient_id', $this->patientId)->value('id'),
            'completed' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('readings')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'type' => 'blood_glucose',
            'measured_at' => now(),
            'values' => json_encode(['value' => 5.5]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('events')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'event_date' => $today,
            'type' => 'appointment',
            'title' => 'Khám',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withAuth()->getJson('/api/v1/patients/' . $this->patientId . '/day/' . $today);

        $response->assertOk();
        $data = $response->json('data');
        $medications = array_values(array_filter($data['items'], fn ($item) => $item['schedule_item_id'] ?? null));
        $this->assertCount(1, $medications);
        $this->assertTrue($medications[0]['done'], 'Việc đã có log completed phải hiện là đã làm.');
        $this->assertSame(['done' => 1, 'total' => 1, 'percent' => 100], $data['summary']);
        $this->assertCount(1, $data['readings']);
        $this->assertCount(1, $data['events']);
    }

    public function test_store_log_creates_or_updates(): void
    {
        $today = now()->toDateString();
        $scheduleItemId = (string) Str::ulid();
        DB::table('schedule_items')->insert([
            'id' => $scheduleItemId, 'tenant_id' => $this->tenantId, 'patient_id' => $this->patientId,
            'scheduled_time' => '07:30', 'type' => 'medication', 'title' => 'Thuốc A', 'dose_text' => '1 viên',
            'starts_at' => $today, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withAuth()->postJson('/api/v1/patients/' . $this->patientId . '/logs', [
            'log_date' => $today, 'schedule_item_id' => $scheduleItemId, 'completed' => true,
        ])->assertCreated();
        $this->assertDatabaseHas('logs', ['patient_id' => $this->patientId, 'schedule_item_id' => $scheduleItemId, 'completed' => 1]);

        // Bỏ tích: cập nhật cùng bản ghi, không tạo bản mới.
        $this->withAuth()->postJson('/api/v1/patients/' . $this->patientId . '/logs', [
            'log_date' => $today, 'schedule_item_id' => $scheduleItemId, 'completed' => false,
        ])->assertCreated();
        $this->assertSame(1, DB::table('logs')->where('schedule_item_id', $scheduleItemId)->count());
        $this->assertDatabaseHas('logs', ['schedule_item_id' => $scheduleItemId, 'completed' => 0]);
    }

    public function test_day_level_log_stores_water_symptoms_and_note(): void
    {
        $today = now()->toDateString();

        $this->withAuth()->postJson('/api/v1/patients/' . $this->patientId . '/logs', [
            'log_date' => $today, 'meta' => ['water_cups' => 3, 'note' => 'ok'],
        ])->assertCreated();
        $this->withAuth()->postJson('/api/v1/patients/' . $this->patientId . '/logs', [
            'log_date' => $today, 'meta' => ['symptoms' => ['hypo']],
        ])->assertCreated();

        $dayLog = $this->withAuth()->getJson('/api/v1/patients/' . $this->patientId . '/day/' . $today)->json('data.day_log');
        $this->assertSame(['water_cups' => 3, 'symptoms' => ['hypo'], 'note' => 'ok'], $dayLog);
    }

    public function test_log_rejects_schedule_item_of_another_patient(): void
    {
        $otherPatientId = (string) Str::ulid();
        DB::table('patients')->insert([
            'id' => $otherPatientId, 'tenant_id' => $this->tenantId, 'full_name' => 'Người khác',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignItemId = (string) Str::ulid();
        DB::table('schedule_items')->insert([
            'id' => $foreignItemId, 'tenant_id' => $this->tenantId, 'patient_id' => $otherPatientId,
            'scheduled_time' => '07:30', 'type' => 'medication', 'title' => 'Thuốc B', 'dose_text' => '1 viên',
            'starts_at' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withAuth()->postJson('/api/v1/patients/' . $this->patientId . '/logs', [
            'log_date' => now()->toDateString(), 'schedule_item_id' => $foreignItemId, 'completed' => true,
        ])->assertUnprocessable();
    }

    public function test_day_events_do_not_leak_from_another_patient_in_same_tenant(): void
    {
        $today = now()->toDateString();
        $otherPatientId = (string) Str::ulid();
        DB::table('patients')->insert([
            'id' => $otherPatientId, 'tenant_id' => $this->tenantId, 'full_name' => 'Người khác',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Sự kiện của bệnh nhân khác, kéo dài qua hôm nay (trước đây lọt qua điều kiện orWhere không nhóm).
        DB::table('events')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'patient_id' => $otherPatientId,
            'event_date' => now()->subDay()->toDateString(), 'due_date' => now()->addDay()->toDateString(),
            'type' => 'test', 'title' => 'XN của người khác', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $events = $this->withAuth()->getJson('/api/v1/patients/' . $this->patientId . '/day/' . $today)->json('data.events');
        $this->assertSame([], $events);
    }

    public function test_store_reading_creates_record(): void
    {
        $response = $this->withAuth()->postJson('/api/v1/patients/' . $this->patientId . '/readings', [
            'type' => 'blood_glucose',
            'context' => 'pre_meal',
            'measured_at' => now()->toDateTimeString(),
            'values' => ['value' => 6.2],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('readings', [
            'patient_id' => $this->patientId,
            'type' => 'blood_glucose',
        ]);
    }

    public function test_red_reading_creates_alert_for_same_tenant_and_reading(): void
    {
        DB::table('patient_thresholds')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'metric' => 'blood_glucose',
            'context' => 'pre_meal',
            'ranges' => json_encode([
                'target' => [4.4, 7.2],
                'red' => ['below' => 3.9, 'above' => 10],
            ]),
            'source' => 'template',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withAuth()->postJson('/api/v1/patients/' . $this->patientId . '/readings', [
            'type' => 'blood_glucose',
            'context' => 'pre_meal',
            'measured_at' => now()->toDateTimeString(),
            'values' => ['value' => 3.6],
        ]);

        $response->assertCreated();
        $readingId = $response->json('data.id');
        $this->assertDatabaseHas('alerts', [
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'reading_id' => $readingId,
            'level' => 'red',
        ]);
        $this->assertDatabaseCount('alerts', 1);
    }

    public function test_store_event_creates_record(): void
    {
        $response = $this->withAuth()->postJson('/api/v1/patients/' . $this->patientId . '/events', [
            'event_date' => now()->addDays(3)->toDateString(),
            'type' => 'appointment',
            'title' => 'Tái khám',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('events', [
            'patient_id' => $this->patientId,
            'title' => 'Tái khám',
        ]);
    }

    public function test_readings_index_filters_by_type(): void
    {
        DB::table('readings')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'type' => 'blood_glucose',
            'measured_at' => now(),
            'values' => json_encode(['value' => 5.0]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('readings')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'type' => 'blood_pressure',
            'measured_at' => now(),
            'values' => json_encode(['systolic' => 120, 'diastolic' => 80]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withAuth()->getJson('/api/v1/patients/' . $this->patientId . '/readings?type=blood_glucose');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_cross_tenant_patient_returns_404(): void
    {
        $otherTenantId = (string) Str::ulid();
        $otherPatientId = (string) Str::ulid();

        DB::table('tenants')->insert([
            'id' => $otherTenantId,
            'name' => 'Other',
            'type' => 'family',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('patients')->insert([
            'id' => $otherPatientId,
            'tenant_id' => $otherTenantId,
            'full_name' => 'Other Patient',
            'gender' => 'female',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withAuth()->getJson('/api/v1/patients/' . $otherPatientId . '/day/' . now()->toDateString());

        $response->assertNotFound();
    }
}
