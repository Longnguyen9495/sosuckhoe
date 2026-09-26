<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $tenantA;
    private string $tenantB;
    private string $patientA;
    private string $patientB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->tenantA = (string) Str::ulid();
        $this->tenantB = (string) Str::ulid();
        $this->patientA = (string) Str::ulid();
        $this->patientB = (string) Str::ulid();

        DB::table('tenants')->insert([
            ['id' => $this->tenantA, 'name' => 'Gia đình A', 'type' => 'family', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->tenantB, 'name' => 'Gia đình B', 'type' => 'family', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('tenant_members')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantA,
            'user_id' => $this->user->getKey(),
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('patients')->insert([
            ['id' => $this->patientA, 'tenant_id' => $this->tenantA, 'full_name' => 'Bệnh nhân A', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->patientB, 'tenant_id' => $this->tenantB, 'full_name' => 'Bệnh nhân B', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('patient_access')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantA,
            'patient_id' => $this->patientA,
            'user_id' => $this->user->getKey(),
            'role' => 'caregiver',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_patient_route_requires_tenant_context(): void
    {
        $this->actingAs($this->user)
            ->getJson("/api/v1/patients/{$this->patientA}")
            ->assertNotFound();
    }

    public function test_user_cannot_select_a_tenant_they_do_not_belong_to(): void
    {
        $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $this->tenantB)
            ->getJson("/api/v1/patients/{$this->patientB}")
            ->assertNotFound();
    }

    public function test_patient_from_another_tenant_is_hidden_by_route_binding(): void
    {
        $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $this->tenantA)
            ->getJson("/api/v1/patients/{$this->patientB}")
            ->assertNotFound();
    }

    public function test_invitation_route_hides_patient_from_another_tenant(): void
    {
        $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $this->tenantA)
            ->postJson("/api/v1/patients/{$this->patientB}/invitations", [
                'recipient' => 'nguoinhan@example.test',
                'role' => 'viewer',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('invitations', 0);
    }

    public function test_authorized_patient_can_be_viewed_and_access_is_audited_without_health_payload(): void
    {
        $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $this->tenantA)
            ->getJson("/api/v1/patients/{$this->patientA}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->patientA)
            ->assertJsonPath('data.full_name', 'Bệnh nhân A');

        $audit = DB::table('audit_logs')->where('subject_id', $this->patientA)->first();
        $this->assertNotNull($audit);
        $this->assertSame($this->tenantA, $audit->tenant_id);
        $this->assertSame('get:patients.show', $audit->action);
        $this->assertSame([
            'route' => 'api/v1/patients/{patient}',
            'status' => 200,
        ], json_decode($audit->metadata, true, flags: JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Bệnh nhân A', $audit->metadata);
    }
}
