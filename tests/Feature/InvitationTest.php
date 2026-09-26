<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $recipient;
    private string $tenantId;
    private string $patientId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-26 08:00:00');
        $this->owner = User::factory()->create(['phone' => '0900000001']);
        $this->recipient = User::factory()->create([
            'email' => 'nguoinhan@example.test',
            'phone' => '0900000002',
        ]);
        $this->tenantId = (string) Str::ulid();
        $this->patientId = (string) Str::ulid();

        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Gia đình thử nghiệm',
            'type' => 'family',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenant_members')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'user_id' => $this->owner->getKey(),
            'role' => 'owner',
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
        DB::table('patient_access')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'user_id' => $this->owner->getKey(),
            'role' => 'caregiver',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_authorized_user_can_create_invitation_and_database_only_stores_hash(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->postJson("/api/v1/patients/{$this->patientId}/invitations", [
                'recipient' => 'nguoinhan@example.test',
                'role' => 'viewer',
            ])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'token', 'expires_at']]);

        $token = $response->json('data.token');
        $this->assertIsString($token);
        $this->assertSame(64, strlen($token));

        $invitation = DB::table('invitations')->where('id', $response->json('data.id'))->first();
        $this->assertNotNull($invitation);
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertSame(now()->addDays(7)->format('Y-m-d H:i:s'), $invitation->expires_at);
    }

    public function test_recipient_can_accept_invitation_once_and_receives_expected_access(): void
    {
        $token = $this->createInvitation('nguoinhan@example.test', 'doctor');

        $this->actingAs($this->recipient)
            ->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertOk();

        $this->assertDatabaseHas('tenant_members', [
            'tenant_id' => $this->tenantId,
            'user_id' => $this->recipient->getKey(),
            'role' => 'member',
        ]);
        $this->assertDatabaseHas('patient_access', [
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'user_id' => $this->recipient->getKey(),
            'role' => 'doctor',
        ]);
        $this->assertNotNull(DB::table('invitations')->where('token_hash', hash('sha256', $token))->value('accepted_at'));

        $this->actingAs($this->recipient)
            ->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertUnprocessable();
    }

    public function test_user_whose_identity_does_not_match_recipient_cannot_accept_invitation(): void
    {
        $token = $this->createInvitation('nguoinhan@example.test', 'viewer');
        $other = User::factory()->create([
            'email' => 'nguoi-khac@example.test',
            'phone' => '0900000003',
        ]);

        $this->actingAs($other)
            ->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');

        $this->assertDatabaseMissing('patient_access', [
            'patient_id' => $this->patientId,
            'user_id' => $other->getKey(),
        ]);
    }

    public function test_expired_invitation_cannot_be_accepted(): void
    {
        $token = $this->createInvitation('0900000002', 'caregiver');
        DB::table('invitations')
            ->where('token_hash', hash('sha256', $token))
            ->update(['expires_at' => now()->subSecond()]);

        $this->actingAs($this->recipient)
            ->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');
    }

    private function createInvitation(string $recipient, string $role): string
    {
        return (string) $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->postJson("/api/v1/patients/{$this->patientId}/invitations", compact('recipient', 'role'))
            ->assertCreated()
            ->json('data.token');
    }
}
