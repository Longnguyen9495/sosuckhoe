<?php

namespace Tests\Feature;

use App\Models\Drug;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiPrescriptionDraftTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;
    private string $patientId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::ulid();
        $this->patientId = (string) Str::ulid();
        $this->user = User::factory()->create();

        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Gia đình thử nghiệm',
            'type' => 'family',
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

        DB::table('tenant_members')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'user_id' => $this->user->getKey(),
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('patient_access')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'user_id' => $this->user->getKey(),
            'role' => 'caregiver',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed drugs for matchDrugs test
        Drug::create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'brand_name' => 'Metformin 500mg',
            'active_ingredient' => 'Metformin Hydrochloride',
            'form' => 'Viên nén',
            'unit' => 'viên',
        ]);
    }

    public function test_user_can_create_ai_draft_from_image(): void
    {
        $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->postJson("/api/v1/patients/{$this->patientId}/ai-prescription-drafts", [
                'image_base64' => base64_encode('fake image data'),
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

        $this->assertDatabaseHas('ai_prescription_drafts', [
            'patient_id' => $this->patientId,
            'status' => 'pending',
        ]);
    }

    public function test_user_can_list_ai_drafts(): void
    {
        $documentId = (string) Str::ulid();
        DB::table('documents')->insert([
            'id' => $documentId,
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'encrypted_path' => 'ai://test',
            'type' => 'prescription_image',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ai_prescription_drafts')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'document_id' => $documentId,
            'suggestions' => json_encode([]),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->getJson("/api/v1/patients/{$this->patientId}/ai-prescription-drafts")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_user_can_confirm_draft_lines(): void
    {
        $documentId = (string) Str::ulid();
        DB::table('documents')->insert([
            'id' => $documentId,
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'encrypted_path' => 'ai://test',
            'type' => 'prescription_image',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $draftId = (string) Str::ulid();
        DB::table('ai_prescription_drafts')->insert([
            'id' => $draftId,
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'document_id' => $documentId,
            'suggestions' => json_encode([
                ['drug_name' => 'Metformin', 'strength' => '500mg'],
            ]),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->postJson("/api/v1/patients/{$this->patientId}/ai-prescription-drafts/{$draftId}/confirm", [
                'confirmed_lines' => [
                    ['index' => 0, 'confirmed' => true],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('draft.status', 'confirmed');
    }

    public function test_user_can_match_drugs_in_draft(): void
    {
        $documentId = (string) Str::ulid();
        DB::table('documents')->insert([
            'id' => $documentId,
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'encrypted_path' => 'ai://test',
            'type' => 'prescription_image',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $draftId = (string) Str::ulid();
        DB::table('ai_prescription_drafts')->insert([
            'id' => $draftId,
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'document_id' => $documentId,
            'suggestions' => json_encode([
                ['drug_name' => 'Metformin 500mg', 'strength' => '500mg'],
                ['drug_name' => 'Không có trong danh mục', 'strength' => '10mg'],
            ]),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->postJson("/api/v1/patients/{$this->patientId}/ai-prescription-drafts/{$draftId}/match-drugs")
            ->assertOk()
            ->json('data');

        $this->assertNotNull($response[0]['matched_drug_id']);
        $this->assertSame(100, $response[0]['match_score']);
        $this->assertNull($response[1]['matched_drug_id']);
        $this->assertSame(0, $response[1]['match_score']);
    }
}
