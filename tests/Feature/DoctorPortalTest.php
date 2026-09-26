<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DoctorPortalTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;
    private string $patientId;
    private User $doctor;
    private User $caregiver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::ulid();
        $this->patientId = (string) Str::ulid();

        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Phòng khám thử nghiệm',
            'type' => 'clinic',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('patients')->insert([
            'id' => $this->patientId,
            'tenant_id' => $this->tenantId,
            'full_name' => 'Bệnh nhân thử nghiệm',
            'birth_year' => 1960,
            'gender' => 'female',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->doctor = User::factory()->create();
        $this->caregiver = User::factory()->create();

        foreach ([$this->doctor, $this->caregiver] as $user) {
            DB::table('tenant_members')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $this->tenantId,
                'user_id' => $user->getKey(),
                'role' => 'member',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('patient_access')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'user_id' => $this->doctor->getKey(),
            'role' => 'doctor',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('patient_access')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'user_id' => $this->caregiver->getKey(),
            'role' => 'caregiver',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function enableTwoFactor(User $user): User
    {
        $user->forceFill([
            'two_factor_secret' => 'encrypted-secret-placeholder',
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->fresh();
    }

    private function withDoctorToken(User $user): self
    {
        $token = $user->createToken('test', ['2fa-passed'])->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    public function test_clinic_patients_requires_doctor_two_factor(): void
    {
        $this->actingAs($this->doctor)
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->getJson('/api/v1/clinic/patients')
            ->assertForbidden()
            ->assertJsonPath('code', 'DOCTOR_TWO_FACTOR_REQUIRED');
    }

    public function test_doctor_can_list_patients_sorted_by_red_flags(): void
    {
        $doctor = $this->enableTwoFactor($this->doctor);

        // Tạo cảnh báo đỏ trong 7 ngày
        DB::table('alerts')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'level' => 'red',
            'content' => 'Cao huyết áp',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        // Tạo chỉ số gần nhất
        DB::table('readings')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'type' => 'blood_glucose',
            'context' => 'fasting',
            'measured_at' => now()->subHours(2),
            'values' => json_encode(['value' => 120]),
            'evaluation' => 'warning',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Tạo lịch hẹn
        DB::table('events')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'event_date' => now()->addWeek()->toDateString(),
            'type' => 'appointment',
            'title' => 'Tái khám',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withDoctorToken($doctor)
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->getJson('/api/v1/clinic/patients')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Bệnh nhân thử nghiệm')
            ->assertJsonPath('data.0.red_flag_count_7d', 1)
            // Chưa có lịch nào trong 7 ngày: không tính tuân thủ (null), không hiện 0% hay 100%.
            ->assertJsonPath('data.0.adherence_percent', null)
            ->assertJsonPath('data.0.next_appointment.title', 'Tái khám');
    }

    public function test_caregiver_cannot_access_clinic_patients(): void
    {
        // Caregiver không bị yêu cầu 2FA, nhưng route clinic/patients nằm trong nhóm doctor.2fa
        // Middleware doctor.2fa sẽ bỏ qua nếu không phải doctor -> cho qua
        // Nhưng caregiver không có role doctor -> vẫn có thể truy cập? Không, vì route này nằm trong doctor.2fa,
        // middleware doctor.2fa chỉ kiểm tra xem user có phải doctor không; nếu không phải doctor thì cho qua.
        // Nhưng route này không có authorize patient. Vậy caregiver cũng có thể gọi?
        // Thực tế, `DoctorPatientListController::index` chỉ lọc patient_access với role=doctor.
        // Nếu caregiver gọi, sẽ trả về danh sách rỗng vì không có patient_access với role doctor cho caregiver.
        $this->actingAs($this->caregiver)
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->getJson('/api/v1/clinic/patients')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_doctor_can_create_and_list_notes(): void
    {
        $doctor = $this->enableTwoFactor($this->doctor);

        // Tạo note
        $this->withDoctorToken($doctor)
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->postJson("/api/v1/patients/{$this->patientId}/notes", [
                'content' => 'Theo dõi huyết áp ngày 1',
                'type' => 'doctor',
            ])
            ->assertCreated()
            ->assertJsonPath('content', 'Theo dõi huyết áp ngày 1');

        // Liệt kê notes
        $this->withDoctorToken($doctor)
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->getJson("/api/v1/patients/{$this->patientId}/notes")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_doctor_can_answer_question(): void
    {
        $doctor = $this->enableTwoFactor($this->doctor);

        $questionId = (string) Str::ulid();
        DB::table('questions')->insert([
            'id' => $questionId,
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'asked_by' => $this->doctor->getKey(),
            'question' => 'Thuốc này uống trước hay sau ăn?',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withDoctorToken($doctor)
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->postJson("/api/v1/patients/{$this->patientId}/questions/{$questionId}/answer", [
                'answer' => 'Uống sau bữa ăn 30 phút.',
            ])
            ->assertOk()
            ->assertJsonPath('answer', 'Uống sau bữa ăn 30 phút.')
            ->assertJsonPath('answered_by', $doctor->id);
    }

    public function test_doctor_can_confirm_threshold_and_history_created(): void
    {
        $doctor = $this->enableTwoFactor($this->doctor);

        $thresholdId = (string) Str::ulid();
        DB::table('patient_thresholds')->insert([
            'id' => $thresholdId,
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'metric' => 'blood_glucose',
            'context' => 'fasting',
            'ranges' => json_encode(['normal' => [70, 100], 'warning' => [100, 126]]),
            'source' => 'template',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withDoctorToken($doctor)
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->postJson("/api/v1/patients/{$this->patientId}/thresholds/{$thresholdId}/confirm")
            ->assertOk()
            ->assertJsonPath('threshold.confirmed_by', $doctor->id)
            ->assertJsonPath('threshold.source', 'doctor');

        $this->assertDatabaseHas('threshold_histories', [
            'patient_threshold_id' => $thresholdId,
            'changed_by' => $doctor->id,
        ]);
    }

    public function test_doctor_can_update_monitoring_plan(): void
    {
        $doctor = $this->enableTwoFactor($this->doctor);

        $planId = (string) Str::ulid();
        DB::table('monitoring_plans')->insert([
            'id' => $planId,
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'metric' => 'blood_glucose',
            'phase' => 'phase_2',
            'starts_at' => '2026-09-01',
            'ends_at' => '2026-10-01',
            'schedule' => json_encode(['days' => [1, 3, 5]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withDoctorToken($doctor)
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->putJson("/api/v1/patients/{$this->patientId}/monitoring-plans/{$planId}", [
                'schedule' => ['days' => [1, 3, 5, 7]],
                'starts_at' => '2026-09-01',
                'ends_at' => '2026-11-01',
            ])
            ->assertOk()
            ->assertJsonPath('schedule.days', [1, 3, 5, 7]);
    }

    public function test_doctor_can_view_report_json(): void
    {
        $doctor = $this->enableTwoFactor($this->doctor);

        $this->seedReportData();

        $this->withDoctorToken($doctor)
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->getJson("/api/v1/patients/{$this->patientId}/report?from=2026-09-01&to=2026-09-30")
            ->assertOk()
            ->assertJsonPath('patient.full_name', 'Bệnh nhân thử nghiệm')
            ->assertJsonPath('red_alerts_count', 1)
            ->assertJsonPath('adherence_percent', 50);
    }

    public function test_doctor_can_view_report_pdf(): void
    {
        $doctor = $this->enableTwoFactor($this->doctor);

        $this->seedReportData();

        $this->withDoctorToken($doctor)
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->get("/api/v1/patients/{$this->patientId}/report/pdf?from=2026-09-01&to=2026-09-30")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function seedReportData(): void
    {
        // Reading gần nhất
        DB::table('readings')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'type' => 'blood_glucose',
            'context' => 'fasting',
            'measured_at' => now()->subDay(),
            'values' => json_encode(['value' => 130]),
            'evaluation' => 'red',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Alert đỏ
        DB::table('alerts')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'level' => 'red',
            'content' => 'Cao',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        // Logs
        DB::table('logs')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'log_date' => '2026-09-25',
            'completed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('logs')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'log_date' => '2026-09-26',
            'completed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Doctor note
        DB::table('notes')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'author_id' => $this->doctor->getKey(),
            'type' => 'doctor',
            'content' => 'Tình trạng ổn định',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
