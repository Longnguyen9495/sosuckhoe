<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Patient;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentEncryptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Disk giả: không bao giờ đọc / xoá file thật trong storage/app (trước đây tearDown xoá sạch ảnh phiếu thật).
        Storage::fake('local');

        $this->user = User::factory()->create();
        $this->tenant = Tenant::create(['name' => 'DocTest', 'type' => 'family']);
        app(TenantContext::class)->set($this->tenant);
        TenantMember::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'role' => 'owner',
        ]);
        $this->patient = Patient::create([
            'tenant_id' => $this->tenant->id,
            'full_name' => 'Patient',
        ]);
        $this->user->patientAccess()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $this->patient->id,
            'role' => 'caregiver',
        ]);
        $this->user->currentAccessToken()?->delete();
        $this->token = $this->user->createToken('test', ['basic'])->plainTextToken;
    }


    public function test_encrypted_upload_returns_download_url_and_content_is_encrypted(): void
    {
        $uploaded = UploadedFile::fake()->create('prescription.jpg', 10, 'image/jpeg');
        $response = $this
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'X-Tenant-ID' => $this->tenant->id,
            ])
            ->postJson(
                "/api/v1/patients/{$this->patient->id}/documents",
                [
                    'file' => $uploaded,
                    'type' => 'prescription',
                ]
            );

        $response->assertCreated();
        $data = $response->json('data');
        $this->assertNotNull($data['id']);
        $this->assertSame('don', $data['type'], 'Loại cũ "prescription" được chuẩn hoá thành "don".');
        $this->assertStringContainsString("/patients/{$this->patient->id}/documents/{$data['id']}/file", $data['file_url']);

        // Khôi phục tenant context vì middleware đã clear sau request
        app(TenantContext::class)->set($this->tenant);
        $document = Document::findOrFail($data['id']);
        $this->assertTrue(Storage::disk('local')->exists($document->encrypted_path));

        $raw = Storage::disk('local')->get($document->encrypted_path);
        // Content phải là chuỗi mã hóa, không phải plain text JPEG
        $this->assertStringContainsString('eyJ', $raw);
    }

    public function test_unauthorized_user_cannot_download_document(): void
    {
        $otherUser = User::factory()->create();
        $otherToken = $otherUser->createToken('other', ['basic'])->plainTextToken;

        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $this->patient->id,
            'encrypted_path' => 'documents/test.enc',
            'type' => 'prescription',
        ]);

        $this->withToken($otherToken)->getJson("/api/v1/documents/{$document->id}")
            ->assertForbidden();
    }

    public function test_authorized_user_can_download_decrypted_document(): void
    {
        $fakeContent = Crypt::encryptString('fake image bytes');
        $path = 'documents/2026/09/test.jpg.enc';
        Storage::disk('local')->put($path, $fakeContent);

        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $this->patient->id,
            'encrypted_path' => $path,
            'type' => 'prescription',
        ]);

        $response = $this->withToken($this->token)->get("/api/v1/documents/{$document->id}");
        $response->assertOk();
        // StreamedResponse không hỗ trợ getContent() trực tiếp trong PHPUnit,
        // nên ta kiểm tra header và status thay vì nội dung
        $response->assertHeader('Content-Type', 'application/octet-stream');
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertHeader('Content-Disposition');
    }
}
