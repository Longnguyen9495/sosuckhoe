<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DoctorTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;
    private string $patientId;

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
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_doctor_without_confirmed_two_factor_is_denied_patient_access(): void
    {
        $doctor = $this->createMemberWithPatientRole('doctor');

        $this->actingAs($doctor)
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->getJson("/api/v1/patients/{$this->patientId}")
            ->assertForbidden()
            ->assertJsonPath('code', 'DOCTOR_TWO_FACTOR_REQUIRED');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_doctor_with_confirmed_two_factor_can_access_patient(): void
    {
        $doctor = $this->createMemberWithPatientRole('doctor');
        $doctor->forceFill([
            'two_factor_secret' => 'encrypted-secret-placeholder',
            'two_factor_confirmed_at' => now(),
        ])->save();

        $token = $doctor->fresh()->createToken('test', ['2fa-passed'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->getJson("/api/v1/patients/{$this->patientId}")
            ->assertOk();
    }

    public function test_caregiver_is_not_subject_to_doctor_two_factor_requirement(): void
    {
        $caregiver = $this->createMemberWithPatientRole('caregiver');

        $this->actingAs($caregiver)
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->getJson("/api/v1/patients/{$this->patientId}")
            ->assertOk();
    }

    private function createMemberWithPatientRole(string $role): User
    {
        $user = User::factory()->create();
        DB::table('tenant_members')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'user_id' => $user->getKey(),
            'role' => 'member',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('patient_access')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'user_id' => $user->getKey(),
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
